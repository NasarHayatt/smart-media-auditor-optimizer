/**
 * Pre-built layout: make the page look finished while its scripts wait.
 *
 * Run from wp-admin only. The page is loaded twice at one width, finished and
 * with scripts held; every element carries the same data-smao-n label in both.
 * Comparing the two finds the areas a script changes. Styles are written that
 * make the held page match: the area keeps its finished size, cards a script
 * lays out in a row sit in that row, and images a script places are shown
 * where it places them. The held page is then laid out again with those
 * styles and compared with the finished page, and the result decides whether
 * the page may hold its scripts at all.
 */
(function (root) {
	'use strict';

	var NL = String.fromCharCode(10);
	var TOLERANCE = 2;

	/** Media query for the widths a measurement at this width stands for. */
	function range(width) {
		if (width <= 600) { return '(max-width: 600px)'; }
		if (width <= 1024) { return '(min-width: 601px) and (max-width: 1024px)'; }
		if (width <= 1600) { return '(min-width: 1025px) and (max-width: 1600px)'; }
		return '(min-width: 1601px)';
	}

	function round(value) { return Math.round(value * 10) / 10; }

	/** Every labelled element's box, preferring an instance on screen. */
	function boxes(doc) {
		var out = {};
		var view = doc.defaultView;
		var width = doc.documentElement.clientWidth;
		var list = doc.querySelectorAll('[data-smao-n]');
		for (var i = 0; i < list.length; i++) {
			var el = list[i];
			var n = el.getAttribute('data-smao-n');
			var r = el.getBoundingClientRect();
			var cs = view.getComputedStyle(el);
			var visible = r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && Number(cs.opacity) > 0.01;
			var box = { x: r.left + view.scrollX, y: r.top + view.scrollY, w: r.width, h: r.height, visible: visible, el: el };
			var onScreen = visible && box.x > -TOLERANCE && box.x < width;
			var current = out[n];
			// Carousels clone their items; keep the copy actually on screen.
			if (!current || (onScreen && !current.onScreen) || (visible && !current.visible)) {
				box.onScreen = onScreen;
				out[n] = box;
			}
		}
		return out;
	}

	function children(el) {
		var list = [];
		for (var i = 0; i < el.children.length; i++) {
			if (el.children[i].hasAttribute('data-smao-n')) { list.push(el.children[i]); }
		}
		return list;
	}

	function differs(a, b) {
		if (!a || !b) { return false; }
		if (a.visible !== b.visible) { return true; }
		return Math.abs(a.h - b.h) > TOLERANCE || Math.abs(a.w - b.w) > TOLERANCE;
	}

	/**
	 * Where each difference starts: an element that differs, none of whose
	 * children differs by the same amount. Its ancestors only differ because
	 * of it, and fixing it fixes them.
	 */
	function origins(held, done, doc) {
		var found = [];
		var body = doc.body;
		function walk(el) {
			var n = el.getAttribute && el.getAttribute('data-smao-n');
			var a = n ? held[n] : null;
			var b = n ? done[n] : null;
			if (n && a && b && differs(a, b)) {
				var dh = b.h - a.h;
				var dw = b.w - a.w;
				var kids = children(el);
				var explained = a.visible === b.visible && kids.some(function (kid) {
					var ka = held[kid.getAttribute('data-smao-n')];
					var kb = done[kid.getAttribute('data-smao-n')];
					return ka && kb && Math.abs((kb.h - ka.h) - dh) <= TOLERANCE && Math.abs((kb.w - ka.w) - dw) <= TOLERANCE && (ka.visible === a.visible);
				});
				if (!explained) {
					found.push(el);
					return; // Everything inside is handled with it.
				}
			}
			for (var i = 0; i < el.children.length; i++) { walk(el.children[i]); }
		}
		if (body) { walk(body); }
		return found;
	}

	/** A selector that finds exactly this element on the real page. */
	function selector(el, doc) {
		function unique(sel) {
			try {
				var hits = doc.querySelectorAll(sel);
				return hits.length === 1 && hits[0] === el;
			} catch (error) {
				return false;
			}
		}
		function part(node) {
			if (node.id && /^[A-Za-z][\w-]*$/.test(node.id)) { return '#' + node.id; }
			var classes = (typeof node.className === 'string' ? node.className : '').trim().split(/\s+/).filter(function (c) {
				return c && /^[A-Za-z_][\w-]*$/.test(c) && !/^(smao|is-|has-|active|current|hover|focus|lazy|loaded|animated|elementor-invisible)/.test(c);
			});
			for (var i = 0; i < classes.length; i++) {
				var candidate = node.tagName.toLowerCase() + '.' + classes[i];
				if (doc.querySelectorAll(candidate).length === 1) { return candidate; }
			}
			var index = 1;
			for (var s = node.previousElementSibling; s; s = s.previousElementSibling) {
				if (s.tagName === node.tagName) { index++; }
			}
			return node.tagName.toLowerCase() + (classes[0] ? '.' + classes[0] : '') + ':nth-of-type(' + index + ')';
		}
		var parts = [];
		for (var node = el; node && node !== doc.documentElement; node = node.parentElement) {
			parts.unshift(part(node));
			var candidate = parts.join(' > ');
			if (unique(candidate)) { return candidate; }
			if (parts[0].charAt(0) === '#') { break; }
		}
		var full = parts.join(' > ');
		return unique(full) ? full : '';
	}

	/**
	 * Compare two layouts: movement like Cumulative Layout Shift, measured
	 * over the whole page, and the difference in page length.
	 */
	function compare(held, done, doc) {
		var width = doc.documentElement.clientWidth;
		var screen = doc.defaultView.innerHeight || 900;
		var worst = 0;
		var keys = Object.keys(done);
		// Score one screenful at a time and keep the worst, since a visitor
		// can be anywhere on the page when the scripts start.
		var pageHeight = Math.max(doc.documentElement.scrollHeight, 1);
		for (var top = 0; top < pageHeight; top += screen) {
			var before = [];
			var after = [];
			keys.forEach(function (n) {
				var a = held[n];
				var b = done[n];
				if (!a || !b || !b.visible || !a.visible) { return; }
				if (b.y + b.h <= top || b.y >= top + screen) { return; }
				before.push({ left: a.x, top: a.y - top, width: a.w, height: a.h });
				after.push({ left: b.x, top: b.y - top, width: b.w, height: b.h });
			});
			if (root.SMAOCritical && before.length) {
				worst = Math.max(worst, root.SMAOCritical.score(before, after, width, screen));
			}
		}
		return worst;
	}

	function pageHeight(doc) {
		return Math.max(doc.documentElement.scrollHeight, doc.body ? doc.body.scrollHeight : 0);
	}

	/**
	 * The visible pieces of an area in the finished page: every image, every
	 * block that holds its own text, every link or button, and anything with a
	 * background picture. Pieces inside a chosen piece stay with it.
	 */
	function pieces(el, done, view) {
		var picked = [];
		var seen = {};
		var area = done[el.getAttribute('data-smao-n')];
		function hasText(node) {
			for (var i = 0; i < node.childNodes.length; i++) {
				var c = node.childNodes[i];
				if (c.nodeType === 3 && c.textContent.trim()) { return true; }
			}
			return false;
		}
		function walk(node) {
			if (picked.length >= 80) { return; }
			var n = node.getAttribute && node.getAttribute('data-smao-n');
			var b = n ? done[n] : null;
			if (n && seen[n]) { return; }
			if (n && b && b.visible) {
				// Only what shows inside the area: a carousel's cards scrolled
				// out of view are clipped away in the finished page too.
				if (area && (b.x + b.w <= area.x + 1 || b.x >= area.x + area.w - 1 || b.y + b.h <= area.y + 1 || b.y >= area.y + area.h - 1)) { return; }
				var cs = view.getComputedStyle(node);
				var tag = node.tagName;
				var bg = cs.backgroundImage && cs.backgroundImage !== 'none' && cs.backgroundImage.indexOf('url(') !== -1;
				// A plain shape: an element with no children of its own that
				// is drawn only by its colour or border, like decorative dots.
				var painted = cs.backgroundColor && !/rgba\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*,\s*0\s*\)|transparent/.test(cs.backgroundColor);
				var shape = node.children.length === 0 && (painted || parseFloat(cs.borderTopWidth) > 0);
				if (tag === 'IMG' || tag === 'A' || tag === 'BUTTON' || tag === 'I' || tag === 'svg' || hasText(node) || bg || shape) {
					seen[n] = true;
					picked.push(node);
					return;
				}
			}
			for (var i = 0; i < node.children.length; i++) { walk(node.children[i]); }
		}
		for (var i = 0; i < el.children.length; i++) { walk(el.children[i]); }
		return picked;
	}

	/** A selector for an element inside an area, from the held page. */
	function inside(region, regionSel, el, doc) {
		var own = selector(el, doc);
		if (own) { return own; }
		var steps = [];
		for (var node = el; node && node !== region; node = node.parentElement) {
			var index = 1;
			for (var sib = node.previousElementSibling; sib; sib = sib.previousElementSibling) { index++; }
			steps.unshift(':nth-child(' + index + ')');
			if (!node.parentElement) { return ''; }
		}
		var sel = regionSel + ' > ' + steps.join(' > ');
		try { return doc.querySelectorAll(sel).length === 1 ? sel : ''; } catch (error) { return ''; }
	}

	var COPY = ['font-family', 'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-transform', 'text-align', 'color', 'background-color', 'background-image', 'background-size', 'background-position', 'background-repeat', 'border-radius', 'border-top', 'border-right', 'border-bottom', 'border-left', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'box-shadow', 'white-space'];

	function pageBox(doc, el) {
		var r = el.getBoundingClientRect();
		var v = doc.defaultView;
		return { x: r.left + v.scrollX, y: r.top + v.scrollY };
	}

	function firstUrl(value) {
		var start = value.indexOf('url(');
		if (start < 0) { return ''; }
		var rest = value.slice(start + 4);
		var end = rest.indexOf(')');
		return rest.slice(0, end < 0 ? rest.length : end).replace(/^["']|["']$/g, '');
	}

	/** Styles that make one area of the held page look finished. */
	function rules(el, held, done, doc, doneView) {
		var sel = selector(el, doc);
		if (!sel) { return null; }
		var n = el.getAttribute('data-smao-n');
		var a = held[n];
		var b = done[n];
		var view = doc.defaultView;
		if (!b.visible) {
			return { selector: sel, css: sel + '{display:none!important}', pieces: [] };
		}
		// An area as tall as the screen follows the screen, not this frame.
		var screenHeight = doneView.innerHeight;
		var height = Math.abs(b.h - screenHeight) <= TOLERANCE ? '100vh' : round(b.h) + 'px';
		var decl = ['height:' + height + '!important', 'min-height:0!important', 'max-height:none!important', 'overflow:hidden!important', 'visibility:visible!important', 'opacity:1!important'];
		if (view.getComputedStyle(el).display === 'none') {
			decl.push('display:' + (doneView.getComputedStyle(b.el).display || 'block') + '!important');
		}
		if (view.getComputedStyle(el).position === 'static') { decl.push('position:relative!important'); }
		if (Math.abs(a.w - b.w) > TOLERANCE || Math.abs(a.x - b.x) > TOLERANCE) {
			var docWidth = doc.documentElement.clientWidth;
			if (Math.abs(b.w - docWidth) <= TOLERANCE && b.x <= TOLERANCE) {
				decl.push('width:100vw!important', 'max-width:100vw!important', 'margin-left:calc(50% - 50vw)!important', 'margin-right:calc(50% - 50vw)!important', 'left:auto!important');
			} else {
				// Its parent may also differ until the script runs, so a share
				// of the parent would be wrong; use the finished width.
				decl.push('width:' + round(b.w) + 'px!important', 'max-width:100%!important');
			}
		}
		var lines = [sel + '{' + decl.join(';') + '}', sel + ' *{visibility:hidden!important;animation:none!important;transition:none!important}'];
		return { selector: sel, css: lines.join(NL), region: el, done: b, pieces: pieces(b.el, done, doneView) };
	}

	/**
	 * Put a piece's selector under its area's selector, so its rules always
	 * outrank the area's rule that hides everything else inside it.
	 */
	function scoped(area, sel, el, doc) {
		if (!sel) { return ''; }
		if (sel.indexOf(area.selector + ' ') === 0) { return sel; }
		var candidate = area.selector + ' ' + sel;
		try {
			var hits = doc.querySelectorAll(candidate);
			return hits.length === 1 && hits[0] === el ? candidate : '';
		} catch (error) {
			return '';
		}
	}

	/**
	 * Place each finished piece of an area exactly where it will be.
	 *
	 * Every piece is positioned against the area itself, and the containers
	 * between the area and its pieces are neutralised: no positioning,
	 * transforms, clipping or hiding of their own. Otherwise placing one piece
	 * would change the containers the next piece is measured against.
	 */
	function place(area, done, doc, doneView, perf) {
		var lines = [];
		var view = doc.defaultView;
		var regionBox = done[area.region.getAttribute('data-smao-n')];
		var between = [];
		area.pieces.forEach(function (finished) {
			var n = finished.getAttribute('data-smao-n');
			var target = doc.querySelector('[data-smao-n="' + n + '"]');
			if (!target || !area.region.contains(target)) { return; }
			var sel = scoped(area, inside(area.region, area.selector, target, doc), target, doc);
			if (!sel) { return; }
			for (var up = target.parentElement; up && up !== area.region; up = up.parentElement) {
				if (between.indexOf(up) === -1) { between.push(up); }
			}
			var fb = done[n];
			var cs = doneView.getComputedStyle(finished);
			var hs = view.getComputedStyle(target);
			var decl = ['position:absolute!important', 'left:' + round(fb.x - regionBox.x) + 'px!important', 'top:' + round(fb.y - regionBox.y) + 'px!important', 'right:auto!important', 'bottom:auto!important', 'width:' + round(fb.w) + 'px!important', 'height:' + round(fb.h) + 'px!important', 'margin:0!important', 'transform:none!important', 'box-sizing:border-box!important', 'visibility:visible!important', 'opacity:1!important', 'overflow:hidden!important', 'z-index:1'];
			if (hs.display === 'none' || hs.display === 'inline' || hs.display === 'contents') { decl.push('display:block!important'); }
			COPY.forEach(function (prop) {
				var value = cs.getPropertyValue(prop);
				if (!value || value === hs.getPropertyValue(prop)) { return; }
				if (prop === 'background-image') {
					if (value === 'none') { return; }
					var url = firstUrl(value);
					if (url && perf && !perf(url)) { return; }
				}
				decl.push(prop + ':' + value.replace(/;/g, '') + '!important');
			});
			if (finished.tagName === 'IMG') {
				var src = finished.currentSrc || finished.src || '';
				var shows = target.naturalWidth > 8 && (target.currentSrc || target.src) === src;
				if (!shows && src && src.indexOf('data:') !== 0 && finished.naturalWidth > 8 && (!perf || perf(src))) {
					// A script swaps a placeholder for the real file; show the
					// real file behind the placeholder until it does.
					var real = 'url("' + src.replace(/"/g, '%22') + '")';
					decl.push('content:' + real + '!important', 'background-image:' + real + '!important', 'background-size:100% 100%!important', 'background-repeat:no-repeat!important', 'object-position:-9999px 0!important', 'border:0!important');
				}
			}
			lines.push(sel + '{' + decl.join(';') + '}');
			lines.push(sel + ' *{visibility:visible!important}');
		});
		var chain = between.map(function (el) { return inside(area.region, area.selector, el, doc); }).filter(Boolean);
		if (chain.length) {
			lines.unshift(chain.join(',') + '{position:static!important;transform:none!important;filter:none!important;contain:none!important;opacity:1!important;clip-path:none!important;overflow:visible!important;visibility:hidden!important}');
			// Hidden containers still lay out; one that is display:none would
			// take its pieces with it.
			var gone = between.filter(function (el) { return view.getComputedStyle(el).display === 'none'; })
				.map(function (el) { return inside(area.region, area.selector, el, doc); }).filter(Boolean);
			if (gone.length) { lines.unshift(gone.join(',') + '{display:block!important}'); }
		}
		return lines;
	}

	/**
	 * How much of the finished page's content the held page is missing, as a
	 * share of all visible content, and how many placed pieces landed off
	 * target.
	 */
	function fidelity(held, done, doc, hidden) {
		var view = doc.defaultView;
		var total = 0;
		var missing = 0;
		Object.keys(done).forEach(function (n) {
			var b = done[n];
			if (!b.visible || !b.el) { return; }
			var tag = b.el.tagName;
			var content = tag === 'IMG' || tag === 'A' || tag === 'BUTTON' || tag === 'svg';
			if (!content) {
				for (var i = 0; i < b.el.childNodes.length; i++) {
					var c = b.el.childNodes[i];
					if (c.nodeType === 3 && c.textContent.trim()) { content = true; break; }
				}
			}
			if (!content) { return; }
			var area = b.w * b.h;
			total += area;
			var a = held[n];
			if (!a || !a.visible || hidden[n]) { missing += area; }
		});
		return total > 0 ? missing / total : 0;
	}

	function offTarget(held, done, pieces) {
		var off = 0;
		pieces.forEach(function (n) {
			var a = held[n];
			var b = done[n];
			if (!a || !b || !a.visible || Math.abs(a.x - b.x) > 4 || Math.abs(a.y - b.y) > 4 || Math.abs(a.w - b.w) > 4 || Math.abs(a.h - b.h) > 4) { off++; }
		});
		return pieces.length ? off / pieces.length : 0;
	}

	/**
	 * Build and check the styles for one width.
	 *
	 * @param {Document} heldDoc    Page with scripts held, labelled.
	 * @param {Object}   done       boxes() of the finished page at this width.
	 * @param {number}   doneHeight Finished page height.
	 * @param {number}   width      Viewport width.
	 * @param {Function} perf       url -> whether it loaded, or null.
	 * @param {Window}   doneView   Window of the finished page.
	 */
	function prepare(heldDoc, done, doneHeight, width, perf, doneView) {
		var held = boxes(heldDoc);
		var areas = origins(held, done, heldDoc);
		var built = [];
		var skipped = 0;
		areas.forEach(function (el) {
			var r = rules(el, held, done, heldDoc, doneView);
			if (r) { built.push(r); } else { skipped++; }
		});
		var probe = heldDoc.createElement('style');
		probe.textContent = built.map(function (r) { return r.css; }).join(NL) + NL + 'body{overflow-x:clip!important}';
		heldDoc.head.appendChild(probe);
		var placed = [];
		built.forEach(function (r) {
			if (r.region) { placed = placed.concat(place(r, done, heldDoc, doneView, perf)); }
		});
		var text = built.map(function (r) { return r.css; }).concat(placed).join(NL);
		probe.textContent = text + NL + 'body{overflow-x:clip!important}';
		var after = boxes(heldDoc);
		// Inside a rebuilt area only its placed pieces show, so only those
		// are compared there; everything outside is compared as it is.
		var inner = {};
		var pieceKeys = [];
		built.forEach(function (r) {
			if (!r.region) { return; }
			var all = r.region.querySelectorAll('[data-smao-n]');
			for (var i = 0; i < all.length; i++) { inner[all[i].getAttribute('data-smao-n')] = true; }
			r.pieces.forEach(function (piece) { pieceKeys.push(piece.getAttribute('data-smao-n')); });
		});
		var considered = {};
		Object.keys(done).forEach(function (key) { if (!inner[key]) { considered[key] = done[key]; } });
		pieceKeys.forEach(function (key) { if (done[key]) { considered[key] = done[key]; } });
		var shift = compare(after, considered, heldDoc);
		var missing = fidelity(after, considered, heldDoc, {});
		var off = offTarget(after, done, pieceKeys);
		var height = Math.abs(pageHeight(heldDoc) - doneHeight) / Math.max(doneHeight, 1);
		probe.parentNode.removeChild(probe);
		return { css: text, areas: areas.length, pieces: pieceKeys.length, skipped: skipped, shift: shift + (skipped ? 1 : 0), height: height, missing: missing, off: off };
	}

	/** Screen height to measure with, for each width. */
	function screenFor(width) {
		if (width <= 600) { return 823; }
		if (width <= 1024) { return 1024; }
		if (width <= 1600) { return 940; }
		return 1080;
	}

	/** Wrap each width's styles so they only apply while scripts wait. */
	function wrap(width, css) {
		if (!css) { return ''; }
		var scoped = css.split(NL).map(function (line) {
			var brace = line.indexOf('{');
			if (brace < 0) { return ''; }
			return 'html:not(.smao-ran) ' + line.slice(0, brace) + line.slice(brace);
		}).join(NL);
		return '@media ' + range(width) + '{' + NL + scoped + NL + 'html:not(.smao-ran) body{overflow-x:clip}' + NL + '}';
	}

	var api = { range: range, screenFor: screenFor, boxes: boxes, origins: origins, selector: selector, prepare: prepare, wrap: wrap, compare: compare, pageHeight: pageHeight, pieces: pieces, inside: inside, WIDTHS: [412, 768, 1350, 1920] };
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	} else {
		root.SMAOPrebuild = api;
	}
})(typeof window !== 'undefined' ? window : this);
