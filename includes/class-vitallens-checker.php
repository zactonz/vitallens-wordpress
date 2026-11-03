<?php
/**
 * Running a check and writing down what came back.
 *
 * Deliberately thin: fetch, store, report. The judgement about whether a
 * result is worth telling anyone about belongs to the alert layer, and the
 * numbers themselves belong to VitalLens_Thresholds.
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates checks for tracked pages.
 */
class VitalLens_Checker {

	/**
	 * Check one tracked page.
	 *
	 * @param object $tracked Row from the tracked table.
	 * @return int|WP_Error Check id on success.
	 */
	public static function run_one( $tracked ) {
		if ( ! $tracked || empty( $tracked->url ) ) {
			return new WP_Error( 'vitallens_no_target', __( 'That page is not being tracked.', 'vitallens' ) );
		}

		$client = new VitalLens_PSI_Client( (string) VitalLens_Settings::get( 'api_key', '' ) );
		$result = $client->fetch( (string) $tracked->url, (string) $tracked->strategy );

		if ( is_wp_error( $result ) ) {
			// A failed check is not a result. Writing a row of nulls would put
			// a hole in the history chart that looks exactly like a page that
			// suddenly stopped having metrics.
			return $result;
		}

		$check_id = VitalLens_Store::record_check( (int) $tracked->id, $result );
		if ( ! $check_id ) {
			return new WP_Error( 'vitallens_store_failed', __( 'The result came back but could not be saved.', 'vitallens' ) );
		}

		/**
		 * Fires after a check has been stored.
		 *
		 * @param int    $check_id Stored check id.
		 * @param object $tracked  The tracked row.
		 * @param array  $result   Normalised PSI result.
		 */
		do_action( 'vitallens_check_recorded', $check_id, $tracked, $result );

		return $check_id;
	}

	/**
	 * Work through one batch of pages that are due.
	 *
	 * Bounded on purpose. PSI takes 10-30 seconds per call, so a site with
	 * forty tracked pages cannot be checked inside one request without either
	 * timing out or holding a PHP worker open for twenty minutes. The sweep
	 * takes a few, records them, and leaves the rest for the next tick.
	 *
	 * @return array{checked: int, failed: int, remaining: int, errors: array<string, int>}
	 */
	public static function run_batch() {
		$settings = VitalLens_Settings::all();
		$interval = (int) $settings['interval_hours'];
		$batch    = (int) $settings['batch_size'];

		$due = VitalLens_Store::get_due( $interval, $batch );

		$summary = array(
			'checked'   => 0,
			'failed'    => 0,
			'remaining' => 0,
			'errors'    => array(),
		);

		foreach ( $due as $tracked ) {
			$result = self::run_one( $tracked );
			if ( is_wp_error( $result ) ) {
				++$summary['failed'];

				/*
				 * Grouped by reason rather than listed per URL. One rejected
				 * key fails every page in the batch, and five copies of the
				 * same sentence buries the one thing worth reading.
				 */
				$reason = $result->get_error_message();
				if ( ! isset( $summary['errors'][ $reason ] ) ) {
					$summary['errors'][ $reason ] = 0;
				}
				++$summary['errors'][ $reason ];

				// Push it to the back of the queue either way, so one URL that
				// Google refuses cannot hold up every page behind it.
				VitalLens_Store::touch( (int) $tracked->id );
				continue;
			}
			++$summary['checked'];
		}

		$summary['remaining'] = count( VitalLens_Store::get_due( $interval, 500 ) );

		return $summary;
	}
}
