<?php
/**
 * Reverse-index media usage scanner.
 *
 * Scans every known reference source once and builds an attachment_id → labels
 * map that is stored as a JSON file. Subsequent batch requests simply look up
 * attachments against this pre-built index — no per-attachment queries needed.
 *
 * @package MediaUsageScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUS_Scanner {

	/** @var array<int, string[]> attachment_id => list of human-readable usage labels */
	private $usage_map = array();

	/** @var array<string, int[]> url_fragment => list of attachment IDs */
	private $url_to_ids = array();

	/** @var array<int, string[]> attachment_id => list of URL/filename variants */
	private $id_to_urls = array();

	/** @var array<int, array> attachment_id => { filename, relative, mime } */
	private $att_meta = array();

	/** @var string Path to the uploads base directory. */
	private $uploads_baseurl = '';

	/** @var string Path to the uploads base dir on disk. */
	private $uploads_basedir = '';

	/* ───────────────────────────── public API ───────────────────────────── */

	/**
	 * Build the full usage index and persist it to disk.
	 *
	 * @return array{ total: int, scanned_at: int }
	 */
	public function build_index() {
		@set_time_limit( 300 ); // phpcs:ignore

		$upload_dir            = wp_upload_dir();
		$this->uploads_baseurl = trailingslashit( $upload_dir['baseurl'] );
		$this->uploads_basedir = trailingslashit( $upload_dir['basedir'] );

		$this->usage_map = array();
		$this->url_to_ids = array();
		$this->id_to_urls = array();
		$this->att_meta   = array();

		$this->load_attachment_meta();
		$this->build_url_maps();

		$this->scan_site_identity();
		$this->scan_theme_mods();
		$this->scan_featured_images();
		$this->scan_post_content();
		$this->scan_elementor_data();
		$this->scan_elementor_fonts();
		$this->scan_woocommerce_galleries();
		$this->scan_widgets();
		$this->scan_nav_menus();
		$this->scan_postmeta_urls();
		$this->scan_acf_id_fields();
		$this->scan_options();

		if ( get_option( 'mus_scan_theme_files', false ) ) {
			$this->scan_theme_files();
		}

		$index = array(
			'usage_map'  => $this->usage_map,
			'scanned_at' => time(),
			'total'      => count( $this->att_meta ),
		);

		$this->save_index( $index );

		return array(
			'total'      => $index['total'],
			'scanned_at' => $index['scanned_at'],
		);
	}

	/**
	 * Load a previously built index from disk.
	 *
	 * @return array|null
	 */
	public function load_index() {
		$path = $this->get_index_path();

		if ( ! file_exists( $path ) ) {
			return null;
		}

		$json = file_get_contents( $path ); // phpcs:ignore
		if ( ! $json ) {
			return null;
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data['usage_map'] ) ) {
			$data['usage_map'] = array();
		}

		return $data;
	}

	/**
	 * Delete the stored index file.
	 */
	public function clear_index() {
		$path = $this->get_index_path();
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Return a filtered + paginated batch of attachment results
	 * using the pre-built index.
	 *
	 * @param int   $offset  SQL offset.
	 * @param int   $limit   SQL limit.
	 * @param array $filters { date_from, date_to, search_term, search_mode }.
	 * @return array{ items: array, total: int, next_offset: int, complete: bool }
	 */
	public function get_results_batch( $offset, $limit, $filters = array() ) {
		global $wpdb;

		$index = $this->load_index();
		if ( null === $index ) {
			return array(
				'items'       => array(),
				'total'       => 0,
				'next_offset' => 0,
				'complete'    => true,
			);
		}

		$usage_map = $index['usage_map'];

		$where = $this->build_attachment_where( $filters );
		$args  = $where['args'];

		$total_sql = "SELECT COUNT(ID) FROM {$wpdb->posts}" . $where['sql'];
		if ( ! empty( $args ) ) {
			$total_sql = $wpdb->prepare( $total_sql, $args );
		}
		$total = (int) $wpdb->get_var( $total_sql );

		$batch_args   = $args;
		$batch_args[] = max( 1, min( 200, (int) $limit ) );
		$batch_args[] = max( 0, (int) $offset );

		$batch_sql = "SELECT ID, post_title, post_date, post_mime_type
			FROM {$wpdb->posts}
			{$where['sql']}
			ORDER BY ID DESC
			LIMIT %d OFFSET %d";

		$attachments = $wpdb->get_results( $wpdb->prepare( $batch_sql, $batch_args ) );

		$items = array();
		foreach ( $attachments as $att ) {
			$id        = (int) $att->ID;
			$filename  = $this->get_filename( $id );
			$file_path = get_attached_file( $id );
			$file_size = ( $file_path && file_exists( $file_path ) ) ? filesize( $file_path ) : 0;
			$used_in   = isset( $usage_map[ $id ] ) ? $usage_map[ $id ] : array();

			$items[] = array(
				'id'        => $id,
				'title'     => $att->post_title ? $att->post_title : '(no title)',
				'filename'  => $filename ? $filename : '(unknown)',
				'mime'      => $att->post_mime_type,
				'thumbnail' => $this->get_thumb_url( $id, $filename ),
				'date'      => date_i18n( get_option( 'date_format' ), strtotime( $att->post_date ) ),
				'size'      => $file_size ? size_format( $file_size ) : 'Unknown',
				'size_raw'  => (int) $file_size,
				'status'    => empty( $used_in ) ? 'unused' : 'used',
				'used_in'   => array_values( array_unique( $used_in ) ),
			);
		}

		$next_offset = $offset + count( $attachments );

		return array(
			'items'       => $items,
			'total'       => $total,
			'next_offset' => $next_offset,
			'complete'    => $next_offset >= $total || empty( $attachments ),
		);
	}

	/* ──────────────────────── attachment metadata loader ─────────────────── */

	/**
	 * Bulk-load all attachment metadata using raw queries (2 queries total).
	 */
	private function load_attachment_meta() {
		global $wpdb;

		$attached_files = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'"
		);

		$file_map = array();
		foreach ( $attached_files as $row ) {
			$file_map[ (int) $row->post_id ] = $row->meta_value;
		}

		$ids = $wpdb->get_results(
			"SELECT ID, post_mime_type FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);

		foreach ( $ids as $row ) {
			$id       = (int) $row->ID;
			$relative = isset( $file_map[ $id ] ) ? trim( (string) $file_map[ $id ] ) : '';
			$filename = $relative ? wp_basename( $relative ) : '';

			$this->att_meta[ $id ] = array(
				'filename' => $filename,
				'relative' => $relative,
				'mime'     => $row->post_mime_type,
			);
		}
	}

	/**
	 * Build forward and reverse URL maps for all attachments.
	 */
	private function build_url_maps() {
		global $wpdb;

		$meta_rows = $wpdb->get_results(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata'"
		);
		$size_map = array();
		foreach ( $meta_rows as $row ) {
			$data = maybe_unserialize( $row->meta_value );
			if ( is_array( $data ) && ! empty( $data['sizes'] ) ) {
				$size_map[ (int) $row->post_id ] = $data['sizes'];
			}
		}

		foreach ( $this->att_meta as $id => $meta ) {
			$urls = array();

			if ( $meta['relative'] ) {
				$urls[] = $meta['relative'];
				$urls[] = $this->uploads_baseurl . $meta['relative'];

				$parsed = wp_parse_url( $this->uploads_baseurl . $meta['relative'], PHP_URL_PATH );
				if ( $parsed ) {
					$urls[] = $parsed;
				}
			}

			if ( $meta['filename'] ) {
				$urls[] = $meta['filename'];
			}

			$urls = array_unique( array_filter( $urls ) );
			$this->id_to_urls[ $id ] = $urls;

			foreach ( $urls as $url ) {
				$this->url_to_ids[ $url ][] = $id;
			}

			if ( isset( $size_map[ $id ] ) ) {
				foreach ( $size_map[ $id ] as $size_data ) {
					if ( ! empty( $size_data['file'] ) ) {
						$sized_name = $size_data['file'];
						$this->url_to_ids[ $sized_name ][] = $id;

						if ( $meta['relative'] ) {
							$dir_prefix  = trailingslashit( dirname( $meta['relative'] ) );
							$sized_path  = ( '.' === dirname( $meta['relative'] ) ) ? $sized_name : $dir_prefix . $sized_name;
							$this->url_to_ids[ $sized_path ][] = $id;
						}
					}
				}
			}
		}
	}

	/**
	 * Given a URL fragment found in content, return matching attachment IDs.
	 *
	 * @param string $url URL or path fragment.
	 * @return int[]
	 */
	private function find_ids_by_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return array();
		}

		$ids = array();

		if ( isset( $this->url_to_ids[ $url ] ) ) {
			$ids = array_merge( $ids, $this->url_to_ids[ $url ] );
		}

		$basename = wp_basename( $url );
		if ( $basename !== $url && isset( $this->url_to_ids[ $basename ] ) ) {
			$ids = array_merge( $ids, $this->url_to_ids[ $basename ] );
		}

		if ( preg_match( '/^(.+)-\d+x\d+(\.[a-z0-9]+)$/i', $basename, $m ) ) {
			$original = $m[1] . $m[2];
			if ( isset( $this->url_to_ids[ $original ] ) ) {
				$ids = array_merge( $ids, $this->url_to_ids[ $original ] );
			}
		}

		if ( preg_match( '#(?:uploads/)(.+)$#', $url, $m ) ) {
			$rel = $m[1];
			if ( isset( $this->url_to_ids[ $rel ] ) ) {
				$ids = array_merge( $ids, $this->url_to_ids[ $rel ] );
			}
		}

		return array_unique( array_filter( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Find IDs referenced as bare numeric values or within serialized/JSON data.
	 *
	 * @param mixed $value Meta value (scalar, array, or object).
	 * @return int[]
	 */
	private function extract_ids_from_value( $value ) {
		$found = array();

		if ( is_numeric( $value ) && isset( $this->att_meta[ (int) $value ] ) ) {
			$found[] = (int) $value;
		}

		$str = is_scalar( $value ) ? (string) $value : '';
		if ( '' !== $str ) {
			if ( preg_match_all( '#wp-content/uploads/[^\s"\'<>)]+#i', $str, $matches ) ) {
				foreach ( $matches[0] as $url ) {
					$found = array_merge( $found, $this->find_ids_by_url( $url ) );
				}
			}

			if ( preg_match_all( '/"id"\s*:\s*(\d+)/', $str, $jm ) ) {
				foreach ( $jm[1] as $jid ) {
					if ( isset( $this->att_meta[ (int) $jid ] ) ) {
						$found[] = (int) $jid;
					}
				}
			}
		}

		if ( is_array( $value ) ) {
			if ( isset( $value['id'] ) && is_numeric( $value['id'] ) && isset( $this->att_meta[ (int) $value['id'] ] ) ) {
				$found[] = (int) $value['id'];
			}
			foreach ( $value as $sub ) {
				$found = array_merge( $found, $this->extract_ids_from_value( $sub ) );
			}
		}

		return array_unique( $found );
	}

	/* ────────────────────────────── scanners ─────────────────────────────── */

	private function add_usage( $attachment_id, $label ) {
		$attachment_id = (int) $attachment_id;
		$label         = trim( (string) $label );

		if ( $attachment_id < 1 || '' === $label ) {
			return;
		}

		if ( ! isset( $this->usage_map[ $attachment_id ] ) ) {
			$this->usage_map[ $attachment_id ] = array();
		}

		if ( ! in_array( $label, $this->usage_map[ $attachment_id ], true ) ) {
			$this->usage_map[ $attachment_id ][] = $label;
		}
	}

	/**
	 * Site logo + favicon.
	 */
	private function scan_site_identity() {
		$logo = (int) get_theme_mod( 'custom_logo' );
		$icon = (int) get_option( 'site_icon' );

		if ( $logo && isset( $this->att_meta[ $logo ] ) ) {
			$this->add_usage( $logo, __( 'Site Logo', 'media-usage-scanner' ) );
		}
		if ( $icon && isset( $this->att_meta[ $icon ] ) ) {
			$this->add_usage( $icon, __( 'Site Favicon', 'media-usage-scanner' ) );
		}
	}

	/**
	 * All theme_mods (header image, background image, etc.).
	 */
	private function scan_theme_mods() {
		$mods = get_theme_mods();
		if ( ! is_array( $mods ) ) {
			return;
		}

		$skip = array( 'custom_logo', 'sidebars_widgets', 'nav_menu_locations', 'custom_css_post_id' );

		foreach ( $mods as $key => $value ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}

			$ids = $this->extract_ids_from_value( $value );
			foreach ( $ids as $id ) {
				$this->add_usage( $id, sprintf( __( 'Theme Mod: %s', 'media-usage-scanner' ), $key ) );
			}

			if ( is_string( $value ) && preg_match( '#wp-content/uploads/#', $value ) ) {
				$url_ids = $this->find_ids_by_url( $value );
				foreach ( $url_ids as $id ) {
					$this->add_usage( $id, sprintf( __( 'Theme Mod: %s', 'media-usage-scanner' ), $key ) );
				}
			}
		}
	}

	/**
	 * Featured images — single bulk query.
	 */
	private function scan_featured_images() {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT pm.meta_value AS att_id, p.post_title, p.post_type
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE pm.meta_key = '_thumbnail_id'
			   AND p.post_status NOT IN ('trash','auto-draft')"
		);

		foreach ( $rows as $row ) {
			$att_id = (int) $row->att_id;
			if ( $att_id > 0 && isset( $this->att_meta[ $att_id ] ) ) {
				$this->add_usage(
					$att_id,
					sprintf( __( 'Featured Image: %1$s (%2$s)', 'media-usage-scanner' ), $row->post_title ? $row->post_title : '(no title)', $row->post_type )
				);
			}
		}
	}

	/**
	 * Post content — scans all non-attachment/revision posts in batches.
	 */
	private function scan_post_content() {
		global $wpdb;

		$batch  = 500;
		$offset = 0;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_title, post_type, post_content
					 FROM {$wpdb->posts}
					 WHERE post_content LIKE %s
					   AND post_type NOT IN ('attachment','revision')
					   AND post_status NOT IN ('trash','auto-draft')
					 LIMIT %d OFFSET %d",
					'%wp-content/uploads%',
					$batch,
					$offset
				)
			);

			foreach ( $rows as $post ) {
				preg_match_all( '#wp-content/uploads/[^\s"\'<>)]+#i', $post->post_content, $matches );

				$seen = array();
				foreach ( $matches[0] as $url ) {
					$ids = $this->find_ids_by_url( $url );
					foreach ( $ids as $id ) {
						if ( isset( $seen[ $id ] ) ) {
							continue;
						}
						$seen[ $id ] = true;

						if ( 'wp_custom_css' === $post->post_type ) {
							$this->add_usage( $id, __( 'Custom CSS (Customizer)', 'media-usage-scanner' ) );
						} elseif ( 'wp_block' === $post->post_type ) {
							$this->add_usage( $id, sprintf( __( 'Reusable Block: %s', 'media-usage-scanner' ), $post->post_title ? $post->post_title : '(no title)' ) );
						} else {
							$this->add_usage( $id, sprintf( __( 'Content: %1$s (%2$s)', 'media-usage-scanner' ), $post->post_title ? $post->post_title : '(no title)', $post->post_type ) );
						}
					}
				}

				preg_match_all( '/wp:(?:image|gallery|cover|media-text|video|audio|file)[^}]*"id"\s*:\s*(\d+)/', $post->post_content, $block_matches );
				if ( ! empty( $block_matches[1] ) ) {
					foreach ( $block_matches[1] as $bid ) {
						$bid = (int) $bid;
						if ( $bid > 0 && isset( $this->att_meta[ $bid ] ) && ! isset( $seen[ $bid ] ) ) {
							$seen[ $bid ] = true;
							$label = 'wp_block' === $post->post_type
								? sprintf( __( 'Reusable Block: %s', 'media-usage-scanner' ), $post->post_title ? $post->post_title : '(no title)' )
								: sprintf( __( 'Content (Block): %1$s (%2$s)', 'media-usage-scanner' ), $post->post_title ? $post->post_title : '(no title)', $post->post_type );
							$this->add_usage( $bid, $label );
						}
					}
				}
			}

			$offset += $batch;
		} while ( count( $rows ) === $batch );
	}

	/**
	 * Elementor data — parses _elementor_data and _elementor_page_settings.
	 */
	private function scan_elementor_data() {
		global $wpdb;

		$batch  = 100;
		$offset = 0;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_type
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
					 WHERE pm.meta_key IN ('_elementor_data','_elementor_page_settings')
					   AND p.post_status NOT IN ('trash','auto-draft')
					 LIMIT %d OFFSET %d",
					$batch,
					$offset
				)
			);

			foreach ( $rows as $row ) {
				$post_label = ( $row->post_title ? $row->post_title : '(no title)' ) . ' (' . $row->post_type . ')';

				if ( '_elementor_data' === $row->meta_key ) {
					$data = is_string( $row->meta_value ) ? json_decode( $row->meta_value, true ) : null;
					if ( is_array( $data ) ) {
						$this->walk_elementor_elements( $data, $post_label );
					}
				} else {
					$settings = maybe_unserialize( $row->meta_value );
					if ( is_array( $settings ) ) {
						$ids = $this->extract_ids_from_value( $settings );
						foreach ( $ids as $id ) {
							$this->add_usage( $id, sprintf( __( 'Elementor Page Settings: %s', 'media-usage-scanner' ), $post_label ) );
						}
					}
				}
			}

			$offset += $batch;
		} while ( count( $rows ) === $batch );
	}

	/**
	 * Recursively walk Elementor element tree.
	 *
	 * @param array  $elements   Elementor elements array.
	 * @param string $post_label Label for the parent post.
	 */
	private function walk_elementor_elements( $elements, $post_label ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$el_type     = isset( $element['elType'] ) ? $element['elType'] : '';
			$widget_type = isset( $element['widgetType'] ) ? $element['widgetType'] : '';
			$base        = $this->get_elementor_label( $el_type, $widget_type );
			$settings    = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

			$ids = $this->extract_ids_from_value( $settings );
			foreach ( $ids as $id ) {
				$this->add_usage(
					$id,
					/* translators: 1: post label, 2: element type label */
					sprintf( __( 'Elementor: %1$s → %2$s', 'media-usage-scanner' ), $post_label, $base )
				);
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->walk_elementor_elements( $element['elements'], $post_label );
			}
		}
	}

	/**
	 * Human-readable Elementor element label.
	 */
	private function get_elementor_label( $el_type, $widget_type ) {
		if ( 'widget' === $el_type && $widget_type ) {
			return ucwords( str_replace( array( '-', '_' ), ' ', $widget_type ) ) . ' widget';
		}

		$map = array(
			'container' => 'Container',
			'section'   => 'Section',
			'column'    => 'Column',
		);

		return isset( $map[ $el_type ] ) ? $map[ $el_type ] : ucfirst( $el_type );
	}

	/**
	 * Elementor custom font registrations.
	 */
	private function scan_elementor_fonts() {
		if ( ! post_type_exists( 'elementor_font_face' ) ) {
			return;
		}

		$fonts = get_posts(
			array(
				'post_type'      => 'elementor_font_face',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
			)
		);

		foreach ( $fonts as $font ) {
			$meta = get_post_meta( $font->ID );

			foreach ( $meta as $key => $values ) {
				foreach ( (array) $values as $raw ) {
					$data = maybe_unserialize( $raw );
					$ids  = $this->extract_ids_from_value( $data );
					foreach ( $ids as $id ) {
						$this->add_usage( $id, sprintf( __( 'Elementor Font: %s', 'media-usage-scanner' ), $font->post_title ) );
					}
				}
			}
		}
	}

	/**
	 * WooCommerce product gallery images — single query.
	 */
	private function scan_woocommerce_galleries() {
		global $wpdb;

		$rows = $wpdb->get_results(
			"SELECT pm.meta_value, p.ID, p.post_title
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			 WHERE pm.meta_key = '_product_image_gallery'
			   AND pm.meta_value != ''
			   AND p.post_status NOT IN ('trash','auto-draft')"
		);

		foreach ( $rows as $row ) {
			$ids = array_filter( array_map( 'intval', explode( ',', (string) $row->meta_value ) ) );
			foreach ( $ids as $id ) {
				if ( isset( $this->att_meta[ $id ] ) ) {
					$this->add_usage( $id, sprintf( __( 'Product Gallery: %s', 'media-usage-scanner' ), $row->post_title ? $row->post_title : '(no title)' ) );
				}
			}
		}
	}

	/**
	 * Sidebar widgets.
	 */
	private function scan_widgets() {
		$sidebars = get_option( 'sidebars_widgets', array() );
		if ( ! is_array( $sidebars ) ) {
			return;
		}

		foreach ( $sidebars as $sidebar_id => $widgets ) {
			if ( 'wp_inactive_widgets' === $sidebar_id || ! is_array( $widgets ) ) {
				continue;
			}

			foreach ( $widgets as $widget_id ) {
				$base = preg_replace( '/-\d+$/', '', $widget_id );
				$num  = (int) preg_replace( '/^.+-/', '', $widget_id );

				$instances = get_option( 'widget_' . $base, array() );
				if ( ! is_array( $instances ) || ! isset( $instances[ $num ] ) ) {
					continue;
				}

				$ids = $this->extract_ids_from_value( $instances[ $num ] );
				foreach ( $ids as $id ) {
					$this->add_usage( $id, sprintf( __( 'Widget: %1$s (#%2$s)', 'media-usage-scanner' ), $base, $sidebar_id ) );
				}
			}
		}
	}

	/**
	 * Navigation menu items with images.
	 */
	private function scan_nav_menus() {
		$locations = get_nav_menu_locations();
		if ( ! is_array( $locations ) ) {
			return;
		}

		foreach ( $locations as $location => $menu_id ) {
			if ( ! $menu_id ) {
				continue;
			}

			$items = wp_get_nav_menu_items( $menu_id );
			if ( ! is_array( $items ) ) {
				continue;
			}

			$menu_obj  = wp_get_nav_menu_object( $menu_id );
			$menu_name = $menu_obj ? $menu_obj->name : "#{$menu_id}";

			foreach ( $items as $item ) {
				$meta = get_post_meta( $item->ID );
				foreach ( $meta as $key => $values ) {
					foreach ( (array) $values as $val ) {
						$ids = $this->extract_ids_from_value( maybe_unserialize( $val ) );
						foreach ( $ids as $id ) {
							$this->add_usage( $id, sprintf( __( 'Nav Menu: %1$s → %2$s', 'media-usage-scanner' ), $menu_name, $item->title ? $item->title : '(no title)' ) );
						}
					}
				}
			}
		}
	}

	/**
	 * Meta keys already handled by dedicated scanners, so the generic
	 * postmeta passes below should skip them to avoid redundant work.
	 *
	 * @return string[]
	 */
	private function core_skip_meta_keys() {
		return array(
			'_wp_attached_file',
			'_wp_attachment_metadata',
			'_wp_attachment_backup_sizes',
			'_wp_attachment_image_alt',
			'_thumbnail_id',
			'_product_image_gallery',
			'_elementor_data',
			'_elementor_page_settings',
			'_elementor_css',
			'_elementor_controls_usage',
		);
	}

	/**
	 * Post meta containing upload URLs (excluding already-scanned keys).
	 */
	private function scan_postmeta_urls() {
		global $wpdb;

		$skip_keys    = $this->core_skip_meta_keys();
		$placeholders = implode( ',', array_fill( 0, count( $skip_keys ), '%s' ) );

		$batch  = 500;
		$offset = 0;

		do {
			$args   = $skip_keys;
			$args[] = $batch;
			$args[] = $offset;

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_type
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
					 WHERE pm.meta_key NOT IN ({$placeholders})
					   AND p.post_status NOT IN ('trash','auto-draft')
					   AND p.post_type NOT IN ('attachment','revision')
					   AND pm.meta_value LIKE '%wp-content/uploads%'
					 LIMIT %d OFFSET %d",
					$args
				)
			);

			foreach ( $rows as $row ) {
				$decoded = maybe_unserialize( $row->meta_value );
				$ids     = $this->extract_ids_from_value( $decoded );
				foreach ( $ids as $id ) {
					$this->add_usage(
						$id,
						sprintf(
							/* translators: 1: meta key, 2: post title, 3: post type */
							__( 'Meta (%1$s): %2$s (%3$s)', 'media-usage-scanner' ),
							$row->meta_key,
							$row->post_title ? $row->post_title : '(no title)',
							$row->post_type
						)
					);
				}
			}

			$offset += $batch;
		} while ( count( $rows ) === $batch );
	}

	/**
	 * ACF / custom-field-style attachment references stored as bare IDs.
	 *
	 * ACF Image/Gallery fields (and repeater/flexible-content sub-fields)
	 * configured with the "ID" return format persist just a plain integer,
	 * or a serialized array of plain integers — neither of which contains
	 * the "wp-content/uploads" string that {@see scan_postmeta_urls()}
	 * relies on. Those rows are therefore invisible to that scan. This
	 * pass targets exactly that shape: numeric-only meta values and
	 * serialized arrays, and resolves any values that match a known
	 * attachment ID via the same extraction logic used elsewhere.
	 */
	private function scan_acf_id_fields() {
		global $wpdb;

		$skip_keys    = $this->core_skip_meta_keys();
		$placeholders = implode( ',', array_fill( 0, count( $skip_keys ), '%s' ) );

		$batch  = 500;
		$offset = 0;

		do {
			$args   = $skip_keys;
			$args[] = $batch;
			$args[] = $offset;

			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_title, p.post_type
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
					 WHERE pm.meta_key NOT IN ({$placeholders})
					   AND p.post_status NOT IN ('trash','auto-draft')
					   AND p.post_type NOT IN ('attachment','revision')
					   AND pm.meta_value NOT LIKE '%wp-content/uploads%'
					   AND ( pm.meta_value REGEXP '^[0-9]+$' OR pm.meta_value LIKE 'a:%' )
					 LIMIT %d OFFSET %d",
					$args
				)
			);

			foreach ( $rows as $row ) {
				$decoded = maybe_unserialize( $row->meta_value );
				$ids     = $this->extract_ids_from_value( $decoded );
				foreach ( $ids as $id ) {
					$this->add_usage(
						$id,
						sprintf(
							/* translators: 1: meta key, 2: post title, 3: post type */
							__( 'ACF/Custom Field: %1$s → %2$s (%3$s)', 'media-usage-scanner' ),
							$row->meta_key,
							$row->post_title ? $row->post_title : '(no title)',
							$row->post_type
						)
					);
				}
			}

			$offset += $batch;
		} while ( count( $rows ) === $batch );
	}

	/**
	 * Options table (broad LIKE scan for upload URLs).
	 */
	private function scan_options() {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value
				 FROM {$wpdb->options}
				 WHERE option_value LIKE %s
				   AND option_name NOT LIKE '_transient%%'
				   AND option_name NOT LIKE '_site_transient%%'
				 LIMIT 500",
				'%wp-content/uploads%'
			)
		);

		foreach ( $rows as $row ) {
			$decoded = maybe_unserialize( $row->option_value );
			$ids     = $this->extract_ids_from_value( $decoded );
			foreach ( $ids as $id ) {
				$this->add_usage( $id, sprintf( __( 'Option: %s', 'media-usage-scanner' ), $row->option_name ) );
			}
		}
	}

	/**
	 * Scan active theme PHP/CSS files on disk for hardcoded upload URLs.
	 */
	private function scan_theme_files() {
		$theme_dir = get_stylesheet_directory();
		if ( ! is_dir( $theme_dir ) ) {
			return;
		}

		$files = $this->glob_recursive( $theme_dir, '/\.(php|css|html?)$/i' );
		$files = array_slice( $files, 0, 500 );

		foreach ( $files as $file ) {
			if ( filesize( $file ) > 512000 ) {
				continue;
			}

			$content = file_get_contents( $file ); // phpcs:ignore
			if ( false === stripos( $content, 'wp-content/uploads' ) ) {
				continue;
			}

			preg_match_all( '#wp-content/uploads/[^\s"\'<>)]+#i', $content, $matches );
			$relative = str_replace( $theme_dir, '', $file );

			$seen = array();
			foreach ( $matches[0] as $url ) {
				$ids = $this->find_ids_by_url( $url );
				foreach ( $ids as $id ) {
					if ( ! isset( $seen[ $id ] ) ) {
						$seen[ $id ] = true;
						$this->add_usage( $id, sprintf( __( 'Theme File: %s', 'media-usage-scanner' ), $relative ) );
					}
				}
			}
		}
	}

	/* ─────────────────────────────── helpers ─────────────────────────────── */

	/**
	 * Recursively glob files matching a regex.
	 *
	 * @param string $dir   Directory path.
	 * @param string $regex Pattern to match filenames.
	 * @return string[]
	 */
	private function glob_recursive( $dir, $regex ) {
		$results = array();
		$items   = @scandir( $dir );

		if ( ! is_array( $items ) ) {
			return $results;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item || 'node_modules' === $item || 'vendor' === $item ) {
				continue;
			}

			$path = trailingslashit( $dir ) . $item;

			if ( is_dir( $path ) ) {
				$results = array_merge( $results, $this->glob_recursive( $path, $regex ) );
			} elseif ( preg_match( $regex, $item ) ) {
				$results[] = $path;
			}
		}

		return $results;
	}

	private function get_filename( $attachment_id ) {
		if ( isset( $this->att_meta[ $attachment_id ] ) ) {
			return $this->att_meta[ $attachment_id ]['filename'];
		}

		$file_path = get_attached_file( $attachment_id );
		return $file_path ? wp_basename( $file_path ) : '';
	}

	private function get_thumb_url( $attachment_id, $filename = '' ) {
		$src = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );
		if ( $src ) {
			return esc_url_raw( $src[0] );
		}

		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( in_array( $ext, array( 'ttf', 'woff', 'woff2', 'eot', 'otf' ), true ) ) {
			return includes_url( 'images/media/default.png' );
		}

		if ( $ext && file_exists( ABSPATH . WPINC . '/images/media/' . $ext . '.png' ) ) {
			return includes_url( 'images/media/' . $ext . '.png' );
		}

		return includes_url( 'images/media/default.png' );
	}

	/**
	 * Build the WHERE clause for attachment queries with user filters.
	 *
	 * @param array $filters { date_from, date_to, search_term, search_mode }.
	 * @return array{ sql: string, args: array }
	 */
	private function build_attachment_where( $filters ) {
		global $wpdb;

		$where = " WHERE post_type = 'attachment' AND post_status = 'inherit' ";
		$args  = array();

		$from = $this->normalize_date( isset( $filters['date_from'] ) ? $filters['date_from'] : '', false );
		$to   = $this->normalize_date( isset( $filters['date_to'] ) ? $filters['date_to'] : '', true );

		$search_term = isset( $filters['search_term'] ) ? sanitize_text_field( $filters['search_term'] ) : '';
		$search_mode = isset( $filters['search_mode'] ) ? $filters['search_mode'] : 'contains';

		$allowed_modes = array( 'contains', 'starts', 'ends', 'exact' );
		if ( ! in_array( $search_mode, $allowed_modes, true ) ) {
			$search_mode = 'contains';
		}

		if ( $from ) {
			$where .= ' AND post_date >= %s ';
			$args[] = $from;
		}

		if ( $to ) {
			$where .= ' AND post_date <= %s ';
			$args[] = $to;
		}

		if ( '' !== $search_term ) {
			$like = $wpdb->esc_like( $search_term );

			switch ( $search_mode ) {
				case 'starts':
					$search_like = $like . '%';
					break;
				case 'ends':
					$search_like = '%' . $like;
					break;
				case 'exact':
					$search_like = $like;
					break;
				default:
					$search_like = '%' . $like . '%';
			}

			$path_like = $search_like;
			if ( 'starts' === $search_mode || 'exact' === $search_mode ) {
				$path_like = '%/' . $wpdb->esc_like( $search_term ) . ( 'starts' === $search_mode ? '%' : '' );
			}

			$where .= " AND ( post_title LIKE %s OR EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm
				WHERE pm.post_id = {$wpdb->posts}.ID
				AND pm.meta_key = '_wp_attached_file'
				AND ( pm.meta_value LIKE %s OR pm.meta_value LIKE %s )
			) ) ";

			$args[] = $search_like;
			$args[] = $search_like;
			$args[] = $path_like;
		}

		return array(
			'sql'  => $where,
			'args' => $args,
		);
	}

	private function normalize_date( $date, $end_of_day = false ) {
		$date = is_string( $date ) ? trim( $date ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}
		return $date . ( $end_of_day ? ' 23:59:59' : ' 00:00:00' );
	}

	/* ───────────────────────── index persistence ────────────────────────── */

	private function get_index_dir() {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'media-usage-scanner';
		wp_mkdir_p( $dir );
		return $dir;
	}

	private function get_index_path() {
		$user_id = get_current_user_id();
		$suffix  = $user_id ? $user_id : 'cron';
		return trailingslashit( $this->get_index_dir() ) . 'scan-index-' . $suffix . '.json';
	}

	private function save_index( $data ) {
		$path = $this->get_index_path();
		file_put_contents( $path, wp_json_encode( $data ) ); // phpcs:ignore
	}
}
