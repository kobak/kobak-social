<?php
/**
 * Plugin Name:       kobak social: share posts to Mastodon and Bluesky
 * Plugin URI:        https://kobak.org/plugins/
 * Description:       Automatically shares every new post to Bluesky and Mastodon, with optional LinkedIn support. Adds u-syndication links for IndieWeb setups.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Koren Balázs
 * License:           GPL-2.0-or-later
 * Text Domain:       kobak-social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KOBAK_SOCIAL_VERSION', '2.0.0' );
define( 'KOBAK_SOCIAL_OPTION', 'kobak_social_settings' );
define( 'KOBAK_SOCIAL_LOG', 'kobak_social_log' );
define( 'KOBAK_SOCIAL_DONE_META', '_kobak_social_done' );
define( 'KOBAK_SOCIAL_FAILURES', 'kobak_social_failures' );
define( 'KOBAK_SOCIAL_MAX_ATTEMPTS', 3 );

/* -------------------------------------------------------------------------
 * Betöltés. A platform interfésznek meg kell előznie a platform osztályokat
 * (implements-hez kell), a regisztrynek és a beállításoknak pedig a rájuk
 * támaszkodó megosztási/admin/szindikációs logikát.
 * ---------------------------------------------------------------------- */

require_once __DIR__ . '/includes/interface-kobak-social-platform.php';
require_once __DIR__ . '/includes/platforms/class-kobak-social-bluesky.php';
require_once __DIR__ . '/includes/platforms/class-kobak-social-mastodon.php';
require_once __DIR__ . '/includes/platforms/class-kobak-social-linkedin.php';
require_once __DIR__ . '/includes/class-kobak-social-registry.php';
require_once __DIR__ . '/includes/class-kobak-social-settings.php';
require_once __DIR__ . '/includes/class-kobak-social-sharer.php';
require_once __DIR__ . '/includes/class-kobak-social-admin.php';
require_once __DIR__ . '/includes/class-kobak-social-syndication.php';

/* -------------------------------------------------------------------------
 * Hook-ok bekötése.
 * ---------------------------------------------------------------------- */

add_action( 'transition_post_status', 'kobak_social_on_transition', 10, 3 );
add_action( 'kobak_social_delayed_share', 'kobak_social_run_scheduled_share', 10, 2 );

add_filter( 'the_content', 'kobak_social_append_syndication', 20 );
add_filter( 'comments_open', 'kobak_social_close_local_comments', 20, 2 );
add_filter( 'preprocess_comment', 'kobak_social_block_human_comments', 5 );

add_action( 'admin_notices', 'kobak_social_admin_notices' );
add_action( 'admin_menu', 'kobak_social_admin_menu' );
