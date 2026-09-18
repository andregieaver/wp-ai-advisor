# WP AI Advisor

A WordPress plugin that adds an "ask us anything" conversation panel to any page. It answers visitor questions using **your own site content** — crawled from the site itself, plus any documents you upload — through the OpenAI API.

Questions outside that material are refused rather than answered from the model's general knowledge.

## How it works

1. **Crawl.** The plugin fetches your site (sitemap first, then internal links), strips each page to readable text, and records the internal links it found.
2. **Index.** That text is split into overlapping passages and embedded. Vectors are stored as binary blobs in a custom table.
3. **Ask.** A visitor's question is embedded, matched against those vectors by cosine similarity, and the best passages are sent to the chat model as the only permitted source.
4. **Answer.** The model returns structured JSON — the answer, whether it was actually grounded in the passages, suggested follow-ups, and links. Links are discarded unless the URL genuinely appears in the retrieved context, so the widget cannot show a URL the model invented.

## Requirements

- WordPress 6.2+, PHP 7.4+
- An OpenAI API key
- The PHP `zip` extension, only if you want to upload `.docx` files

## Installation

1. Copy this directory to `wp-content/plugins/wp-ai-advisor`.
2. Activate **WP AI Advisor**.
3. Go to **Settings → AI Advisor**, add your API key, and save.
4. Open the **Knowledge base** tab and press **Crawl and index site**.
5. Put `[ai_advisor]` on a page.

### Keeping the key out of the database

```php
// wp-config.php
define( 'WP_AI_ADVISOR_API_KEY', 'sk-...' );
```

The constant wins over the stored setting, and the admin field becomes read-only.

## Shortcode

```
[ai_advisor]
[ai_advisor heading="Hi, how can we help?" eyebrow="Ask us" theme="light" open="yes"]
```

| Attribute | Default | Description |
| --- | --- | --- |
| `eyebrow` | from settings | Small uppercase label above the heading. |
| `heading` | from settings | Headline on the collapsed card. |
| `placeholder` | from settings | Composer placeholder. |
| `theme` | `dark` | `dark` or `light`. |
| `open` | `no` | `yes` renders the panel already open. |

The container is fluid: it fills its parent up to `56rem`, drops from two columns to one below `48em`, and tightens its padding and controls below `30em`. Colours come from CSS custom properties on `.aiadv`, so a theme can override them without touching the stylesheet.

## Settings

**OpenAI connection** — API key, chat model (default `gpt-4o-mini`), embedding model (default `text-embedding-3-small`), temperature, max answer tokens.

**Answering**
- *Restrict to site content* — on by default. Answers come only from indexed material; anything else gets your off-topic reply. Turn it off to let the model fall back on general knowledge.
- *Off-topic reply*, *system prompt* — both optional, both have sensible defaults.
- *Context passages* (`top_k`) and *relevance threshold* (`min_score`) — raise the threshold if answers drift, lower it if the advisor refuses too readily.

**Access**
- *Admin-only* — the widget renders for administrators only, **and** the REST endpoint refuses everyone else. Safe for testing on a live site.
- *Questions per hour* — per visitor (user ID, or IP when logged out). Administrators are exempt.

**Appearance** — eyebrow, heading, placeholder, suggested questions (one per line, up to six), a highlighted call-to-action button (label + URL), and an accent colour.

## Knowledge base tab

- **Crawl and index site** — clears indexed pages, re-crawls, then embeds. One page per request, driven from the browser, so a large site cannot hit PHP's time limit. Progress is live and the run can be stopped.
- **Additional documents** — upload `.txt`, `.md`, `.csv`, `.json`, `.html`, `.docx`, `.pdf`. Useful for price lists or policies the website does not spell out.
- **Sources table** — every page and document with its status, plus per-row delete.

Re-crawl whenever the site changes; nothing updates the index automatically.

### PDF caveat

PDF text extraction is best-effort and built in — it reads Flate-compressed and plain content streams. It handles ordinary text PDFs; it will not handle scanned PDFs, which contain images rather than text. Those are rejected with a message telling you to run OCR first. For anything important, `.txt` or `.docx` is more reliable.

## REST API

| Route | Method | Access |
| --- | --- | --- |
| `/wp-json/wp-ai-advisor/v1/ask` | POST | Visitors (honours admin-only and the rate limit) |
| `/wp-json/wp-ai-advisor/v1/status` | GET | `manage_options` |
| `/wp-json/wp-ai-advisor/v1/crawl/start`, `/crawl/step`, `/index/step` | POST | `manage_options` |
| `/wp-json/wp-ai-advisor/v1/documents` | POST | `manage_options` |
| `/wp-json/wp-ai-advisor/v1/sources/delete`, `/clear`, `/test` | POST | `manage_options` |

All routes require a valid `X-WP-Nonce` header. `POST /ask` takes `{question, history}` and returns:

```json
{
  "answer": "…",
  "links": [{ "label": "Menu", "url": "https://example.com/menu" }],
  "followups": ["…"],
  "cta": { "label": "Book a table", "url": "https://example.com/book" },
  "grounded": true
}
```

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `wp_ai_advisor_system_prompt` | filter | Change the system prompt. |
| `wp_ai_advisor_context` | filter | Inspect or replace retrieved passages before they reach the model. |
| `wp_ai_advisor_request_body` | filter | Adjust the chat completion request body. |
| `wp_ai_advisor_is_visible` | filter | Decide whether the widget renders. |
| `wp_ai_advisor_allowed_extensions` | filter | Change which document types may be uploaded. |
| `wp_ai_advisor_answered` | action | Fires after a successful answer, with question, result and context. |

## Data

Two custom tables, `{prefix}aiadv_sources` and `{prefix}aiadv_chunks`, created on activation and dropped on uninstall along with the options row. Uploaded documents go to the media library and are not removed on uninstall.

## Implementation notes

- Requests use the WordPress HTTP API, not the OpenAI PHP SDK, so the plugin ships no `vendor/` autoloader that could collide with another plugin.
- Similarity search scans the chunk table in PHP. That is the right trade for a single site; it is not built for corpus-scale data.
- Answers render as text, never HTML, so model output cannot inject markup.
- `max_tokens` is the parameter sent to the chat endpoint. Some newer OpenAI models require `max_completion_tokens` instead — use the `wp_ai_advisor_request_body` filter if you switch to one.

## Tests

```bash
php tests/logic-test.php
```

Covers URL normalisation, vector maths, HTML and document text extraction, chunking, and settings sanitisation against stubbed WordPress functions. It does not cover anything needing a database or a live API.

## License

GPL-2.0-or-later.
