<?php
/**
 * Admin page registration, asset enqueuing, and HTML rendering.
 *
 * @package MediaUsageScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MUS_Admin {

	const PAGE_SLUG = 'media-usage-scanner';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function add_menu() {
		add_submenu_page(
			'upload.php',
			__( 'Media Usage Scanner', 'media-usage-scanner' ),
			__( 'Usage Scanner', 'media-usage-scanner' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'mus-admin',
			MUS_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			MUS_VERSION
		);

		wp_enqueue_script(
			'mus-admin',
			MUS_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			MUS_VERSION,
			true
		);

		$cached_index = ( new MUS_Scanner() )->load_index();

		wp_localize_script(
			'mus-admin',
			'mus_cfg',
			array(
				'ajax_url'        => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( MUS_Ajax::NONCE_ACTION ),
				'batch_size'      => 50,
				'batch_delay_ms'  => (int) get_option( 'mus_batch_delay_ms', 250 ),
				'has_cached_scan' => (bool) $cached_index,
				'backups_enabled' => (bool) get_option( 'mus_backups_enabled', true ),
				'strings'         => array(
					'confirm_delete'           => __( 'Create a ZIP backup and permanently delete selected items? This cannot be undone.', 'media-usage-scanner' ),
					'confirm_delete_no_backup' => __( 'Permanently delete selected items? ZIP backups are disabled, so this cannot be undone and there will be nothing to restore.', 'media-usage-scanner' ),
					'delete_btn_backup'        => __( 'Export ZIP + Delete Selected', 'media-usage-scanner' ),
					'delete_btn_no_backup'     => __( 'Delete Selected', 'media-usage-scanner' ),
					'building_index'           => __( 'Building usage index…', 'media-usage-scanner' ),
					'scanning'                 => __( 'Scanning…', 'media-usage-scanner' ),
					'scan_complete'            => __( 'Scan complete.', 'media-usage-scanner' ),
					'scan_failed'              => __( 'Scan failed.', 'media-usage-scanner' ),
					'loading_cached'           => __( 'Loading last scan results…', 'media-usage-scanner' ),
					'finding_dupes'            => __( 'Finding duplicates…', 'media-usage-scanner' ),
					'dupes_complete'           => __( 'Duplicate scan complete.', 'media-usage-scanner' ),
					'preparing_zip'            => __( 'Preparing ZIP backup…', 'media-usage-scanner' ),
					'deleting'                 => __( 'Deleting…', 'media-usage-scanner' ),
					'no_items'                 => __( 'No items found.', 'media-usage-scanner' ),
					'settings_saved'           => __( 'Settings saved.', 'media-usage-scanner' ),
					'error'                    => __( 'An error occurred.', 'media-usage-scanner' ),
					'scan_btn_first'           => __( 'Scan Media Library', 'media-usage-scanner' ),
					'scan_btn_refresh'         => __( 'Refresh Scan', 'media-usage-scanner' ),
					'last_scanned'             => __( 'Last scanned: %s', 'media-usage-scanner' ),
					'last_scanned_now'         => __( 'Last scanned: just now', 'media-usage-scanner' ),
					'retrying'                 => __( 'Server hiccup — retrying (%1$d/%2$d)…', 'media-usage-scanner' ),
					'scan_paused'              => __( 'Scan paused after a server error. This usually means a temporary hosting limit was hit. What was found so far is shown below — wait a moment and click "Refresh Scan" to continue, or increase the delay between requests in Settings.', 'media-usage-scanner' ),
					'bad_response'             => __( 'The server returned an unexpected response (HTTP %d) instead of data. This usually means a temporary hosting limit (CPU/resource cap) was hit.', 'media-usage-scanner' ),
					'backups_disabled_notice'  => __( 'ZIP backups are currently disabled — files you delete from the Run a Scan tab are removed permanently and cannot be restored. Any backups created before you disabled this are still listed below.', 'media-usage-scanner' ),
					'backups_disabled_empty'   => __( 'ZIP backups are currently disabled, so there are none to show. Deleted files are removed permanently. Enable backups in Settings to protect against accidental deletions.', 'media-usage-scanner' ),
				),
				'settings'        => array(
					'enable_cron'      => get_option( 'mus_enable_cron', false ) ? '1' : '',
					'cron_email'       => get_option( 'mus_cron_email', get_option( 'admin_email' ) ),
					'backups_enabled'  => get_option( 'mus_backups_enabled', true ) ? '1' : '',
					'retention_days'   => get_option( 'mus_backup_retention_days', 30 ),
					'scan_theme'       => get_option( 'mus_scan_theme_files', false ) ? '1' : '',
					'batch_delay_ms'   => get_option( 'mus_batch_delay_ms', 250 ),
				),
			)
		);
	}

	public function render() {
		$cached_index = ( new MUS_Scanner() )->load_index();
		?>
		<div class="wrap mus-wrap">
			<h1><?php esc_html_e( 'Media Usage Scanner', 'media-usage-scanner' ); ?></h1>
			<p class="mus-header-desc"><?php esc_html_e( 'Scans your posts, pages, Elementor layouts, widgets, menus, theme settings, and ACF fields to work out which media files are actually in use — and which ones are safe to review for deletion.', 'media-usage-scanner' ); ?></p>
			<hr class="wp-header-end">

			<!-- Main navigation -->
			<nav class="mus-main-tabs" aria-label="<?php esc_attr_e( 'Media Usage Scanner sections', 'media-usage-scanner' ); ?>">
				<a href="#scan" class="mus-main-tab is-active" data-mus-panel="scan"><?php esc_html_e( 'Run a Scan', 'media-usage-scanner' ); ?></a>
				<a href="#backups" class="mus-main-tab" data-mus-panel="backups"><?php esc_html_e( 'Backup Archives', 'media-usage-scanner' ); ?></a>
				<a href="#settings" class="mus-main-tab" data-mus-panel="settings"><?php esc_html_e( 'Settings', 'media-usage-scanner' ); ?></a>
				<a href="#sizes" class="mus-main-tab" data-mus-panel="sizes"><?php esc_html_e( 'Image Size Manager', 'media-usage-scanner' ); ?></a>
				<a href="#regen" class="mus-main-tab" data-mus-panel="regen"><?php esc_html_e( 'Regenerate Thumbnails', 'media-usage-scanner' ); ?></a>
			</nav>

			<!-- Run a Scan -->
			<div class="mus-tab-panel is-active" data-mus-panel="scan">

			<!-- Scan Card -->
			<div class="mus-card">
				<div class="mus-card-header">
					<h2><?php esc_html_e( 'Run a Scan', 'media-usage-scanner' ); ?></h2>
					<p class="mus-card-desc"><?php esc_html_e( 'Builds a fresh usage index and checks every file in your media library against it. Filters below are optional — leave them blank to scan the entire library.', 'media-usage-scanner' ); ?></p>
				</div>
				<div class="mus-card-body">
					<div class="mus-filters">
						<label>
							<span><?php esc_html_e( 'From', 'media-usage-scanner' ); ?></span>
							<input type="date" id="mus-date-from">
						</label>
						<label>
							<span><?php esc_html_e( 'To', 'media-usage-scanner' ); ?></span>
							<input type="date" id="mus-date-to">
						</label>
						<label>
							<span><?php esc_html_e( 'Search', 'media-usage-scanner' ); ?></span>
							<input type="search" id="mus-search" placeholder="<?php echo esc_attr__( 'Filename or title…', 'media-usage-scanner' ); ?>">
						</label>
						<label>
							<span><?php esc_html_e( 'Match', 'media-usage-scanner' ); ?></span>
							<select id="mus-search-mode">
								<option value="contains"><?php esc_html_e( 'Contains', 'media-usage-scanner' ); ?></option>
								<option value="starts"><?php esc_html_e( 'Begins with', 'media-usage-scanner' ); ?></option>
								<option value="ends"><?php esc_html_e( 'Ends with', 'media-usage-scanner' ); ?></option>
								<option value="exact"><?php esc_html_e( 'Exact', 'media-usage-scanner' ); ?></option>
							</select>
						</label>
					</div>

					<div class="mus-buttons">
						<button id="mus-scan-btn" class="button button-primary">
							<?php
							echo $cached_index
								? esc_html__( 'Refresh Scan', 'media-usage-scanner' )
								: esc_html__( 'Scan Media Library', 'media-usage-scanner' );
							?>
						</button>
						<button id="mus-dupes-btn" class="button"><?php esc_html_e( 'Find Duplicates', 'media-usage-scanner' ); ?></button>
						<span class="spinner" id="mus-spinner"></span>
						<span class="mus-last-scanned" id="mus-last-scanned">
							<?php
							if ( $cached_index && ! empty( $cached_index['scanned_at'] ) ) {
								printf(
									/* translators: %s: formatted date/time of the last scan. */
									esc_html__( 'Last scanned: %s', 'media-usage-scanner' ),
									esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $cached_index['scanned_at'] ) )
								);
							}
							?>
						</span>
					</div>

					<p class="description mus-cached-note" id="mus-cached-note" style="<?php echo $cached_index ? '' : 'display:none;'; ?>">
						<?php esc_html_e( 'Showing results from the last scan. Click "Refresh Scan" if files have changed since then.', 'media-usage-scanner' ); ?>
					</p>

					<div class="mus-progress-wrap" id="mus-progress-wrap" style="display:none;">
						<div class="mus-progress-bar">
							<div class="mus-progress-fill" id="mus-progress-fill"></div>
						</div>
						<span class="mus-progress-text" id="mus-progress-text"></span>
					</div>
				</div>
			</div>

			<!-- Summary -->
			<div class="mus-summary" id="mus-summary" style="display:none;">
				<div class="mus-summary-item">
					<span class="mus-summary-num" id="mus-sum-total">0</span>
					<span class="mus-summary-label"><?php esc_html_e( 'Total Files', 'media-usage-scanner' ); ?></span>
				</div>
				<div class="mus-summary-item">
					<span class="mus-summary-num" id="mus-sum-used">0</span>
					<span class="mus-summary-label"><?php esc_html_e( 'Used', 'media-usage-scanner' ); ?></span>
				</div>
				<div class="mus-summary-item mus-summary-item-flag">
					<span class="mus-summary-num" id="mus-sum-unused">0</span>
					<span class="mus-summary-label"><?php esc_html_e( 'Unused', 'media-usage-scanner' ); ?></span>
				</div>
				<div class="mus-summary-item">
					<span class="mus-summary-num" id="mus-sum-size">0</span>
					<span class="mus-summary-label"><?php esc_html_e( 'Unused Size', 'media-usage-scanner' ); ?></span>
				</div>
			</div>

			<!-- Results -->
			<div id="mus-results" style="display:none;">
				<p class="mus-results-desc"><?php esc_html_e( '"Used" files were found referenced somewhere on your site. "Unused" files were not found anywhere and are candidates for cleanup — review them before deleting.', 'media-usage-scanner' ); ?></p>
				<h2 class="nav-tab-wrapper">
					<a href="#" class="nav-tab nav-tab-active" data-tab="all"><?php esc_html_e( 'All Media', 'media-usage-scanner' ); ?> <span class="mus-tab-count" id="mus-count-all"></span></a>
					<a href="#" class="nav-tab" data-tab="unused"><?php esc_html_e( 'Unused', 'media-usage-scanner' ); ?> <span class="mus-tab-count" id="mus-count-unused"></span></a>
					<a href="#" class="nav-tab" data-tab="used"><?php esc_html_e( 'Used', 'media-usage-scanner' ); ?> <span class="mus-tab-count" id="mus-count-used"></span></a>
					<a href="#" class="nav-tab" data-tab="duplicates"><?php esc_html_e( 'Duplicates', 'media-usage-scanner' ); ?> <span class="mus-tab-count" id="mus-count-dupes"></span></a>
				</h2>

				<!-- Client-side filters -->
				<div class="mus-client-filters">
					<label>
						<span><?php esc_html_e( 'Type', 'media-usage-scanner' ); ?></span>
						<select id="mus-filter-type">
							<option value="all"><?php esc_html_e( 'All types', 'media-usage-scanner' ); ?></option>
							<option value="image"><?php esc_html_e( 'Images', 'media-usage-scanner' ); ?></option>
							<option value="video"><?php esc_html_e( 'Video', 'media-usage-scanner' ); ?></option>
							<option value="audio"><?php esc_html_e( 'Audio', 'media-usage-scanner' ); ?></option>
							<option value="document"><?php esc_html_e( 'Documents', 'media-usage-scanner' ); ?></option>
							<option value="font"><?php esc_html_e( 'Fonts', 'media-usage-scanner' ); ?></option>
							<option value="other"><?php esc_html_e( 'Other', 'media-usage-scanner' ); ?></option>
						</select>
					</label>
					<label>
						<span><?php esc_html_e( 'Min size', 'media-usage-scanner' ); ?></span>
						<input type="number" id="mus-filter-minsize" min="0" step="0.1" placeholder="MB">
					</label>
					<label>
						<span><?php esc_html_e( 'Sort by', 'media-usage-scanner' ); ?></span>
						<select id="mus-sort">
							<option value="size_desc"><?php esc_html_e( 'Size (largest)', 'media-usage-scanner' ); ?></option>
							<option value="size_asc"><?php esc_html_e( 'Size (smallest)', 'media-usage-scanner' ); ?></option>
							<option value="date_desc"><?php esc_html_e( 'Date (newest)', 'media-usage-scanner' ); ?></option>
							<option value="date_asc"><?php esc_html_e( 'Date (oldest)', 'media-usage-scanner' ); ?></option>
							<option value="name_asc"><?php esc_html_e( 'Name (A–Z)', 'media-usage-scanner' ); ?></option>
							<option value="name_desc"><?php esc_html_e( 'Name (Z–A)', 'media-usage-scanner' ); ?></option>
						</select>
					</label>
					<label>
						<span><?php esc_html_e( 'Per page', 'media-usage-scanner' ); ?></span>
						<select id="mus-per-page">
							<option value="25">25</option>
							<option value="50" selected>50</option>
							<option value="100">100</option>
							<option value="200">200</option>
						</select>
					</label>
				</div>

				<!-- Toolbar -->
				<div class="tablenav top">
					<div class="alignleft actions">
						<button id="mus-delete-btn" class="button button-link-delete" disabled style="display:none;"><?php echo get_option( 'mus_backups_enabled', true ) ? esc_html__( 'Export ZIP + Delete Selected', 'media-usage-scanner' ) : esc_html__( 'Delete Selected', 'media-usage-scanner' ); ?></button>
						<button id="mus-csv-btn" class="button" style="display:none;"><?php esc_html_e( 'Export CSV', 'media-usage-scanner' ); ?></button>
					</div>
					<div class="mus-pagination" id="mus-pagination"></div>
				</div>

				<!-- Main table -->
				<div id="mus-table-wrap">
					<table class="wp-list-table widefat fixed striped media">
						<thead>
							<tr>
								<td class="manage-column column-cb check-column"><input id="mus-select-all" type="checkbox"></td>
								<th class="manage-column column-thumbnail"><?php esc_html_e( 'Preview', 'media-usage-scanner' ); ?></th>
								<th class="manage-column column-title"><?php esc_html_e( 'Details', 'media-usage-scanner' ); ?></th>
								<th class="manage-column column-status"><?php esc_html_e( 'Status', 'media-usage-scanner' ); ?></th>
								<th class="manage-column column-used-in"><?php esc_html_e( 'Used In', 'media-usage-scanner' ); ?></th>
							</tr>
						</thead>
						<tbody id="mus-tbody"></tbody>
					</table>
				</div>

				<!-- Duplicates view -->
				<div id="mus-dupes-wrap" style="display:none;"></div>

				<!-- Bottom pagination -->
				<div class="tablenav bottom">
					<div class="mus-pagination" id="mus-pagination-bottom"></div>
				</div>
			</div>

			</div><!-- /.mus-tab-panel[scan] -->

			<!-- Backup Archives -->
			<div class="mus-tab-panel" data-mus-panel="backups">
			<div class="mus-card" id="mus-backups-section">
				<div class="mus-card-header">
					<h2><?php esc_html_e( 'Backup Archives', 'media-usage-scanner' ); ?></h2>
					<p class="mus-card-desc"><?php esc_html_e( 'Before any file is deleted, a ZIP backup is created here automatically (unless disabled in Settings). Click Restore to add its files back into the Media Library — where possible, each file gets its original ID back so anything that referenced it (ACF fields, featured images, Elementor widgets) picks it up automatically. Backups are purged based on the retention setting below.', 'media-usage-scanner' ); ?></p>
				</div>
				<div class="mus-card-body">
					<div id="mus-backups-disabled-notice" class="mus-notice-warn" style="display:none;"><p></p></div>
					<div id="mus-backups-list"><em><?php esc_html_e( 'Loading…', 'media-usage-scanner' ); ?></em></div>
					<div id="mus-restore-result" class="mus-restore-result" style="display:none;"></div>
				</div>
			</div>
			</div><!-- /.mus-tab-panel[backups] -->

			<!-- Settings -->
			<div class="mus-tab-panel" data-mus-panel="settings">
			<div class="mus-card" id="mus-settings-section">
				<div class="mus-card-header">
					<h2><?php esc_html_e( 'Settings', 'media-usage-scanner' ); ?></h2>
					<p class="mus-card-desc"><?php esc_html_e( 'Control automatic scanning, backup cleanup, and how thoroughly the scanner checks your theme.', 'media-usage-scanner' ); ?></p>
				</div>
				<div class="mus-card-body">
					<div class="mus-settings-grid">
						<div class="mus-setting-row">
							<label class="mus-setting-label" for="mus-set-cron"><?php esc_html_e( 'Weekly scheduled scan', 'media-usage-scanner' ); ?></label>
							<div class="mus-setting-field">
								<label><input type="checkbox" id="mus-set-cron"> <?php esc_html_e( 'Automatically re-scan the media library once a week', 'media-usage-scanner' ); ?></label>
								<p class="description"><?php esc_html_e( 'Runs in the background and emails a summary to the address below — no need to open this page.', 'media-usage-scanner' ); ?></p>
							</div>
						</div>
						<div class="mus-setting-row">
							<label class="mus-setting-label" for="mus-set-email"><?php esc_html_e( 'Email results to', 'media-usage-scanner' ); ?></label>
							<div class="mus-setting-field">
								<input type="email" id="mus-set-email" class="regular-text">
								<p class="description"><?php esc_html_e( 'Where the weekly scan summary is sent. Only used if the option above is enabled.', 'media-usage-scanner' ); ?></p>
							</div>
						</div>
						<div class="mus-setting-row">
							<label class="mus-setting-label" for="mus-set-backups-enabled"><?php esc_html_e( 'ZIP backups on delete', 'media-usage-scanner' ); ?></label>
							<div class="mus-setting-field">
								<label><input type="checkbox" id="mus-set-backups-enabled"> <?php esc_html_e( 'Create a ZIP backup before permanently deleting files', 'media-usage-scanner' ); ?></label>
								<p class="description"><?php esc_html_e( 'Recommended — lets you restore files if you delete something by mistake. Turn this off to skip the ZIP export entirely and just delete files directly.', 'media-usage-scanner' ); ?></p>
							</div>
						</div>
						<div class="mus-setting-row">
							<label class="mus-setting-label" for="mus-set-retention"><?php esc_html_e( 'Backup retention', 'media-usage-scanner' ); ?></label>
							<div class="mus-setting-field">
								<input type="number" id="mus-set-retention" class="small-text" min="1"> <?php esc_html_e( 'days', 'media-usage-scanner' ); ?>
								<p class="description"><?php esc_html_e( 'ZIP backups older than this are deleted automatically to save disk space. Only applies while ZIP backups are enabled above.', 'media-usage-scanner' ); ?></p>
							</div>
						</div>
						<div class="mus-setting-row">
							<label class="mus-setting-label" for="mus-set-theme"><?php esc_html_e( 'Scan theme files on disk', 'media-usage-scanner' ); ?></label>
							<div class="mus-setting-field">
								<label><input type="checkbox" id="mus-set-theme"> <?php esc_html_e( 'Also check PHP/CSS/HTML files in the active theme for hardcoded media references', 'media-usage-scanner' ); ?></label>
								<p class="description"><?php esc_html_e( 'Catches images referenced directly in template code. Off by default because it can slow down large themes.', 'media-usage-scanner' ); ?></p>
							</div>
						</div>
						<div class="mus-setting-row">
							<label class="mus-setting-label" for="mus-set-batch-delay"><?php esc_html_e( 'Delay between requests', 'media-usage-scanner' ); ?></label>
							<div class="mus-setting-field">
								<input type="number" id="mus-set-batch-delay" class="small-text" min="0" max="5000" step="50"> <?php esc_html_e( 'ms', 'media-usage-scanner' ); ?>
								<p class="description"><?php esc_html_e( 'A short pause between each batch of files while scanning or regenerating. Increase this (e.g. 500–1000ms) if large scans fail with a 503 "server unavailable" error — common on shared hosting with strict resource limits.', 'media-usage-scanner' ); ?></p>
							</div>
						</div>
					</div>
					<div class="mus-setting-actions">
						<button id="mus-save-settings" class="button button-primary"><?php esc_html_e( 'Save Settings', 'media-usage-scanner' ); ?></button>
						<span id="mus-settings-msg" class="mus-inline-msg"></span>
					</div>
				</div>
			</div>
			</div><!-- /.mus-tab-panel[settings] -->

			<!-- Image Size Manager -->
			<div class="mus-tab-panel" data-mus-panel="sizes">
			<div class="mus-card" id="mus-sizes-section">
				<div class="mus-card-header">
					<h2><?php esc_html_e( 'Image Size Manager', 'media-usage-scanner' ); ?></h2>
					<p class="mus-card-desc"><?php esc_html_e( 'Every registered image size generates an extra file on upload. Disable sizes you don\'t use to reduce storage, then regenerate to apply the change to existing images.', 'media-usage-scanner' ); ?></p>
				</div>
				<div class="mus-card-body">
					<div class="mus-setting-row mus-setting-row-bordered">
						<label class="mus-setting-label" for="mus-srcset-toggle"><?php esc_html_e( 'Disable srcset globally', 'media-usage-scanner' ); ?></label>
						<div class="mus-setting-field">
							<label><input type="checkbox" id="mus-srcset-toggle"> <?php esc_html_e( 'Remove srcset/sizes attributes from all images', 'media-usage-scanner' ); ?></label>
							<p class="description"><?php esc_html_e( 'Stops WordPress from offering responsive image variants in the srcset attribute. Rarely needed — only enable if a theme or plugin conflict requires it.', 'media-usage-scanner' ); ?></p>
						</div>
					</div>

					<div id="mus-sizes-table-wrap">
						<p class="description"><em><?php esc_html_e( 'Loading registered image sizes…', 'media-usage-scanner' ); ?></em></p>
					</div>

					<div class="mus-setting-actions">
						<button id="mus-save-sizes" class="button button-primary"><?php esc_html_e( 'Save Size Settings', 'media-usage-scanner' ); ?></button>
						<span id="mus-sizes-msg" class="mus-inline-msg"></span>
					</div>
				</div>
			</div>
			</div><!-- /.mus-tab-panel[sizes] -->

			<!-- Regenerate Thumbnails -->
			<div class="mus-tab-panel" data-mus-panel="regen">
			<div class="mus-card">
				<div class="mus-card-header">
					<h2><?php esc_html_e( 'Regenerate Thumbnails', 'media-usage-scanner' ); ?></h2>
					<p class="mus-card-desc"><?php esc_html_e( 'Re-creates intermediate image files from the originals using only the sizes currently enabled in Image Size Manager. Run this after changing which sizes are enabled.', 'media-usage-scanner' ); ?></p>
				</div>
				<div class="mus-card-body">
					<div class="mus-regen-section">
						<div class="mus-regen-options">
							<label><input type="checkbox" id="mus-regen-delete-disabled" checked> <?php esc_html_e( 'Delete files for disabled sizes during regeneration', 'media-usage-scanner' ); ?></label>
						</div>

						<div class="mus-buttons">
							<button id="mus-regen-btn" class="button button-primary"><?php esc_html_e( 'Regenerate All Thumbnails', 'media-usage-scanner' ); ?></button>
							<button id="mus-cleanup-btn" class="button"><?php esc_html_e( 'Clean Up Disabled Sizes Only', 'media-usage-scanner' ); ?></button>
							<span class="spinner" id="mus-regen-spinner"></span>
						</div>

						<div class="mus-progress-wrap" id="mus-regen-progress-wrap" style="display:none;">
							<div class="mus-progress-bar">
								<div class="mus-progress-fill" id="mus-regen-progress-fill"></div>
							</div>
							<span class="mus-progress-text" id="mus-regen-progress-text"></span>
						</div>

						<div id="mus-regen-result" style="display:none;"></div>
					</div>
				</div>
			</div>
			</div><!-- /.mus-tab-panel[regen] -->

			<p class="mus-footer">
				<?php echo esc_html( 'Media Usage Scanner v' . MUS_VERSION ); ?>
				<span class="mus-footer-sep">&middot;</span>
				<?php esc_html_e( 'Built by', 'media-usage-scanner' ); ?> Hassan Ali &mdash; Coresol Studio
			</p>
		</div>
		<?php
	}
}
