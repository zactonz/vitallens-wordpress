<?php
/**
 * Tables, and every query that touches them.
 *
 * Custom tables rather than postmeta: this is time series, and history is the
 * whole point of the plugin. A meta_key scan per page per chart gets ugly at
 * a few hundred rows and unusable at a few thousand.
 *
 * One deliberate omission -- the raw Lighthouse payload is never stored. It is
 * roughly 800 KB per check, nothing here reads it back, and keeping it is how
 * a table quietly reaches gigabytes on a site that has done nothing wrong.
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

/**
 * Data access for tracked pages, checks and findings.
 */
class VitalLens_Store {

	/**
	 * Table of pages the user asked us to watch.
	 *
	 * @return string
	 */
	public static function tracked_table() {
		global $wpdb;
		return $wpdb->prefix . 'vitallens_tracked';
	}

	/**
	 * Table of individual check results.
	 *
	 * @return string
	 */
	public static function checks_table() {
		global $wpdb;
		return $wpdb->prefix . 'vitallens_checks';
	}

	/**
	 * Table of lab opportunities attached to a check.
	 *
	 * @return string
	 */
	public static function findings_table() {
		global $wpdb;
		return $wpdb->prefix . 'vitallens_findings';
	}

	/**
	 * Create or migrate the tables.
	 *
	 * Runs on activation and whenever VITALLENS_DB_VERSION moves. dbDelta is
	 * fussy: two spaces after PRIMARY KEY, one column per line, lower-case
	 * types. Changing the formatting here will silently stop migrations.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$tracked = self::tracked_table();
		$checks  = self::checks_table();
		$finds   = self::findings_table();

		dbDelta(
			"CREATE TABLE {$tracked} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				url varchar(500) NOT NULL,
				strategy varchar(10) NOT NULL DEFAULT 'mobile',
				active tinyint(1) NOT NULL DEFAULT 1,
				last_checked_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uniq_target (post_id,strategy),
				KEY idx_active (active,last_checked_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$checks} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				tracked_id bigint(20) unsigned NOT NULL,
				checked_at datetime NOT NULL,
				field_source varchar(10) NOT NULL DEFAULT 'none',
				score smallint(6) DEFAULT NULL,
				f_lcp float DEFAULT NULL,
				f_inp float DEFAULT NULL,
				f_cls float DEFAULT NULL,
				f_fcp float DEFAULT NULL,
				f_ttfb float DEFAULT NULL,
				l_lcp float DEFAULT NULL,
				l_cls float DEFAULT NULL,
				l_tbt float DEFAULT NULL,
				l_si float DEFAULT NULL,
				l_fcp float DEFAULT NULL,
				l_ttfb float DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY idx_tracked_time (tracked_id,checked_at),
				KEY idx_time (checked_at)
			) {$charset};"
		);

		/*
		 * One table for both halves of "what the lab run found": the
		 * opportunities, which are savings, and the diagnoses, which name the
		 * element and the cause. They are shown apart and ranked differently,
		 * but they belong to the same check and are always read together, so
		 * a second table would buy nothing but a second join.
		 */
		dbDelta(
			"CREATE TABLE {$finds} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				check_id bigint(20) unsigned NOT NULL,
				kind varchar(16) NOT NULL DEFAULT 'opportunity',
				metric varchar(8) NOT NULL DEFAULT '',
				title varchar(255) NOT NULL,
				cause varchar(255) NOT NULL DEFAULT '',
				label varchar(255) NOT NULL DEFAULT '',
				selector varchar(600) NOT NULL DEFAULT '',
				saving_ms int(11) NOT NULL DEFAULT 0,
				amount float DEFAULT NULL,
				unit varchar(8) NOT NULL DEFAULT '',
				evidence varchar(600) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY idx_check (check_id),
				KEY idx_check_kind (check_id,kind)
			) {$charset};"
		);

		update_option( 'vitallens_db_version', VITALLENS_DB_VERSION, false );
	}

	/**
	 * Bring the schema up to date if the plugin was updated in place.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( 'vitallens_db_version', 0 ) !== (int) VITALLENS_DB_VERSION ) {
			self::install();
		}
	}

	// ----------------------------------------------------------- tracked

	/**
	 * Start watching a post.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $strategy 'mobile' or 'desktop'.
	 * @return int|false Row id, or false on failure.
	 */
	public static function track( $post_id, $strategy = 'mobile' ) {
		global $wpdb;

		$post_id = (int) $post_id;
		$url     = get_permalink( $post_id );
		if ( ! $url ) {
			return false;
		}

		$existing = self::get_tracked_for( $post_id, $strategy );
		if ( $existing ) {
			$wpdb->update(
				self::tracked_table(),
				array(
					'active' => 1,
					'url'    => $url,
				),
				array( 'id' => (int) $existing->id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
			return (int) $existing->id;
		}

		$ok = $wpdb->insert(
			self::tracked_table(),
			array(
				'post_id'    => $post_id,
				'url'        => $url,
				'strategy'   => $strategy,
				'active'     => 1,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Stop watching a post, keeping its history.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $strategy Device.
	 * @return void
	 */
	public static function untrack( $post_id, $strategy = 'mobile' ) {
		global $wpdb;
		$wpdb->update(
			self::tracked_table(),
			array( 'active' => 0 ),
			array(
				'post_id'  => (int) $post_id,
				'strategy' => $strategy,
			),
			array( '%d' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * One tracked row.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $strategy Device.
	 * @return object|null
	 */
	public static function get_tracked_for( $post_id, $strategy = 'mobile' ) {
		global $wpdb;
		$table = self::tracked_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix.
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d AND strategy = %s", (int) $post_id, $strategy )
		);
	}

	/**
	 * Every row we are actively watching.
	 *
	 * @return array<int, object>
	 */
	public static function get_active() {
		global $wpdb;
		$table = self::tracked_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix.
		return $wpdb->get_results( "SELECT * FROM {$table} WHERE active = 1 ORDER BY id ASC" );
	}

	/**
	 * Active rows whose last check is older than the configured interval.
	 *
	 * NULL last_checked_at sorts first so a newly tracked page is measured
	 * promptly rather than queueing behind everything that already has data.
	 *
	 * @param int $interval_hours How stale a reading has to be to count as due.
	 * @param int $limit          How many to take.
	 * @return array<int, object>
	 */
	public static function get_due( $interval_hours = 24, $limit = 5 ) {
		global $wpdb;
		$table = self::tracked_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE active = 1
				   AND ( last_checked_at IS NULL
				         OR last_checked_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d HOUR) )
				 ORDER BY last_checked_at IS NULL DESC, last_checked_at ASC
				 LIMIT %d",
				(int) $interval_hours,
				(int) $limit
			)
		);
	}

	/**
	 * Mark a page as attempted without storing a result.
	 *
	 * Used when a check fails, so one URL Google keeps refusing cannot sit at
	 * the head of the queue and starve every page behind it.
	 *
	 * @param int $tracked_id Tracked row id.
	 * @return void
	 */
	public static function touch( $tracked_id ) {
		global $wpdb;
		$wpdb->update(
			self::tracked_table(),
			array( 'last_checked_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $tracked_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Every active page with its newest check attached.
	 *
	 * One query rather than one per row: the dashboard and the posts column
	 * both need the whole set, and a correlated lookup inside a render loop is
	 * how an admin screen quietly becomes slow as a site grows.
	 *
	 * @return array<int, object>
	 */
	public static function latest_for_all() {
		global $wpdb;
		$tracked = self::tracked_table();
		$checks  = self::checks_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix, no user input.
		return $wpdb->get_results(
			"SELECT t.id AS tracked_id, t.post_id, t.url, t.strategy, t.last_checked_at,
			        c.id AS check_id, c.checked_at, c.field_source, c.score,
			        c.f_lcp, c.f_inp, c.f_cls, c.l_lcp, c.l_cls, c.l_tbt
			   FROM {$tracked} t
			   LEFT JOIN {$checks} c
			          ON c.id = (
			             SELECT id FROM {$checks}
			              WHERE tracked_id = t.id
			              ORDER BY checked_at DESC, id DESC
			              LIMIT 1
			          )
			  WHERE t.active = 1
			  ORDER BY t.id ASC"
		);
	}

	/**
	 * The newest check for a set of post ids, keyed by post id.
	 *
	 * Used by the Posts list column, which must not run a query per row.
	 *
	 * @param int[] $post_ids Post ids on screen.
	 * @return array<int, array<string, object>> post_id => strategy => row.
	 */
	public static function latest_for_posts( $post_ids ) {
		global $wpdb;

		$post_ids = array_values( array_filter( array_map( 'intval', (array) $post_ids ) ) );
		if ( ! $post_ids ) {
			return array();
		}

		$tracked = self::tracked_table();
		$checks  = self::checks_table();
		$in      = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above, values bound below.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.id AS tracked_id, t.post_id, t.strategy,
				        c.field_source, c.score, c.f_lcp, c.f_inp, c.f_cls,
				        c.l_lcp, c.l_cls, c.l_tbt
				   FROM {$tracked} t
				   LEFT JOIN {$checks} c
				          ON c.id = (
				             SELECT id FROM {$checks}
				              WHERE tracked_id = t.id
				              ORDER BY checked_at DESC, id DESC
				              LIMIT 1
				          )
				  WHERE t.active = 1 AND t.post_id IN ({$in})",
				$post_ids
			)
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->post_id ][ $row->strategy ] = $row;
		}
		return $out;
	}

	/**
	 * One tracked row by id.
	 *
	 * @param int $tracked_id Row id.
	 * @return object|null
	 */
	public static function get_tracked( $tracked_id ) {
		global $wpdb;
		$table = self::tracked_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $tracked_id )
		);
	}

	/**
	 * How many pages are being watched.
	 *
	 * @return int
	 */
	public static function count_active() {
		global $wpdb;
		$table = self::tracked_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE active = 1" );
	}

	// ------------------------------------------------------------ checks

	/**
	 * Store one result.
	 *
	 * @param int   $tracked_id Row in the tracked table.
	 * @param array $result     Normalised VitalLens_PSI_Client result.
	 * @return int|false Check id.
	 */
	public static function record_check( $tracked_id, $result ) {
		global $wpdb;

		$field = isset( $result['field'] ) ? $result['field'] : array();
		$lab   = isset( $result['lab'] ) ? $result['lab'] : array();

		$pick = static function ( $set, $key ) {
			return isset( $set[ $key ] ) ? (float) $set[ $key ] : null;
		};

		$ok = $wpdb->insert(
			self::checks_table(),
			array(
				'tracked_id'   => (int) $tracked_id,
				'checked_at'   => gmdate( 'Y-m-d H:i:s', isset( $result['checked_at'] ) ? (int) $result['checked_at'] : time() ),
				'field_source' => isset( $result['field_source'] ) ? $result['field_source'] : VitalLens_PSI_Client::FIELD_NONE,
				'score'        => isset( $result['score'] ) ? $result['score'] : null,
				'f_lcp'        => $pick( $field, 'LCP' ),
				'f_inp'        => $pick( $field, 'INP' ),
				'f_cls'        => $pick( $field, 'CLS' ),
				'f_fcp'        => $pick( $field, 'FCP' ),
				'f_ttfb'       => $pick( $field, 'TTFB' ),
				'l_lcp'        => $pick( $lab, 'LCP' ),
				'l_cls'        => $pick( $lab, 'CLS' ),
				'l_tbt'        => $pick( $lab, 'TBT' ),
				'l_si'         => $pick( $lab, 'SI' ),
				'l_fcp'        => $pick( $lab, 'FCP' ),
				'l_ttfb'       => $pick( $lab, 'TTFB' ),
			)
		);

		if ( ! $ok ) {
			return false;
		}
		$check_id = (int) $wpdb->insert_id;

		if ( ! empty( $result['findings'] ) && is_array( $result['findings'] ) ) {
			foreach ( $result['findings'] as $finding ) {
				$wpdb->insert(
					self::findings_table(),
					array(
						'check_id'  => $check_id,
						'kind'      => 'opportunity',
						'title'     => isset( $finding['title'] ) ? $finding['title'] : '',
						'saving_ms' => isset( $finding['saving_ms'] ) ? (int) $finding['saving_ms'] : 0,
						'evidence'  => isset( $finding['evidence'] ) ? $finding['evidence'] : '',
					)
				);
			}
		}

		if ( ! empty( $result['diagnoses'] ) && is_array( $result['diagnoses'] ) ) {
			foreach ( $result['diagnoses'] as $d ) {
				$wpdb->insert(
					self::findings_table(),
					array(
						'check_id' => $check_id,
						'kind'     => isset( $d['kind'] ) ? $d['kind'] : 'element',
						'metric'   => isset( $d['metric'] ) ? $d['metric'] : '',
						'title'    => isset( $d['title'] ) ? $d['title'] : '',
						'cause'    => isset( $d['cause'] ) ? $d['cause'] : '',
						'label'    => isset( $d['label'] ) ? $d['label'] : '',
						'selector' => isset( $d['selector'] ) ? $d['selector'] : '',
						'amount'   => isset( $d['amount'] ) ? $d['amount'] : null,
						'unit'     => isset( $d['unit'] ) ? $d['unit'] : '',
						'evidence' => isset( $d['evidence'] ) ? $d['evidence'] : '',
					)
				);
			}
		}

		$wpdb->update(
			self::tracked_table(),
			array( 'last_checked_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $tracked_id ),
			array( '%s' ),
			array( '%d' )
		);

		return $check_id;
	}

	/**
	 * The most recent check for a tracked page.
	 *
	 * @param int $tracked_id Tracked row id.
	 * @param int $offset     1 returns the one before the newest.
	 * @return object|null
	 */
	public static function latest_check( $tracked_id, $offset = 0 ) {
		global $wpdb;
		$table = self::checks_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix.
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE tracked_id = %d ORDER BY checked_at DESC, id DESC LIMIT 1 OFFSET %d",
				(int) $tracked_id,
				(int) $offset
			)
		);
	}

	/**
	 * Readings for one metric over time, oldest first.
	 *
	 * @param int    $tracked_id Tracked row id.
	 * @param string $column     Column name, e.g. 'f_inp'.
	 * @param int    $days       Window.
	 * @return array<int, object>
	 */
	public static function history( $tracked_id, $column, $days = 90 ) {
		global $wpdb;

		$allowed = array( 'score', 'f_lcp', 'f_inp', 'f_cls', 'f_fcp', 'f_ttfb', 'l_lcp', 'l_cls', 'l_tbt', 'l_si', 'l_fcp', 'l_ttfb' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return array();
		}

		$table = self::checks_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- column is allow-listed above, table from $wpdb->prefix.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT checked_at, {$column} AS value
				 FROM {$table}
				 WHERE tracked_id = %d
				   AND {$column} IS NOT NULL
				   AND checked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
				 ORDER BY checked_at ASC",
				(int) $tracked_id,
				(int) $days
			)
		);
	}

	/**
	 * Every stored check for one page, newest first.
	 *
	 * Whole rows rather than one column: history() answers a chart, this
	 * answers an export, and an export that quietly drops ten of fourteen
	 * columns is worse than no export.
	 *
	 * @param int $tracked_id Tracked row id.
	 * @param int $days       How far back to go. Zero means everything.
	 * @return array<int, object>
	 */
	public static function checks_for( $tracked_id, $days = 0 ) {
		global $wpdb;
		$table = self::checks_table();

		if ( $days > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					 WHERE tracked_id = %d
					   AND checked_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
					 ORDER BY checked_at DESC, id DESC",
					(int) $tracked_id,
					(int) $days
				)
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE tracked_id = %d ORDER BY checked_at DESC, id DESC",
				(int) $tracked_id
			)
		);
	}

	/**
	 * The opportunities stored against one check.
	 *
	 * @param int $check_id Check id.
	 * @return array<int, object>
	 */
	public static function findings_for( $check_id ) {
		global $wpdb;
		$table = self::findings_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix.
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE check_id = %d AND kind = 'opportunity' ORDER BY saving_ms DESC", (int) $check_id )
		);
	}

	/**
	 * What the lab run blamed, for one check.
	 *
	 * Ordered by how much of its metric each one accounts for, with CLS
	 * scaled up: a 0.16 shift and a 160ms task are both worth reading, and
	 * sorting the raw numbers together would bury every shift ever recorded.
	 *
	 * @param int $check_id Check id.
	 * @return array<int, object>
	 */
	public static function diagnoses_for( $check_id ) {
		global $wpdb;
		$table = self::findings_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				  WHERE check_id = %d AND kind <> 'opportunity'
				  ORDER BY CASE unit WHEN 'cls' THEN amount * 10000 ELSE amount END DESC, id ASC",
				(int) $check_id
			)
		);
	}

	/**
	 * Drop checks older than the retention window.
	 *
	 * @param int $days Keep this many days. Zero means keep everything.
	 * @return int Rows removed.
	 */
	public static function prune( $days ) {
		global $wpdb;
		$days = (int) $days;
		if ( $days <= 0 ) {
			return 0;
		}

		$checks = self::checks_table();
		$finds  = self::findings_table();

		// Findings first, or their check rows are gone and they are orphans.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE f FROM {$finds} f
				 INNER JOIN {$checks} c ON c.id = f.check_id
				 WHERE c.checked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$days
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$checks} WHERE checked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$days
			)
		);
	}

	/**
	 * Remove every table. Only ever called from uninstall.php.
	 *
	 * @return void
	 */
	public static function drop_all() {
		global $wpdb;
		foreach ( array( self::findings_table(), self::checks_table(), self::tracked_table() ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- uninstall only.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}
}
