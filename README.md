# WP AI Advisor

A WordPress plugin that adds an "ask us anything" conversation panel to any page. It answers visitor questions using **your own site content** — crawled from the site itself, plus any documents you upload — through the OpenAI API.

Fully translatable, and shipped with a Norwegian Bokmål translation.

Questions outside that material are refused rather than answered from the model's general knowledge.

## How it works

1. **Collect.** Either **crawl** the site over HTTP (sitemap first, then internal links), **read posts locally** from this WordPress install, or both. Each page is stripped to readable text, and its internal links are recorded.
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
4. Open the **Knowledge base** tab and press **Build knowledge base**.
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
| `lang` | page language | Overrides the language the widget reports, e.g. `lang="nb"`. |
| `eyebrow` | from settings | Small uppercase label above the heading. |
| `heading` | from settings | Headline on the collapsed card. |
| `placeholder` | from settings | Composer placeholder. |
| `theme` | `dark` | `dark` or `light`. |
| `open` | `no` | `yes` renders the panel already open. |

The container is fluid: it fills its parent up to `56rem`, drops from two columns to one below `48em`, and tightens its padding and controls below `30em`. Colours come from CSS custom properties on `.aiadv`, so a theme can override them without touching the stylesheet.

## Page-aware widgets

Drop the widget on a product template and it answers about the product the visitor is reading:

```
[ai_advisor layout="compact"]
```

"Hva er spesielt med denne kaffemaskinen?" has nothing for semantic search to match — the pronoun carries no meaning. So context is supplied rather than inferred:

- The widget reports the post it sits on. The endpoint re-validates that ID server-side and accepts only published posts of public types, so a widget cannot be pointed at a draft.
- That post's own indexed passages are **pinned** to the front of the context, whatever the question scored, and duplicate semantic hits are dropped.
- Its live field values are stated in the prompt as current and authoritative, so an edit applies immediately without re-indexing.
- The model is told that "this", "denne" and "dette" mean that page, and to answer about it by default.

A page-aware widget also gets its own suggested questions, and a question answered from the page's facts counts as grounded — so a product question no longer falls through to the off-topic reply.

| Attribute | Default | Description |
| --- | --- | --- |
| `context` | `auto` | `auto` adopts the current post on a singular view. `none` disables it. A post ID pins a specific page. |
| `layout` | `full` | `compact` is smaller, ranged left, without the hero heading — for a sidebar or product page. |
| `suggestions` | from settings | Inline overrides, separated by `\|`. |

### Price range field

Set **Price range field** under **Settings → AI Advisor → Page context** to the name of a custom field holding a price range as text — an ACF text field works as-is, since ACF stores plain text under the field name. The default is `hvor_mye_koster_det`.

The field name is treated as a meta key, so its case is preserved: `priceRange` and `pricerange` are different fields.

When the field holds a value it is:

- **stated live** in the prompt for the page the visitor is on, so edits in the product editor apply immediately;
- **indexed** with the post, so a price question works from anywhere on the site (needs a re-import to pick up);
- **quoted as a range**, in the shop's own words. The model is told not to present one end as the price and not to recalculate it — which also keeps it out of the estimate calculator's hands.

The settings screen shows a live example of the field it found, so a wrong field name is obvious before you rely on it.

Use the `wp_ai_advisor_page_facts` filter to expose more fields — stock, lead time, SKU.

## Estimates

Asked "what would this cost for 50 employees?", the advisor works the figure out rather than refusing — under constraints that keep it honest:

- **Prices come from your content only.** Every price, rate and fee must appear in a retrieved excerpt. The model may not invent one or adjust one. If a needed price is not indexed, the answer says which figure is missing and offers contact instead of guessing.
- **Arithmetic is done by the plugin.** Language models predict plausible digits rather than computing, so the model writes the expression and PHP evaluates it exactly, through a hand-written parser — not `eval()`, since the expression originates from a model reading visitor input. Anything that is not arithmetic is refused.
- **Assumptions are declared.** Figures your content cannot supply — cups per person per day, working days per month — come from an editable list under **Settings → AI Advisor → Estimates**, and the answer is required to say which numbers were assumptions.

The result reads like: *"Regner vi 2,5 kopper per ansatt per dag og 21 arbeidsdager, blir det … rundt X kroner i måneden."* It is labelled an estimate and points at getting a real quote.

Turn the whole thing off with **Work out estimates** if you would rather the advisor never produced a number.

## Languages

The plugin is multilingual in three separate senses, and it is worth keeping them apart.

**1. The plugin's own interface.** Every string goes through WordPress i18n against the `wp-ai-advisor` text domain. A complete Norwegian Bokmål translation (`nb_NO`) ships in `languages/`, covering the admin screens, the widget, and the built-in prompt text. Set WordPress to Norsk bokmål and it loads automatically. `languages/wp-ai-advisor.pot` is there for any other locale.

**2. What language the assistant answers in.** Set under **Settings → AI Advisor → Language**:

| Setting | Behaviour |
| --- | --- |
| **Match the visitor** (default) | Answers in the language the question was written in. Falls back to the language of the page the widget sits on. |
| **Always use the language of the page** | Ignores the question's language. Useful when you want one consistent voice. |
| **A specific language** | Always answers in that language, whatever is asked. |

The widget reports its own language to the endpoint via `lang` on the container, so on a translated site the Norwegian page and the English page behave differently without any extra configuration. Follow-up chips and link labels are written in the same language as the answer.

**3. Multilingual content.** Every indexed source records the language it is in — from `<html lang>` when crawling, from Polylang or WPML when importing locally, and from the dropdown when uploading a document. Retrieval then prefers passages in the visitor's language: a Norwegian question is answered from Norwegian pages rather than the English translation of the same page. If nothing in that language clears the relevance threshold, every language is reconsidered rather than refusing, and the model is told to translate what it needs instead of switching language mid-answer.

On Polylang and WPML sites, local mode indexes **every** translation, each tagged with its own language. Sources indexed before this version carry no language and stay eligible for every question — rebuild the knowledge base to tag them.

### Adding another translation

```bash
cp languages/wp-ai-advisor.pot languages/wp-ai-advisor-nn_NO.po   # then translate it
python3 bin/po2mo.py languages/wp-ai-advisor-nn_NO.po
```

`python3 bin/make-pot.py` regenerates the POT after you add or change strings; it exits non-zero and names the offenders if any string has the wrong text domain or is not a plain literal. Norwegian Nynorsk is **not** included — Bokmål is not a substitute for it, so it is left to a Nynorsk speaker rather than guessed at.

The widget uses CSS logical properties throughout, so it mirrors correctly in right-to-left locales without a separate stylesheet.

## Choosing a knowledge source

Set this under **Settings → AI Advisor → Knowledge source**.

| Mode | Reads | Good for | Trade-off |
| --- | --- | --- | --- |
| **Crawl** (default) | The rendered site over HTTP | Seeing exactly what a visitor sees, including theme-rendered menus and anything a page builder outputs | Needs the site reachable from the server; bounded by the page limit; misses unlinked pages |
| **Local** | Published posts from this install | Speed and completeness — no HTTP, no page budget, reaches pages nothing links to | Sees post content only, so theme-rendered navigation and non-post templates are invisible |
| **Both** | Both of the above | Navigation from the crawl, completeness from the database | Pages covered twice cost extra to index and can return near-duplicate passages |

Local mode indexes the post types you select, published only — drafts, private and password-protected posts are skipped. Content runs through `the_content` with the loop globals set up, so shortcodes and blocks resolve the way the theme renders them; title, excerpt and public taxonomy terms are indexed alongside the body. It is capped at 5000 posts.

Neither mode updates itself. Re-run a build after the site changes.

## Settings

**OpenAI connection** — API key, chat model (default `gpt-4o-mini`), embedding model (default `text-embedding-3-small`), temperature, max answer tokens.

**Knowledge source** — mode, local post types, crawl base URL, page limit, URL fragments to skip, plus:

- *Avoid duplicates* (on by default) — in both-modes, skips local posts whose URL the crawl already covered. Without it the same page is indexed twice, costing tokens and returning near-duplicate passages.
- *Render with theme filters* (on by default) — runs local content through `the_content` so shortcodes and page-builder markup resolve as the theme renders them. Turn it off if importing fails on a hostile plugin: blocks are still rendered, but shortcode output is dropped.

**Answering**
- *Restrict to site content* — on by default. Answers come only from indexed material; anything else gets your off-topic reply. Turn it off to let the model fall back on general knowledge.
- *Off-topic reply*, *system prompt* — both optional, both have sensible defaults.
- *Context passages* (`top_k`) and *relevance threshold* (`min_score`) — raise the threshold if answers drift, lower it if the advisor refuses too readily.

**Language** — reply language.

**Access**
- *Admin-only* — the widget renders for administrators only, **and** the REST endpoint refuses everyone else. Safe for testing on a live site.
- *Questions per hour* — per visitor (user ID, or IP when logged out). Administrators are exempt.

**Appearance** — eyebrow, heading, placeholder, suggested questions (one per line, up to six), a highlighted call-to-action button (label + URL), and an accent colour.

## Knowledge base tab

- **Build knowledge base** — clears and rebuilds: runs whichever phases the mode calls for, then embeds. One source per request, driven from the browser, so a large site cannot hit PHP's time limit. Progress is live and the run can be stopped.
- **Resume** — picks up wherever the last run stopped, clearing nothing. Use this after closing the tab mid-build, or after a run reports a problem. It fetches whatever is still waiting to be fetched, then embeds whatever is waiting to be embedded.
- **Retry failed** — appears only when something failed. Puts failed sources back in the queue and resumes: sources that already hold text go straight back to embedding, ones that never got text go back to the crawl queue.
- **Crawl only** / **Import local content only** — run a single phase, shown when the mode includes it. Each clears and rebuilds just its own sources; uploaded documents are never touched.

Under the table, **Duplicates** lists sources whose URL another source already covers — the later row of each pair, so deleting everything listed always leaves one copy. Tick rows for bulk **Delete** or **Queue for crawling again**, or use **Delete all N duplicates** in one go.

The counters distinguish **To fetch** (queued, not yet retrieved) from **To index** (retrieved, not yet embedded), so a stalled run tells you which half stopped. Under the sources table, the filter links narrow it by status — **Failed** shows exactly what went wrong, with the reason on each row.

A run survives transient failures: each step is retried up to three times with a backoff, and a phase that still gives up no longer cancels the phases after it. Nothing is lost when a run stops — Resume continues from the queue.

Importing local content means standing inside `the_content`, which is a hostile place outside a real front-end request: other plugins hook it and may echo markup — corrupting the JSON response — or call template functions that do not exist in REST, which is a fatal. So the filter runs inside an output buffer with stray output discarded, crashes fall back to rendering the stored blocks, every admin endpoint returns JSON even when something under it explodes, and each source records an attempt *before* the risky work. That last one matters: a source that crashes the request uncatchably (a timeout, an exhausted memory limit) is set aside after three tries instead of trapping the queue on one row forever.
- **Additional documents** — upload `.txt`, `.md`, `.csv`, `.json`, `.html`, `.docx`, `.pdf`. Useful for price lists or policies the website does not spell out.
- **Sources table** — every page, imported post and document with its status, plus per-row delete.

### PDF caveat

PDF text extraction is best-effort and built in — it reads Flate-compressed and plain content streams. It handles ordinary text PDFs; it will not handle scanned PDFs, which contain images rather than text. Those are rejected with a message telling you to run OCR first. For anything important, `.txt` or `.docx` is more reliable.

## REST API

| Route | Method | Access |
| --- | --- | --- |
| `/wp-json/wp-ai-advisor/v1/ask` | POST | Visitors (honours admin-only and the rate limit) |
| `/wp-json/wp-ai-advisor/v1/status` | GET | `manage_options` |
| `/wp-json/wp-ai-advisor/v1/build/start` | POST | `manage_options` |
| `/wp-json/wp-ai-advisor/v1/crawl/start`, `/crawl/step` | POST | `manage_options` |
| `/wp-json/wp-ai-advisor/v1/local/start`, `/local/step` | POST | `manage_options` |
| `/wp-json/wp-ai-advisor/v1/index/step` | POST | `manage_options` |
| `/wp-json/wp-ai-advisor/v1/documents` | POST | `manage_options` |
| `/wp-json/wp-ai-advisor/v1/sources/delete`, `/clear`, `/test` | POST | `manage_options` |

All routes require a valid `X-WP-Nonce` header. `POST /ask` takes `{question, history, language, post_id}` and returns:

```json
{
  "answer": "…",
  "links": [{ "label": "Menu", "url": "https://example.com/menu" }],
  "followups": ["…"],
  "cta": { "label": "Book a table", "url": "https://example.com/book" },
  "grounded": true,
  "language": "nb",
  "calculations": [{ "label": "Kaffe per måned", "expression": "50 * 2.5 * 21 * 1.9", "value": 4987.5 }]
}
```

`calculations` records every expression the model asked for and what it evaluated to, so an estimate can be checked rather than taken on trust.

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `wp_ai_advisor_system_prompt` | filter | Change the system prompt. |
| `wp_ai_advisor_context` | filter | Inspect or replace retrieved passages before they reach the model. |
| `wp_ai_advisor_request_body` | filter | Adjust the chat completion request body. |
| `wp_ai_advisor_is_visible` | filter | Decide whether the widget renders. |
| `wp_ai_advisor_allowed_extensions` | filter | Change which document types may be uploaded. |
| `wp_ai_advisor_local_post_ids` | filter | Change which posts local mode indexes. |
| `wp_ai_advisor_assumptions` | filter | Change the figures offered for estimates. |
| `wp_ai_advisor_page_facts` | filter | Add fields to the current-page block. |
| `wp_ai_advisor_pinned_passages` | filter | How many of the page's own passages are pinned. |
| `wp_ai_advisor_answered` | action | Fires after a successful answer, with question, result and context. |

## Data

Two custom tables, `{prefix}aiadv_sources` and `{prefix}aiadv_chunks`, created on activation and dropped on uninstall along with the options row. Uploaded documents go to the media library and are not removed on uninstall.

## Implementation notes

- Requests use the WordPress HTTP API, not the OpenAI PHP SDK, so the plugin ships no `vendor/` autoloader that could collide with another plugin.
- Similarity search scans the chunk table in PHP. That is the right trade for a single site; it is not built for corpus-scale data.
- Answers are Markdown, rendered into the page by building DOM nodes with `textContent` — never `innerHTML`. Model output therefore cannot inject markup, and only `http(s)` and relative links become anchors.
- `max_tokens` is the parameter sent to the chat endpoint. Some newer OpenAI models require `max_completion_tokens` instead — use the `wp_ai_advisor_request_body` filter if you switch to one.

## Tests

```bash
php tests/logic-test.php     # 119 checks
node tests/markdown-test.js  # 19 checks
```

The PHP suite covers URL normalisation, vector maths, HTML and document text extraction, link resolution, chunking, the expression evaluator (including shell calls, statement separators, division by zero and runaway exponents, all of which must be refused), page-context validation (drafts, private, password-protected and non-public types must all be refused), settings migration, language detection, settings sanitisation, and translation coverage — the last of these fails if any extracted string lacks a Norwegian translation or loses a `printf` placeholder. It runs against stubbed WordPress functions and does not cover anything needing a database or a live API. The JS suite builds a minimal DOM and checks the Markdown renderer's output alongside its safety property: `javascript:` and `data:` URLs never become anchors.

## License

GPL-2.0-or-later.
