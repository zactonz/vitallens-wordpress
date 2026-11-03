<?php
/**
 * The printable report -- which is also the PDF.
 *
 * Rendered as an ordinary admin screen with a print stylesheet that drops
 * the WordPress chrome, rather than redrawn by a bundled PDF engine. The
 * browser's own "Save as PDF" then produces a file that looks like the
 * product, with no vendored library, no GD or mbstring requirement, and
 * nothing to keep patched.
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

/**
 * A one-page summary of every tracked page.
 */
class VitalLens_Report_Page {

	/**
	 * A link to the report.
	 *
	 * @param bool $print Whether to open the print dialog on arrival.
	 * @return string
	 */
	public static function url( $print = false ) {
		$args = array( 'page' => VitalLens_Admin::SLUG . '-report' );
		if ( $print ) {
			$args['print'] = 1;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( VitalLens_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'vitallens' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view toggle.
		$auto = isset( $_GET['print'] );

		$rows    = VitalLens_Store::latest_for_all();
		$state   = VitalLens_Dashboard_Page::assess( $rows );
		$verdict = VitalLens_Dashboard_Page::verdict( $state );
		$since   = VitalLens_Plugin::seconds_since_sweep();
		?>
		<div class="wrap vl-wrap vl-report">

			<div class="vl-noprint vl-report__bar">
				<a class="vl-btn vl-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=' . VitalLens_Admin::SLUG ) ); ?>">
					&larr; <?php esc_html_e( 'Back', 'vitallens' ); ?>
				</a>
				<div class="vl-head__actions">
					<a class="vl-btn vl-btn--ghost" href="<?php echo esc_url( VitalLens_Export::url( 'all' ) ); ?>">
						<?php esc_html_e( 'Download CSV', 'vitallens' ); ?>
					</a>
					<button type="button" class="vl-btn vl-btn--primary" id="vl-print">
						<?php esc_html_e( 'Save as PDF', 'vitallens' ); ?>
					</button>
				</div>
			</div>

			<?php if ( $auto ) : ?>
				<span id="vl-autoprint" hidden></span>
			<?php endif; ?>

			<?php
			$host  = wp_parse_url( home_url(), PHP_URL_HOST );
			$stamp = date_i18n( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ) );
			?>

			<header class="vl-report__head">
				<div class="vl-report__brand">
					<?php echo wp_kses( VitalLens_View::mark( 34, '#4f46e5' ), VitalLens_View::svg_allowed() ); ?>
					<div>
						<div class="vl-report__product">
							VitalLens
							<span><?php esc_html_e( 'Core Web Vitals monitoring', 'vitallens' ); ?></span>
						</div>
						<h1 class="vl-title"><?php esc_html_e( 'Core Web Vitals report', 'vitallens' ); ?></h1>
					</div>
				</div>
				<div class="vl-report__stamp">
					<strong><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong>
					<span><?php echo esc_html( $host ); ?></span>
					<span>
						<?php
						printf(
							/* translators: %s: date and time. */
							esc_html__( 'Generated %s', 'vitallens' ),
							esc_html( $stamp )
						);
						?>
					</span>
					<?php if ( null !== $since ) : ?>
						<span>
							<?php
							printf(
								/* translators: %s: human-readable duration. */
								esc_html__( 'Last check %s ago', 'vitallens' ),
								esc_html( human_time_diff( time() - $since, time() ) )
							);
							?>
						</span>
					<?php endif; ?>
				</div>
			</header>

			<?php
			/*
			 * A running strip for the printed copy. Chrome repeats a fixed
			 * element on every sheet, so page two onwards still says what this
			 * is and whose site it is about -- which matters once a report has
			 * been forwarded twice and detached from the email it arrived in.
			 */
			?>
			<div class="vl-report__running" aria-hidden="true">
				<span>VitalLens &middot; <?php echo esc_html( $host ); ?></span>
				<span><?php echo esc_html( $stamp ); ?></span>
			</div>

			<section class="vl-report__verdict vl-report__verdict--<?php echo esc_attr( $verdict['tone'] ); ?>">
				<span class="vl-pill vl-pill--<?php echo esc_attr( $verdict['tone'] ); ?>"><?php echo esc_html( $verdict['pill'] ); ?></span>
				<h2 class="vl-banner__title"><?php echo esc_html( $verdict['title'] ); ?></h2>
				<p class="vl-banner__detail"><?php echo esc_html( $verdict['detail'] ); ?></p>
			</section>

			<div class="vl-cost">
				<?php
				$cells = array(
					array( __( 'Tracked', 'vitallens' ), $state['tracked'], '' ),
					array( __( 'Passing', 'vitallens' ), $state['passing'], $state['passing'] > 0 ? 'good' : '' ),
					array( __( 'Failing', 'vitallens' ), $state['failing'], $state['failing'] > 0 ? 'bad' : '' ),
					array( __( 'Lab only', 'vitallens' ), $state['lab_only'], '' ),
					array( __( 'Not checked', 'vitallens' ), $state['pending'], '' ),
				);
				foreach ( $cells as $cell ) :
					?>
					<div>
						<span class="vl-cost__k"><?php echo esc_html( $cell[0] ); ?></span>
						<span class="vl-cost__v <?php echo $cell[2] ? 'vl-cost__v--' . esc_attr( $cell[2] ) : ''; ?>"><?php echo esc_html( number_format_i18n( $cell[1] ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="vl-card vl-card--flush">
				<div class="vl-scroll">
				<table class="vl-table vl-table--report">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Page', 'vitallens' ); ?></th>
							<th scope="col" class="vl-hide-sm"><?php esc_html_e( 'Source', 'vitallens' ); ?></th>
							<th scope="col" class="vl-num"><?php esc_html_e( 'Score', 'vitallens' ); ?></th>
							<th scope="col" class="vl-num"><?php esc_html_e( 'LCP', 'vitallens' ); ?></th>
							<th scope="col" class="vl-num"><?php esc_html_e( 'INP', 'vitallens' ); ?></th>
							<th scope="col" class="vl-num"><?php esc_html_e( 'CLS', 'vitallens' ); ?></th>
							<th scope="col" class="vl-hide-sm"><?php esc_html_e( 'Checked', 'vitallens' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="7" class="vl-empty vl-empty--pad"><?php esc_html_e( 'No pages are being tracked yet.', 'vitallens' ); ?></td></tr>
					<?php endif; ?>
					<?php
					foreach ( $rows as $row ) :
						$lab_only = ( VitalLens_PSI_Client::FIELD_NONE === $row->field_source );
						$prefix   = $lab_only ? 'l_' : 'f_';
						?>
						<tr>
							<td>
								<span class="vl-table__title"><?php echo esc_html( VitalLens_Dashboard_Page::title_for( $row ) ); ?></span>
								<span class="vl-table__sub"><?php echo esc_html( wp_parse_url( $row->url, PHP_URL_PATH ) ); ?></span>
							</td>
							<td class="vl-hide-sm">
								<?php
								if ( empty( $row->check_id ) ) {
									echo '<span class="vl-muted">' . esc_html__( 'Not checked yet', 'vitallens' ) . '</span>';
								} else {
									echo wp_kses_post( VitalLens_View::source_badge( $row->field_source ) );
								}
								?>
							</td>
							<td class="vl-num">
								<?php echo esc_html( isset( $row->score ) && null !== $row->score ? (int) $row->score : '—' ); ?>
							</td>
							<?php
							foreach ( array( 'LCP', 'INP', 'CLS' ) as $metric ) :
								$column = $prefix . strtolower( $metric );
								$value  = isset( $row->$column ) ? $row->$column : null;
								$colors = VitalLens_Thresholds::verdict_colors( VitalLens_Thresholds::verdict( $metric, $value ) );
								?>
								<td class="vl-num" style="color:<?php echo esc_attr( null === $value ? '#83869a' : $colors['text'] ); ?>">
									<?php echo esc_html( VitalLens_Thresholds::format( $metric, $value ) ); ?>
								</td>
							<?php endforeach; ?>
							<td class="vl-muted vl-hide-sm">
								<?php
								if ( ! empty( $row->checked_at ) ) {
									echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->checked_at . ' UTC' ) ) );
								} else {
									echo '—';
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			</div>

			<footer class="vl-report__credits">
				<div class="vl-report__credits-main">
					<div class="vl-report__credits-brand">
						<?php echo wp_kses( VitalLens_View::mark( 22, '#83869a' ), VitalLens_View::svg_allowed() ); ?>
						<span>
							<?php
							printf(
								/* translators: %s: plugin version. */
								esc_html__( 'Generated by VitalLens %s for WordPress', 'vitallens' ),
								esc_html( VITALLENS_VERSION )
							);
							?>
						</span>
					</div>
					<p>
						<?php esc_html_e( 'Measured with the Google PageSpeed Insights API. “Real visitors” figures are the Chrome UX Report’s 28-day 75th percentile of actual visits; “lab only” figures come from a single simulated Lighthouse load and are not what anyone experienced.', 'vitallens' ); ?>
					</p>
					<p>
						<?php esc_html_e( 'Passing thresholds — LCP 2.50s · INP 200ms · CLS 0.10', 'vitallens' ); ?>
					</p>
				</div>
				<div class="vl-report__credits-side">
					<a href="https://vitallens.zactonz.com/">vitallens.zactonz.com</a>
					<span><?php esc_html_e( 'Zactonz Technologies', 'vitallens' ); ?></span>
				</div>
			</footer>
		</div>
		<?php
	}
}
