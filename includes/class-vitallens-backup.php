<?php
/**
 * Snapshots that outlive the plugin folder.
 *
 * Why this exists: uninstall.php used to drop every table, and fixing that
 * only protects a delete that happens while the fixed version is the one on
 * disk. But a delete is what *removes* that version -- so the release
 * carrying the fix could not protect the upgrade that installed it. Someone
 * updating by deleting first lost their history to code that was already
 * gone by the time the new code arrived. No edit here can reach backwards
 * and change what an older uninstall.php does.
 *
 * What it can do is keep a copy somewhere the plugin folder does not reach.
 * These are written to uploads automatically whenever the plugin is switched
 * off, which is the step before every delete, so the worst case becomes a
 * restore rather than a loss.
 *
 * The API key is deliberately left out. Everything else is public information
 * about public URLs; a key is a credential.
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writing, listing and restoring data snapshots.
 */
class VitalLens_Backup {

	const FORMAT = 1;
	const KEEP   = 5;
	const DIRNAME = 'vitallens-backups';

	const ACTION = 'vitallens_backup';
	const NONCE  = 'vitallens_backup';

	/**
	 * Register the admin handlers.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * A signed link for one of the two actions.
	 *
	 * @param string $do   'create' or 'restore'.
	 * @param string $file Snapshot basename, for a restore.
	 * @return string
	 */
	public static function action_url( $do, $file = '' ) {
		$args = array(
			'action' => self::ACTION,
			'do'     => $do,
		);
		if ( '' !== $file ) {
			$args['file'] = rawurlencode( $file );
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::NONCE );
	}

	/**
	 * Run it and go back to the screen the user was on.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! current_user_can( VitalLens_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'vitallens' ), 403 );
		}
		check_admin_referer( self::NONCE );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified immediately above.
		$do   = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$file = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$back = admin_url( 'admin.php?page=' . VitalLens_Admin::SLUG . '-settings' );

		if ( 'restore' === $do ) {
			$result = self::restore( $file );
			$args   = is_wp_error( $result )
				? array( 'vl_msg' => 'restore_failed', 'vl_detail' => rawurlencode( $result->get_error_message() ) )
				: array( 'vl_msg' => 'restored', 'vl_t' => $result['tracked'], 'vl_c' => $result['checks'] );
			wp_safe_redirect( add_query_arg( $args, $back ) );
			exit;
		}

		$result = self::create();
		$args   = is_wp_error( $result )
			? array( 'vl_msg' => 'backup_failed', 'vl_detail' => rawurlencode( $result->get_error_message() ) )
			: array( 'vl_msg' => 'backed_up' );
		wp_safe_redirect( add_query_arg( $args, $back ) );
		exit;
	}

	/**
	 * Where snapshots live.
	 *
	 * Inside uploads, so deleting the plugin directory does not take them with
	 * it and a restore is possible from the WordPress side alone.
	 *
	 * @return string|WP_Error Directory path with a trailing slash.
	 */
	public static function dir() {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'vitallens_uploads', (string) $uploads['error'] );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::DIRNAME . '/';
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'vitallens_mkdir', __( 'Could not create a backup folder inside your uploads directory.', 'vitallens' ) );
		}

		/*
		 * Apache refuses these; nginx ignores .htaccess entirely, which is why
		 * the filenames carry a random component too. Nothing in a snapshot is
		 * a credential, but a list of your URLs is nobody else's business.
		 */
		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			file_put_contents( $dir . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $dir;
	}

	/**
	 * Write a snapshot.
	 *
	 * Streamed a row at a time rather than assembled in memory: forty pages
	 * with a year of daily checks is around sixteen thousand rows, and
	 * json_encode() of all of it at once is how a backup routine becomes the
	 * thing that runs out of memory.
	 *
	 * @return string|WP_Error Path written.
	 */
	public static function create() {
		global $wpdb;

		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$path = $dir . sprintf( 'vitallens-%s-%s.json', gmdate( 'Ymd-His' ), wp_generate_password( 8, false, false ) );

		$handle = fopen( $path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return new WP_Error( 'vitallens_write', __( 'Could not write to the backup folder.', 'vitallens' ) );
		}

		$settings = VitalLens_Settings::all();
		unset( $settings['api_key'] );

		fwrite( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			$handle,
			'{"format":' . self::FORMAT
			. ',"plugin":' . wp_json_encode( VITALLENS_VERSION )
			. ',"site":' . wp_json_encode( home_url() )
			. ',"generated_at":' . wp_json_encode( gmdate( 'c' ) )
			. ',"settings":' . wp_json_encode( $settings )
		);

		foreach ( array( 'tracked', 'checks', 'findings' ) as $set ) {
			$table = call_user_func( array( 'VitalLens_Store', $set . '_table' ) );
			fwrite( $handle, ',"' . $set . '":[' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

			$offset = 0;
			$first  = true;
			do {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d", 500, $offset ),
					ARRAY_A
				);
				if ( ! is_array( $rows ) ) {
					break;
				}
				foreach ( $rows as $row ) {
					fwrite( $handle, ( $first ? '' : ',' ) . wp_json_encode( $row ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					$first = false;
				}
				$offset += 500;
			} while ( count( $rows ) === 500 );

			fwrite( $handle, ']' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}

		fwrite( $handle, '}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		self::prune_files();

		return $path;
	}

	/**
	 * Snapshots on disk, newest first.
	 *
	 * @return array<int, array{file: string, path: string, time: int, size: int, pages: int}>
	 */
	public static function all() {
		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return array();
		}

		$out = array();
		foreach ( (array) glob( $dir . 'vitallens-*.json' ) as $path ) {
			$out[] = array(
				'file' => basename( $path ),
				'path' => $path,
				'time' => (int) filemtime( $path ),
				'size' => (int) filesize( $path ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $b['time'] <=> $a['time'];
			}
		);

		return $out;
	}

	/**
	 * Keep the newest few and drop the rest.
	 *
	 * @return void
	 */
	private static function prune_files() {
		foreach ( array_slice( self::all(), self::KEEP ) as $old ) {
			wp_delete_file( $old['path'] );
		}
	}

	/**
	 * Put a snapshot back.
	 *
	 * Additive. A page already tracked keeps its row, a check already stored
	 * is skipped, so restoring twice -- or restoring onto a site that carried
	 * on measuring -- merges rather than duplicating.
	 *
	 * @param string $file Basename of a snapshot in the backup folder.
	 * @return array|WP_Error Counts restored.
	 */
	public static function restore( $file ) {
		global $wpdb;

		$dir = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		// Basename only, resolved inside our own folder: a path that arrived
		// in a request is not a path to read.
		$path = $dir . basename( (string) $file );
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'vitallens_missing', __( 'That backup is no longer on disk.', 'vitallens' ) );
		}

		$data = json_decode( file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) || empty( $data['format'] ) ) {
			return new WP_Error( 'vitallens_unreadable', __( 'That file is not a VitalLens backup.', 'vitallens' ) );
		}

		// The tables may not exist at all, which is the whole point.
		VitalLens_Store::install();

		$counts = array(
			'tracked'  => 0,
			'checks'   => 0,
			'findings' => 0,
		);

		$tracked_map = array();
		foreach ( (array) ( isset( $data['tracked'] ) ? $data['tracked'] : array() ) as $row ) {
			if ( empty( $row['strategy'] ) ) {
				continue;
			}
			$existing = VitalLens_Store::get_tracked_for( (int) $row['post_id'], (string) $row['strategy'] );
			if ( $existing ) {
				$tracked_map[ (int) $row['id'] ] = (int) $existing->id;
				continue;
			}
			$wpdb->insert(
				VitalLens_Store::tracked_table(),
				array(
					'post_id'         => (int) $row['post_id'],
					'url'             => (string) $row['url'],
					'strategy'        => (string) $row['strategy'],
					'active'          => (int) $row['active'],
					'last_checked_at' => $row['last_checked_at'],
					'created_at'      => empty( $row['created_at'] ) ? current_time( 'mysql', true ) : $row['created_at'],
				)
			);
			$tracked_map[ (int) $row['id'] ] = (int) $wpdb->insert_id;
			++$counts['tracked'];
		}

		// What is already stored, read once, so a second restore is a no-op
		// rather than sixteen thousand SELECTs.
		$seen = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix; the query takes no input at all.
		foreach ( (array) $wpdb->get_results( 'SELECT tracked_id, checked_at FROM ' . VitalLens_Store::checks_table() ) as $have ) {
			$seen[ (int) $have->tracked_id . '|' . $have->checked_at ] = true;
		}

		$columns   = array( 'checked_at', 'field_source', 'score', 'f_lcp', 'f_inp', 'f_cls', 'f_fcp', 'f_ttfb', 'l_lcp', 'l_cls', 'l_tbt', 'l_si', 'l_fcp', 'l_ttfb' );
		$check_map = array();

		foreach ( (array) ( isset( $data['checks'] ) ? $data['checks'] : array() ) as $row ) {
			$tracked_id = isset( $tracked_map[ (int) $row['tracked_id'] ] ) ? $tracked_map[ (int) $row['tracked_id'] ] : 0;
			if ( ! $tracked_id || empty( $row['checked_at'] ) || isset( $seen[ $tracked_id . '|' . $row['checked_at'] ] ) ) {
				continue;
			}

			$insert = array( 'tracked_id' => $tracked_id );
			foreach ( $columns as $column ) {
				$insert[ $column ] = array_key_exists( $column, $row ) ? $row[ $column ] : null;
			}

			$wpdb->insert( VitalLens_Store::checks_table(), $insert );
			$check_map[ (int) $row['id'] ] = (int) $wpdb->insert_id;
			$seen[ $tracked_id . '|' . $row['checked_at'] ] = true;
			++$counts['checks'];
		}

		foreach ( (array) ( isset( $data['findings'] ) ? $data['findings'] : array() ) as $row ) {
			if ( ! isset( $check_map[ (int) $row['check_id'] ] ) ) {
				continue;
			}
			$wpdb->insert(
				VitalLens_Store::findings_table(),
				array(
					'check_id'  => $check_map[ (int) $row['check_id'] ],
					'title'     => (string) $row['title'],
					'saving_ms' => (int) $row['saving_ms'],
					'evidence'  => (string) $row['evidence'],
				),
				array( '%d', '%s', '%d', '%s' )
			);
			++$counts['findings'];
		}

		/*
		 * Settings last, and never the key: the one on this site is the one
		 * that works here, and a snapshot taken elsewhere must not overwrite
		 * it with a blank.
		 */
		if ( ! empty( $data['settings'] ) && is_array( $data['settings'] ) ) {
			$restored = $data['settings'];
			unset( $restored['api_key'] );
			VitalLens_Settings::save( $restored );
		}

		VitalLens_Plugin::schedule();

		return $counts;
	}
}
