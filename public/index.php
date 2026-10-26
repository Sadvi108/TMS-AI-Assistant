<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$config = app_config();
$i18n = require APP_ROOT . '/src/i18n.php';
$catalog = require APP_ROOT . '/src/topics.php';
$lang = current_lang();
visitor_id(); // set the cookie before any output

$t = fn(string $key): string => e($i18n[$lang][$key] ?? $i18n['en'][$key] ?? $key);
$asset = fn(string $path): string => e($path . '?v=' . (@filemtime(__DIR__ . '/' . $path) ?: '1'));

$boot = [
    'lang' => $lang,
    'i18n' => $i18n,
    'topics' => $catalog['topics'],
    'featured' => $catalog['featured'],
    'support' => $config['support'],
    'marker' => $config['handoff_marker'],
];

header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'");
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>TMS Help Centre</title>
    <meta name="description" content="<?= $t('heroLead') ?>">
    <meta name="theme-color" content="#f3f5fa">
    <link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="8" fill="#2447e6"/><path d="M16 7l2.2 6.8L25 16l-6.8 2.2L16 25l-2.2-6.8L7 16l6.8-2.2z" fill="#fff"/></svg>') ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wdth,wght@125,800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= $asset('assets/app.css') ?>">
</head>
<body>
<svg class="sprite" aria-hidden="true">
    <symbol id="i-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></symbol>
    <symbol id="i-user-plus" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6"/><path d="M22 11h-6"/></symbol>
    <symbol id="i-credit-card" viewBox="0 0 24 24"><rect width="20" height="14" x="2" y="5" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/></symbol>
    <symbol id="i-lock" viewBox="0 0 24 24"><rect width="16" height="11" x="4" y="11" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></symbol>
    <symbol id="i-sparkles" viewBox="0 0 24 24"><path d="M10 3.5l1.7 5 5 1.7-5 1.7-1.7 5-1.7-5-5-1.7 5-1.7z"/><path d="M18 14.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/></symbol>
    <symbol id="i-arrow-right" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></symbol>
    <symbol id="i-arrow-up" viewBox="0 0 24 24"><path d="M12 19V5"/><path d="m5 12 7-7 7 7"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M5 12h14"/><path d="M12 5v14"/></symbol>
    <symbol id="i-chevron-left" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></symbol>
    <symbol id="i-external" viewBox="0 0 24 24"><path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></symbol>
    <symbol id="i-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></symbol>
    <symbol id="i-file" viewBox="0 0 24 24"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v5h6"/><path d="M9 13h6"/><path d="M9 17h4"/></symbol>
    <symbol id="i-thumbs-up" viewBox="0 0 24 24"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H4a2 2 0 0 1-2-2v-8a2 2 0 0 1 2-2h2.76a2 2 0 0 0 1.79-1.11L12 2a3.13 3.13 0 0 1 3 3.88Z"/></symbol>
    <symbol id="i-thumbs-down" viewBox="0 0 24 24"><path d="M17 14V2"/><path d="M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H20a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-2.76a2 2 0 0 0-1.79 1.11L12 22a3.13 3.13 0 0 1-3-3.88Z"/></symbol>
    <symbol id="i-mail" viewBox="0 0 24 24"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></symbol>
    <symbol id="i-phone" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></symbol>
    <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></symbol>
    <symbol id="i-shield" viewBox="0 0 24 24"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></symbol>
    <symbol id="i-x" viewBox="0 0 24 24"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></symbol>
    <symbol id="i-stop" viewBox="0 0 24 24"><rect x="7" y="7" width="10" height="10" rx="2" fill="currentColor"/></symbol>
    <symbol id="i-alert" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></symbol>
    <symbol id="i-retry" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></symbol>
</svg>

<div class="app" data-view="welcome">
    <header class="masthead">
        <div class="masthead-row">
            <a class="brand" href="./" data-action="new-chat">
                <span class="brand-mark" aria-hidden="true">TMS</span>
                <span class="brand-name" data-i18n="helpCentre"><?= $t('helpCentre') ?></span>
            </a>
            <div class="masthead-actions">
                <button class="btn-quiet new-chat" type="button" data-action="new-chat">
                    <svg class="i"><use href="#i-plus"/></svg>
                    <span data-i18n="newChat"><?= $t('newChat') ?></span>
                </button>
                <div class="segmented" role="group" aria-label="<?= $t('language') ?>" data-i18n-label="language">
                    <button type="button" data-lang="en" aria-pressed="<?= $lang === 'en' ? 'true' : 'false' ?>">EN</button>
                    <button type="button" data-lang="ms" aria-pressed="<?= $lang === 'ms' ? 'true' : 'false' ?>">BM</button>
                </div>
                <a class="back-link" href="<?= e($config['website_url']) ?>" aria-label="<?= $t('backToSite') ?>" data-i18n-label="backToSite">
                    <svg class="i"><use href="#i-external"/></svg>
                    <span data-i18n="backToSite"><?= $t('backToSite') ?></span>
                </a>
            </div>
        </div>
        <nav class="topics" aria-label="<?= $t('topics') ?>" data-i18n-label="topics">
            <ul>
                <?php foreach ($catalog['topics'] as $topic): ?>
                    <li>
                        <button class="topic" type="button" data-topic="<?= e($topic['id']) ?>" aria-pressed="false">
                            <svg class="i"><use href="#i-<?= e($topic['icon']) ?>"/></svg>
                            <span data-topic-label="<?= e($topic['id']) ?>"><?= e($topic['label'][$lang]) ?></span>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </nav>
    </header>

    <main class="stage" id="scroller">
        <section class="welcome" aria-labelledby="hero-title">
            <svg class="tracks" viewBox="0 0 760 460" aria-hidden="true" focusable="false">
                <path d="M418 -270 A340 340 0 0 0 418 410 H760"/>
                <path d="M418 -215 A285 285 0 0 0 418 355 H760"/>
                <path class="tracks-accent" d="M418 -157 A227 227 0 0 0 418 297 H760"/>
                <path d="M418 -100 A170 170 0 0 0 418 240 H760"/>
            </svg>
            <div class="welcome-inner">
                <p class="provenance"><span class="dot" aria-hidden="true"></span><span data-i18n="officialGuides"><?= $t('officialGuides') ?></span></p>
                <h1 class="hero-title" id="hero-title" data-i18n="heroTitle"><?= $t('heroTitle') ?></h1>
                <p class="hero-lead" data-i18n="heroLead"><?= $t('heroLead') ?></p>

                <div class="hero-composer" id="hero-slot">
                    <form class="composer" id="composer" autocomplete="off">
                        <label class="visually-hidden" for="question" data-i18n="placeholderFirst"><?= $t('placeholderFirst') ?></label>
                        <textarea id="question" name="question" rows="1" maxlength="2000" enterkeyhint="send"
                                  placeholder="<?= $t('placeholderFirst') ?>"></textarea>
                        <button class="send" id="send" type="submit" data-i18n-label="send" aria-label="<?= $t('send') ?>">
                            <svg class="i"><use href="#i-arrow-up"/></svg>
                        </button>
                    </form>
                    <p class="note">
                        <svg class="i"><use href="#i-shield"/></svg>
                        <span data-i18n="privacyNote"><?= $t('privacyNote') ?></span>
                    </p>
                </div>

                <section class="suggestions" aria-labelledby="cards-label">
                    <h2 class="eyebrow" id="cards-label" data-i18n="suggestionsFor"><?= $t('suggestionsFor') ?></h2>
                    <ol class="questions" id="cards"></ol>
                </section>
            </div>
        </section>

        <div class="thread" id="thread" aria-live="polite" aria-relevant="additions"></div>
    </main>

    <footer class="dock" id="dock">
        <div class="dock-inner">
            <div class="suggest" id="suggest" hidden></div>
            <div id="dock-slot"></div>
            <p class="note" data-i18n="scopeNote"><?= $t('scopeNote') ?></p>
        </div>
    </footer>
</div>

<script type="application/json" id="boot"><?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= $asset('assets/app.js') ?>" defer></script>
</body>
</html>
