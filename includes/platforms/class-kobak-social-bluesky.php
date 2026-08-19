<?php
/**
 * Bluesky (AT Protocol) platform.
 *
 * @package kobak-social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kobak_Social_Bluesky implements Kobak_Social_Platform {

	public function id() {
		return 'bluesky';
	}

	public function label() {
		return 'Bluesky';
	}

	public function limit() {
		return 300;
	}

	public function is_enabled( $settings ) {
		return true;
	}

	public function settings_fields( $settings ) {
		$s = $settings;
		?>
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
		<?php
	}

	/**
	 * A Bluesky mezők beolvasása mentéskor. A nonce-ot a hívó már ellenőrizte.
	 */
	public function save_fields( $current ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		return array(
			'bsky_handle'       => sanitize_text_field( wp_unslash( $_POST['bsky_handle'] ?? '' ) ),
			'bsky_app_password' => kobak_social_secret_value( 'bsky_app_password', $current['bsky_app_password'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Bluesky facet: a szövegben szereplő URL kattinthatóvá tétele.
	 */
	private function facets( $text ) {
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

	public function publish( $post, $settings, $force = false ) {
		$s = $settings;
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

		$text = kobak_social_build_message( $post, $this->limit() );

		/**
		 * Az üzenet szövege platformonként felülírható.
		 *
		 * @param string  $text     Az összeállított szöveg.
		 * @param WP_Post $post     A megosztott bejegyzés.
		 * @param string  $platform A platform azonosítója.
		 */
		$text = apply_filters( 'kobak_social_message', $text, $post, $this->id() );

		$record = array(
			'$type'     => 'app.bsky.feed.post',
			'text'      => $text,
			'createdAt' => gmdate( 'c' ),
			'langs'     => array( kobak_social_language() ),
		);
		$facets = $this->facets( $text );
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
}
