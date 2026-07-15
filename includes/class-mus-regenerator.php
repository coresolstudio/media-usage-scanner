<?php
/**
 * Thumbnail regeneration engine.
 *
 * Regenerates intermediate image sizes for attachments using only the
 * currently enabled sizes. Can also clean up files for disabled sizes.
 *
 * @package MediaUsageScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUS_Regenerator {

	const BATCH_SIZE = 5;

	/**
	 * Count all image attachments.
	 */
	public static function count_images() {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type = 'attachment'
			 AND post_mime_type LIKE 'image/%'
			 AND post_status = 'inherit'"
		);
	}

	/**
	 * Get a batch of image attachment IDs.
	 */
	public static function get_image_ids( $offset = 0, $limit = 5 ) {
		global $wpdb;
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'attachment'
				 AND post_mime_type LIKE 'image/%%'
				 AND post_status = 'inherit'
				 ORDER BY ID ASC
				 LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);
	}

	/**
	 * Regenerate thumbnails for a batch of attachments.
	 *
	 * @param int  $offset          Offset for the query.
	 * @param int  $limit           Batch size.
	 * @param bool $delete_disabled Whether to delete files for disabled sizes.
	 * @return array Results including processed count, errors, total.
	 */
	public static function regenerate_batch( $offset = 0, $limit = 5, $delete_disabled = false ) {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/admin.php';
		}

		$ids       = self::get_image_ids( $offset, $limit );
		$total     = self::count_images();
		$processed = 0;
		$errors    = array();

		foreach ( $ids as $id ) {
			$result = self::regenerate_single( (int) $id, $delete_disabled );
			$processed++;

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'id'      => (int) $id,
					'message' => $result->get_error_message(),
				);
			}
		}

		return array(
			'processed'   => $offset + $processed,
			'batch_count' => $processed,
			'errors'      => $errors,
			'total'       => $total,
			'complete'    => ( $offset + $processed ) >= $total,
		);
	}

	/**
	 * Regenerate thumbnails for a single attachment.
	 *
	 * @param int  $attachment_id   The attachment to regenerate.
	 * @param bool $delete_disabled Whether to delete files for disabled sizes.
	 * @return true|WP_Error
	 */
	public static function regenerate_single( $attachment_id, $delete_disabled = false ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'invalid', 'Not a valid attachment.' );
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error( 'not_image', 'Not an image attachment.' );
		}

		$fullsizepath = self::get_fullsizepath( $attachment_id );
		if ( ! $fullsizepath || ! file_exists( $fullsizepath ) ) {
			return new WP_Error( 'missing_file', 'Original file not found.' );
		}

		$old_metadata = wp_get_attachment_metadata( $attachment_id );

		if ( $delete_disabled && is_array( $old_metadata ) && ! empty( $old_metadata['sizes'] ) ) {
			self::delete_disabled_files( $attachment_id, $old_metadata );
		}

		$new_metadata = wp_generate_attachment_metadata( $attachment_id, $fullsizepath );

		if ( is_wp_error( $new_metadata ) ) {
			return $new_metadata;
		}

		if ( empty( $new_metadata ) ) {
			return new WP_Error( 'generation_failed', 'Metadata generation returned empty.' );
		}

		wp_update_attachment_metadata( $attachment_id, $new_metadata );

		return true;
	}

	/**
	 * Delete thumbnail files for sizes that are currently disabled.
	 */
	public static function delete_disabled_files( $attachment_id, $metadata = null ) {
		if ( null === $metadata ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}

		if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) ) {
			return 0;
		}

		$disabled  = MUS_Image_Sizes::get_disabled();
		if ( empty( $disabled ) ) {
			return 0;
		}

		$upload_dir = wp_get_upload_dir();
		$file       = get_attached_file( $attachment_id );
		$file_dir   = trailingslashit( dirname( $file ) );
		$deleted    = 0;

		foreach ( $disabled as $size_name ) {
			if ( ! isset( $metadata['sizes'][ $size_name ] ) ) {
				continue;
			}

			$size_file = $metadata['sizes'][ $size_name ]['file'];
			$filepath  = $file_dir . $size_file;

			if ( file_exists( $filepath ) ) {
				wp_delete_file( $filepath );
				$deleted++;
			}

			unset( $metadata['sizes'][ $size_name ] );
		}

		if ( $deleted > 0 ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		return $deleted;
	}

	/**
	 * Clean up disabled size files for a batch of attachments (without regenerating).
	 */
	public static function cleanup_batch( $offset = 0, $limit = 20 ) {
		$ids       = self::get_image_ids( $offset, $limit );
		$total     = self::count_images();
		$processed = 0;
		$cleaned   = 0;

		foreach ( $ids as $id ) {
			$cleaned += self::delete_disabled_files( (int) $id );
			$processed++;
		}

		return array(
			'processed' => $offset + $processed,
			'cleaned'   => $cleaned,
			'total'     => $total,
			'complete'  => ( $offset + $processed ) >= $total,
		);
	}

	/**
	 * Get the path to the original full-size image.
	 */
	private static function get_fullsizepath( $attachment_id ) {
		if ( function_exists( 'wp_get_original_image_path' ) ) {
			$path = wp_get_original_image_path( $attachment_id );
			if ( $path && file_exists( $path ) ) {
				return $path;
			}
		}
		return get_attached_file( $attachment_id );
	}
}
