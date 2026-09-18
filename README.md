# WP AI Advisor

A WordPress plugin that adds an AI advisor powered by [Claude](https://www.anthropic.com/api). Visitors ask a question, the plugin finds matching published content on the site, and Claude answers from that content — with links back to the posts it used.

## Requirements

- WordPress 6.2+
- PHP 7.4+
- An Anthropic API key

## Installation

1. Copy this directory into `wp-content/plugins/wp-ai-advisor`.
2. Activate **WP AI Advisor** in the Plugins screen.
3. Go to **Settings → AI Advisor** and add your API key.
4. Put `[ai_advisor]` on any page or post.

### Storing the API key outside the database

Preferred for production. Add to `wp-config.php`:

```php
define( 'WP_AI_ADVISOR_API_KEY', 'sk-ant-...' );
```

The constant wins over the setting, and the admin field turns read-only.

## Shortcode

```
[ai_advisor title="Ask us anything" placeholder="Your question…" button="Ask"]
```

| Attribute | Default | Description |
| --- | --- | --- |
| `title` | `Ask the advisor` | Heading above the widget. Pass an empty string to hide it. |
| `placeholder` | `What would you like to know?` | Input placeholder. |
| `button` | `Ask` | Submit button label. |

## Settings

| Setting | Default | Notes |
| --- | --- | --- |
| Model | `claude-opus-5` | Also offers Sonnet 5 and Haiku 4.5. |
| Effort | `medium` | Reasoning depth. Higher costs more tokens and takes longer. |
| Max response tokens | `4096` | Upper bound on a single answer. |
| System prompt | built-in | Matched site content is appended to whatever you set. |
| Content to search | posts, pages | Any public post type. |
| Context items | `5` | Matching posts passed as context. `0` disables site context. |
| Require login | off | Restricts the endpoint to logged-in users. |
| Questions per hour | `10` | Per visitor (user ID, or IP for anonymous). `0` disables. |

## REST API

`POST /wp-json/wp-ai-advisor/v1/ask`

```json
{
  "question": "What are your opening hours?",
  "history": [{ "role": "user", "content": "…" }]
}
```

Requires a valid `X-WP-Nonce` header. Responds with:

```json
{
  "answer": "…",
  "sources": [{ "title": "Visit us", "url": "https://example.com/visit" }]
}
```

Errors use standard WordPress REST error shapes — `401` when login is required, `429` when rate limited, `502` when the API is unreachable.

## Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `wp_ai_advisor_system_prompt` | filter | Change the system prompt. |
| `wp_ai_advisor_context_posts` | filter | Replace or reorder the posts used as context. |
| `wp_ai_advisor_request_body` | filter | Adjust the Messages API request body before it is sent. |
| `wp_ai_advisor_answered` | action | Fires after a successful answer, with question and usage. |

## Implementation notes

- Requests go through the WordPress HTTP API (`wp_remote_post`) rather than the Anthropic PHP SDK, so the plugin ships no `vendor/` autoloader that could collide with other plugins on the same site.
- Adaptive thinking is on; `output_config.effort` controls depth.
- Server-side refusal fallbacks are enabled (`fallbacks: "default"`), so a refused request is routed to a fallback model instead of failing.
- Answers are rendered as text, not HTML, so model output cannot inject markup into the page.

## License

GPL-2.0-or-later.
