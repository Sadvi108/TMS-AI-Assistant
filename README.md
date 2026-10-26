# TMS Help Centre — AI Assistant

PHP web app for the TMS help centre chat. Visitors ask questions in English or Bahasa Malaysia; answers come from a Dify app built on TMS's official guides. The Dify API key stays on the server — the browser only talks to `public/api/*.php`.

## Requirements

- PHP 8.1 or newer with the `curl` extension (standard on most hosts)
- A Dify **Chatbot**, **Agent** or **Chatflow** app (not a Workflow app)

## Setup

```bash
cp .env.example .env
```

Fill in `.env`:

| Key | What to put |
| --- | --- |
| `DIFY_API_BASE` | `https://api.dify.ai/v1` for Dify Cloud, `http://localhost/v1` for Dify on this Mac |
| `DIFY_API_KEY` | Dify → your app → **Access Point** (called *API Access* before Dify 1.17) → API Key (starts with `app-`) |
| `TMS_SUPPORT_EMAIL` / `_PHONE` / `TMS_OFFICE_HOURS` | Shown on the "contact TMS" card |
| `TMS_WEBSITE_URL` | Target of "Back to TMS website" |

### Starting everything (Dify on this Mac)

With Dify self-hosted on this Mac, the assistant needs Docker Desktop (which runs Dify), Ollama (the `bge-m3` model Dify uses to match questions) and the PHP server. One command starts whatever isn't running, waits for Dify, checks that a test question gets answered and opens the browser:

```bash
./start.sh
```

Ctrl+C stops the assistant's web server; Docker, Dify and Ollama keep running. `./start.sh --no-open` skips the browser. If Dify isn't in `~/dify/docker`, run `DIFY_DIR=/path/to/dify/docker ./start.sh`.

To run only the web server:

```bash
PHP_CLI_SERVER_WORKERS=4 php -S localhost:8000 -t public
```

Open http://localhost:8000. (The extra workers let "stop" and feedback requests through while an answer is streaming; PHP's dev server handles one request at a time otherwise.)

### Checking every FAQ question

`tools/faq-check.php` sends each question in `tools/faq.csv` through the running app and reports whether it got a verified answer, an AI answer with sources, a hand-off or an error:

```bash
php tools/faq-check.php tools/faq.csv
```

### Try it without a Dify key

`tools/mock-dify.php` fakes the Dify API and streams the three answer styles from the design. Run it in a second terminal:

```bash
php -S localhost:8081 tools/mock-dify.php
```

and set `DIFY_API_BASE=http://localhost:8081/v1` and `DIFY_API_KEY=mock` in `.env`. Ask about "documents" (verified), "payment" (hand-off) or anything else (AI answer with sources).

## Setting up the Dify app

The three answer styles in the design map to Dify features:

| Card in the UI | Comes from |
| --- | --- |
| **Verified by TMS** (green) | Dify **Annotation Reply** — answers TMS staff wrote and approved. Turn it on under *Features → Annotation Reply* and add Q&A pairs (bulk import takes a CSV with question and answer columns, so `TMS_FAQ_Training_Data.xlsx` saved as CSV works). |
| **AI answer from TMS documents** + document chips | Knowledge base retrieval. Turn on *Features → Citations and Attributions* or the chips won't appear. |
| **Contact TMS** card (amber) | The model starts its reply with `[[HANDOFF]]` (see prompt below). The question is also saved to `storage/unanswered.jsonl` so you can add it to the knowledge base later. |

Add this to the app's instructions (system prompt), adjusting the wording to taste:

```
You are the TMS Help Centre assistant. Answer only from the provided knowledge.
- Reply in the language of the question (English or Bahasa Malaysia).
- Keep answers short: one to three sentences, or a short bullet list.
- Never ask for IC, passport or card numbers.
- If the answer isn't in the knowledge, or the question needs someone to look at
  a specific account, payment or registration, reply in exactly this format:
  [[HANDOFF]] <short title, e.g. "I can't check payment records from here">
  <one or two sentences saying why, and that TMS staff can help>
```

Optional: if your app has an input variable for the language, set `DIFY_LANGUAGE_INPUT` to its name and it will receive `English` or `Bahasa Malaysia` from the EN/BM switch.

"Did this solve it?" answers are saved as Dify message feedback (like / dislike) and show up in the app's **Logs & Annotations**.

## Deploying

- Point the web server's document root at `public/`. If your host forces the project folder as the root (common on cPanel), the included `.htaccess` serves `public/` and blocks `src/`, `storage/`, `tools/` and `.env`.
- `storage/` must be writable by PHP (rate limits and the unanswered-questions log).
- Answers stream token by token. Behind nginx, `X-Accel-Buffering: no` is already sent; behind other proxies or CDNs, turn off response buffering for `/api/chat.php`.
- Set `APP_DEBUG=false` in production.

### Vercel

The Vercel project uses the **Container** framework preset, which builds the root `Dockerfile` (PHP 8.3 serving `public/` on port 80). Set `DIFY_API_BASE`, `DIFY_API_KEY` and the `TMS_*` contact details under **Settings → Environment Variables**, then redeploy. `/api/health.php` shows whether the server can see them (true/false only).

The live site uses **Dify Cloud** (`DIFY_API_BASE=https://api.dify.ai/v1`), so it runs without this Mac.

### Dify Cloud (free plan)

The free plan allows only 10 annotation replies, so the knowledge base is the single source of answers. The Chatflow (built from the local export by `tools/build-cloud-dsl.py`) runs **Knowledge Retrieval → Exact FAQ match → Verified FAQ answer**, and sends anything that isn't (almost) exactly an FAQ question to the LLM:

- An exact FAQ question gets that FAQ's answer word for word, shown as **Verified by TMS** (node id `faq_answer`; matcher code in `tools/dify-faq-match.py`).
- Anything else gets an AI answer from the knowledge base, or the Contact TMS card when the knowledge base doesn't cover it.

Models: Groq `gpt-oss-120b` for answers, Gemini `gemini-embedding-001` for matching, both on your own API keys (Integrations → Model Provider). The knowledge base uses hybrid search with weighted score (0.7 semantic / 0.3 keyword) and no rerank model.

If AI answers stop showing source chips, open the workflow, check **Features → Citations and Attributions** is on, and publish again. Every publish copies the draft's features, so a draft with citations off removes them from the live site.

**Adding or changing answers:** the free plan can't add single chunks, so upload a file in **Knowledge → tms-knowledge-FAQ → Add file**. Write one Q&A per line and keep Dify's default chunk settings; every line becomes one entry:

```
Q: How do I contact the organiser? A: Use the organiser contact shown on the tournament page.
Q: Can I change my club name? A: Contact TMS support with your club ID and the new name.
```

New entries are used immediately, with no redeploy. To change an existing answer, edit the line in your file and upload it again (then delete the old document), or replace `tms-knowledge-FAQ-EN.txt`.

**Free plan limits:** about 10 knowledge lookups a minute (each question is one), 5,000 API calls a month, 50 documents / 50 MB. Run the FAQ check against it with `php tools/faq-check.php --base=https://tms-ai-drab.vercel.app --gap=6.5 tools/faq.csv`.

### Using the Dify on this Mac instead

Vercel can't reach `http://localhost`, so to point the live site at the Dify on this Mac, open a tunnel:

```bash
./start.sh     # Docker, Dify, Ollama
./tunnel.sh    # prints DIFY_API_BASE=https://<random>.trycloudflare.com/v1
```

`tunnel.sh` publishes only Dify's app API (`/v1/...`) through `tools/dify-gateway.php`; the Dify console stays private. The live site answers only while the tunnel, Docker and Ollama run, and a quick tunnel gets a new address every start, so update `DIFY_API_BASE` in Vercel and redeploy each time. For a permanent address, use a named Cloudflare tunnel on your own domain, or move the app to Dify Cloud (exports in `dify-export/` are made for that).

## Privacy and safety

- Before a question is sent to Dify, Malaysian IC numbers (`900101-14-5678`), passport numbers (`A12345678`) and card numbers that pass the Luhn check are replaced with `[… removed]`, and the visitor is told.
- Each browser gets an anonymous id (`tms_uid` cookie) so Dify keeps conversations apart. No names or logins are collected.
- `api/chat.php` allows 20 questions per minute per IP.

## Files

```
public/index.php        Page (welcome + chat views)
public/assets/app.css   Styles
public/assets/app.js    Chat client: streaming, markdown, feedback, EN/BM switch
public/api/chat.php     Streams answers from Dify /chat-messages
public/api/feedback.php Sends "Did this solve it?" to Dify
public/api/stop.php     Stops an answer mid-stream
src/bootstrap.php       .env loading, helpers, rate limiting
src/DifyClient.php      Dify API client
src/Redactor.php        Removes IC / passport / card numbers
src/i18n.php            English and Bahasa Malaysia copy
src/topics.php          Sidebar topics and suggested questions
tools/mock-dify.php     Fake Dify server for local testing
```
