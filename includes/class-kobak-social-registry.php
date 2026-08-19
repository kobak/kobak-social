<?php
/**
 * A regisztrált platformok gyűjtője. Harmadik fél a kobak_social_platforms
 * szűrővel vehet fel egy negyedik hálózatot, anélkül hogy bármelyik meglévő
 * fájlhoz hozzá kellene nyúlnia.
 *
 * @package kobak-social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kobak_Social_Registry {

	/**
	 * A regisztrált platformok, a megosztás sorrendjében.
	 *
	 * @return Kobak_Social_Platform[]
	 */
	public static function get_platforms() {
		$platforms = array(
			new Kobak_Social_Bluesky(),
			new Kobak_Social_Mastodon(),
			new Kobak_Social_LinkedIn(),
		);

		/**
		 * A regisztrált közösségi platformok listája.
		 *
		 * @param Kobak_Social_Platform[] $platforms
		 */
		return apply_filters( 'kobak_social_platforms', $platforms );
	}

	/**
	 * Egy platform megkeresése azonosító alapján.
	 *
	 * @param string $id
	 * @return Kobak_Social_Platform|null
	 */
	public static function get( $id ) {
		foreach ( self::get_platforms() as $platform ) {
			if ( $platform->id() === $id ) {
				return $platform;
			}
		}
		return null;
	}
}

/**
 * A támogatott platformok azonosítói, a megosztás sorrendjében.
 */
function kobak_social_platforms() {
	$ids = array();
	foreach ( Kobak_Social_Registry::get_platforms() as $platform ) {
		$ids[] = $platform->id();
	}
	return $ids;
}

/**
 * Emberi név a naplóhoz és a felülethez.
 */
function kobak_social_platform_label( $platform ) {
	$obj = Kobak_Social_Registry::get( $platform );
	return $obj ? $obj->label() : $platform;
}
