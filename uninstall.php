<?php
/**
 * Uninstall handler — removes all plugin data.
 *
 * @package MediaUsageScanner
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mus_deletion_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mus_restore_log" );

delete_option( 'mus_enable_cron' );
delete_option( 'mus_cron_email' );
delete_option( 'mus_backup_retention_days' );
delete_option( 'mus_scan_theme_files' );
delete_option( 'mus_db_version' );
delete_option( 'mus_disabled_image_sizes' );
delete_option( 'mus_disable_srcset' );
delete_option( 'mus_batch_delay_ms' );

wp_clear_scheduled_hook( 'mus_scheduled_scan' );

$upload_dir = wp_upload_dir();
$mus_dir    = trailingslashit( $upload_dir['basedir'] ) . 'media-usage-scanner';

if ( is_dir( $mus_dir ) ) {
	$files = glob( trailingslashit( $mus_dir ) . '*' );
	if ( is_array( $files ) ) {
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
	rmdir( $mus_dir );
}
