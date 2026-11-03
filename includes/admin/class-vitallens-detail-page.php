<?php
/**
 * One page's report: field, lab, history and what the lab run found.
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

/**
 * The per-page detail screen.
 */
class VitalLens_Detail_Page {

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( VitalLens_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'vitallens' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen selector.
		$tracked_id = isset( $_GET['tracked'] ) ? (int) $_GET['tracked'] : 0;
		$tracked    = $tracked_id ? VitalLens_Store::get_tracked( $tracked_id ) : null;

		if ( ! $tracked ) {
			echo '<div class="wrap vl-wrap"><h1 class="vl-title">' . esc_html__( 'Page not found', 'vitallens' ) . '</h1>';
			echo '<p class="vl-empty">' . esc_html__( 'That page is not being tracked.', 'vitallens' ) . '</p></div>';
			return;
		}

		$latest   = VitalLens_Store::latest_check( $tracked_id, 0 );
		$previous = VitalLens_Store::latest_check( $tracked_id, 1 );
		$title    = VitalLens_Dashboard_Page::title_for( $tracked );
		?>
		<div class="wrap vl-wrap">
			<a class="vl-back" href="<?php echo esc_url( admin_url( 'admin.php?page=' . VitalLens_Admin::SLUG . '-tracked' ) ); ?>">
				&larr; <?php esc_html_e( 'All tracked pages', 'vitallens' ); ?>
			</a>

			<div class="vl-head">
				<div>
					<h1 class="vl-title"><?php echo esc_html( $title ); ?></h1>
					<div class="vl-tags">
						<span class="vl-tag"><?php echo esc_html( wp_parse_url( $tracked->url, PHP_URL_PATH ) ); ?></span>
						<span class="vl-tag"><?php echo esc_html( ucfirst( $tracked->strategy ) ); ?></span>
						<span class="vl-tag">
							<?php
							printf(
								/* translators: %s: interval, e.g. "24 hours". */
								esc_html__( 'Every %s', 'vitallens' ),
								esc_html( (int) VitalLens_Settings::get( 'interval_hours', 24 ) . ' ' . __( 'hours', 'vitallens' ) )
							);
							?>
						</span>
						<?php if ( $latest ) : ?>
							<span class="vl-tag"><?php echo wp_kses_post( VitalLens_View::source_badge( $latest->field_source ) ); ?></span>
						<?php endif; ?>
					</div>
				</div>
				<div class="vl-head__actions">
					<button type="button" class="vl-btn vl-btn--primary" id="vl-recheck" data-vl-tracked="<?php echo esc_attr( (int) $tracked_id ); ?>">
						<?php esc_html_e( 'Check now', 'vitallens' ); ?>
					</button>
					<a class="vl-btn vl-btn--ghost" href="<?php echo esc_url( VitalLens_Export::url( 'page', $tracked_id ) ); ?>">
						<?php esc_html_e( 'Export history', 'vitallens' ); ?>
					</a>
					<a class="vl-btn vl-btn--ghost" href="<?php echo esc_url( $tracked->url ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'View page', 'vitallens' ); ?>
					</a>
				</div>
			</div>
			<p class="vl-result" id="vl-recheck-result" role="status" aria-live="polite"></p>

			<?php if ( ! $latest ) : ?>
				<p class="vl-empty vl-empty--pad"><?php esc_html_e( 'No check has completed for this page yet. Use Check now above, or wait for the next scheduled run.', 'vitallens' ); ?></p>
				</div>
				<?php
				return;
			endif;
			?>

			<?php self::render_verdict( $latest, $previous, $title ); ?>

			<div class="vl-split">
				<?php self::render_field( $latest ); ?>
				<?php self::render_lab( $latest ); ?>
			</div>

			<?php self::render_history( $tracked_id, $latest ); ?>
			<?php self::render_findings( (int) $latest->id ); ?>
		</div>
		<?php
	}

	/**
	 * The one-line verdict, said once at the top.
	 *
	 * @param object      $latest   Newest check.
	 * @param object|null $previous The one before.
	 * @param string      $title    Page title.
	 * @return void
	 */
	private static function render_verdict( $latest, $previous, $title ) {
		$lab_only = ( VitalLens_PSI_Client::FIELD_NONE === $latest->field_source );
		$prefix   = $lab_only ? 'l_' : 'f_';
		$metrics  = $lab_only ? array( 'LCP', 'CLS' ) : VitalLens_Thresholds::core_metrics();

		$failing  = array();
		$tightest = null;

		foreach ( $metrics as $metric ) {
			$column = $prefix . strtolower( $metric );
			if ( ! isset( $latest->$column ) || null === $latest->$column ) {
				continue;
			}
			$value   = (float) $latest->$column;
			$verdict = VitalLens_Thresholds::verdict( $metric, $value );
			if ( VitalLens_Thresholds::GOOD !== $verdict ) {
				$failing[] = $metric;
				continue;
			}
			$limit = VitalLens_Thresholds::good_limit( $metric );
			if ( $limit ) {
				$headroom = ( $limit - $value ) / $limit;
				if ( null === $tightest || $headroom < $tightest['headroom'] ) {
					$tightest = array(
						'metric'   => $metric,
						'headroom' => $headroom,
						'value'    => $value,
						'limit'    => $limit,
					);
				}
			}
		}

		if ( $failing ) {
			$tone   = 'bad';
			$pill   = __( 'Failing', 'vitallens' );
			$head   = sprintf(
				/* translators: %s: comma-separated metric names. */
				__( '%s outside the “good” range', 'vitallens' ),
				implode( ', ', $failing )
			);
			$detail = $lab_only
				? __( 'Measured by the lab test — Google has no real-user data for this page, so this is one simulated load rather than what visitors felt.', 'vitallens' )
				: __( 'This is what real visitors measured over the last 28 days.', 'vitallens' );
		} elseif ( $tightest && $tightest['headroom'] <= 0.33 ) {
			$tone   = 'warn';
			$pill   = __( 'Watch', 'vitallens' );
			$head   = sprintf(
				/* translators: 1: metric, 2: percentage left. */
				__( 'Passing, but %1$s has only %2$d%% of its margin left', 'vitallens' ),
				$tightest['metric'],
				(int) round( $tightest['headroom'] * 100 )
			);
			$detail = sprintf(
				/* translators: 1: value, 2: limit. */
				__( '%1$s against a %2$s limit. You will be emailed before it crosses, not after.', 'vitallens' ),
				VitalLens_Thresholds::format( $tightest['metric'], $tightest['value'] ),
				VitalLens_Thresholds::format( $tightest['metric'], $tightest['limit'] )
			);
		} else {
			$tone   = 'good';
			$pill   = __( 'Passing', 'vitallens' );
			$head   = sprintf(
				/* translators: %s: page title. */
				__( '%s is passing on every Core Web Vital', 'vitallens' ),
				$title
			);
			$detail = __( 'Nothing is close to a threshold.', 'vitallens' );
		}
		?>
		<div class="vl-banner vl-banner--<?php echo esc_attr( $tone ); ?>">
			<div class="vl-banner__body">
				<span class="vl-pill vl-pill--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $pill ); ?></span>
				<h2 class="vl-banner__title"><?php echo esc_html( $head ); ?></h2>
				<p class="vl-banner__detail"><?php echo esc_html( $detail ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * What real visitors measured.
	 *
	 * @param object $latest Newest check.
	 * @return void
	 */
	private static function render_field( $latest ) {
		$none = ( VitalLens_PSI_Client::FIELD_NONE === $latest->field_source );
		?>
		<section class="vl-card vl-card--flush vl-split__main">
			<div class="vl-card__head">
				<h2 class="vl-card__title">
					<?php echo wp_kses_post( VitalLens_View::dot( VitalLens_Thresholds::GOOD, true, 11 ) ); ?>
					<?php esc_html_e( 'What real visitors measured', 'vitallens' ); ?>
				</h2>
				<span class="vl-muted"><?php esc_html_e( 'CrUX · 28-day p75', 'vitallens' ); ?></span>
			</div>

			<?php if ( $none ) : ?>
				<p class="vl-empty vl-empty--pad"><?php echo esc_html( VitalLens_View::source_help( $latest->field_source ) ); ?></p>
			<?php else : ?>
				<?php if ( VitalLens_PSI_Client::FIELD_ORIGIN === $latest->field_source ) : ?>
					<p class="vl-note"><?php echo esc_html( VitalLens_View::source_help( $latest->field_source ) ); ?></p>
				<?php endif; ?>
				<?php foreach ( VitalLens_Thresholds::core_metrics() as $metric ) : ?>
					<?php
					$column = 'f_' . strtolower( $metric );
					if ( ! isset( $latest->$column ) || null === $latest->$column ) {
						continue;
					}
					$value   = (float) $latest->$column;
					$verdict = VitalLens_Thresholds::verdict( $metric, $value );
					$colors  = VitalLens_Thresholds::verdict_colors( $verdict );
					$limit   = VitalLens_Thresholds::good_limit( $metric );
					?>
					<div class="vl-metric">
						<div class="vl-metric__top">
							<span class="vl-metric__name"><?php echo esc_html( VitalLens_Alerts::metric_name( $metric ) ); ?></span>
							<span class="vl-metric__value" style="color:<?php echo esc_attr( $colors['text'] ); ?>">
								<?php echo esc_html( VitalLens_Thresholds::format( $metric, $value ) ); ?>
							</span>
						</div>
						<?php echo wp_kses_post( VitalLens_View::track( $metric, $value ) ); ?>
						<div class="vl-metric__note">
							<?php
							if ( VitalLens_Thresholds::GOOD === $verdict && $limit ) {
								printf(
									/* translators: 1: percentage, 2: limit value. */
									esc_html__( '%1$d%% of the margin to %2$s left', 'vitallens' ),
									(int) round( ( ( $limit - $value ) / $limit ) * 100 ),
									esc_html( VitalLens_Thresholds::format( $metric, $limit ) )
								);
							} else {
								printf(
									/* translators: %s: limit value. */
									esc_html__( 'Over the %s limit', 'vitallens' ),
									esc_html( VitalLens_Thresholds::format( $metric, $limit ) )
								);
							}
							?>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * The lab run.
	 *
	 * @param object $latest Newest check.
	 * @return void
	 */
	private static function render_lab( $latest ) {
		$rows = array( 'TBT' => 'l_tbt', 'SI' => 'l_si', 'TTFB' => 'l_ttfb', 'FCP' => 'l_fcp' );
		?>
		<section class="vl-card vl-split__side">
			<div class="vl-card__head vl-card__head--plain">
				<h2 class="vl-card__title">
					<?php echo wp_kses_post( VitalLens_View::dot( VitalLens_Thresholds::NEEDS, false, 11 ) ); ?>
					<?php esc_html_e( 'Lab test', 'vitallens' ); ?>
				</h2>
			</div>
			<div class="vl-lab">
				<?php echo wp_kses( VitalLens_View::score_ring( isset( $latest->score ) ? $latest->score : null ), VitalLens_View::svg_allowed() ); ?>
				<p class="vl-lab__note"><?php esc_html_e( 'One simulated load, useful for finding causes — never a measure of what visitors felt.', 'vitallens' ); ?></p>
			</div>
			<?php foreach ( $rows as $metric => $column ) : ?>
				<?php
				if ( ! isset( $latest->$column ) || null === $latest->$column ) {
					continue;
				}
				$value  = (float) $latest->$column;
				$colors = VitalLens_Thresholds::verdict_colors( VitalLens_Thresholds::verdict( $metric, $value ) );
				?>
				<div class="vl-lab__row">
					<span><?php echo esc_html( VitalLens_Alerts::metric_name( $metric ) ); ?></span>
					<strong style="color:<?php echo esc_attr( $colors['text'] ); ?>"><?php echo esc_html( VitalLens_Thresholds::format( $metric, $value ) ); ?></strong>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * The history chart, for whichever metric is most worth watching.
	 *
	 * @param int    $tracked_id Tracked row id.
	 * @param object $latest     Newest check.
	 * @return void
	 */
	private static function render_history( $tracked_id, $latest ) {
		$lab_only = ( VitalLens_PSI_Client::FIELD_NONE === $latest->field_source );
		$prefix   = $lab_only ? 'l_' : 'f_';
		$metrics  = $lab_only ? array( 'LCP', 'CLS' ) : VitalLens_Thresholds::core_metrics();

		// Chart the metric closest to failing: that is the one whose trend
		// actually decides something.
		$choice = null;
		foreach ( $metrics as $metric ) {
			$column = $prefix . strtolower( $metric );
			if ( ! isset( $latest->$column ) || null === $latest->$column ) {
				continue;
			}
			$limit = VitalLens_Thresholds::good_limit( $metric );
			if ( ! $limit ) {
				continue;
			}
			$ratio = (float) $latest->$column / $limit;
			if ( null === $choice || $ratio > $choice['ratio'] ) {
				$choice = array(
					'metric' => $metric,
					'column' => $column,
					'ratio'  => $ratio,
				);
			}
		}
		if ( ! $choice ) {
			return;
		}

		$rows = VitalLens_Store::history( $tracked_id, $choice['column'], 90 );
		?>
		<section class="vl-card">
			<div class="vl-card__head vl-card__head--plain">
				<h2 class="vl-card__title">
					<?php
					printf(
						/* translators: %s: metric name. */
						esc_html__( '%s, 90 days', 'vitallens' ),
						esc_html( VitalLens_Alerts::metric_name( $choice['metric'] ) )
					);
					?>
				</h2>
				<span class="vl-legend-line">
					<span class="vl-legend-line__dash"></span><?php esc_html_e( 'Fails above here', 'vitallens' ); ?>
				</span>
			</div>
			<p class="vl-card__sub"><?php esc_html_e( 'Built from your own stored checks, so it reaches further back the longer the plugin runs.', 'vitallens' ); ?></p>
			<?php echo wp_kses( VitalLens_View::chart( $rows, $choice['metric'] ), VitalLens_View::svg_allowed() ); ?>
			<?php if ( count( $rows ) > 1 ) : ?>
				<div class="vl-chart-foot">
					<span><?php echo esc_html( self::when( $rows[0]->checked_at ) ); ?></span>
					<span>
						<?php
						$last = end( $rows );
						printf(
							/* translators: 1: "now", 2: value. */
							esc_html__( 'Now · %s', 'vitallens' ),
							esc_html( VitalLens_Thresholds::format( $choice['metric'], $last->value ) )
						);
						?>
					</span>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * What the lab run found.
	 *
	 * @param int $check_id Check id.
	 * @return void
	 */
	private static function render_findings( $check_id ) {
		self::render_diagnoses( $check_id );

		$findings = VitalLens_Store::findings_for( $check_id );
		if ( ! $findings ) {
			return;
		}
		?>
		<section class="vl-card vl-card--flush">
			<div class="vl-card__head">
				<h2 class="vl-card__title"><?php esc_html_e( 'Opportunities', 'vitallens' ); ?></h2>
				<span class="vl-muted"><?php esc_html_e( 'Estimated savings on a lab run', 'vitallens' ); ?></span>
			</div>
			<?php foreach ( $findings as $finding ) : ?>
				<div class="vl-finding">
					<div class="vl-finding__top">
						<strong><?php echo esc_html( $finding->title ); ?></strong>
						<?php if ( $finding->saving_ms > 0 ) : ?>
							<span class="vl-chip" style="color:#92620a;background:#fffbeb;">
								<?php
								printf(
									/* translators: %s: milliseconds. */
									esc_html__( '%s savings', 'vitallens' ),
									esc_html( number_format_i18n( (int) $finding->saving_ms ) . 'ms' )
								);
								?>
							</span>
						<?php endif; ?>
					</div>
					<?php if ( $finding->evidence ) : ?>
						<code class="vl-finding__evidence"><?php echo esc_html( $finding->evidence ); ?></code>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * What is actually causing each metric to be what it is.
	 *
	 * The point of the whole screen. "CLS outside the good range" names the
	 * metric; this names the element that moved, what moved it, and how much
	 * of the score it accounts for -- which is the difference between a
	 * number and something a person can go and fix.
	 *
	 * @param int $check_id Check id.
	 * @return void
	 */
	private static function render_diagnoses( $check_id ) {
		$rows = VitalLens_Store::diagnoses_for( $check_id );
		if ( ! $rows ) {
			return;
		}

		// Grouped by metric, in the order the metrics themselves are read.
		$groups = array();
		foreach ( $rows as $row ) {
			$metric = $row->metric ? $row->metric : '—';
			if ( ! isset( $groups[ $metric ] ) ) {
				$groups[ $metric ] = array();
			}
			$groups[ $metric ][] = $row;
		}

		$order = array( 'CLS', 'LCP', 'INP', 'TBT', 'TTFB' );
		uksort(
			$groups,
			static function ( $a, $b ) use ( $order ) {
				$ai = array_search( $a, $order, true );
				$bi = array_search( $b, $order, true );
				return ( false === $ai ? 99 : $ai ) <=> ( false === $bi ? 99 : $bi );
			}
		);
		?>
		<section class="vl-card vl-card--flush">
			<div class="vl-card__head">
				<h2 class="vl-card__title"><?php esc_html_e( 'What is causing this', 'vitallens' ); ?></h2>
				<span class="vl-muted"><?php esc_html_e( 'From the lab run', 'vitallens' ); ?></span>
			</div>

			<?php foreach ( $groups as $metric => $items ) : ?>
				<?php foreach ( $items as $row ) : ?>
					<div class="vl-cause">
						<div class="vl-cause__head">
							<span class="vl-cause__metric vl-cause__metric--<?php echo esc_attr( strtolower( $metric ) ); ?>"><?php echo esc_html( $metric ); ?></span>
							<span class="vl-cause__what">
								<?php echo esc_html( $row->cause ? $row->cause : $row->title ); ?>
							</span>
							<?php if ( null !== $row->amount && '' !== $row->unit ) : ?>
								<span class="vl-cause__amount"><?php echo esc_html( self::amount( $row->amount, $row->unit ) ); ?></span>
							<?php endif; ?>
						</div>

						<?php if ( $row->label ) : ?>
							<div class="vl-cause__label"><?php echo esc_html( $row->label ); ?></div>
						<?php endif; ?>

						<?php
						/*
						 * Two selectors with no labels read as a repeat. They
						 * are not: the first is the thing that moved, the
						 * second is what pushed it.
						 */
						?>
						<?php if ( $row->selector ) : ?>
							<div class="vl-cause__key">
								<?php
								echo esc_html(
									'CLS' === $row->metric
										? __( 'Element that moved', 'vitallens' )
										: __( 'Element', 'vitallens' )
								);
								?>
							</div>
							<code class="vl-finding__evidence vl-cause__selector"><?php echo esc_html( $row->selector ); ?></code>
						<?php endif; ?>

						<?php if ( $row->evidence && $row->evidence !== $row->selector ) : ?>
							<div class="vl-cause__key">
								<?php
								echo esc_html(
									preg_match( '#^https?://#i', $row->evidence )
										? __( 'Request involved', 'vitallens' )
										: __( 'Blamed on', 'vitallens' )
								);
								?>
							</div>
							<code class="vl-finding__evidence"><?php echo esc_html( $row->evidence ); ?></code>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			<?php endforeach; ?>

			<p class="vl-note vl-note--foot">
				<?php esc_html_e( 'Selectors come from one simulated load, so a page that renders differently for logged-in visitors may shift somewhere else for them.', 'vitallens' ); ?>
			</p>
		</section>
		<?php
	}

	/**
	 * A diagnosis amount in the unit it was measured in.
	 *
	 * @param float  $amount Raw value.
	 * @param string $unit   'cls', 'ms' or 'bytes'.
	 * @return string
	 */
	private static function amount( $amount, $unit ) {
		$amount = (float) $amount;

		if ( 'cls' === $unit ) {
			return number_format_i18n( $amount, 3 ) . ' ' . __( 'CLS', 'vitallens' );
		}
		if ( 'bytes' === $unit ) {
			return size_format( $amount );
		}
		if ( $amount >= 1000 ) {
			return number_format_i18n( $amount / 1000, 2 ) . 's';
		}
		return number_format_i18n( round( $amount ) ) . 'ms';
	}

	/**
	 * A stored UTC timestamp as a readable date.
	 *
	 * @param string $mysql_utc Datetime string.
	 * @return string
	 */
	private static function when( $mysql_utc ) {
		$ts = strtotime( $mysql_utc . ' UTC' );
		return $ts ? date_i18n( get_option( 'date_format' ), $ts ) : '';
	}
}
