# Live Chat for Telegram

[![Latest Release](https://img.shields.io/github/v/release/guilamu/live-chat-for-telegram?color=blue)](https://github.com/guilamu/live-chat-for-telegram/releases) [![License: AGPL-3.0](https://img.shields.io/badge/license-AGPL--3.0-green.svg)](LICENSE) [![WordPress: 5.9+](https://img.shields.io/badge/WordPress-5.9%2B-blue.svg)](https://wordpress.org) [![PHP: 7.4+](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)

A live chat bubble for signed in users, answered from a Telegram group.

## How it works

```
member types in the bubble  →  WordPress  →  sendMessage into their topic
you reply in the topic      →  webhook    →  WordPress  →  the bubble picks it up
```

Each member gets their own forum topic, created on first contact and reused for good, so their history survives a logout, a new device, or a six month gap — on both sides. Telegram pushes updates to a webhook, so nothing polls and nothing runs in the background. The only client you need is the Telegram app you already have.

## For Members

- Chat is restricted to signed in WordPress users — that is the whole anti-spam design, there is no anonymous way in
- Send images, documents, voice notes and video, both ways
- Pick from a built-in emoji picker in the composer, or type your own
- See a typing indicator while support is composing a reply
- Message outside opening hours and it is still accepted, with a note on when to expect an answer

## For Operators

- Reply from any phone with the Telegram app — no operator dashboard, no background process on the server
- Everyone in the group can answer; members always see one support identity rather than whichever phone replied
- Each topic is named and pinned with the member's details, pulled from their WordPress account and optionally a Gravity Forms entry
- A 👀 reaction lands on your reply once the member has read it

## Key Features

- **Multilingual:** works with content in any language
- **Translation-Ready:** all strings are internationalized
- **Secure:** timing-safe webhook secret verification, ownership-checked attachment downloads, bot token never written to a log or rendered into the settings page
- **GitHub Updates:** automatic updates from GitHub releases
- **No dependencies:** no Composer, no bundled SDK — the Bot API is called directly over `wp_remote_post`
- **One topic per member, forever:** permanent history, not a session that resets

## Requirements

- A bot token from [@BotFather](https://t.me/botfather), **dedicated to this site**
- A Telegram supergroup with Topics enabled, with the bot as an administrator able to manage topics
- HTTPS with a valid certificate, on port 443, 80, 88 or 8443 — Telegram will not deliver to anything else
- WordPress 5.9 or higher
- PHP 7.4 or higher
- Gravity Forms is optional, and only used to enrich member details

## Installation

1. Upload the `live-chat-for-telegram` folder to `/wp-content/plugins/` and activate it
2. Create a bot with `/newbot` in [@BotFather](https://t.me/botfather), then send `/setprivacy` → **Disable** for that bot
3. Create a supergroup, turn on **Topics** in its settings, add the bot, and make it an administrator with **Manage topics**
4. Go to **Live Chat → Connection**, paste the token and the group ID, save, then press **Run checks**
5. Press **Register webhook**

## FAQ

### Why must the bot be dedicated to this site?
A bot can deliver updates to exactly one webhook. Pointing a second site at the same bot silently takes the webhook away from the first, and replies stop arriving with no error anywhere. If you also run a notification plugin, give it its own bot.

### The group ID says "chat not found". What's wrong?
Supergroup IDs need a `-100` prefix that is easy to miss when copying a raw ID. The group ID field accepts the raw digits, the full `-100…` form, or a pasted `t.me/c/…` link, and normalizes it either way.

### My replies in the topic never reach the widget. Where do I look?
Run **Live Chat → Connection → Run checks** first, in order — it stops at the first broken step. If checks pass but nothing still arrives, check whether another plugin blocks anonymous REST API access sitewide (a security or "password protect the site" plugin is the usual cause); this plugin's own routes are exempted from its own lockdown, but a third-party plugin hooking the same filter can still block them.

### Can I route requests through a proxy?
Yes, for networks where Telegram is blocked, use the `lcft_api_base_url` filter:
```php
add_filter( 'lcft_api_base_url', function ( $url ) {
	return 'https://telegram-proxy.example.org';
} );
```

### Why is the chat restricted to signed in users, with no anonymous mode?
It is the anti-spam design: with no anonymous entry point, there is nothing to rate limit or CAPTCHA. Use the `lcft_user_can_chat` filter to narrow eligibility further (e.g. to a specific role or membership status).

### Can I keep the bot token out of the database?
Yes, define it in `wp-config.php` and the settings field no longer stores it:
```php
define( 'LCFT_BOT_TOKEN', '123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ' );
```

## Member details

Each topic is named from a template, and a card is pinned inside it. Both use `{placeholders}` filled from the WordPress account and, when configured, from a Gravity Forms entry.

Map the fields under **Live Chat → Member details**, one per line:

```
first_name = 66
last_name  = 67
union      = 11
department = 69
email      = 2
user_id    = 76
```

Then write a title:

```
{first_name} {last_name} — {union} ({department})
```

Placeholders that resolve to nothing are removed, along with the punctuation stranded around them: a member with no union on file gets `Marie Dupont`, not `Marie Dupont — ()`.

The entry is matched to the member by `created_by`, then by the mapped `user_id` field, then by email — whichever hits first is cached on the user.

## Limitations

These are Telegram's, not the plugin's:

- **"Support is typing…" is not possible.** The Bot API never tells a bot that a human is typing in a group. The reverse works, and you will see members typing in their topic.
- **Read receipts only run one way.** The site reacts to your message once the member has seen it. A bot cannot know what group members have read, so members are not told when you read theirs.
- **Files you send are capped at 20 MB.** `getFile` refuses anything larger, so a bigger reply cannot be mirrored back. You get a warning in the topic rather than silence. Members can upload up to 50 MB in the other direction.
- **Voice notes are Ogg/Opus**, which some versions of Safari will not play. The widget falls back to a download link rather than a dead player.

## Configuration constants

Keep secrets out of the database by defining them in `wp-config.php`:

```php
define( 'LCFT_BOT_TOKEN', '123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ' );
define( 'LCFT_WEBHOOK_SECRET', 'a-long-random-string' );
```

## Filters

```php
// Route requests through a proxy, for networks where Telegram is blocked.
add_filter( 'lcft_api_base_url', function () {
	return 'https://telegram-proxy.example.org';
} );

// Decide who may use the chat.
add_filter( 'lcft_user_can_chat', function ( $can, $user_id ) {
	return $can && ! user_can( $user_id, 'edit_posts' );
}, 10, 2 );

// Add placeholders for the topic title and pinned card.
add_filter( 'lcft_member_tokens', function ( $tokens, $user_id, $entry ) {
	$tokens['seniority'] = get_user_meta( $user_id, 'joined_year', true );

	return $tokens;
}, 10, 3 );

// Hide the bubble on specific pages.
add_filter( 'lcft_should_display', function ( $display ) {
	return $display && ! is_page( 'checkout' );
} );

// Allow more file types.
add_filter( 'lcft_allowed_mimes', function ( $mimes ) {
	$mimes['zip'] = 'application/zip';

	return $mimes;
} );

// Change the emoji offered by the composer's picker.
add_filter( 'lcft_emoji_set', function ( $emoji ) {
	return array( '👍', '🙏', '✅' );
} );
```

## Data

Three tables — conversations, messages, attachments — plus a private uploads directory. Files are never added to the media library: they are private support attachments, and Media would list them for every editor on the site.

Set a retention period under **Advanced** to delete old messages and their files daily. Deleting the plugin removes everything; the Telegram side is never touched.

## Project Structure

```
.
├── live-chat-for-telegram.php     # Main plugin file
├── uninstall.php                  # Cleanup on uninstall
├── README.md
├── assets
│   ├── css
│   │   ├── admin.css              # Settings page styling
│   │   └── widget.css             # Chat bubble styling
│   └── js
│       ├── admin.js               # Settings page interactions
│       └── widget.js              # Chat bubble client
├── languages
│   ├── live-chat-for-telegram.pot      # Translation template
│   └── live-chat-for-telegram-fr_FR.po # French translation (source)
└── includes
    ├── class-lcft-admin.php           # Settings screen
    ├── class-lcft-attachments.php     # Attachment storage and serving
    ├── class-lcft-chat.php            # Chat message handling
    ├── class-lcft-conversations.php   # Conversation and message storage
    ├── class-lcft-db.php              # Database schema and installer
    ├── class-lcft-diagnostics.php     # Connection checks
    ├── class-lcft-format.php          # Message formatting
    ├── class-lcft-github-updater.php  # GitHub auto-updates
    ├── class-lcft-member.php          # Member detail resolution
    ├── class-lcft-rest.php            # Widget-facing REST API
    ├── class-lcft-schedule.php        # Retention purge scheduling
    ├── class-lcft-settings.php        # Settings storage
    ├── class-lcft-telegram-api.php    # Telegram Bot API client
    ├── class-lcft-webhook.php         # Telegram webhook handler
    ├── class-lcft-widget.php          # Front end bubble bootstrap
    └── Parsedown.php                  # Markdown parser for the details popup
```

## Changelog

### 1.1.0 - 2026-10-05
- **Fixed:** Message length is counted in UTF-16 code units, as Telegram counts it, so long messages full of emoji are split correctly instead of being rejected; the same applies to captions and topic names
- **Fixed:** A member value containing a quote no longer breaks the pinned card when its placeholder sits inside a link (`<a href="{…}">`)
- **Fixed:** The pinned card template now keeps every tag Telegram supports (`blockquote`, `tg-spoiler`, `ins`, `del`, `strike`, spoiler `span`, `code` language class, `tg-emoji`), which were previously removed on save
- **Improved:** Saving a card template that contains unsupported HTML tags now shows a warning naming the tags that were removed
- **Changed:** The Guilamu Bug Reporter integration is removed; the **🐛 Report a Bug** link on the Plugins screen now opens a new GitHub issue

### 1.0.0 - 2026-08-04
- Initial release
- **New:** Chat bubble restricted to signed in WordPress users, with one permanent Telegram forum topic per member
- **New:** Bidirectional text and attachments (images, documents, voice, video)
- **New:** Typing indicator (member → Telegram) and read receipts (operator reply → 👀 reaction once read)
- **New:** Declared opening hours, with messages always accepted and a closed notice shown outside them
- **New:** Member details pulled from the WordPress account and, optionally, a mapped Gravity Forms entry, with a field browser that lists the form's actual fields instead of having to look up IDs by hand
- **New:** Emoji picker in the composer, with an Advanced setting to turn it off
- **New:** Merge-tag style placeholder pickers on every templated field (Welcome message, Closed message, Topic title, Pinned card)
- **New:** Avatar picker backed by the WordPress Media Library
- **New:** French translation
- **New:** GitHub auto-updates and Guilamu Bug Reporter integration

## Security

If you discover a security vulnerability in this plugin, please report it responsibly through [GitHub Security Advisories](https://github.com/guilamu/live-chat-for-telegram/security/advisories/new). Do not open a public issue for security reports.

- The webhook verifies Telegram's `X-Telegram-Bot-Api-Secret-Token` on every delivery, with a timing-safe comparison, and refuses everything when no secret is configured.
- Updates from any chat other than the configured group are ignored.
- Attachments are served through an ownership-checked route with `nosniff`, and anything that is not an image or audio file downloads rather than rendering.
- The bot token is never written to a log or rendered into the settings page.

## Contributing

Contributions are welcome! Please open an issue or submit a pull request on [GitHub](https://github.com/guilamu/live-chat-for-telegram).

For translations, the plugin uses WordPress i18n. You can contribute translations by adding `.po` files in the `languages/` directory and generating the corresponding `.mo` files with the `wp i18n` CLI commands.

## License

This project is licensed under the GNU Affero General Public License v3.0 (AGPL-3.0) — see the [LICENSE](LICENSE) file for details.

Not affiliated with, endorsed by, or sponsored by Telegram FZ-LLC.

---

Made with love for the WordPress community
