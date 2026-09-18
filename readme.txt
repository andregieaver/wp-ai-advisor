=== WP AI Advisor ===
Contributors: andregieaver
Tags: ai, claude, chatbot, assistant, support
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An AI advisor powered by Claude that answers visitor questions from your own site content.

== Description ==

WP AI Advisor adds a question box to any page. When a visitor asks something, the plugin
searches your published content, hands the matching posts to Claude as context, and shows
the answer along with links to the posts it drew on.

Features:

* `[ai_advisor]` shortcode with configurable title, placeholder, and button label
* Choose which public post types are searched, and how many results become context
* Model and reasoning-effort selection
* Optional login requirement and per-visitor hourly rate limiting
* API key can live in `wp-config.php` instead of the database
* Filters for the system prompt, context posts, and the outgoing request body

== Installation ==

1. Upload the plugin to `wp-content/plugins/wp-ai-advisor` and activate it.
2. Go to Settings → AI Advisor and enter your Anthropic API key.
3. Add `[ai_advisor]` to a page.

== Frequently Asked Questions ==

= Where do I get an API key? =

From the Anthropic Console. For production sites, define `WP_AI_ADVISOR_API_KEY` in
`wp-config.php` so the key never touches the database.

= Does it send my whole site to the API? =

No. Only the posts matching a given question are sent, trimmed to an excerpt each, and
only for the post types you enable.

== Changelog ==

= 0.1.0 =
* Initial release.
