<?php
/**
 * The dashboard: what the schedule found, including when it found nothing.
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the overview screen.
 */
class VitalLens_Dashboard_Page {

	/**
	 * Render.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( VitalLens_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'vitallens' ) );
		}

		$rows  = VitalLens_Store::latest_for_all();
		$state = self::assess( $rows );
		?>
		<div class="wrap vl-wrap">
			<div class="vl-head">
				<div>
					<div class="vl-eyebrow"><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></div>
					<h1 class="vl-title"><?php esc_html_e( 'Core Web Vitals', 'vitallens' ); ?></h1>
				</div>
				<div class="vl-head__actions">
					<a class="vl-btn vl-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=' . VitalLens_Admin::SLUG . '-tracked' ) ); ?>">
						<?php esc_html_e( 'Tracked pages', 'vitallens' ); ?>
					</a>
					<?php
					/*
					 * A menu rather than two more buttons: four across the top
					 * of a header is a toolbar, and the one that matters here
					 * is "Run checks now".
					 */
					?>
					<details class="vl-menu">
						<summary class="vl-btn vl-btn--ghost"><?php esc_html_e( 'Export', 'vitallens' ); ?></summary>
						<div class="vl-menu__panel">
							<a href="<?php echo esc_url( VitalLens_Report_Page::url() ); ?>">
								<strong><?php esc_html_e( 'View report', 'vitallens' ); ?></strong>
								<span><?php esc_html_e( 'Every tracked page on one screen', 'vitallens' ); ?></span>
							</a>
							<a href="<?php echo esc_url( VitalLens_Report_Page::url( true ) ); ?>">
								<strong><?php esc_html_e( 'Save as PDF', 'vitallens' ); ?></strong>
								<span><?php esc_html_e( 'Opens the report and the print dialog', 'vitallens' ); ?></span>
							</a>
							<a href="<?php echo esc_url( VitalLens_Export::url( 'all' ) ); ?>">
								<strong><?php esc_html_e( 'Download CSV', 'vitallens' ); ?></strong>
								<span><?php esc_html_e( 'Raw numbers for a spreadsheet', 'vitallens' ); ?></span>
							</a>
						</div>
					</details>
					<button type="button" class="vl-btn vl-btn--primary" id="vl-run-now"><?php esc_html_e( 'Run checks now', 'vitallens' ); ?></button>
				</div>
			</div>
			<p class="vl-result" id="vl-run-result" role="status" aria-live="polite"></p>

			<?php self::render_banner( $state ); ?>

			<?php if ( $state['tracked'] > 0 ) : ?>
				<?php self::render_counts( $state ); ?>
				<?php self::render_attention( $state['attention'] ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Work out what the whole portfolio is doing.
	 *
	 * @param array $rows Rows from VitalLens_Store::latest_for_all().
	 * @return array
	 */
	public static function assess( $rows ) {
		$state = array(
			'tracked'   => count( $rows ),
			'passing'   => 0,
			'failing'   => 0,
			'unmeasured' => 0,
			'lab_only'  => 0,
			'pending'   => 0,
			'tightest'  => null,
			'attention' => array(),
		);

		foreach ( $rows as $row ) {
			if ( empty( $row->check_id ) ) {
				++$state['pending'];
				continue;
			}

			$lab_only = ( VitalLens_PSI_Client::FIELD_NONE === $row->field_source );
			if ( $lab_only ) {
				++$state['lab_only'];
			}

			$prefix  = $lab_only ? 'l_' : 'f_';
			$metrics = $lab_only ? array( 'LCP', 'CLS' ) : VitalLens_Thresholds::core_metrics();

			$bad   = array();
			$seen  = 0;
			$worst = null;

			foreach ( $metrics as $metric ) {
				$column = $prefix . strtolower( $metric );
				if ( ! isset( $row->$column ) || null === $row->$column ) {
					continue;
				}
				++$seen;
				$value   = (float) $row->$column;
				$verdict = VitalLens_Thresholds::verdict( $metric, $value );

				if ( VitalLens_Thresholds::GOOD !== $verdict ) {
					$bad[] = $metric;
					continue;
				}

				$limit = VitalLens_Thresholds::good_limit( $metric );
				if ( $limit ) {
					$headroom = ( $limit - $value ) / $limit;
					if ( null === $worst || $headroom < $worst['headroom'] ) {
						$worst = array(
							'metric'   => $metric,
							'value'    => $value,
							'limit'    => $limit,
							'headroom' => $headroom,
						);
					}
				}
			}

			if ( 0 === $seen ) {
				++$state['unmeasured'];
				continue;
			}

			if ( $bad ) {
				++$state['failing'];
				$state['attention'][] = array(
					'row'     => $row,
					'reason'  => sprintf(
						/* translators: %s: comma-separated metric names. */
						__( '%s outside the good range', 'vitallens' ),
						implode( ', ', $bad )
					),
					'rank'    => 100 + count( $bad ),
					'lab'     => $lab_only,
				);
				continue;
			}

			++$state['passing'];

			if ( $worst && $worst['headroom'] <= 0.33 ) {
				if ( null === $state['tightest'] || $worst['headroom'] < $state['tightest']['headroom'] ) {
					$state['tightest']          = $worst;
					$state['tightest']['row']   = $row;
				}
				$state['attention'][] = array(
					'row'    => $row,
					'reason' => sprintf(
						/* translators: 1: metric, 2: percentage of headroom left. */
						__( '%1$s has %2$d%% of its headroom left', 'vitallens' ),
						$worst['metric'],
						(int) round( $worst['headroom'] * 100 )
					),
					'rank'   => 50 + ( 1 - $worst['headroom'] ) * 10,
					'lab'    => $lab_only,
				);
			}
		}

		usort(
			$state['attention'],
			static function ( $a, $b ) {
				return $b['rank'] <=> $a['rank'];
			}
		);
		$state['attention'] = array_slice( $state['attention'], 0, 6 );

		return $state;
	}

	/**
	 * The verdict. Five states, in the order they matter.
	 *
	 * The "stopped" and "nothing tracked" states exist because an empty screen
	 * and a broken schedule look identical otherwise, and a user cannot tell
	 * which one they are looking at without reading the database.
	 *
	 * Returned rather than echoed because two things say it -- this screen and
	 * the dashboard widget -- and a situation described twice is a situation
	 * described differently the first time either one changes.
	 *
	 * @param array $state Assessment.
	 * @return array{tone: string, pill: string, title: string, detail: string, meta: string[]}
	 */
	public static function verdict( $state ) {
		$since   = VitalLens_Plugin::seconds_since_sweep();
		$overdue = VitalLens_Plugin::is_overdue();

		if ( ! VitalLens_Settings::has_key() ) {
			$tone    = 'info';
			$pill    = __( 'Not started', 'vitallens' );
			$title   = __( 'Add your PageSpeed API key to begin', 'vitallens' );
			$detail  = __( 'VitalLens needs a free Google API key before it can measure anything.', 'vitallens' );
		} elseif ( 0 === $state['tracked'] ) {
			$tone   = 'info';
			$pill   = __( 'Not started', 'vitallens' );
			$title  = __( 'No pages are being tracked yet', 'vitallens' );
			$detail = __( 'Pick the pages worth watching and the first run starts within the hour.', 'vitallens' );
		} elseif ( $overdue ) {
			$tone   = 'bad';
			$pill   = __( 'Stopped', 'vitallens' );
			$title  = __( 'Checks have stopped running', 'vitallens' );
			$detail = __( 'Nothing has completed in twice the scheduled interval, so these numbers are stale. WP-Cron only fires when someone visits the site — a real cron entry fixes it for good.', 'vitallens' );
		} elseif ( $state['failing'] > 0 ) {
			$tone   = 'bad';
			$pill   = __( 'Failing', 'vitallens' );
			$title  = sprintf(
				/* translators: 1: failing count, 2: total tracked. */
				_n( '%1$d of %2$d pages is failing for real visitors', '%1$d of %2$d pages are failing for real visitors', $state['failing'], 'vitallens' ),
				$state['failing'],
				$state['tracked']
			);
			$detail = __( 'Those pages are outside Google’s “good” range on at least one Core Web Vital.', 'vitallens' );
		} elseif ( $state['tightest'] ) {
			$t      = $state['tightest'];
			$tone   = 'warn';
			$pill   = __( 'Watch', 'vitallens' );
			$title  = sprintf(
				/* translators: %d: number of passing pages. */
				_n( '%d page passing — one is nearly out of room', 'All %d pages passing — one is nearly out of room', $state['passing'], 'vitallens' ),
				$state['passing']
			);
			$detail = sprintf(
				/* translators: 1: page title, 2: metric, 3: value, 4: limit, 5: percentage. */
				__( '%1$s has the least headroom: %2$s %3$s against a %4$s limit, %5$d%% left.', 'vitallens' ),
				self::title_for( $t['row'] ),
				$t['metric'],
				VitalLens_Thresholds::format( $t['metric'], $t['value'] ),
				VitalLens_Thresholds::format( $t['metric'], $t['limit'] ),
				(int) round( $t['headroom'] * 100 )
			);
		} elseif ( $state['passing'] > 0 ) {
			$tone   = 'good';
			$pill   = __( 'All clear', 'vitallens' );
			$title  = sprintf(
				/* translators: %d: number of pages. */
				_n( '%d tracked page is passing', 'All %d tracked pages are passing', $state['passing'], 'vitallens' ),
				$state['passing']
			);
			$detail = __( 'Nothing has crossed a threshold, and nothing is close. You will be emailed if that changes.', 'vitallens' );
		} else {
			$tone   = 'info';
			$pill   = __( 'Waiting', 'vitallens' );
			$title  = __( 'Waiting for the first results', 'vitallens' );
			$detail = __( 'Monitoring is scheduled but no page has been measured yet.', 'vitallens' );
		}

		$meta = array();
		if ( null !== $since ) {
			$meta[] = sprintf(
				/* translators: %s: human-readable duration. */
				__( 'Last run %s ago', 'vitallens' ),
				human_time_diff( time() - $since, time() )
			);
		}
		if ( $state['lab_only'] > 0 ) {
			$meta[] = sprintf(
				/* translators: %d: number of pages. */
				_n( '%d page has no real-user data', '%d pages have no real-user data', $state['lab_only'], 'vitallens' ),
				$state['lab_only']
			);
		}
		if ( $state['pending'] > 0 ) {
			$meta[] = sprintf(
				/* translators: %d: number of pages. */
				_n( '%d page not checked yet', '%d pages not checked yet', $state['pending'], 'vitallens' ),
				$state['pending']
			);
		}

		return array(
			'tone'   => $tone,
			'pill'   => $pill,
			'title'  => $title,
			'detail' => $detail,
			'meta'   => $meta,
		);
	}

	/**
	 * The verdict, as the banner at the top of the screen.
	 *
	 * @param array $state Assessment.
	 * @return void
	 */
	private static function render_banner( $state ) {
		$verdict = self::verdict( $state );
		$tone    = $verdict['tone'];
		$pill    = $verdict['pill'];
		$title   = $verdict['title'];
		$detail  = $verdict['detail'];
		$meta    = $verdict['meta'];
		?>
		<div class="vl-banner vl-banner--<?php echo esc_attr( $tone ); ?>">
			<div class="vl-banner__body">
				<span class="vl-pill vl-pill--<?php echo esc_attr( $tone ); ?>"><?php echo esc_html( $pill ); ?></span>
				<h2 class="vl-banner__title"><?php echo esc_html( $title ); ?></h2>
				<p class="vl-banner__detail"><?php echo esc_html( $detail ); ?></p>
				<?php if ( $meta ) : ?>
					<div class="vl-banner__meta">
						<?php foreach ( $meta as $item ) : ?>
							<span><?php echo esc_html( $item ); ?></span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( $state['tracked'] > 0 && ( $state['passing'] + $state['failing'] ) > 0 ) : ?>
				<?php self::render_ring( $state ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The passing ring.
	 *
	 * @param array $state Assessment.
	 * @return void
	 */
	private static function render_ring( $state ) {
		$measured = $state['passing'] + $state['failing'];
		$ratio    = $measured > 0 ? $state['passing'] / $measured : 0;
		$r        = 55;
		$circ     = 2 * M_PI * $r;
		$offset   = $circ * ( 1 - $ratio );
		?>
		<svg class="vl-ring" width="132" height="132" viewBox="0 0 132 132" role="img"
			aria-label="<?php echo esc_attr( sprintf(
				/* translators: 1: passing count, 2: measured count. */
				__( '%1$d of %2$d measured pages are passing', 'vitallens' ),
				$state['passing'],
				$measured
			) ); ?>">
			<circle cx="66" cy="66" r="<?php echo esc_attr( $r ); ?>" stroke="#f1f1f6" stroke-width="13" fill="none"/>
			<circle cx="66" cy="66" r="<?php echo esc_attr( $r ); ?>" stroke="#10b981" stroke-width="13" fill="none" stroke-linecap="round"
				stroke-dasharray="<?php echo esc_attr( round( $circ, 1 ) ); ?>"
				stroke-dashoffset="<?php echo esc_attr( round( $offset, 1 ) ); ?>"
				transform="rotate(-90 66 66)"/>
			<text x="66" y="62" text-anchor="middle" font-size="30" font-weight="600" fill="#14141c"><?php echo esc_html( $state['passing'] ); ?></text>
			<text x="66" y="82" text-anchor="middle" font-size="12" font-weight="500" fill="#8d90a1">
				<?php
				printf(
					/* translators: %d: number of measured pages. */
					esc_html__( 'of %d passing', 'vitallens' ),
					(int) $measured
				);
				?>
			</text>
		</svg>
		<?php
	}

	/**
	 * The counts strip.
	 *
	 * @param array $state Assessment.
	 * @return void
	 */
	private static function render_counts( $state ) {
		$cells = array(
			array( __( 'Tracked', 'vitallens' ), $state['tracked'], '' ),
			array( __( 'Passing', 'vitallens' ), $state['passing'], $state['passing'] > 0 ? 'good' : '' ),
			array( __( 'Failing', 'vitallens' ), $state['failing'], $state['failing'] > 0 ? 'bad' : '' ),
			array( __( 'Lab only', 'vitallens' ), $state['lab_only'], '' ),
		);
		?>
		<div class="vl-cost">
			<?php foreach ( $cells as $cell ) : ?>
				<div>
					<span class="vl-cost__k"><?php echo esc_html( $cell[0] ); ?></span>
					<span class="vl-cost__v <?php echo $cell[2] ? 'vl-cost__v--' . esc_attr( $cell[2] ) : ''; ?>">
						<?php echo esc_html( number_format_i18n( $cell[1] ) ); ?>
					</span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * The pages worth looking at.
	 *
	 * @param array $attention Ranked rows.
	 * @return void
	 */
	private static function render_attention( $attention ) {
		?>
		<section class="vl-card vl-card--flush">
			<div class="vl-card__head">
				<h2 class="vl-card__title"><?php esc_html_e( 'Needs attention', 'vitallens' ); ?></h2>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . VitalLens_Admin::SLUG . '-tracked' ) ); ?>">
					<?php esc_html_e( 'All tracked pages', 'vitallens' ); ?> &rarr;
				</a>
			</div>

			<?php if ( empty( $attention ) ) : ?>
				<p class="vl-empty vl-empty--pad"><?php esc_html_e( 'Nothing is failing and nothing is close to a threshold.', 'vitallens' ); ?></p>
			<?php else : ?>
				<?php foreach ( $attention as $item ) : ?>
					<?php $row = $item['row']; ?>
					<a class="vl-attn" href="<?php echo esc_url( VitalLens_View::detail_url( $row->tracked_id ) ); ?>">
						<span class="vl-attn__main">
							<span class="vl-attn__title"><?php echo esc_html( self::title_for( $row ) ); ?></span>
							<span class="vl-attn__why"><?php echo esc_html( $item['reason'] ); ?></span>
						</span>
						<span class="vl-attn__chips">
							<?php
							$prefix  = $item['lab'] ? 'l_' : 'f_';
							$metrics = $item['lab'] ? array( 'LCP', 'CLS' ) : VitalLens_Thresholds::core_metrics();
							foreach ( $metrics as $metric ) {
								$column = $prefix . strtolower( $metric );
								if ( isset( $row->$column ) && null !== $row->$column ) {
									echo wp_kses_post( VitalLens_View::chip( $metric, (float) $row->$column ) );
								}
							}
							echo wp_kses_post( VitalLens_View::source_badge( $row->field_source ) );
							?>
						</span>
					</a>
				<?php endforeach; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * A readable name for a tracked row.
	 *
	 * @param object $row Row carrying post_id and url.
	 * @return string
	 */
	public static function title_for( $row ) {
		if ( ! empty( $row->post_id ) ) {
			$title = get_the_title( (int) $row->post_id );
			if ( $title ) {
				return $title;
			}
		}
		$path = wp_parse_url( $row->url, PHP_URL_PATH );
		return $path ? $path : $row->url;
	}
}
