<?php
/**
 * Plugin Name:       VitalLens
 * Plugin URI:        https://vitallens.zactonz.com/
 * Description:       Tracks Core Web Vitals for the pages you choose — real-user data where Google has it, a lab test where it doesn't — and keeps the history so you can see what changed.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Zactonz Technologies
 * Author URI:        https://zactonz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vitallens
 *
 * @package VitalLens
 */

defined( 'ABSPATH' ) || exit;

define( 'VITALLENS_VERSION', '1.0.0' );
define( 'VITALLENS_FILE', __FILE__ );
define( 'VITALLENS_DIR', plugin_dir_path( __FILE__ ) );
define( 'VITALLENS_URL', plugin_dir_url( __FILE__ ) );

/**
 * The schema version, bumped whenever a table changes.
 *
 * Kept separate from the plugin version so a release that touches no table
 * does not make every site re-run dbDelta on the next admin request.
 */
define( 'VITALLENS_DB_VERSION', 2 );

require_once VITALLENS_DIR . 'includes/class-vitallens-thresholds.php';
require_once VITALLENS_DIR . 'includes/class-vitallens-psi-client.php';
require_once VITALLENS_DIR . 'includes/class-vitallens-store.php';
require_once VITALLENS_DIR . 'includes/class-vitallens-settings.php';
require_once VITALLENS_DIR . 'includes/class-vitallens-checker.php';
require_once VITALLENS_DIR . 'includes/class-vitallens-alerts.php';
require_once VITALLENS_DIR . 'includes/class-vitallens-view.php';
require_once VITALLENS_DIR . 'includes/class-vitallens-backup.php';
require_once VITALLENS_DIR . 'includes/class-vitallens-plugin.php';

/*
 * VitalLens_Admin holds the menu slug and the capability that the alert mail,
 * the shared view helpers and the backup handler all read. Alerts are sent
 * from cron and a deactivation snapshot can be written from WP-CLI, and in
 * neither is is_admin() true -- so loading this only in the admin made those
 * paths fatal with "Class VitalLens_Admin not found". The file declares a
 * class and nothing else; VitalLens_Plugin::boot() keeps its own is_admin()
 * guard around VitalLens_Admin::boot(), so nothing extra is registered on a
 * front-end request.
 */
require_once VITALLENS_DIR . 'includes/admin/class-vitallens-admin.php';

if ( is_admin() ) {
	require_once VITALLENS_DIR . 'includes/admin/class-vitallens-dashboard-page.php';
	require_once VITALLENS_DIR . 'includes/admin/class-vitallens-tracked-page.php';
	require_once VITALLENS_DIR . 'includes/admin/class-vitallens-detail-page.php';
	require_once VITALLENS_DIR . 'includes/admin/class-vitallens-settings-page.php';
	require_once VITALLENS_DIR . 'includes/admin/class-vitallens-posts-column.php';
	require_once VITALLENS_DIR . 'includes/admin/class-vitallens-report-page.php';
	require_once VITALLENS_DIR . 'includes/admin/class-vitallens-export.php';
	require_once VITALLENS_DIR . 'includes/admin/class-vitallens-widget.php';
}

register_activation_hook( __FILE__, array( 'VitalLens_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VitalLens_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'VitalLens_Plugin', 'boot' ) );
