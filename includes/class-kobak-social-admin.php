<?php
/**
 * Admin felület.
 *
 * @package kobak-social
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

	$failures = kobak_social_pending_failures();
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

function kobak_social_admin_menu() {
	add_options_page( 'kobak social', 'kobak social', 'manage_options', 'kobak-social', 'kobak_social_settings_page' );
}

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

		// Csak a ténylegesen létező, publikus (és nem attachment) post type-okat fogadjuk el —
		// így egy manipulált POST sem tud érvénytelen értéket becsempészni. Az üres kijelölés
		// szándékos lehet, azt nem helyettesítjük vissza 'post'-ra.
		$kobak_valid_post_types = array_diff( array_keys( get_post_types( array( 'public' => true ), 'names' ) ), array( 'attachment' ) );
		$kobak_posted_types     = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['post_types'] ) ) : array();

		$new = array(
			'enabled'                => isset( $_POST['enabled'] ) ? 1 : 0,
			'post_language'          => strtolower( substr( preg_replace( '/[^A-Za-z]/', '', sanitize_text_field( wp_unslash( $_POST['post_language'] ?? '' ) ) ), 0, 2 ) ),
			'template'               => sanitize_textarea_field( wp_unslash( $_POST['template'] ?? '' ) ),
			'post_types'             => array_values( array_intersect( $kobak_posted_types, $kobak_valid_post_types ) ),
			'show_syndication'       => isset( $_POST['show_syndication'] ) ? 1 : 0,
			'disable_local_comments' => isset( $_POST['disable_local_comments'] ) ? 1 : 0,
		);

		// Minden platform a saját mezőit olvassa be és fésüli bele — egy
		// negyedik hálózat felvételéhez ide sem kell nyúlni.
		foreach ( Kobak_Social_Registry::get_platforms() as $platform ) {
			$new = array_merge( $new, $platform->save_fields( $current ) );
		}

		// Merge, nem felülírás: ami nincs az űrlapon, az maradjon meg.
		update_option( KOBAK_SOCIAL_OPTION, wp_parse_args( $new, $current ), false );
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'kobak-social' ) . '</p></div>';
	}

	// LinkedIn kapcsolat ellenőrzése — nem posztol, csak lekérdez.
	if ( isset( $_POST['kobak_social_licheck_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['kobak_social_licheck_nonce'] ) ), 'kobak_social_licheck' ) ) {
		$linkedin = Kobak_Social_Registry::get( 'linkedin' );
		$chk      = $linkedin->check_connection();
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
			$linkedin = Kobak_Social_Registry::get( 'linkedin' );
			$r        = $linkedin->publish( $latest[0], kobak_social_get_settings(), true );
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
		$pending = kobak_social_pending_failures();
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
				<tr>
					<th scope="row"><?php esc_html_e( 'Post types to share', 'kobak-social' ); ?></th>
					<td>
						<?php
						// Az attachment kimarad: egy médiaelem megosztása nem értelmezhető, csak zavart okozna.
						$kobak_saved_types = (array) $s['post_types'];
						$kobak_post_types  = get_post_types( array( 'public' => true ), 'objects' );
						unset( $kobak_post_types['attachment'] );
						foreach ( $kobak_post_types as $kobak_post_type ) :
							?>
							<label style="display:inline-block;margin-right:1em">
								<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $kobak_post_type->name ); ?>" <?php checked( in_array( $kobak_post_type->name, $kobak_saved_types, true ) ); ?>>
								<?php echo esc_html( $kobak_post_type->label ); ?>
							</label>
							<?php
						endforeach;
						?>
						<?php if ( empty( $kobak_saved_types ) ) : ?>
							<p class="description"><?php esc_html_e( 'Nothing is selected, so automatic sharing has nothing to share even while it is switched on above.', 'kobak-social' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>

				<?php
				// Minden regisztrált platform maga rajzolja ki a saját szakaszát —
				// egy negyedik hálózat felvételéhez itt semmit nem kell módosítani.
				foreach ( Kobak_Social_Registry::get_platforms() as $platform ) {
					$platform->settings_fields( $s );
				}
				?>

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
		$failures = kobak_social_pending_failures();
		if ( $failures ) :
			?>
		<hr>
		<h2><?php esc_html_e( 'Failed shares', 'kobak-social' ); ?></h2>
		<p><?php esc_html_e( 'These posts did not go out. A timeout or an outage is retried three times before it lands here; a missing or invalid credential lands here at once, because waiting cannot fix it. The log below says which happened. Retrying only touches the platforms that are still missing.', 'kobak-social' ); ?></p>
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
