/**
 * Smart Media Auditor & Optimizer - admin interface.
 *
 * The browser never builds table markup. PHP renders every row; this script
 * only polls for change, swaps in server-rendered fragments when it is safe to
 * do so, and drives the forms and actions.
 */
(function () {
	'use strict';

	var model = SMAOModel;
	var t = function (text) { return wp.i18n.__(text, 'smart-media-auditor-optimizer'); };

	var $ = function (selector, scope) { return (scope || document).querySelector(selector); };
	var $$ = function (selector, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(selector)); };

	var latest = null;
	var busy = false;
	var stopped = false;
	var polling = false;
	var failures = 0;
	var working = false;
	var workerFailures = 0;
	var scanId;
	var digest = '';
	var lastNext;
	var pendingRefresh = false;
	var lastStage;

	/* ------------------------------------------------------------ helpers */

	function node(tag, text, className) {
		var el = document.createElement(tag);
		if (text !== undefined) { el.textContent = text; }
		if (className) { el.className = className; }
		return el;
	}

	function put(selector, text, scope) {
		var el = $(selector, scope);
		if (el) { el.textContent = text; }
	}

	/**
	 * Show a message next to the control that caused it, and move focus there
	 * so it is never missed at the bottom of a long table.
	 */
	function toast(message, isError) {
		if (!message) { return; }
		var box = $('#smao-toast');
		if (!box) {
			box = node('div', undefined, 'smao-toast');
			box.id = 'smao-toast';
			box.setAttribute('role', isError ? 'alert' : 'status');
			document.body.append(box);
		}
		box.className = 'smao-toast' + (isError ? ' is-error' : ' is-ok');
		box.textContent = '';
		box.append(node('span', (isError ? '\u2716 ' : '\u2714 ') + message));
		var close = node('button', t('Close'), 'smao-toast-close');
		close.type = 'button';
		close.onclick = function () { box.remove(); };
		box.append(close);
		window.clearTimeout(box._timer);
		if (!isError) {
			box._timer = window.setTimeout(function () { box.remove(); }, 7000);
		}
	}

	function notice(message, isError, anchor) {
		toast(message, isError);
		var host = $('#smao-notice');
		if (!host) { return; }
		host.className = isError ? 'is-error' : '';
		host.textContent = message;
		var target = anchor && anchor.closest('.smao-panel');
		if (target) {
			target.insertAdjacentElement('afterbegin', host);
		}
		host.setAttribute('tabindex', '-1');
		host.focus({ preventScroll: false });
	}

	async function request(route, payload) {
		var controller = new AbortController();
		var timer = window.setTimeout(function () { controller.abort(); }, payload ? 60000 : 20000);
		try {
			var response = await fetch(model.endpoint(smaoConfig.root, route), {
				signal: controller.signal,
				method: payload ? 'POST' : 'GET',
				credentials: 'same-origin',
				cache: 'no-store',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': smaoConfig.nonce
				},
				body: payload ? JSON.stringify(payload) : undefined
			});
			var data = await response.json();
			if (!response.ok) {
				var error = new Error(data.message || t('That request failed. Please try again.'));
				error.code = data.code;
				if (response.status === 401 || response.status === 403) { stopped = true; }
				throw error;
			}
			return data;
		} catch (error) {
			if (error.name === 'AbortError') {
				throw new Error(t('That took too long. The server may still be working, so check the latest checkpoint before retrying.'));
			}
			throw error;
		} finally {
			window.clearTimeout(timer);
		}
	}

	/* ---------------------------------------------------------- selection */

	/** Selection is per region, so two tables on one screen never mix. */
	function scopeOf(element) { return element.closest('[data-scope]') || document; }

	function selectedIn(scope) {
		return model.selected($$('.smao-id:checked', scope).map(function (box) { return box.value; }));
	}

	function refreshSelection(scope) {
		var ids = selectedIn(scope);
		var count = $('.smao-selection-count', scope);
		if (count) {
			var shown = $$('.smao-id', scope).length;
			count.textContent = ids.length === 0
				? t('Nothing selected')
				: (count.dataset.total
					? ids.length + ' ' + t('of') + ' ' + shown + ' ' + t('selected')
					: ids.length + ' ' + t('selected'));
		}
		var boxes = $$('.smao-id', scope);
		var all = $('.smao-select-all', scope);
		if (all) {
			all.checked = boxes.length > 0 && boxes.every(function (box) { return box.checked; });
			all.indeterminate = !all.checked && boxes.some(function (box) { return box.checked; });
		}
		updateActions(scope);
	}

	function updateActions(scope) {
		if (scope === document) { return; }
		var ids = selectedIn(scope);
		// Action buttons live in a bulk bar or a review bar; find them either way.
		var buttons = $$('[data-action]', scope);
		if (!buttons.length) { return; }
		var reviewed = $('.smao-reviewed', scope);
		var ready = !latest || !latest.live || !latest.live.cleanup || latest.live.cleanup.ready;
		buttons.forEach(function (button) {
			var action = button.dataset.action;
			var needsAck = model.requiresTyping(action);
			var blocked = busy
				|| ids.length === 0
				|| ids.length > 50
				|| (needsAck && reviewed && !reviewed.checked)
				|| (action === 'quarantine' && !ready);
			button.disabled = blocked;
			if (action === 'quarantine' && !ready && latest && latest.live && latest.live.cleanup) {
				button.title = latest.live.cleanup.blockers.join(' ');
			} else {
				button.removeAttribute('title');
			}
		});
	}

	function clearSelection(scope) {
		$$('.smao-id', scope).forEach(function (box) { box.checked = false; });
		var reviewed = $('.smao-reviewed', scope);
		if (reviewed) { reviewed.checked = false; }
		refreshSelection(scope);
	}

	document.addEventListener('change', function (event) {
		var target = event.target;
		if (target.matches('.smao-id')) {
			refreshSelection(scopeOf(target));
			return;
		}
		if (target.matches('.smao-select-all')) {
			var scope = scopeOf(target);
			$$('.smao-id', scope).forEach(function (box) { box.checked = target.checked; });
			refreshSelection(scope);
			return;
		}
		if (target.matches('.smao-reviewed')) {
			updateActions(scopeOf(target));
		}
	});

	/* ------------------------------------------------------------- dialog */

	function confirmAction(action, ids, labels) {
		return new Promise(function (resolve) {
			var dialog = node('dialog', undefined, 'smao-dialog');
			var form = document.createElement('form');
			form.method = 'dialog';

			var heading = action === 'quarantine'
				? t('Move these images to safe storage?')
				: action === 'purge'
					? t('Permanently delete these recovery copies?')
					: t('Confirm this action');
			form.append(node('h2', heading));

			var summary = ids.length + ' ' + (ids.length === 1 ? t('file') : t('files'));
			form.append(node('p', summary));
			if (ids.length) {
				var named = ids.map(function (id) {
					return (labels && labels[id]) ? labels[id] : '#' + id;
				});
				form.append(node('div', named.join(', '), 'smao-dialog-list'));
			}

			var input = null;
			if (model.requiresTyping(action) || action === 'cleanup_records') {
				var word = action === 'cleanup_records' ? 'CLEAR' : action.toUpperCase();
				form.append(node('p', t('This cannot be undone.')));
				var label = node('label', t('Type') + ' ' + word);
				input = document.createElement('input');
				input.type = 'text';
				input.required = true;
				input.autocomplete = 'off';
				input.setAttribute('aria-label', t('Confirmation word'));
				label.append(input);
				form.append(label);
			}

			var actions = node('div', undefined, 'smao-dialog-actions');
			var cancel = node('button', t('Cancel'), 'button');
			cancel.type = 'button';
			cancel.onclick = function () { dialog.close(); };
			var submit = node('button', t('Confirm'), 'button button-primary');
			actions.append(cancel, submit);
			form.append(actions);

			form.onsubmit = function (event) {
				event.preventDefault();
				var word = action === 'cleanup_records' ? 'CLEAR' : action.toUpperCase();
				if (input && input.value !== word) {
					input.setCustomValidity(t('That does not match.'));
					input.reportValidity();
					return;
				}
				resolve(input ? input.value : true);
				dialog.close();
			};
			if (input) {
				input.oninput = function () { input.setCustomValidity(''); };
			}

			dialog.append(form);
			document.body.append(dialog);
			dialog.onclose = function () { resolve(null); dialog.remove(); };
			dialog.showModal();
			(input || submit).focus();
		});
	}

	/**
	 * Turn a list of per-file results into something a person can read.
	 *
	 * Printing one line per file produced a wall of identical sentences when a
	 * whole selection failed for the same reason. Group by reason instead, and
	 * lead with what actually happened.
	 */
	function summarize(results, action) {
		var done = results.filter(function (row) { return row.ok; });
		var failed = results.filter(function (row) { return !row.ok; });

		var verb = action === 'quarantine' ? t('removed')
			: action === 'restore' ? t('restored')
				: action === 'purge' ? t('permanently deleted') : t('processed');

		if (!failed.length) {
			return { isError: false, text: done.length + ' ' + (done.length === 1 ? t('file') : t('files')) + ' ' + verb + '.' };
		}

		// Group failures by their reason so one cause reads as one sentence.
		var reasons = [];
		failed.forEach(function (row) {
			var group = reasons.filter(function (item) { return item.message === row.message; })[0];
			if (!group) { group = { message: row.message, ids: [] }; reasons.push(group); }
			group.ids.push(row.id);
		});

		var lines = [];
		lines.push(done.length
			? done.length + ' ' + (done.length === 1 ? t('file') : t('files')) + ' ' + verb + '. '
				+ failed.length + ' ' + (failed.length === 1 ? t('could not be') : t('could not be')) + ' ' + verb + ':'
			: (failed.length === 1
				? t('That file could not be') + ' ' + verb + ':'
				: t('None of the selected files were') + ' ' + verb + '. ' + t('Reason:')));

		reasons.forEach(function (group) {
			var shown = group.ids.slice(0, 8).map(function (id) { return '#' + id; }).join(', ');
			var extra = group.ids.length > 8 ? ' ' + t('and') + ' ' + (group.ids.length - 8) + ' ' + t('more') : '';
			lines.push('');
			lines.push(group.message);
			lines.push(t('Affected') + ': ' + shown + extra);
		});

		return { isError: true, text: lines.join('\n') };
	}

	/* ------------------------------------------------------------ actions */

	async function run(task, anchor) {
		if (busy) { return; }
		busy = true;
		var controls = $$('[data-command], [data-action], form button');
		controls.forEach(function (button) { button.dataset.was = button.disabled ? '1' : ''; button.disabled = true; });
		try {
			await task();
		} catch (error) {
			notice(error.message, true, anchor);
		} finally {
			busy = false;
			controls.forEach(function (button) { button.disabled = button.dataset.was === '1'; });
			$$('[data-scope]').forEach(updateActions);
		}
	}

	$$('[data-action]').forEach(function (button) {
		button.addEventListener('click', function () {
			run(async function () {
				var scope = scopeOf(button);
				var action = button.dataset.action;
				var ids = selectedIn(scope);
				var reviewed = $('.smao-reviewed', scope);
				var acknowledged = reviewed ? reviewed.checked : false;

				if (!ids.length || ids.length > 50) {
					throw new Error(t('Select between 1 and 50 files.'));
				}
				if (model.requiresTyping(action) && !acknowledged) {
					throw new Error(t('Tick the box to confirm you understand this cannot be undone.'));
				}

				var labels = {};
				$$('.smao-id', scope).forEach(function (box) {
					var row = box.closest('.smao-tile') || box.closest('li') || box.parentElement;
					var name = row && (row.querySelector('.smao-tile-name') || row.querySelector('.smao-removed-name') || row.querySelector('.smao-filename'));
					if (name) { labels[Number(box.value)] = name.textContent.trim(); }
				});
				var typed = await confirmAction(action, ids, labels);
				if (typed === null) { return; }
				if (!model.confirmation(action, ids, acknowledged, typed)) { return; }

				var payload = { action: action, ids: ids };
				if (model.requiresTyping(action)) { payload.confirmation = typed; }
				var result = await request('action', payload);

				var summaryText;
				if (result.results) {
					var summary = summarize(result.results, action);
					summaryText = summary.text;
					notice(summary.text, summary.isError, button);
				} else {
					summaryText = result.message;
					notice(result.message, false, button);
				}

				if (result.status) { render(result.status); }
				clearSelection(scope);
				if ($('#smao-report')) {
					await refreshFragment(true);
				} else {
					// The review grid and recovery list are rendered server-side.
					window.sessionStorage.setItem('smaoNotice', summaryText);
					window.location.reload();
				}
			}, button);
		});
	});

	/* ----------------------------------------------------------- commands */

	$$('[data-command]').forEach(function (button) {
		button.addEventListener('click', function () {
			run(async function () {
				var command = button.dataset.command;
				if (command === 'retry') {
					stopped = false;
					render(await request('status'));
					drive();
					return;
				}
				if (command === 'restart' && !window.confirm(t('Start a fresh scan? No media files are changed.'))) {
					return;
				}
				if (command === 'cancel_jobs' && !window.confirm(t('Cancel all queued jobs?'))) {
					return;
				}
				var result = await request('control', { command: command });
				// Cache commands answer with a message and the status inside it.
				if (result && result.message) {
					notice(result.message, false, button);
					if (result.status) { render(result.status); }
				} else {
					render(result);
				}
				await refreshFragment(true);
			}, button);
		});
	});

	var forgetButton = $('#smao-forget');
	if (forgetButton) {
		forgetButton.addEventListener('click', function () {
			run(async function () {
				var field = $('#smao-forget-url');
				var url = field ? field.value.trim() : '';
				if (!url) {
					throw new Error(t('Enter the address of the page to clear.'));
				}
				var result = await request('control', { command: 'cache_forget', url: url });
				notice(result.message, false, forgetButton);
				if (result.status) { render(result.status); }
			}, forgetButton);
		});
	}

	var recordsButton = $('#smao-cleanup-records');
	if (recordsButton) {
		recordsButton.addEventListener('click', function () {
			run(async function () {
				var typed = await confirmAction('cleanup_records', []);
				if (typed === null) { return; }
				var result = await request('control', { command: 'cleanup_records', confirmation: typed });
				notice(result.removed + ' ' + t('old records removed.'), false, recordsButton);
			}, recordsButton);
		});
	}

	/* -------------------------------------------------------------- forms */

	var scopeForm = $('#smao-scope');
	if (scopeForm) {
		scopeForm.addEventListener('submit', function (event) {
			event.preventDefault();
			run(async function () {
				render(await request('control', { command: 'review_scan', scope_reviewed: true }));
				notice(t('Scope confirmed. A fresh scan has started.'), false, scopeForm);
			}, scopeForm);
		});
	}

	var storageForm = $('#smao-storage');
	if (storageForm) {
		storageForm.addEventListener('submit', function (event) {
			event.preventDefault();
			run(async function () {
				var data = Object.fromEntries(new FormData(storageForm));
				await request('storage', data);
				notice(t('Recovery folder checked and saved.'), false, storageForm);
			}, storageForm);
		});
	}

	var runtimeForm = $('#smao-runtime');
	if (runtimeForm) {
		runtimeForm.addEventListener('submit', function (event) {
			event.preventDefault();
			run(async function () {
				var data = new FormData(runtimeForm);
				var payload = Object.fromEntries(data);
				payload.browser_worker = data.has('browser_worker');
				render(await request('runtime', payload));
				notice(t('Processing options applied from the next batch.'), false, runtimeForm);
			}, runtimeForm);
		});
	}

	$$('form[data-settings]').forEach(function (form) {
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			run(async function () {
				var data = new FormData(form);
				var payload = Object.fromEntries(data);
				// Tell the server which checkboxes this tab owns, so unchecked
				// boxes are stored as false without touching other tabs.
				payload._flags = String(payload._flags || '').split(',').filter(Boolean);
				payload._flags.forEach(function (flag) { payload[flag] = data.has(flag); });
				if (form.querySelector('[name="disabled_sizes[]"]')) {
					payload.disabled_sizes = data.getAll('disabled_sizes[]');
				}
				delete payload['disabled_sizes[]'];

				var result = await request('settings', payload);
				applySaved(form, result);
			}, form);
		});
	});

	/** Reflect what the server actually stored, including any clamping. */
	function applySaved(form, result) {
		var messages = [t('Settings saved.')];
		Object.keys(result.adjusted || {}).forEach(function (key) {
			var info = result.adjusted[key];
			var field = form.querySelector('[name="' + key + '"]');
			if (field) { field.value = info.stored; }
			messages.push(
				t('Adjusted') + ' ' + key + ': ' + info.requested + ' -> ' + info.stored
				+ ' (' + t('allowed') + ' ' + info.min + '-' + info.max + ')'
			);
		});
		if (result.invalidated) {
			messages.push(t('Your scan results were invalidated because the scan scope changed. Run a fresh scan.'));
		}
		if (result.status) { render(result.status); }
		notice(messages.join('\n'), false, form);
	}

	/* ------------------------------------------------------------ refresh */

	/** Refreshing must never destroy work in progress. */
	function userIsBusy() {
		if ($('details.smao-evidence[open]')) { return true; }
		return $$('[data-scope]').some(function (scope) { return selectedIn(scope).length > 0; });
	}

	async function refreshFragment(force) {
		var host = $('#smao-report');
		if (!host) { return; }
		var params = new URLSearchParams(location.search);
		params.set('screen', host.dataset.screen || 'audit');

		if (!force) {
			var check = await request('digest?' + params.toString());
			if (check.signature === digest) { return; }
			if (userIsBusy()) { showRefreshPrompt(); return; }
		}

		var data = await request('fragment?' + params.toString());
		digest = data.digest.signature;
		host.innerHTML = data.html;
		removeRefreshPrompt();
		try {
		var carried = window.sessionStorage.getItem('smaoNotice');
		if (carried) {
			window.sessionStorage.removeItem('smaoNotice');
			notice(carried, false, null);
		}
	} catch (error) {
		/* Storage can be unavailable; the notice is a convenience. */
	}

	$$('[data-scope]').forEach(refreshSelection);
	}

	function showRefreshPrompt() {
		if (pendingRefresh) { return; }
		pendingRefresh = true;
		var host = $('#smao-report');
		if (!host) { return; }
		var bar = node('div', undefined, 'smao-refresh-bar');
		bar.id = 'smao-refresh-bar';
		bar.append(node('span', t('New scan results are available.')));
		var button = node('button', t('Refresh the table'), 'button');
		button.type = 'button';
		button.onclick = function () {
			refreshFragment(true).catch(function (error) { notice(error.message, true, button); });
		};
		bar.append(button);
		host.insertAdjacentElement('beforebegin', bar);
	}

	function removeRefreshPrompt() {
		pendingRefresh = false;
		var bar = $('#smao-refresh-bar');
		if (bar) { bar.remove(); }
	}

	/* ------------------------------------------------------------- render */

	function render(data) {
		latest = data;
		var scan = data.scan || {};
		var live = data.live || {};
		var summary = live.summary || {};

		if (scanId !== undefined && scanId !== scan.id) {
			$$('[data-scope]').forEach(clearSelection);
			digest = '';
		}
		if (lastStage !== undefined && lastStage !== stage(scan) && $('#smao-next')) {
			window.location.reload();
			return;
		}
		// Anything that changes the next step (an image deleted in the Media
		// Library, a job finishing) updates this page without a manual reload.
		if (lastNext !== undefined && data.next && lastNext !== data.next && $('#smao-next')) {
			try { window.sessionStorage.setItem('smaoNotice', t('Updated to reflect the latest changes on your site.')); } catch (error) { /* Optional. */ }
			window.location.reload();
			return;
		}
		lastNext = data.next;
		lastStage = stage(scan);
		scanId = scan.id;

		$$('[data-metric]').forEach(function (el) {
			var key = el.dataset.metric;
			if (key === 'unused' && scan.state !== 'complete') {
				el.textContent = t('Pending');
				return;
			}
			el.textContent = ['bytes', 'saved'].indexOf(key) > -1
				? model.bytes(summary[key])
				: Number(summary[key] || 0).toLocaleString();
		});

		var stalled = model.stalled(scan, live.server_time);
		scan.stalled = stalled;

		put('#smao-state', stalled ? t('Waiting for worker') : (scan.state || t('Ready')));
		put('#smao-progress', model.progress(scan));

		var percent = model.percent(scan);
		var track = $('#smao-track-fill');
		if (track) { track.style.width = (percent === null ? 6 : percent) + '%'; }
		var bar = $('#smao-progress-bar');
		if (bar) {
			if (percent === null && scan.state === 'running') {
				bar.removeAttribute('value');
			} else {
				bar.value = percent || 0;
			}
		}
		put('#smao-percent', percent === null ? '-' : percent + '%');

		var labels = {
			inventory: t('Finding your media files'),
			sources: t('Tracing where each file is used'),
			classify: t('Classifying results'),
			recheck: t('Double-checking changes made during the scan')
		};
		put('#smao-phase-label', scan.state === 'complete'
			? t('Scan complete. Ready to review.')
			: (labels[scan.phase] || t('Ready to scan')));

		put('#smao-work-count', (scan.processed || 0) + ' ' + t('files') + ' · '
			+ (scan.records || 0) + ' ' + t('source records'));
		put('#smao-elapsed', scan.started
			? Math.max(0, Math.floor(((scan.finished || live.server_time) - scan.started) / 60)) + ' ' + t('min')
			: '');

		['inventory', 'sources', 'classify'].forEach(function (phase, index) {
			var el = $('[data-phase="' + phase + '"]');
			if (el) { el.classList.toggle('active', scan.phase === phase && scan.state !== 'complete'); }
			var counts = [scan.processed || 0, scan.records || 0, scan.classified || 0];
			put('[data-phase-count="' + phase + '"]', Number(counts[index]).toLocaleString());
		});

		put('#smao-current-file', scan.current || t('Waiting for the first batch'));
		put('#smao-freshness', scan.updated ? new Date(scan.updated * 1000).toLocaleTimeString() : '');
		put('#smao-connection', stalled ? t('No recent progress') : t('Connected'));
		put('#smao-worker-mode', live.runtime && live.runtime.browser_worker
			? t('This page is helping') : t('Background processing'));

		var warning = $('#smao-scan-warning');
		if (warning) {
			warning.textContent = [
				stalled ? t('Nothing has advanced for over 30 seconds. Try Retry processing, and restart the scan if the same checkpoint keeps returning.') : '',
				scan.stale ? t('Your site changed during this scan. Run a fresh scan before removing anything.') : '',
				scan.incomplete ? t('Scan coverage is incomplete, so unused conclusions are disabled.') : '',
				scan.message || ''
			].filter(Boolean).join(' ');
			warning.hidden = !warning.textContent;
		}

		var visible = model.visibleCommands(scan);
		$$('.smao-scan-actions [data-command]').forEach(function (button) {
			var command = button.dataset.command;
			if (Object.prototype.hasOwnProperty.call(visible, command)) {
				button.hidden = !visible[command];
			}
		});

		var activity = $('#smao-activity');
		if (activity && scan.activity && scan.activity.length) {
			activity.replaceChildren.apply(activity, scan.activity.slice().reverse().map(function (item) {
				var li = node('li');
				li.append(
					node('small', new Date(item.time * 1000).toLocaleTimeString()
						+ ' · ' + String(item.event).replace(/_/g, ' ')),
					node('strong', item.label)
				);
				return li;
			}));
		}

		renderCleanup(data);

		put('#smao-queue-counts', (data.jobs_paused ? t('Paused') : t('Active')) + ' · '
			+ Object.keys(live.queue_counts || {}).map(function (key) {
				return live.queue_counts[key] + ' ' + key;
			}).join(' · '));

		var jobs = $('#smao-jobs');
		if (jobs) {
			jobs.replaceChildren.apply(jobs, (data.jobs && data.jobs.length)
				? data.jobs.slice(0, 8).map(function (job) {
					return node('p', '#' + job.attachment_id + ' · ' + job.action + ' · ' + job.state
						+ (job.message ? ': ' + job.message : ''));
				})
				: [node('p', t('Select images below and choose Optimize selected to add jobs.'), 'smao-muted')]);
		}

		$$('[data-scope]').forEach(updateActions);
	}

	function renderCleanup(data) {
		var cleanup = data.live && data.live.cleanup;
		if (!cleanup) { return; }

		var scopeForm = $('#smao-scope');
		if (scopeForm) { scopeForm.hidden = cleanup.ready || cleanup.permanent; }

		put('#smao-cleanup-title', cleanup.ready
			? t('Ready to review unused images')
			: t('Before you remove anything'));

		var list = $('#smao-cleanup-reasons');
		if (list) {
			list.replaceChildren.apply(list, cleanup.blockers.length
				? cleanup.blockers.map(function (reason) { return node('li', reason); })
				: [node('li', t('All prerequisites are met. Review the evidence, select files, then remove them.'), 'smao-ok')]);
		}

		var scan = data.scan || {};
		var summary = (data.live && data.live.summary) || {};
		put('#smao-review-progress', scan.state === 'complete'
			? t('Scan complete') + ' · ' + (scan.processed || 0) + ' ' + t('files checked')
				+ ' · ' + (summary.unused || 0) + ' ' + t('unused candidates')
			: (scan.state ? t('Scan status') + ': ' + scan.state : ''));
	}

	/* -------------------------------------------------------------- loops */

	async function drive() {
		if (working || busy || stopped || !latest || !model.shouldWork(latest)) { return; }
		working = true;
		try {
			render(await request('control', { command: 'tick' }));
			workerFailures = 0;
		} catch (error) {
			if (error.code !== 'smao_busy') { workerFailures++; }
		} finally {
			working = false;
		}
	}

	async function workLoop() {
		await drive();
		var idle = !latest || !model.shouldWork(latest);
		var delay = idle ? 5000 : (workerFailures ? Math.min(30000, workerFailures * 3000) : 400);
		window.setTimeout(workLoop, delay);
	}

	async function poll() {
		if (!polling && !stopped) {
			polling = true;
			try {
				if (!working) { render(await request('status')); }
				failures = 0;
			} catch (error) {
				failures++;
				put('#smao-connection', t('Connection lost. Retrying.'));
				if (stopped) { notice(t('Your session expired. Reload this page to reconnect.'), true); }
			} finally {
				polling = false;
			}
			if (!document.hidden) {
				try {
					await refreshFragment(false);
				} catch (error) {
					/* A failed refresh must never stop processing. */
				}
			}
		}
		window.setTimeout(poll, model.pollDelay(latest, failures, document.hidden));
	}

	/* ---------------------------------------------------- review controls */

	var keepAll = $('#smao-keep-all');
	if (keepAll) {
		keepAll.addEventListener('click', function () {
			var scope = scopeOf(keepAll);
			$$('.smao-id', scope).forEach(function (box) { box.checked = false; });
			refreshSelection(scope);
		});
	}

	$$('[data-where]').forEach(function (button) {
		button.addEventListener('click', function () {
			run(async function () {
				if (button.dataset.loaded) { return; }
				var rows = await request('evidence?id=' + button.dataset.where);
				var box = node('div', undefined, 'smao-where');
				if (!rows.length) {
					box.append(node('p', t('We looked through your pages, posts, menus, widgets and theme settings. Nothing referred to this image.')));
				} else {
					box.append(node('p', t('We found it mentioned here:')));
					var list = node('ul');
					rows.slice(0, 8).forEach(function (row) {
						list.append(node('li', row.source + ' #' + row.source_id + ' (' + row.field + ')'));
					});
					box.append(list);
				}
				button.insertAdjacentElement('afterend', box);
				button.dataset.loaded = '1';
				button.hidden = true;
			}, button);
		});
	});

	/** The next step is computed on the server, so reload when the stage changes. */
	function stage(scan) {
		var state = scan.state || 'idle';
		if (state === 'running' || state === 'paused') { return 'busy'; }
		return state;
	}

	/* --------------------------------------------------------------- init */

	try {
		var carried = window.sessionStorage.getItem('smaoNotice');
		if (carried) {
			window.sessionStorage.removeItem('smaoNotice');
			notice(carried, false, null);
		}
	} catch (error) {
		/* Storage can be unavailable; the notice is a convenience. */
	}

	$$('[data-scope]').forEach(refreshSelection);

	if ($('#smao-progress') || $('#smao-jobs') || $('#smao-report')) {
		poll();
		workLoop();
		// Coming back to this tab, for example after deleting an image in the
		// Media Library, checks straight away instead of at the next poll.
		var checkNow = async function () {
			if (document.hidden || polling || stopped) { return; }
			polling = true;
			try { render(await request('status')); } catch (error) { /* The regular poll retries. */ } finally { polling = false; }
		};
		if (typeof document.addEventListener === 'function') { document.addEventListener('visibilitychange', checkNow); }
		if (typeof window.addEventListener === 'function') { window.addEventListener('focus', checkNow); }
	}
})();
