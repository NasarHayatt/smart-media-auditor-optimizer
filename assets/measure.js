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
	var NL = String.fromCharCode(10);

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

	/** Load a page into a frame of a given size and wait for it to settle. */
	function loadInto(target, url, width, height, settle) {
		return new Promise(function (resolve) {
			target.style.width = width + 'px';
			target.style.height = height + 'px';
			var finished = false;
			var finish = function () { if (!finished) { finished = true; resolve(target); } };
			target.onload = function () { window.setTimeout(finish, settle); };
			window.setTimeout(finish, 15000);
			target.src = url;
		});
	}

	/**
	 * Pre-build one page: at each width, load it finished and with scripts
	 * held, write styles that make the held page look finished, and check
	 * the result. Returns what the server needs to decide.
	 */
	async function prebuildPage(url, label) {
		var tool = window.SMAOPrebuild;
		if (!tool) { return null; }
		var done = document.createElement('iframe');
		var held = document.createElement('iframe');
		[done, held].forEach(function (f) {
			f.setAttribute('aria-hidden', 'true');
			f.setAttribute('tabindex', '-1');
			// On screen but invisible. A frame placed off screen never counts
			// as in view, so anything a page builds as it scrolls into view,
			// sliders included, never started, and the "finished" page the
			// check compared against was not finished at all.
			f.style.cssText = 'position:fixed;left:0;top:0;border:0;opacity:0;pointer-events:none;z-index:-1;';
			document.body.appendChild(f);
		});
		var sep = url.indexOf('?') === -1 ? '?' : '&';
		var css = {};
		var worst = { shift: 0, height: 0, missing: 0, off: 0, width: 0, widths: [] };
		var worstBadness = 0;

		// Wait until the page stops changing length: sliders and carousels
		// finish building some time after the page reports it has loaded,
		// and on a slow host that is well after any fixed delay.
		async function steady(frame, limit) {
			var last = -1;
			var same = 0;
			var start = Date.now();
			while (Date.now() - start < limit) {
				var doc = frame.contentDocument;
				var height = doc && doc.body ? tool.pageHeight(doc) : 0;
				if (height > 0 && Math.abs(height - last) < 2) {
					if (++same >= 2) { return; }
				} else {
					same = 0;
				}
				last = height;
				await new Promise(function (resolve) { window.setTimeout(resolve, 400); });
			}
		}

		// Scroll a page from top to bottom and back, so everything that
		// appears as it scrolls into view has appeared before it is compared.
		async function scrollThrough(frame) {
			var view = frame.contentWindow;
			var doc = frame.contentDocument;
			if (!view || !doc || !doc.body) { return; }
			var step = Math.max(300, Math.round(view.innerHeight * 0.8));
			var pause = function (ms) { return new Promise(function (resolve) { window.setTimeout(resolve, ms); }); };
			for (var y = 0, n = 0; y < tool.pageHeight(doc) && n < 120; y += step, n++) {
				view.scrollTo(0, y);
				await pause(120);
			}
			view.scrollTo(0, tool.pageHeight(doc));
			await pause(800);
			view.scrollTo(0, 0);
			await pause(600);
		}

		async function attempt(width, doneSettle, heldSettle) {
			var screen = tool.screenFor(width);
			await Promise.all([
				loadInto(done, url + sep + 'smao-measure=1&smao-try=' + Date.now(), width, screen, doneSettle),
				loadInto(held, url + sep + 'smao-measure=1&smao-held=1&smao-try=' + Date.now(), width, screen, heldSettle)
			]);
			await Promise.all([scrollThrough(done), scrollThrough(held)]);
			await steady(done, 8000);
			await steady(held, 3000);
			var dd = done.contentDocument;
			var hd = held.contentDocument;
			if (!dd || !hd || !dd.body || !hd.body) { return null; }
			var view = done.contentWindow;
			var perf = function (address) {
				var entry = view.performance.getEntriesByName(address)[0];
				return !entry || !entry.responseStatus || entry.responseStatus < 400;
			};
			var doneBoxes = tool.boxes(dd);
			var doneHeight = tool.pageHeight(dd);
			var result = tool.prepare(hd, doneBoxes, doneHeight, width, perf, view);
			if (tool.passes(result)) {
				var started = await release(width, result, doneBoxes, doneHeight);
				// The same limits a visitor's page uses to switch itself back.
				if (started && started.shift > 0.05) { result.shift = Math.max(result.shift, started.shift); }
				if (started && started.height > 0.03) { result.height = Math.max(result.height, started.height); }
			}
			return result;
		}

		// Start the held page's scripts as a visitor's first tap would, with
		// the pre-built styles in place, and see whether the page moves.
		async function release(width, result, doneBoxes, doneHeight) {
			var hd = held.contentDocument;
			var hv = held.contentWindow;
			if (!hv || typeof hv.smaoRelease !== 'function') { return null; }
			var style = hd.createElement('style');
			style.textContent = tool.wrap(width, result.css);
			hd.head.appendChild(style);
			await new Promise(function (resolve) { window.setTimeout(resolve, 300); });
			hv.smaoRelease();
			var start = Date.now();
			while (!hd.documentElement.classList.contains('smao-ran') && Date.now() - start < 20000) {
				await new Promise(function (resolve) { window.setTimeout(resolve, 250); });
			}
			await scrollThrough(held);
			await steady(held, 6000);
			var after = tool.boxes(hd);
			// Inside a rebuilt area the real widget takes over and may differ in
			// detail, such as a carousel on another slide; the area itself and
			// everything around it must stay where it is.
			var considered = {};
			Object.keys(doneBoxes).forEach(function (key) { if (!result.inner[key]) { considered[key] = doneBoxes[key]; } });
			return {
				shift: tool.compare(after, considered, hd),
				height: Math.abs(tool.pageHeight(hd) - doneHeight) / Math.max(doneHeight, 1)
			};
		}

		try {
			for (var i = 0; i < tool.WIDTHS.length; i++) {
				var width = tool.WIDTHS[i];
				say(label + ': ' + t('checking how it looks before scripts run') + ' ' + width + 'px');
				var result = await attempt(width, 2000, 1200);
				if (!result || !tool.passes(result)) {
					// One slow response should not decide the page: try again,
					// giving both copies longer, and keep the better result.
					say(label + ': ' + t('checking again, more slowly') + ' ' + width + 'px');
					var second = await attempt(width, 5000, 2500);
					if (second && (!result || tool.badness(second) < tool.badness(result))) { result = second; }
				}
				if (!result) { return null; }
				css[width] = result.css;
				var finite = function (value) { return isFinite(value) ? Math.round(value * 10000) / 10000 : null; };
				worst.widths.push({ width: width, shift: finite(result.shift), height: finite(result.height), missing: finite(result.missing), off: finite(result.off) });
				['shift', 'height', 'missing', 'off'].forEach(function (key) {
					worst[key] = isFinite(result[key]) && worst[key] !== null ? Math.max(worst[key], result[key]) : null;
				});
				var bad = tool.badness(result);
				if (bad > worstBadness) {
					worstBadness = bad;
					worst.width = width;
				}
			}
		} catch (error) {
			return null;
		} finally {
			done.parentNode.removeChild(done);
			held.parentNode.removeChild(held);
		}
		worst.css = tool.combine(css);
		return worst;
	}

	async function sendCritical(url, capture, shift, heroes, prebuild) {
		var response = await fetch(smaoConfig.root + 'critical', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': smaoConfig.nonce },
			body: JSON.stringify({ url: url, css: capture.css, handles: capture.handles, shift: shift, heroes: heroes, prebuild: prebuild })
		});
		var data = await response.json();
		return response.ok ? data : null;
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

		var critical = window.SMAOCritical;
		// Verification reloads the page at up to two more widths.
		var checks = critical ? [viewports[0], 1280].filter(function (width, index, list) {
			return list.indexOf(width) === index && width !== viewports[viewports.length - 1];
		}) : [];
		var total = targets.length * (viewports.length + checks.length);
		var done = 0;
		var stored = 0;

		try {
			for (var i = 0; i < targets.length; i++) {
				var marks = new Set();
				var heroes = {};
				for (var j = 0; j < viewports.length; j++) {
					say(targets[i].label + ': ' + viewports[j] + 'px');
					var observations = await measure(targets[i].url, viewports[j]);
					stored += await send(targets[i].url, viewports[j], observations);
					if (critical) {
						try { critical.mark(frame.contentDocument, viewports[j], marks); } catch (error) { /* Unreadable page: nothing captured. */ }
						try { heroes[viewports[j]] = critical.hero(frame.contentDocument, viewports[j]); } catch (error) { /* No main image recorded. */ }
					}
					progress(++done, total);
				}
				if (!critical) { continue; }

				// The frame still shows the widest layout. Capture from it, then
				// prove the capture reproduces the page at every width checked.
				var capture = null;
				var shift = -1;
				try {
					capture = critical.build(frame.contentDocument, marks);
					shift = critical.verify(frame.contentDocument, capture.css, viewports[viewports.length - 1]);
				} catch (error) {
					capture = null;
				}
				for (var k = 0; k < checks.length; k++) {
					say(targets[i].label + ': ' + t('checking the layout') + ' ' + checks[k] + 'px');
					await measure(targets[i].url, checks[k]);
					if (capture && shift >= 0) {
						try {
							shift = Math.max(shift, critical.verify(frame.contentDocument, capture.css, checks[k]));
						} catch (error) {
							shift = -1;
						}
					}
					progress(++done, total);
				}
				var prebuild = await prebuildPage(targets[i].url, targets[i].label);
				await sendCritical(targets[i].url, capture || { css: '', handles: [] }, capture ? shift : -1, heroes, prebuild);
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
	// Measurements the site's changes cleared are taken again straight away.
	if (button.getAttribute('data-auto') === '1') {
		window.setTimeout(function () { if (!button.disabled) { button.click(); } }, 1500);
	}
})();
