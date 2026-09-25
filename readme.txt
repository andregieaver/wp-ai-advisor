=== WP AI Advisor ===
Contributors: andregieaver
Tags: ai, openai, chatbot, assistant, multilingual
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI advisor powered by OpenAI that answers visitor questions from your own site content.

== Description ==

WP AI Advisor adds a conversation panel to any page. It crawls your own website, indexes
the text, and answers visitor questions from that material only — with follow-up
suggestions and links back to the pages it used.

Features:

* `[ai_advisor]` shortcode, fully responsive, dark or light
* Built-in crawler that follows your sitemap and internal links
* Or index posts directly from WordPress, with no HTTP requests — or do both
* Upload extra documents (txt, md, csv, json, html, docx, pdf)
* Answers restricted to your own content, with a configurable off-topic reply
* Admin-only mode for testing on a live site
* Per-visitor hourly rate limiting
* API key can live in `wp-config.php` instead of the database
* Fully translatable, with a Norwegian Bokmål translation included
* Answers in the visitor's language, and prefers passages in that language
* Polylang and WPML aware: every translation is indexed and tagged
* Page-aware on product and post templates: answers about what the visitor is reading
* Reads a price range from a custom field, such as an ACF field, live from the page
* Works out cost estimates from your own prices, with the arithmetic done in PHP
* Answers render Markdown: lists, bold and links instead of raw asterisks
* Filters for the system prompt, retrieved context, and the outgoing request body

== Installation ==

1. Upload the plugin to `wp-content/plugins/wp-ai-advisor` and activate it.
2. Go to Settings → AI Advisor and enter your OpenAI API key.
3. Open the Knowledge base tab and run "Build knowledge base".
4. Add `[ai_advisor]` to a page.

== Frequently Asked Questions ==

= Where do I get an API key? =

From platform.openai.com. For production sites, define `WP_AI_ADVISOR_API_KEY` in
`wp-config.php` so the key never touches the database.

= Does it send my whole site to the API? =

Indexing embeds your page text once. After that, each question sends only the handful of
passages that match it.

= Does the index update itself? =

No. Re-run a build after you change the site.

= Crawl or local mode? =

Crawl sees the rendered site, menus included, but needs the site reachable from the
server and obeys a page limit. Local mode reads posts straight from the database: faster,
unlimited, and it finds unlinked pages, but it cannot see theme-rendered navigation.
Choose Both to get each one's strengths at the cost of some duplicate indexing.

= Does it work on Norwegian sites? =

Yes. A Norwegian Bokmål translation is included, so set WordPress to Norsk bokmål and the
admin screens, the widget and the built-in prompts are all in Norwegian. The assistant
replies in whatever language the visitor writes in by default.

= Does it handle a site in several languages? =

Yes. Each indexed page records its language, and a question gets answered from pages in
that same language where possible. On Polylang and WPML sites every translation is
indexed separately.

= A build stopped before it finished. Do I have to start over? =

No. Press Resume on the Knowledge base tab and it carries on from the queue. Use Build
knowledge base only when you want to clear everything and rebuild from scratch.

= Importing local content fails with a server error. What now? =

A plugin or theme is misbehaving when the advisor renders post content. Turn off "Render
with theme filters" under Knowledge source and import again; blocks are still read, but
output produced by shortcodes is dropped.

= Can it tell a visitor what something will cost? =

Yes, if the prices are in your indexed content. It states the assumptions it used, works
the arithmetic out in PHP rather than guessing, and calls the result an estimate. If a
price it needs is missing it says so instead of inventing one. Turn it off under
Settings, AI Advisor, Estimates.

= Can it answer about the product the visitor is looking at? =

Yes. Use [ai_advisor layout="compact"] in a product template. The widget adopts that
product, pins its content into every answer, and reads its price range field live.

= Can it read scanned PDFs? =

No. Those contain images rather than text. Run OCR first, or upload a .txt file.

== Changelog ==

= 0.6.0 =
* The widget can now answer about the page it sits on, so "what is special about this one" works.
* Added a compact layout for product pages and sidebars, with its own suggested questions.
* Added a price range custom field, read live from the page and indexed with the post.

= 0.5.0 =
* The advisor can now work out estimates instead of refusing "what would this cost" questions.
* Arithmetic runs through a restricted expression evaluator in PHP, never the model's guesswork.
* Prices must still come from indexed content; assumptions are configurable and always stated.
* Answers now render Markdown — lists, bold, headings and links — instead of showing raw syntax.

= 0.4.2 =
* Fixed local import failing with a 500 when a plugin or theme misbehaves on the_content.
* Admin endpoints now always return JSON, never a half-rendered HTML error page.
* A source that repeatedly crashes the request is set aside instead of blocking the queue.
* Added duplicate detection, a Duplicates view and one-click removal.
* Added bulk select with Delete and Queue-for-crawling-again.
* Both-modes now skips local posts the crawl already covered, by default.
* Added a setting to import local content without running the_content.

= 0.4.1 =
* Added Resume, which continues a build from wherever it stopped instead of starting over.
* Added Retry failed, and a status filter so failed sources and their reasons are easy to find.
* Counters now separate sources waiting to be fetched from those waiting to be indexed.
* Steps are retried before giving up, and one failing phase no longer cancels the rest of the run.

= 0.4.0 =
* Added multilingual support: translatable throughout, with a Norwegian Bokmål translation.
* Answers follow the visitor's language, configurable per site.
* Indexed sources record their language; retrieval prefers the visitor's language.
* Polylang and WPML translations are indexed and tagged individually.
* Widget uses CSS logical properties so it mirrors in right-to-left locales.


= 0.3.0 =
* Added local content mode: index posts directly from WordPress, with crawl, local or both.
* Shared the HTML-to-text and link extraction between both modes.

= 0.2.0 =
* Switched to the OpenAI API with embedding-based retrieval.
* Added the site crawler, document uploads and the Knowledge base tab.
* Added strict grounding, the admin-only toggle and the new conversation UI.

= 0.1.0 =
* Initial release.
