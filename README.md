# 🔍 Media Usage Scanner

[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://php.net/)
[![Version](https://img.shields.io/badge/Version-2.5.0-orange.svg)](media-usage-scanner.php)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WP--CLI](https://img.shields.io/badge/WP--CLI-supported-46B450.svg)](https://wp-cli.org/)

**Find out which media files are actually in use — and safely clean up the ones that aren't.**

Media Usage Scanner builds a reverse index of every place a file could be referenced across your WordPress site — posts, Elementor, WooCommerce, ACF, widgets, menus, theme mods, and more — then tells you exactly which library items are safe to review for deletion. Nothing gets removed without a ZIP backup, and every backup can be restored with one click, complete with original-ID recovery and full restore history.

---

## ✨ Features

| | Feature | Description |
|---|---------|-------------|
| 🧠 | **Reverse-Index Engine** | Scans every reference source once and builds an attachment → usage map, so lookups are instant instead of re-querying per file |
| 🧩 | **13 Detection Sources** | Post content, Elementor (data + fonts), WooCommerce galleries, widgets, nav menus, theme mods, site identity, postmeta URLs, ACF ID/array fields, options table, and optional theme file scanning |
| 🎯 | **ACF ID-Field Aware** | Catches ACF Image/Gallery fields using the "ID" return format — bare integers and serialized ID arrays that URL-based scanners miss entirely |
| 🗑️ | **Duplicate Finder** | Hashes files to surface exact duplicates and how much disk space they're wasting |
| 💾 | **Automatic ZIP Backups** | Every deletion is backed up to a ZIP archive first — nothing is ever permanently lost by accident. Optional: can be turned off in Settings to delete files directly with no backup |
| ↩️ | **Selective Restore** | Pick exactly which files to bring back from a backup ZIP — restoring each with its **original attachment ID** whenever that ID is still free — so ACF fields, `_thumbnail_id`, Elementor widgets, and `custom_logo` references start working again automatically |
| 🕓 | **Restore History Tracking** | Every restore is logged with date, time, and user; restoring the same file again warns you upfront if it's been restored before |
| ⚡ | **Cached Scan Results** | Scan results persist between visits — no need to re-scan every time you open the page. A "Refresh Scan" button gets you up-to-date data on demand |
| 📅 | **Scheduled Weekly Scans** | Optional background scan with an emailed summary report — no need to open the admin page at all |
| 🖼️ | **Image Size Manager** | Disable image sizes you don't use to stop WordPress generating extra files on every upload |
| 🔁 | **Bulk Thumbnail Regeneration** | Re-creates intermediate image files from originals after changing enabled sizes, with an optional cleanup-only mode |
| 📤 | **CSV / ZIP Export** | Export scan results to CSV, or export + delete selected media as a ZIP backup in one action |
| 🖥️ | **WP-CLI Support** | `wp mus scan`, `wp mus duplicates`, `wp mus delete-unused`, `wp mus regenerate`, `wp mus cleanup-backups` |
| 🎨 | **Clean, Native Admin UI** | WordPress-native styling, tabbed navigation, and clear explanatory copy throughout — no bulky colored panels |

---

## 🧭 Detection Sources

The scanner checks all of the following before marking a file as unused:

| Source | What it catches |
|--------|------------------|
| Post content | `<img>`, `<a>`, background images, and shortcodes across all post types |
| Elementor data | Widget settings and page/section background images stored in `_elementor_data` |
| Elementor fonts | Custom font/icon assets referenced in Elementor global settings |
| WooCommerce galleries | Product featured image + gallery image IDs |
| Featured images | `_thumbnail_id` on any post type |
| Widgets | Legacy widgets and block-based widget areas |
| Navigation menus | Menu item images/custom fields |
| Theme mods | `custom_logo`, custom headers/backgrounds, and other `theme_mod` image settings |
| Site identity | Site icon and other core identity images |
| Postmeta URLs | Any postmeta value containing a `wp-content/uploads` URL |
| ACF ID fields | ACF Image/Gallery fields using the "ID" return format (bare integers or serialized ID arrays) |
| Options table | Site-wide options that store attachment IDs or upload URLs |
| Theme files *(optional)* | Hardcoded references inside the active theme's PHP/CSS/HTML files — off by default, since it can slow down large themes |

---

## 🖥️ Admin Interface

The plugin adds **Media → Usage Scanner**, organized into five tabs:

### Run a Scan
Builds the usage index and lists every attachment with its status (**Used** / **Unused**), size, and everywhere it was found referenced. Supports date-range and filename filters, per-type/size filtering, sorting, and pagination. Results are cached — reopening the page shows your last scan instantly, with a **Refresh Scan** button and a "Last scanned" timestamp so you always know how fresh the data is.

### Backup Archives
Lists every ZIP backup created before a deletion. Each backup can be:
- **Downloaded** for safekeeping, or
- **Restored** — re-imported into the Media Library, reclaiming each file's original attachment ID when possible. If a backup's files have already been restored before, you're warned upfront (with dates and counts) before confirming.

### Settings
Toggle the weekly scheduled scan and its report email, set backup retention (in days), and opt in to scanning theme files on disk.

### Image Size Manager
Disable registered image sizes you don't actually use, and optionally disable `srcset`/`sizes` output globally.

### Regenerate Thumbnails
Re-generates intermediate image files based on currently-enabled sizes, or run a cleanup-only pass that just removes files for disabled sizes.

---

## ↩️ Backup & Restore, in Detail

- **Before any deletion**, the affected files are zipped into a timestamped backup archive under `wp-content/uploads/media-usage-scanner/backups/`, and every deleted file is logged (attachment ID, filename, URL, size, backup reference).
- **Clicking "Restore"** opens a checklist of every file inside that backup (with size and prior-restore status), so you can restore all of them or only the specific ones you need — anything left unchecked stays in the archive, untouched, for later.
- **On restore**, the plugin checks whether the file's original attachment ID is still free. WordPress never re-issues a deleted post ID through normal `AUTO_INCREMENT` inserts, so that ID slot is almost always still open — the plugin uses WordPress's `import_id` mechanism to recreate the attachment at that exact ID. Anything that referenced the file *by ID* (ACF fields, `_thumbnail_id`, Elementor widgets, `custom_logo`, menu items) starts working again immediately, with no manual re-linking.
- If the original ID is no longer available, the file is restored as a new Media Library item instead — clearly flagged in the results.
- Every restore is recorded with a timestamp and the user who performed it. Restoring the same file again shows its full restore history, both as an upfront warning before you confirm and as a per-file breakdown in the results afterward — but only if that earlier restored copy is still sitting in the Media Library. If it was deleted again since, there's nothing to warn about and the file is treated as if it were never restored.

---

## 📋 Requirements

- WordPress 5.8+
- PHP 7.4+
- `ZipArchive` PHP extension (for backups, restore, and ZIP export)

## 📦 Installation

1. Upload the `media-usage-scanner` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress Plugins menu
3. Go to **Media → Usage Scanner**
4. Click **Scan Media Library** to build the initial usage index

## 🖥️ WP-CLI Commands

```bash
# Run a full scan and print a summary table
wp mus scan

# Output results as CSV or JSON instead
wp mus scan --format=csv
wp mus scan --format=json

# Find duplicate files and how much space they waste
wp mus duplicates

# Back up and delete all unused files (with confirmation)
wp mus delete-unused

# Preview what would be deleted, without deleting anything
wp mus delete-unused --dry-run

# Regenerate thumbnails for all images using currently-enabled sizes
wp mus regenerate

# Only clean up files for disabled sizes, without regenerating
wp mus regenerate --cleanup-only

# Purge backup ZIPs older than N days (defaults to the saved setting)
wp mus cleanup-backups --days=30
```

## 🗄️ Database

The plugin creates two tables on activation:

**`{prefix}mus_deletion_log`** — one row per deleted file

| Column | Type | Description |
|--------|------|--------------|
| attachment_id | BIGINT(20) | Original attachment ID |
| filename | VARCHAR(255) | Original filename |
| file_url | VARCHAR(500) | Full URL at time of deletion |
| file_size | BIGINT(20) | File size in bytes |
| deleted_by | BIGINT(20) | User ID who performed the deletion |
| deleted_at | DATETIME | Timestamp of deletion |
| backup_file | VARCHAR(255) | Associated backup ZIP filename |

**`{prefix}mus_restore_log`** — one row per restore event

| Column | Type | Description |
|--------|------|--------------|
| filename | VARCHAR(255) | Original filename that was restored |
| original_attachment_id | BIGINT(20) | Attachment ID before deletion (0 if unknown) |
| new_attachment_id | BIGINT(20) | Attachment ID assigned on restore |
| id_reused | TINYINT(1) | Whether the original ID was successfully reclaimed |
| backup_file | VARCHAR(255) | Backup ZIP this restore came from |
| restored_by | BIGINT(20) | User ID who performed the restore |
| restored_at | DATETIME | Timestamp of the restore |

Both tables — along with all plugin options, scheduled events, and the `media-usage-scanner` uploads folder — are removed cleanly when the plugin is deleted through the WordPress admin.

---

## 📜 Changelog

### 2.8.0
- New Settings toggle: **"ZIP backups on delete"**. Turn it off to skip the ZIP export step entirely and delete files directly — the delete button switches to a plain "Delete Selected" with no backup created. Existing backups made before disabling remain listed and restorable

### 2.7.1
- "Already restored" warnings now only appear if that restored copy is still in the Media Library — if it was deleted again since, the file is treated as never restored

### 2.7.0
- Selective restore — pick exactly which files inside a backup ZIP to bring back instead of restoring everything; unselected files are left untouched in the archive

### 2.6.0
- Fixed 503/timeout errors during large scans on resource-limited hosting: automatic retry with exponential backoff, a configurable delay between batch requests, clearer error messages for non-JSON server responses, and graceful handling of partial scan failures
- Reduced redundant database queries during batch scanning

### 2.5.0
- Upfront warning popup when restoring a backup whose files have already been restored before, with dates and counts, before confirming

### 2.4.1
- Fixed "Last scanned" and backup dates displaying in the wrong timezone (switched from `date_i18n()` to `wp_date()` for raw Unix timestamps)

### 2.4.0
- Scan results are now cached and reloaded automatically on page visit; added a "Refresh Scan" action and "Last scanned" indicator

### 2.3.0
- Restore history tracking — every restore is logged and shown with full date/time detail, including repeat restores of the same file

### 2.2.0
- One-click backup restore, with automatic original-ID recovery where possible
- ACF Image/Gallery "ID" return format field scanning

### 2.1.x
- Professional, WordPress-native admin UI redesign with tabbed navigation

### 2.0.0
- Reverse-index scanning engine, duplicate detection, Image Size Manager, thumbnail regeneration, WP-CLI, and scheduled scans

---

## 🤝 Contributing

1. Fork this repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

Please ensure all code follows WordPress Coding Standards and passes a PHP lint check before submitting.

## 📄 License

This project is licensed under the [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html).

## 👤 Credits

**Author:** Hassan Ali | [Coresol Studio](https://coresolstudio.com)
