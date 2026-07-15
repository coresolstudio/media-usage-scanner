<?php
/**
 * WP-CLI commands for headless media scanning.
 *
 * Usage:
 *   wp mus scan              Run usage scan and print summary.
 *   wp mus scan --format=csv Output CSV of all results.
 *   wp mus duplicates        Find duplicate media files.
 *   wp mus delete-unused     Delete all unused media (with confirmation).
 *   wp mus cleanup-backups   Purge old backup ZIPs.
 *
 * @package MediaUsageScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUS_CLI {

	/**
	 * Run a full media usage scan.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Accepts table, csv, json. Default: table.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function scan( $args, $assoc_args ) {
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		WP_CLI::log( 'Building usage index…' );
		$scanner = new MUS_Scanner();
		$result  = $scanner->build_index();
		WP_CLI::log( sprintf( 'Index built. Total attachments: %d', $result['total'] ) );

		$offset = 0;
		$limit  = 200;
		$all    = array();

		while ( true ) {
			$batch = $scanner->get_results_batch( $offset, $limit );
			if ( empty( $batch['items'] ) ) {
				break;
			}
			$all    = array_merge( $all, $batch['items'] );
			$offset = $batch['next_offset'];
			if ( $batch['complete'] ) {
				break;
			}
		}

		$used   = array_filter( $all, function ( $i ) { return 'used' === $i['status']; } );
		$unused = array_filter( $all, function ( $i ) { return 'unused' === $i['status']; } );

		$unused_bytes = array_sum( array_column( $unused, 'size_raw' ) );

		WP_CLI::log( '' );
		WP_CLI::success(
			sprintf(
				'Total: %d | Used: %d | Unused: %d | Unused size: %s',
				count( $all ),
				count( $used ),
				count( $unused ),
				size_format( $unused_bytes )
			)
		);

		if ( 'csv' === $format ) {
			$csv = MUS_Exporter::build_csv( $all );
			WP_CLI::log( $csv );
			return;
		}

		if ( 'json' === $format ) {
			WP_CLI::log( wp_json_encode( $all, JSON_PRETTY_PRINT ) );
			return;
		}

		$rows = array();
		foreach ( $all as $item ) {
			$rows[] = array(
				'ID'       => $item['id'],
				'Filename' => $item['filename'],
				'Size'     => $item['size'],
				'Status'   => $item['status'],
				'Used In'  => implode( ' | ', $item['used_in'] ),
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'ID', 'Filename', 'Size', 'Status', 'Used In' ) );
	}

	/**
	 * Find duplicate media files.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function duplicates( $args, $assoc_args ) {
		WP_CLI::log( 'Computing file hashes…' );

		$hash_map = array();
		$offset   = 0;
		$limit    = 200;

		while ( true ) {
			$result = MUS_Duplicates::process_batch( $offset, $limit, $hash_map );
			$hash_map = $result['hash_map'];
			WP_CLI::log( sprintf( 'Processed %d / %d', $result['processed'], $result['total'] ) );

			if ( $result['complete'] ) {
				break;
			}
			$offset = $result['processed'];
		}

		$groups = MUS_Duplicates::extract_groups( $hash_map );

		if ( empty( $groups['groups'] ) ) {
			WP_CLI::success( 'No duplicates found.' );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Found %d duplicate files wasting %s',
				$groups['duplicate_count'],
				$groups['total_wasted']
			)
		);

		foreach ( $groups['groups'] as $group ) {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( '--- Hash: %s (%d copies) ---', $group['hash'], $group['count'] ) );
			foreach ( $group['items'] as $item ) {
				WP_CLI::log( sprintf( '  ID: %d  %s  (%s)', $item['id'], $item['filename'], $item['size_fmt'] ) );
			}
		}
	}

	/**
	 * Delete all unused media after confirmation.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show what would be deleted without actually deleting.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function delete_unused( $args, $assoc_args ) { // phpcs:ignore -- WP-CLI naming.
		$dry_run = isset( $assoc_args['dry-run'] );

		WP_CLI::log( 'Building usage index…' );
		$scanner = new MUS_Scanner();
		$scanner->build_index();

		$offset = 0;
		$limit  = 200;
		$unused = array();

		while ( true ) {
			$batch = $scanner->get_results_batch( $offset, $limit );
			foreach ( $batch['items'] as $item ) {
				if ( 'unused' === $item['status'] ) {
					$unused[] = $item;
				}
			}
			$offset = $batch['next_offset'];
			if ( $batch['complete'] ) {
				break;
			}
		}

		if ( empty( $unused ) ) {
			WP_CLI::success( 'No unused media found.' );
			return;
		}

		$total_size = array_sum( array_column( $unused, 'size_raw' ) );
		WP_CLI::log( sprintf( 'Found %d unused files (%s)', count( $unused ), size_format( $total_size ) ) );

		if ( $dry_run ) {
			foreach ( $unused as $item ) {
				WP_CLI::log( sprintf( '  [DRY-RUN] Would delete ID %d: %s (%s)', $item['id'], $item['filename'], $item['size'] ) );
			}
			return;
		}

		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( sprintf( 'Delete %d unused files?', count( $unused ) ) );
		}

		$ids    = array_column( $unused, 'id' );
		$result = MUS_Exporter::create_zip( $ids );

		if ( is_wp_error( $result ) ) {
			WP_CLI::warning( 'Could not create ZIP backup: ' . $result->get_error_message() );
		} else {
			WP_CLI::log( sprintf( 'Backup ZIP created: %s (%d files)', $result['filename'], $result['added_count'] ) );
		}

		$backup_file = is_wp_error( $result ) ? '' : $result['filename'];
		$deleted     = 0;
		$site_logo   = (int) get_theme_mod( 'custom_logo' );
		$site_icon   = (int) get_option( 'site_icon' );

		foreach ( $ids as $id ) {
			if ( $id === $site_logo || $id === $site_icon ) {
				WP_CLI::log( sprintf( '  Skipped ID %d (site identity)', $id ) );
				continue;
			}

			$filename  = wp_basename( get_attached_file( $id ) );
			$file_url  = wp_get_attachment_url( $id );
			$file_path = get_attached_file( $id );
			$file_size = ( $file_path && file_exists( $file_path ) ) ? filesize( $file_path ) : 0;

			if ( wp_delete_attachment( $id, true ) ) {
				$deleted++;
				MUS_Logger::log( $id, $filename, $file_url ? $file_url : '', $file_size, $backup_file );
			}
		}

		WP_CLI::success( sprintf( 'Deleted %d files.', $deleted ) );
	}

	/**
	 * Regenerate thumbnails for all images based on enabled sizes.
	 *
	 * ## OPTIONS
	 *
	 * [--delete-disabled]
	 * : Delete files for disabled sizes during regeneration.
	 *
	 * [--cleanup-only]
	 * : Only delete files for disabled sizes without regenerating.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function regenerate( $args, $assoc_args ) {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/admin.php';
		}

		$cleanup_only    = isset( $assoc_args['cleanup-only'] );
		$delete_disabled = isset( $assoc_args['delete-disabled'] ) || $cleanup_only;
		$total           = MUS_Regenerator::count_images();

		if ( ! $total ) {
			WP_CLI::success( 'No image attachments found.' );
			return;
		}

		$disabled = MUS_Image_Sizes::get_disabled();
		WP_CLI::log( sprintf( 'Total images: %d | Disabled sizes: %d', $total, count( $disabled ) ) );

		if ( $cleanup_only ) {
			WP_CLI::log( 'Running cleanup only (no regeneration)…' );
		} else {
			WP_CLI::log( 'Regenerating thumbnails…' );
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Processing', $total );
		$offset   = 0;
		$limit    = 20;
		$errors   = 0;
		$cleaned  = 0;

		while ( $offset < $total ) {
			if ( $cleanup_only ) {
				$result  = MUS_Regenerator::cleanup_batch( $offset, $limit );
				$cleaned += $result['cleaned'];
			} else {
				$result = MUS_Regenerator::regenerate_batch( $offset, $limit, $delete_disabled );
				$errors += count( $result['errors'] );
			}
			$batch_processed = $result['processed'] - $offset;
			for ( $i = 0; $i < $batch_processed; $i++ ) {
				$progress->tick();
			}
			$offset = $result['processed'];
		}

		$progress->finish();

		if ( $cleanup_only ) {
			WP_CLI::success( sprintf( 'Cleaned up %d files across %d images.', $cleaned, $total ) );
		} else {
			WP_CLI::success( sprintf( 'Regenerated %d images.%s', $total, $errors ? " $errors errors." : '' ) );
		}
	}

	/**
	 * Purge old backup ZIPs.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Purge backups older than this many days. Default: saved setting or 30.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function cleanup_backups( $args, $assoc_args ) { // phpcs:ignore
		$days = isset( $assoc_args['days'] ) ? absint( $assoc_args['days'] ) : (int) get_option( 'mus_backup_retention_days', 30 );

		$purged = MUS_Exporter::purge_old_backups( $days );
		WP_CLI::success( sprintf( 'Purged %d backup(s) older than %d days.', $purged, $days ) );
	}
}
