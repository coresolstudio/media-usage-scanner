<?php
/**
 * Plugin Name: Media Usage Scanner
 * Plugin URI:  https://coresolstudio.com
 * Description: Comprehensive media library scanner with reverse-index engine, duplicate detection, selective one-click backup restore, ZIP/CSV export, scheduled scans, WP-CLI, and intelligent usage detection across Elementor, WooCommerce, ACF (including ID-only return format fields), widgets, menus, theme mods, and more.
 * Version:     2.7.0
 * Author:      Hassan Ali | Coresol Studio
 * Author URI:  https://coresolstudio.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: media-usage-scanner
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package MediaUsageScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MUS_VERSION', '2.7.0' );
define( 'MUS_PLUGIN_FILE', __FILE__ );
define( 'MUS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MUS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MUS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

require_once MUS_PLUGIN_DIR . 'includes/class-mus-logger.php';
require_once MUS_PLUGIN_DIR . 'includes/class-mus-scanner.php';
require_once MUS_PLUGIN_DIR . 'includes/class-mus-exporter.php';
require_once MUS_PLUGIN_DIR . 'includes/class-mus-duplicates.php';
require_once MUS_PLUGIN_DIR . 'includes/class-mus-ajax.php';
require_once MUS_PLUGIN_DIR . 'includes/class-mus-admin.php';
require_once MUS_PLUGIN_DIR . 'includes/class-mus-cron.php';
require_once MUS_PLUGIN_DIR . 'includes/class-mus-image-sizes.php';
require_once MUS_PLUGIN_DIR . 'includes/class-mus-regenerator.php';

register_activation_hook( __FILE__, array( 'MUS_Logger', 'create_table' ) );
register_activation_hook( __FILE__, array( 'MUS_Cron', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MUS_Cron', 'deactivate' ) );

add_action( 'plugins_loaded', 'mus_init' );

/**
 * Bootstrap all plugin components.
 */
function mus_init() {
	MUS_Logger::maybe_upgrade();

	new MUS_Admin();
	new MUS_Ajax();
	new MUS_Cron();
	new MUS_Image_Sizes();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once MUS_PLUGIN_DIR . 'includes/class-mus-cli.php';
	WP_CLI::add_command( 'mus', 'MUS_CLI' );
}
