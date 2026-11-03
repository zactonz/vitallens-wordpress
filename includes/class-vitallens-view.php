<?php
/**
 * Shared bits of rendering: the dots, the chips, the charts.
 *
 * Here rather than in each screen so the field/lab distinction is drawn the
 * same way everywhere. The moment two screens draw it differently is the
 * moment one of them starts lying.
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

/**
 * Presentation helpers. Every method returns escaped, printable HTML.
 */
class VitalLens_View {

	/**
	 * A status dot.
	 *
	 * Filled means real visitors, a ring means a lab test. A difference in
	 * shape, not only colour, so the pair survives a narrow column and reads
	 * for anyone who finds green and amber hard to tell apart.
	 *
	 * @param string $verdict One of the VitalLens_Thresholds constants.
	 * @param bool   $solid   True for field data, false for lab.
	 * @param int    $size    Diameter in px.
	 * @return string
	 */
	public static function dot( $verdict, $solid = true, $size = 13 ) {
		$colors = VitalLens_Thresholds::verdict_colors( $verdict );
		$style  = sprintf(
			'width:%1$dpx;height:%1$dpx;border-radius:999px;display:inline-block;box-sizing:border-box;',
			(int) $size
		);
		$style .= $solid
			? 'background:' . $colors['fill'] . ';'
			: 'border:2.5px solid ' . $colors['fill'] . ';';

		return '<span class="vl-dot-mark" style="' . esc_attr( $style ) . '"></span>';
	}

	/**
	 * A metric value as a coloured chip.
	 *
	 * @param string     $metric Metric key.
	 * @param float|null $value  Value.
	 * @return string
	 */
	public static function chip( $metric, $value ) {
		$verdict = VitalLens_Thresholds::verdict( $metric, $value );
		$colors  = VitalLens_Thresholds::verdict_colors( $verdict );

		return sprintf(
			'<span class="vl-chip" style="color:%1$s;background:%2$s;">%3$s <strong>%4$s</strong></span>',
			esc_attr( $colors['text'] ),
			esc_attr( $colors['bg'] ),
			esc_html( $metric ),
			esc_html( VitalLens_Thresholds::format( $metric, $value ) )
		);
	}

	/**
	 * A label for where a reading came from, never hidden.
	 *
	 * @param string $source One of the VitalLens_PSI_Client::FIELD_* constants.
	 * @return string
	 */
	public static function source_badge( $source ) {
		switch ( $source ) {
			case VitalLens_PSI_Client::FIELD_PAGE:
				return '<span class="vl-badge vl-badge--good">' . esc_html__( 'Real visitors', 'vitallens' ) . '</span>';
			case VitalLens_PSI_Client::FIELD_ORIGIN:
				return '<span class="vl-badge vl-badge--muted">' . esc_html__( 'Origin only', 'vitallens' ) . '</span>';
			default:
				return '<span class="vl-badge vl-badge--warn">' . esc_html__( 'Lab only', 'vitallens' ) . '</span>';
		}
	}

	/**
	 * A one-line explanation of what a source badge means.
	 *
	 * @param string $source Source constant.
	 * @return string
	 */
	public static function source_help( $source ) {
		switch ( $source ) {
			case VitalLens_PSI_Client::FIELD_PAGE:
				return __( 'Google has enough traffic on this exact URL to report what real visitors measured.', 'vitallens' );
			case VitalLens_PSI_Client::FIELD_ORIGIN:
				return __( 'Google has no data for this URL specifically, so these numbers describe the whole site.', 'vitallens' );
			default:
				return __( 'Google has no real-user data for this site yet. These are lab numbers — one simulated load, not what your visitors experienced.', 'vitallens' );
		}
	}

	/**
	 * A track with the failing line marked on it.
	 *
	 * More useful than a plain bar: it shows how close to failing a value is,
	 * not just how big it is.
	 *
	 * @param string     $metric Metric key.
	 * @param float|null $value  Value.
	 * @return string
	 */
	public static function track( $metric, $value ) {
		$limit = VitalLens_Thresholds::good_limit( $metric );
		if ( null === $limit || null === $value ) {
			return '';
		}
		$verdict = VitalLens_Thresholds::verdict( $metric, $value );
		$colors  = VitalLens_Thresholds::verdict_colors( $verdict );

		// The scale runs to 1.5x the limit so a failing value still has
		// somewhere to go and the marker does not sit at the very end.
		$scale = $limit * 1.5;
		$fill  = max( 0, min( 100, ( (float) $value / $scale ) * 100 ) );
		$mark  = max( 0, min( 100, ( $limit / $scale ) * 100 ) );

		return '<span class="vl-track">'
			. '<span class="vl-track__fill" style="width:' . esc_attr( round( $fill, 1 ) ) . '%;background:' . esc_attr( $colors['fill'] ) . ';"></span>'
			. '<span class="vl-track__mark" style="left:' . esc_attr( round( $mark, 1 ) ) . '%;"></span>'
			. '</span>';
	}

	/**
	 * A small sparkline.
	 *
	 * @param array  $values Numeric values, oldest first.
	 * @param string $color  Stroke colour.
	 * @param string $label  Accessible description.
	 * @return string
	 */
	public static function sparkline( $values, $color = '#4f46e5', $label = '' ) {
		$values = array_values( array_filter( $values, 'is_numeric' ) );
		if ( count( $values ) < 2 ) {
			return '<span class="vl-spark-empty">' . esc_html__( 'building', 'vitallens' ) . '</span>';
		}

		$width  = 92;
		$height = 26;
		$min    = min( $values );
		$max    = max( $values );
		$range  = ( $max - $min ) > 0 ? ( $max - $min ) : 1;
		$step   = $width / max( 1, count( $values ) - 1 );

		$points = array();
		foreach ( $values as $i => $value ) {
			$x = round( $i * $step, 1 );
			// Inverted: a lower metric is better, so a falling line is good news.
			$y = round( $height - 3 - ( ( $value - $min ) / $range ) * ( $height - 6 ), 1 );
			$points[] = $x . ',' . $y;
		}

		return sprintf(
			'<svg width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d" fill="none" role="img" aria-label="%3$s">'
			. '<polyline points="%4$s" stroke="%5$s" stroke-width="1.6" fill="none" stroke-linejoin="round" stroke-linecap="round"/></svg>',
			$width,
			$height,
			esc_attr( $label ? $label : __( 'Trend', 'vitallens' ) ),
			esc_attr( implode( ' ', $points ) ),
			esc_attr( $color )
		);
	}

	/**
	 * The full history chart for one metric, with the failing line drawn in.
	 *
	 * Rendered server-side as SVG rather than handing JSON to a charting
	 * library: it is one line and a threshold, and shipping a dependency for
	 * that is how admin screens get slow.
	 *
	 * @param array  $rows   Rows of {checked_at, value}, oldest first.
	 * @param string $metric Metric key.
	 * @return string
	 */
	public static function chart( $rows, $metric ) {
		$values = array();
		foreach ( $rows as $row ) {
			if ( is_numeric( $row->value ) ) {
				$values[] = (float) $row->value;
			}
		}
		if ( count( $values ) < 2 ) {
			return '<p class="vl-empty">' . esc_html__( 'Not enough history yet — the chart fills in as checks accumulate.', 'vitallens' ) . '</p>';
		}

		$width  = 900;
		$height = 200;
		$limit  = VitalLens_Thresholds::good_limit( $metric );

		$max = max( $values );
		if ( null !== $limit ) {
			$max = max( $max, $limit );
		}
		$max  = $max * 1.15;
		$max  = $max > 0 ? $max : 1;
		$step = $width / max( 1, count( $values ) - 1 );

		$points = array();
		foreach ( $values as $i => $value ) {
			$x        = round( $i * $step, 1 );
			$y        = round( $height - ( $value / $max ) * $height, 1 );
			$points[] = $x . ',' . $y;
		}

		$line = implode( ' ', $points );
		$area = 'M' . str_replace( ' ', ' L', $line ) . ' L' . $width . ',' . $height . ' L0,' . $height . ' Z';

		$limit_y   = null !== $limit ? round( $height - ( $limit / $max ) * $height, 1 ) : null;
		$last      = end( $values );
		$last_bad  = VitalLens_Thresholds::verdict( $metric, $last );
		$stroke    = VitalLens_Thresholds::verdict_colors( $last_bad )['fill'];

		$label = sprintf(
			/* translators: 1: metric name, 2: first value, 3: last value. */
			__( '%1$s over time, from %2$s to %3$s', 'vitallens' ),
			VitalLens_Alerts::metric_name( $metric ),
			VitalLens_Thresholds::format( $metric, $values[0] ),
			VitalLens_Thresholds::format( $metric, $last )
		);

		/*
		 * The points, for the hover layer. They are carried as data rather
		 * than redrawn in JavaScript so the chart and its tooltip can never
		 * disagree about what a reading was.
		 */
		$data = array();
		foreach ( $rows as $i => $row ) {
			if ( ! isset( $values[ $i ] ) ) {
				continue;
			}
			$ts      = strtotime( $row->checked_at . ' UTC' );
			$data[]  = array(
				'x' => round( $i * $step, 1 ),
				'y' => round( $height - ( $values[ $i ] / $max ) * $height, 1 ),
				'v' => VitalLens_Thresholds::format( $metric, $values[ $i ] ),
				'd' => $ts ? date_i18n( get_option( 'date_format' ), $ts ) : '',
				'b' => VitalLens_Thresholds::verdict_colors( VitalLens_Thresholds::verdict( $metric, $values[ $i ] ) )['fill'],
			);
		}

		$svg = '<svg class="vl-chart" viewBox="0 0 ' . $width . ' ' . $height . '" preserveAspectRatio="none" role="img" aria-label="' . esc_attr( $label ) . '">';
		$svg .= '<defs><linearGradient id="vlArea" x1="0" y1="0" x2="0" y2="1">'
			. '<stop offset="0%" stop-color="' . esc_attr( $stroke ) . '" stop-opacity=".2"/>'
			. '<stop offset="100%" stop-color="' . esc_attr( $stroke ) . '" stop-opacity="0"/></linearGradient></defs>';
		$svg .= '<path d="' . esc_attr( $area ) . '" fill="url(#vlArea)"/>';
		if ( null !== $limit_y ) {
			$svg .= '<line x1="0" y1="' . esc_attr( $limit_y ) . '" x2="' . $width . '" y2="' . esc_attr( $limit_y ) . '" stroke="#ef4444" stroke-width="1.5" stroke-dasharray="6 5"/>';
		}
		$svg .= '<polyline points="' . esc_attr( $line ) . '" stroke="' . esc_attr( $stroke ) . '" stroke-width="2.5" fill="none" stroke-linejoin="round" stroke-linecap="round"/>';
		$svg .= '</svg>';

		/*
		 * The guide, dot and tooltip are HTML, not SVG. The viewBox is
		 * stretched with preserveAspectRatio="none", so a circle drawn inside
		 * it comes out an ellipse and a 1px line comes out whatever width the
		 * container happens to make it.
		 */
		return '<div class="vl-chart-wrap" tabindex="0" data-vl-chart="' . esc_attr( wp_json_encode( $data ) ) . '"'
			. ' data-vl-chart-w="' . esc_attr( $width ) . '" data-vl-chart-h="' . esc_attr( $height ) . '">'
			. $svg
			. '<span class="vl-chart-guide" aria-hidden="true"></span>'
			. '<span class="vl-chart-dot" aria-hidden="true"></span>'
			. '<span class="vl-chart-tip" role="status" aria-live="polite"></span>'
			. '</div>';
	}

	/**
	 * The ring used for a Lighthouse score.
	 *
	 * @param int|null $score Score out of 100.
	 * @param int      $size  Diameter.
	 * @return string
	 */
	public static function score_ring( $score, $size = 86 ) {
		if ( null === $score ) {
			return '<span class="vl-empty">' . esc_html__( 'No score yet', 'vitallens' ) . '</span>';
		}
		$score   = max( 0, min( 100, (int) $score ) );
		$verdict = $score >= 90 ? VitalLens_Thresholds::GOOD : ( $score >= 50 ? VitalLens_Thresholds::NEEDS : VitalLens_Thresholds::POOR );
		$colors  = VitalLens_Thresholds::verdict_colors( $verdict );

		$r      = ( $size / 2 ) - 8;
		$circ   = 2 * M_PI * $r;
		$offset = $circ * ( 1 - ( $score / 100 ) );
		$c      = $size / 2;

		return sprintf(
			'<svg width="%1$d" height="%1$d" viewBox="0 0 %1$d %1$d" role="img" aria-label="%2$s">'
			. '<circle cx="%3$s" cy="%3$s" r="%4$s" stroke="#f1f1f6" stroke-width="8" fill="none"/>'
			. '<circle cx="%3$s" cy="%3$s" r="%4$s" stroke="%5$s" stroke-width="8" fill="none" stroke-linecap="round"'
			. ' stroke-dasharray="%6$s" stroke-dashoffset="%7$s" transform="rotate(-90 %3$s %3$s)"/>'
			. '<text x="%3$s" y="%8$s" text-anchor="middle" font-size="%9$s" font-weight="600" fill="%10$s"'
			. ' font-family="-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif">%11$d</text></svg>',
			(int) $size,
			esc_attr( sprintf(
				/* translators: %d: performance score out of 100. */
				__( 'Lighthouse performance score %d out of 100', 'vitallens' ),
				$score
			) ),
			esc_attr( $c ),
			esc_attr( round( $r, 1 ) ),
			esc_attr( $colors['fill'] ),
			esc_attr( round( $circ, 1 ) ),
			esc_attr( round( $offset, 1 ) ),
			esc_attr( $c + ( $size * 0.08 ) ),
			esc_attr( round( $size * 0.28 ) ),
			esc_attr( $colors['text'] ),
			$score
		);
	}

	/**
	 * The tags and attributes an inline SVG of ours is allowed to use.
	 *
	 * wp_kses_post() permits no SVG whatsoever -- it took the 549 characters
	 * of the Lighthouse score ring and returned the two digits of the score,
	 * silently, on every page report. Anything this class draws has to be
	 * escaped against this list instead.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function svg_allowed() {
		$tags = array(
			'svg'            => array(
				'class'               => true,
				'viewbox'             => true,
				'preserveaspectratio' => true,
				'role'                => true,
				'aria-label'          => true,
				'width'               => true,
				'height'              => true,
				'fill'                => true,
				'xmlns'               => true,
			),
			'defs'           => array(),
			'lineargradient' => array(
				'id' => true,
				'x1' => true,
				'y1' => true,
				'x2' => true,
				'y2' => true,
			),
			'stop'           => array(
				'offset'       => true,
				'stop-color'   => true,
				'stop-opacity' => true,
			),
			'path'           => array(
				'd'    => true,
				'fill' => true,
			),
			'polyline'       => array(
				'points'           => true,
				'stroke'           => true,
				'stroke-width'     => true,
				'fill'             => true,
				'stroke-linejoin'  => true,
				'stroke-linecap'   => true,
			),
			'line'           => array(
				'x1'               => true,
				'y1'               => true,
				'x2'               => true,
				'y2'               => true,
				'stroke'           => true,
				'stroke-width'     => true,
				'stroke-dasharray' => true,
			),
			'circle'         => array(
				'cx'                => true,
				'cy'                => true,
				'r'                 => true,
				'stroke'            => true,
				'stroke-width'      => true,
				'fill'              => true,
				'stroke-dasharray'  => true,
				'stroke-dashoffset' => true,
				'stroke-linecap'    => true,
				'transform'         => true,
			),
			'text'           => array(
				'x'           => true,
				'y'           => true,
				'text-anchor' => true,
				'font-size'   => true,
				'font-weight' => true,
				'fill'        => true,
				'font-family' => true,
			),
			'p'              => array( 'class' => true ),
		);

		$tags['rect'] = array(
			'x'      => true,
			'y'      => true,
			'width'  => true,
			'height' => true,
			'rx'     => true,
			'ry'     => true,
			'fill'   => true,
			'class'  => true,
		);
		$tags['span'] = array(
			'class'       => true,
			'style'       => true,
			'role'        => true,
			'aria-live'   => true,
			'aria-hidden' => true,
		);
		$tags['div']  = array(
			'class'            => true,
			'tabindex'         => true,
			'role'             => true,
			'aria-label'       => true,
			'data-vl-chart'    => true,
			'data-vl-chart-w'  => true,
			'data-vl-chart-h'  => true,
		);
		$tags['strong'] = array( 'class' => true );
		$tags['svg']['aria-hidden'] = true;
		$tags['svg']['focusable']   = true;

		return $tags;
	}

	/**
	 * The brand mark, inline so it takes the colour around it.
	 *
	 * The admin menu needs a base64 data URI and has its own copy in
	 * VitalLens_Admin; this one is for places that can hold real markup.
	 *
	 * @param int    $size  Pixel size.
	 * @param string $color Any CSS colour.
	 * @return string
	 */
	public static function mark( $size = 28, $color = 'currentColor' ) {
		return sprintf(
			'<svg class="vl-mark" width="%1$d" height="%1$d" viewBox="0 0 40 40" fill="%2$s" aria-hidden="true" focusable="false">'
			. '<rect x="6" y="21" width="5" height="12" rx="2"/>'
			. '<rect x="13" y="11" width="5" height="22" rx="2"/>'
			. '<rect x="20" y="16" width="5" height="17" rx="2"/>'
			. '<rect x="27" y="24" width="5" height="9" rx="2"/>'
			. '</svg>',
			(int) $size,
			esc_attr( $color )
		);
	}

	/**
	 * The admin URL for one tracked page's detail screen.
	 *
	 * @param int $tracked_id Tracked row id.
	 * @return string
	 */
	public static function detail_url( $tracked_id ) {
		return admin_url(
			'admin.php?page=' . VitalLens_Admin::SLUG . '-page&tracked=' . (int) $tracked_id
		);
	}
}
