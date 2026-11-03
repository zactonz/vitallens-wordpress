<?php
/**
 * Wiring: activation, the schedule, and the one place hooks are registered.
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin lifecycle and scheduling.
 */
class VitalLens_Plugin {

	const SWEEP_HOOK = 'vitallens_sweep';
	const SWEEP_SLUG = 'vitallens_quarter_hour';

	/**
	 * Timestamp of the last sweep that actually ran.
	 */
	const LAST_SWEEP_OPTION = 'vitallens_last_sweep';

	/**
	 * Register everything.
	 *
	 * @return void
	 */
	public static function boot() {
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'sweep' ) );
		add_action( 'admin_init', array( 'VitalLens_Store', 'maybe_upgrade' ) );

		// Puts the sweep back if it is missing -- an install activated before
		// schedule() registered its own interval has nothing on the calendar,
		// and nobody thinks to deactivate and reactivate a plugin that looks
		// like it installed cleanly.
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );

		VitalLens_Alerts::boot();

		if ( is_admin() ) {
			VitalLens_Admin::boot();
			VitalLens_Posts_Column::boot();
			VitalLens_Widget::boot();
			VitalLens_Export::boot();
			VitalLens_Backup::boot();
		}
	}

	/**
	 * A quarter-hour tick.
	 *
	 * The sweep runs far more often than any page's check interval: each tick
	 * takes only the pages that are actually due, so the work spreads itself
	 * out instead of forty PSI calls landing in one request.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_schedule( $schedules ) {
		$schedules[ self::SWEEP_SLUG ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (VitalLens)', 'vitallens' ),
		);
		return $schedules;
	}

	/**
	 * Create tables and start the schedule.
	 *
	 * @return void
	 */
	public static function activate() {
		VitalLens_Store::install();
		self::schedule();
	}

	/**
	 * Stop the schedule. Tables and history stay: deactivating a plugin is
	 * not the same as asking for your data to be thrown away.
	 *
	 * @return void
	 */
	public static function deactivate() {
		/*
		 * A snapshot on the way out, into uploads rather than the plugin
		 * folder. Deactivation is the step before every delete, and an older
		 * release's uninstall.php -- which this code cannot reach or change --
		 * may still drop every table. This is the copy that survives that.
		 */
		VitalLens_Backup::create();

		$timestamp = wp_next_scheduled( self::SWEEP_HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::SWEEP_HOOK );
			$timestamp = wp_next_scheduled( self::SWEEP_HOOK );
		}
	}

	/**
	 * Make sure the sweep is on the calendar.
	 *
	 * The filter is registered here as well as in boot(), and that is not
	 * belt-and-braces -- it is the only thing that makes activation work.
	 * WordPress fires plugins_loaded before it includes a plugin it is
	 * activating, so boot() has not run in that request, 'vitallens_quarter_hour'
	 * is not a schedule WordPress has heard of, and wp_schedule_event() refuses
	 * the event outright. The plugin then sits there, activated, with nothing
	 * on the calendar and no error anywhere, until the user happens to save a
	 * settings form. Registering the interval at the point of use makes the
	 * call safe from every entry point.
	 *
	 * @return bool Whether the sweep is scheduled.
	 */
	public static function schedule() {
		if ( ! has_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) ) ) {
			add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		}

		if ( wp_next_scheduled( self::SWEEP_HOOK ) ) {
			return true;
		}

		return false !== wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SWEEP_SLUG, self::SWEEP_HOOK );
	}

	/**
	 * One tick: check whatever is due, prune, record that we ran.
	 *
	 * @return array The batch summary, so a manual caller can show it.
	 */
	public static function sweep() {
		/*
		 * The migration is otherwise only on admin_init, and cron never fires
		 * that. A site updated in place whose sweep ran before anyone opened
		 * wp-admin wrote its checks against the old schema: record_check()
		 * still returned a check id, so nothing looked wrong, but every
		 * finding and diagnosis insert failed silently and those checks keep
		 * an empty "What is causing this" for good.
		 */
		VitalLens_Store::maybe_upgrade();

		if ( ! VitalLens_Settings::has_key() ) {
			return array(
				'checked'   => 0,
				'failed'    => 0,
				'remaining' => 0,
				'errors'    => array( __( 'No API key saved yet.', 'vitallens' ) => 1 ),
			);
		}

		$summary = VitalLens_Checker::run_batch();

		// Asked before the stamp below is written, so an overdue schedule is
		// judged on the gap that just ended rather than the one starting now.
		VitalLens_Alerts::check_stalled();

		// Written whatever the batch found, including an empty one: this is
		// the proof the schedule is alive, and it has to be distinguishable
		// from a schedule that has quietly stopped. A stamp written only when
		// there was work to do cannot tell those apart.
		update_option( self::LAST_SWEEP_OPTION, time(), false );

		self::maybe_prune();

		return $summary;
	}

	/**
	 * Trim history once a day, not on every tick.
	 *
	 * @return void
	 */
	private static function maybe_prune() {
		$last = (int) get_option( 'vitallens_last_prune', 0 );
		if ( $last > time() - DAY_IN_SECONDS ) {
			return;
		}
		VitalLens_Store::prune( (int) VitalLens_Settings::get( 'retention_days', 365 ) );
		update_option( 'vitallens_last_prune', time(), false );
	}

	/**
	 * How long since a sweep completed, in seconds. Null when never.
	 *
	 * @return int|null
	 */
	public static function seconds_since_sweep() {
		$last = (int) get_option( self::LAST_SWEEP_OPTION, 0 );
		return $last ? max( 0, time() - $last ) : null;
	}

	/**
	 * Whether the schedule looks like it has stopped.
	 *
	 * Twice the configured interval, rather than a fixed number that would be
	 * wrong for anything but a daily check. WP-Cron fires on page visits, so
	 * on a quiet site this is the difference between "all your pages are fine"
	 * and "nobody has looked in a fortnight" — and those two produce an
	 * identical screen unless something says so out loud.
	 *
	 * @return bool
	 */
	public static function is_overdue() {
		$since = self::seconds_since_sweep();
		if ( null === $since ) {
			// Never run, but only alarming once it has had a fair chance.
			return VitalLens_Store::count_active() > 0 && self::installed_longer_than( 2 * HOUR_IN_SECONDS );
		}
		$interval = (int) VitalLens_Settings::get( 'interval_hours', 24 ) * HOUR_IN_SECONDS;
		return $since > ( 2 * $interval );
	}

	/**
	 * Whether the plugin has been installed longer than a given age.
	 *
	 * @param int $seconds Age to compare against.
	 * @return bool
	 */
	private static function installed_longer_than( $seconds ) {
		$installed = (int) get_option( 'vitallens_installed_at', 0 );
		if ( ! $installed ) {
			update_option( 'vitallens_installed_at', time(), false );
			return false;
		}
		return ( time() - $installed ) > $seconds;
	}
}
