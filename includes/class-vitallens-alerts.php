<?php
/**
 * Deciding what is worth an email, and sending it.
 *
 * The rule, learned the expensive way in the product this was ported from:
 * judge a change by how much of the remaining margin it consumed, not by
 * which band it happens to sit in. A band check only speaks once visitors are
 * already feeling it; by then the useful moment has passed.
 *
 * Every send goes through notify() so consent, the daily cooldown and the
 * recipient are settled in one place and a detector added later cannot forget
 * to ask.
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

/**
 * Alert detection and delivery.
 */
class VitalLens_Alerts {

	/**
	 * Hook the detector onto stored checks.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'vitallens_check_recorded', array( __CLASS__, 'evaluate' ), 10, 3 );
	}

	/**
	 * Alert types, and which setting switches each one on.
	 *
	 * @return array<string, array{setting: string, kind: string}>
	 */
	public static function types() {
		return array(
			'failing'    => array(
				'setting' => 'alert_failing',
				'kind'    => 'problem',
			),
			'regression' => array(
				'setting' => 'alert_failing',
				'kind'    => 'problem',
			),
			'headroom'   => array(
				'setting' => 'alert_headroom',
				'kind'    => 'problem',
			),
			'recovered'  => array(
				'setting' => 'alert_recovered',
				'kind'    => 'improvement',
			),
			'stalled'    => array(
				'setting' => 'alert_stalled',
				'kind'    => 'problem',
			),
		);
	}

	/**
	 * Compare a stored check against the one before it.
	 *
	 * @param int    $check_id Stored check id.
	 * @param object $tracked  Tracked row.
	 * @param array  $result   Normalised PSI result.
	 * @return void
	 */
	public static function evaluate( $check_id, $tracked, $result ) {
		$previous = VitalLens_Store::latest_check( (int) $tracked->id, 1 );
		if ( ! $previous ) {
			// With nothing to compare against, a standing failure is
			// indistinguishable from a new one. Saying nothing beats alarming
			// someone about a state that may have been true for months.
			return;
		}
		$current = VitalLens_Store::latest_check( (int) $tracked->id, 0 );
		if ( ! $current ) {
			return;
		}

		// Field data is the only thing worth an alert about real visitors. A
		// page Google has no CrUX for falls back to the lab run, and the email
		// says so rather than borrowing language it has not earned.
		$use_lab = ( VitalLens_PSI_Client::FIELD_NONE === $current->field_source );
		$prefix  = $use_lab ? 'l_' : 'f_';
		$metrics = $use_lab ? array( 'LCP', 'CLS', 'TBT' ) : VitalLens_Thresholds::core_metrics();

		foreach ( $metrics as $metric ) {
			$column = $prefix . strtolower( $metric );
			if ( ! isset( $current->$column ) || null === $current->$column ) {
				continue;
			}
			if ( ! isset( $previous->$column ) || null === $previous->$column ) {
				continue;
			}

			$now  = (float) $current->$column;
			$was  = (float) $previous->$column;

			if ( ! VitalLens_Thresholds::is_material( $metric, $was, $now ) ) {
				continue;
			}

			$type = self::classify( $metric, $was, $now, $use_lab );
			if ( ! $type ) {
				continue;
			}

			self::notify(
				$type,
				$tracked,
				array(
					'metric'   => $metric,
					'was'      => $was,
					'now'      => $now,
					'lab_only' => $use_lab,
					'consumed' => VitalLens_Thresholds::margin_consumed( $metric, $was, $now ),
					'regained' => VitalLens_Thresholds::margin_regained( $metric, $was, $now ),
					'limit'    => VitalLens_Thresholds::good_limit( $metric ),
				)
			);
		}
	}

	/**
	 * Which kind of event a move is, if any.
	 *
	 * The four cases, and why each is drawn where it is:
	 *
	 *   good -> failing      the crossing. Always worth saying.
	 *   failing -> worse     already failing and getting worse. This was the
	 *                        one case the original gate missed entirely.
	 *   good -> good         only when the move ate a third of the margin
	 *                        left, which is what catches a steady drift
	 *                        towards a threshold before it arrives.
	 *   failing -> good      recovered. Opt-in, because good news nobody
	 *                        asked for should start quiet.
	 *
	 * @param string $metric  Metric key.
	 * @param float  $was     Earlier value.
	 * @param float  $now     Later value.
	 * @param bool   $use_lab Whether this is a lab comparison.
	 * @return string|null Alert type, or null when nothing is worth saying.
	 */
	private static function classify( $metric, $was, $now, $use_lab ) {
		$was_verdict = VitalLens_Thresholds::verdict( $metric, $was );
		$now_verdict = VitalLens_Thresholds::verdict( $metric, $now );
		$failing     = array( VitalLens_Thresholds::NEEDS, VitalLens_Thresholds::POOR );

		$was_bad = in_array( $was_verdict, $failing, true );
		$now_bad = in_array( $now_verdict, $failing, true );

		if ( ! $was_bad && $now_bad ) {
			return 'failing';
		}
		if ( $was_bad && $now_bad && $now > $was ) {
			return 'regression';
		}
		if ( $was_bad && ! $now_bad ) {
			return 'recovered';
		}
		if ( ! $was_bad && ! $now_bad ) {
			// A lab number moving inside "good" is one machine's opinion on
			// one load. Not worth an interruption.
			if ( $use_lab ) {
				return null;
			}
			$consumed = VitalLens_Thresholds::margin_consumed( $metric, $was, $now );
			if ( null !== $consumed && $consumed >= 0.33 ) {
				return 'headroom';
			}
		}
		return null;
	}

	/**
	 * Send one alert, if the user asked for this kind and has not had it today.
	 *
	 * @param string $type    Alert type.
	 * @param object $tracked Tracked row.
	 * @param array  $data    Values for the copy.
	 * @return bool Whether mail was sent.
	 */
	public static function notify( $type, $tracked, $data ) {
		$types = self::types();
		if ( ! isset( $types[ $type ] ) ) {
			return false;
		}
		if ( empty( VitalLens_Settings::get( $types[ $type ]['setting'] ) ) ) {
			return false;
		}

		// Per page, per type, per day: a flapping metric cannot turn into a
		// stream of mail, and a second sweep cannot double-send.
		$key = 'vl_sent_' . md5( $type . ':' . (int) $tracked->id . ':' . gmdate( 'Y-m-d' ) );
		if ( get_transient( $key ) ) {
			return false;
		}

		$copy = self::copy( $type, $tracked, $data );
		if ( ! $copy ) {
			return false;
		}

		$to      = VitalLens_Settings::alert_recipient();
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$sent = wp_mail( $to, $copy['subject'], self::wrap( $copy ), $headers );

		if ( $sent ) {
			set_transient( $key, 1, DAY_IN_SECONDS );
		}

		/**
		 * Fires after an alert was attempted.
		 *
		 * @param string $type    Alert type.
		 * @param object $tracked Tracked row.
		 * @param bool   $sent    Whether wp_mail accepted it.
		 */
		do_action( 'vitallens_alert_sent', $type, $tracked, $sent );

		return (bool) $sent;
	}

	/**
	 * Subject and body for each type.
	 *
	 * Subjects name the page and the consequence rather than the mechanism:
	 * someone scanning an inbox needs to know which page and whether it
	 * matters without opening anything.
	 *
	 * @param string $type    Alert type.
	 * @param object $tracked Tracked row.
	 * @param array  $d       Values.
	 * @return array{subject: string, heading: string, lead: string, rows: array}|null
	 */
	private static function copy( $type, $tracked, $d ) {
		$title  = $tracked->post_id ? get_the_title( (int) $tracked->post_id ) : $tracked->url;
		$title  = $title ? $title : $tracked->url;
		$metric = isset( $d['metric'] ) ? $d['metric'] : '';
		$name   = self::metric_name( $metric );
		$was    = VitalLens_Thresholds::format( $metric, isset( $d['was'] ) ? $d['was'] : null );
		$now    = VitalLens_Thresholds::format( $metric, isset( $d['now'] ) ? $d['now'] : null );
		$source = ! empty( $d['lab_only'] )
			? __( 'Measured by a lab test — Google has no real-user data for this page.', 'vitallens' )
			: __( 'Measured against real visitors over the last 28 days.', 'vitallens' );

		switch ( $type ) {
			case 'failing':
				return array(
					'subject' => sprintf(
						/* translators: 1: metric abbreviation, 2: page title. */
						__( '%1$s is now failing on %2$s', 'vitallens' ),
						$metric,
						$title
					),
					'heading' => __( 'Now failing', 'vitallens' ),
					'lead'    => sprintf(
						/* translators: 1: metric name, 2: page title, 3: what the metric means. */
						__( '%1$s on %2$s has crossed out of Google’s “good” range, which means %3$s', 'vitallens' ),
						$name,
						$title,
						self::metric_meaning( $metric )
					),
					'rows'    => array(
						array( __( 'Before', 'vitallens' ), $was ),
						array( __( 'Now', 'vitallens' ), $now ),
						array( __( 'Fails at', 'vitallens' ), VitalLens_Thresholds::format( $metric, $d['limit'] ) ),
					),
					'source'  => $source,
				);

			case 'regression':
				return array(
					'subject' => sprintf(
						/* translators: 1: metric abbreviation, 2: page title. */
						__( '%1$s is getting worse on %2$s', 'vitallens' ),
						$metric,
						$title
					),
					'heading' => __( 'Already failing, and getting worse', 'vitallens' ),
					'lead'    => sprintf(
						/* translators: 1: metric name, 2: page title. */
						__( '%1$s on %2$s was already outside the “good” range, and it has just moved further in the wrong direction.', 'vitallens' ),
						$name,
						$title
					),
					'rows'    => array(
						array( __( 'Before', 'vitallens' ), $was ),
						array( __( 'Now', 'vitallens' ), $now ),
					),
					'source'  => $source,
				);

			case 'headroom':
				$pct = (int) round( ( isset( $d['consumed'] ) ? (float) $d['consumed'] : 0 ) * 100 );
				return array(
					'subject' => sprintf(
						/* translators: 1: metric abbreviation, 2: page title. */
						__( '%1$s is using up its margin on %2$s', 'vitallens' ),
						$metric,
						$title
					),
					'heading' => __( 'Running out of headroom', 'vitallens' ),
					'lead'    => sprintf(
						/* translators: 1: metric name, 2: page title, 3: percentage. */
						__( '%1$s on %2$s is still inside the “good” range, so nothing is failing yet — but this one move spent %3$d%% of the room it had left.', 'vitallens' ),
						$name,
						$title,
						$pct
					),
					'rows'    => array(
						array( __( 'Before', 'vitallens' ), $was ),
						array( __( 'Now', 'vitallens' ), $now ),
						array( __( 'Fails at', 'vitallens' ), VitalLens_Thresholds::format( $metric, $d['limit'] ) ),
						array( __( 'Margin used', 'vitallens' ), $pct . '%' ),
					),
					'source'  => $source,
				);

			case 'recovered':
				return array(
					'subject' => sprintf(
						/* translators: 1: metric abbreviation, 2: page title. */
						__( '%1$s is passing again on %2$s', 'vitallens' ),
						$metric,
						$title
					),
					'heading' => __( 'Passing again', 'vitallens' ),
					'lead'    => sprintf(
						/* translators: 1: metric name, 2: page title. */
						__( '%1$s on %2$s is back inside the “good” range. Whatever changed, it worked — worth noting so it can be repeated if it slips again.', 'vitallens' ),
						$name,
						$title
					),
					'rows'    => array(
						array( __( 'Before', 'vitallens' ), $was ),
						array( __( 'Now', 'vitallens' ), $now ),
					),
					'source'  => $source,
				);

			case 'stalled':
				return array(
					'subject' => sprintf(
						/* translators: %s: site name. */
						__( 'VitalLens has stopped checking %s', 'vitallens' ),
						get_bloginfo( 'name' )
					),
					'heading' => __( 'Monitoring needs attention', 'vitallens' ),
					'lead'    => __( 'No check has completed in twice the scheduled interval. No news from us is not good news here — it means nothing is being watched, so a regression could pass unnoticed.', 'vitallens' ),
					'rows'    => array(
						array( __( 'Last successful run', 'vitallens' ), isset( $d['last'] ) ? $d['last'] : __( 'never', 'vitallens' ) ),
						array( __( 'Most likely cause', 'vitallens' ), __( 'WP-Cron has not fired — the site may be too quiet for it', 'vitallens' ) ),
					),
					'source'  => '',
				);
		}

		return null;
	}

	/**
	 * Put the copy into an email that survives a mail client.
	 *
	 * Tables and inline styles on purpose: this is the only layout that holds
	 * up across Outlook, Gmail and Apple Mail without a build step.
	 *
	 * @param array $copy Copy parts.
	 * @return string
	 */
	private static function wrap( $copy ) {
		$rows = '';
		foreach ( $copy['rows'] as $row ) {
			$rows .= '<tr>'
				. '<td style="padding:8px 16px 8px 0;font-size:14px;color:#5c5f72;">' . esc_html( $row[0] ) . '</td>'
				. '<td style="padding:8px 0;font-size:14px;color:#14141c;font-weight:600;">' . esc_html( $row[1] ) . '</td>'
				. '</tr>';
		}

		$dashboard = admin_url( 'admin.php?page=' . VitalLens_Admin::SLUG );

		return '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:560px;margin:0 auto;padding:28px 24px;color:#14141c;">'
			. '<div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#8d90a1;margin-bottom:10px;">'
			. esc_html( $copy['heading'] ) . '</div>'
			. '<p style="font-size:15px;line-height:1.6;color:#5c5f72;margin:0 0 18px;">' . esc_html( $copy['lead'] ) . '</p>'
			. '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:0 0 18px;">' . $rows . '</table>'
			. ( $copy['source'] ? '<p style="font-size:12px;color:#8d90a1;margin:0 0 20px;line-height:1.6;">' . esc_html( $copy['source'] ) . '</p>' : '' )
			. '<a href="' . esc_url( $dashboard ) . '" style="display:inline-block;background:#4f46e5;color:#fff;font-size:14px;font-weight:600;text-decoration:none;padding:12px 20px;border-radius:10px;">'
			. esc_html__( 'Open VitalLens', 'vitallens' ) . '</a>'
			. '<p style="font-size:11px;color:#8d90a1;margin:22px 0 0;line-height:1.6;">'
			. esc_html__( 'You are getting this because alerts are switched on in VitalLens settings.', 'vitallens' )
			. '</p></div>';
	}

	/**
	 * Check whether the schedule has stopped, and say so once a week.
	 *
	 * Every other alert fires on a change. This one fires on the absence of
	 * one, which is the gap: if checks stop, the screen looks exactly like a
	 * healthy site. Only we can tell those apart.
	 *
	 * @return void
	 */
	public static function check_stalled() {
		if ( empty( VitalLens_Settings::get( 'alert_stalled' ) ) ) {
			return;
		}
		if ( ! VitalLens_Plugin::is_overdue() ) {
			return;
		}

		$key = 'vl_stalled_notice';
		if ( get_transient( $key ) ) {
			return;
		}

		$since = VitalLens_Plugin::seconds_since_sweep();
		$last  = null === $since
			? __( 'never', 'vitallens' )
			: sprintf(
				/* translators: %s: human-readable duration. */
				__( '%s ago', 'vitallens' ),
				human_time_diff( time() - $since, time() )
			);

		$stub = (object) array(
			'id'      => 0,
			'post_id' => 0,
			'url'     => home_url( '/' ),
		);

		if ( self::notify( 'stalled', $stub, array( 'last' => $last ) ) ) {
			// A week, not a day: this exists to break a silence once, not to
			// nag about a schedule somebody has deliberately left alone.
			set_transient( $key, 1, WEEK_IN_SECONDS );
		}
	}

	/**
	 * A metric's full name.
	 *
	 * @param string $metric Metric key.
	 * @return string
	 */
	public static function metric_name( $metric ) {
		$names = array(
			'LCP'  => __( 'Largest Contentful Paint', 'vitallens' ),
			'INP'  => __( 'Interaction to Next Paint', 'vitallens' ),
			'CLS'  => __( 'Cumulative Layout Shift', 'vitallens' ),
			'TBT'  => __( 'Total Blocking Time', 'vitallens' ),
			'FCP'  => __( 'First Contentful Paint', 'vitallens' ),
			'TTFB' => __( 'Time to First Byte', 'vitallens' ),
			'SI'   => __( 'Speed Index', 'vitallens' ),
		);
		return isset( $names[ $metric ] ) ? $names[ $metric ] : $metric;
	}

	/**
	 * What a failing metric means for a visitor, in plain words.
	 *
	 * @param string $metric Metric key.
	 * @return string
	 */
	public static function metric_meaning( $metric ) {
		$meanings = array(
			'LCP'  => __( 'the main content takes too long to appear.', 'vitallens' ),
			'INP'  => __( 'the page feels slow to respond when tapped or clicked.', 'vitallens' ),
			'CLS'  => __( 'the layout moves around while the page loads.', 'vitallens' ),
			'TBT'  => __( 'the page cannot respond while scripts are running.', 'vitallens' ),
			'FCP'  => __( 'the page stays blank longer than it should.', 'vitallens' ),
			'TTFB' => __( 'the server is slow to start responding.', 'vitallens' ),
		);
		return isset( $meanings[ $metric ] ) ? $meanings[ $metric ] : __( 'visitors experience slower performance.', 'vitallens' );
	}
}
