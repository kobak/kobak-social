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
 * A megosztás, a hibák részletével együtt. Platformonként külön jelölést
 * tesz a bejegyzésre, így egy elszállt Mastodon-poszt később újrapróbálható
 * anélkül, hogy a sikeres Bluesky-poszt még egyszer kimenne.
 *
 * A kobak_social_share_post() ennek vékony csomagolása — a visszatérési
 * értékét egy téma is hívhatja, ezért annak alakja nem változhat. Ahol a
 * WP_Error is kell (pl. az újrapróbálkozás dönti el belőle, hogy végleges-e
 * a hiba), ezt a függvényt kell hívni.
 *
 * @return array {
 *     @type array $failed A sikertelen platformok azonosítói.
 *     @type array $errors A sikertelen platformok WP_Error objektumai, platform azonosító szerint kulcsolva.
 * }
 */
function kobak_social_share_post_detailed( $post_id, $force = false ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array(
			'failed' => array(),
			'errors' => array(),
		);
	}

	$s = kobak_social_get_settings();

	if ( ! $force ) {
		if ( empty( $s['enabled'] ) ) {
			return array(
				'failed' => array(),
				'errors' => array(),
			);
		}
		if ( ! in_array( $post->post_type, (array) $s['post_types'], true ) ) {
			return array(
				'failed' => array(),
				'errors' => array(),
			);
		}
		// 1.4 előtt egyetlen közös jelölés volt. Ha az megvan, de platformonkénti
		// még nincs, akkor ez a bejegyzés a régi logika szerint már kiment.
		if ( get_post_meta( $post_id, KOBAK_SOCIAL_DONE_META, true ) && ! kobak_social_has_platform_meta( $post_id ) ) {
			return array(
				'failed' => array(),
				'errors' => array(),
			);
		}
	}

	$results = array();
	$failed  = array();
	$errors  = array();

	foreach ( kobak_social_active_platforms( $s ) as $platform ) {
		$done_key = kobak_social_done_meta_key( $platform );
		if ( ! $force && get_post_meta( $post_id, $done_key, true ) ) {
			continue; // ez a platform már megkapta
		}

		$result = kobak_social_publish_to( $platform, $post, $s, $force );
		$label  = kobak_social_platform_label( $platform );

		if ( is_wp_error( $result ) ) {
			$failed[]            = $platform;
			$errors[ $platform ] = $result;
			$results[]           = $label . ': ERROR - ' . $result->get_error_message();
		} else {
			update_post_meta( $post_id, $done_key, current_time( 'mysql' ) );
			$results[] = $label . ': OK';
		}
	}

	if ( ! $results ) {
		return array(
			'failed' => array(),
			'errors' => array(),
		);
	}

	// A közös jelölés is megmarad: erről ismeri fel a régebbi verzióval
	// megosztott bejegyzéseket, és erre épül a fenti visszalépés-védelem.
	if ( count( $failed ) < count( $results ) ) {
		update_post_meta( $post_id, KOBAK_SOCIAL_DONE_META, current_time( 'mysql' ) );
	}

	kobak_social_log( sprintf( '#%d "%s" -> %s', $post_id, get_the_title( $post ), implode( ' | ', $results ) ) );

	return array(
		'failed' => $failed,
		'errors' => $errors,
	);
}

/**
 * A megosztás. Platformonként külön jelölést tesz a bejegyzésre, így egy
 * elszállt Mastodon-poszt később újrapróbálható anélkül, hogy a sikeres
 * Bluesky-poszt még egyszer kimenne.
 *
 * @return array A sikertelen platformok azonosítói.
 */
function kobak_social_share_post( $post_id, $force = false ) {
	$detail = kobak_social_share_post_detailed( $post_id, $force );
	return $detail['failed'];
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

/**
 * A bejegyzéshez már rögzített sikertelen platformok. Ahhoz kell, hogy egy
 * végleges hibáról ne szóljunk többször ugyanarra a bejegyzésre.
 */
function kobak_social_recorded_failures( $post_id ) {
	$failures = get_option( KOBAK_SOCIAL_FAILURES, array() );
	if ( ! is_array( $failures ) || empty( $failures[ (int) $post_id ]['platforms'] ) ) {
		return array();
	}
	return array_values( (array) $failures[ (int) $post_id ]['platforms'] );
}

/**
 * A hibalista karbantartva. Kikerül belőle a törölt bejegyzés és az a platform,
 * ami időközben kikerült a beállításokból — egy be sem állított hálózat ne
 * figyelmeztessen minden admin oldalon olyasmiért, amit nem is akartunk.
 *
 * Csak akkor ír, ha tényleg változott valami.
 *
 * @return array A megtisztított lista.
 */
function kobak_social_pending_failures() {
	$failures = get_option( KOBAK_SOCIAL_FAILURES, array() );
	if ( ! is_array( $failures ) ) {
		return array();
	}

	$active = kobak_social_active_platforms( kobak_social_get_settings() );
	$clean  = array();

	foreach ( $failures as $failed_id => $row ) {
		if ( ! get_post( $failed_id ) ) {
			continue;
		}
		$platforms = isset( $row['platforms'] ) ? array_values( array_intersect( (array) $row['platforms'], $active ) ) : array();
		if ( ! $platforms ) {
			continue;
		}
		$row['platforms']    = $platforms;
		$clean[ $failed_id ] = $row;
	}

	if ( $clean !== $failures ) {
		update_option( KOBAK_SOCIAL_FAILURES, $clean, false );
	}

	return $clean;
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
 * Eldönti egy megosztási hibáról, hogy végleges-e — tehát olyan, amit az
 * újrapróbálkozás nem tud orvosolni (hiányzó hitelesítő adat, lejárt token,
 * hiányzó jogosultság), szemben az átmenetivel (időtúllépés, hálózati hiba,
 * túlterhelt szerver), ami idővel magától is helyrejöhet.
 *
 * @param WP_Error $error
 * @return bool
 */
function kobak_social_is_permanent_failure( $error ) {
	if ( ! is_wp_error( $error ) ) {
		return false;
	}

	$code = $error->get_error_code();

	// Ezek soha nem hálózati hibák, hanem hiányzó vagy hibás beállítások —
	// várakozással nem gyógyulnak, tehát azonnal feladjuk, nem próbáljuk újra.
	$always_permanent = array(
		'kobak_social_bsky_missing',
		'kobak_social_mastodon_missing',
		'kobak_social_li_missing',
		'kobak_social_li_no_token',
		'kobak_social_li_notoken',
		'kobak_social_li_off',
		'kobak_social_bsky_auth',
		'kobak_social_li_401',
		'kobak_social_li_403',
		'kobak_social_unknown_platform',
	);
	if ( in_array( $code, $always_permanent, true ) ) {
		return true;
	}

	// Ezeknél a HTTP státuszkód dönt: a data argumentumban utazik (lásd az
	// egyes platformoknál), nem a szövegből fejtjük vissza. 4xx a 408
	// (timeout) és a 429 (rate limit) kivételével véglegesnek számít — egy
	// 401/403/400 újrapróbálkozással nem lesz jobb.
	$status_based = array(
		'kobak_social_bsky_post',
		'kobak_social_mastodon_post',
		'kobak_social_li_post',
		'kobak_social_li_upload',
		'kobak_social_li_userinfo',
		'kobak_social_li_check',
	);
	if ( in_array( $code, $status_based, true ) ) {
		$data   = $error->get_error_data();
		$status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 0;
		if ( $status >= 400 && $status < 500 && 408 !== $status && 429 !== $status ) {
			return true;
		}
	}

	// Minden más (a wp_remote_post()/wp_remote_get() saját WP_Error-ja hálózati
	// hiba esetén, illetve az 5xx/408/429 státuszok) átmenetinek számít.
	return false;
}

/**
 * Az ütemezett megosztás. Ha valamelyik platform elszállt, a hiba fajtája
 * dönti el a folytatást: az átmeneti hibák (leggyakrabban időtúllépés)
 * növekvő szünettel újrapróbálhatók, a végleges hibák (pl. hiányzó
 * hitelesítő adat) viszont azonnal a hibalistába kerülnek — ott várakozás
 * semmit nem javítana, csak feleslegesen tartaná a bejegyzést a
 * háttérfolyamatban. A sikeres platformok a jelölésük miatt kimaradnak,
 * tehát nem lesz dupla poszt.
 */
function kobak_social_run_scheduled_share( $post_id, $attempt = 1 ) {
	$attempt = max( 1, (int) $attempt );
	$detail  = kobak_social_share_post_detailed( $post_id );
	$failed  = $detail['failed'];
	$errors  = $detail['errors'];

	if ( empty( $failed ) ) {
		kobak_social_clear_failure( $post_id );
		return;
	}

	$permanent = array();
	$transient = array();
	foreach ( $failed as $platform ) {
		$error = isset( $errors[ $platform ] ) ? $errors[ $platform ] : null;
		if ( $error && kobak_social_is_permanent_failure( $error ) ) {
			$permanent[] = $platform;
		} else {
			$transient[] = $platform;
		}
	}

	// A végleges hibájú platformokat azonnal a hibalistába írjuk — ezekre
	// várni fölösleges, a felhasználónak a beállításon kell javítania.
	if ( $permanent ) {
		// Egy átmeneti hibájú társ miatt ez a függvény még kétszer lefuthat.
		// A véglegesről viszont egyszer kell szólni, nem minden körben.
		$mar_rogzitett = kobak_social_recorded_failures( $post_id );
		$ujak          = array_values( array_diff( $permanent, $mar_rogzitett ) );

		kobak_social_record_failure( $post_id, array_values( array_unique( array_merge( $mar_rogzitett, $permanent ) ) ) );

		if ( $ujak ) {
			kobak_social_log( sprintf( '#%d gave up immediately, permanent failure: %s', $post_id, implode( ', ', $ujak ) ) );
		}
	}

	// Az átmeneti hibájú platformokra a megszokott módon, növekvő szünettel
	// újrapróbálkozunk — a végleges hiba nem tarthatja fogva ezt a folyamatot.
	if ( $transient ) {
		if ( $attempt < KOBAK_SOCIAL_MAX_ATTEMPTS ) {
			$delay = 300 * $attempt;
			wp_schedule_single_event( time() + $delay, 'kobak_social_delayed_share', array( $post_id, $attempt + 1 ) );
			kobak_social_log( sprintf( '#%d retry %d/%d in %d minutes', $post_id, $attempt + 1, KOBAK_SOCIAL_MAX_ATTEMPTS, (int) ( $delay / 60 ) ) );
			return;
		}

		// A véglegesen elszállt platformok rekordját itt egészítjük ki az
		// átmenetiekkel is, mert kobak_social_record_failure() a teljes
		// bejegyzésnyi listát cseréli le, nem egészíti ki azt.
		kobak_social_record_failure( $post_id, array_merge( $permanent, $transient ) );
		kobak_social_log( sprintf( '#%d gave up after %d attempts: %s', $post_id, KOBAK_SOCIAL_MAX_ATTEMPTS, implode( ', ', $transient ) ) );
	}
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
