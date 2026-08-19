<?php
/**
 * Napló, szövegépítés, a megosztás vezénylése és az újrapróbálkozás.
 *
 * @package kobak-social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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

/* -------------------------------------------------------------------------
 * Publikálásra fülelés
 * ---------------------------------------------------------------------- */

/**
 * Amelyik platformra egyáltalán megyünk. Minden regisztrált platformot
 * megkérdezünk: is_enabled() dönti el, hogy bekapcsolt állapotban van-e.
 * Ez szándékosan nem hitelesítő adatot ellenőriz — az a publish() dolga,
 * és annak a hibáját a napló mutatja meg.
 */
function kobak_social_active_platforms( $settings ) {
	$active = array();
	foreach ( Kobak_Social_Registry::get_platforms() as $platform ) {
		if ( $platform->is_enabled( $settings ) ) {
			$active[] = $platform->id();
		}
	}
	return $active;
}

/**
 * Egy platform kiszolgálása. Azért külön, hogy a naplózás, a jelölés és az
 * újrapróbálkozás mindenhol ugyanúgy működjön.
 */
function kobak_social_publish_to( $platform, $post, $settings, $force = false ) {
	$obj = Kobak_Social_Registry::get( $platform );
	if ( ! $obj ) {
		return new WP_Error( 'kobak_social_unknown_platform', 'Unknown platform: ' . $platform );
	}
	return $obj->publish( $post, $settings, $force );
}

/**
 * Platformonkénti „ez már kiment" jelölés kulcsa.
 */
function kobak_social_done_meta_key( $platform ) {
	return '_kobak_social_done_' . $platform;
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

		$result = kobak_social_publish_to( $platform, $post, $s, $force );
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
