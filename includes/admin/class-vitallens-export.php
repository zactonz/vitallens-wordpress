<?php
/**
 * Taking the data out again.
 *
 * CSV is written here. PDF is not: a plugin that ships a PDF engine ships
 * two or three megabytes of vendored code, a GD and mbstring dependency, and
 * a memory limit a cheap host will not grant -- all to redraw a report the
 * browser can already render perfectly. The PDF route is therefore a
 * print-ready view of the same report (see VitalLens_Report_Page), which the browser
 * saves as a PDF that looks like the screen rather than like 2004.
 *
 * The one case that would justify the engine is mailing a PDF with nobody at
 * a browser, which nothing here does yet.
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

/**
 * CSV exports.
 */
class VitalLens_Export {

	const ACTION = 'vitallens_export';
	const NONCE  = 'vitallens_export';

	/**
	 * Register the handler.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * A signed URL for one of the exports.
	 *
	 * @param string $scope      'all' or 'page'.
	 * @param int    $tracked_id Row id, for the 'page' scope.
	 * @return string
	 */
	public static function url( $scope = 'all', $tracked_id = 0 ) {
		$args = array(
			'action' => self::ACTION,
			'scope'  => $scope,
		);
		if ( $tracked_id ) {
			$args['tracked'] = (int) $tracked_id;
		}
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::NONCE );
	}

	/**
	 * Send the file.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! current_user_can( VitalLens_Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to export this.', 'vitallens' ), 403 );
		}
		check_admin_referer( self::NONCE );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified immediately above.
		$scope = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : 'all';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified immediately above.
		$tracked_id = isset( $_GET['tracked'] ) ? (int) $_GET['tracked'] : 0;

		if ( 'page' === $scope && $tracked_id ) {
			$tracked = VitalLens_Store::get_tracked( $tracked_id );
			if ( ! $tracked ) {
				wp_die( esc_html__( 'That page is not being tracked.', 'vitallens' ), 404 );
			}
			self::send(
				self::filename( sanitize_title( VitalLens_Dashboard_Page::title_for( $tracked ) ) . '-history' ),
				self::history_rows( $tracked )
			);
		}

		self::send( self::filename( 'core-web-vitals' ), self::portfolio_rows() );
	}

	/**
	 * Every tracked page with its newest reading.
	 *
	 * @return array<int, array<int, string|int|float|null>>
	 */
	private static function portfolio_rows() {
		$rows = array(
			array(
				__( 'Page', 'vitallens' ),
				__( 'URL', 'vitallens' ),
				__( 'Device', 'vitallens' ),
				__( 'Checked (UTC)', 'vitallens' ),
				__( 'Data source', 'vitallens' ),
				__( 'Verdict', 'vitallens' ),
				__( 'Lighthouse score', 'vitallens' ),
				__( 'LCP (ms)', 'vitallens' ),
				__( 'INP (ms)', 'vitallens' ),
				__( 'CLS', 'vitallens' ),
				__( 'Lab LCP (ms)', 'vitallens' ),
				__( 'Lab CLS', 'vitallens' ),
				__( 'Lab TBT (ms)', 'vitallens' ),
			),
		);

		foreach ( VitalLens_Store::latest_for_all() as $row ) {
			$rows[] = array(
				VitalLens_Dashboard_Page::title_for( $row ),
				$row->url,
				$row->strategy,
				empty( $row->checked_at ) ? '' : $row->checked_at,
				self::source_label( isset( $row->field_source ) ? $row->field_source : '' ),
				self::row_verdict( $row ),
				self::num( isset( $row->score ) ? $row->score : null ),
				self::num( isset( $row->f_lcp ) ? $row->f_lcp : null ),
				self::num( isset( $row->f_inp ) ? $row->f_inp : null ),
				self::num( isset( $row->f_cls ) ? $row->f_cls : null, 3 ),
				self::num( isset( $row->l_lcp ) ? $row->l_lcp : null ),
				self::num( isset( $row->l_cls ) ? $row->l_cls : null, 3 ),
				self::num( isset( $row->l_tbt ) ? $row->l_tbt : null ),
			);
		}

		return $rows;
	}

	/**
	 * One page's whole stored history.
	 *
	 * @param object $tracked Tracked row.
	 * @return array<int, array<int, string|int|float|null>>
	 */
	private static function history_rows( $tracked ) {
		$rows = array(
			array(
				__( 'Checked (UTC)', 'vitallens' ),
				__( 'Data source', 'vitallens' ),
				__( 'Lighthouse score', 'vitallens' ),
				__( 'LCP (ms)', 'vitallens' ),
				__( 'INP (ms)', 'vitallens' ),
				__( 'CLS', 'vitallens' ),
				__( 'FCP (ms)', 'vitallens' ),
				__( 'TTFB (ms)', 'vitallens' ),
				__( 'Lab LCP (ms)', 'vitallens' ),
				__( 'Lab CLS', 'vitallens' ),
				__( 'Lab TBT (ms)', 'vitallens' ),
				__( 'Lab Speed Index (ms)', 'vitallens' ),
				__( 'Lab FCP (ms)', 'vitallens' ),
				__( 'Lab TTFB (ms)', 'vitallens' ),
			),
		);

		foreach ( VitalLens_Store::checks_for( (int) $tracked->id ) as $check ) {
			$rows[] = array(
				$check->checked_at,
				self::source_label( $check->field_source ),
				self::num( $check->score ),
				self::num( $check->f_lcp ),
				self::num( $check->f_inp ),
				self::num( $check->f_cls, 3 ),
				self::num( $check->f_fcp ),
				self::num( $check->f_ttfb ),
				self::num( $check->l_lcp ),
				self::num( $check->l_cls, 3 ),
				self::num( $check->l_tbt ),
				self::num( $check->l_si ),
				self::num( $check->l_fcp ),
				self::num( $check->l_ttfb ),
			);
		}

		return $rows;
	}

	/**
	 * The worst verdict across whichever metrics this row actually has.
	 *
	 * @param object $row Tracked row joined with its newest check.
	 * @return string
	 */
	private static function row_verdict( $row ) {
		if ( empty( $row->check_id ) ) {
			return __( 'Not checked yet', 'vitallens' );
		}

		$lab_only = ( VitalLens_PSI_Client::FIELD_NONE === $row->field_source );
		$prefix   = $lab_only ? 'l_' : 'f_';
		$metrics  = $lab_only ? array( 'LCP', 'CLS' ) : VitalLens_Thresholds::core_metrics();

		$rank  = array(
			VitalLens_Thresholds::UNKNOWN => 0,
			VitalLens_Thresholds::GOOD    => 1,
			VitalLens_Thresholds::NEEDS   => 2,
			VitalLens_Thresholds::POOR    => 3,
		);
		$worst = VitalLens_Thresholds::UNKNOWN;

		foreach ( $metrics as $metric ) {
			$column = $prefix . strtolower( $metric );
			if ( ! isset( $row->$column ) || null === $row->$column ) {
				continue;
			}
			$verdict = VitalLens_Thresholds::verdict( $metric, (float) $row->$column );
			if ( $rank[ $verdict ] > $rank[ $worst ] ) {
				$worst = $verdict;
			}
		}

		return VitalLens_Thresholds::verdict_label( $worst );
	}

	/**
	 * A data source as a word rather than a slug.
	 *
	 * @param string $source Stored source.
	 * @return string
	 */
	public static function source_label( $source ) {
		switch ( $source ) {
			case VitalLens_PSI_Client::FIELD_PAGE:
				return __( 'Real visitors (this page)', 'vitallens' );
			case VitalLens_PSI_Client::FIELD_ORIGIN:
				return __( 'Real visitors (whole site)', 'vitallens' );
			case VitalLens_PSI_Client::FIELD_NONE:
				return __( 'Lab only', 'vitallens' );
			default:
				return '';
		}
	}

	/**
	 * A number a spreadsheet will read as a number.
	 *
	 * Deliberately not number_format_i18n(): a thousands separator turns
	 * 4,200 into text in every spreadsheet on earth, and a locale that uses a
	 * comma for the decimal point breaks the column separator too.
	 *
	 * @param mixed $value    Raw column value.
	 * @param int   $decimals Decimal places.
	 * @return string
	 */
	private static function num( $value, $decimals = 0 ) {
		if ( null === $value || '' === $value ) {
			return '';
		}
		return number_format( (float) $value, $decimals, '.', '' );
	}

	/**
	 * A filename that says what it is and when it was taken.
	 *
	 * @param string $stem Leading part.
	 * @return string
	 */
	private static function filename( $stem ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return sprintf( 'vitallens-%s-%s-%s.csv', sanitize_title( (string) $host ), $stem, gmdate( 'Y-m-d' ) );
	}

	/**
	 * Stream the rows and stop.
	 *
	 * @param string $filename Download name.
	 * @param array  $rows     Rows, first one being the header.
	 * @return void
	 */
	private static function send( $filename, $rows ) {
		/*
		 * Anything already buffered -- a stray newline after a ?> in some
		 * other plugin, a notice, a debug bar -- would be sent as the first
		 * bytes of the file and corrupt it. A download is one of the few
		 * places where discarding that is the right thing to do.
		 */
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );

		// Excel reads a UTF-8 CSV as the system codepage unless it finds a
		// byte-order mark, which is how page titles with accents arrive as
		// mojibake in the one tool most people will open this with.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		foreach ( $rows as $row ) {
			/*
			 * $escape passed explicitly. PHP 8.4 deprecates relying on its
			 * default, and on a host with display_errors on that notice is
			 * printed into the download -- an export that opens as two lines
			 * of HTML followed by the data. Empty string is also the correct
			 * value: RFC 4180 escapes a quote by doubling it, not with a
			 * backslash, and the backslash default mangles Windows paths.
			 */
			fputcsv( $out, $row, ',', '"', '' );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
