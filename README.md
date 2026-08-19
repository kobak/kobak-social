# kobak social

A WordPress plugin that shares every newly published post to Bluesky and Mastodon, with optional LinkedIn support, and adds `u-syndication` links under the post for IndieWeb setups.

On the plugin directory: [wordpress.org/plugins/kobak-social](https://wordpress.org/plugins/kobak-social)

Hungarian install and setup guide: [OLVASSEL.md](OLVASSEL.md).

## What it does

- Posts to Bluesky and Mastodon when a post is published for the first time. Editing an existing post never re-posts it.
- LinkedIn is optional and needs your own LinkedIn app, because LinkedIn does not hand out shared API access.
- Stores the URL of each copy and lists them under the post with the `u-syndication` microformat, so Brid.gy and a webmention receiver can bring replies back.
- Can hide the local comment form, to move the conversation to social.
- Retries a failed share three times, five then ten minutes apart. A failure that retrying cannot fix — a missing or invalid credential, or a 4xx other than 408 and 429 — is reported at once instead.

Saved tokens are never written back into the settings page HTML.

## Requirements

WordPress 6.0 or newer, PHP 7.4 or newer.

## Adding another network

Since 2.0 each network is a single file behind the `Kobak_Social_Platform` interface:

```php
interface Kobak_Social_Platform {
    public function id();                      // 'bluesky'
    public function label();                   // 'Bluesky'
    public function limit();                   // character limit for the message
    public function is_enabled( $settings );   // should we attempt this platform at all
    public function settings_fields( $settings );
    public function save_fields( $current );   // this platform's values, read from $_POST
    public function publish( $post, $settings, $force = false );
}
```

Register it from your own plugin or theme:

```php
add_filter( 'kobak_social_platforms', function ( $platforms ) {
    require_once __DIR__ . '/class-my-network.php';
    $platforms[] = new My_Network();
    return $platforms;
} );
```

Nothing in this plugin needs to be edited. The registry drives the posting loop, the settings page, the save handler and the syndication links alike.

A platform that is enabled but misconfigured must return a `WP_Error` from `publish()` rather than quietly doing nothing — that is what keeps the log honest. Attach the HTTP status as error data (`array( 'status' => $code )`) so the retry logic can tell a dead token from a timeout.

Two more filters:

- `kobak_social_message` — `( $text, $post, $platform_id )`, to change the message per network.
- `kobak_social_syndication_urls` — `( $urls, $post_id )`, to change the list of syndication links.

## Layout

```
kobak-social.php                 bootstrap: header, constants, requires, hook wiring
includes/
  interface-kobak-social-platform.php
  class-kobak-social-registry.php      collects platforms, exposes the filter
  class-kobak-social-settings.php      defaults and reading
  class-kobak-social-sharer.php        sharing, logging, retry and failure handling
  class-kobak-social-admin.php         settings page and admin notices
  class-kobak-social-syndication.php   syndication links and comment handling
  platforms/
    class-kobak-social-bluesky.php
    class-kobak-social-mastodon.php
    class-kobak-social-linkedin.php
languages/                       .pot, and the Hungarian .po/.mo
```

## Translations

User-facing strings are English and go through the `kobak-social` text domain. A Hungarian translation ships with the plugin; other languages are welcome through [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/kobak-social/).

When adding a string, regenerate the template from every PHP file, not just the main one:

```bash
find . -name "*.php" | sort | xargs xgettext --language=PHP --from-code=UTF-8 --no-location --no-wrap --force-po -k --keyword=__ --keyword=_e --keyword=esc_html__ --keyword=esc_html_e --keyword=esc_attr__ --keyword=esc_attr_e --keyword=_x:1,2c --keyword=esc_html_x:1,2c --keyword=_n:1,2 --keyword=_nx:1,2,4c -o languages/kobak-social.pot
```

## License

GPL-2.0-or-later. See the plugin header and `readme.txt`.
