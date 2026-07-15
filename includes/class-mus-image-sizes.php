<?php
/**
 * Image size discovery, enable/disable management, and srcset control.
 *
 * @package MediaUsageScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUS_Image_Sizes {

	const OPTION_DISABLED = 'mus_disabled_image_sizes';
	const OPTION_SRCSET   = 'mus_disable_srcset';

	public function __construct() {
		add_filter( 'intermediate_image_sizes_advanced', array( $this, 'filter_sizes_on_upload' ), 999, 3 );

		if ( get_option( self::OPTION_SRCSET, false ) ) {
			add_filter( 'wp_calculate_image_srcset', '__return_false' );
			add_filter( 'wp_img_tag_add_srcset_and_sizes_attr', '__return_false' );
		}
	}

	/**
	 * Remove disabled sizes during upload/regeneration.
	 */
	public function filter_sizes_on_upload( $sizes, $image_meta = array(), $attachment_id = 0 ) {
		$disabled = self::get_disabled();
		if ( empty( $disabled ) ) {
			return $sizes;
		}
		return array_diff_key( $sizes, array_flip( $disabled ) );
	}

	/**
	 * Get all registered image sizes with full metadata.
	 */
	public static function get_all_sizes() {
		$wp_sizes    = wp_get_registered_image_subsizes();
		$disabled    = self::get_disabled();
		$core_names  = array( 'thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048' );
		$theme       = wp_get_theme();
		$theme_name  = $theme->get( 'Name' );
		$sizes       = array();

		foreach ( $wp_sizes as $name => $data ) {
			$source = 'Plugin';
			if ( in_array( $name, $core_names, true ) ) {
				$source = 'Core';
			} elseif ( self::is_theme_size( $name ) ) {
				$source = 'Theme (' . $theme_name . ')';
			}

			$sizes[] = array(
				'name'    => $name,
				'width'   => (int) $data['width'],
				'height'  => (int) $data['height'],
				'crop'    => ! empty( $data['crop'] ),
				'source'  => $source,
				'enabled' => ! in_array( $name, $disabled, true ),
			);
		}

		usort( $sizes, function ( $a, $b ) {
			$order = array( 'Core' => 0, 'Theme' => 1, 'Plugin' => 2 );
			$oa = 2;
			$ob = 2;
			foreach ( $order as $prefix => $val ) {
				if ( strpos( $a['source'], $prefix ) === 0 ) $oa = $val;
				if ( strpos( $b['source'], $prefix ) === 0 ) $ob = $val;
			}
			if ( $oa !== $ob ) return $oa - $ob;
			return ( $a['width'] * $a['height'] ) - ( $b['width'] * $b['height'] );
		});

		return $sizes;
	}

	/**
	 * Heuristic to detect theme-registered sizes.
	 */
	private static function is_theme_size( $name ) {
		$theme_slug = strtolower( get_template() );
		$name_lower = strtolower( $name );
		$theme_prefixes = array( $theme_slug, str_replace( '-', '_', $theme_slug ) );

		foreach ( $theme_prefixes as $prefix ) {
			if ( strpos( $name_lower, $prefix ) !== false ) {
				return true;
			}
		}

		$theme_indicators = array(
			'post-thumbnail', 'featured', 'header-image', 'blog-',
			'shop_', 'woocommerce_', 'portfolio-', 'gallery-',
		);
		foreach ( $theme_indicators as $indicator ) {
			if ( strpos( $name_lower, $indicator ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the list of disabled size names.
	 */
	public static function get_disabled() {
		$disabled = get_option( self::OPTION_DISABLED, array() );
		return is_array( $disabled ) ? $disabled : array();
	}

	/**
	 * Save the list of disabled sizes.
	 */
	public static function save_disabled( $disabled_names ) {
		$valid = array_keys( wp_get_registered_image_subsizes() );
		$disabled_names = array_values( array_intersect( (array) $disabled_names, $valid ) );
		update_option( self::OPTION_DISABLED, $disabled_names );
	}

	/**
	 * Save srcset preference.
	 */
	public static function save_srcset_setting( $disable ) {
		update_option( self::OPTION_SRCSET, (bool) $disable );
	}

	/**
	 * Check if srcset is disabled.
	 */
	public static function is_srcset_disabled() {
		return (bool) get_option( self::OPTION_SRCSET, false );
	}
}
