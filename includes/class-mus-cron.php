<?php
/**
 * Scheduled (weekly) background scan with email report.
 *
 * @package MediaUsageScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUS_Cron {

	const HOOK = 'mus_scheduled_scan';

	public function __construct() {
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Schedule the weekly event (called on activation or when the setting is enabled).
	 */
	public static function activate() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::HOOK );
		}
	}

	/**
	 * Remove the scheduled event (called on deactivation or when disabled).
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * Execute the scheduled scan and send a summary email.
	 */
	public function run() {
		if ( ! get_option( 'mus_enable_cron', false ) ) {
			return;
		}

		$retention = (int) get_option( 'mus_backup_retention_days', 30 );
		if ( $retention > 0 ) {
			MUS_Exporter::purge_old_backups( $retention );
		}

		$scanner = new MUS_Scanner();
		$result  = $scanner->build_index();
		$index   = $scanner->load_index();

		if ( ! $index || empty( $index['usage_map'] ) ) {
			$usage_map = array();
		} else {
			$usage_map = $index['usage_map'];
		}

		global $wpdb;
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);

		$used_count   = count( $usage_map );
		$unused_count = max( 0, $total - $used_count );

		$unused_size = 0;
		$att_ids     = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);

		foreach ( $att_ids as $att_id ) {
			$att_id = (int) $att_id;
			if ( ! isset( $usage_map[ $att_id ] ) ) {
				$path = get_attached_file( $att_id );
				if ( $path && file_exists( $path ) ) {
					$unused_size += filesize( $path );
				}
			}
		}

		$email = get_option( 'mus_cron_email', get_option( 'admin_email' ) );
		if ( ! is_email( $email ) ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf(
			/* translators: %s: site name */
			__( '[%s] Weekly Media Usage Scan Report', 'media-usage-scanner' ),
			$site_name
		);

		$body  = sprintf( __( 'Media Usage Scanner — Weekly Report for %s', 'media-usage-scanner' ), $site_name ) . "\n\n";
		$body .= sprintf( __( 'Total files: %d', 'media-usage-scanner' ), $total ) . "\n";
		$body .= sprintf( __( 'Used: %d', 'media-usage-scanner' ), $used_count ) . "\n";
		$body .= sprintf( __( 'Unused: %d', 'media-usage-scanner' ), $unused_count ) . "\n";
		$body .= sprintf( __( 'Unused disk space: %s', 'media-usage-scanner' ), size_format( $unused_size ) ) . "\n\n";
		$body .= sprintf(
			__( 'Review and clean up: %s', 'media-usage-scanner' ),
			admin_url( 'upload.php?page=media-usage-scanner' )
		) . "\n";

		wp_mail( $email, $subject, $body );
	}
}
