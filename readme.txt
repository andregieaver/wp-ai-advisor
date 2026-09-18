=== WP AI Advisor ===
Contributors: andregieaver
Tags: ai, openai, chatbot, assistant, support
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.3.0
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

= Can it read scanned PDFs? =

No. Those contain images rather than text. Run OCR first, or upload a .txt file.

== Changelog ==

= 0.3.0 =
* Added local content mode: index posts directly from WordPress, with crawl, local or both.
* Shared the HTML-to-text and link extraction between both modes.

= 0.2.0 =
* Switched to the OpenAI API with embedding-based retrieval.
* Added the site crawler, document uploads and the Knowledge base tab.
* Added strict grounding, the admin-only toggle and the new conversation UI.

= 0.1.0 =
* Initial release.
