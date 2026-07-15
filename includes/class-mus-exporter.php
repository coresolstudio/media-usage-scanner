<?php
/**
 * Handles ZIP backup, CSV export, and backup file management.
 *
 * @package MediaUsageScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUS_Exporter {

	/**
	 * Create a ZIP archive containing the given attachment files.
	 *
	 * @param int[] $ids Attachment IDs to include.
	 * @return array{ download_url: string, filename: string, added_count: int, missing_ids: int[] }|WP_Error
	 */
	public static function create_zip( $ids ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_zip', __( 'ZipArchive is not available on this server.', 'media-usage-scanner' ) );
		}

		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			return new WP_Error( 'no_ids', __( 'No media items were selected.', 'media-usage-scanner' ) );
		}

		$backup_dir = self::get_backup_dir();
		if ( is_wp_error( $backup_dir ) ) {
			return $backup_dir;
		}

		$stamp    = gmdate( 'Y-m-d-H-i-s' );
		$rand     = wp_generate_password( 6, false, false );
		$filename = "mus-backup-{$stamp}-{$rand}.zip";
		$zip_path = trailingslashit( $backup_dir['path'] ) . $filename;
		$zip_url  = trailingslashit( $backup_dir['url'] ) . $filename;

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip_create', __( 'Could not create the ZIP file.', 'media-usage-scanner' ) );
		}

		$added      = 0;
		$missing    = array();
		$used_names = array();

		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( $id ) ) {
				$missing[] = $id;
				continue;
			}

			$file_path = get_attached_file( $id );
			if ( ! $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
				$missing[] = $id;
				continue;
			}

			$entry = wp_basename( $file_path );
			if ( isset( $used_names[ $entry ] ) ) {
				$info  = pathinfo( $entry );
				$entry = ( $info['filename'] ?? 'file' ) . '-' . $id . '.' . ( $info['extension'] ?? '' );
			}
			$used_names[ $entry ] = true;

			if ( $zip->addFile( $file_path, $entry ) ) {
				$added++;
			} else {
				$missing[] = $id;
			}
		}

		$zip->close();

		if ( ! file_exists( $zip_path ) ) {
			return new WP_Error( 'zip_missing', __( 'ZIP file was not created successfully.', 'media-usage-scanner' ) );
		}

		return array(
			'download_url' => esc_url_raw( $zip_url ),
			'filename'     => $filename,
			'added_count'  => $added,
			'missing_ids'  => $missing,
		);
	}

	/**
	 * Build a CSV string from scan result items.
	 *
	 * @param array $items Scan result items.
	 * @return string CSV content.
	 */
	public static function build_csv( $items ) {
		$output = fopen( 'php://temp', 'r+' );

		fputcsv( $output, array( 'ID', 'Title', 'Filename', 'MIME Type', 'Date', 'Size', 'Size (bytes)', 'Status', 'Used In' ) );

		foreach ( $items as $item ) {
			$used_in = is_array( $item['used_in'] ) ? implode( ' | ', $item['used_in'] ) : '';
			fputcsv(
				$output,
				array(
					$item['id'],
					$item['title'],
					$item['filename'],
					$item['mime'] ?? '',
					$item['date'],
					$item['size'],
					$item['size_raw'],
					$item['status'],
					$used_in,
				)
			);
		}

		rewind( $output );
		$csv = stream_get_contents( $output );
		fclose( $output );

		return $csv;
	}

	/**
	 * List existing backup ZIP files.
	 *
	 * @return array[] Each entry: { filename, url, size, date }.
	 */
	public static function list_backups() {
		$dir = self::get_backup_dir();
		if ( is_wp_error( $dir ) ) {
			return array();
		}

		$files   = glob( trailingslashit( $dir['path'] ) . 'mus-backup-*.zip' );
		$backups = array();

		if ( ! is_array( $files ) ) {
			return $backups;
		}

		foreach ( $files as $file ) {
			$name      = wp_basename( $file );
			$backups[] = array(
				'filename' => $name,
				'url'      => trailingslashit( $dir['url'] ) . $name,
				'size'     => size_format( filesize( $file ) ),
				'date'     => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), filemtime( $file ) ),
			);
		}

		usort(
			$backups,
			function ( $a, $b ) {
				return strcmp( $b['filename'], $a['filename'] );
			}
		);

		return $backups;
	}

	/**
	 * Delete a single backup file.
	 *
	 * @param string $filename Backup ZIP filename (basename only).
	 * @return bool
	 */
	public static function delete_backup( $filename ) {
		$filename = sanitize_file_name( $filename );
		$dir      = self::get_backup_dir();

		if ( is_wp_error( $dir ) ) {
			return false;
		}

		$path = trailingslashit( $dir['path'] ) . $filename;

		if ( file_exists( $path ) && 0 === strpos( $path, $dir['path'] ) ) {
			return wp_delete_file( $path ) !== false;
		}

		return false;
	}

	/**
	 * Inspect a backup ZIP without restoring anything, so the UI can warn
	 * the user upfront if any of its files have already been restored
	 * before (rather than only finding out after the fact).
	 *
	 * @param string $filename Backup ZIP filename (basename only).
	 * @return array{ files: array[], total_files: int, previously_restored_count: int }|WP_Error
	 */
	public static function preview_restore( $filename ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_zip', __( 'ZipArchive is not available on this server.', 'media-usage-scanner' ) );
		}

		$filename = sanitize_file_name( $filename );
		$dir      = self::get_backup_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$zip_path = trailingslashit( $dir['path'] ) . $filename;

		if ( ! $filename || 0 !== strpos( $zip_path, $dir['path'] ) || ! file_exists( $zip_path ) ) {
			return new WP_Error( 'not_found', __( 'Backup file not found.', 'media-usage-scanner' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'zip_open', __( 'Could not open the backup ZIP.', 'media-usage-scanner' ) );
		}

		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$files       = array();

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = $zip->getNameIndex( $i );

			if ( ! $entry || false !== strpos( $entry, '/' ) || false !== strpos( $entry, '\\' ) || false !== strpos( $entry, '..' ) ) {
				continue;
			}

			$history = MUS_Logger::get_restore_history( $entry );
			$dates   = array();
			foreach ( $history as $row ) {
				$dates[] = date_i18n( $date_format, strtotime( $row->restored_at ) );
			}

			$stat = $zip->statIndex( $i );
			$size = ( $stat && isset( $stat['size'] ) ) ? (int) $stat['size'] : 0;

			$files[] = array(
				'filename'          => $entry,
				'size'              => $size,
				'size_fmt'          => $size ? size_format( $size ) : '',
				'restored_before'   => ! empty( $dates ),
				'restore_count'     => count( $dates ),
				'previous_restores' => $dates,
			);
		}

		$zip->close();

		return array(
			'files'                     => $files,
			'total_files'               => count( $files ),
			'previously_restored_count' => count( array_filter( $files, function ( $f ) {
				return $f['restored_before'];
			} ) ),
		);
	}

	/**
	 * Re-import every file inside a backup ZIP back into the Media Library.
	 *
	 * Where the deletion log has a record of the file's original attachment
	 * ID and folder:
	 * - The file is written back into its original uploads subfolder under
	 *   its original filename where possible, so hardcoded URL references
	 *   have the best chance of resolving again.
	 * - If the original attachment ID is currently unused, the new
	 *   attachment is re-created at that exact same ID (via `import_id`),
	 *   so any ID-based reference elsewhere (ACF fields, _thumbnail_id,
	 *   Elementor widgets, custom_logo, etc.) starts working again
	 *   automatically. If that ID is no longer free, it falls back to a
	 *   normal auto-generated ID.
	 *
	 * @param string   $filename       Backup ZIP filename (basename only).
	 * @param string[] $selected_files Optional. If non-empty, only these filenames
	 *                                 (as they appear inside the ZIP) are restored —
	 *                                 everything else in the archive is left alone.
	 *                                 Empty/omitted restores every file, as before.
	 * @return array{ restored: array[], errors: string[], count: int, skipped_count: int }|WP_Error
	 */
	public static function restore_zip( $filename, $selected_files = array() ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'no_zip', __( 'ZipArchive is not available on this server.', 'media-usage-scanner' ) );
		}

		$selected_files = array_filter( array_map( 'sanitize_file_name', (array) $selected_files ) );
		$selected_lookup = $selected_files ? array_flip( $selected_files ) : null;

		$filename = sanitize_file_name( $filename );
		$dir      = self::get_backup_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$zip_path = trailingslashit( $dir['path'] ) . $filename;

		if ( ! $filename || 0 !== strpos( $zip_path, $dir['path'] ) || ! file_exists( $zip_path ) ) {
			return new WP_Error( 'not_found', __( 'Backup file not found.', 'media-usage-scanner' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error( 'zip_open', __( 'Could not open the backup ZIP.', 'media-usage-scanner' ) );
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/admin.php';
		}

		@set_time_limit( 300 ); // phpcs:ignore
		wp_raise_memory_limit( 'image' );

		$log_map       = MUS_Logger::get_entries_by_backup( $filename );
		$restored      = array();
		$errors        = array();
		$skipped_count = 0;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry = $zip->getNameIndex( $i );

			// Flat filenames only — this plugin never writes directory entries,
			// so anything else is unexpected and skipped for safety.
			if ( ! $entry || false !== strpos( $entry, '/' ) || false !== strpos( $entry, '\\' ) || false !== strpos( $entry, '..' ) ) {
				continue;
			}

			// A specific selection was requested — leave everything else in the
			// backup untouched (partial restore).
			if ( null !== $selected_lookup && ! isset( $selected_lookup[ $entry ] ) ) {
				++$skipped_count;
				continue;
			}

			$contents = $zip->getFromIndex( $i );
			if ( false === $contents ) {
				$errors[] = sprintf( __( '%s: could not be read from the ZIP.', 'media-usage-scanner' ), $entry );
				continue;
			}

			$filetype = wp_check_filetype( $entry );
			if ( empty( $filetype['type'] ) ) {
				$errors[] = sprintf( __( '%s: unrecognized file type, skipped.', 'media-usage-scanner' ), $entry );
				continue;
			}

			$log_entry = isset( $log_map[ $entry ] ) ? $log_map[ $entry ] : null;
			$target    = self::resolve_restore_target( $log_entry );

			// Has this exact filename been restored before? Fetch this
			// *before* logging the current attempt, so it only reflects
			// prior restores, not this one.
			$prior_restores = MUS_Logger::get_restore_history( $entry );

			$unique_name = wp_unique_filename( $target['dir'], $entry );
			$dest_path   = trailingslashit( $target['dir'] ) . $unique_name;

			if ( false === file_put_contents( $dest_path, $contents ) ) { // phpcs:ignore
				$errors[] = sprintf( __( '%s: could not be written to the uploads folder.', 'media-usage-scanner' ), $entry );
				continue;
			}

			$attachment_args = array(
				'post_title'     => pathinfo( $entry, PATHINFO_FILENAME ),
				'post_mime_type' => $filetype['type'],
				'post_status'    => 'inherit',
				'guid'           => trailingslashit( $target['url'] ) . $unique_name,
			);

			// If we know the file's original attachment ID and that ID is
			// currently free, re-create the attachment at that exact ID.
			// WordPress never re-issues a deleted ID through normal
			// AUTO_INCREMENT inserts, so any ID-based reference elsewhere on
			// the site (ACF fields, _thumbnail_id, Elementor widgets, the
			// custom_logo theme mod, etc.) still contains that number —
			// restoring at the same ID makes those references work again
			// immediately, with no manual re-linking needed.
			$original_id = ( $log_entry && ! empty( $log_entry->attachment_id ) ) ? (int) $log_entry->attachment_id : 0;
			$attempt_id_reuse = ( $original_id > 0 && null === get_post( $original_id ) );

			if ( $attempt_id_reuse ) {
				$attachment_args['import_id'] = $original_id;
			}

			$attachment_id = wp_insert_attachment( $attachment_args, $dest_path );

			// The ID slot could theoretically be taken in the moment between
			// our check and the insert — if the forced-ID insert failed,
			// retry once as a normal (auto-ID) insert rather than losing the file.
			if ( $attempt_id_reuse && ( ! $attachment_id || is_wp_error( $attachment_id ) ) ) {
				unset( $attachment_args['import_id'] );
				$attachment_id    = wp_insert_attachment( $attachment_args, $dest_path );
				$attempt_id_reuse = false;
			}

			if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
				$errors[] = sprintf( __( '%s: could not be added to the Media Library.', 'media-usage-scanner' ), $entry );
				continue;
			}

			$metadata = wp_generate_attachment_metadata( $attachment_id, $dest_path );
			if ( ! is_wp_error( $metadata ) && ! empty( $metadata ) ) {
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}

			$id_reused = ( $attempt_id_reuse && (int) $attachment_id === $original_id );

			MUS_Logger::log_restore( $entry, $original_id, $attachment_id, $id_reused, $filename );

			$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			$history     = array();
			foreach ( $prior_restores as $prior ) {
				$restorer = get_userdata( $prior->restored_by );
				$history[] = array(
					'date' => date_i18n( $date_format, strtotime( $prior->restored_at ) ),
					'by'   => $restorer ? $restorer->display_name : __( 'Unknown user', 'media-usage-scanner' ),
				);
			}

			$restored[] = array(
				'id'                => $attachment_id,
				'original_name'     => $entry,
				'filename'          => $unique_name,
				'renamed'           => ( $unique_name !== $entry ),
				'id_reused'         => $id_reused,
				'edit_url'          => admin_url( 'post.php?post=' . $attachment_id . '&action=edit' ),
				'url'               => wp_get_attachment_url( $attachment_id ),
				'restored_before'   => ! empty( $history ),
				'restore_count'     => count( $history ) + 1,
				'previous_restores' => $history,
			);
		}

		$zip->close();

		return array(
			'restored'      => $restored,
			'errors'        => $errors,
			'count'         => count( $restored ),
			'skipped_count' => $skipped_count,
		);
	}

	/**
	 * Work out which uploads subfolder a restored file should land in.
	 * Falls back to the current month's folder if no deletion-log record
	 * exists, or its stored URL doesn't resolve to a folder inside uploads.
	 *
	 * @param object|null $log_entry Row from the deletion log, if any.
	 * @return array{ dir: string, url: string }
	 */
	private static function resolve_restore_target( $log_entry ) {
		$upload_dir = wp_upload_dir();
		$target_dir = $upload_dir['path'];
		$target_url = $upload_dir['url'];

		if ( $log_entry && ! empty( $log_entry->file_url ) ) {
			$file_path = wp_parse_url( $log_entry->file_url, PHP_URL_PATH );
			$base_path = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH );

			if ( $file_path && $base_path && 0 === strpos( $file_path, $base_path ) ) {
				$relative = ltrim( substr( $file_path, strlen( $base_path ) ), '/' );
				$rel_dir  = dirname( $relative );

				if ( '.' !== $rel_dir && '' !== $rel_dir ) {
					$candidate_dir = trailingslashit( $upload_dir['basedir'] ) . trailingslashit( $rel_dir );

					if ( wp_mkdir_p( $candidate_dir ) ) {
						$target_dir = $candidate_dir;
						$target_url = trailingslashit( $upload_dir['baseurl'] ) . trailingslashit( $rel_dir );
					}
				}
			}
		}

		return array(
			'dir' => $target_dir,
			'url' => $target_url,
		);
	}

	/**
	 * Purge backup ZIPs older than N days.
	 *
	 * @param int $days Retention period in days.
	 * @return int Number of files deleted.
	 */
	public static function purge_old_backups( $days = 30 ) {
		$dir = self::get_backup_dir();
		if ( is_wp_error( $dir ) ) {
			return 0;
		}

		$files   = glob( trailingslashit( $dir['path'] ) . 'mus-backup-*.zip' );
		$cutoff  = time() - ( absint( $days ) * DAY_IN_SECONDS );
		$deleted = 0;

		if ( ! is_array( $files ) ) {
			return 0;
		}

		foreach ( $files as $file ) {
			if ( filemtime( $file ) < $cutoff ) {
				wp_delete_file( $file );
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * Ensure and return the backup directory path/URL.
	 *
	 * @return array{ path: string, url: string }|WP_Error
	 */
	public static function get_backup_dir() {
		$upload_dir = wp_upload_dir();

		if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) ) {
			return new WP_Error( 'upload_dir', __( 'Uploads directory is not available.', 'media-usage-scanner' ) );
		}

		$path = trailingslashit( $upload_dir['basedir'] ) . 'media-usage-scanner-backups';

		if ( ! wp_mkdir_p( $path ) ) {
			return new WP_Error( 'mkdir', __( 'Could not create the backup folder.', 'media-usage-scanner' ) );
		}

		$htaccess = trailingslashit( $path ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Options -Indexes\n" ); // phpcs:ignore
		}

		return array(
			'path' => $path,
			'url'  => trailingslashit( $upload_dir['baseurl'] ) . 'media-usage-scanner-backups',
		);
	}
}
