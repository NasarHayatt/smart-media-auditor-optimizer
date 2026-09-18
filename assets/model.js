/**
 * Pure helpers shared by the admin interface and the unit tests.
 *
 * Nothing here touches the DOM or the network, so every rule below can be
 * asserted directly in tests/js/model.test.cjs.
 */
(function (root) {
	'use strict';

	var DESTRUCTIVE = ['quarantine', 'purge'];
	var TYPED = ['purge'];
	var ALLOWED = ['quarantine', 'restore', 'purge', 'optimize', 'thumbnails'];

	var api = {
		/**
		 * Normalise a set of selected IDs to unique positive integers.
		 */
		selected: function (values) {
			return [...new Set(values.map(Number).filter(function (id) {
				return Number.isSafeInteger(id) && id > 0;
			}))];
		},

		/**
		 * Whether an action permanently changes or removes files.
		 */
		destructive: function (action) {
			return DESTRUCTIVE.includes(action);
		},

		/**
		 * Only a genuinely irreversible action asks the user to type a word.
		 * Moving a file to safe storage can be undone, so it does not.
		 */
		requiresTyping: function (action) {
			return TYPED.includes(action);
		},

		/**
		 * Gate an action. Only destructive actions require an acknowledgement
		 * and a typed confirmation word.
		 */
		confirmation: function (action, ids, reviewed, typed) {
			if (!ALLOWED.includes(action) || ids.length < 1 || ids.length > 50) {
				return false;
			}
			if (!api.requiresTyping(action)) {
				return true;
			}
			return reviewed === true && typed === action.toUpperCase();
		},

		/**
		 * A scan is stalled when it claims to be running but has not
		 * checkpointed recently.
		 */
		stalled: function (scan, now) {
			return scan.state === 'running'
				&& Number(now) - Number(scan.updated || scan.started || now) > 30;
		},

		/**
		 * Build a REST endpoint URL, preserving any query already on the root.
		 */
		endpoint: function (root, route) {
			var parts = route.split('?');
			var path = parts[0];
			var query = parts[1];
			return root + path + (query ? (root.includes('?') ? '&' : '?') + query : '');
		},

		bytes: function (value) {
			var n = Math.max(0, Number(value) || 0);
			var unit = Math.min(3, Math.floor(Math.log(n || 1) / Math.log(1024)));
			return (n / Math.pow(1024, unit)).toFixed(unit ? 1 : 0)
				+ ' ' + ['B', 'KB', 'MB', 'GB'][unit];
		},

		percent: function (scan) {
			if (scan.state === 'complete') {
				return 100;
			}
			if (!Number.isFinite(scan.total) || !Array.isArray(scan.source_totals)) {
				return null;
			}
			var total = scan.total * 2 + scan.source_totals.reduce(function (a, b) {
				return a + Number(b);
			}, 0);
			var done = (scan.processed || 0) + (scan.records || 0) + (scan.classified || 0);
			return Math.min(99, Math.max(0, Math.floor(100 * done / (total || 1))));
		},

		/**
		 * Whether the browser should help advance work.
		 */
		shouldWork: function (data) {
			if (!data.live || !data.live.runtime || !data.live.runtime.browser_worker) {
				return false;
			}
			if (data.scan.state === 'running') {
				return true;
			}
			return !data.jobs_paused
				&& Number(data.live.queue_counts && data.live.queue_counts.queued) > 0;
		},

		/**
		 * Adaptive poll delay in milliseconds.
		 *
		 * Fast while something is actually happening, slow when idle, and
		 * backed off further after consecutive failures. This is what stops
		 * an open tab hammering the database forever.
		 */
		pollDelay: function (data, failures, hidden) {
			var base = Number(data && data.live && data.live.runtime
				&& data.live.runtime.poll_interval) || 2;
			var busy = data && (
				data.scan.state === 'running'
				|| data.scan.state === 'paused'
				|| Number(data.live && data.live.queue_counts
					&& data.live.queue_counts.queued) > 0
			);
			if (!busy) {
				return hidden ? 120000 : 30000;
			}
			if (hidden) {
				return 15000;
			}
			return Math.min(15000, base * 1000 * Math.max(1, failures + 1));
		},

		/**
		 * Which scan control buttons should be visible for a given state.
		 */
		visibleCommands: function (scan) {
			var state = scan.state || 'idle';
			var started = state !== 'idle' && state !== '';
			var active = state === 'running' || state === 'paused';
			return {
				start: !active && state !== 'complete' && !started,
				pause: state === 'running',
				resume: state === 'paused',
				cancel: active,
				restart: started && !active,
				retry: Boolean(scan.stalled) || Boolean(scan.incomplete)
			};
		},

		progress: function (scan) {
			return [
				scan.state || 'idle',
				scan.phase || 'no scan',
				'files: ' + (scan.processed || 0),
				'sources: ' + (scan.records || 0)
			].join(' - ');
		}
	};

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	} else {
		root.SMAOModel = api;
	}
})(typeof window !== 'undefined' ? window : globalThis);
