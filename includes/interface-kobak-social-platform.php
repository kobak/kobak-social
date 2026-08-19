<?php
/**
 * A közösségi platformok közös szerződése. Egy új hálózat felvétele csak
 * ennyit igényel: egy osztály, ami ezt implementálja, plusz regisztrálás a
 * kobak_social_platforms szűrőn keresztül.
 *
 * @package kobak-social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Kobak_Social_Platform {

	/**
	 * A platform azonosítója, pl. 'bluesky'.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Emberi név a naplóhoz és a szindikációs dobozhoz.
	 *
	 * @return string
	 */
	public function label();

	/**
	 * Az üzenet karakterkorlátja ezen a platformon.
	 *
	 * @return int
	 */
	public function limit();

	/**
	 * Megy-e egyáltalán ez a platform az automatikus megosztásba — tehát
	 * bekapcsolt állapotban van-e. FONTOS: ez nem azt jelenti, hogy a
	 * hitelesítő adatok is rendben vannak; a hiányzó/hibás beállítás
	 * ellenőrzése a publish() dolga, aminek ilyenkor WP_Error-t kell
	 * visszaadnia. Ha egy bekapcsolt, de rosszul beállított platform itt
	 * csendben leállna, a napló hazudna: eddig pont az volt a lényeg, hogy
	 * egy elfelejtett token azonnal látszódjon a naplóban, ne csak
	 * találgatás legyen belőle.
	 *
	 * @param array $settings A kobak_social_settings opció tartalma.
	 * @return bool
	 */
	public function is_enabled( $settings );

	/**
	 * A platform saját szakasza a beállítási oldalon — a szakaszcím sorától
	 * a saját mezőiig, a teljes blokkot ez a metódus rajzolja ki.
	 *
	 * @param array $settings A kobak_social_settings opció tartalma.
	 */
	public function settings_fields( $settings );

	/**
	 * A platform saját mezőinek beolvasása mentéskor, a $_POST-ból. A
	 * visszaadott tömb kulcs => érték párjai kerülnek bele a mentett
	 * beállítás-opcióba. FONTOS: ez a metódus nem bízhat a $_POST
	 * tartalmában — mindent magának kell sanitizálnia (sanitize_text_field,
	 * esc_url_raw, kobak_social_secret_value stb.); a nonce ellenőrzése a
	 * hívó dolga, az már megtörtént, mire ide kerül a vezérlés.
	 *
	 * @param array $current A jelenleg eltárolt beállítások — a titkos
	 *                        mezők megőrzéséhez kell, ha az űrlapon üresen
	 *                        jöttek be.
	 * @return array Kulcs => érték párok a mentett opcióhoz.
	 */
	public function save_fields( $current );

	/**
	 * A bejegyzés kiposztolása. Sikeres kimenetnél true, egyébként WP_Error
	 * — utóbbi adja a napló hibaüzenetét és indítja az újrapróbálkozást.
	 *
	 * @param WP_Post $post
	 * @param array   $settings A kobak_social_settings opció tartalma.
	 * @param bool    $force    Kézi/teszt megosztásnál true; ilyenkor a
	 *                          platform saját duplikálás-védelme (ha van)
	 *                          kikapcsol, hogy tényleg kimenjen még egyszer.
	 * @return true|WP_Error
	 */
	public function publish( $post, $settings, $force = false );
}
