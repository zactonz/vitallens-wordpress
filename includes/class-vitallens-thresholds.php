<?php
/**
 * Where "good" stops, and how to judge a move between two readings.
 *
 * The only place these numbers live. Nothing else in the plugin may restate a
 * limit, a noise floor or a verdict rule: the product this was ported from
 * shipped three separate bugs that all traced back to the same facts being
 * written down in more than one file and drifting apart.
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

/**
 * Core Web Vitals limits and the judgements built on them.
 */
class VitalLens_Thresholds {

	const GOOD    = 'good';
	const NEEDS   = 'needs-improvement';
	const POOR    = 'poor';
	const UNKNOWN = 'unknown';

	/**
	 * Google's published boundaries, plus the lab-only metrics on Lighthouse's
	 * own scoring curve.
	 *
	 * @return array<string, array{good: float, poor: float, unit: string}>
	 */
	public static function limits() {
		return array(
			'LCP'  => array(
				'good' => 2500.0,
				'poor' => 4000.0,
				'unit' => 'ms',
			),
			'INP'  => array(
				'good' => 200.0,
				'poor' => 500.0,
				'unit' => 'ms',
			),
			'CLS'  => array(
				'good' => 0.1,
				'poor' => 0.25,
				'unit' => '',
			),
			'FCP'  => array(
				'good' => 1800.0,
				'poor' => 3000.0,
				'unit' => 'ms',
			),
			'TTFB' => array(
				'good' => 800.0,
				'poor' => 1800.0,
				'unit' => 'ms',
			),
			'TBT'  => array(
				'good' => 200.0,
				'poor' => 600.0,
				'unit' => 'ms',
			),
			'SI'   => array(
				'good' => 3400.0,
				'poor' => 5800.0,
				'unit' => 'ms',
			),
		);
	}

	/**
	 * The three metrics that actually decide whether a page passes.
	 *
	 * @return string[]
	 */
	public static function core_metrics() {
		return array( 'LCP', 'INP', 'CLS' );
	}

	/**
	 * Which band a value falls in.
	 *
	 * @param string     $metric Metric key.
	 * @param float|null $value  Measured value.
	 * @return string One of the class constants.
	 */
	public static function verdict( $metric, $value ) {
		if ( null === $value || '' === $value ) {
			return self::UNKNOWN;
		}
		$limits = self::limits();
		if ( ! isset( $limits[ $metric ] ) ) {
			return self::UNKNOWN;
		}
		$value = (float) $value;
		if ( $value <= $limits[ $metric ]['good'] ) {
			return self::GOOD;
		}
		if ( $value <= $limits[ $metric ]['poor'] ) {
			return self::NEEDS;
		}
		return self::POOR;
	}

	/**
	 * The value at which a metric stops being "good".
	 *
	 * @param string $metric Metric key.
	 * @return float|null Null when the metric has no published limit.
	 */
	public static function good_limit( $metric ) {
		$limits = self::limits();
		return isset( $limits[ $metric ] ) ? (float) $limits[ $metric ]['good'] : null;
	}

	/**
	 * The smallest move, in a metric's own units, worth reporting at all.
	 *
	 * Set well inside a band so anything large enough to push a page towards
	 * failing survives. CrUX reports CLS on a 0.01 grid, so a single tick --
	 * 0.03 to 0.04 -- is a 33% change that would clear any sane percentage
	 * threshold while the metric is still three times inside "good". A
	 * percentage alone cannot tell that apart from a real regression.
	 *
	 * @param string $metric Metric key.
	 * @return float Zero when the metric has no floor, i.e. percentage only.
	 */
	public static function noise_floor( $metric ) {
		$floors = array(
			'LCP'  => 100.0,
			'INP'  => 50.0,
			'CLS'  => 0.02,
			'FCP'  => 100.0,
			'TTFB' => 100.0,
			'TBT'  => 50.0,
			'SI'   => 200.0,
		);
		return isset( $floors[ $metric ] ) ? $floors[ $metric ] : 0.0;
	}

	/**
	 * What fraction of the room left before failing a single move consumed.
	 *
	 * 0.04 -> 0.07 against a CLS limit of 0.1 had 0.06 of room and spent 0.03
	 * of it: a half. The same absolute move at 0.01 would be a fifth. Asking
	 * the question this way is what lets one rule hold for a metric measured
	 * in milliseconds and one measured in hundredths -- and it is what catches
	 * a page drifting steadily towards a threshold, which a band check only
	 * notices once visitors are already feeling it.
	 *
	 * @param string $metric   Metric key.
	 * @param float  $previous Earlier reading.
	 * @param float  $current  Later reading.
	 * @return float|null Null when there is no margin left to spend, or the
	 *                    move was an improvement.
	 */
	public static function margin_consumed( $metric, $previous, $current ) {
		$limit = self::good_limit( $metric );
		if ( null === $limit ) {
			return null;
		}
		$previous = (float) $previous;
		$current  = (float) $current;
		if ( $previous >= $limit || $current <= $previous ) {
			return null;
		}
		return ( $current - $previous ) / ( $limit - $previous );
	}

	/**
	 * The mirror of margin_consumed(): how much ground a move won back.
	 *
	 * Measured against the room *after* the move, so 0.04 -> 0.07 and
	 * 0.07 -> 0.04 both read 50%. Any other formula makes a recovery easier or
	 * harder to earn than the regression it undoes, for no reason anybody
	 * could defend to a user.
	 *
	 * @param string $metric   Metric key.
	 * @param float  $previous Earlier reading.
	 * @param float  $current  Later reading.
	 * @return float|null Null when the move was not an improvement.
	 */
	public static function margin_regained( $metric, $previous, $current ) {
		$limit = self::good_limit( $metric );
		if ( null === $limit ) {
			return null;
		}
		$previous = (float) $previous;
		$current  = (float) $current;
		if ( $current >= $previous ) {
			return null;
		}
		if ( $previous > $limit ) {
			// Was failing: how much of the excess over the limit did it shed?
			return min( 1.0, ( $previous - $current ) / ( $previous - $limit ) );
		}
		$room = $limit - $current;
		return $room <= 0 ? null : min( 1.0, ( $previous - $current ) / $room );
	}

	/**
	 * Whether a move is big enough to be worth recording at all.
	 *
	 * The absolute floor answers "is this measurement noise", and it cannot
	 * answer that alone when a metric is nearly out of room: INP's 50ms floor
	 * is a quarter of its entire good band, so 150ms -> 185ms -- which spends
	 * 70% of the margin left -- would otherwise be discarded. A move that eats
	 * a third of the remaining margin is not noise at any absolute size.
	 *
	 * @param string $metric   Metric key.
	 * @param float  $previous Earlier reading.
	 * @param float  $current  Later reading.
	 * @return bool
	 */
	public static function is_material( $metric, $previous, $current ) {
		$move = abs( (float) $current - (float) $previous );

		$consumed = self::margin_consumed( $metric, $previous, $current );
		$regained = self::margin_regained( $metric, $previous, $current );
		$eats     = ( null !== $consumed && $consumed >= 0.33 );
		$wins     = ( null !== $regained && $regained >= 0.33 );

		// The tolerance is not decoration: 0.15 - 0.13 evaluates to
		// 0.019999999999999997 in binary floating point, so a real two-step
		// CLS move would land just under a 0.02 floor and be dropped for a
		// reason no one could explain from the outside.
		if ( $move < self::noise_floor( $metric ) - 1e-9 && ! $eats && ! $wins ) {
			return false;
		}
		return true;
	}

	/**
	 * A value as a person would read it.
	 *
	 * @param string     $metric Metric key.
	 * @param float|null $value  Measured value.
	 * @return string
	 */
	public static function format( $metric, $value ) {
		if ( null === $value || '' === $value ) {
			return '—';
		}
		$value = (float) $value;
		if ( 'CLS' === $metric ) {
			return number_format_i18n( $value, 2 );
		}
		if ( $value >= 1000 ) {
			return number_format_i18n( $value / 1000, 2 ) . 's';
		}
		return number_format_i18n( round( $value ) ) . 'ms';
	}

	/**
	 * A verdict as a short label, for a badge or a column.
	 *
	 * @param string $verdict One of the class constants.
	 * @return string
	 */
	public static function verdict_label( $verdict ) {
		switch ( $verdict ) {
			case self::GOOD:
				return __( 'Good', 'vitallens' );
			case self::NEEDS:
				return __( 'Needs work', 'vitallens' );
			case self::POOR:
				return __( 'Poor', 'vitallens' );
			default:
				return __( 'No data', 'vitallens' );
		}
	}

	/**
	 * The colour a verdict is drawn in.
	 *
	 * Returned as a pair because text and fill cannot be the same value and
	 * still clear 4.5:1 on white -- the bright fills Google uses for CWV all
	 * fail as text.
	 *
	 * @param string $verdict One of the class constants.
	 * @return array{fill: string, text: string, bg: string}
	 */
	public static function verdict_colors( $verdict ) {
		switch ( $verdict ) {
			case self::GOOD:
				return array(
					'fill' => '#10b981',
					'text' => '#047857',
					'bg'   => '#ecfdf5',
				);
			case self::NEEDS:
				return array(
					'fill' => '#f59e0b',
					'text' => '#92620a',
					'bg'   => '#fffbeb',
				);
			case self::POOR:
				return array(
					'fill' => '#ef4444',
					'text' => '#b91c1c',
					'bg'   => '#fef2f2',
				);
			default:
				return array(
					'fill' => '#c7c9d4',
					'text' => '#5c5f72',
					'bg'   => '#f7f7fb',
				);
		}
	}
}
