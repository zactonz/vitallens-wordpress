<?php
/**
 * Admin menu, assets, and the two actions the settings screen needs.
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin bootstrap.
 */
class VitalLens_Admin {

	const CAPABILITY = 'manage_options';
	const SLUG       = 'vitallens';

	/**
	 * Titles for screens that have no menu entry to read one from.
	 *
	 * @var array<string, string>
	 */
	private static $hidden_titles = array();

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_vitallens_test_key', array( __CLASS__, 'ajax_test_key' ) );
		add_action( 'wp_ajax_vitallens_run_now', array( __CLASS__, 'ajax_run_now' ) );
		add_action( 'wp_ajax_vitallens_check_one', array( __CLASS__, 'ajax_check_one' ) );
	}

	/**
	 * Add the menu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page(
			__( 'VitalLens', 'vitallens' ),
			__( 'VitalLens', 'vitallens' ),
			self::CAPABILITY,
			self::SLUG,
			array( 'VitalLens_Dashboard_Page', 'render' ),
			self::menu_icon(),
			58
		);

		$pages = array(
			array( self::SLUG, __( 'Dashboard', 'vitallens' ), array( 'VitalLens_Dashboard_Page', 'render' ) ),
			array( self::SLUG . '-tracked', __( 'Tracked pages', 'vitallens' ), array( 'VitalLens_Tracked_Page', 'render' ) ),
			array( self::SLUG . '-settings', __( 'Settings', 'vitallens' ), array( 'VitalLens_Settings_Page', 'render' ) ),
		);
		foreach ( $pages as $page ) {
			add_submenu_page( self::SLUG, $page[1], $page[1], self::CAPABILITY, $page[0], $page[2] );
		}

		// Routable but not listed: both are reached from a link, and a menu
		// entry for "whichever page you last clicked" is noise.
		self::add_hidden_page( self::SLUG . '-page', __( 'Page report', 'vitallens' ), array( 'VitalLens_Detail_Page', 'render' ) );
		self::add_hidden_page( self::SLUG . '-report', __( 'Core Web Vitals report', 'vitallens' ), array( 'VitalLens_Report_Page', 'render' ) );
	}

	/**
	 * A screen with a route but no menu entry.
	 *
	 * A page with no parent is not in $submenu, so get_admin_page_title()
	 * finds nothing and leaves $title null. admin-header.php then hands that
	 * null to strip_tags(), which on PHP 8.1 and up prints a deprecation
	 * notice across the top of the screen -- and leaves the browser tab
	 * titled " < Site Name". Setting the title on the load- hook, which runs
	 * before the header is rendered, fixes both.
	 *
	 * @param string   $slug     Page slug.
	 * @param string   $title    Screen title.
	 * @param callable $callback Renderer.
	 * @return void
	 */
	private static function add_hidden_page( $slug, $title, $callback ) {
		$hook = add_submenu_page( null, $title, $title, self::CAPABILITY, $slug, $callback );
		if ( ! $hook ) {
			return;
		}
		self::$hidden_titles[ $hook ] = $title;
		add_action(
			'load-' . $hook,
			static function () use ( $hook ) {
				$GLOBALS['title'] = VitalLens_Admin::hidden_title( $hook );
			}
		);
	}

	/**
	 * The title registered for a hidden screen.
	 *
	 * @param string $hook Page hook.
	 * @return string
	 */
	public static function hidden_title( $hook ) {
		return isset( self::$hidden_titles[ $hook ] ) ? self::$hidden_titles[ $hook ] : '';
	}

	/**
	 * The brand mark, inline so it inherits the admin menu's colour scheme.
	 *
	 * @return string A data URI.
	 */
	public static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40">'
			. '<rect x="6" y="21" width="5" height="12" rx="2" fill="black"/>'
			. '<rect x="13" y="11" width="5" height="22" rx="2" fill="black"/>'
			. '<rect x="20" y="16" width="5" height="17" rx="2" fill="black"/>'
			. '<rect x="27" y="24" width="5" height="9" rx="2" fill="black"/>'
			. '</svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- required form for a menu icon data URI.
	}

	/**
	 * Load our stylesheet only on our own screens.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function assets( $hook ) {
		/*
		 * Our own screens, plus the two WordPress screens we render into: the
		 * post lists, where the column lives, and the dashboard, where the
		 * widget does. Neither needs the script.
		 */
		$style_only = in_array( $hook, array( 'edit.php', 'index.php' ), true );
		if ( false === strpos( (string) $hook, self::SLUG ) && ! $style_only ) {
			return;
		}
		wp_enqueue_style(
			'vitallens-admin',
			VITALLENS_URL . 'assets/admin.css',
			array(),
			VITALLENS_VERSION
		);
		if ( $style_only ) {
			return;
		}

		wp_enqueue_script(
			'vitallens-admin',
			VITALLENS_URL . 'assets/admin.js',
			array(),
			VITALLENS_VERSION,
			true
		);
		wp_localize_script(
			'vitallens-admin',
			'vitallensAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'vitallens_admin' ),
				'i18n'    => array(
					'testing'       => __( 'Testing…', 'vitallens' ),
					'testingDetail' => __( 'Asking Google to score your home page…', 'vitallens' ),
					'running'       => __( 'Running…', 'vitallens' ),
					'runningDetail' => __( 'Checking the pages that are due. Google takes 10–30 seconds each, so this can run for a minute.', 'vitallens' ),
					'checking'       => __( 'Checking…', 'vitallens' ),
					'checkingDetail' => __( 'Asking Google to measure this page now. It usually takes 10–30 seconds.', 'vitallens' ),
					'copied'        => __( 'Copied', 'vitallens' ),
					'copy'          => __( 'Copy', 'vitallens' ),
					'failed'        => __( 'Something went wrong. Try again.', 'vitallens' ),
				),
			)
		);
	}

	/**
	 * Check that the saved key actually works.
	 *
	 * Probes the site's own home page: it is guaranteed to exist, and a key
	 * that can read it can read anything else we will ask for.
	 *
	 * @return void
	 */
	public static function ajax_test_key() {
		check_ajax_referer( 'vitallens_admin', 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'vitallens' ) ), 403 );
		}

		// Test what was typed if anything was, so the button answers for the
		// key in front of you rather than the one saved an hour ago.
		$typed = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$key   = ( '' !== $typed && false === strpos( $typed, '•' ) ) ? $typed : (string) VitalLens_Settings::get( 'api_key', '' );

		if ( '' === trim( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Paste a key first.', 'vitallens' ) ) );
		}

		$client = new VitalLens_PSI_Client( $key );
		$result = $client->test( home_url( '/' ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => __( 'Key accepted — Google answered for your home page.', 'vitallens' ) ) );
	}

	/**
	 * Re-check one tracked page on demand.
	 *
	 * The sweep only looks at pages whose interval has elapsed, which is right
	 * for a schedule and wrong for someone who has just fixed a page and wants
	 * to know whether it worked. This goes straight to
	 * VitalLens_Checker::run_one(), so being early is not a reason to refuse.
	 *
	 * @return void
	 */
	public static function ajax_check_one() {
		check_ajax_referer( 'vitallens_admin', 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'vitallens' ) ), 403 );
		}

		if ( ! VitalLens_Settings::has_key() ) {
			wp_send_json_success(
				array(
					'message' => __( 'No API key saved yet — nothing can be checked until one is.', 'vitallens' ),
					'tone'    => 'bad',
				)
			);
		}

		$tracked_id = isset( $_POST['tracked'] ) ? absint( wp_unslash( $_POST['tracked'] ) ) : 0;
		$tracked    = $tracked_id ? VitalLens_Store::get_tracked( $tracked_id ) : null;

		if ( ! $tracked ) {
			wp_send_json_error( array( 'message' => __( 'That page is not being tracked.', 'vitallens' ) ), 404 );
		}

		$result = VitalLens_Checker::run_one( $tracked );

		if ( is_wp_error( $result ) ) {
			/*
			 * Google's own wording, not ours: "quota exceeded" or "that URL is
			 * not reachable" is the thing the person needs to read, and
			 * flattening it to "check failed" throws that away.
			 */
			wp_send_json_success(
				array(
					'message' => $result->get_error_message(),
					'tone'    => 'bad',
				)
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Checked. Reloading with the new numbers…', 'vitallens' ),
				'tone'    => 'ok',
				'reload'  => true,
			)
		);
	}

	/**
	 * Run one sweep by hand.
	 *
	 * @return void
	 */
	public static function ajax_run_now() {
		check_ajax_referer( 'vitallens_admin', 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'vitallens' ) ), 403 );
		}

		if ( ! VitalLens_Settings::has_key() ) {
			wp_send_json_success(
				array(
					'message' => __( 'No API key saved yet — nothing can be checked until one is.', 'vitallens' ),
					'tone'    => 'bad',
				)
			);
		}

		$summary = VitalLens_Plugin::sweep();

		if ( 0 === $summary['checked'] && 0 === $summary['failed'] ) {
			$message = VitalLens_Store::count_active() > 0
				? __( 'Nothing was due. Every tracked page has been checked within its interval.', 'vitallens' )
				: __( 'No pages are being tracked yet.', 'vitallens' );
			wp_send_json_success(
				array(
					'message' => $message,
					'tone'    => 'ok',
				)
			);
		}

		$message = sprintf(
			/* translators: 1: number checked, 2: number that failed, 3: number still waiting. */
			_n( 'Checked %1$d page. %2$d failed, %3$d still due.', 'Checked %1$d pages. %2$d failed, %3$d still due.', $summary['checked'], 'vitallens' ),
			$summary['checked'],
			$summary['failed'],
			$summary['remaining']
		);

		$errors = array();
		foreach ( array_slice( $summary['errors'], 0, 3, true ) as $reason => $count ) {
			$errors[] = $count > 1
				? sprintf(
					/* translators: 1: error message, 2: how many pages hit it. */
					__( '%1$s (%2$d pages)', 'vitallens' ),
					$reason,
					$count
				)
				: $reason;
		}

		wp_send_json_success(
			array(
				'message' => $message,
				'errors'  => $errors,
				// Nothing measured and something broken is a failure, whatever
				// the transport says. Reporting it in the "it worked" colour is
				// how a dead API key goes unnoticed for a fortnight.
				'tone'    => ( 0 === $summary['checked'] && $summary['failed'] > 0 ) ? 'bad' : 'ok',
			)
		);
	}

	/**
	 * The PHP binary a cron entry on this host should use.
	 *
	 * PHP_BINARY is what is actually running, which on cPanel is the
	 * EasyApache build rather than whatever `which php` reports — a real trap
	 * that silently produces a cron job doing nothing.
	 *
	 * @return string
	 */
	public static function php_binary() {
		if ( defined( 'PHP_BINARY' ) && PHP_BINARY && is_string( PHP_BINARY ) ) {
			return PHP_BINARY;
		}
		return '/usr/local/bin/php';
	}

	/**
	 * The cron command for this install, ready to paste.
	 *
	 * @return string
	 */
	public static function cron_command() {
		return sprintf(
			'*/15 * * * * %s %swp-cron.php >/dev/null 2>&1',
			self::php_binary(),
			trailingslashit( ABSPATH )
		);
	}

	/**
	 * The WP-CLI form, for hosts that have it.
	 *
	 * @return string
	 */
	public static function cron_command_cli() {
		return sprintf(
			'*/15 * * * * cd %s && wp cron event run --due-now >/dev/null 2>&1',
			untrailingslashit( ABSPATH )
		);
	}
}
