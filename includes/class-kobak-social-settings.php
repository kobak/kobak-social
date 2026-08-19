<?php
/**
 * Beállítások: alapértékek és a mentett opció beolvasása.
 *
 * @package kobak-social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function kobak_social_defaults() {
	return array(
		'enabled'          => 0,
		'bsky_handle'      => '',
		'bsky_app_password'=> '',
		'mastodon_instance'=> 'https://mastodon.social',
		'mastodon_token'   => '',
		'mastodon_handle'  => '',
		'linkedin_enabled'     => 0,
		'linkedin_token'       => '',
		'linkedin_author_urn'  => '',
		'linkedin_version'     => '202607',
		'linkedin_token_saved' => 0,
		'post_language'    => '',
		'template'         => '{title}

{excerpt}

{url}',
		'post_types'       => array( 'post' ),
		'show_syndication'       => 1,
		'disable_local_comments' => 0,
	);
}

function kobak_social_get_settings() {
	$saved = get_option( KOBAK_SOCIAL_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, kobak_social_defaults() );
}
