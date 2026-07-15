<?php
/**
 * AJAX request handlers for every scanner action.
 *
 * @package MediaUsageScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUS_Ajax {

	const NONCE_ACTION = 'mus_nonce';

	public function __construct() {
		$actions = array(
			'mus_build_index',
			'mus_scan_batch',
			'mus_delete_media',
			'mus_export_zip',
			'mus_export_csv',
			'mus_find_duplicates',
			'mus_get_backups',
			'mus_delete_backup',
			'mus_preview_restore',
			'mus_restore_backup',
			'mus_save_settings',
			'mus_get_image_sizes',
			'mus_save_image_sizes',
			'mus_regenerate_batch',
			'mus_cleanup_sizes',
		);

		foreach ( $actions as $action ) {
			$method = str_replace( 'mus_', '', $action );
			add_action( 'wp_ajax_' . $action, array( $this, $method ) );
		}
	}

	/* ─────────────────────── request verification ────────────────────────── */

	private function verify() {
		nocache_headers();

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'media-usage-scanner' ) ), 403 );
		}

		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page.', 'media-usage-scanner' ) ), 403 );
		}
	}

	/* ──────────────────── Phase 1: build reverse index ───────────────────── */

	public function build_index() {
		try {
			$this->verify();

			$scanner = new MUS_Scanner();
			$result  = $scanner->build_index();

			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	/* ──────────────── Phase 2: batched result delivery ───────────────────── */

	public function scan_batch() {
		try {
			$this->verify();

			$offset      = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
			$limit       = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 50;
			$known_total = isset( $_POST['total'] ) ? absint( $_POST['total'] ) : 0;
			$filters     = array(
				'date_from'   => isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '',
				'date_to'     => isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '',
				'search_term' => isset( $_POST['search_term'] ) ? sanitize_text_field( wp_unslash( $_POST['search_term'] ) ) : '',
				'search_mode' => isset( $_POST['search_mode'] ) ? sanitize_key( wp_unslash( $_POST['search_mode'] ) ) : 'contains',
			);

			$scanner = new MUS_Scanner();
			$result  = $scanner->get_results_batch( $offset, $limit, $filters, $known_total );

			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	/* ─────────────────────── ZIP export ──────────────────────────────────── */

	public function export_zip() {
		try {
			$this->verify();

			$ids    = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();
			$result = MUS_Exporter::create_zip( $ids );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			}

			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	/* ────────────────────── CSV export ───────────────────────────────────── */

	public function export_csv() {
		try {
			$this->verify();

			$items_json = isset( $_POST['items'] ) ? wp_unslash( $_POST['items'] ) : '[]';
			$items      = json_decode( $items_json, true );

			if ( ! is_array( $items ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid item data.', 'media-usage-scanner' ) ), 400 );
			}

			$csv = MUS_Exporter::build_csv( $items );

			$upload_dir = wp_upload_dir();
			$dir        = trailingslashit( $upload_dir['basedir'] ) . 'media-usage-scanner';
			wp_mkdir_p( $dir );

			$filename = 'mus-export-' . gmdate( 'Y-m-d-H-i-s' ) . '.csv';
			$filepath = trailingslashit( $dir ) . $filename;

			file_put_contents( $filepath, $csv ); // phpcs:ignore

			$url = trailingslashit( $upload_dir['baseurl'] ) . 'media-usage-scanner/' . $filename;

			wp_send_json_success(
				array(
					'download_url' => esc_url_raw( $url ),
					'filename'     => $filename,
				)
			);
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	/* ──────────────────── Delete unused media ────────────────────────────── */

	public function delete_media() {
		try {
			$this->verify();

			$ids         = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();
			$backup_file = isset( $_POST['backup_file'] ) ? sanitize_file_name( wp_unslash( $_POST['backup_file'] ) ) : '';

			$scanner         = new MUS_Scanner();
			$index           = $scanner->load_index();
			$usage_map       = $index ? $index['usage_map'] : array();
			$deleted_count   = 0;
			$skipped         = array();
			$site_logo       = (int) get_theme_mod( 'custom_logo' );
			$site_icon       = (int) get_option( 'site_icon' );

			foreach ( $ids as $id ) {
				$id = (int) $id;

				if ( 'attachment' !== get_post_type( $id ) ) {
					$skipped[] = $id;
					continue;
				}

				if ( $id === $site_logo || $id === $site_icon ) {
					$skipped[] = $id;
					continue;
				}

				if ( ! empty( $usage_map[ $id ] ) ) {
					$skipped[] = $id;
					continue;
				}

				$filename  = wp_basename( get_attached_file( $id ) );
				$file_url  = wp_get_attachment_url( $id );
				$file_path = get_attached_file( $id );
				$file_size = ( $file_path && file_exists( $file_path ) ) ? filesize( $file_path ) : 0;

				if ( wp_delete_attachment( $id, true ) ) {
					$deleted_count++;
					MUS_Logger::log( $id, $filename, $file_url ? $file_url : '', $file_size, $backup_file );
				} else {
					$skipped[] = $id;
				}
			}

			wp_send_json_success(
				array(
					'deleted' => $deleted_count,
					'skipped' => $skipped,
				)
			);
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	/* ──────────────── Duplicate detection (batched) ─────────────────────── */

	public function find_duplicates() {
		try {
			$this->verify();

			$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
			$limit  = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : MUS_Duplicates::BATCH_SIZE;
			$reset  = isset( $_POST['reset'] ) && '1' === $_POST['reset'];

			if ( $reset || 0 === $offset ) {
				MUS_Duplicates::clear_hash_map();
			}

			$hash_map = MUS_Duplicates::load_hash_map();
			$result   = MUS_Duplicates::process_batch( $offset, $limit, $hash_map );

			MUS_Duplicates::save_hash_map( $result['hash_map'] );

			$response = array(
				'processed' => $result['processed'],
				'total'     => $result['total'],
				'complete'  => $result['complete'],
			);

			if ( $result['complete'] ) {
				$groups             = MUS_Duplicates::extract_groups( $result['hash_map'] );
				$response['groups'] = $groups['groups'];
				$response['total_wasted']    = $groups['total_wasted'];
				$response['duplicate_count'] = $groups['duplicate_count'];
				MUS_Duplicates::clear_hash_map();
			}

			wp_send_json_success( $response );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	/* ─────────────────────── Backup management ──────────────────────────── */

	public function get_backups() {
		try {
			$this->verify();
			wp_send_json_success( array( 'backups' => MUS_Exporter::list_backups() ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	public function delete_backup() {
		try {
			$this->verify();

			$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
			if ( ! $filename ) {
				wp_send_json_error( array( 'message' => __( 'No filename provided.', 'media-usage-scanner' ) ), 400 );
			}

			MUS_Exporter::delete_backup( $filename );

			wp_send_json_success( array( 'backups' => MUS_Exporter::list_backups() ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	public function restore_backup() {
		try {
			$this->verify();

			$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
			if ( ! $filename ) {
				wp_send_json_error( array( 'message' => __( 'No filename provided.', 'media-usage-scanner' ) ), 400 );
			}

			$selected_files = isset( $_POST['files'] ) ? array_map( 'sanitize_file_name', (array) wp_unslash( $_POST['files'] ) ) : array();

			$result = MUS_Exporter::restore_zip( $filename, $selected_files );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			}

			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	/**
	 * Inspect a backup ZIP before restoring, so the frontend can warn the
	 * user upfront if any file inside it has already been restored before.
	 */
	public function preview_restore() {
		try {
			$this->verify();

			$filename = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
			if ( ! $filename ) {
				wp_send_json_error( array( 'message' => __( 'No filename provided.', 'media-usage-scanner' ) ), 400 );
			}

			$result = MUS_Exporter::preview_restore( $filename );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
			}

			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	/* ─────────────── Image Size Management ──────────────────────────────── */

	public function get_image_sizes() {
		try {
			$this->verify();

			wp_send_json_success( array(
				'sizes'          => MUS_Image_Sizes::get_all_sizes(),
				'srcset_disabled' => MUS_Image_Sizes::is_srcset_disabled(),
			) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	public function save_image_sizes() {
		try {
			$this->verify();

			$disabled_raw = isset( $_POST['disabled_sizes'] ) ? wp_unslash( $_POST['disabled_sizes'] ) : '[]';
			$disabled     = json_decode( $disabled_raw, true );
			if ( ! is_array( $disabled ) ) {
				$disabled = array();
			}

			$disable_srcset = isset( $_POST['disable_srcset'] ) && '1' === $_POST['disable_srcset'];

			MUS_Image_Sizes::save_disabled( $disabled );
			MUS_Image_Sizes::save_srcset_setting( $disable_srcset );

			wp_send_json_success( array(
				'message' => __( 'Image size settings saved. New uploads will use the updated sizes.', 'media-usage-scanner' ),
				'sizes'   => MUS_Image_Sizes::get_all_sizes(),
			) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	public function regenerate_batch() {
		try {
			$this->verify();

			@set_time_limit( 120 );
			wp_raise_memory_limit( 'image' );

			$offset          = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
			$limit           = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : MUS_Regenerator::BATCH_SIZE;
			$delete_disabled = isset( $_POST['delete_disabled'] ) && '1' === $_POST['delete_disabled'];

			$result = MUS_Regenerator::regenerate_batch( $offset, $limit, $delete_disabled );

			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	public function cleanup_sizes() {
		try {
			$this->verify();

			$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
			$limit  = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 20;

			$result = MUS_Regenerator::cleanup_batch( $offset, $limit );

			wp_send_json_success( $result );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	/* ─────────────────────── Settings ────────────────────────────────────── */

	public function save_settings() {
		try {
			$this->verify();

			$enable_cron    = isset( $_POST['enable_cron'] ) && '1' === $_POST['enable_cron'];
			$cron_email     = isset( $_POST['cron_email'] ) ? sanitize_email( wp_unslash( $_POST['cron_email'] ) ) : '';
			$retention_days = isset( $_POST['retention_days'] ) ? absint( $_POST['retention_days'] ) : 30;
			$scan_theme     = isset( $_POST['scan_theme_files'] ) && '1' === $_POST['scan_theme_files'];
			$batch_delay_ms = isset( $_POST['batch_delay_ms'] ) ? absint( $_POST['batch_delay_ms'] ) : 250;

			update_option( 'mus_enable_cron', $enable_cron );
			update_option( 'mus_cron_email', $cron_email );
			update_option( 'mus_backup_retention_days', max( 1, $retention_days ) );
			update_option( 'mus_scan_theme_files', $scan_theme );
			update_option( 'mus_batch_delay_ms', min( 5000, $batch_delay_ms ) );

			if ( $enable_cron ) {
				MUS_Cron::activate();
			} else {
				MUS_Cron::deactivate();
			}

			$purged = MUS_Exporter::purge_old_backups( $retention_days );

			wp_send_json_success(
				array(
					'message'       => __( 'Settings saved.', 'media-usage-scanner' ),
					'backups_purged' => $purged,
				)
			);
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
	}
}
