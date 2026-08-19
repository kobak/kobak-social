<?php
/**
 * Törléskor takarítás: a beállítások, a napló és a bejegyzésekhez tartozó
 * saját meta mezők eltávolítása.
 *
 * @package kobak-social
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'kobak_social_settings' );
delete_option( 'kobak_social_log' );
delete_option( 'kobak_social_failures' );

$kobak_social_meta_keys = array(
	'_kobak_social_done',
	'_kobak_social_done_bluesky',
	'_kobak_social_done_mastodon',
	'_kobak_social_done_linkedin',
	'_kobak_social_bluesky_url',
	'_kobak_social_mastodon_url',
	'_kobak_social_linkedin_url',
);

foreach ( $kobak_social_meta_keys as $kobak_social_meta_key ) {
	delete_post_meta_by_key( $kobak_social_meta_key );
}

wp_clear_scheduled_hook( 'kobak_social_delayed_share' );
