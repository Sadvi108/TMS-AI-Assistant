/*
 * TMS Help Centre — chat client.
 * Talks only to api/*.php; the Dify API key never reaches the browser.
 */
(() => {
    'use strict';

    const boot = JSON.parse(document.getElementById('boot').textContent);

    const app = document.querySelector('.app');
    const sidebar = document.getElementById('sidebar');
    const scrim = document.querySelector('.scrim');
    const drawerToggle = document.querySelector('[data-action="open-drawer"]');
    const scroller = document.getElementById('scroller');
    const thread = document.getElementById('thread');
    const cards = document.getElementById('cards');
    const cardsLabel = document.getElementById('cards-label');
    const suggest = document.getElementById('suggest');
    const form = document.getElementById('composer');
    const input = document.getElementById('question');
    const sendBtn = document.getElementById('send');
    const narrow = matchMedia('(max-width: 900px)');

    const state = {
        lang: boot.lang,
        conversationId: '',
        topic: null,
        busy: false,
        controller: null,
        taskId: '',
        session: 0, // bumped by "New chat" so late events from an old answer are ignored
    };

    const topicsById = Object.fromEntries(boot.topics.map((topic) => [topic.id, topic]));

    const t = (key) => boot.i18n[state.lang]?.[key] ?? boot.i18n.en[key] ?? key;
    const pick = (pair) => pair[state.lang] ?? pair.en;

    /* ---------- DOM helpers ---------- */

    function h(tag, attrs = {}, ...children) {
        const node = document.createElement(tag);
        for (const [key, value] of Object.entries(attrs)) {
            if (value == null || value === false) continue;
            if (key === 'class') node.className = value;
            else if (key === 'text') node.textContent = value;
            else if (key === 'html') node.innerHTML = value;
            else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
            else node.setAttribute(key, value === true ? '' : value);
        }
        for (const child of children.flat()) {
            if (child != null && child !== false) node.append(child);
        }
        return node;
    }

    function icon(name) {
        const ns = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('class', 'i');
        svg.setAttribute('aria-hidden', 'true');
        const use = document.createElementNS(ns, 'use');
        use.setAttribute('href', `#i-${name}`);
        svg.append(use);
        return svg;
    }

    /** replaceChildren() that skips empty slots, like h() does. */
    const fill = (node, ...children) => node.replaceChildren(...children.flat().filter((c) => c != null && c !== false));

    /** Element whose text follows the language switch. */
    const tx = (key, tag = 'span', attrs = {}) => h(tag, { ...attrs, 'data-i18n': key, text: t(key) });

    /* ---------- Markdown (small, escape-first, so model output can't inject HTML) ---------- */

    const escapeHtml = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function inline(text) {
        const code = [];
        let s = escapeHtml(text).replace(/`([^`\n]+)`/g, (_, c) => `\u0000${code.push(c) - 1}\u0000`);
        s = s
            .replace(/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
            .replace(/(^|[\s(])(https?:\/\/[^\s<)]*[^\s<).,;:!?'"])/g, '$1<a href="$2" target="_blank" rel="noopener noreferrer">$2</a>')
            .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
            .replace(/__([^_\n]+)__/g, '<strong>$1</strong>')
            .replace(/(^|[^*\w])\*([^*\n]+)\*(?!\w)/g, '$1<em>$2</em>')
            .replace(/(^|[^_\w])_([^_\n]+)_(?!\w)/g, '$1<em>$2</em>');
        return s.replace(/\u0000(\d+)\u0000/g, (_, i) => `<code>${code[i]}</code>`);
    }

    function renderMarkdown(src) {
        const out = [];
        let para = [];
        let list = null;
        let code = null;
        const flushPara = () => {
            if (para.length) out.push(`<p>${para.map(inline).join('<br>')}</p>`);
            para = [];
        };
        const flushList = () => {
            if (list) out.push(`<${list.type}>${list.items.map((item) => `<li>${inline(item)}</li>`).join('')}</${list.type}>`);
            list = null;
        };

        for (const line of src.replace(/\r\n?/g, '\n').split('\n')) {
            if (code) {
                if (/^\s*```/.test(line)) {
                    out.push(`<pre><code>${escapeHtml(code.join('\n'))}</code></pre>`);
                    code = null;
                } else {
                    code.push(line);
                }
                continue;
            }
            const heading = line.match(/^\s{0,3}#{1,6}\s+(.*)$/);
            const bullet = line.match(/^\s*[-*•]\s+(.*)$/);
            const ordered = line.match(/^\s*\d+[.)]\s+(.*)$/);

            if (/^\s*```/.test(line)) {
                flushPara(); flushList();
                code = [];
            } else if (/^\s*([-*_])(\s*\1){2,}\s*$/.test(line) || !line.trim()) {
                flushPara(); flushList();
            } else if (heading) {
                flushPara(); flushList();
                out.push(`<h4>${inline(heading[1])}</h4>`);
            } else if (bullet || ordered) {
                flushPara();
                const type = bullet ? 'ul' : 'ol';
                if (list && list.type !== type) flushList();
                list ??= { type, items: [] };
                list.items.push((bullet || ordered)[1]);
            } else if (list && /^\s{2,}/.test(line)) {
                list.items[list.items.length - 1] += ` ${line.trim()}`;
            } else {
                flushList();
                para.push(line.trim());
            }
        }
        if (code) out.push(`<pre><code>${escapeHtml(code.join('\n'))}</code></pre>`);
        flushPara(); flushList();
        return out.join('');
    }

    /* ---------- Answer text ---------- */

    /** Drops reasoning that some models stream before the answer. */
    const stripThinking = (text) => text
        .replace(/<think>[\s\S]*?(<\/think>|$)/g, '')
        .replace(/<details[^>]*>\s*<summary>[\s\S]*?(<\/details>|$)/g, '');

    /** Hides the first characters of the hand-off marker while it is still arriving. */
    function hidePartialMarker(text) {
        const marker = boot.marker;
        for (let n = Math.min(marker.length - 1, text.length); n > 0; n--) {
            if (text.endsWith(marker.slice(0, n))) return text.slice(0, -n);
        }
        return text;
    }

    /** "[[HANDOFF]] Title\nBody" → {title, body}. Title falls back to a default. */
    function splitHandoff(text) {
        const at = text.indexOf(boot.marker);
        const before = text.slice(0, at).trim();
        const after = text.slice(at + boot.marker.length).replace(/^[\s:]+/, '');
        const newline = after.indexOf('\n');
        let title = (newline < 0 ? after : after.slice(0, newline)).replace(/^[#*\s]+|[*\s]+$/g, '');
        let body = newline < 0 ? '' : after.slice(newline + 1).trim();
        if (title.length > 120) {
            body = [title, body].filter(Boolean).join('\n\n');
            title = '';
        }
        return { title, body: [before, body].filter(Boolean).join('\n\n') };
    }

    const sourceLabel = (s) => s.title + (s.page ? ` · ${t('page')} ${s.page}` : s.section ? ` · ${t('section')} ${s.section}` : '');

    function badge(key) {
        const verified = key === 'verified';
        return h('div', { class: `badge ${verified ? 'badge--verified' : 'badge--ai'}` }, icon(verified ? 'check-circle' : 'sparkles'), tx(key));
    }

    function sourcesRow(sources) {
        return h('div', { class: 'sources' }, sources.map((source) => {
            const label = h('span', { class: 'source-label', text: sourceLabel(source) });
            label._source = source;
            return h('span', { class: 'source', title: source.snippet || null }, icon('file'), label);
        }));
    }

    function contactList() {
        const { email, phone, hours } = boot.support;
        const real = (value) => value && !/^\[.*\]$/.test(value.trim());
        const row = (name, labelKey, content) => h('li', {}, icon(name), tx(labelKey, 'span', { class: 'visually-hidden' }), content);
        return h('ul', { class: 'contact' },
            email && row('mail', 'contactEmail', real(email) && email.includes('@') ? h('a', { href: `mailto:${email}`, text: email }) : h('span', { text: email })),
            phone && row('phone', 'contactPhone', real(phone) ? h('a', { href: `tel:${phone.replace(/[^\d+]/g, '')}`, text: phone }) : h('span', { text: phone })),
            hours && row('clock', 'contactHours', h('span', { text: hours })),
        );
    }

    /* ---------- Messages ---------- */

    function addUserMessage(text) {
        const bubble = h('div', { class: 'bubble', text });
        const stack = h('div', { class: 'stack' }, bubble);
        const node = h('div', { class: 'msg msg--user' }, stack);
        thread.append(node);
        return {
            node,
            showRedacted(clean) {
                bubble.textContent = clean;
                stack.append(h('p', { class: 'redacted-note' }, icon('shield'), tx('redacted')));
            },
        };
    }

    class BotMessage {
        constructor() {
            this.text = '';
            this.messageId = '';
            this.frame = 0;
            this.prose = null;
            this.card = h('article', { class: 'card' },
                h('div', { class: 'typing', role: 'status', 'aria-label': '…' }, h('span'), h('span'), h('span')));
            this.node = h('div', { class: 'msg msg--bot' }, h('span', { class: 'avatar' }, icon('sparkles')), this.card);
            thread.append(this.node);
        }

        /** Streams text in, re-rendering at most once per frame. */
        update(text) {
            this.text = text;
            if (this.frame) return;
            this.frame = requestAnimationFrame(() => {
                this.frame = 0;
                const visible = hidePartialMarker(stripThinking(this.text)).split(boot.marker).join('').trim();
                if (!visible) return;
                if (!this.prose) {
                    this.prose = h('div', { class: 'prose' });
                    this.card.replaceChildren(this.prose);
                }
                this.prose.innerHTML = renderMarkdown(visible);
                let last = this.prose.lastElementChild;
                while (last && /^(UL|OL)$/.test(last.tagName) && last.lastElementChild) last = last.lastElementChild;
                last?.classList.add('caret');
                stickToBottom();
            });
        }

        finish(end = {}, { stopped = false } = {}) {
            cancelAnimationFrame(this.frame);
            this.frame = 0;
            const text = stripThinking(this.text).trim();
            this.card.className = 'card';
            this.card.replaceChildren();

            if (!stopped && (end.handoff || text.includes(boot.marker))) {
                this.renderHandoff(splitHandoff(text));
            } else if (!text) {
                if (stopped) this.card.append(tx('stopped', 'p', { class: 'stopped-note' }));
                else this.fail(t('errorGeneric'));
            } else if (stopped) {
                this.card.append(h('div', { class: 'prose', html: renderMarkdown(text.split(boot.marker).join('')) }), tx('stopped', 'p', { class: 'stopped-note' }));
            } else {
                const sources = end.sources || [];
                fill(this.card,
                    badge(end.verified ? 'verified' : sources.length ? 'aiFromDocs' : 'aiAnswer'),
                    h('div', { class: 'prose', html: renderMarkdown(text) }),
                    end.verified ? tx('verifiedFoot', 'p', { class: 'card-foot' }) : null,
                    !end.verified && sources.length ? sourcesRow(sources) : null,
                    !end.verified && this.messageId ? this.feedbackRow() : null,
                );
            }
            stickToBottom();
        }

        renderHandoff({ title, body }) {
            this.card.className = 'card card--handoff';
            fill(this.card,
                title ? h('h3', { class: 'handoff-title', text: title }) : tx('handoffTitle', 'h3', { class: 'handoff-title' }),
                body ? h('div', { class: 'prose', html: renderMarkdown(body) }) : null,
                contactList(),
                tx('handoffTip', 'p', { class: 'handoff-tip' }),
            );
        }

        feedbackRow() {
            const after = h('span', { class: 'fb-after', 'aria-live': 'polite' });
            const buttons = [['like', 'thumbs-up', 'yes'], ['dislike', 'thumbs-down', 'no']].map(([rating, iconName, key]) =>
                h('button', { type: 'button', class: 'fb-btn', 'data-rating': rating, 'aria-pressed': 'false' }, icon(iconName), tx(key)));
            const row = h('div', { class: 'feedback' }, tx('didItSolve'), ...buttons, after);

            const show = (rating) => {
                buttons.forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.rating === rating)));
                row.querySelectorAll('.fb-sorry, .contact').forEach((n) => n.remove());
                after.replaceChildren(rating === 'like' ? tx('thanksResolved', 'span', { class: 'fb-thanks' }) : '');
                if (rating === 'dislike') row.append(tx('thanksNo', 'p', { class: 'fb-sorry' }), contactList());
            };

            let current = null;
            buttons.forEach((button) => button.addEventListener('click', async () => {
                const rating = button.dataset.rating;
                if (rating === current) return;
                const previous = current;
                current = rating;
                show(rating);
                buttons.forEach((b) => { b.disabled = true; });
                try {
                    await postJson('api/feedback.php', { message_id: this.messageId, rating });
                } catch {
                    current = previous;
                    show(previous);
                } finally {
                    buttons.forEach((b) => { b.disabled = false; });
                }
            }));
            return row;
        }

        fail(message, retry) {
            cancelAnimationFrame(this.frame);
            this.frame = 0;
            this.card.className = 'card card--error';
            fill(this.card,
                h('div', { class: 'error-row', role: 'alert' }, icon('alert'), h('span', { text: message })),
                retry ? h('button', { type: 'button', class: 'btn-retry', onclick: retry }, icon('retry'), tx('retry')) : null,
            );
            stickToBottom();
        }
    }

    /* ---------- Talking to the server ---------- */

    async function postJson(url, body) {
        const res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }

    /** Yields each JSON line of the api/chat.php stream as it arrives. */
    async function* readEvents(body) {
        const reader = body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        for (;;) {
            const { value, done } = await reader.read();
            buffer += decoder.decode(value, { stream: !done });
            let newline;
            while ((newline = buffer.indexOf('\n')) >= 0) {
                const line = buffer.slice(0, newline).trim();
                buffer = buffer.slice(newline + 1);
                if (!line) continue;
                let event;
                try { event = JSON.parse(line); } catch { continue; }
                yield event;
            }
            if (done) return;
        }
    }

    async function ask(question, { retried = false } = {}) {
        question = question.trim();
        if (!question || state.busy) return;

        showChat();
        if (state.topic) selectTopic(null);
        const session = state.session;
        const user = addUserMessage(question);
        const bot = new BotMessage();
        const retry = () => {
            user.node.remove();
            bot.node.remove();
            ask(question);
        };
        stickToBottom(true);
        setBusy(true);

        const controller = new AbortController();
        state.controller = controller;
        let text = '';
        let end = null;
        let failed = false;
        let expired = false;

        try {
            const res = await fetch('api/chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ query: question, conversation_id: state.conversationId, lang: state.lang }),
                signal: controller.signal,
            });
            if (!res.ok || !res.body) {
                const data = await res.json().catch(() => ({}));
                bot.fail(data.error || t('errorGeneric'), retry);
                return;
            }
            for await (const event of readEvents(res.body)) {
                if (session !== state.session) return;
                switch (event.type) {
                    case 'start':
                        if (event.redacted) user.showRedacted(event.query);
                        break;
                    case 'meta':
                        state.conversationId = event.conversation_id || state.conversationId;
                        state.taskId = event.task_id || '';
                        bot.messageId = event.message_id || '';
                        break;
                    case 'delta':
                        text += event.text;
                        bot.update(text);
                        break;
                    case 'replace':
                        text = event.text;
                        bot.update(text);
                        break;
                    case 'end':
                        end = event;
                        break;
                    case 'error':
                        failed = true;
                        if (event.code === 'conversation_expired' && !retried) expired = true;
                        else bot.fail(event.message || t('errorGeneric'), retry);
                        break;
                }
            }
            if (!failed) bot.finish(end || {});
        } catch (err) {
            if (session !== state.session) return;
            if (err.name === 'AbortError') {
                bot.text = text;
                bot.finish(end || {}, { stopped: true });
            } else {
                bot.fail(t('errorNetwork'), retry);
            }
        } finally {
            if (session === state.session) {
                state.controller = null;
                state.taskId = '';
                setBusy(false);
            }
        }

        if (expired) {
            // The server forgot this conversation; start a fresh one with the same question.
            user.node.remove();
            bot.node.remove();
            state.conversationId = '';
            ask(question, { retried: true });
        }
    }

    function stop() {
        if (!state.busy) return;
        const taskId = state.taskId;
        state.controller?.abort();
        if (taskId) postJson('api/stop.php', { task_id: taskId }).catch(() => {});
    }

    /* ---------- View state ---------- */

    function setBusy(busy) {
        state.busy = busy;
        const key = busy ? 'stop' : 'send';
        sendBtn.dataset.mode = key;
        sendBtn.type = busy ? 'button' : 'submit';
        sendBtn.dataset.i18nLabel = key;
        sendBtn.setAttribute('aria-label', t(key));
        sendBtn.replaceChildren(icon(busy ? 'stop' : 'arrow-right'));
    }

    function setPlaceholder(key) {
        input.dataset.i18nPlaceholder = key;
        input.placeholder = t(key);
    }

    function showChat() {
        if (app.dataset.view === 'chat') return;
        app.dataset.view = 'chat';
        setPlaceholder('placeholderNext');
    }

    function newChat() {
        stop();
        state.session++;
        state.controller = null;
        state.taskId = '';
        state.conversationId = '';
        setBusy(false);
        thread.replaceChildren();
        app.dataset.view = 'welcome';
        setPlaceholder('placeholderFirst');
        selectTopic(null);
        input.value = '';
        autosize();
        scroller.scrollTop = 0;
        closeDrawer();
        if (!narrow.matches) input.focus();
    }

    function renderCards() {
        const list = state.topic
            ? topicsById[state.topic].questions.map((q) => [state.topic, q])
            : boot.featured.map(([id, index]) => [id, topicsById[id].questions[index]]);
        cardsLabel.hidden = !state.topic;
        cardsLabel.textContent = state.topic ? `${t('suggestionsFor')} · ${pick(topicsById[state.topic].label)}` : '';
        cards.replaceChildren(...list.map(([id, question], i) => {
            const card = h('button', { type: 'button', class: 'q-card', onclick: () => ask(pick(question)) },
                h('span', { class: 'q-topic', text: pick(topicsById[id].short) }),
                h('span', { class: 'q-text', text: pick(question) }));
            card.style.animationDelay = `${i * 40}ms`;
            return card;
        }));
    }

    function showSuggestions(id) {
        suggest.replaceChildren(
            ...topicsById[id].questions.map((q) => h('button', { type: 'button', class: 'chip', onclick: () => ask(pick(q)) }, pick(q))),
            h('button', { type: 'button', class: 'chip chip--close', 'data-i18n-label': 'hideSuggestions', 'aria-label': t('hideSuggestions'), onclick: () => selectTopic(null) }, icon('x')),
        );
        suggest.hidden = false;
    }

    function selectTopic(id) {
        state.topic = id;
        document.querySelectorAll('[data-topic]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.topic === id)));
        renderCards();
        if (id && app.dataset.view === 'chat') showSuggestions(id);
        else suggest.hidden = true;
    }

    function setLang(lang) {
        if (lang === state.lang) return;
        state.lang = lang;
        document.cookie = `tms_lang=${lang}; path=/; max-age=31536000; samesite=lax`;
        document.documentElement.lang = lang;
        document.querySelectorAll('[data-lang]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.lang === lang)));
        document.querySelectorAll('[data-i18n]').forEach((n) => { n.textContent = t(n.dataset.i18n); });
        document.querySelectorAll('[data-i18n-label]').forEach((n) => n.setAttribute('aria-label', t(n.dataset.i18nLabel)));
        document.querySelectorAll('[data-topic-label]').forEach((n) => { n.textContent = pick(topicsById[n.dataset.topicLabel].label); });
        document.querySelectorAll('.source-label').forEach((n) => { n.textContent = sourceLabel(n._source); });
        input.placeholder = t(input.dataset.i18nPlaceholder);
        renderCards();
        if (state.topic && app.dataset.view === 'chat') showSuggestions(state.topic);
    }

    function syncDrawer() {
        const open = app.classList.contains('drawer-open');
        sidebar.inert = narrow.matches && !open;
        scrim.hidden = !open;
        drawerToggle.setAttribute('aria-expanded', String(open));
    }

    function openDrawer() {
        app.classList.add('drawer-open');
        syncDrawer();
        sidebar.querySelector('.drawer-close').focus();
    }

    function closeDrawer() {
        if (!app.classList.contains('drawer-open')) return;
        app.classList.remove('drawer-open');
        syncDrawer();
        drawerToggle.focus();
    }

    /* Keeps the newest text in view unless the reader has scrolled up. */
    let pinned = true;
    scroller.addEventListener('scroll', () => {
        pinned = scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 80;
    }, { passive: true });

    function stickToBottom(force = false) {
        if (force) pinned = true;
        if (pinned) scroller.scrollTop = scroller.scrollHeight;
    }

    function autosize() {
        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 180)}px`;
    }

    /* ---------- Events ---------- */

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        if (state.busy) return;
        const question = input.value;
        if (!question.trim()) {
            input.focus();
            return;
        }
        input.value = '';
        autosize();
        ask(question);
    });

    sendBtn.addEventListener('click', (e) => {
        if (!state.busy) return;
        e.preventDefault();
        stop();
    });

    input.addEventListener('input', autosize);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            form.requestSubmit();
        }
    });

    document.addEventListener('click', (e) => {
        const target = e.target.closest('[data-action], [data-topic], [data-lang]');
        if (!target) return;
        const { action, topic, lang } = target.dataset;
        if (action === 'new-chat') newChat();
        else if (action === 'open-drawer') openDrawer();
        else if (action === 'close-drawer') closeDrawer();
        else if (topic) {
            selectTopic(state.topic === topic ? null : topic);
            closeDrawer();
        } else if (lang) setLang(lang);
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeDrawer();
    });

    narrow.addEventListener('change', syncDrawer);

    /* ---------- Start ---------- */

    setPlaceholder('placeholderFirst');
    renderCards();
    syncDrawer();
    if (!narrow.matches) input.focus();
})();
