<?php
/**
 * Mastodon platform.
 *
 * @package kobak-social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kobak_Social_Mastodon implements Kobak_Social_Platform {

	public function id() {
		return 'mastodon';
	}

	public function label() {
		return 'Mastodon';
	}

	public function limit() {
		return 500;
	}

	public function is_enabled( $settings ) {
		return true;
	}

	public function settings_fields( $settings ) {
		$s = $settings;
		?>
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
		<?php
	}

	/**
	 * A Mastodon mezők beolvasása mentéskor. A nonce-ot a hívó már ellenőrizte.
	 */
	public function save_fields( $current ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		return array(
			'mastodon_instance' => esc_url_raw( wp_unslash( $_POST['mastodon_instance'] ?? '' ) ),
			'mastodon_token'    => kobak_social_secret_value( 'mastodon_token', $current['mastodon_token'] ),
			'mastodon_handle'   => trim( sanitize_text_field( wp_unslash( $_POST['mastodon_handle'] ?? '' ) ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	public function publish( $post, $settings, $force = false ) {
		$s = $settings;
		if ( empty( $s['mastodon_token'] ) || empty( $s['mastodon_instance'] ) ) {
			return new WP_Error( 'kobak_social_mastodon_missing', __( 'Mastodon credentials are missing.', 'kobak-social' ) );
		}

		$endpoint = untrailingslashit( esc_url_raw( $s['mastodon_instance'] ) ) . '/api/v1/statuses';
		$text     = kobak_social_build_message( $post, $this->limit() );

		/** This filter is documented in includes/platforms/class-kobak-social-bluesky.php */
		$text = apply_filters( 'kobak_social_message', $text, $post, $this->id() );

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
			// A data-ban visszaadott státusz alapján dönti el az újrapróbálkozás logikája,
			// hogy végleges-e a hiba — az üzenet szövege emiatt nem változik.
			return new WP_Error( 'kobak_social_mastodon_post', sprintf( /* translators: 1: HTTP status code, 2: error response from the Mastodon API. */ __( 'Mastodon error (HTTP %1$d): %2$s', 'kobak-social' ), (int) $code, wp_remote_retrieve_body( $res ) ), array( 'status' => (int) $code ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! empty( $body['url'] ) ) {
			update_post_meta( $post->ID, '_kobak_social_mastodon_url', esc_url_raw( $body['url'] ) );
		}

		return true;
	}
}
