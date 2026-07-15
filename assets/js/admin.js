/**
 * Media Usage Scanner — Admin JS
 *
 * Handles two-phase scanning, client-side filtering / sorting / pagination,
 * duplicate detection, ZIP/CSV export, backup management, and settings.
 */
(function () {
	'use strict';

	const cfg = window.mus_cfg || {};
	const S   = cfg.strings || {};

	/* ── DOM refs ─────────────────────────────────────────────────────── */

	const $ = (id) => document.getElementById(id);

	const scanBtn     = $('mus-scan-btn');
	const dupesBtn    = $('mus-dupes-btn');
	const deleteBtn   = $('mus-delete-btn');
	const csvBtn      = $('mus-csv-btn');
	const selectAll   = $('mus-select-all');
	const spinner     = $('mus-spinner');
	const progressW   = $('mus-progress-wrap');
	const progressF   = $('mus-progress-fill');
	const progressT   = $('mus-progress-text');
	const summaryEl   = $('mus-summary');
	const resultsEl   = $('mus-results');
	const tbody       = $('mus-tbody');
	const dupesWrap   = $('mus-dupes-wrap');
	const tableWrap   = $('mus-table-wrap');
	const pagTop      = $('mus-pagination');
	const pagBottom   = $('mus-pagination-bottom');
	const tabs        = document.querySelectorAll('.nav-tab-wrapper .nav-tab');
	const lastScanned = $('mus-last-scanned');
	const cachedNote  = $('mus-cached-note');

	/* ── State ────────────────────────────────────────────────────────── */

	let allItems       = [];
	let dupGroups      = [];
	let dupTotalWasted = '';
	let dupCount       = 0;
	let currentTab     = 'all';
	let currentPage    = 1;
	let isBusy         = false;

	/* ── Helpers ──────────────────────────────────────────────────────── */

	function esc(str) {
		const el = document.createElement('span');
		el.textContent = String(str || '');
		return el.innerHTML;
	}

	function getVal(id) {
		const el = $(id);
		return el ? el.value.trim() : '';
	}

	function setProgress(pct, text) {
		progressW.style.display = 'flex';
		progressF.style.width = Math.min(100, Math.max(0, pct)) + '%';
		progressT.textContent = text || '';
	}

	function hideProgress() {
		progressW.style.display = 'none';
		progressF.style.width = '0';
		progressT.textContent = '';
	}

	function busy(on) {
		isBusy = on;
		scanBtn.disabled = on;
		dupesBtn.disabled = on;
		if (spinner) spinner.classList.toggle('is-active', on);
	}

	function markScannedNow() {
		if (lastScanned) lastScanned.textContent = S.last_scanned_now || 'Last scanned: just now';
		if (cachedNote) cachedNote.style.display = 'none';
		if (scanBtn) scanBtn.textContent = S.scan_btn_refresh || 'Refresh Scan';
	}

	function triggerDownload(url, name) {
		const a = document.createElement('a');
		a.href = url;
		if (name) a.setAttribute('download', name);
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
	}

	/* ── AJAX helper ─────────────────────────────────────────────────── */

	async function post(action, data) {
		const params = new URLSearchParams();
		params.append('action', action);
		params.append('nonce', cfg.nonce);

		if (data) {
			Object.keys(data).forEach((k) => {
				const v = data[k];
				if (Array.isArray(v)) {
					v.forEach((item) => params.append(k + '[]', item));
				} else {
					params.append(k, v);
				}
			});
		}

		const res = await fetch(cfg.ajax_url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: params.toString(),
			credentials: 'same-origin',
		});

		const json = await res.json();
		if (!res.ok || !json.success) {
			throw new Error((json.data && json.data.message) || S.error || 'Error');
		}
		return json.data;
	}

	/* ── File-type classifier ────────────────────────────────────────── */

	function fileType(mime) {
		if (!mime) return 'other';
		if (mime.startsWith('image/')) return 'image';
		if (mime.startsWith('video/')) return 'video';
		if (mime.startsWith('audio/')) return 'audio';
		if (/font|woff|ttf|otf|eot/.test(mime)) return 'font';
		if (/pdf|msword|spreadsheet|presentation|text\/|xml|json/.test(mime)) return 'document';
		return 'other';
	}

	function isFontByName(filename) {
		return /\.(woff2?|ttf|otf|eot)$/i.test(filename || '');
	}

	/* ── Filtering / Sorting / Pagination ────────────────────────────── */

	function getFiltered() {
		const typeFilter = getVal('mus-filter-type');
		const minSizeMB  = parseFloat(getVal('mus-filter-minsize')) || 0;
		const minBytes   = minSizeMB * 1024 * 1024;

		return allItems.filter((item) => {
			if (currentTab === 'unused' && item.status !== 'unused') return false;
			if (currentTab === 'used' && item.status !== 'used') return false;

			if (typeFilter !== 'all') {
				const ft = isFontByName(item.filename) ? 'font' : fileType(item.mime);
				if (ft !== typeFilter) return false;
			}

			if (minBytes > 0 && item.size_raw < minBytes) return false;
			return true;
		});
	}

	function getSorted(items) {
		const sort = getVal('mus-sort') || 'size_desc';
		const copy = items.slice();

		copy.sort((a, b) => {
			switch (sort) {
				case 'size_desc': return b.size_raw - a.size_raw;
				case 'size_asc':  return a.size_raw - b.size_raw;
				case 'date_desc': return b.id - a.id;
				case 'date_asc':  return a.id - b.id;
				case 'name_asc':  return (a.filename || '').localeCompare(b.filename || '');
				case 'name_desc': return (b.filename || '').localeCompare(a.filename || '');
				default: return 0;
			}
		});

		return copy;
	}

	function getPerPage() {
		return parseInt(getVal('mus-per-page'), 10) || 50;
	}

	function humanSize(bytes) {
		if (bytes < 1024) return bytes + ' B';
		if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
		if (bytes < 1073741824) return (bytes / 1048576).toFixed(1) + ' MB';
		return (bytes / 1073741824).toFixed(2) + ' GB';
	}

	/* ── Summary ─────────────────────────────────────────────────────── */

	function updateSummary() {
		const used   = allItems.filter((i) => i.status === 'used');
		const unused = allItems.filter((i) => i.status === 'unused');
		const uSize  = unused.reduce((s, i) => s + i.size_raw, 0);

		$('mus-sum-total').textContent  = allItems.length;
		$('mus-sum-used').textContent   = used.length;
		$('mus-sum-unused').textContent = unused.length;
		$('mus-sum-size').textContent   = humanSize(uSize);

		$('mus-count-all').textContent    = allItems.length;
		$('mus-count-unused').textContent = unused.length;
		$('mus-count-used').textContent   = used.length;
		$('mus-count-dupes').textContent  = dupCount || '';

		summaryEl.style.display = 'flex';
	}

	/* ── Render table ────────────────────────────────────────────────── */

	function render() {
		resultsEl.style.display = 'block';

		const isDupeTab = currentTab === 'duplicates';
		tableWrap.style.display  = isDupeTab ? 'none' : '';
		dupesWrap.style.display  = isDupeTab ? 'block' : 'none';
		deleteBtn.style.display  = currentTab === 'unused' ? 'inline-block' : 'none';
		csvBtn.style.display     = isDupeTab ? 'none' : 'inline-block';
		selectAll.disabled       = currentTab !== 'unused';
		selectAll.checked        = false;

		if (isDupeTab) {
			renderDuplicates();
			updateDeleteBtn();
			return;
		}

		const filtered = getFiltered();
		const sorted   = getSorted(filtered);
		const perPage  = getPerPage();
		const totalPages = Math.max(1, Math.ceil(sorted.length / perPage));

		if (currentPage > totalPages) currentPage = totalPages;

		const start = (currentPage - 1) * perPage;
		const page  = sorted.slice(start, start + perPage);

		tbody.innerHTML = '';

		if (page.length === 0) {
			tbody.innerHTML = '<tr><td colspan="5">' + esc(S.no_items || 'No items found.') + '</td></tr>';
			renderPagination(0, 0, pagTop);
			renderPagination(0, 0, pagBottom);
			updateDeleteBtn();
			return;
		}

		page.forEach((item) => {
			const isUsed = item.status === 'used';
			const badge  = isUsed
				? '<span class="mus-badge used">Used</span>'
				: '<span class="mus-badge unused">Unused</span>';

			const cb = !isUsed
				? '<input type="checkbox" class="mus-cb" value="' + item.id + '">'
				: '<input type="checkbox" disabled>';

			let usedHtml;
			if (item.used_in && item.used_in.length) {
				usedHtml = '<ul class="mus-used-list">' +
					item.used_in.map((u) => '<li>' + esc(u) + '</li>').join('') +
					'</ul>';
			} else {
				usedHtml = '<span class="description">Not found in analysis.</span>';
			}

			const tr = document.createElement('tr');
			tr.innerHTML =
				'<th class="check-column">' + cb + '</th>' +
				'<td class="column-thumbnail"><div class="mus-thumb"><img src="' + esc(item.thumbnail) + '" alt=""></div></td>' +
				'<td class="column-title"><strong>' + esc(item.title) + '</strong><br>' +
					'<span class="mus-filename">' + esc(item.filename) + '</span><br>' +
					'<span class="mus-meta">' + esc(item.date) + ' &bull; ' + esc(item.size) + '</span></td>' +
				'<td class="column-status">' + badge + '</td>' +
				'<td class="column-used-in">' + usedHtml + '</td>';

			tbody.appendChild(tr);
		});

		renderPagination(sorted.length, totalPages, pagTop);
		renderPagination(sorted.length, totalPages, pagBottom);
		updateDeleteBtn();
	}

	/* ── Pagination renderer ─────────────────────────────────────────── */

	function renderPagination(total, totalPages, container) {
		if (!container) return;
		container.innerHTML = '';

		if (totalPages <= 1) {
			container.innerHTML = '<span class="mus-page-info">' + total + ' items</span>';
			return;
		}

		const info = document.createElement('span');
		info.className = 'mus-page-info';
		const perPage = getPerPage();
		const from = (currentPage - 1) * perPage + 1;
		const to   = Math.min(currentPage * perPage, total);
		info.textContent = from + '–' + to + ' of ' + total;
		container.appendChild(info);

		const addBtn = (label, page, disabled) => {
			const btn = document.createElement('button');
			btn.className = 'mus-page-btn' + (page === currentPage ? ' active' : '');
			btn.textContent = label;
			btn.disabled = disabled;
			btn.type = 'button';
			if (!disabled && page !== currentPage) {
				btn.addEventListener('click', () => { currentPage = page; render(); });
			}
			container.appendChild(btn);
		};

		addBtn('«', 1, currentPage === 1);
		addBtn('‹', currentPage - 1, currentPage === 1);

		let start = Math.max(1, currentPage - 3);
		let end   = Math.min(totalPages, currentPage + 3);

		if (start > 1) {
			addBtn('1', 1, false);
			if (start > 2) {
				const dot = document.createElement('span');
				dot.className = 'mus-page-info';
				dot.textContent = '…';
				container.appendChild(dot);
			}
		}

		for (let i = start; i <= end; i++) {
			addBtn(String(i), i, false);
		}

		if (end < totalPages) {
			if (end < totalPages - 1) {
				const dot = document.createElement('span');
				dot.className = 'mus-page-info';
				dot.textContent = '…';
				container.appendChild(dot);
			}
			addBtn(String(totalPages), totalPages, false);
		}

		addBtn('›', currentPage + 1, currentPage === totalPages);
		addBtn('»', totalPages, currentPage === totalPages);
	}

	/* ── Duplicates renderer ─────────────────────────────────────────── */

	function renderDuplicates() {
		dupesWrap.innerHTML = '';

		if (!dupGroups.length) {
			dupesWrap.innerHTML = '<p>' + esc(S.no_items || 'No duplicates found. Run "Find Duplicates" first.') + '</p>';
			return;
		}

		const sumDiv = document.createElement('div');
		sumDiv.className = 'mus-dup-summary';
		sumDiv.innerHTML = '<strong>' + dupCount + '</strong> duplicate files wasting <strong>' + esc(dupTotalWasted) + '</strong> of disk space.';
		dupesWrap.appendChild(sumDiv);

		dupGroups.forEach((group) => {
			const div = document.createElement('div');
			div.className = 'mus-dup-group';
			div.innerHTML = '<h4>Hash: ' + esc(group.hash.substring(0, 12)) + '… — ' + group.count + ' copies (wasted: ' + esc(group.wasted) + ')</h4>';

			const items = document.createElement('div');
			items.className = 'mus-dup-items';

			group.items.forEach((item) => {
				const card = document.createElement('div');
				card.className = 'mus-dup-item';
				card.innerHTML =
					'<img src="' + esc(item.thumbnail) + '" alt="">' +
					'<div class="mus-filename">' + esc(item.filename) + '</div>' +
					'<div class="mus-meta">ID: ' + item.id + ' &bull; ' + esc(item.size_fmt) + '</div>';
				items.appendChild(card);
			});

			div.appendChild(items);
			dupesWrap.appendChild(div);
		});
	}

	/* ── Delete button state ─────────────────────────────────────────── */

	function updateDeleteBtn() {
		const checked = document.querySelectorAll('.mus-cb:checked');
		deleteBtn.disabled = checked.length === 0;
		deleteBtn.textContent = checked.length
			? 'Export ZIP + Delete Selected (' + checked.length + ')'
			: 'Export ZIP + Delete Selected';
	}

	/* ── Scan flow ───────────────────────────────────────────────────── */

	async function runScan() {
		if (isBusy) return;
		busy(true);

		allItems    = [];
		currentTab  = 'all';
		currentPage = 1;

		tabs.forEach((t) => t.classList.toggle('nav-tab-active', t.dataset.tab === 'all'));

		try {
			setProgress(0, S.building_index || 'Building usage index…');

			await post('mus_build_index');

			const filters = {
				date_from:   getVal('mus-date-from'),
				date_to:     getVal('mus-date-to'),
				search_term: getVal('mus-search'),
				search_mode: getVal('mus-search-mode'),
			};

			let offset   = 0;
			let total    = 0;
			let complete = false;

			while (!complete) {
				const data = await post('mus_scan_batch', {
					offset: String(offset),
					limit:  String(cfg.batch_size || 50),
					...filters,
				});

				total    = parseInt(data.total, 10) || 0;
				offset   = parseInt(data.next_offset, 10) || 0;
				complete = !!data.complete;

				if (Array.isArray(data.items)) {
					allItems = allItems.concat(data.items);
				}

				const pct = total > 0 ? Math.round((allItems.length / total) * 100) : 0;
				setProgress(pct, (S.scanning || 'Scanning…') + ' ' + allItems.length + ' / ' + total);
				updateSummary();
				render();
			}

		setProgress(100, (S.scan_complete || 'Scan complete.') + ' ' + allItems.length + ' / ' + total);
		updateSummary();
		render();
		markScannedNow();

		try { await findDuplicates(); } catch (e) { /* duplicate scan is optional */ }
	} catch (err) {
		console.error(err);
		alert((S.scan_failed || 'Scan failed.') + ' ' + (err.message || ''));
	} finally {
		busy(false);
		updateSummary();
		render();
		setTimeout(hideProgress, 4000);
	}
	}

	/* ── Load previously cached scan results (no rebuild) ──────────────── */

	async function loadCachedResults() {
		if (isBusy) return;
		busy(true);

		allItems    = [];
		currentTab  = 'all';
		currentPage = 1;

		tabs.forEach((t) => t.classList.toggle('nav-tab-active', t.dataset.tab === 'all'));

		try {
			setProgress(0, S.loading_cached || 'Loading last scan results…');

			const filters = {
				date_from:   getVal('mus-date-from'),
				date_to:     getVal('mus-date-to'),
				search_term: getVal('mus-search'),
				search_mode: getVal('mus-search-mode'),
			};

			let offset   = 0;
			let total    = 0;
			let complete = false;

			while (!complete) {
				const data = await post('mus_scan_batch', {
					offset: String(offset),
					limit:  String(cfg.batch_size || 50),
					...filters,
				});

				total    = parseInt(data.total, 10) || 0;
				offset   = parseInt(data.next_offset, 10) || 0;
				complete = !!data.complete;

				if (Array.isArray(data.items)) {
					allItems = allItems.concat(data.items);
				}

				updateSummary();
				render();
			}
		} catch (err) {
			console.error(err);
		} finally {
			busy(false);
			updateSummary();
			render();
			hideProgress();
		}
	}

	/* ── Duplicate scan core ─────────────────────────────────────────── */

	async function findDuplicates() {
		dupGroups      = [];
		dupTotalWasted = '';
		dupCount       = 0;

		let offset   = 0;
		let complete = false;

		while (!complete) {
			const data = await post('mus_find_duplicates', {
				offset: String(offset),
				limit:  '100',
				reset:  offset === 0 ? '1' : '0',
			});

			const total = parseInt(data.total, 10) || 1;
			const proc  = parseInt(data.processed, 10) || 0;
			complete    = !!data.complete;
			offset      = proc;

			setProgress(
				Math.round((proc / total) * 100),
				(S.finding_dupes || 'Finding duplicates…') + ' ' + proc + ' / ' + total
			);

			if (complete && data.groups) {
				dupGroups      = data.groups;
				dupTotalWasted = data.total_wasted || '0 B';
				dupCount       = data.duplicate_count || 0;
			}
		}

		$('mus-count-dupes').textContent = dupCount || '';
	}

	/* ── Duplicate scan flow (standalone button) ─────────────────────── */

	async function runDupeScan() {
		if (isBusy) return;
		busy(true);

		try {
			setProgress(0, S.finding_dupes || 'Finding duplicates…');
			await findDuplicates();
			setProgress(100, S.dupes_complete || 'Duplicate scan complete.');

			currentTab = 'duplicates';
			tabs.forEach((t) => t.classList.toggle('nav-tab-active', t.dataset.tab === 'duplicates'));
			render();
		} catch (err) {
			console.error(err);
			alert((S.error || 'Error') + ' ' + (err.message || ''));
		} finally {
			busy(false);
			setTimeout(hideProgress, 4000);
		}
	}

	/* ── Delete flow (ZIP + delete) ──────────────────────────────────── */

	async function runDelete() {
		if (!confirm(S.confirm_delete || 'Proceed?')) return;

		const checked = document.querySelectorAll('.mus-cb:checked');
		const ids = Array.from(checked).map((cb) => cb.value);
		if (!ids.length) return;

		try {
			busy(true);
			setProgress(50, S.preparing_zip || 'Preparing ZIP backup…');

			const zipData = await post('mus_export_zip', { ids });
			triggerDownload(zipData.download_url, zipData.filename || '');

			setProgress(75, S.deleting || 'Deleting…');

			const delData = await post('mus_delete_media', {
				ids,
				backup_file: zipData.filename || '',
			});

			const skipped = Array.isArray(delData.skipped) ? delData.skipped.map(String) : [];
			allItems = allItems.filter((item) => {
				return skipped.includes(String(item.id)) || !ids.includes(String(item.id));
			});

			let msg = 'ZIP backup downloaded. Deleted ' + delData.deleted + ' items.';
			if (skipped.length) {
				msg += ' ' + skipped.length + ' skipped (usage detected or deletion failed).';
			}
			alert(msg);

			updateSummary();
			render();
			setProgress(100, '');
		} catch (err) {
			console.error(err);
			alert((S.error || 'Error') + ' ' + (err.message || ''));
			updateDeleteBtn();
		} finally {
			busy(false);
			setTimeout(hideProgress, 3000);
		}
	}

	/* ── CSV export ──────────────────────────────────────────────────── */

	async function runCSV() {
		if (isBusy) return;

		try {
			busy(true);
			const filtered = getSorted(getFiltered());
			const data = await post('mus_export_csv', { items: JSON.stringify(filtered) });
			triggerDownload(data.download_url, data.filename || 'export.csv');
		} catch (err) {
			console.error(err);
			alert((S.error || 'Error') + ' ' + (err.message || ''));
		} finally {
			busy(false);
		}
	}

	/* ── Backups panel ───────────────────────────────────────────────── */

	async function loadBackups() {
		try {
			const data    = await post('mus_get_backups');
			const list    = $('mus-backups-list');
			const backups = data.backups || [];

			if (!backups.length) {
				list.innerHTML = '<em>No backups yet.</em>';
				return;
			}

			list.innerHTML = '';
			backups.forEach((b) => {
				const row = document.createElement('div');
				row.className = 'mus-backup-row';
				row.innerHTML =
					'<span class="mus-backup-name">' + esc(b.filename) + '</span>' +
					'<span class="mus-backup-meta">' + esc(b.size) + ' &bull; ' + esc(b.date) + '</span>' +
					'<a href="' + esc(b.url) + '" class="button button-small" download>Download</a>' +
					'<button class="button button-small mus-restore-backup" data-file="' + esc(b.filename) + '">Restore</button>' +
					'<button class="button button-small button-link-delete mus-del-backup" data-file="' + esc(b.filename) + '">Delete</button>';
				list.appendChild(row);
			});

			list.querySelectorAll('.mus-del-backup').forEach((btn) => {
				btn.addEventListener('click', async () => {
					if (!confirm('Delete this backup?')) return;
					await post('mus_delete_backup', { filename: btn.dataset.file });
					loadBackups();
				});
			});

			list.querySelectorAll('.mus-restore-backup').forEach((btn) => {
				btn.addEventListener('click', () => runRestore(btn));
			});
		} catch (err) {
			$('mus-backups-list').innerHTML = '<em>Could not load backups.</em>';
		}
	}

	/* ── Restore flow ─────────────────────────────────────────────────── */

	async function runRestore(btn) {
		const filename = btn.dataset.file;
		if (!filename) return;

		const original = btn.textContent;
		btn.disabled = true;
		btn.textContent = 'Checking…';

		let preview = null;
		try {
			preview = await post('mus_preview_restore', { filename });
		} catch (err) {
			/* If the check fails, fall through to the normal confirm below rather than blocking the restore. */
		}

		btn.disabled = false;
		btn.textContent = original;

		let message = 'Restore files from "' + filename + '" into the Media Library?\n\n' +
			'Where possible, each file is restored with its original ID, so places that referenced it ' +
			'by ID (ACF fields, featured images, Elementor widgets, etc.) should pick it up automatically. ' +
			'If its original ID is no longer free, it will be added as a new item instead.';

		if (preview && preview.previously_restored_count > 0) {
			const repeats = (preview.files || []).filter((f) => f.restored_before);
			message = '⚠ ' + repeats.length + ' file(s) in this backup have already been restored before:\n\n' +
				repeats.map((f) => {
					const lastDate = f.previous_restores[f.previous_restores.length - 1];
					return '• ' + f.filename + ' — restored ' + f.restore_count + 'x before (last: ' + lastDate + ')';
				}).join('\n') +
				'\n\nRestoring again will add another copy of each as a new Media Library item (or reclaim its original ID, if still free). Continue?';
		}

		if (!confirm(message)) {
			return;
		}

		const resultEl = $('mus-restore-result');
		btn.disabled = true;
		btn.textContent = 'Restoring…';
		if (resultEl) resultEl.style.display = 'none';

		try {
			const data = await post('mus_restore_backup', { filename });
			renderRestoreResult(data);
		} catch (err) {
			renderRestoreResult({ restored: [], errors: [err.message || (S.error || 'Error')] });
		} finally {
			btn.disabled = false;
			btn.textContent = original;
		}
	}

	function renderRestoreHistory(r) {
		const prior = r.previous_restores || [];
		if (!prior.length) return '';

		let out = ' <span class="mus-badge-sm mus-badge-warn">Restored before (' + prior.length + 'x)</span>';
		out += '<ul class="mus-restore-history">';
		prior.forEach((p, i) => {
			out += '<li>' + (i + 1) + '. ' + esc(p.date) + (p.by ? ' — <span class="description">' + esc(p.by) + '</span>' : '') + '</li>';
		});
		out += '<li>' + (prior.length + 1) + '. ' + esc('Just now') + ' — <span class="description">this restore</span></li>';
		out += '</ul>';
		return out;
	}

	function renderRestoreResult(data) {
		const el = $('mus-restore-result');
		if (!el) return;

		const restored = data.restored || [];
		const errors   = data.errors || [];

		let html = '';

		if (restored.length) {
			const reusedCount = restored.filter((r) => r.id_reused).length;

			html += '<p><strong>' + restored.length + '</strong> file(s) restored to the Media Library:</p>';
			html += '<ul class="mus-used-list">';
			restored.forEach((r) => {
				html += '<li><a href="' + esc(r.edit_url) + '" target="_blank" rel="noopener">' + esc(r.filename) + '</a> ' +
					(r.id_reused
						? '<span class="mus-badge-sm mus-badge-crop">Original ID restored</span>'
						: '<span class="mus-badge-sm">New item</span>') +
					(r.renamed ? ' <span class="description">(renamed from ' + esc(r.original_name) + ')</span>' : '') +
					(r.restored_before ? renderRestoreHistory(r) : '') +
					'</li>';
			});
			html += '</ul>';

			if (reusedCount) {
				html += '<p class="description">' + reusedCount + ' file(s) were restored with their original ID — anything that referenced them by ID (ACF fields, featured images, Elementor widgets, etc.) should work again automatically.</p>';
			}
			if (reusedCount < restored.length) {
				html += '<p class="description">' + (restored.length - reusedCount) + ' file(s) got a new ID (their original ID wasn\'t available or wasn\'t on record) — you may need to re-attach these manually wherever they were used.</p>';
			}
		}

		if (errors.length) {
			html += '<p style="color:#a94442;"><strong>' + errors.length + '</strong> file(s) could not be restored:</p>';
			html += '<ul class="mus-used-list">' + errors.map((e) => '<li>' + esc(e) + '</li>').join('') + '</ul>';
		}

		if (!restored.length && !errors.length) {
			html = '<p>No files were found inside that backup.</p>';
		}

		el.innerHTML = html;
		el.style.display = 'block';
	}

	/* ── Settings ────────────────────────────────────────────────────── */

	function initSettings() {
		const s = cfg.settings || {};
		const cronEl      = $('mus-set-cron');
		const emailEl     = $('mus-set-email');
		const retentionEl = $('mus-set-retention');
		const themeEl     = $('mus-set-theme');

		if (cronEl) cronEl.checked           = !!s.enable_cron;
		if (emailEl) emailEl.value           = s.cron_email || '';
		if (retentionEl) retentionEl.value    = s.retention_days || 30;
		if (themeEl) themeEl.checked          = !!s.scan_theme;

		$('mus-save-settings').addEventListener('click', async () => {
			try {
				const data = await post('mus_save_settings', {
					enable_cron:      cronEl && cronEl.checked ? '1' : '0',
					cron_email:       emailEl ? emailEl.value : '',
					retention_days:   retentionEl ? retentionEl.value : '30',
					scan_theme_files: themeEl && themeEl.checked ? '1' : '0',
				});

				$('mus-settings-msg').textContent = data.message || S.settings_saved || 'Saved.';
				setTimeout(() => { $('mus-settings-msg').textContent = ''; }, 4000);

				if (data.backups_purged > 0) loadBackups();
			} catch (err) {
				$('mus-settings-msg').textContent = (S.error || 'Error') + ' ' + (err.message || '');
			}
		});
	}

	/* ── Main section tabs (Run a Scan / Backups / Settings / Sizes / Regen) ── */

	function initMainTabs() {
		const mainTabs = document.querySelectorAll('.mus-main-tab');
		const panels   = document.querySelectorAll('.mus-tab-panel');

		if (!mainTabs.length) return;

		function activate(name) {
			mainTabs.forEach((t) => t.classList.toggle('is-active', t.dataset.musPanel === name));
			panels.forEach((p) => p.classList.toggle('is-active', p.dataset.musPanel === name));
		}

		mainTabs.forEach((tab) => {
			tab.addEventListener('click', (e) => {
				e.preventDefault();
				const name = tab.dataset.musPanel;
				activate(name);
				if (window.history && window.history.replaceState) {
					window.history.replaceState(null, '', '#' + name);
				}
			});
		});

		const requested = (window.location.hash || '').replace('#', '');
		const isValid    = Array.from(mainTabs).some((t) => t.dataset.musPanel === requested);
		activate(isValid ? requested : 'scan');
	}

	/* ── Event bindings ──────────────────────────────────────────────── */

	function init() {
		if (!scanBtn || !tbody) return;

		initMainTabs();

		scanBtn.addEventListener('click', runScan);
		dupesBtn.addEventListener('click', runDupeScan);
		deleteBtn.addEventListener('click', runDelete);
		csvBtn.addEventListener('click', runCSV);

		tabs.forEach((tab) => {
			tab.addEventListener('click', (e) => {
				e.preventDefault();
				tabs.forEach((t) => t.classList.remove('nav-tab-active'));
				tab.classList.add('nav-tab-active');
				currentTab  = tab.dataset.tab || 'all';
				currentPage = 1;
				selectAll.checked = false;
				render();
			});
		});

		selectAll.addEventListener('change', () => {
			document.querySelectorAll('.mus-cb:not(:disabled)').forEach((cb) => {
				cb.checked = selectAll.checked;
			});
			updateDeleteBtn();
		});

		tbody.addEventListener('change', (e) => {
			if (e.target.classList.contains('mus-cb')) updateDeleteBtn();
		});

		['mus-filter-type', 'mus-filter-minsize', 'mus-sort', 'mus-per-page'].forEach((id) => {
			const el = $(id);
			if (el) el.addEventListener('change', () => { currentPage = 1; render(); });
		});

		const searchEl = $('mus-search');
		if (searchEl) {
			searchEl.addEventListener('keydown', (e) => {
				if (e.key === 'Enter' && !isBusy) { e.preventDefault(); runScan(); }
			});
		}

		initSettings();
		loadBackups();
		initImageSizes();

		if (cfg.has_cached_scan) {
			loadCachedResults();
		}
	}

	/* ── Image Size Manager ─────────────────────────────────────────── */

	let sizesData = [];

	function initImageSizes() {
		const saveBtn    = $('mus-save-sizes');
		const regenBtn   = $('mus-regen-btn');
		const cleanupBtn = $('mus-cleanup-btn');

		if (!saveBtn) return;

		loadImageSizes();

		saveBtn.addEventListener('click', saveImageSizes);
		regenBtn.addEventListener('click', runRegenerate);
		cleanupBtn.addEventListener('click', runCleanup);
	}

	async function loadImageSizes() {
		try {
			const data = await post('mus_get_image_sizes');
			sizesData = data.sizes || [];
			const srcsetOff = !!data.srcset_disabled;

			const toggle = $('mus-srcset-toggle');
			if (toggle) toggle.checked = srcsetOff;

			renderSizesTable(sizesData);
		} catch (err) {
			$('mus-sizes-table-wrap').innerHTML = '<p style="color:#d63638;">' + esc(err.message) + '</p>';
		}
	}

	function renderSizesTable(sizes) {
		const wrap = $('mus-sizes-table-wrap');
		if (!sizes.length) {
			wrap.innerHTML = '<p>No registered image sizes found.</p>';
			return;
		}

		let html = '<table class="wp-list-table widefat fixed striped mus-sizes-table">';
		html += '<thead><tr>';
		html += '<th class="mus-st-name">Name</th>';
		html += '<th class="mus-st-dim">Dimensions</th>';
		html += '<th class="mus-st-crop">Crop</th>';
		html += '<th class="mus-st-source">Source</th>';
		html += '<th class="mus-st-toggle">Enabled</th>';
		html += '</tr></thead><tbody>';

		sizes.forEach((s) => {
			const h = s.height === 0 ? 'auto' : s.height;
			const w = s.width === 0 ? 'auto' : s.width;
			const checked = s.enabled ? 'checked' : '';
			const dimStr = w + ' × ' + h;
			const cropStr = s.crop ? '<span class="mus-badge-sm mus-badge-crop">Yes</span>' : '<span class="mus-badge-sm">No</span>';

			let srcClass = 'mus-src-plugin';
			if (s.source === 'Core') srcClass = 'mus-src-core';
			else if (s.source.indexOf('Theme') === 0) srcClass = 'mus-src-theme';

			html += '<tr>';
			html += '<td class="mus-st-name"><code>' + esc(s.name) + '</code></td>';
			html += '<td class="mus-st-dim">' + esc(dimStr) + '</td>';
			html += '<td class="mus-st-crop">' + cropStr + '</td>';
			html += '<td class="mus-st-source"><span class="mus-source-badge ' + srcClass + '">' + esc(s.source) + '</span></td>';
			html += '<td class="mus-st-toggle"><label class="mus-switch"><input type="checkbox" data-size="' + esc(s.name) + '" ' + checked + '><span class="mus-slider"></span></label></td>';
			html += '</tr>';
		});

		html += '</tbody></table>';
		wrap.innerHTML = html;
	}

	async function saveImageSizes() {
		const checkboxes = document.querySelectorAll('#mus-sizes-table-wrap input[type="checkbox"]');
		const disabled = [];
		checkboxes.forEach((cb) => {
			if (!cb.checked && cb.dataset.size) disabled.push(cb.dataset.size);
		});

		const srcsetToggle = $('mus-srcset-toggle');
		const disableSrcset = srcsetToggle && srcsetToggle.checked ? '1' : '0';

		try {
			const data = await post('mus_save_image_sizes', {
				disabled_sizes: JSON.stringify(disabled),
				disable_srcset: disableSrcset,
			});
			$('mus-sizes-msg').textContent = data.message || 'Saved.';
			if (data.sizes) renderSizesTable(data.sizes);
			setTimeout(() => { $('mus-sizes-msg').textContent = ''; }, 5000);
		} catch (err) {
			$('mus-sizes-msg').textContent = (S.error || 'Error') + ' ' + (err.message || '');
		}
	}

	/* ── Regeneration flow ──────────────────────────────────────────── */

	function setRegenProgress(pct, text) {
		const wrap = $('mus-regen-progress-wrap');
		const fill = $('mus-regen-progress-fill');
		const txt  = $('mus-regen-progress-text');
		wrap.style.display = 'flex';
		fill.style.width = Math.min(100, Math.max(0, pct)) + '%';
		txt.textContent = text || '';
	}

	function hideRegenProgress() {
		$('mus-regen-progress-wrap').style.display = 'none';
		$('mus-regen-progress-fill').style.width = '0';
		$('mus-regen-progress-text').textContent = '';
	}

	function setRegenBusy(on) {
		$('mus-regen-btn').disabled = on;
		$('mus-cleanup-btn').disabled = on;
		const sp = $('mus-regen-spinner');
		if (sp) sp.classList.toggle('is-active', on);
	}

	async function runRegenerate() {
		if ($('mus-regen-btn').disabled) return;
		setRegenBusy(true);

		const deleteDisabled = $('mus-regen-delete-disabled').checked ? '1' : '0';
		let offset = 0;
		let totalErrors = 0;
		const resultEl = $('mus-regen-result');
		resultEl.style.display = 'none';

		try {
			setRegenProgress(0, 'Starting regeneration…');

			let complete = false;
			while (!complete) {
				const data = await post('mus_regenerate_batch', {
					offset: String(offset),
					limit: '5',
					delete_disabled: deleteDisabled,
				});

				offset   = data.processed;
				complete = !!data.complete;
				totalErrors += (data.errors || []).length;

				const pct = data.total > 0 ? Math.round((data.processed / data.total) * 100) : 0;
				setRegenProgress(pct, 'Regenerating… ' + data.processed + ' / ' + data.total);
			}

			setRegenProgress(100, 'Regeneration complete!');
			resultEl.style.display = 'block';
			resultEl.className = 'mus-regen-success';
			resultEl.innerHTML = '<strong>Done!</strong> Regenerated thumbnails for ' + offset + ' images.' +
				(totalErrors > 0 ? ' <span style="color:#d63638;">' + totalErrors + ' errors.</span>' : '');
		} catch (err) {
			resultEl.style.display = 'block';
			resultEl.className = 'mus-regen-error';
			resultEl.innerHTML = '<strong>Error:</strong> ' + esc(err.message);
		} finally {
			setRegenBusy(false);
			setTimeout(hideRegenProgress, 5000);
		}
	}

	async function runCleanup() {
		if ($('mus-cleanup-btn').disabled) return;
		setRegenBusy(true);

		let offset = 0;
		let totalCleaned = 0;
		const resultEl = $('mus-regen-result');
		resultEl.style.display = 'none';

		try {
			setRegenProgress(0, 'Cleaning up disabled sizes…');

			let complete = false;
			while (!complete) {
				const data = await post('mus_cleanup_sizes', {
					offset: String(offset),
					limit: '20',
				});

				offset       = data.processed;
				complete     = !!data.complete;
				totalCleaned += data.cleaned || 0;

				const pct = data.total > 0 ? Math.round((data.processed / data.total) * 100) : 0;
				setRegenProgress(pct, 'Cleaning… ' + data.processed + ' / ' + data.total);
			}

			setRegenProgress(100, 'Cleanup complete!');
			resultEl.style.display = 'block';
			resultEl.className = 'mus-regen-success';
			resultEl.innerHTML = '<strong>Done!</strong> Removed ' + totalCleaned + ' files for disabled sizes across ' + offset + ' images.';
		} catch (err) {
			resultEl.style.display = 'block';
			resultEl.className = 'mus-regen-error';
			resultEl.innerHTML = '<strong>Error:</strong> ' + esc(err.message);
		} finally {
			setRegenBusy(false);
			setTimeout(hideRegenProgress, 5000);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
