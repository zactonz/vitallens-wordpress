<?php
/**
 * The settings screen.
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the settings form.
 */
class VitalLens_Settings_Page {

	/**
	 * Render, handling a submit first.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( VitalLens_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'vitallens' ) );
		}

		$notice = self::maybe_save();
		$s      = VitalLens_Settings::all();

		?>
		<div class="wrap vl-wrap">
			<h1 class="vl-title"><?php esc_html_e( 'VitalLens settings', 'vitallens' ); ?></h1>

			<?php if ( $notice ) : ?>
				<div class="vl-notice vl-notice--good" role="status"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<?php $action = self::action_notice(); ?>
			<?php if ( $action ) : ?>
				<div class="vl-notice vl-notice--<?php echo esc_attr( $action['tone'] ); ?>" role="status"><p><?php echo esc_html( $action['text'] ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'vitallens_save_settings', 'vitallens_nonce' ); ?>

				<!-- API key -->
				<section class="vl-card">
					<h2 class="vl-card__title"><?php esc_html_e( 'Google PageSpeed API key', 'vitallens' ); ?></h2>
					<p class="vl-card__sub"><?php esc_html_e( 'Stored in your database, sent only to Google.', 'vitallens' ); ?></p>

					<div class="vl-field-row">
						<input
							type="text"
							id="vl-api-key"
							name="api_key"
							class="vl-input vl-input--mono"
							autocomplete="off"
							spellcheck="false"
							value="<?php echo esc_attr( self::mask( $s['api_key'] ) ); ?>"
							aria-describedby="vl-key-result"
						>
						<button type="button" class="vl-btn vl-btn--ghost" id="vl-test-key"><?php esc_html_e( 'Test key', 'vitallens' ); ?></button>
					</div>
					<p class="vl-result" id="vl-key-result" role="status" aria-live="polite"></p>

					<p class="vl-help">
						<?php
						printf(
							/* translators: %s: link to the Google Cloud console. */
							esc_html__( 'Free from the %s — enable the PageSpeed Insights API. 25,000 calls a day, no billing account needed.', 'vitallens' ),
							'<a href="https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Google Cloud console', 'vitallens' ) . '</a>'
						);
						?>
					</p>
				</section>

				<!-- schedule -->
				<section class="vl-card">
					<h2 class="vl-card__title"><?php esc_html_e( 'Schedule', 'vitallens' ); ?></h2>

					<div class="vl-grid-3">
						<label class="vl-label">
							<span><?php esc_html_e( 'Check every', 'vitallens' ); ?></span>
							<select name="interval_hours" class="vl-input">
								<?php foreach ( VitalLens_Settings::intervals() as $hours => $label ) : ?>
									<option value="<?php echo esc_attr( $hours ); ?>" <?php selected( (int) $s['interval_hours'], (int) $hours ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>

						<label class="vl-label">
							<span><?php esc_html_e( 'Pages per run', 'vitallens' ); ?></span>
							<select name="batch_size" class="vl-input">
								<?php foreach ( VitalLens_Settings::batch_sizes() as $size => $label ) : ?>
									<option value="<?php echo esc_attr( $size ); ?>" <?php selected( (int) $s['batch_size'], (int) $size ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>

						<label class="vl-label">
							<span><?php esc_html_e( 'Keep history for', 'vitallens' ); ?></span>
							<select name="retention_days" class="vl-input">
								<?php foreach ( VitalLens_Settings::retentions() as $days => $label ) : ?>
									<option value="<?php echo esc_attr( $days ); ?>" <?php selected( (int) $s['retention_days'], (int) $days ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>

					<fieldset class="vl-label vl-devices">
						<legend><?php esc_html_e( 'Device', 'vitallens' ); ?></legend>
						<label class="vl-check">
							<input type="checkbox" name="devices[]" value="mobile" <?php checked( in_array( 'mobile', (array) $s['devices'], true ) ); ?>>
							<?php esc_html_e( 'Mobile', 'vitallens' ); ?>
						</label>
						<label class="vl-check">
							<input type="checkbox" name="devices[]" value="desktop" <?php checked( in_array( 'desktop', (array) $s['devices'], true ) ); ?>>
							<?php esc_html_e( 'Desktop', 'vitallens' ); ?>
						</label>
					</fieldset>

					<p class="vl-help">
						<?php esc_html_e( 'A sweep runs every 15 minutes and takes the pages that are due, so “pages per run” is what sets how fast a backlog clears — 5 per run works through 20 pages in an hour. Each device counts as its own check.', 'vitallens' ); ?>
					</p>

					<?php self::render_cost( $s ); ?>

					<fieldset class="vl-fieldset">
						<legend class="vl-legend"><?php esc_html_e( 'Run the schedule with', 'vitallens' ); ?></legend>
						<div class="vl-grid-2">
							<label class="vl-radio-card <?php echo 'wp' === $s['cron_source'] ? 'is-on' : ''; ?>">
								<input type="radio" name="cron_source" value="wp" <?php checked( 'wp', $s['cron_source'] ); ?>>
								<span>
									<strong><?php esc_html_e( 'WordPress (WP-Cron)', 'vitallens' ); ?></strong>
									<span class="vl-radio-card__sub"><?php esc_html_e( 'No setup. Fires on page visits, so it drifts or stalls on a quiet site.', 'vitallens' ); ?></span>
								</span>
							</label>
							<label class="vl-radio-card <?php echo 'server' === $s['cron_source'] ? 'is-on' : ''; ?>">
								<input type="radio" name="cron_source" value="server" <?php checked( 'server', $s['cron_source'] ); ?>>
								<span>
									<strong><?php esc_html_e( 'My host’s cron', 'vitallens' ); ?></strong>
									<span class="vl-radio-card__sub"><?php esc_html_e( 'A real clock. Recommended — the command is below, ready to paste.', 'vitallens' ); ?></span>
								</span>
							</label>
						</div>
					</fieldset>

					<?php self::render_schedule_status(); ?>
					<?php self::render_cron_help(); ?>
				</section>

				<!-- alerts -->
				<section class="vl-card">
					<h2 class="vl-card__title"><?php esc_html_e( 'Email alerts', 'vitallens' ); ?></h2>

					<fieldset class="vl-fieldset">
						<legend class="vl-legend"><?php esc_html_e( 'Tell me when', 'vitallens' ); ?></legend>
						<?php
						foreach ( self::alert_options() as $key => $copy ) :
							$on = ! empty( $s[ $key ] );
							?>
							<label class="vl-alert <?php echo $on ? 'is-on' : ''; ?>">
								<input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( $on ); ?>>
								<span>
									<strong><?php echo esc_html( $copy['title'] ); ?></strong>
									<span class="vl-alert__sub"><?php echo esc_html( $copy['detail'] ); ?></span>
								</span>
							</label>
						<?php endforeach; ?>
					</fieldset>

					<div class="vl-grid-2">
						<label class="vl-label">
							<span><?php esc_html_e( 'Send to', 'vitallens' ); ?></span>
							<input type="email" name="alert_email" class="vl-input" value="<?php echo esc_attr( $s['alert_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
						</label>
					</div>
					<p class="vl-help">
						<?php
						printf(
							/* translators: %s: the wp_mail() function name. */
							esc_html__( 'Sent with %s, so it follows whatever SMTP plugin you already use.', 'vitallens' ),
							'<code>wp_mail()</code>'
						);
						?>
					</p>
				</section>

				<!-- data -->
				<section class="vl-card">
					<h2 class="vl-card__title"><?php esc_html_e( 'Your data', 'vitallens' ); ?></h2>
					<p class="vl-card__sub"><?php esc_html_e( 'Where the measurements live, and what happens to them.', 'vitallens' ); ?></p>

					<div class="vl-notice vl-notice--info">
						<p>
							<?php esc_html_e( 'Updating VitalLens never touches your history or your settings — upload a new version straight over the old one and everything stays put.', 'vitallens' ); ?>
						</p>
					</div>

					<label class="vl-alert vl-alert--danger <?php echo ! empty( $s['delete_data'] ) ? 'is-on' : ''; ?>">
						<input type="checkbox" name="delete_data" value="1" <?php checked( ! empty( $s['delete_data'] ) ); ?>>
						<span>
							<strong><?php esc_html_e( 'Delete everything if this plugin is deleted', 'vitallens' ); ?></strong>
							<span class="vl-alert__sub">
								<?php esc_html_e( 'Off by default. Leave it off and deleting the plugin keeps your tracked pages and every stored check, so re-installing picks up exactly where you left off. Tick it only when you want the tables dropped for good — there is no undo, and no backup is taken first.', 'vitallens' ); ?>
							</span>
						</span>
					</label>

					<p class="vl-help">
						<?php esc_html_e( 'Want a copy before you change anything? Dashboard → Export → Download CSV gives you every tracked page, and each page’s own report exports its full history.', 'vitallens' ); ?>
					</p>

					<?php self::render_backups(); ?>
				</section>

				<p>
					<button type="submit" class="vl-btn vl-btn--primary"><?php esc_html_e( 'Save changes', 'vitallens' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Snapshots, and the two things you can do with them.
	 *
	 * Listed on the settings screen rather than hidden behind a tool, because
	 * the moment a snapshot matters is the moment somebody is about to do
	 * something they cannot undo.
	 *
	 * @return void
	 */
	private static function render_backups() {
		$backups = VitalLens_Backup::all();
		?>
		<div class="vl-backups">
			<div class="vl-backups__head">
				<div>
					<strong><?php esc_html_e( 'Backups', 'vitallens' ); ?></strong>
					<span>
						<?php esc_html_e( 'Written automatically every time VitalLens is deactivated — the step before every delete — and kept in your uploads folder, which deleting the plugin does not touch. The last five are kept. Your API key is never included.', 'vitallens' ); ?>
					</span>
				</div>
				<a class="vl-btn vl-btn--ghost" href="<?php echo esc_url( VitalLens_Backup::action_url( 'create' ) ); ?>">
					<?php esc_html_e( 'Back up now', 'vitallens' ); ?>
				</a>
			</div>

			<?php if ( ! $backups ) : ?>
				<p class="vl-empty"><?php esc_html_e( 'No backups yet. One is written the next time the plugin is deactivated, or take one now.', 'vitallens' ); ?></p>
			<?php else : ?>
				<ul class="vl-backups__list">
					<?php foreach ( $backups as $backup ) : ?>
						<li>
							<span>
								<strong><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $backup['time'] ) ); ?></strong>
								<span class="vl-muted"><?php echo esc_html( size_format( $backup['size'] ) ); ?></span>
							</span>
							<a class="vl-btn vl-btn--ghost" href="<?php echo esc_url( VitalLens_Backup::action_url( 'restore', $backup['file'] ) ); ?>"
								data-vl-confirm="<?php esc_attr_e( 'Restore this backup? Pages and checks already here are kept — anything missing is added back.', 'vitallens' ); ?>">
								<?php esc_html_e( 'Restore', 'vitallens' ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * What came back from a backup or restore.
	 *
	 * @return array{text: string, tone: string}|null
	 */
	private static function action_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only, the action itself was nonced.
		$msg = isset( $_GET['vl_msg'] ) ? sanitize_key( wp_unslash( $_GET['vl_msg'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded first, then sanitised: sanitize_text_field() is the outermost call, so nothing reaches $detail unsanitised.
		$detail = isset( $_GET['vl_detail'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['vl_detail'] ) ) ) : '';
		$tracked = isset( $_GET['vl_t'] ) ? (int) $_GET['vl_t'] : 0;
		$checks = isset( $_GET['vl_c'] ) ? (int) $_GET['vl_c'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		switch ( $msg ) {
			case 'backed_up':
				return array(
					'text' => __( 'Backup written to your uploads folder.', 'vitallens' ),
					'tone' => 'good',
				);
			case 'restored':
				return array(
					'text' => sprintf(
						/* translators: 1: pages restored, 2: checks restored. */
						__( 'Restored %1$d tracked page and %2$d stored checks. Anything already here was left alone.', 'vitallens' ),
						$tracked,
						$checks
					),
					'tone' => 'good',
				);
			case 'backup_failed':
			case 'restore_failed':
				return array(
					'text' => '' !== $detail ? $detail : __( 'That did not work.', 'vitallens' ),
					'tone' => 'bad',
				);
		}

		return null;
	}

	/**
	 * Handle a submit.
	 *
	 * @return string Notice text, empty when nothing was saved.
	 */
	private static function maybe_save() {
		if ( ! isset( $_POST['vitallens_nonce'] ) ) {
			return '';
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['vitallens_nonce'] ) ), 'vitallens_save_settings' ) ) {
			return '';
		}
		if ( ! current_user_can( VitalLens_Admin::CAPABILITY ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified immediately above.
		$raw = wp_unslash( $_POST );
		VitalLens_Settings::save( is_array( $raw ) ? $raw : array() );

		// A saved interval only means anything if the sweep is on the calendar.
		VitalLens_Plugin::schedule();

		return __( 'Settings saved.', 'vitallens' );
	}

	/**
	 * What the current selection will cost, before it is saved.
	 *
	 * @param array $s Settings.
	 * @return void
	 */
	private static function render_cost( $s ) {
		$devices  = max( 1, count( (array) $s['devices'] ) );
		$pages    = VitalLens_Store::count_active();
		$per_run  = $pages * $devices;
		$interval = max( 1, (int) $s['interval_hours'] );
		$per_month = (int) round( $per_run * ( 24 / $interval ) * 30 );
		$share     = ( $per_run * ( 24 / $interval ) ) / VitalLens_Settings::DAILY_ALLOWANCE * 100;

		?>
		<div class="vl-cost">
			<div><span class="vl-cost__k"><?php esc_html_e( 'Pages tracked', 'vitallens' ); ?></span><span class="vl-cost__v"><?php echo esc_html( number_format_i18n( $pages ) ); ?></span></div>
			<div><span class="vl-cost__k"><?php esc_html_e( 'Calls per run', 'vitallens' ); ?></span><span class="vl-cost__v"><?php echo esc_html( number_format_i18n( $per_run ) ); ?></span></div>
			<div><span class="vl-cost__k"><?php esc_html_e( 'Per month', 'vitallens' ); ?></span><span class="vl-cost__v"><?php echo esc_html( number_format_i18n( $per_month ) ); ?></span></div>
			<div><span class="vl-cost__k"><?php esc_html_e( 'Of your allowance', 'vitallens' ); ?></span><span class="vl-cost__v vl-cost__v--good"><?php echo esc_html( number_format_i18n( $share, 1 ) . '%' ); ?></span></div>
		</div>
		<?php
	}

	/**
	 * When the schedule last ran, and whether that is a problem.
	 *
	 * @return void
	 */
	private static function render_schedule_status() {
		$since   = VitalLens_Plugin::seconds_since_sweep();
		$overdue = VitalLens_Plugin::is_overdue();

		if ( null === $since ) {
			$text = __( 'No check has run yet.', 'vitallens' );
		} else {
			$text = sprintf(
				/* translators: %s: human-readable duration, e.g. "18 hours". */
				__( 'Last run %s ago.', 'vitallens' ),
				human_time_diff( time() - $since, time() )
			);
		}
		?>
		<div class="vl-run-row">
			<span class="vl-run-row__text <?php echo $overdue ? 'is-overdue' : ''; ?>">
				<?php echo esc_html( $text ); ?>
				<?php if ( $overdue ) : ?>
					<strong><?php esc_html_e( 'That is overdue — the schedule may have stopped.', 'vitallens' ); ?></strong>
				<?php endif; ?>
			</span>
			<button type="button" class="vl-btn vl-btn--ghost" id="vl-run-now"><?php esc_html_e( 'Run now', 'vitallens' ); ?></button>
		</div>
		<p class="vl-result" id="vl-run-result" role="status" aria-live="polite"></p>
		<?php
	}

	/**
	 * The host-cron instructions, with this install's real paths.
	 *
	 * @return void
	 */
	private static function render_cron_help() {
		?>
		<div class="vl-warn">
			<strong><?php esc_html_e( 'WP-Cron only fires when someone visits your site.', 'vitallens' ); ?></strong>
			<?php esc_html_e( 'On a quiet site, checks drift or stop. VitalLens flags an overdue run rather than letting the gap pass unnoticed — but a real cron entry is the fix, not the warning.', 'vitallens' ); ?>
		</div>

		<div class="vl-cron">
			<h3 class="vl-cron__title"><?php esc_html_e( 'Set it up in cPanel → Cron Jobs', 'vitallens' ); ?></h3>
			<p class="vl-cron__sub"><?php esc_html_e( 'Paths read from this install. Two steps, and WordPress stops relying on visitor traffic to keep time.', 'vitallens' ); ?></p>

			<div class="vl-step">
				<div class="vl-step__label">1. <?php esc_html_e( 'Stop WordPress scheduling it itself — add to wp-config.php', 'vitallens' ); ?></div>
				<div class="vl-copy-row">
					<code class="vl-code">define( 'DISABLE_WP_CRON', true );</code>
					<button type="button" class="vl-btn vl-btn--copy" data-vl-copy="define( 'DISABLE_WP_CRON', true );"><?php esc_html_e( 'Copy', 'vitallens' ); ?></button>
				</div>
				<p class="vl-step__note"><?php esc_html_e( 'Above the line that reads /* That’s all, stop editing! */', 'vitallens' ); ?></p>
			</div>

			<div class="vl-step">
				<div class="vl-step__label">2. <?php esc_html_e( 'Add the cron job — every 15 minutes', 'vitallens' ); ?></div>
				<div class="vl-copy-row">
					<code class="vl-code"><?php echo esc_html( VitalLens_Admin::cron_command() ); ?></code>
					<button type="button" class="vl-btn vl-btn--copy" data-vl-copy="<?php echo esc_attr( VitalLens_Admin::cron_command() ); ?>"><?php esc_html_e( 'Copy', 'vitallens' ); ?></button>
				</div>
				<p class="vl-step__note"><?php esc_html_e( 'The PHP path is the binary actually running this site — on cPanel that is usually not the one `which php` reports. Check it against any cron entry you already have.', 'vitallens' ); ?></p>
			</div>

			<details class="vl-details">
				<summary><?php esc_html_e( 'Other hosts, and the WP-CLI version', 'vitallens' ); ?></summary>
				<div class="vl-copy-row">
					<code class="vl-code"><?php echo esc_html( VitalLens_Admin::cron_command_cli() ); ?></code>
					<button type="button" class="vl-btn vl-btn--copy" data-vl-copy="<?php echo esc_attr( VitalLens_Admin::cron_command_cli() ); ?>"><?php esc_html_e( 'Copy', 'vitallens' ); ?></button>
				</div>
				<p class="vl-step__note"><?php esc_html_e( 'Cleaner where WP-CLI exists: it runs only what is due instead of loading a web request. Plesk, RunCloud and Cloudways take the same line in their own schedulers; with no shell at all, an external cron service hitting wp-cron.php every 15 minutes works too.', 'vitallens' ); ?></p>
			</details>
		</div>
		<?php
	}

	/**
	 * Alert copy, kept out of the markup.
	 *
	 * @return array<string, array{title: string, detail: string}>
	 */
	private static function alert_options() {
		return array(
			'alert_failing'   => array(
				'title'  => __( 'A page starts failing for real visitors', 'vitallens' ),
				'detail' => __( 'A Core Web Vital crosses out of the “good” range. The one alert worth interrupting someone for.', 'vitallens' ),
			),
			'alert_headroom'  => array(
				'title'  => __( 'A page is running out of headroom', 'vitallens' ),
				'detail' => __( 'Still passing, but one move spent a third or more of the margin it had left — so you hear about it before visitors do.', 'vitallens' ),
			),
			'alert_recovered' => array(
				'title'  => __( 'A page starts passing again', 'vitallens' ),
				'detail' => __( 'Confirms a fix landed. Off by default: good news should be opt-in.', 'vitallens' ),
			),
			'alert_stalled'   => array(
				'title'  => __( 'Checks stop running', 'vitallens' ),
				'detail' => __( 'No successful run in twice the scheduled interval. Silence and health look identical otherwise.', 'vitallens' ),
			),
		);
	}

	/**
	 * Show that a key exists without printing it back into the page.
	 *
	 * @param string $key Stored key.
	 * @return string
	 */
	private static function mask( $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return '';
		}
		return str_repeat( '•', max( 0, strlen( $key ) - 4 ) ) . substr( $key, -4 );
	}
}
