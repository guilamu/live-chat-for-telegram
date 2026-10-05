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

## Styling

The widget follows the theme on its own: with **Use the theme's main colour** ticked (the default), the accent is the Divi 5 primary colour or a block theme's `primary` preset, and the text uses the Divi 5 global fonts when they exist.

For anything finer, set these custom properties on `.lcft` in **Widget › Custom CSS** (or the theme's custom CSS): `--lcft-accent`, `--lcft-accent-hover`, `--lcft-surface`, `--lcft-text`, `--lcft-muted`, `--lcft-border`, `--lcft-them` (operator bubbles), `--lcft-radius`, `--lcft-control-radius`, `--lcft-shadow`, `--lcft-font`, `--lcft-heading-font`. Two more are set for you: `--lcft-member-initial` and `--lcft-agent-initial`, the first letter of the member's first name and of the support name, ready for `content: var( --lcft-member-initial )`. Divi 5 variables can be used directly:

```css
body .lcft {
	--lcft-accent: var(--gcid-primary-color);
	--lcft-heading-font: var(--et_global_heading_font);
}
```

The `<body>` gets the `lcft-chat-active` class wherever the member can chat, so a contact button the bubble replaces can be hidden only there:

```css
body.lcft-chat-active .my-contact-button { display: none !important; }
```

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

// Treat another editor's preview as a page builder (the bubble is hidden there by default).
add_filter( 'lcft_is_builder_request', function ( $is_builder ) {
	return $is_builder || isset( $_GET['my_builder_preview'] );
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

### 1.1.8 - 2026-10-05
- **New:** The chat bubble is hidden while editing pages (Divi Visual Builder, block editor and customizer previews, Elementor, Beaver Builder…). A "Page builders" option on the Widget tab shows it again, and the `lcft_is_builder_request` filter adjusts the detection

### 1.1.7 - 2026-10-05
- **New:** The conversation shows the date once per day, above that day's first message (Today, Yesterday, then the full date)

### 1.1.6 - 2026-10-05
- **New:** `--lcft-member-initial` and `--lcft-agent-initial` custom properties on the chat, holding the first letter of the member's first name and of the support name, for sites that draw a letter avatar beside each message

### 1.1.5 - 2026-10-05
- **New:** Paste a screenshot straight into the message box (Ctrl+V / Cmd+V), or drop a file on the chat panel
- **Fixed:** A message sent from the chat no longer vanishes from the conversation until the page is reloaded (regression in 1.1.4)
- **Fixed:** The send button no longer touches the panel's edge in Firefox and Safari, where the message box would not shrink to make room
- **Improved:** The emoji, attach and send buttons use clean SVG icons matching the chat button, instead of emoji
- **Improved:** The file about to be sent is shown above the message box, with a thumbnail for images and a button to remove it

### 1.1.4 - 2026-10-05
- **Fixed:** A member message that failed to reach Telegram is now really sent again, in order: with the member's next message, while the chat is open, and every five minutes in the background
- **Fixed:** A member's first message no longer appears twice (once as "not delivered") while its topic is being created
- **Improved:** Instead of a "not delivered" warning, a message on its way shows three animated dots until it reaches Telegram

### 1.1.3 - 2026-10-05
- **Improved:** The three dots of the chat button bounce briefly every 30 seconds, like someone typing (disabled when the visitor prefers reduced motion)

### 1.1.2 - 2026-10-05
- **Fixed:** The pinned card help text now lists the Telegram tags actually accepted
- **Improved:** The chat button shows a clean SVG speech bubble in the button's text colour instead of the 💬 emoji

### 1.1.1 - 2026-10-05
- **Fixed:** The accent colour picked in the settings is now actually applied; the stylesheet's default used to override it
- **New:** The widget follows the theme's main colour (Divi 5 global colours, block theme presets) and Divi 5 global fonts, and exposes more custom properties for styling
- **New:** Custom CSS field in the widget settings, loaded after the widget stylesheet
- **New:** `lcft-chat-active` body class wherever the member can chat

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
