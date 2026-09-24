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

	/**
	 * Collect the CSS rules that style what appears in the first screenful.
	 *
	 * Everything else can load asynchronously, which is what stops the
	 * stylesheet blocking the first paint.
	 */
	function extractCritical(doc, viewportHeight) {
		var css = [];
		var sheets = doc.styleSheets;

		for (var i = 0; i < sheets.length; i++) {
			var rules;
			try {
				rules = sheets[i].cssRules;
			} catch (error) {
				continue; // Cross-origin stylesheet, unreadable by design.
			}
			if (!rules) { continue; }
			collect(rules, css, doc, viewportHeight);
		}
		return css.join(NL);
	}

	function collect(rules, out, doc, fold) {
		for (var i = 0; i < rules.length; i++) {
			var rule = rules[i];

			// Keep at-rules the first paint depends on, in full.
			if (rule.type === 5 || rule.type === 6) { out.push(rule.cssText); continue; }

			if (rule.type === 4) { // @media
				var inner = [];
				collect(rule.cssRules || [], inner, doc, fold);
				if (inner.length) {
					out.push('@media ' + rule.conditionText + '{' + inner.join(NL) + '}');
				}
				continue;
			}
			if (rule.type === 12) { // @supports
				var supported = [];
				collect(rule.cssRules || [], supported, doc, fold);
				if (supported.length) {
					out.push('@supports ' + rule.conditionText + '{' + supported.join(NL) + '}');
				}
				continue;
			}
			if (rule.type !== 1 || !rule.selectorText) { continue; }

			if (matchesAboveFold(rule.selectorText, doc, fold)) {
				out.push(rule.cssText);
			}
		}
	}

	function matchesAboveFold(selectorText, doc, fold) {
		var parts = selectorText.split(',');
		for (var i = 0; i < parts.length; i++) {
			// Pseudo-states cannot be matched now; keep their base selector.
			var selector = parts[i].replace(/::?(hover|focus|active|visited|focus-within|focus-visible|before|after|placeholder|selection|first-line|first-letter|marker|backdrop)[^\s,>+~]*/g, '').trim();
			if (!selector || selector === '*') { continue; }
			var found;
			try {
				found = doc.querySelectorAll(selector);
			} catch (error) {
				continue; // Selector we cannot evaluate; skip rather than guess.
			}
			for (var j = 0; j < found.length && j < 60; j++) {
				var box = found[j].getBoundingClientRect();
				if (box.top < fold && box.bottom > -1 && box.width > 0 && box.height > 0) {
					return true;
				}
			}
			// html and body always matter.
			if (selector === 'html' || selector === 'body' || selector === ':root') { return true; }
		}
		return false;
	}

	async function sendCritical(template, css) {
		if (!template || css.length < 50) { return 0; }
		var response = await fetch(smaoConfig.root + 'critical', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': smaoConfig.nonce },
			body: JSON.stringify({ template: template, css: css })
		});
		var data = await response.json();
		return response.ok ? (data.bytes || 0) : 0;
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

					// Critical CSS is per template, so capture it once per page
					// at the widest viewport, where the most is visible.
					if (viewports[j] === viewports[viewports.length - 1]) {
						try {
							var doc = frame.contentDocument;
							var meta = doc && doc.querySelector('meta[name="smao-template"]');
							if (meta) {
								var css = extractCritical(doc, 1000);
								await sendCritical(meta.getAttribute('content'), css);
							}
						} catch (error) {
							// A page we cannot read simply contributes nothing.
						}
					}
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
