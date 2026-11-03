<?php
/**
 * Stored options, with defaults and sanitising in one place.
 *
 * Every read goes through here so no caller has to remember what a missing
 * option should mean, and no caller can store a value the rest of the plugin
 * would have to defend against later.
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings accessor.
 */
class VitalLens_Settings {

	const OPTION = 'vitallens_settings';

	/**
	 * Google's free daily quota for the PageSpeed Insights API.
	 *
	 * Named here so the two screens that price a selection cannot drift
	 * apart, and so raising it later is one edit.
	 */
	const DAILY_ALLOWANCE = 25000;

	/**
	 * Intervals offered, in hours.
	 *
	 * @return array<int, string>
	 */
	public static function intervals() {
		return array(
			6  => __( 'Every 6 hours', 'vitallens' ),
			12 => __( 'Every 12 hours', 'vitallens' ),
			24 => __( 'Every 24 hours', 'vitallens' ),
			168 => __( 'Weekly', 'vitallens' ),
		);
	}

	/**
	 * How many pages one sweep may check.
	 *
	 * PSI answers in ten to thirty seconds, so this is bounded by how long a
	 * single PHP request may reasonably run, not by anything Google limits.
	 * Five is safe everywhere; a host with a generous max_execution_time can
	 * clear a large backlog faster by raising it.
	 *
	 * @return array<int, string>
	 */
	public static function batch_sizes() {
		return array(
			3  => __( '3 pages — slow hosts', 'vitallens' ),
			5  => __( '5 pages — safe everywhere', 'vitallens' ),
			10 => __( '10 pages — faster, needs a longer timeout', 'vitallens' ),
			20 => __( '20 pages — only with real cron', 'vitallens' ),
		);
	}

	/**
	 * Retention windows, in days. Zero keeps everything.
	 *
	 * @return array<int, string>
	 */
	public static function retentions() {
		return array(
			90  => __( '90 days', 'vitallens' ),
			365 => __( '1 year', 'vitallens' ),
			0   => __( 'Forever', 'vitallens' ),
		);
	}

	/**
	 * Defaults.
	 *
	 * Improvement alerts default off, like every other piece of mail nobody
	 * asked for. Problems default on, because that is what someone installing
	 * a monitoring plugin is asking for.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_key'           => '',
			'interval_hours'    => 24,
			'devices'           => array( 'mobile' ),
			'retention_days'    => 365,
			'cron_source'       => 'wp',
			'alert_failing'     => 1,
			'alert_headroom'    => 1,
			'alert_recovered'   => 0,
			'alert_stalled'     => 1,
			'alert_email'       => '',
			'batch_size'        => 5,

			/*
			 * Off, and it stays off unless somebody goes and ticks it. The
			 * cost of the other default is a site owner who deleted the
			 * plugin as a step in updating it, and lost a year of history
			 * doing so.
			 */
			'delete_data'       => 0,
		);
	}

	/**
	 * Every setting, defaults filled in.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	/**
	 * One setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Replace the stored settings with a sanitised set.
	 *
	 * @param array $input Raw input, usually straight off a form.
	 * @return array The settings as stored.
	 */
	public static function save( array $input ) {
		$clean = self::sanitize( $input );
		update_option( self::OPTION, $clean, false );
		return $clean;
	}

	/**
	 * Force every value into something the rest of the plugin can trust.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$defaults = self::defaults();
		$current  = self::all();
		$out      = array();

		// The key is only replaced when something was actually typed, so
		// saving the form with the field untouched cannot wipe it.
		$key = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
		if ( '' !== $key && false === strpos( $key, '•' ) ) {
			$out['api_key'] = sanitize_text_field( $key );
		} else {
			$out['api_key'] = (string) $current['api_key'];
		}

		$interval = isset( $input['interval_hours'] ) ? (int) $input['interval_hours'] : $defaults['interval_hours'];
		$out['interval_hours'] = array_key_exists( $interval, self::intervals() ) ? $interval : $defaults['interval_hours'];

		$devices = isset( $input['devices'] ) ? (array) $input['devices'] : array();
		$devices = array_values( array_intersect( array( 'mobile', 'desktop' ), $devices ) );
		// Checking nothing would quietly stop all monitoring while the screen
		// still said it was on. Fall back rather than store an empty set.
		$out['devices'] = $devices ? $devices : array( 'mobile' );

		$retention = isset( $input['retention_days'] ) ? (int) $input['retention_days'] : $defaults['retention_days'];
		$out['retention_days'] = array_key_exists( $retention, self::retentions() ) ? $retention : $defaults['retention_days'];

		$source = isset( $input['cron_source'] ) ? (string) $input['cron_source'] : $defaults['cron_source'];
		$out['cron_source'] = in_array( $source, array( 'wp', 'server' ), true ) ? $source : $defaults['cron_source'];

		foreach ( array( 'alert_failing', 'alert_headroom', 'alert_recovered', 'alert_stalled' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$email = isset( $input['alert_email'] ) ? trim( (string) $input['alert_email'] ) : '';
		$out['alert_email'] = ( '' !== $email && is_email( $email ) ) ? sanitize_email( $email ) : '';

		$batch = isset( $input['batch_size'] ) ? (int) $input['batch_size'] : $defaults['batch_size'];
		$out['batch_size'] = max( 1, min( 20, $batch ) );

		$out['delete_data'] = empty( $input['delete_data'] ) ? 0 : 1;

		return array_merge( $defaults, $out );
	}

	/**
	 * Where alert mail goes.
	 *
	 * @return string
	 */
	public static function alert_recipient() {
		$explicit = (string) self::get( 'alert_email', '' );
		return '' !== $explicit ? $explicit : (string) get_option( 'admin_email' );
	}

	/**
	 * How many API calls one sweep of every tracked page costs.
	 *
	 * Shown on screen before saving, because the cost of a selection is the
	 * one thing a free-quota product has to be honest about up front.
	 *
	 * @return int
	 */
	public static function calls_per_run() {
		return VitalLens_Store::count_active() * max( 1, count( (array) self::get( 'devices', array( 'mobile' ) ) ) );
	}

	/**
	 * Whether a key has been saved at all.
	 *
	 * @return bool
	 */
	public static function has_key() {
		return '' !== trim( (string) self::get( 'api_key', '' ) );
	}
}
