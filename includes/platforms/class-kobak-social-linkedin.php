<?php
/**
 * LinkedIn platform.
 *
 * Fontos: itt szándékosan NINCS curl_exec(). A kobak.org tárhelyén a PHP
 * disable_functions listája tiltja, ezért szállt el 500-zal a korábbi
 * LinkedIn plugin. A WordPress saját HTTP API-ja (wp_remote_*) ha nincs
 * cURL, automatikusan stream alapú átvitelre vált, tehát itt működik.
 *
 * @package kobak-social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kobak_Social_LinkedIn implements Kobak_Social_Platform {

	public function id() {
		return 'linkedin';
	}

	public function label() {
		return 'LinkedIn';
	}

	public function limit() {
		return 2800;
	}

	public function is_enabled( $settings ) {
		return ! empty( $settings['linkedin_enabled'] );
	}

	public function settings_fields( $settings ) {
		$s = $settings;
		?>
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
		<?php
	}

	/**
	 * A LinkedIn mezők beolvasása mentéskor. A nonce-ot a hívó már ellenőrizte.
	 */
	public function save_fields( $current ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$new = array(
			'linkedin_enabled'    => isset( $_POST['linkedin_enabled'] ) ? 1 : 0,
			'linkedin_token'      => kobak_social_secret_value( 'linkedin_token', $current['linkedin_token'] ),
			'linkedin_author_urn' => trim( sanitize_text_field( wp_unslash( $_POST['linkedin_author_urn'] ?? '' ) ) ),
			'linkedin_version'    => preg_replace( '/[^0-9]/', '', sanitize_text_field( wp_unslash( $_POST['linkedin_version'] ?? '202607' ) ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// A LinkedIn-token nagyjából 60 nap után lejár, ezért kell a mentés dátuma.
		$new['linkedin_token_saved'] = (int) $current['linkedin_token_saved'];
		if ( $new['linkedin_token'] !== $current['linkedin_token'] ) {
			$new['linkedin_token_saved'] = $new['linkedin_token'] ? time() : 0;
		}

		return $new;
	}

	/**
	 * A LinkedIn „little" szövegformátumban a foglalt karaktereket escapelni kell,
	 * akkor is, ha nem elemként használjuk őket.
	 */
	private function escape_text( $text ) {
		$reserved = array( '\\', '|', '{', '}', '@', '[', ']', '(', ')', '<', '>', '#', '*', '_', '~' );
		foreach ( $reserved as $ch ) {
			$text = str_replace( $ch, '\\' . $ch, $text );
		}
		return $text;
	}

	/**
	 * Közös fejlécek a LinkedIn verziózott API-hoz.
	 */
	private function headers( $s, $extra = array() ) {
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
	 *
	 * Ez nincs az interfészen: csak a LinkedInnek van szüksége erre a
	 * lépésre a posztolás előtt.
	 */
	public function author_urn( $s ) {
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
			// A data-ban visszaadott státusz alapján dönti el az újrapróbálkozás logikája,
			// hogy végleges-e a hiba — az üzenet szövege emiatt nem változik.
			return new WP_Error( 'kobak_social_li_userinfo', sprintf( /* translators: 1: HTTP status code, 2: error response from the LinkedIn API. */ __( 'LinkedIn userinfo error (HTTP %1$d): %2$s', 'kobak-social' ), (int) $code, wp_remote_retrieve_body( $res ) ), array( 'status' => (int) $code ) );
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
	 *
	 * Ez sem interfész-metódus: csak a LinkedIn link-kártyájához kell.
	 */
	public function upload_thumbnail( $post, $s, $author ) {
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
				'headers' => $this->headers( $s ),
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
			// A data-ban visszaadott státusz alapján dönti el az újrapróbálkozás logikája,
			// hogy végleges-e a hiba — az üzenet szövege emiatt nem változik.
			return new WP_Error( 'kobak_social_li_upload', sprintf( /* translators: %d: HTTP status code. */ __( 'Image upload failed (HTTP %d).', 'kobak-social' ), (int) $put_code ), array( 'status' => (int) $put_code ) );
		}

		return $init_body['value']['image'];
	}

	/**
	 * A bejegyzés kiposztolása LinkedInre link-kártyaként.
	 */
	public function publish( $post, $settings, $force = false ) {
		$s = $settings;

		if ( empty( $s['linkedin_enabled'] ) && ! $force ) {
			return new WP_Error( 'kobak_social_li_off', __( 'LinkedIn is disabled.', 'kobak-social' ) );
		}
		if ( empty( $s['linkedin_token'] ) ) {
			return new WP_Error( 'kobak_social_li_missing', __( 'The LinkedIn access token is missing.', 'kobak-social' ) );
		}

		$author = $this->author_urn( $s );
		if ( is_wp_error( $author ) ) {
			return $author;
		}

		$text = kobak_social_build_message( $post, $this->limit() );

		/** This filter is documented in includes/platforms/class-kobak-social-bluesky.php */
		$text = apply_filters( 'kobak_social_message', $text, $post, $this->id() );

		$commentary = $this->escape_text( $text );

		$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
		$desc  = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_strip_all_tags( $post->post_content );
		$desc  = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $desc ), ENT_QUOTES, 'UTF-8' ) ) );
		$desc  = mb_substr( $desc, 0, 250 );

		$article = array(
			'source'      => get_permalink( $post ),
			'title'       => mb_substr( $title, 0, 200 ),
			'description' => $desc,
		);

		$thumb = $this->upload_thumbnail( $post, $s, $author );
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
				'headers' => $this->headers( $s ),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			// A data-ban visszaadott státusz alapján dönti el az újrapróbálkozás logikája,
			// hogy végleges-e a hiba — az üzenet szövege emiatt nem változik.
			return new WP_Error( 'kobak_social_li_post', sprintf( /* translators: 1: HTTP status code, 2: error response from the LinkedIn API. */ __( 'LinkedIn error (HTTP %1$d): %2$s', 'kobak-social' ), (int) $code, wp_remote_retrieve_body( $res ) ), array( 'status' => (int) $code ) );
		}

		$urn = wp_remote_retrieve_header( $res, 'x-restli-id' );
		if ( $urn ) {
			update_post_meta( $post->ID, '_kobak_social_linkedin_url', esc_url_raw( 'https://www.linkedin.com/feed/update/' . $urn . '/' ) );
		}

		return true;
	}

	/**
	 * Kapcsolat-ellenőrzés: lekéri a profilt, elmenti az URN-t. Nem posztol.
	 *
	 * Ez az admin oldal saját gombjának a kiszolgálója, nem az interfész
	 * része — más platformnak nincs is hasonló lépése.
	 */
	public function check_connection() {
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
			// A data-ban visszaadott státusz alapján dönti el az újrapróbálkozás logikája,
			// hogy végleges-e a hiba — az üzenet szövege emiatt nem változik.
			return new WP_Error( 'kobak_social_li_check', sprintf( /* translators: 1: HTTP status code, 2: error response from the LinkedIn API. */ __( 'Unexpected response (HTTP %1$d): %2$s', 'kobak-social' ), (int) $code, wp_remote_retrieve_body( $res ) ), array( 'status' => (int) $code ) );
		}

		$urn = 'urn:li:person:' . $body['sub'];
		$s['linkedin_author_urn'] = $urn;
		update_option( KOBAK_SOCIAL_OPTION, $s, false );

		$nev = ! empty( $body['name'] ) ? $body['name'] : __( 'unknown name', 'kobak-social' );

		return array( 'urn' => $urn, 'nev' => $nev );
	}
}
