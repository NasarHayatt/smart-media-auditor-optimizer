/**
 * Above-the-fold style capture and verification, run from wp-admin only.
 *
 * Capture: while the measurement frame shows a page at each width, note every
 * rule in a stylesheet that could load in the background and that styles
 * something in the first screenful. The union over all widths becomes the
 * page's critical CSS.
 *
 * Verification: lay the page out again with those background stylesheets
 * switched off and only the captured rules in their place, and compare the
 * position of everything in the first screenful with the real layout. If
 * anything moved, the capture is not good enough and the page keeps its
 * stylesheets blocking. That comparison is what makes this safe on any theme
 * or builder: it does not trust the capture, it checks it.
 */
(function (root) {
	'use strict';

	var NL = String.fromCharCode(10);
	var URL_PATTERN = /url\(\s*(['"]?)([^'")]+)\1\s*\)/g;
	var SKIP_TAGS = { SCRIPT: 1, STYLE: 1, LINK: 1, META: 1, NOSCRIPT: 1, BR: 1, TEMPLATE: 1, TITLE: 1, HEAD: 1 };
	var FREEZE = '*,*::before,*::after{transition:none!important;animation:none!important;caret-color:transparent!important}';

	/** Height of the first screenful at a given width. */
	function fold(width) {
		return width <= 480 ? 900 : 1100;
	}

	/**
	 * Rewrite relative url() references so they still resolve once the rule
	 * is inlined into the page instead of living in its stylesheet.
	 */
	function absolutize(cssText, base) {
		if (!base) { return cssText; }
		return cssText.replace(URL_PATTERN, function (whole, quote, value) {
			var target = value.trim();
			if (/^(data:|https?:|\/\/|#|about:|blob:)/i.test(target)) { return whole; }
			try {
				return 'url("' + new URL(target, base).href + '")';
			} catch (error) {
				return whole;
			}
		});
	}

	/** The font family an @font-face rule declares, normalised. */
	function familyName(value) {
		return String(value || '').replace(/["']/g, '').trim().toLowerCase();
	}

	/**
	 * Keep only @font-face rules for families the captured rules use.
	 *
	 * Icon fonts and unused weights are often embedded as large data URIs,
	 * which would swell the inline block for no visual benefit.
	 */
	function usedFonts(fonts, cssText) {
		var haystack = cssText.toLowerCase();
		return fonts.filter(function (font) {
			return font.family !== '' && haystack.indexOf(font.family) !== -1;
		}).map(function (font) { return font.text; });
	}

	/** Stylesheets on the page that the plugin could load in the background. */
	function candidates(doc) {
		var list = [];
		var node = doc.getElementById('smao-styles');
		var handles = [];
		try { handles = JSON.parse(node ? node.textContent : '[]') || []; } catch (error) { handles = []; }
		handles.forEach(function (handle) {
			var link = doc.getElementById(handle + '-css');
			if (!link || !link.sheet) { return; }
			var rules = null;
			try { rules = link.sheet.cssRules; } catch (error) { rules = null; }
			// A sheet we cannot read cannot be captured, so it must keep
			// blocking; leaving it out of the list does exactly that.
			if (!rules) { return; }
			list.push({ handle: handle, link: link, sheet: link.sheet });
		});
		return list;
	}

	/**
	 * Whether an element sits in the first screenful.
	 *
	 * A hidden element has no box of its own, yet the rule hiding it is
	 * exactly what the first paint needs, or a collapsed menu renders open.
	 * So it is placed by its nearest ancestor that does have a box.
	 */
	function inFold(el, limit) {
		var node = el;
		for (var depth = 0; node && node.getBoundingClientRect && depth < 16; depth++) {
			var box = node.getBoundingClientRect();
			if (box.width > 0 || box.height > 0) {
				// Anything that starts above the bottom of the first screen,
				// including what a theme moves off screen on purpose: the
				// rule moving it away is exactly what the first paint needs.
				// A "skip to content" link, placed at -144001px by its theme,
				// was left out, showed at the top and pushed the page down.
				return box.top < limit;
			}
			node = node.parentElement;
		}
		return false;
	}

	/** Header of a grouping rule such as @media or @layer, without its body. */
	function groupHeader(rule) {
		var text = rule.cssText || '';
		var brace = text.indexOf('{');
		return brace > 0 ? text.slice(0, brace).trim() : '';
	}

	/** Rules whose children are rules: @media, @supports, @layer, @container. */
	function isGroup(rule) {
		return rule.type !== 1 && rule.type !== 7 && !!rule.cssRules && groupHeader(rule).charAt(0) === '@';
	}

	function selectorAboveFold(selectorText, doc, limit) {
		var parts = selectorText.split(',');
		for (var i = 0; i < parts.length; i++) {
			// Pseudo-states cannot be matched now; keep their base selector.
			var selector = parts[i].replace(/::?(hover|focus|active|visited|focus-within|focus-visible|before|after|placeholder|selection|first-line|first-letter|marker|backdrop|-webkit-[a-z-]+|-moz-[a-z-]+)[^\s,>+~]*/g, '').trim();
			if (!selector) { continue; }
			// Universal and root rules apply to everything on screen.
			if (selector === '*' || selector === 'html' || selector === 'body' || selector === ':root') { return true; }
			var found;
			try {
				found = doc.querySelectorAll(selector);
			} catch (error) {
				continue; // Selector we cannot evaluate; skip rather than guess.
			}
			for (var j = 0; j < found.length && j < 120; j++) {
				if (inFold(found[j], limit)) {
					return true;
				}
			}
		}
		return false;
	}

	/** Visit every style rule, depth first, with a key stable across reloads. */
	function walk(rules, prefix, base, visit) {
		for (var i = 0; i < rules.length; i++) {
			var rule = rules[i];
			var key = prefix + '/' + i;
			if (rule.type === 3 && rule.styleSheet) { // @import
				var imported = null;
				try { imported = rule.styleSheet.cssRules; } catch (error) { imported = null; }
				if (imported) { walk(imported, key, rule.styleSheet.href || base, visit); }
				continue;
			}
			if (isGroup(rule)) {
				walk(rule.cssRules, key, base, visit);
				continue;
			}
			if (rule.type === 1 && rule.selectorText) {
				visit(rule, key, base);
			}
		}
	}

	/** Record which rules style the first screenful at the current width. */
	function mark(doc, width, marks) {
		var limit = fold(width);
		candidates(doc).forEach(function (item) {
			walk(item.sheet.cssRules, item.handle, item.sheet.href, function (rule, key) {
				if (!marks.has(key) && selectorAboveFold(rule.selectorText, doc, limit)) {
					marks.add(key);
				}
			});
		});
	}

	function emit(rules, prefix, base, marks, out, fonts) {
		for (var i = 0; i < rules.length; i++) {
			var rule = rules[i];
			var key = prefix + '/' + i;
			if (rule.type === 5) { // @font-face
				fonts.push({
					family: familyName(rule.style && rule.style.getPropertyValue('font-family')),
					text: absolutize(rule.cssText, base)
				});
				continue;
			}
			if (rule.type === 3 && rule.styleSheet) {
				var imported = null;
				try { imported = rule.styleSheet.cssRules; } catch (error) { imported = null; }
				if (imported) { emit(imported, key, rule.styleSheet.href || base, marks, out, fonts); }
				continue;
			}
			if (isGroup(rule)) {
				var inner = [];
				emit(rule.cssRules, key, base, marks, inner, fonts);
				if (inner.length) {
					out.push(groupHeader(rule) + '{' + inner.join(NL) + '}');
				}
				continue;
			}
			if (rule.type === 1 && marks.has(key)) {
				out.push(absolutize(rule.cssText, base));
			}
		}
	}

	/** Turn the marks into CSS, in the original cascade order. */
	function build(doc, marks) {
		var out = [];
		var fonts = [];
		var handles = [];
		candidates(doc).forEach(function (item) {
			handles.push(item.handle);
			emit(item.sheet.cssRules, item.handle, item.sheet.href, marks, out, fonts);
		});
		var body = out.join(NL);
		var faces = usedFonts(fonts, body);
		return { css: (faces.length ? faces.join(NL) + NL : '') + body, handles: handles };
	}

	/** Positions of everything visible in the first screenful. */
	function snapshot(doc, limit) {
		var shot = [];
		var all = doc.body ? doc.body.getElementsByTagName('*') : [];
		for (var i = 0; i < all.length && shot.length < 4000; i++) {
			var el = all[i];
			if (SKIP_TAGS[el.tagName]) { continue; }
			var box = el.getBoundingClientRect();
			if (box.bottom <= 0 || box.top >= limit || (box.width === 0 && box.height === 0)) { continue; }
			shot.push({ el: el, left: box.left, top: box.top, width: box.width, height: box.height });
		}
		return shot;
	}

	/**
	 * Estimate the layout shift between two snapshots, the way Chrome scores
	 * Cumulative Layout Shift: the share of the screen affected, times the
	 * largest distance anything moved relative to the screen size.
	 */
	function score(before, after, width, limit) {
		var cols = 48;
		var rows = 48;
		var grid = new Uint8Array(cols * rows);
		var distance = 0;
		var paint = function (box) {
			var x0 = Math.max(0, Math.floor(box.left / width * cols));
			var x1 = Math.min(cols, Math.ceil((box.left + box.width) / width * cols));
			var y0 = Math.max(0, Math.floor(box.top / limit * rows));
			var y1 = Math.min(rows, Math.ceil((box.top + box.height) / limit * rows));
			for (var y = y0; y < y1; y++) {
				for (var x = x0; x < x1; x++) { grid[y * cols + x] = 1; }
			}
		};
		for (var i = 0; i < before.length; i++) {
			var a = before[i];
			var b = after[i];
			var gone = b.width === 0 && b.height === 0;
			var moved = gone ? Math.max(a.width, a.height) : Math.max(Math.abs(b.left - a.left), Math.abs(b.top - a.top));
			if (moved <= 2) { continue; }
			distance = Math.max(distance, moved);
			paint(a);
			if (!gone) { paint(b); }
		}
		var covered = 0;
		for (var c = 0; c < grid.length; c++) { covered += grid[c]; }
		var impact = covered / grid.length;
		return impact * Math.min(1, distance / Math.max(width, limit));
	}

	/**
	 * Compare the real layout with the layout the captured styles produce.
	 *
	 * @return {number} Estimated shift; 0 means identical.
	 */
	function verify(doc, css, width) {
		var limit = fold(width);
		var html = doc.documentElement;
		var previousOverflow = html.style.overflowY;
		var freeze = doc.createElement('style');
		freeze.textContent = FREEZE;
		// The same scrollbar in both layouts, so its appearance is not a shift.
		html.style.overflowY = 'scroll';
		doc.head.appendChild(freeze);

		var items = candidates(doc);
		var before = snapshot(doc, limit);
		var after = [];
		var probe = doc.createElement('style');
		probe.textContent = css;
		// Test the captured rules where visitors get them: printed before every
		// stylesheet, so inline styles that follow a stylesheet still win.
		// Appended at the end instead, they overrode the theme's inline rules
		// and failed pages that were fine.
		var first = null;
		items.forEach(function (item) {
			if (!first || (item.link.compareDocumentPosition(first) & 4)) { first = item.link; }
		});
		try {
			items.forEach(function (item) { item.link.disabled = true; });
			if (first && first.parentNode) {
				first.parentNode.insertBefore(probe, first);
			} else {
				doc.head.appendChild(probe);
			}
			for (var i = 0; i < before.length; i++) {
				var box = before[i].el.getBoundingClientRect();
				after.push({ left: box.left, top: box.top, width: box.width, height: box.height });
			}
		} finally {
			if (probe.parentNode) { probe.parentNode.removeChild(probe); }
			items.forEach(function (item) { item.link.disabled = false; });
			if (freeze.parentNode) { freeze.parentNode.removeChild(freeze); }
			html.style.overflowY = previousOverflow;
		}
		if (!items.length) { return 0; }
		return score(before, after, width, limit);
	}

	/** Height of the first screen that PageSpeed looks at, per width. */
	function screen(width) {
		return width <= 480 ? 823 : 940;
	}

	/** First real image URL in a computed background-image value. */
	function backgroundUrl(value) {
		var match = /url\(\s*(['"]?)([^'")]+)\1\s*\)/.exec(value || '');
		return match && !/^data:/i.test(match[2]) ? match[2] : '';
	}

	/**
	 * The largest image in the first screen: an img, or a CSS background.
	 *
	 * This is what the browser will report as the Largest Contentful Paint
	 * when the page's biggest element is an image. Guessing from the post
	 * (featured image, first image in the content) misses page-builder
	 * sections, whose images are usually CSS backgrounds.
	 */
	function hero(doc, width) {
		var height = screen(width);
		var best = null;
		var top = null;
		var above = [];
		var all = doc.body ? doc.body.getElementsByTagName('*') : [];
		for (var i = 0; i < all.length; i++) {
			var el = all[i];
			if (SKIP_TAGS[el.tagName]) { continue; }
			var box = el.getBoundingClientRect();
			if (box.bottom <= 0 || box.top >= height || box.width < 2 || box.height < 2) { continue; }
			var url = '';
			var kind = '';
			var id = 0;
			if (el.tagName === 'IMG') {
				// Sliders and lazy loaders stretch a tiny placeholder over the
				// real image; the file itself gives it away.
				if (el.naturalWidth <= 8 || el.naturalHeight <= 8) { continue; }
				url = el.currentSrc || el.src || '';
				kind = 'img';
				var match = /wp-image-(\d+)/.exec(el.className || '');
				id = match ? Number(match[1]) : Number(el.getAttribute('data-smao-id') || 0);
			} else {
				var style = el.ownerDocument.defaultView.getComputedStyle(el);
				if (style.visibility === 'hidden' || Number(style.opacity) === 0) { continue; }
				url = backgroundUrl(style.backgroundImage);
				kind = 'bg';
			}
			if (!url || /^data:/i.test(url)) { continue; }
			var visible = Math.max(0, Math.min(box.right, width) - Math.max(box.left, 0)) *
				Math.max(0, Math.min(box.bottom, height) - Math.max(box.top, 0));
			if (visible <= 0) { continue; }
			if (kind === 'img') {
				// Every image drawn in the first screen: none may wait its turn.
				above.push({ id: id, url: url });
				if (!top || visible > top.area) { top = { id: id, url: url, area: visible }; }
			}
			if (!best || visible > best.area) {
				best = { kind: kind, url: url, id: id, area: visible };
			}
		}
		if (!best) { return null; }
		best.share = Math.round(best.area / (width * height) * 1000) / 1000;
		delete best.area;
		if (top) {
			top.share = Math.round(top.area / (width * height) * 1000) / 1000;
			delete top.area;
		}
		best.top = top;
		best.above = above.slice(0, 40);
		return best;
	}

	var api = {
		fold: fold,
		hero: hero,
		backgroundUrl: backgroundUrl,
		absolutize: absolutize,
		groupHeader: groupHeader,
		familyName: familyName,
		usedFonts: usedFonts,
		score: score,
		mark: mark,
		build: build,
		verify: verify
	};

	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	} else {
		root.SMAOCritical = api;
	}
})(typeof window !== 'undefined' ? window : this);
