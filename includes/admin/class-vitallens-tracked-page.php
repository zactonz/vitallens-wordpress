<?php
/**
 * Choosing what gets checked.
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

/**
 * The tracked-pages picker.
 */
class VitalLens_Tracked_Page {

	const PER_PAGE = 20;

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

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$type   = isset( $_GET['vl_type'] ) ? sanitize_key( wp_unslash( $_GET['vl_type'] ) ) : 'any';
		$search = isset( $_GET['vl_s'] ) ? sanitize_text_field( wp_unslash( $_GET['vl_s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$query   = self::query( $type, $search, $paged );
		$rows    = VitalLens_Store::latest_for_all();
		$tracked = self::tracked_map( $rows );
		$devices = (array) VitalLens_Settings::get( 'devices', array( 'mobile' ) );
		$never   = self::never_checked( $rows );
		?>
		<div class="wrap vl-wrap">
			<h1 class="vl-title"><?php esc_html_e( 'Tracked pages', 'vitallens' ); ?></h1>
			<p class="vl-lede"><?php esc_html_e( 'Pick what gets checked. Each page costs one API call per device, per run.', 'vitallens' ); ?></p>

			<?php if ( $notice ) : ?>
				<div class="vl-notice vl-notice--good" role="status"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<?php self::render_backlog( $never ); ?>
			<?php self::render_cost( $query, $tracked, $devices ); ?>

			<form method="get" class="vl-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( VitalLens_Admin::SLUG . '-tracked' ); ?>">
				<label class="vl-filters__item">
					<span><?php esc_html_e( 'Type', 'vitallens' ); ?></span>
					<select name="vl_type" class="vl-input">
						<option value="any" <?php selected( 'any', $type ); ?>><?php esc_html_e( 'All types', 'vitallens' ); ?></option>
						<?php foreach ( self::post_types() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $type ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="vl-filters__item vl-filters__item--grow">
					<span><?php esc_html_e( 'Search', 'vitallens' ); ?></span>
					<input type="search" name="vl_s" class="vl-input" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Title', 'vitallens' ); ?>">
				</label>
				<button type="submit" class="vl-btn vl-btn--ghost"><?php esc_html_e( 'Filter', 'vitallens' ); ?></button>
			</form>

			<form method="post">
				<?php wp_nonce_field( 'vitallens_save_tracked', 'vitallens_nonce' ); ?>
				<input type="hidden" name="vl_submitted" value="1">
				<?php
				/*
				 * Which rows this screen actually offered. Without it, saving a
				 * filtered or paged view would read every hidden page as
				 * "unchecked" and untrack the lot.
				 */
				?>
				<input type="hidden" name="vl_onscreen" value="<?php echo esc_attr( implode( ',', wp_list_pluck( $query->posts, 'ID' ) ) ); ?>">

				<div class="vl-card vl-card--flush">
					<?php
					/*
					 * The card clips its corners with overflow:hidden, so a
					 * table wider than the screen was not scrolled -- it was
					 * cut off, and the last two columns simply could not be
					 * reached on a phone.
					 */
					?>
					<div class="vl-scroll">
					<table class="vl-table">
						<thead>
							<tr>
								<th scope="col" class="vl-table__check"><input type="checkbox" id="vl-check-all" aria-label="<?php esc_attr_e( 'Select every page on this screen', 'vitallens' ); ?>"></th>
								<th scope="col"><?php esc_html_e( 'Title', 'vitallens' ); ?></th>
								<th scope="col" class="vl-hide-sm"><?php esc_html_e( 'Type', 'vitallens' ); ?></th>
								<th scope="col" class="vl-hide-sm"><?php esc_html_e( 'Data source', 'vitallens' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Score', 'vitallens' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Checked', 'vitallens' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php if ( ! $query->have_posts() ) : ?>
							<tr><td colspan="6" class="vl-empty vl-empty--pad"><?php esc_html_e( 'Nothing matches that filter.', 'vitallens' ); ?></td></tr>
						<?php endif; ?>

						<?php
						while ( $query->have_posts() ) :
							$query->the_post();
							$post_id = get_the_ID();
							$row     = isset( $tracked[ $post_id ] ) ? $tracked[ $post_id ] : null;
							$is_on   = (bool) $row;
							?>
							<tr class="<?php echo $is_on ? 'is-tracked' : ''; ?>">
								<td class="vl-table__check">
									<input type="checkbox" name="vl_track[]" value="<?php echo esc_attr( $post_id ); ?>"
										<?php checked( $is_on ); ?>
										aria-label="<?php echo esc_attr( sprintf(
											/* translators: %s: post title. */
											__( 'Track %s', 'vitallens' ),
											get_the_title()
										) ); ?>">
								</td>
								<td>
									<?php if ( $row ) : ?>
										<a class="vl-table__title" href="<?php echo esc_url( VitalLens_View::detail_url( $row->tracked_id ) ); ?>"><?php echo esc_html( get_the_title() ); ?></a>
									<?php else : ?>
										<span class="vl-table__title"><?php echo esc_html( get_the_title() ); ?></span>
									<?php endif; ?>
									<span class="vl-table__sub"><?php echo esc_html( wp_parse_url( get_permalink(), PHP_URL_PATH ) ); ?></span>
								</td>
								<td class="vl-hide-sm"><?php echo esc_html( self::type_label( get_post_type() ) ); ?></td>
								<td class="vl-hide-sm">
									<?php
									if ( $row && $row->field_source ) {
										echo wp_kses_post( VitalLens_View::source_badge( $row->field_source ) );
									} else {
										echo '<span class="vl-muted">—</span>';
									}
									?>
								</td>
								<td>
									<?php if ( $row && null !== $row->score ) : ?>
										<span class="vl-score"><?php echo esc_html( (int) $row->score ); ?></span>
									<?php else : ?>
										<span class="vl-muted"><?php echo $is_on ? esc_html__( 'Pending', 'vitallens' ) : '—'; ?></span>
									<?php endif; ?>
								</td>
								<td class="vl-muted">
									<?php
									$ago = ( $row && $row->last_checked_at )
										? human_time_diff( strtotime( $row->last_checked_at . ' UTC' ), time() )
										: '';

									if ( '' === $ago ) {
										echo '—';
									} elseif ( $row && empty( $row->check_id ) ) {
										/*
										 * Attempted and nothing stored, which only happens when
										 * the PSI call failed. Printing a plain timestamp here
										 * said "checked 5 minutes ago" next to a blank score --
										 * a run that never produced a number, reported as one
										 * that did.
										 */
										printf(
											'<span class="vl-badge vl-badge--warn">%s</span>',
											esc_html( sprintf(
												/* translators: %s: human-readable duration. */
												__( 'Failed %s ago', 'vitallens' ),
												$ago
											) )
										);
									} else {
										printf(
											/* translators: %s: human-readable duration. */
											esc_html__( '%s ago', 'vitallens' ),
											esc_html( $ago )
										);
									}
									?>
								</td>
							</tr>
						<?php endwhile; ?>
						<?php wp_reset_postdata(); ?>
						</tbody>
					</table>
					</div>
				</div>

				<div class="vl-table-foot">
					<span class="vl-muted">
						<?php
						printf(
							/* translators: 1: posts on this screen, 2: total found, 3: number tracked. */
							esc_html__( 'Showing %1$d of %2$d · %3$d tracked', 'vitallens' ),
							(int) $query->post_count,
							(int) $query->found_posts,
							count( $tracked )
						);
						?>
					</span>
					<button type="submit" class="vl-btn vl-btn--primary"><?php esc_html_e( 'Save tracked pages', 'vitallens' ); ?></button>
				</div>

				<?php self::render_pagination( $query, $paged ); ?>
			</form>

			<p class="vl-help vl-help--wide">
				<strong><?php esc_html_e( 'Why some rows say “lab only”.', 'vitallens' ); ?></strong>
				<?php esc_html_e( 'Google publishes real-user measurements only for URLs with enough traffic. Those pages are still checked, but what comes back is a simulated load rather than what your visitors experienced — so VitalLens labels which is which instead of mixing them into one number.', 'vitallens' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Apply a submitted selection.
	 *
	 * Only the ids on the screen that was submitted are touched, so saving a
	 * filtered view cannot silently untrack everything the filter hid.
	 *
	 * @return string Notice text.
	 */
	private static function maybe_save() {
		if ( ! isset( $_POST['vl_submitted'], $_POST['vitallens_nonce'] ) ) {
			return '';
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['vitallens_nonce'] ) ), 'vitallens_save_tracked' ) ) {
			return '';
		}
		if ( ! current_user_can( VitalLens_Admin::CAPABILITY ) ) {
			return '';
		}

		$wanted = isset( $_POST['vl_track'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['vl_track'] ) ) : array();
		$onscreen = isset( $_POST['vl_onscreen'] ) ? array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $_POST['vl_onscreen'] ) ) ) ) : array();
		$onscreen = array_filter( $onscreen );

		$devices = (array) VitalLens_Settings::get( 'devices', array( 'mobile' ) );
		$added   = 0;
		$removed = 0;

		foreach ( $wanted as $post_id ) {
			foreach ( $devices as $device ) {
				if ( VitalLens_Store::track( $post_id, $device ) ) {
					++$added;
				}
			}
		}

		foreach ( array_diff( $onscreen, $wanted ) as $post_id ) {
			foreach ( array( 'mobile', 'desktop' ) as $device ) {
				VitalLens_Store::untrack( $post_id, $device );
			}
			++$removed;
		}

		VitalLens_Plugin::schedule();

		return __( 'Tracked pages updated.', 'vitallens' );
	}

	/**
	 * Build the post list.
	 *
	 * @param string $type   Post type or 'any'.
	 * @param string $search Search term.
	 * @param int    $paged  Page number.
	 * @return WP_Query
	 */
	private static function query( $type, $search, $paged ) {
		$args = array(
			'post_type'      => ( 'any' === $type ) ? array_keys( self::post_types() ) : $type,
			'post_status'    => array( 'publish' ),
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => false,
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		return new WP_Query( $args );
	}

	/**
	 * Post types worth offering.
	 *
	 * @return array<string, string>
	 */
	private static function post_types() {
		$types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);
		$out = array();
		foreach ( $types as $slug => $object ) {
			if ( 'attachment' === $slug ) {
				continue;
			}
			$out[ $slug ] = $object->labels->singular_name;
		}
		return $out;
	}

	/**
	 * A post type's label.
	 *
	 * @param string $slug Post type.
	 * @return string
	 */
	private static function type_label( $slug ) {
		$types = self::post_types();
		return isset( $types[ $slug ] ) ? $types[ $slug ] : $slug;
	}

	/**
	 * Tracked rows keyed by post id, with their newest check.
	 *
	 * @param array $rows Rows from VitalLens_Store::latest_for_all().
	 * @return array<int, object>
	 */
	private static function tracked_map( $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $out[ (int) $row->post_id ] ) ) {
				$out[ (int) $row->post_id ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Pages that are being watched but have no reading yet.
	 *
	 * @param array $rows Rows from VitalLens_Store::latest_for_all().
	 * @return int
	 */
	private static function never_checked( $rows ) {
		$count = 0;
		foreach ( $rows as $row ) {
			if ( empty( $row->check_id ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Say out loud that a backlog is a backlog.
	 *
	 * A sweep takes a few pages at a time on purpose -- PSI answers in ten to
	 * thirty seconds, so forty pages in one request is a timeout. The cost of
	 * that choice is that someone who tracks thirty pages and looks straight
	 * away sees five with data and twenty-five blank, which is indis-
	 * tinguishable from a plugin that only tracks five. So it is stated.
	 *
	 * @param int $never Pages with no check yet.
	 * @return void
	 */
	private static function render_backlog( $never ) {
		if ( $never < 1 ) {
			return;
		}
		$batch = (int) VitalLens_Settings::get( 'batch_size', 5 );
		?>
		<div class="vl-notice vl-notice--info" role="status">
			<p>
				<?php
				printf(
					/* translators: 1: number of pages waiting, 2: pages checked per run. */
					esc_html( _n(
						'%1$d tracked page has not been checked yet. Checks run %2$d at a time so one slow page cannot hold up the rest — the queue clears over the next few runs.',
						'%1$d tracked pages have not been checked yet. Checks run %2$d at a time so one slow page cannot hold up the rest — the queue clears over the next few runs.',
						$never,
						'vitallens'
					) ),
					(int) $never,
					(int) $batch
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . VitalLens_Admin::SLUG . '-settings' ) ); ?>"><?php esc_html_e( 'Change how many run at once', 'vitallens' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * What the current selection costs, before it is saved.
	 *
	 * The figures are rendered from what is stored and then recomputed in the
	 * browser as rows are ticked -- the screen claims to price the selection
	 * in front of you, and a number that only moves after a save does not.
	 *
	 * @param WP_Query $query   The rows on screen.
	 * @param array    $tracked Tracked rows keyed by post id.
	 * @param array    $devices Devices selected.
	 * @return void
	 */
	private static function render_cost( $query, $tracked, $devices ) {
		$pages      = count( $tracked );
		$per_device = max( 1, count( $devices ) );
		$interval   = max( 1, (int) VitalLens_Settings::get( 'interval_hours', 24 ) );
		$per_run    = $pages * $per_device;
		$per_day    = $per_run * ( 24 / $interval );
		$per_month  = (int) round( $per_day * 30 );
		$share      = ( $per_day / VitalLens_Settings::DAILY_ALLOWANCE ) * 100;

		/*
		 * Everything tracked that this screen is not showing. A filtered or
		 * paged view can only add to or remove from that, so the live total
		 * has to count up from what is off screen rather than from zero.
		 */
		$on_screen = 0;
		foreach ( $query->posts as $post ) {
			$post_id = is_object( $post ) ? (int) $post->ID : (int) $post;
			if ( isset( $tracked[ $post_id ] ) ) {
				++$on_screen;
			}
		}

		$cells = array(
			array( 'pages', __( 'Pages tracked', 'vitallens' ), number_format_i18n( $pages ), '' ),
			array( 'per_run', __( 'Calls per run', 'vitallens' ), number_format_i18n( $per_run ), '' ),
			array( 'per_month', __( 'Per month', 'vitallens' ), number_format_i18n( $per_month ), '' ),
			array( 'share', __( 'Of your allowance', 'vitallens' ), number_format_i18n( $share, 1 ) . '%', 'vl-cost__v--good' ),
		);
		?>
		<div class="vl-cost" id="vl-cost"
			data-devices="<?php echo esc_attr( $per_device ); ?>"
			data-interval="<?php echo esc_attr( $interval ); ?>"
			data-allowance="<?php echo esc_attr( VitalLens_Settings::DAILY_ALLOWANCE ); ?>"
			data-baseline="<?php echo esc_attr( max( 0, $pages - $on_screen ) ); ?>">
			<?php foreach ( $cells as $cell ) : ?>
				<div>
					<span class="vl-cost__k"><?php echo esc_html( $cell[1] ); ?></span>
					<span class="vl-cost__v <?php echo esc_attr( $cell[3] ); ?>"
						data-vl-cost="<?php echo esc_attr( $cell[0] ); ?>"
						data-saved="<?php echo esc_attr( $cell[2] ); ?>"><?php echo esc_html( $cell[2] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Pager.
	 *
	 * @param WP_Query $query Query.
	 * @param int      $paged Current page.
	 * @return void
	 */
	private static function render_pagination( $query, $paged ) {
		if ( $query->max_num_pages < 2 ) {
			return;
		}
		$links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'current'   => $paged,
				'total'     => $query->max_num_pages,
				'prev_text' => '&larr;',
				'next_text' => '&rarr;',
			)
		);
		if ( $links ) {
			echo '<div class="vl-pager tablenav-pages">' . wp_kses_post( $links ) . '</div>';
		}
	}
}
