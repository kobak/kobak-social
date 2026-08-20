=== kobak social: share posts to Mastodon and Bluesky ===
Contributors: kobak
Tags: bluesky, mastodon, indieweb, syndication, webmention
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publishes every new post to Bluesky and Mastodon and adds u-syndication links. LinkedIn support is optional.

== Description ==

You write a post. It should appear on your social accounts. That used to be a solved problem, and then it wasn't.

This plugin does the boring part: when a post is published for the first time, it posts a message to Bluesky and Mastodon, remembers the URLs it created, and lists them under the post with the `u-syndication` microformat.

It only runs on the transition to published. Editing an old post never re-shares it.

= About replies coming back =

Be clear about what this plugin does and does not do. It **publishes** your posts and **marks up** the syndicated copies. It does **not** receive webmentions, and it does not talk to Brid.gy.

Bringing replies back from Bluesky and Mastodon needs three separate pieces:

1. The `u-syndication` links, so a service can tell which social post belongs to which article. **This plugin provides that.**
2. A webmention receiver on your site, for example the [Webmention](https://wordpress.org/plugins/webmention/) plugin. **Not included here.**
3. [Brid.gy](https://brid.gy/), a free external service you connect to your accounts, which watches for replies and sends them to your site as webmentions. **Not included here, and not affiliated with this plugin.**

Without the other two, this plugin still shares your posts perfectly well. You simply will not get replies back, and the syndication box is just a list of links.

= What it does =

* Posts to **Bluesky** and **Mastodon** when a post is first published
* Handles the character limits (300 on Bluesky, 500 on Mastodon): the excerpt is shortened, the title and the link always survive
* Makes links in the Bluesky post clickable, using proper richtext facets
* Stores the URL of every copy it created and shows a syndication box under the post
* Can hide the local comment form **without breaking webmentions**, for people who collect replies on social instead
* Keeps a short log of what was sent and what came back
* Sends every request through the WordPress HTTP API, so it also works on hosts where `curl_exec()` is disabled

= LinkedIn is optional =

LinkedIn is deliberately a separate, opt-out-by-default section. Bluesky and Mastodon need nothing but an app password or a token, and take two minutes to set up. LinkedIn is a different story:

* You have to create your own LinkedIn app and request the *Share on LinkedIn* and *Sign In with LinkedIn using OpenID Connect* products
* You have to generate an access token yourself, with the `w_member_social`, `openid` and `profile` scopes
* **That token expires after roughly 60 days** and has to be generated again by hand

If that sounds like too much, leave the LinkedIn section switched off and ignore it. Everything else works without it. If you do turn it on, the post goes out as a link card with your featured image, and there are two buttons in the settings: one that checks the connection without posting anything, and one that posts a test to LinkedIn only.

= What it is not =

It is not a full social media scheduler. There is no queue, no analytics, no per-network message, no reposting of old content. It shares new posts, and that is all.

== External services ==

This plugin sends data to third party services. Nothing is transmitted until you enter credentials for a service and enable sharing.

**Bluesky (AT Protocol)**
Used to publish your post to Bluesky. On each publish the plugin sends your handle and app password to `https://bsky.social/xrpc/com.atproto.server.createSession` to obtain a session, then sends the message text and your post URL to `https://bsky.social/xrpc/com.atproto.repo.createRecord`.
Terms of service: https://bsky.social/about/support/tos — Privacy policy: https://bsky.social/about/support/privacy-policy

**Mastodon**
Used to publish your post to the Mastodon instance you configure. The plugin sends your access token, the message text and your post URL to `/api/v1/statuses` on that instance. The instance is chosen by you, so its terms and privacy policy are those of that server. For the default suggestion, mastodon.social: https://mastodon.social/terms-of-service and https://mastodon.social/privacy-policy

**LinkedIn (only if you enable it)**
Used to publish your post to your LinkedIn profile. The plugin sends your access token to `https://api.linkedin.com/v2/userinfo` to look up your member URN, uploads your featured image to `https://api.linkedin.com/rest/images`, and sends the message text, post title, excerpt and URL to `https://api.linkedin.com/rest/posts`.
Terms of service: https://www.linkedin.com/legal/user-agreement — Privacy policy: https://www.linkedin.com/legal/privacy-policy

Credentials you enter are stored in your own WordPress database and are never sent anywhere except to the service they belong to.

== Installation ==

1. Install and activate the plugin.
2. Go to **Settings → kobak social**.
3. Enter your Bluesky handle and an app password (Bluesky: Settings → Privacy and security → App passwords). Do not use your account password.
4. Enter your Mastodon instance URL and an access token (Mastodon: Preferences → Development → New application, scope `write:statuses`).
5. Tick **Automatic sharing**.
6. Optional: set up LinkedIn as described in the FAQ.
7. Publish a post, or use the test button to try it with your latest post.

== Frequently Asked Questions ==

= Will it re-share my old posts? =

No. It only fires when a post moves from draft, pending or scheduled to published. Editing a published post does nothing.

= Can I change the message? =

Yes. The template supports `{title}`, `{excerpt}` and `{url}`. If the result is too long for a network, the excerpt is trimmed and the title and link are kept.

= How do I set up LinkedIn? =

Create an app at the LinkedIn developer portal, add the *Share on LinkedIn* and *Sign In with LinkedIn using OpenID Connect* products, then generate an access token with the `w_member_social`, `openid` and `profile` scopes. Paste the token, not the Client Secret, into the LinkedIn section. Use the **Check the LinkedIn connection** button first: it verifies the token and stores your author URN without posting anything.

= My LinkedIn share stopped working =

The token expired. LinkedIn tokens last about 60 days. The log will show a 401 error. Generate a new token and paste it in.

= Will this bring replies from Bluesky and Mastodon back to my site? =

Not on its own. This plugin only publishes your posts and adds the `u-syndication` links. To actually receive replies you also need a webmention receiver, such as the Webmention plugin, and an external service like Brid.gy connected to your accounts. See the description for how the three pieces fit together.

= Does turning off local comments break webmentions? =

No, and that is the point. Webmentions arrive over the REST API, where the plugin leaves `comments_open` alone. Only the visitor-facing comment form is hidden, so if you do run a webmention plugin, the replies it receives still appear under the post.

= It does not post anything =

Check the log at the bottom of the settings page: it records the result of every attempt. Sharing runs 30 seconds after publishing via WP-Cron, so if cron is disabled on your site, nothing will fire.

= Does it work if my host disabled curl_exec()? =

Yes. Every request goes through `wp_remote_*`, which falls back to a stream based transport when cURL is unavailable.

== Screenshots ==

1. The settings screen: Bluesky and Mastodon on top, LinkedIn as an optional section below.
2. The syndication box under a published post.
3. The log, showing what was sent where.

== Changelog ==

= 2.0.1 =
* A network you have not set up is no longer reported as a failed share. Bluesky and Mastodon were attempted on every post even with no credentials at all, so anyone using just one of them collected a permanent red error notice in the admin for something they never wanted
* A network is now attempted once you have started setting it up, so a half-finished setup — a Bluesky handle with no app password — still tells you what is missing instead of going quiet
* Failed-share records left over from networks you do not use are cleared automatically
* Tested up to WordPress 7.1

= 2.0.0 =
* Each network now lives in a single file behind a `Kobak_Social_Platform` interface, instead of being wired into four places. Adding a network no longer means editing existing code
* New filters for anyone extending the plugin: `kobak_social_platforms` to register a network, `kobak_social_message` to override the text per network, and `kobak_social_syndication_urls` to change the syndication links
* The plugin file is split into `includes/`, with one file per network under `includes/platforms/`
* The post types to share can now actually be chosen. The setting existed before but had no form field and always fell back to posts
* Failures that retrying cannot fix no longer run through three retry rounds. A missing or invalid credential, or any 4xx other than 408 and 429, is reported at once instead of a quarter of an hour later; timeouts, 5xx, 408 and 429 are retried as before
* Nothing else changed on purpose: the settings, the stored data and every message are the same as in 1.4.0

= 1.4.0 =
* Security: saved tokens and app passwords are no longer written back into the settings page HTML
* Each platform now gets its own "already shared" marker, so a failed Mastodon post can be retried without posting to Bluesky twice
* Failed shares are retried automatically up to three times, and an admin notice points at the ones that gave up
* The post language is taken from the site language instead of being hardcoded to Hungarian, and can be overridden
* Saving the settings no longer discards values that are not on the form
* LinkedIn: the "large" image size is uploaded instead of the full-size original, and a warning appears before the token expires
* The Mastodon test button posts again instead of being silently deduplicated

= 1.3.2 =
* Clearer wording about what the plugin does and does not do: it adds the u-syndication markup, but receiving replies also needs a webmention plugin and Brid.gy

= 1.3.1 =
* Translations are handled through translate.wordpress.org, so bundled translation files were removed

= 1.3.0 =
* Full internationalisation, Hungarian translation included
* Mastodon profile fallback link is now configurable instead of hardcoded
* Added uninstall cleanup
* First public release

= 1.2.2 =
* Added a LinkedIn-only test button, so testing does not post to Bluesky and Mastodon

= 1.2.1 =
* Added a LinkedIn connection check that stores the author URN without posting

= 1.2.0 =
* Optional LinkedIn support: link card posts with the featured image

= 1.1.0 =
* Syndication box with u-syndication links
* Option to hide the local comment form while keeping webmentions

= 1.0.0 =
* Bluesky and Mastodon sharing on publish

== Upgrade Notice ==

= 1.4.0 =
Security fix: tokens were readable in the settings page source. Failed shares are now retried per platform, and the post language follows your site language.

= 1.3.0 =
First public release. If you used an earlier private build, check the new Mastodon username field under Settings → kobak social.
