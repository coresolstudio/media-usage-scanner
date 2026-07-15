<?php
/**
 * Deletion logger — records every media deletion into a custom DB table.
 *
 * @package MediaUsageScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUS_Logger {

	const DB_VERSION = '1.1';

	/**
	 * Create / upgrade the log tables (called on activation, and re-checked
	 * on every load in case the plugin was updated without re-activation).
	 */
	public static function create_table() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		$table = $wpdb->prefix . 'mus_deletion_log';
		$sql   = "CREATE TABLE {$table} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) UNSIGNED NOT NULL,
			filename varchar(255) NOT NULL DEFAULT '',
			file_url varchar(500) NOT NULL DEFAULT '',
			file_size bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			deleted_by bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			deleted_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			backup_file varchar(255) DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id),
			KEY deleted_at (deleted_at)
		) {$charset};";

		$restore_table = $wpdb->prefix . 'mus_restore_log';
		$restore_sql   = "CREATE TABLE {$restore_table} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			filename varchar(255) NOT NULL DEFAULT '',
			original_attachment_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			new_attachment_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			id_reused tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
			backup_file varchar(255) DEFAULT NULL,
			restored_by bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			restored_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY filename (filename),
			KEY original_attachment_id (original_attachment_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		dbDelta( $restore_sql );

		update_option( 'mus_db_version', self::DB_VERSION );
	}

	/**
	 * Make sure both log tables exist, even on sites where the plugin was
	 * updated in place without a re-activation (which is the common case
	 * for auto-updates and most deploy workflows).
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'mus_db_version' ) !== self::DB_VERSION ) {
			self::create_table();
		}
	}

	/**
	 * Log a single deletion.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $filename      Original filename.
	 * @param string $file_url      Full URL of the attachment.
	 * @param int    $file_size     File size in bytes.
	 * @param string $backup_file   Backup ZIP filename (if any).
	 */
	public static function log( $attachment_id, $filename, $file_url, $file_size, $backup_file = '' ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'mus_deletion_log',
			array(
				'attachment_id' => absint( $attachment_id ),
				'filename'      => sanitize_file_name( $filename ),
				'file_url'      => esc_url_raw( $file_url ),
				'file_size'     => absint( $file_size ),
				'deleted_by'    => get_current_user_id(),
				'deleted_at'    => current_time( 'mysql' ),
				'backup_file'   => $backup_file ? sanitize_file_name( $backup_file ) : null,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
	}

	/**
	 * Retrieve recent log entries.
	 *
	 * @param int $limit Max rows to return.
	 * @param int $offset Offset for pagination.
	 * @return array
	 */
	public static function get_entries( $limit = 50, $offset = 0 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'mus_deletion_log';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY deleted_at DESC LIMIT %d OFFSET %d",
				absint( $limit ),
				absint( $offset )
			)
		);
	}

	/**
	 * Look up deletion-log entries for a given backup ZIP, keyed by the
	 * original filename, so a restore can recover each file's original
	 * URL/location.
	 *
	 * @param string $backup_file Backup ZIP filename.
	 * @return array<string, object> filename => log row.
	 */
	public static function get_entries_by_backup( $backup_file ) {
		global $wpdb;

		$table = $wpdb->prefix . 'mus_deletion_log';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE backup_file = %s",
				$backup_file
			)
		);

		$map = array();
		foreach ( $rows as $row ) {
			$map[ $row->filename ] = $row;
		}

		return $map;
	}

	/**
	 * Record a single restore event for a file.
	 *
	 * @param string $filename                Original filename as it was backed up/deleted under.
	 * @param int    $original_attachment_id  The attachment ID it had before deletion (0 if unknown).
	 * @param int    $new_attachment_id       The attachment ID it was restored to.
	 * @param bool   $id_reused               Whether the original ID was successfully reused.
	 * @param string $backup_file             Backup ZIP filename this restore came from.
	 */
	public static function log_restore( $filename, $original_attachment_id, $new_attachment_id, $id_reused, $backup_file = '' ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'mus_restore_log',
			array(
				'filename'               => sanitize_file_name( $filename ),
				'original_attachment_id' => absint( $original_attachment_id ),
				'new_attachment_id'      => absint( $new_attachment_id ),
				'id_reused'              => $id_reused ? 1 : 0,
				'backup_file'            => $backup_file ? sanitize_file_name( $backup_file ) : null,
				'restored_by'            => get_current_user_id(),
				'restored_at'            => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * Get every previous restore recorded for a given original filename, so
	 * a restore can flag "this file has been restored before" with dates.
	 *
	 * @param string $filename Original filename.
	 * @return array Rows ordered oldest to newest.
	 */
	public static function get_restore_history( $filename ) {
		global $wpdb;

		$table = $wpdb->prefix . 'mus_restore_log';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE filename = %s ORDER BY restored_at ASC",
				sanitize_file_name( $filename )
			)
		);
	}

	/**
	 * Whether a given attachment ID still exists as a Media Library item.
	 * Used to decide whether a past restore is still relevant — if the
	 * restored copy was since deleted again, there's nothing left to warn
	 * about and the file can be treated as if it were never restored.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool
	 */
	public static function attachment_exists( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id ) {
			return false;
		}

		$post = get_post( $attachment_id );

		return ( $post && 'attachment' === $post->post_type );
	}

	/**
	 * Same as get_restore_history(), but only includes past restores whose
	 * resulting attachment is still present in the Media Library today.
	 * A restore whose copy was deleted again shouldn't trigger "already
	 * restored" warnings — restoring again is effectively a fresh restore.
	 *
	 * @param string $filename Original filename.
	 * @return array Rows ordered oldest to newest.
	 */
	public static function get_active_restore_history( $filename ) {
		$history = self::get_restore_history( $filename );

		return array_values(
			array_filter(
				$history,
				function ( $row ) {
					return self::attachment_exists( $row->new_attachment_id );
				}
			)
		);
	}

	/**
	 * Total number of log entries.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;

		$table = $wpdb->prefix . 'mus_deletion_log';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}
}
