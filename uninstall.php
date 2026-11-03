<?php
/**
 * What happens when the plugin is deleted.
 *
 * By default: nothing is thrown away.
 *
 * This file runs from "Delete" in the plugins list and nowhere else -- an
 * update never reaches it. But people delete a plugin as a step in updating
 * it, usually because uploading a zip over an existing folder can fail with
 * "Destination folder already exists" and deleting first is the obvious way
 * round. If deleting also dropped three tables, that detour silently cost
 * them months of history, with no warning and nothing to restore from.
 *
 * So the tables and the settings stay unless the site owner has explicitly
 * asked for them to go, on the Settings screen, before deleting. Re-installing
 * then picks the history straight back up.
 *
 * @package VitalLens
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * The schedule goes either way: an event pointing at code that is no longer
 * on disk is not data worth keeping, it is litter in the cron array.
 */
$vitallens_timestamp = wp_next_scheduled( 'vitallens_sweep' );
while ( $vitallens_timestamp ) {
	wp_unschedule_event( $vitallens_timestamp, 'vitallens_sweep' );
	$vitallens_timestamp = wp_next_scheduled( 'vitallens_sweep' );
}

$vitallens_settings = get_option( 'vitallens_settings', array() );

// Read before anything is deleted, and treat anything but an explicit yes as
// a no -- including a missing option, which is what a half-finished earlier
// uninstall would leave behind.
if ( ! is_array( $vitallens_settings ) || empty( $vitallens_settings['delete_data'] ) ) {
	return;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-vitallens-store.php';

VitalLens_Store::drop_all();

delete_option( 'vitallens_settings' );
delete_option( 'vitallens_db_version' );
delete_option( 'vitallens_last_sweep' );
delete_option( 'vitallens_last_prune' );
delete_option( 'vitallens_installed_at' );
