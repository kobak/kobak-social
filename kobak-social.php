<?php
/**
 * Plugin Name:       kobak social: share posts to Mastodon and Bluesky
 * Plugin URI:        https://kobak.org/plugins/
 * Description:       Automatically shares every new post to Bluesky and Mastodon, with optional LinkedIn support. Adds u-syndication links for IndieWeb setups.
 * Version:           1.4.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Koren Balázs
 * License:           GPL-2.0-or-later
 * Text Domain:       kobak-social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KOBAK_SOCIAL_VERSION', '1.4.0' );
define( 'KOBAK_SOCIAL_OPTION', 'kobak_social_settings' );
define( 'KOBAK_SOCIAL_LOG', 'kobak_social_log' );
define( 'KOBAK_SOCIAL_DONE_META', '_kobak_social_done' );
define( 'KOBAK_SOCIAL_FAILURES', 'kobak_social_failures' );
define( 'KOBAK_SOCIAL_MAX_ATTEMPTS', 3 );

/**
 * A támogatott platformok azonosítói, a megosztás sorrendjében.
 */
function kobak_social_platforms() {
	return array( 'bluesky', 'mastodon', 'linkedin' );
}

/**
 * Emberi név a naplóhoz és a felülethez.
 */
function kobak_social_platform_label( $platform ) {
	$labels = array(
		'bluesky'  => 'Bluesky',
		'mastodon' => 'Mastodon',
		'linkedin' => 'LinkedIn',
	);
	return isset( $labels[ $platform ] ) ? $labels[ $platform ] : $platform;
}

/**
 * Platformonkénti „ez már kiment" jelölés kulcsa.
 */
function kobak_social_done_meta_key( $platform ) {
	return '_kobak_social_done_' . $platform;
}

/* -------------------------------------------------------------------------
 * Beállítások
 * ---------------------------------------------------------------------- */

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

/* -------------------------------------------------------------------------
 * Napló
 * ---------------------------------------------------------------------- */

function kobak_social_log( $message ) {
	$log = get_option( KOBAK_SOCIAL_LOG, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	array_unshift(
		$log,
		array(
			'time' => current_time( 'mysql' ),
			'msg'  => (string) $message,
		)
	);
	$log = array_slice( $log, 0, 60 );
	update_option( KOBAK_SOCIAL_LOG, $log, false );
}

/* -------------------------------------------------------------------------
 * Szövegépítés
 * ---------------------------------------------------------------------- */

/**
 * A poszthoz tartozó nyelvkód. Ha nincs kézzel beállítva, az oldal nyelvéből
 * jön. Korábban fixen „hu" volt, ami minden nem magyar oldalon hibás.
 */
function kobak_social_language() {
	$s = kobak_social_get_settings();

	$code = ! empty( $s['post_language'] ) ? $s['post_language'] : substr( get_locale(), 0, 2 );
	$code = strtolower( preg_replace( '/[^A-Za-z]/', '', (string) $code ) );
	if ( 2 !== strlen( $code ) ) {
		$code = 'en';
	}

	/**
	 * A közösségi posztokra tett nyelvjelölés.
	 *
	 * @param string $code Kétbetűs nyelvkód.
	 */
	return apply_filters( 'kobak_social_language', $code );
}

function kobak_social_build_message( $post, $limit ) {
	$settings = kobak_social_get_settings();

	$title   = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
	$url     = get_permalink( $post );
	$excerpt = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_strip_all_tags( $post->post_content );
	$excerpt = html_entity_decode( wp_strip_all_tags( $excerpt ), ENT_QUOTES, 'UTF-8' );
	$excerpt = trim( preg_replace( '/\s+/u', ' ', $excerpt ) );

	$text = str_replace(
		array( '{title}', '{url}', '{excerpt}' ),
		array( $title, $url, $excerpt ),
		$settings['template']
	);
	$text = trim( preg_replace( "/\n{3,}/u", "\n\n", $text ) );

	// Ha túl hosszú, a kivonatot rövidítjük, a cím és a link marad.
	if ( mb_strlen( $text ) > $limit ) {
		$fixed_len = mb_strlen( str_replace( array( '{title}', '{url}', '{excerpt}' ), array( $title, $url, '' ), $settings['template'] ) );
		$room      = $limit - $fixed_len - 1;
		if ( $room < 0 ) {
			$room = 0;
		}
		$short = mb_substr( $excerpt, 0, $room );
		$cut   = mb_strrpos( $short, ' ' );
		if ( $cut > 20 ) {
			$short = mb_substr( $short, 0, $cut );
		}
		$short = rtrim( $short, " \t\n\r\0\x0B.,;:" );
		if ( $short !== '' ) {
			$short .= '…';
		}
		$text = str_replace(
			array( '{title}', '{url}', '{excerpt}' ),
			array( $title, $url, $short ),
			$settings['template']
		);
		$text = trim( preg_replace( "/\n{3,}/u", "\n\n", $text ) );
	}

	return $text;
}

/**
 * Bluesky facet: a szövegben szereplő URL kattinthatóvá tétele.
 */
function kobak_social_bsky_facets( $text ) {
	$facets = array();
	if ( preg_match_all( '#https?://[^\s\]\)]+#u', $text, $m, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $m[0] as $hit ) {
			$uri  = rtrim( $hit[0], '.,;:' );
			$byte = $hit[1]; // preg with /u still gives byte offsets
			$facets[] = array(
				'index'    => array(
					'byteStart' => $byte,
					'byteEnd'   => $byte + strlen( $uri ),
				),
				'features' => array(
					array(
						'$type' => 'app.bsky.richtext.facet#link',
						'uri'   => $uri,
					),
				),
			);
		}
	}
	return $facets;
}

/* -------------------------------------------------------------------------
 * Bluesky
 * ---------------------------------------------------------------------- */

function kobak_social_post_bluesky( $post ) {
	$s = kobak_social_get_settings();
	if ( empty( $s['bsky_handle'] ) || empty( $s['bsky_app_password'] ) ) {
		return new WP_Error( 'kobak_social_bsky_missing', __( 'Bluesky credentials are missing.', 'kobak-social' ) );
	}

	$session = wp_remote_post(
		'https://bsky.social/xrpc/com.atproto.server.createSession',
		array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode(
				array(
					'identifier' => $s['bsky_handle'],
					'password'   => $s['bsky_app_password'],
				)
			),
		)
	);

	if ( is_wp_error( $session ) ) {
		return $session;
	}
	$sdata = json_decode( wp_remote_retrieve_body( $session ), true );
	if ( empty( $sdata['accessJwt'] ) ) {
		return new WP_Error( 'kobak_social_bsky_auth', sprintf( /* translators: %s: error response from the Bluesky API. */ __( 'Bluesky login failed: %s', 'kobak-social' ), wp_remote_retrieve_body( $session ) ) );
	}

	$text   = kobak_social_build_message( $post, 300 );
	$record = array(
		'$type'     => 'app.bsky.feed.post',
		'text'      => $text,
		'createdAt' => gmdate( 'c' ),
		'langs'     => array( kobak_social_language() ),
	);
	$facets = kobak_social_bsky_facets( $text );
	if ( $facets ) {
		$record['facets'] = $facets;
	}

	$res = wp_remote_post(
		'https://bsky.social/xrpc/com.atproto.repo.createRecord',
		array(
			'timeout' => 20,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $sdata['accessJwt'],
			),
			'body'    => wp_json_encode(
				array(
					'repo'       => $sdata['did'],
					'collection' => 'app.bsky.feed.post',
					'record'     => $record,
				)
			),
		)
	);

	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = wp_remote_retrieve_response_code( $res );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'kobak_social_bsky_post', sprintf( /* translators: 1: HTTP status code, 2: error response from the Bluesky API. */ __( 'Bluesky error (HTTP %1$d): %2$s', 'kobak-social' ), (int) $code, wp_remote_retrieve_body( $res ) ) );
	}

	// Szindikációs link eltárolása (Bridgy ebből találja meg a válaszokat).
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( ! empty( $body['uri'] ) && preg_match( '#/([^/]+)$#', $body['uri'], $m ) ) {
		$permalink = 'https://bsky.app/profile/' . rawurlencode( $s['bsky_handle'] ) . '/post/' . $m[1];
		update_post_meta( $post->ID, '_kobak_social_bluesky_url', esc_url_raw( $permalink ) );
	}

	return true;
}

/* -------------------------------------------------------------------------
 * Mastodon
 * ---------------------------------------------------------------------- */

function kobak_social_post_mastodon( $post, $force = false ) {
	$s = kobak_social_get_settings();
	if ( empty( $s['mastodon_token'] ) || empty( $s['mastodon_instance'] ) ) {
		return new WP_Error( 'kobak_social_mastodon_missing', __( 'Mastodon credentials are missing.', 'kobak-social' ) );
	}

	$endpoint = untrailingslashit( esc_url_raw( $s['mastodon_instance'] ) ) . '/api/v1/statuses';
	$text     = kobak_social_build_message( $post, 500 );

	$res = wp_remote_post(
		$endpoint,
		array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $s['mastodon_token'],
				'Content-Type'  => 'application/json',
				// Éles megosztásnál a poszt azonosítója véd a duplikálás ellen. A kézi
				// tesztnél viszont pont az a cél, hogy tényleg kimenjen még egyszer.
				'Idempotency-Key' => 'kobak-' . $post->ID . ( $force ? '-' . time() : '' ),
			),
			'body'    => wp_json_encode(
				array(
					'status'     => $text,
					'visibility' => 'public',
					'language'   => kobak_social_language(),
				)
			),
		)
	);

	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = wp_remote_retrieve_response_code( $res );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'kobak_social_mastodon_post', sprintf( /* translators: 1: HTTP status code, 2: error response from the Mastodon API. */ __( 'Mastodon error (HTTP %1$d): %2$s', 'kobak-social' ), (int) $code, wp_remote_retrieve_body( $res ) ) );
	}

	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( ! empty( $body['url'] ) ) {
		update_post_meta( $post->ID, '_kobak_social_mastodon_url', esc_url_raw( $body['url'] ) );
	}

	return true;
}

/* -------------------------------------------------------------------------
 * LinkedIn
 *
 * Fontos: itt szándékosan NINCS curl_exec(). A kobak.org tárhelyén a PHP
 * disable_functions listája tiltja, ezért szállt el 500-zal a korábbi
 * LinkedIn plugin. A WordPress saját HTTP API-ja (wp_remote_*) ha nincs
 * cURL, automatikusan stream alapú átvitelre vált, tehát itt működik.
 * ---------------------------------------------------------------------- */

/**
 * A LinkedIn „little" szövegformátumban a foglalt karaktereket escapelni kell,
 * akkor is, ha nem elemként használjuk őket.
 */
function kobak_social_li_escape( $text ) {
	$reserved = array( '\\', '|', '{', '}', '@', '[', ']', '(', ')', '<', '>', '#', '*', '_', '~' );
	foreach ( $reserved as $ch ) {
		$text = str_replace( $ch, '\\' . $ch, $text );
	}
	return $text;
}

/**
 * Közös fejlécek a LinkedIn verziózott API-hoz.
 */
function kobak_social_li_headers( $s, $extra = array() ) {
	$version = ! empty( $s['linkedin_version'] ) ? $s['linkedin_version'] : '202607';
	return array_merge(
		array(
			'Authorization'             => 'Bearer ' . $s['linkedin_token'],
			'X-Restli-Protocol-Version' => '2.0.0',
			'LinkedIn-Version'          => $version,
			'Content-Type'              => 'application/json',
		),
		$extra
	);
}

/**
 * A szerző URN-je. Ha nincs eltárolva, lekérjük az OpenID userinfo végpontról.
 */
function kobak_social_li_author_urn( $s ) {
	if ( ! empty( $s['linkedin_author_urn'] ) ) {
		return $s['linkedin_author_urn'];
	}
	if ( empty( $s['linkedin_token'] ) ) {
		return new WP_Error( 'kobak_social_li_no_token', __( 'No LinkedIn access token.', 'kobak-social' ) );
	}

	$res = wp_remote_get(
		'https://api.linkedin.com/v2/userinfo',
		array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $s['linkedin_token'] ),
		)
	);
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = wp_remote_retrieve_response_code( $res );
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( $code < 200 || $code >= 300 || empty( $body['sub'] ) ) {
		return new WP_Error( 'kobak_social_li_userinfo', sprintf( /* translators: 1: HTTP status code, 2: error response from the LinkedIn API. */ __( 'LinkedIn userinfo error (HTTP %1$d): %2$s', 'kobak-social' ), (int) $code, wp_remote_retrieve_body( $res ) ) );
	}

	$urn      = 'urn:li:person:' . $body['sub'];
	$settings = kobak_social_get_settings();
	$settings['linkedin_author_urn'] = $urn;
	update_option( KOBAK_SOCIAL_OPTION, $settings, false );

	return $urn;
}

/**
 * Kiemelt kép feltöltése az Images API-val, hogy a link-kártyán legyen kép.
 * Hiba esetén nem állítjuk meg a posztolást, csak kép nélkül megy ki.
 */
function kobak_social_li_upload_thumbnail( $post, $s, $author ) {
	$attachment_id = get_post_thumbnail_id( $post );
	if ( ! $attachment_id ) {
		return new WP_Error( 'kobak_social_li_nothumb', __( 'No featured image.', 'kobak-social' ) );
	}
	// A „large" méret bőven elég a link-kártyához. Az eredeti fájl egy telefonos
	// fotónál több megabájt is lehet, azt fölösleges feltölteni.
	$path = get_attached_file( $attachment_id );
	$mime = get_post_mime_type( $attachment_id );

	$sized = image_get_intermediate_size( $attachment_id, 'large' );
	if ( $sized && ! empty( $sized['path'] ) ) {
		$uploads = wp_get_upload_dir();
		$scaled  = trailingslashit( $uploads['basedir'] ) . $sized['path'];
		if ( file_exists( $scaled ) ) {
			$path      = $scaled;
			$type_info = wp_check_filetype( $scaled );
			if ( ! empty( $type_info['type'] ) ) {
				$mime = $type_info['type'];
			}
		}
	}

	if ( ! $path || ! file_exists( $path ) ) {
		return new WP_Error( 'kobak_social_li_nofile', __( 'The featured image file could not be found.', 'kobak-social' ) );
	}

	// 1. lépés: feltöltés inicializálása
	$init = wp_remote_post(
		'https://api.linkedin.com/rest/images?action=initializeUpload',
		array(
			'timeout' => 25,
			'headers' => kobak_social_li_headers( $s ),
			'body'    => wp_json_encode(
				array(
					'initializeUploadRequest' => array( 'owner' => $author ),
				)
			),
		)
	);
	if ( is_wp_error( $init ) ) {
		return $init;
	}
	$init_body = json_decode( wp_remote_retrieve_body( $init ), true );
	if ( empty( $init_body['value']['uploadUrl'] ) || empty( $init_body['value']['image'] ) ) {
		return new WP_Error( 'kobak_social_li_initupload', sprintf( /* translators: %s: error response from the LinkedIn API. */ __( 'Could not initialise the image upload: %s', 'kobak-social' ), wp_remote_retrieve_body( $init ) ) );
	}

	// 2. lépés: a bináris tartalom feltöltése
	$binary = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false === $binary ) {
		return new WP_Error( 'kobak_social_li_readfile', __( 'The image could not be read.', 'kobak-social' ) );
	}

	$put = wp_remote_request(
		$init_body['value']['uploadUrl'],
		array(
			'method'  => 'PUT',
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Bearer ' . $s['linkedin_token'],
				'Content-Type'  => $mime ? $mime : 'application/octet-stream',
			),
			'body'    => $binary,
		)
	);
	if ( is_wp_error( $put ) ) {
		return $put;
	}
	$put_code = wp_remote_retrieve_response_code( $put );
	if ( $put_code < 200 || $put_code >= 300 ) {
		return new WP_Error( 'kobak_social_li_upload', sprintf( /* translators: %d: HTTP status code. */ __( 'Image upload failed (HTTP %d).', 'kobak-social' ), (int) $put_code ) );
	}

	return $init_body['value']['image'];
}

/**
 * A bejegyzés kiposztolása LinkedInre link-kártyaként.
 */
function kobak_social_post_linkedin( $post, $manual = false ) {
	$s = kobak_social_get_settings();

	if ( empty( $s['linkedin_enabled'] ) && ! $manual ) {
		return new WP_Error( 'kobak_social_li_off', __( 'LinkedIn is disabled.', 'kobak-social' ) );
	}
	if ( empty( $s['linkedin_token'] ) ) {
		return new WP_Error( 'kobak_social_li_missing', __( 'The LinkedIn access token is missing.', 'kobak-social' ) );
	}

	$author = kobak_social_li_author_urn( $s );
	if ( is_wp_error( $author ) ) {
		return $author;
	}

	$commentary = kobak_social_li_escape( kobak_social_build_message( $post, 2800 ) );

	$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
	$desc  = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_strip_all_tags( $post->post_content );
	$desc  = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $desc ), ENT_QUOTES, 'UTF-8' ) ) );
	$desc  = mb_substr( $desc, 0, 250 );

	$article = array(
		'source'      => get_permalink( $post ),
		'title'       => mb_substr( $title, 0, 200 ),
		'description' => $desc,
	);

	$thumb = kobak_social_li_upload_thumbnail( $post, $s, $author );
	if ( ! is_wp_error( $thumb ) ) {
		$article['thumbnail'] = $thumb;
	}

	$payload = array(
		'author'                    => $author,
		'commentary'                => $commentary,
		'visibility'                => 'PUBLIC',
		'distribution'              => array(
			'feedDistribution'               => 'MAIN_FEED',
			'targetEntities'                 => array(),
			'thirdPartyDistributionChannels' => array(),
		),
		'content'                   => array( 'article' => $article ),
		'lifecycleState'            => 'PUBLISHED',
		'isReshareDisabledByAuthor' => false,
	);

	$res = wp_remote_post(
		'https://api.linkedin.com/rest/posts',
		array(
			'timeout' => 30,
			'headers' => kobak_social_li_headers( $s ),
			'body'    => wp_json_encode( $payload ),
		)
	);
	if ( is_wp_error( $res ) ) {
		return $res;
	}

	$code = wp_remote_retrieve_response_code( $res );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'kobak_social_li_post', sprintf( /* translators: 1: HTTP status code, 2: error response from the LinkedIn API. */ __( 'LinkedIn error (HTTP %1$d): %2$s', 'kobak-social' ), (int) $code, wp_remote_retrieve_body( $res ) ) );
	}

	$urn = wp_remote_retrieve_header( $res, 'x-restli-id' );
	if ( $urn ) {
		update_post_meta( $post->ID, '_kobak_social_linkedin_url', esc_url_raw( 'https://www.linkedin.com/feed/update/' . $urn . '/' ) );
	}

	return true;
}

/**
 * Kapcsolat-ellenőrzés: lekéri a profilt, elmenti az URN-t. Nem posztol.
 */
function kobak_social_li_check() {
	$s = kobak_social_get_settings();

	if ( empty( $s['linkedin_token'] ) ) {
		return new WP_Error( 'kobak_social_li_notoken', __( 'No access token has been entered.', 'kobak-social' ) );
	}
	if ( strlen( $s['linkedin_token'] ) < 100 ) {
		return new WP_Error(
			'kobak_social_li_shorttoken',
			sprintf( /* translators: %d: number of characters entered. */ __( 'The value entered is only %d characters long. A LinkedIn access token is much longer, usually 300-500 characters. You have probably pasted the Client ID or the Client Secret instead of the token.', 'kobak-social' ), strlen( $s['linkedin_token'] ) )
		);
	}

	$res = wp_remote_get(
		'https://api.linkedin.com/v2/userinfo',
		array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $s['linkedin_token'] ),
		)
	);
	if ( is_wp_error( $res ) ) {
		return $res;
	}

	$code = wp_remote_retrieve_response_code( $res );
	$body = json_decode( wp_remote_retrieve_body( $res ), true );

	if ( 401 === $code ) {
		return new WP_Error( 'kobak_social_li_401', __( 'The token is invalid or has expired (401). Generate a new one in your LinkedIn app.', 'kobak-social' ) );
	}
	if ( 403 === $code ) {
		return new WP_Error( 'kobak_social_li_403', __( 'The token works but a permission is missing (403). You need the openid, profile and w_member_social scopes.', 'kobak-social' ) );
	}
	if ( $code < 200 || $code >= 300 || empty( $body['sub'] ) ) {
		return new WP_Error( 'kobak_social_li_check', sprintf( /* translators: 1: HTTP status code, 2: error response from the LinkedIn API. */ __( 'Unexpected response (HTTP %1$d): %2$s', 'kobak-social' ), (int) $code, wp_remote_retrieve_body( $res ) ) );
	}

	$urn = 'urn:li:person:' . $body['sub'];
	$s['linkedin_author_urn'] = $urn;
	update_option( KOBAK_SOCIAL_OPTION, $s, false );

	$nev = ! empty( $body['name'] ) ? $body['name'] : __( 'unknown name', 'kobak-social' );

	return array( 'urn' => $urn, 'nev' => $nev );
}

/* -------------------------------------------------------------------------
 * Publikálásra fülelés
 * ---------------------------------------------------------------------- */

/**
 * Amelyik platformra egyáltalán megyünk. A LinkedIn opcionális.
 */
function kobak_social_active_platforms( $settings ) {
	$active = array( 'bluesky', 'mastodon' );
	if ( ! empty( $settings['linkedin_enabled'] ) ) {
		$active[] = 'linkedin';
	}
	return $active;
}

/**
 * Egy platform kiszolgálása. Azért külön, hogy a naplózás, a jelölés és az
 * újrapróbálkozás mindenhol ugyanúgy működjön.
 */
function kobak_social_publish_to( $platform, $post, $force = false ) {
	switch ( $platform ) {
		case 'bluesky':
			return kobak_social_post_bluesky( $post );
		case 'mastodon':
			return kobak_social_post_mastodon( $post, $force );
		case 'linkedin':
			return kobak_social_post_linkedin( $post, $force );
	}
	return new WP_Error( 'kobak_social_unknown_platform', 'Unknown platform: ' . $platform );
}

/**
 * Van-e már platformonkénti jelölés a bejegyzésen.
 */
function kobak_social_has_platform_meta( $post_id ) {
	foreach ( kobak_social_platforms() as $platform ) {
		if ( get_post_meta( $post_id, kobak_social_done_meta_key( $platform ), true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * A megosztás. Platformonként külön jelölést tesz a bejegyzésre, így egy
 * elszállt Mastodon-poszt később újrapróbálható anélkül, hogy a sikeres
 * Bluesky-poszt még egyszer kimenne.
 *
 * @return array A sikertelen platformok azonosítói.
 */
function kobak_social_share_post( $post_id, $force = false ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array();
	}

	$s = kobak_social_get_settings();

	if ( ! $force ) {
		if ( empty( $s['enabled'] ) ) {
			return array();
		}
		if ( ! in_array( $post->post_type, (array) $s['post_types'], true ) ) {
			return array();
		}
		// 1.4 előtt egyetlen közös jelölés volt. Ha az megvan, de platformonkénti
		// még nincs, akkor ez a bejegyzés a régi logika szerint már kiment.
		if ( get_post_meta( $post_id, KOBAK_SOCIAL_DONE_META, true ) && ! kobak_social_has_platform_meta( $post_id ) ) {
			return array();
		}
	}

	$results = array();
	$failed  = array();

	foreach ( kobak_social_active_platforms( $s ) as $platform ) {
		$done_key = kobak_social_done_meta_key( $platform );
		if ( ! $force && get_post_meta( $post_id, $done_key, true ) ) {
			continue; // ez a platform már megkapta
		}

		$result = kobak_social_publish_to( $platform, $post, $force );
		$label  = kobak_social_platform_label( $platform );

		if ( is_wp_error( $result ) ) {
			$failed[]  = $platform;
			$results[] = $label . ': ERROR - ' . $result->get_error_message();
		} else {
			update_post_meta( $post_id, $done_key, current_time( 'mysql' ) );
			$results[] = $label . ': OK';
		}
	}

	if ( ! $results ) {
		return array();
	}

	// A közös jelölés is megmarad: erről ismeri fel a régebbi verzióval
	// megosztott bejegyzéseket, és erre épül a fenti visszalépés-védelem.
	if ( count( $failed ) < count( $results ) ) {
		update_post_meta( $post_id, KOBAK_SOCIAL_DONE_META, current_time( 'mysql' ) );
	}

	kobak_social_log( sprintf( '#%d "%s" -> %s', $post_id, get_the_title( $post ), implode( ' | ', $results ) ) );

	return $failed;
}

/* -------------------------------------------------------------------------
 * Újrapróbálkozás és hibajelzés
 * ---------------------------------------------------------------------- */

function kobak_social_record_failure( $post_id, $platforms ) {
	$failures = get_option( KOBAK_SOCIAL_FAILURES, array() );
	if ( ! is_array( $failures ) ) {
		$failures = array();
	}
	$failures[ (int) $post_id ] = array(
		'time'      => current_time( 'mysql' ),
		'platforms' => array_values( (array) $platforms ),
	);
	$failures = array_slice( $failures, -20, 20, true );
	update_option( KOBAK_SOCIAL_FAILURES, $failures, false );
}

function kobak_social_clear_failure( $post_id ) {
	$failures = get_option( KOBAK_SOCIAL_FAILURES, array() );
	if ( ! is_array( $failures ) || ! isset( $failures[ (int) $post_id ] ) ) {
		return;
	}
	unset( $failures[ (int) $post_id ] );
	update_option( KOBAK_SOCIAL_FAILURES, $failures, false );
}

/**
 * Az ütemezett megosztás. Ha valamelyik platform elszállt (leggyakrabban
 * időtúllépés miatt), növekvő szünettel újrapróbálja. A sikeres platformok
 * a jelölésük miatt kimaradnak, tehát nem lesz dupla poszt.
 */
function kobak_social_run_scheduled_share( $post_id, $attempt = 1 ) {
	$attempt = max( 1, (int) $attempt );
	$failed  = kobak_social_share_post( $post_id );

	if ( empty( $failed ) ) {
		kobak_social_clear_failure( $post_id );
		return;
	}

	if ( $attempt < KOBAK_SOCIAL_MAX_ATTEMPTS ) {
		$delay = 300 * $attempt;
		wp_schedule_single_event( time() + $delay, 'kobak_social_delayed_share', array( $post_id, $attempt + 1 ) );
		kobak_social_log( sprintf( '#%d retry %d/%d in %d minutes', $post_id, $attempt + 1, KOBAK_SOCIAL_MAX_ATTEMPTS, (int) ( $delay / 60 ) ) );
		return;
	}

	kobak_social_record_failure( $post_id, $failed );
	kobak_social_log( sprintf( '#%d gave up after %d attempts: %s', $post_id, KOBAK_SOCIAL_MAX_ATTEMPTS, implode( ', ', $failed ) ) );
}

/**
 * Csak a draft/future/pending → publish átmenetnél megyünk ki.
 * Így a régi bejegyzések szerkesztése nem posztol újra.
 */
function kobak_social_on_transition( $new_status, $old_status, $post ) {
	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}
	if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
		return;
	}
	// Kis késleltetés: a szerkesztő mentése után fusson, ne blokkolja a mentést.
	wp_schedule_single_event( time() + 30, 'kobak_social_delayed_share', array( $post->ID, 1 ) );
}
add_action( 'transition_post_status', 'kobak_social_on_transition', 10, 3 );
add_action( 'kobak_social_delayed_share', 'kobak_social_run_scheduled_share', 10, 2 );

/* -------------------------------------------------------------------------
 * IndieWeb: szindikációs linkek + „kommentelj social-ban" mód
 * ---------------------------------------------------------------------- */

/**
 * A bejegyzéshez tartozó social másolatok URL-jei.
 */
function kobak_social_syndication_urls( $post_id ) {
	$urls = array();
	$b = get_post_meta( $post_id, '_kobak_social_bluesky_url', true );
	$m = get_post_meta( $post_id, '_kobak_social_mastodon_url', true );
	$l = get_post_meta( $post_id, '_kobak_social_linkedin_url', true );
	if ( $b ) {
		$urls['Bluesky'] = $b;
	}
	if ( $m ) {
		$urls['Mastodon'] = $m;
	}
	if ( $l ) {
		$urls['LinkedIn'] = $l;
	}
	return $urls;
}

/**
 * A bejegyzés alá kerülő blokk: szindikációs linkek (u-syndication) +
 * felhívás, hogy social-ban kommenteljenek.
 */
function kobak_social_append_syndication( $content ) {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}

	$s = kobak_social_get_settings();
	if ( empty( $s['show_syndication'] ) ) {
		return $content;
	}

	$post_id = get_the_ID();
	$urls    = kobak_social_syndication_urls( $post_id );

	// Ha nincs eltárolt link (régi poszt), a profilokra mutatunk.
	$fallback = false;
	if ( empty( $urls ) ) {
		$fallback = true;
		if ( ! empty( $s['bsky_handle'] ) ) {
			$urls['Bluesky'] = 'https://bsky.app/profile/' . rawurlencode( $s['bsky_handle'] );
		}
		if ( ! empty( $s['mastodon_instance'] ) && ! empty( $s['mastodon_handle'] ) ) {
			$urls['Mastodon'] = untrailingslashit( $s['mastodon_instance'] ) . '/@' . rawurlencode( ltrim( $s['mastodon_handle'], '@' ) );
		}
	}

	if ( empty( $urls ) ) {
		return $content;
	}

	$links = array();
	foreach ( $urls as $label => $url ) {
		$links[] = sprintf(
			'<a class="%s" href="%s" rel="syndication noopener" target="_blank">%s</a>',
			$fallback ? 'kobak-social-link' : 'u-syndication kobak-social-link',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	$intro = $fallback
		? __( 'Let&#8217;s talk about it:', 'kobak-social' )
		: __( 'This post is also published here:', 'kobak-social' );

	$box  = '<aside class="kobak-social-box" style="margin:2.5em 0 0;padding:1.1em 1.3em;border:1px solid rgba(128,128,128,.28);border-radius:12px;font-size:.95em;line-height:1.6">';
	$box .= '<strong>' . esc_html__( 'Join the conversation', 'kobak-social' ) . '</strong><br>';
	$box .= esc_html( $intro ) . ' ' . implode( ' &middot; ', $links ) . '.<br>';
	$box .= '<span style="opacity:.75">' . esc_html__( 'Replies, likes and reposts over there show up under this post as well.', 'kobak-social' ) . '</span>';
	$box .= '</aside>';

	return $content . $box;
}
add_filter( 'the_content', 'kobak_social_append_syndication', 20 );

/**
 * Helyi kommentelés kikapcsolása ÚGY, hogy a webmention továbbra is működjön.
 *
 * A webmentionök a REST végponton érkeznek, ezért ott nem nyúlunk a
 * comments_open értékéhez — csak a látogatói felületen zárjuk a formot.
 */
function kobak_social_close_local_comments( $open, $post_id ) {
	$s = kobak_social_get_settings();
	if ( empty( $s['disable_local_comments'] ) ) {
		return $open;
	}
	// Webmention / REST / cron / admin: maradjon nyitva.
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return $open;
	}
	if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
		return $open;
	}
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return $open;
	}
	if ( is_admin() ) {
		return $open;
	}
	return false;
}
add_filter( 'comments_open', 'kobak_social_close_local_comments', 20, 2 );

/**
 * Biztonsági háló: közvetlenül beküldött emberi hozzászólás elutasítása,
 * a webmention és pingback típusok átengedésével.
 */
function kobak_social_block_human_comments( $commentdata ) {
	$s = kobak_social_get_settings();
	if ( empty( $s['disable_local_comments'] ) ) {
		return $commentdata;
	}
	$type = isset( $commentdata['comment_type'] ) ? $commentdata['comment_type'] : '';
	$allowed = array( 'webmention', 'pingback', 'trackback', 'like', 'repost', 'reply', 'bookmark', 'favorite' );
	if ( in_array( $type, $allowed, true ) ) {
		return $commentdata;
	}
	if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return $commentdata;
	}
	wp_die(
		esc_html__( 'Commenting is turned off on this site. Reply on Bluesky or Mastodon instead: answers there also appear underneath this post.', 'kobak-social' ),
		esc_html__( 'Commenting is disabled', 'kobak-social' ),
		array( 'response' => 403, 'back_link' => true )
	);
}
add_filter( 'preprocess_comment', 'kobak_social_block_human_comments', 5 );

/* -------------------------------------------------------------------------
 * Admin felület
 * ---------------------------------------------------------------------- */

/**
 * Titkos mező kirajzolása. A mentett értéket szándékosan NEM írjuk vissza a
 * HTML-be — eddig ott volt a lap forrásában minden token. Üresen hagyva a
 * korábbi érték marad, a jelölőnégyzettel törölhető.
 */
function kobak_social_secret_field( $name, $stored, $placeholder = '' ) {
	$has = ( '' !== trim( (string) $stored ) );

	printf(
		'<input type="password" name="%1$s" class="regular-text" value="" placeholder="%2$s" autocomplete="new-password">',
		esc_attr( $name ),
		esc_attr( $has ? __( 'Saved - leave empty to keep it', 'kobak-social' ) : $placeholder )
	);

	if ( $has ) {
		printf(
			'<p><label><input type="checkbox" name="%1$s_clear" value="1"> %2$s</label></p>',
			esc_attr( $name ),
			esc_html__( 'Delete the stored value', 'kobak-social' )
		);
	}
}

/**
 * Titkos mező kiolvasása mentéskor. Az űrlap nonce-át a hívó ellenőrzi.
 */
function kobak_social_secret_value( $name, $current ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	if ( ! empty( $_POST[ $name . '_clear' ] ) ) {
		return '';
	}
	$raw = isset( $_POST[ $name ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) ) : '';
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	return ( '' === $raw ) ? (string) $current : $raw;
}

/**
 * Feladott megosztás és lejáró LinkedIn-token: mindkettőről szólni kell,
 * mert a napló aljára magától senki nem néz.
 */
function kobak_social_admin_notices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$here   = $screen ? $screen->id : '';
	if ( ! in_array( $here, array( 'dashboard', 'plugins', 'edit-post', 'settings_page_kobak-social' ), true ) ) {
		return;
	}

	$failures = get_option( KOBAK_SOCIAL_FAILURES, array() );
	$items    = array();

	// A saját beállítóoldalunkon külön szakasz sorolja fel ugyanezt, gombbal együtt.
	if ( 'settings_page_kobak-social' !== $here && is_array( $failures ) ) {
		foreach ( $failures as $failed_id => $row ) {
			if ( ! get_post( $failed_id ) ) {
				continue;
			}
			$labels = array();
			foreach ( (array) $row['platforms'] as $platform ) {
				$labels[] = kobak_social_platform_label( $platform );
			}
			$items[] = sprintf( '%s (%s)', get_the_title( $failed_id ), implode( ', ', $labels ) );
		}
	}

	if ( $items ) {
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'kobak social: a share did not go out.', 'kobak-social' ) . '</strong> ' .
			esc_html( implode( ' - ', $items ) ) . ' <a href="' . esc_url( admin_url( 'options-general.php?page=kobak-social' ) ) . '">' .
			esc_html__( 'Open the settings to retry', 'kobak-social' ) . '</a></p></div>';
	}

	$s = kobak_social_get_settings();
	if ( ! empty( $s['linkedin_enabled'] ) && ! empty( $s['linkedin_token_saved'] ) ) {
		$days = ( time() - (int) $s['linkedin_token_saved'] ) / DAY_IN_SECONDS;
		if ( $days >= 50 ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'kobak social: the LinkedIn token is about to expire.', 'kobak-social' ) . '</strong> ' .
				esc_html__( 'LinkedIn tokens last about 60 days. Generate a new one and paste it into the settings before it stops working.', 'kobak-social' ) . '</p></div>';
		}
	}
}
add_action( 'admin_notices', 'kobak_social_admin_notices' );

function kobak_social_admin_menu() {
	add_options_page( 'kobak social', 'kobak social', 'manage_options', 'kobak-social', 'kobak_social_settings_page' );
}
add_action( 'admin_menu', 'kobak_social_admin_menu' );

/**
 * Beállítási oldal.
 */
function kobak_social_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Mentés.
	if ( isset( $_POST['kobak_social_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kobak_social_nonce'] ) ), 'kobak_social_save' ) ) {
		$current = kobak_social_get_settings();

		$new = array(
			'enabled'                => isset( $_POST['enabled'] ) ? 1 : 0,
			'bsky_handle'            => sanitize_text_field( wp_unslash( $_POST['bsky_handle'] ?? '' ) ),
			'bsky_app_password'      => kobak_social_secret_value( 'bsky_app_password', $current['bsky_app_password'] ),
			'mastodon_instance'      => esc_url_raw( wp_unslash( $_POST['mastodon_instance'] ?? '' ) ),
			'mastodon_token'         => kobak_social_secret_value( 'mastodon_token', $current['mastodon_token'] ),
			'mastodon_handle'        => trim( sanitize_text_field( wp_unslash( $_POST['mastodon_handle'] ?? '' ) ) ),
			'linkedin_enabled'       => isset( $_POST['linkedin_enabled'] ) ? 1 : 0,
			'linkedin_token'         => kobak_social_secret_value( 'linkedin_token', $current['linkedin_token'] ),
			'linkedin_author_urn'    => trim( sanitize_text_field( wp_unslash( $_POST['linkedin_author_urn'] ?? '' ) ) ),
			'linkedin_version'       => preg_replace( '/[^0-9]/', '', sanitize_text_field( wp_unslash( $_POST['linkedin_version'] ?? '202607' ) ) ),
			'post_language'          => strtolower( substr( preg_replace( '/[^A-Za-z]/', '', sanitize_text_field( wp_unslash( $_POST['post_language'] ?? '' ) ) ), 0, 2 ) ),
			'template'               => sanitize_textarea_field( wp_unslash( $_POST['template'] ?? '' ) ),
			'post_types'             => array( 'post' ),
			'show_syndication'       => isset( $_POST['show_syndication'] ) ? 1 : 0,
			'disable_local_comments' => isset( $_POST['disable_local_comments'] ) ? 1 : 0,
		);

		// A LinkedIn-token nagyjából 60 nap után lejár, ezért kell a mentés dátuma.
		$new['linkedin_token_saved'] = (int) $current['linkedin_token_saved'];
		if ( $new['linkedin_token'] !== $current['linkedin_token'] ) {
			$new['linkedin_token_saved'] = $new['linkedin_token'] ? time() : 0;
		}

		// Merge, nem felülírás: ami nincs az űrlapon, az maradjon meg.
		update_option( KOBAK_SOCIAL_OPTION, wp_parse_args( $new, $current ), false );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'kobak-social' ) . '</p></div>';
	}

	// LinkedIn kapcsolat ellenőrzése — nem posztol, csak lekérdez.
	if ( isset( $_POST['kobak_social_licheck_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kobak_social_licheck_nonce'] ) ), 'kobak_social_licheck' ) ) {
		$chk = kobak_social_li_check();
		if ( is_wp_error( $chk ) ) {
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'LinkedIn connection failed.', 'kobak-social' ) . '</strong> ' . esc_html( $chk->get_error_message() ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success"><p><strong>' . esc_html__( 'LinkedIn connection works.', 'kobak-social' ) . '</strong> ' .
				esc_html(
					sprintf(
						/* translators: %s: the name of the signed in LinkedIn member. */
						__( 'Signed in as %s. The author URN has been saved.', 'kobak-social' ),
						$chk['nev']
					)
				) . '</p></div>';
		}
	}

	// Csak LinkedIn teszt.
	if ( isset( $_POST['kobak_social_litest_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kobak_social_litest_nonce'] ) ), 'kobak_social_litest' ) ) {
		$latest = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish' ) );
		if ( $latest ) {
			$r = kobak_social_post_linkedin( $latest[0], true );
			if ( is_wp_error( $r ) ) {
				echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'LinkedIn test failed.', 'kobak-social' ) . '</strong> ' . esc_html( $r->get_error_message() ) . '</p></div>';
				kobak_social_log( sprintf( 'LinkedIn test #%d -> ERROR - %s', $latest[0]->ID, $r->get_error_message() ) );
			} else {
				$url = get_post_meta( $latest[0]->ID, '_kobak_social_linkedin_url', true );
				echo '<div class="notice notice-success"><p><strong>' . esc_html__( 'LinkedIn test posted.', 'kobak-social' ) . '</strong> ' .
					( $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open the post', 'kobak-social' ) . '</a>' : '' ) . '</p></div>';
				kobak_social_log( sprintf( 'LinkedIn test #%d -> OK', $latest[0]->ID ) );
			}
		}
	}

	// Sikertelen megosztások újrapróbálása. Csak a hiányzó platformokra megy ki,
	// a sikeres poszt nem megy ki még egyszer.
	if ( isset( $_POST['kobak_social_retry_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kobak_social_retry_nonce'] ) ), 'kobak_social_retry' ) ) {
		$pending = get_option( KOBAK_SOCIAL_FAILURES, array() );
		$stuck   = 0;
		$now     = kobak_social_get_settings();
		$blocked = empty( $now['enabled'] );

		if ( ! $blocked && is_array( $pending ) ) {
			foreach ( array_keys( $pending ) as $retry_id ) {
				if ( kobak_social_share_post( $retry_id ) ) {
					$stuck++;
				} else {
					kobak_social_clear_failure( $retry_id );
				}
			}
		}

		if ( $blocked ) {
			// Kikapcsolt megosztásnál a share_post csendben visszafordul, abból
			// sikert hinni és törölni a listát félrevezető lenne.
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Automatic sharing is switched off, so nothing was retried. Turn it on above and try again.', 'kobak-social' ) . '</p></div>';
		} elseif ( $stuck ) {
			echo '<div class="notice notice-error"><p>' . esc_html(
				sprintf(
					/* translators: %d: number of posts that still could not be shared. */
					_n( '%d post still could not be shared. See the log below.', '%d posts still could not be shared. See the log below.', $stuck, 'kobak-social' ),
					$stuck
				)
			) . '</p></div>';
		} else {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Everything went out this time.', 'kobak-social' ) . '</p></div>';
		}
	}

	// Teszt megosztás minden bekapcsolt platformra.
	if ( isset( $_POST['kobak_social_test_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kobak_social_test_nonce'] ) ), 'kobak_social_test' ) ) {
		$latest = get_posts( array( 'numberposts' => 1, 'post_status' => 'publish' ) );
		if ( $latest ) {
			kobak_social_share_post( $latest[0]->ID, true );
			echo '<div class="notice notice-info"><p>' . esc_html__( 'The test ran with your latest post. See the result in the log below.', 'kobak-social' ) . '</p></div>';
		}
	}

	$s   = kobak_social_get_settings();
	$log = get_option( KOBAK_SOCIAL_LOG, array() );
	?>
	<div class="wrap">
		<h1>kobak social</h1>
		<p><?php esc_html_e( 'Automatically shares every new post to Bluesky and Mastodon. LinkedIn is available as an optional extra.', 'kobak-social' ); ?></p>

		<form method="post">
			<?php wp_nonce_field( 'kobak_social_save', 'kobak_social_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatic sharing', 'kobak-social' ); ?></th>
					<td>
						<label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'], 1 ); ?>> <?php esc_html_e( 'Enabled', 'kobak-social' ); ?></label>
						<p class="description"><?php esc_html_e( 'Runs only when a post is published for the first time. Editing an existing post never shares it again.', 'kobak-social' ); ?></p>
					</td>
				</tr>

				<tr><th colspan="2"><h2 style="margin:1em 0 0">Bluesky</h2></th></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Handle', 'kobak-social' ); ?></th>
					<td><input type="text" name="bsky_handle" class="regular-text" value="<?php echo esc_attr( $s['bsky_handle'] ); ?>" placeholder="you.bsky.social"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'App password', 'kobak-social' ); ?></th>
					<td>
						<?php kobak_social_secret_field( 'bsky_app_password', $s['bsky_app_password'], 'xxxx-xxxx-xxxx-xxxx' ); ?>
						<p class="description"><?php esc_html_e( 'Bluesky: Settings, Privacy and security, App passwords. Do not use your account password.', 'kobak-social' ); ?></p>
					</td>
				</tr>

				<tr><th colspan="2"><h2 style="margin:1em 0 0">Mastodon</h2></th></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Instance', 'kobak-social' ); ?></th>
					<td><input type="url" name="mastodon_instance" class="regular-text" value="<?php echo esc_attr( $s['mastodon_instance'] ); ?>" placeholder="https://mastodon.social"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Username', 'kobak-social' ); ?></th>
					<td>
						<input type="text" name="mastodon_handle" class="regular-text" value="<?php echo esc_attr( $s['mastodon_handle'] ); ?>" placeholder="yourname">
						<p class="description"><?php esc_html_e( 'Without the @ sign. Only used to link to your profile on older posts.', 'kobak-social' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Access token', 'kobak-social' ); ?></th>
					<td>
						<?php kobak_social_secret_field( 'mastodon_token', $s['mastodon_token'] ); ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: the name of the required Mastodon scope, wrapped in a code tag. */
								esc_html__( 'Mastodon: Preferences, Development, New application. Required scope: %s.', 'kobak-social' ),
								'<code>write:statuses</code>'
							);
							?>
						</p>
					</td>
				</tr>

				<tr><th colspan="2"><h2 style="margin:1em 0 0"><?php esc_html_e( 'LinkedIn (optional)', 'kobak-social' ); ?></h2></th></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Share to LinkedIn', 'kobak-social' ); ?></th>
					<td>
						<label><input type="checkbox" name="linkedin_enabled" value="1" <?php checked( $s['linkedin_enabled'], 1 ); ?>> <?php esc_html_e( 'Enabled', 'kobak-social' ); ?></label>
						<p class="description"><?php esc_html_e( 'Optional. LinkedIn does not hand out shared API access, so you have to create your own LinkedIn app and generate a token for it. Bluesky and Mastodon work without any of this.', 'kobak-social' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Access token', 'kobak-social' ); ?></th>
					<td>
						<?php kobak_social_secret_field( 'linkedin_token', $s['linkedin_token'] ); ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: the list of required LinkedIn scopes, wrapped in code tags. */
								esc_html__( 'Generate it with the LinkedIn OAuth token generator, not the Client Secret. Required scopes: %s.', 'kobak-social' ),
								'<code>w_member_social</code>, <code>openid</code>, <code>profile</code>'
							);
							?>
							<br><?php esc_html_e( 'LinkedIn tokens expire after about 60 days and have to be generated again.', 'kobak-social' ); ?>
							<?php
							$kobak_token_saved = (int) $s['linkedin_token_saved'];
							if ( $kobak_token_saved ) {
								$kobak_token_age = (int) floor( ( time() - $kobak_token_saved ) / DAY_IN_SECONDS );
								echo '<br><strong>' . esc_html(
									sprintf(
										/* translators: %d: number of days since the token was saved. */
										_n( 'The stored token was saved %d day ago.', 'The stored token was saved %d days ago.', $kobak_token_age, 'kobak-social' ),
										$kobak_token_age
									)
								) . '</strong>';
							}
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Author URN', 'kobak-social' ); ?></th>
					<td>
						<input type="text" name="linkedin_author_urn" class="regular-text" value="<?php echo esc_attr( $s['linkedin_author_urn'] ); ?>" placeholder="urn:li:person:...">
						<p class="description"><?php esc_html_e( 'Leave it empty. The plugin looks it up and fills it in on the first share.', 'kobak-social' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'API version', 'kobak-social' ); ?></th>
					<td>
						<input type="text" name="linkedin_version" class="small-text" value="<?php echo esc_attr( $s['linkedin_version'] ); ?>" placeholder="202607">
						<p class="description"><?php esc_html_e( 'YYYYMM format. LinkedIn supports each version for about a year, then this has to be bumped.', 'kobak-social' ); ?></p>
					</td>
				</tr>

				<tr><th colspan="2"><h2 style="margin:1em 0 0"><?php esc_html_e( 'IndieWeb and comments', 'kobak-social' ); ?></h2></th></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Syndication box', 'kobak-social' ); ?></th>
					<td>
						<label><input type="checkbox" name="show_syndication" value="1" <?php checked( $s['show_syndication'], 1 ); ?>> <?php esc_html_e( 'Show it under each post', 'kobak-social' ); ?></label>
						<p class="description">
							<?php
							printf(
								/* translators: %s: the u-syndication microformat class name, wrapped in a code tag. */
								esc_html__( 'Lists where the post also appeared and invites readers to reply there. The links carry the %s microformat. If you also run a webmention receiver and Brid.gy, that is what lets replies find their way back. This plugin does not receive webmentions itself.', 'kobak-social' ),
								'<code>u-syndication</code>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Local commenting', 'kobak-social' ); ?></th>
					<td>
						<label><input type="checkbox" name="disable_local_comments" value="1" <?php checked( $s['disable_local_comments'], 1 ); ?>> <?php esc_html_e( 'Turn it off, so people comment on social instead', 'kobak-social' ); ?></label>
						<p class="description"><?php esc_html_e( 'This only hides the visitor comment form. It does not touch webmentions, so if you use a webmention plugin, replies collected by Brid.gy still show up under the post.', 'kobak-social' ); ?></p>
					</td>
				</tr>

				<tr><th colspan="2"><h2 style="margin:1em 0 0"><?php esc_html_e( 'Message', 'kobak-social' ); ?></h2></th></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Language', 'kobak-social' ); ?></th>
					<td>
						<input type="text" name="post_language" class="small-text" value="<?php echo esc_attr( $s['post_language'] ); ?>" placeholder="<?php echo esc_attr( kobak_social_language() ); ?>" maxlength="2">
						<p class="description"><?php esc_html_e( 'Two-letter code sent along with the post, so timelines and translation prompts get it right. Leave it empty to follow the site language.', 'kobak-social' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Template', 'kobak-social' ); ?></th>
					<td>
						<textarea name="template" rows="6" class="large-text code"><?php echo esc_textarea( $s['template'] ); ?></textarea>
						<p class="description">
							<?php
							printf(
								/* translators: %s: the list of available template tags, wrapped in code tags. */
								esc_html__( 'Available tags: %s.', 'kobak-social' ),
								'<code>{title}</code>, <code>{excerpt}</code>, <code>{url}</code>'
							);
							?>
							<br><?php esc_html_e( 'Limits are 300 characters on Bluesky, 500 on Mastodon and 2800 on LinkedIn. The excerpt is shortened automatically; the title and the link are always kept.', 'kobak-social' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save settings', 'kobak-social' ) ); ?>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Check the LinkedIn connection', 'kobak-social' ); ?></h2>
		<p><?php esc_html_e( 'Checks whether the token works and stores your author URN. It does not post anything. Worth doing before you run a real share.', 'kobak-social' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'kobak_social_licheck', 'kobak_social_licheck_nonce' ); ?>
			<?php submit_button( __( 'Check the LinkedIn connection', 'kobak-social' ), 'secondary' ); ?>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Test LinkedIn only', 'kobak-social' ); ?></h2>
		<p><?php esc_html_e( 'Shares your latest published post to LinkedIn only. Bluesky and Mastodon are left alone, so there is just one post to delete afterwards.', 'kobak-social' ); ?></p>
		<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'This creates a real LinkedIn post. Continue?', 'kobak-social' ) ); ?>');">
			<?php wp_nonce_field( 'kobak_social_litest', 'kobak_social_litest_nonce' ); ?>
			<?php submit_button( __( 'Post a test to LinkedIn', 'kobak-social' ), 'secondary' ); ?>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Test everything', 'kobak-social' ); ?></h2>
		<p><?php esc_html_e( 'Shares your latest published post to every enabled platform. These are real posts, so they will be visible.', 'kobak-social' ); ?></p>
		<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'This creates real posts on every enabled platform. Continue?', 'kobak-social' ) ); ?>');">
			<?php wp_nonce_field( 'kobak_social_test', 'kobak_social_test_nonce' ); ?>
			<?php submit_button( __( 'Share a test now', 'kobak-social' ), 'secondary' ); ?>
		</form>

		<?php
		$failures = get_option( KOBAK_SOCIAL_FAILURES, array() );
		if ( is_array( $failures ) && $failures ) :
			?>
		<hr>
		<h2><?php esc_html_e( 'Failed shares', 'kobak-social' ); ?></h2>
		<p><?php esc_html_e( 'These posts did not go out after three attempts. Retrying only touches the platforms that are still missing.', 'kobak-social' ); ?></p>
		<ul style="list-style:disc;margin-left:1.5em">
			<?php foreach ( $failures as $failed_id => $row ) : ?>
				<?php
				if ( ! get_post( $failed_id ) ) {
					continue;
				}
				$labels = array();
				foreach ( (array) $row['platforms'] as $platform ) {
					$labels[] = kobak_social_platform_label( $platform );
				}
				?>
				<li>
					<a href="<?php echo esc_url( get_edit_post_link( $failed_id ) ); ?>"><?php echo esc_html( get_the_title( $failed_id ) ); ?></a>
					— <?php echo esc_html( implode( ', ', $labels ) ); ?>
					<span style="opacity:.7">(<?php echo esc_html( $row['time'] ); ?>)</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<form method="post">
			<?php wp_nonce_field( 'kobak_social_retry', 'kobak_social_retry_nonce' ); ?>
			<?php submit_button( __( 'Retry now', 'kobak-social' ), 'secondary' ); ?>
		</form>
		<?php endif; ?>

		<hr>
		<h2><?php esc_html_e( 'Log', 'kobak-social' ); ?></h2>
		<?php if ( empty( $log ) ) : ?>
			<p><?php esc_html_e( 'Nothing here yet.', 'kobak-social' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th style="width:160px"><?php esc_html_e( 'When', 'kobak-social' ); ?></th><th><?php esc_html_e( 'What happened', 'kobak-social' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $log as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['time'] ); ?></td>
						<td><?php echo esc_html( $row['msg'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
