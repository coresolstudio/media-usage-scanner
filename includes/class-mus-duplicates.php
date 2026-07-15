<?php
/**
 * Duplicate file detector — groups media files by MD5 checksum.
 *
 * Processes attachments in batches so the UI can show progress.
 *
 * @package MediaUsageScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUS_Duplicates {

	const BATCH_SIZE = 100;

	/**
	 * Process a batch of attachments and return duplicate groups found so far.
	 *
	 * The caller persists $hash_map between calls.
	 *
	 * @param int   $offset   SQL offset.
	 * @param int   $limit    SQL limit.
	 * @param array $hash_map Running hash → items map (passed by reference).
	 * @return array{ processed: int, total: int, complete: bool, hash_map: array }
	 */
	public static function process_batch( $offset, $limit, &$hash_map ) {
		global $wpdb;

		@set_time_limit( 120 ); // phpcs:ignore

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);

		$attachments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_type = 'attachment' AND post_status = 'inherit'
				 ORDER BY ID ASC
				 LIMIT %d OFFSET %d",
				absint( $limit ),
				absint( $offset )
			)
		);

		foreach ( $attachments as $att ) {
			$id        = (int) $att->ID;
			$file_path = get_attached_file( $id );

			if ( ! $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
				continue;
			}

			$size = filesize( $file_path );
			if ( $size > 100 * MB_IN_BYTES ) {
				continue;
			}

			$hash = md5_file( $file_path );
			if ( false === $hash ) {
				continue;
			}

			$thumb_src = wp_get_attachment_image_src( $id, 'thumbnail' );
			$filename  = wp_basename( $file_path );

			$hash_map[ $hash ][] = array(
				'id'        => $id,
				'filename'  => $filename,
				'size'      => $size,
				'size_fmt'  => size_format( $size ),
				'thumbnail' => $thumb_src ? esc_url_raw( $thumb_src[0] ) : includes_url( 'images/media/default.png' ),
				'date'      => get_the_date( get_option( 'date_format' ), $id ),
			);
		}

		$next_offset = $offset + count( $attachments );

		return array(
			'processed' => $next_offset,
			'total'     => $total,
			'complete'  => $next_offset >= $total || empty( $attachments ),
			'hash_map'  => $hash_map,
		);
	}

	/**
	 * Extract only the duplicate groups (hashes with 2+ items).
	 *
	 * @param array $hash_map Full hash → items map.
	 * @return array{ groups: array[], total_wasted: int, duplicate_count: int }
	 */
	public static function extract_groups( $hash_map ) {
		$groups       = array();
		$total_wasted = 0;
		$dup_count    = 0;

		foreach ( $hash_map as $hash => $items ) {
			if ( count( $items ) < 2 ) {
				continue;
			}

			usort(
				$items,
				function ( $a, $b ) {
					return $a['id'] - $b['id'];
				}
			);

			$wasted = 0;
			for ( $i = 1; $i < count( $items ); $i++ ) {
				$wasted += $items[ $i ]['size'];
			}

			$groups[] = array(
				'hash'   => $hash,
				'count'  => count( $items ),
				'wasted' => size_format( $wasted ),
				'items'  => $items,
			);

			$total_wasted += $wasted;
			$dup_count    += count( $items ) - 1;
		}

		usort(
			$groups,
			function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);

		return array(
			'groups'        => $groups,
			'total_wasted'  => size_format( $total_wasted ),
			'duplicate_count' => $dup_count,
		);
	}

	/**
	 * Convenience: load hash map stored in a transient.
	 *
	 * @return array
	 */
	public static function load_hash_map() {
		$map = get_transient( 'mus_hash_map_' . get_current_user_id() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Save hash map into a transient.
	 *
	 * @param array $map Hash map.
	 */
	public static function save_hash_map( $map ) {
		set_transient( 'mus_hash_map_' . get_current_user_id(), $map, HOUR_IN_SECONDS );
	}

	/**
	 * Clear stored hash map.
	 */
	public static function clear_hash_map() {
		delete_transient( 'mus_hash_map_' . get_current_user_id() );
	}
}
