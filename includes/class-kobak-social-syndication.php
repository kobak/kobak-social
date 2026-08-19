<?php
/**
 * IndieWeb: szindikációs linkek + „kommentelj social-ban" mód.
 *
 * @package kobak-social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A bejegyzéshez tartozó social másolatok URL-jei.
 */
function kobak_social_syndication_urls( $post_id ) {
	$urls = array();
	foreach ( Kobak_Social_Registry::get_platforms() as $platform ) {
		$url = get_post_meta( $post_id, '_kobak_social_' . $platform->id() . '_url', true );
		if ( $url ) {
			$urls[ $platform->label() ] = $url;
		}
	}

	/**
	 * A bejegyzéshez tartozó szindikációs linkek listája.
	 *
	 * @param array $urls    Címke => URL párok.
	 * @param int   $post_id
	 */
	return apply_filters( 'kobak_social_syndication_urls', $urls, $post_id );
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
