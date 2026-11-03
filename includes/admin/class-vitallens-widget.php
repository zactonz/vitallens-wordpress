<?php
/**
 * The WordPress dashboard widget.
 *
 * Registered through wp_add_dashboard_widget(), which is what puts it in
 * Screen Options alongside At a Glance and Activity -- so anyone who does not
 * want it can switch it off where they switch the others off, rather than
 * hunting for a setting of ours.
 *
 * It says the same sentence as the plugin's own dashboard, from the same
 * function, because two places describing one situation is how they end up
 * disagreeing.
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

/**
 * At-a-glance Core Web Vitals on the WordPress dashboard.
 */
class VitalLens_Widget {

	const ID = 'vitallens_overview';

	/**
	 * Register the widget.
	 *
	 * @return void
	 */
	public static function boot() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
	}

	/**
	 * Add it, for users who could act on it.
	 *
	 * The dashboard is shown to every role, so without this check an author
	 * would get a panel full of numbers and no screen to open.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! current_user_can( VitalLens_Admin::CAPABILITY ) ) {
			return;
		}
		wp_add_dashboard_widget(
			self::ID,
			__( 'Core Web Vitals', 'vitallens' ),
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the widget body.
	 *
	 * @return void
	 */
	public static function render() {
		$rows    = VitalLens_Store::latest_for_all();
		$state   = VitalLens_Dashboard_Page::assess( $rows );
		$verdict = VitalLens_Dashboard_Page::verdict( $state );
		$worst   = array_slice( $state['attention'], 0, 3 );
		?>
		<div class="vl-wrap vl-widget">
			<div class="vl-widget__verdict">
				<span class="vl-pill vl-pill--<?php echo esc_attr( $verdict['tone'] ); ?>"><?php echo esc_html( $verdict['pill'] ); ?></span>
				<p class="vl-widget__title"><?php echo esc_html( $verdict['title'] ); ?></p>
			</div>

			<?php if ( $state['tracked'] > 0 ) : ?>
				<div class="vl-widget__counts">
					<?php
					$cells = array(
						array( __( 'Tracked', 'vitallens' ), $state['tracked'], '' ),
						array( __( 'Passing', 'vitallens' ), $state['passing'], $state['passing'] > 0 ? 'good' : '' ),
						array( __( 'Failing', 'vitallens' ), $state['failing'], $state['failing'] > 0 ? 'bad' : '' ),
						array( __( 'Waiting', 'vitallens' ), $state['pending'], '' ),
					);
					foreach ( $cells as $cell ) :
						?>
						<div>
							<span class="vl-cost__v <?php echo $cell[2] ? 'vl-cost__v--' . esc_attr( $cell[2] ) : ''; ?>"><?php echo esc_html( number_format_i18n( $cell[1] ) ); ?></span>
							<span class="vl-cost__k"><?php echo esc_html( $cell[0] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( $worst ) : ?>
				<ul class="vl-widget__list">
					<?php foreach ( $worst as $item ) : ?>
						<?php $row = $item['row']; ?>
						<li>
							<a href="<?php echo esc_url( VitalLens_View::detail_url( $row->tracked_id ) ); ?>">
								<span class="vl-widget__name"><?php echo esc_html( VitalLens_Dashboard_Page::title_for( $row ) ); ?></span>
								<span class="vl-widget__why"><?php echo esc_html( $item['reason'] ); ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<div class="vl-widget__foot">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . VitalLens_Admin::SLUG ) ); ?>">
					<?php esc_html_e( 'Open VitalLens', 'vitallens' ); ?> &rarr;
				</a>
				<?php if ( $state['tracked'] > 0 ) : ?>
					<a href="<?php echo esc_url( VitalLens_Report_Page::url() ); ?>"><?php esc_html_e( 'Report', 'vitallens' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
