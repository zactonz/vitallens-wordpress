<?php
/**
 * The column on the Posts and Pages lists.
 *
 * Two linked circles and nothing else: a filled disc for what real visitors
 * measured, a ring for the lab test. Shape as well as colour, so the pair
 * survives a narrow column and reads for anyone who finds green and amber
 * hard to tell apart. Hovering either shows the latest numbers.
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds and renders the Core Web Vitals column.
 */
class VitalLens_Posts_Column {

	/**
	 * Cached rows for the posts on screen, so the column never queries per row.
	 *
	 * @var array<int, array<string, object>>|null
	 */
	private static $cache = null;

	/**
	 * Register the column on every public post type.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Hook the column onto each list table.
	 *
	 * @return void
	 */
	public static function register() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $types as $type ) {
			if ( 'attachment' === $type ) {
				continue;
			}
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'add_column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'render' ), 10, 2 );
		}
		add_action( 'pre_get_posts', array( __CLASS__, 'prime' ) );
	}

	/**
	 * Add the header.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		// Before Date, which is conventionally last and the least urgent.
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$out['vitallens'] = __( 'Vitals', 'vitallens' );
			}
			$out[ $key ] = $label;
		}
		if ( ! isset( $out['vitallens'] ) ) {
			$out['vitallens'] = __( 'Vitals', 'vitallens' );
		}
		return $out;
	}

	/**
	 * Load every row's data in one query before the table renders.
	 *
	 * @param WP_Query $query The main admin query.
	 * @return void
	 */
	public static function prime( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		// Reset so a second list table on the same request does not reuse
		// the first one's rows.
		self::$cache = null;
	}

	/**
	 * Render one cell.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post id.
	 * @return void
	 */
	public static function render( $column, $post_id ) {
		if ( 'vitallens' !== $column ) {
			return;
		}

		$rows = self::rows_for_screen();
		$post = isset( $rows[ (int) $post_id ] ) ? $rows[ (int) $post_id ] : null;

		if ( ! $post ) {
			// Real text rather than a title attribute: screen readers treat
			// title on a span as optional, so "not tracked" would simply not
			// be announced and the cell would read as empty.
			echo '<span class="vl-col vl-col--off">';
			echo wp_kses_post( VitalLens_View::dot( VitalLens_Thresholds::UNKNOWN, true, 13 ) );
			echo wp_kses_post( VitalLens_View::dot( VitalLens_Thresholds::UNKNOWN, false, 13 ) );
			echo '<span class="screen-reader-text">' . esc_html__( 'Not tracked', 'vitallens' ) . '</span>';
			echo '</span>';
			return;
		}

		// Prefer the device the user is actually tracking; mobile first,
		// because that is the one Google ranks on.
		$row = isset( $post['mobile'] ) ? $post['mobile'] : reset( $post );

		echo '<span class="vl-col">';
		self::dot_link( $row, true );
		self::dot_link( $row, false );
		echo '</span>';
	}

	/**
	 * One circle: a link to the report, with its numbers in a hover card.
	 *
	 * @param object $row   Tracked row joined with its newest check.
	 * @param bool   $field True for the field disc, false for the lab ring.
	 * @return void
	 */
	private static function dot_link( $row, $field ) {
		$has_check = ! empty( $row->field_source );

		if ( $field ) {
			$none = ( ! $has_check || VitalLens_PSI_Client::FIELD_NONE === $row->field_source );
			$metrics = array(
				'LCP' => isset( $row->f_lcp ) ? $row->f_lcp : null,
				'INP' => isset( $row->f_inp ) ? $row->f_inp : null,
				'CLS' => isset( $row->f_cls ) ? $row->f_cls : null,
			);
			$heading = $none
				? __( 'No real-user data', 'vitallens' )
				: ( VitalLens_PSI_Client::FIELD_ORIGIN === $row->field_source
					? __( 'Whole site · 28d', 'vitallens' )
					: __( 'Real visitors · 28d', 'vitallens' ) );
			$foot    = $none
				? __( 'Google needs more traffic on this URL', 'vitallens' )
				: __( 'Open report & history', 'vitallens' );
		} else {
			$none    = ! $has_check;
			$metrics = array(
				'LCP' => isset( $row->l_lcp ) ? $row->l_lcp : null,
				'TBT' => isset( $row->l_tbt ) ? $row->l_tbt : null,
				'CLS' => isset( $row->l_cls ) ? $row->l_cls : null,
			);
			$heading = __( 'Lab · Lighthouse', 'vitallens' );
			$foot    = $has_check ? __( 'Open report & history', 'vitallens' ) : __( 'Not checked yet', 'vitallens' );
		}

		$verdict = self::worst( $metrics );
		$label   = self::aria_label( $field, $verdict, $metrics, $none );

		echo '<a class="vl-dot" href="' . esc_url( VitalLens_View::detail_url( $row->tracked_id ) ) . '" aria-label="' . esc_attr( $label ) . '">';
		echo wp_kses_post( VitalLens_View::dot( $none ? VitalLens_Thresholds::UNKNOWN : $verdict, $field, 13 ) );

		echo '<span class="vl-tip" role="tooltip"><span class="vl-tip__card">';
		echo '<span class="vl-tip__head">' . esc_html( $heading ) . '</span>';
		if ( ! empty( $row->score ) && ! $field ) {
			echo '<span class="vl-tip__row"><span>' . esc_html__( 'Score', 'vitallens' ) . '</span><strong>' . esc_html( (int) $row->score ) . '</strong></span>';
		}
		foreach ( $metrics as $metric => $value ) {
			$colors = VitalLens_Thresholds::verdict_colors( VitalLens_Thresholds::verdict( $metric, $value ) );
			echo '<span class="vl-tip__row"><span>' . esc_html( $metric ) . '</span>';
			echo '<strong style="color:' . esc_attr( null === $value ? '#8d90a1' : $colors['fill'] ) . '">';
			echo esc_html( VitalLens_Thresholds::format( $metric, $value ) );
			echo '</strong></span>';
		}
		echo '<span class="vl-tip__foot">' . esc_html( $foot ) . '</span>';
		echo '</span></span></a>';
	}

	/**
	 * The worst verdict across a set of metrics.
	 *
	 * @param array<string, float|null> $metrics Values keyed by metric.
	 * @return string
	 */
	private static function worst( $metrics ) {
		$rank  = array(
			VitalLens_Thresholds::UNKNOWN => 0,
			VitalLens_Thresholds::GOOD    => 1,
			VitalLens_Thresholds::NEEDS   => 2,
			VitalLens_Thresholds::POOR    => 3,
		);
		$worst = VitalLens_Thresholds::UNKNOWN;
		foreach ( $metrics as $metric => $value ) {
			if ( null === $value ) {
				continue;
			}
			$verdict = VitalLens_Thresholds::verdict( $metric, $value );
			if ( $rank[ $verdict ] > $rank[ $worst ] ) {
				$worst = $verdict;
			}
		}
		return $worst;
	}

	/**
	 * A spoken description of a dot.
	 *
	 * Colour cannot be the only carrier of meaning, and a tooltip that only
	 * answers to a mouse is invisible to anyone tabbing — so the numbers go
	 * into the accessible name as well as the card.
	 *
	 * @param bool   $field   Field or lab.
	 * @param string $verdict Worst verdict.
	 * @param array  $metrics Values.
	 * @param bool   $none    Whether there is no data at all.
	 * @return string
	 */
	private static function aria_label( $field, $verdict, $metrics, $none ) {
		$source = $field ? __( 'Real-user data', 'vitallens' ) : __( 'Lab test', 'vitallens' );

		if ( $none ) {
			return $field
				? __( 'No real-user data for this URL. Open the report.', 'vitallens' )
				: __( 'Not checked yet. Open the report.', 'vitallens' );
		}

		$parts = array();
		foreach ( $metrics as $metric => $value ) {
			if ( null !== $value ) {
				$parts[] = $metric . ' ' . VitalLens_Thresholds::format( $metric, $value );
			}
		}

		return sprintf(
			/* translators: 1: data source, 2: verdict, 3: metric values. */
			__( '%1$s: %2$s. %3$s. Open the report.', 'vitallens' ),
			$source,
			VitalLens_Thresholds::verdict_label( $verdict ),
			implode( ', ', $parts )
		);
	}

	/**
	 * Every tracked row for the posts currently listed, fetched once.
	 *
	 * @return array<int, array<string, object>>
	 */
	private static function rows_for_screen() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		global $wp_query;
		$ids = array();
		if ( isset( $wp_query->posts ) && is_array( $wp_query->posts ) ) {
			foreach ( $wp_query->posts as $post ) {
				$ids[] = is_object( $post ) ? (int) $post->ID : (int) $post;
			}
		}

		self::$cache = $ids ? VitalLens_Store::latest_for_posts( $ids ) : array();
		return self::$cache;
	}
}
