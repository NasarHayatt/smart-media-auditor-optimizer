/**
 * Google PageSpeed check, run from wp-admin only.
 *
 * Runs Google's own PageSpeed test on the home page with the current settings
 * and with each setting that can go either way switched, using a one-request
 * test address, and keeps whichever Google scores highest. Settings are
 * decided by the score that matters, not by what looks faster locally.
 */
(function () {
	'use strict';

	var t = function (text) { return wp.i18n.__(text, 'smart-media-auditor-optimizer'); };
	var button = document.getElementById('smao-psi-run');
	if (!button || !window.smaoPsi) { return; }

	var status = document.getElementById('smao-psi-status');
	var bar = document.getElementById('smao-psi-bar');
	var table = document.getElementById('smao-psi-results');
	var keyField = document.querySelector('input[name="psi_key"]');
	var config = window.smaoPsi;
	var RUNS = 2;
	var MARGIN = 3;

	function say(message) { if (status) { status.textContent = message; } }

	function toast(message, isError) {
		var box = document.getElementById('smao-toast') || document.createElement('div');
		box.id = 'smao-toast';
		box.className = 'smao-toast' + (isError ? ' is-error' : ' is-ok');
		box.setAttribute('role', isError ? 'alert' : 'status');
		box.textContent = (isError ? '✖ ' : '✔ ') + message;
		if (!box.parentNode) { document.body.appendChild(box); }
		if (!isError) { window.setTimeout(function () { if (box.parentNode) { box.parentNode.removeChild(box); } }, 9000); }
	}

	/** The settings worth testing, each flipped from its current value. */
	function variants() {
		var list = [{ key: 'current', label: t('Your current settings'), test: 'current' }];
		config.candidates.forEach(function (candidate) {
			var now = !!config.current[candidate.flag];
			list.push({
				key: candidate.flag,
				flag: candidate.flag,
				value: !now,
				label: (now ? t('Switch off:') : t('Switch on:')) + ' ' + candidate.label,
				test: candidate.flag + '.' + (now ? 'off' : 'on')
			});
		});
		list.push({ key: 'off', label: t('For comparison: all speed features off'), test: 'off', reference: true });
		return list;
	}

	async function pagespeed(test, key) {
		var target = config.home + (config.home.indexOf('?') === -1 ? '?' : '&') + 'smao-test=' + encodeURIComponent(test) + '&smao-run=' + Date.now() + Math.floor(Math.random() * 1000);
		var api = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?strategy=mobile&category=performance'
			+ '&url=' + encodeURIComponent(target) + '&key=' + encodeURIComponent(key);
		var response = await fetch(api);
		var data = await response.json();
		if (!response.ok || data.error) {
			throw new Error((data.error && data.error.message) || t('Google did not return a result.'));
		}
		var audits = data.lighthouseResult.audits;
		return {
			score: Math.round((data.lighthouseResult.categories.performance.score || 0) * 100),
			lcp: audits['largest-contentful-paint'].numericValue,
			fcp: audits['first-contentful-paint'].numericValue,
			tbt: audits['total-blocking-time'].numericValue,
			cls: audits['cumulative-layout-shift'].numericValue
		};
	}

	function average(runs, field) {
		return runs.reduce(function (sum, run) { return sum + run[field]; }, 0) / runs.length;
	}

	function seconds(ms) { return (ms / 1000).toFixed(1) + ' s'; }

	function show(results) {
		if (!table) { return; }
		var body = table.querySelector('tbody');
		body.textContent = '';
		results.forEach(function (row) {
			var tr = document.createElement('tr');
			[row.label, row.runs.length ? String(Math.round(average(row.runs, 'score'))) : '-',
				row.runs.length ? seconds(average(row.runs, 'lcp')) : '-',
				row.runs.length ? Math.round(average(row.runs, 'tbt')) + ' ms' : '-',
				row.runs.length ? average(row.runs, 'cls').toFixed(3) : '-'
			].forEach(function (text) {
				var td = document.createElement('td');
				td.textContent = text;
				tr.appendChild(td);
			});
			if (row.best) { tr.className = 'is-best'; }
			body.appendChild(tr);
		});
		table.hidden = false;
	}

	async function post(route, payload) {
		var response = await fetch(window.smaoConfig.root + route, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.smaoConfig.nonce },
			body: JSON.stringify(payload)
		});
		var data = await response.json();
		if (!response.ok) { throw new Error(data.message || t('Could not save the result.')); }
		return data;
	}

	button.addEventListener('click', async function () {
		var key = keyField ? keyField.value.trim() : '';
		if (!key) {
			toast(t('Paste your Google API key into the box above and click Save first.'), true);
			return;
		}
		button.disabled = true;
		var list = variants();
		var jobs = [];
		list.forEach(function (row) {
			row.runs = [];
			var count = row.reference ? 1 : RUNS;
			for (var i = 0; i < count; i++) { jobs.push(row); }
		});
		var done = 0;
		var failures = [];
		say(t('Asking Google to test your home page. This takes a few minutes.'));
		show(list);

		// Two tests at a time keeps it quick without tripping Google's limits.
		var queue = jobs.slice();
		async function worker() {
			while (queue.length) {
				var row = queue.shift();
				try {
					row.runs.push(await pagespeed(row.test, key));
				} catch (error) {
					failures.push(error.message);
				}
				done++;
				if (bar) { bar.style.width = Math.round(done / jobs.length * 100) + '%'; }
				say(t('Google tests finished:') + ' ' + done + ' / ' + jobs.length);
				show(list);
			}
		}
		await Promise.all([worker(), worker()]);

		var current = list[0];
		if (!current.runs.length) {
			button.disabled = false;
			say('');
			toast(t('Google could not test your page:') + ' ' + (failures[0] || ''), true);
			return;
		}
		var baseline = average(current.runs, 'score');
		var best = null;
		list.forEach(function (row) {
			if (row.reference || row.key === 'current' || row.runs.length < RUNS) { return; }
			var score = average(row.runs, 'score');
			if (score >= baseline + MARGIN && (!best || score > average(best.runs, 'score'))) { best = row; }
		});

		try {
			if (best) {
				best.best = true;
				show(list);
				var payload = { _flags: [best.flag] };
				payload[best.flag] = best.value;
				await post('settings', payload);
				await post('control', { command: 'cache_flush' });
				var gain = Math.round(average(best.runs, 'score') - baseline);
				say(t('Applied the best result. Your stored pages were cleared so visitors get it now.'));
				toast(best.label + ': ' + t('applied, Google scored it higher by') + ' ' + gain + ' ' + t('points.'), false);
			} else {
				current.best = true;
				show(list);
				say(t('Your current settings scored best, so nothing was changed.'));
				toast(t('Your current settings scored best. Nothing was changed.'), false);
			}
			await post('psi', { results: list.map(function (row) {
				return { label: row.label, score: row.runs.length ? Math.round(average(row.runs, 'score')) : null, lcp: row.runs.length ? Math.round(average(row.runs, 'lcp')) : null, best: !!row.best };
			}) });
		} catch (error) {
			toast(error.message, true);
		}
		button.disabled = false;
	});
})();
