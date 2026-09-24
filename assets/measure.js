/**
 * Layout measurement, run from wp-admin only.
 *
 * Loads the site's own pages in a hidden frame at several widths and records
 * how large each image is actually drawn. Nothing here ever runs on a page a
 * visitor sees.
 */
(function () {
	'use strict';

	var t = function (text) { return wp.i18n.__(text, 'smart-media-auditor-optimizer'); };

	var button = document.getElementById('smao-measure');
	if (!button || !window.smaoMeasure) { return; }

	var status = document.getElementById('smao-measure-status');
	var bar = document.getElementById('smao-measure-bar');
	var frame = null;

	function say(message) {
		if (status) { status.textContent = message; }
	}

	function progress(done, total) {
		if (bar) { bar.style.width = Math.round((done / total) * 100) + '%'; }
	}

	/** Load one URL at one width and read back every image's drawn size. */
	function measure(url, viewport) {
		return new Promise(function (resolve) {
			frame.style.width = viewport + 'px';
			frame.onload = null;

			var settled = false;
			var finish = function () {
				if (settled) { return; }
				settled = true;
				var observations = [];
				try {
					var doc = frame.contentDocument;
					var images = doc ? doc.querySelectorAll('img') : [];
					Array.prototype.forEach.call(images, function (img) {
						var match = /wp-image-(\d+)/.exec(img.className || '');
						var id = match ? Number(match[1]) : Number(img.dataset.smaoId || 0);
						if (!id) { return; }
						var box = img.getBoundingClientRect();
						if (box.width < 1) { return; }
						observations.push({
							id: id,
							width: Math.round(box.width),
							height: Math.round(box.height)
						});
					});
				} catch (error) {
					// A cross-origin or blocked document simply yields nothing.
				}
				resolve(observations);
			};

			frame.onload = function () {
				// Give layout and any font swap a moment to settle.
				window.setTimeout(finish, 600);
			};
			// Never hang on a page that will not load.
			window.setTimeout(finish, 8000);

			var separator = url.indexOf('?') === -1 ? '?' : '&';
			frame.src = url + separator + 'smao-measure=1';
		});
	}

	async function send(url, viewport, observations) {
		if (!observations.length) { return 0; }
		var response = await fetch(smaoConfig.root + 'measure', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': smaoConfig.nonce
			},
			body: JSON.stringify({ url: url, viewport: viewport, observations: observations })
		});
		var data = await response.json();
		if (!response.ok) { throw new Error(data.message || t('Could not save the measurements.')); }
		return data.stored || 0;
	}

	button.addEventListener('click', async function () {
		var targets = window.smaoMeasure.targets || [];
		var viewports = window.smaoMeasure.viewports || [];
		if (!targets.length) {
			say(t('There are no published pages to measure yet.'));
			return;
		}

		button.disabled = true;
		frame = document.createElement('iframe');
		frame.setAttribute('aria-hidden', 'true');
		frame.setAttribute('tabindex', '-1');
		frame.style.cssText = 'position:absolute;left:-10000px;top:0;height:2400px;border:0;visibility:hidden;';
		document.body.appendChild(frame);

		var total = targets.length * viewports.length;
		var done = 0;
		var stored = 0;

		try {
			for (var i = 0; i < targets.length; i++) {
				for (var j = 0; j < viewports.length; j++) {
					say(targets[i].label + ' — ' + viewports[j] + 'px');
					var observations = await measure(targets[i].url, viewports[j]);
					stored += await send(targets[i].url, viewports[j], observations);
					progress(++done, total);
				}
			}
			say(t('Done. Reloading the results.'));
			window.location.reload();
		} catch (error) {
			say(error.message);
			button.disabled = false;
		} finally {
			if (frame && frame.parentNode) { frame.parentNode.removeChild(frame); }
		}
	});
})();
