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
	// Marks a rule that applies after the scripts have run. While checking
	// the held page the browser skips it as an unknown at-rule.
	var RAN = '@smao-ran ';
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
		// Anything floating over the page, such as a chat bubble, a cookie bar
		// or a popup, is not part of its layout and is left out. Rebuilding a
		// chat widget as part of the page failed every page of a live site.
		var floating = typeof Set === 'function' ? new Set() : null;
		for (var i = 0; i < list.length; i++) {
			var el = list[i];
			var n = el.getAttribute('data-smao-n');
			var cs = view.getComputedStyle(el);
			if (floating && el.parentElement && floating.has(el.parentElement)) {
				floating.add(el);
				continue;
			}
			var r = el.getBoundingClientRect();
			// A bar pinned across the top of the screen is the page's header,
			// not something floating over it. Left out, a theme's header that
			// stays invisible until its script runs was never compared, and
			// pages showed no header or menu while scripts waited.
			if (floating && cs.position === 'fixed' && !(r.top <= TOLERANCE && r.width >= width * 0.6 && r.height > 0 && r.height <= view.innerHeight * 0.3)) {
				floating.add(el);
				continue;
			}
			// Shown: not hidden by the page. A box of no width can still show
			// its contents, which overflow it; a theme's carousel column did
			// exactly that on phones. Visible: shown and taking up space.
			var shown = cs.visibility !== 'hidden' && cs.display !== 'none' && Number(cs.opacity) > 0.01;
			var visible = shown && r.width > 0 && r.height > 0;
			var box = { x: r.left + view.scrollX, y: r.top + view.scrollY, w: r.width, h: r.height, visible: visible, shown: shown, el: el };
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
		if (a.shown !== b.shown) { return true; }
		return Math.abs(a.h - b.h) > TOLERANCE || Math.abs(a.w - b.w) > TOLERANCE;
	}

	/**
	 * Where each difference starts: an element that differs while none of its
	 * children does. Its ancestors only differ because of such elements, and
	 * fixing them fixes the ancestors; the check afterwards confirms it.
	 */
	function origins(held, done, doc) {
		var found = [];
		var body = doc.body;
		function walk(el) {
			var n = el.getAttribute && el.getAttribute('data-smao-n');
			var a = n ? held[n] : null;
			var b = n ? done[n] : null;
			if (n && a && b && differs(a, b)) {
				var kids = children(el);
				var dh = b.h - a.h;
				var dw = b.w - a.w;
				// The change starts further down when one child changed by the
				// same amount, or when the children's own changes add up to it,
				// as when sections stacked down a page each change a little.
				// A parent that rearranges its children, such as a row of cards
				// a script lays out side by side, is rebuilt as a whole. Taking
				// the parent whenever no single child explained everything
				// rebuilt entire pages as frozen pieces, and the whole page then
				// reflowed at once when the scripts started.
				var sum = 0;
				var changed = 0;
				var single = false;
				kids.forEach(function (kid) {
					var ka = held[kid.getAttribute('data-smao-n')];
					var kb = done[kid.getAttribute('data-smao-n')];
					if (!ka || !kb || !differs(ka, kb)) { return; }
					changed++;
					sum += kb.h - ka.h;
					if (Math.abs((kb.h - ka.h) - dh) <= TOLERANCE && Math.abs((kb.w - ka.w) - dw) <= TOLERANCE && ka.shown === a.shown) { single = true; }
				});
				var stacked = changed > 1 && Math.abs(dw) <= TOLERANCE && Math.abs(sum - dh) <= TOLERANCE * (changed + 1);
				var explained = a.shown === b.shown && (single || stacked);
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
			// Only an id the page uses once: a live home page had two sections
			// with the same id, and nothing inside either could be targeted.
			if (node.id && /^[A-Za-z][\w-]*$/.test(node.id) && doc.querySelectorAll('[id="' + node.id + '"]').length === 1) { return '#' + node.id; }
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
		// Only an area that cuts off its overflow hides what lies outside it;
		// a logo drawn larger than its link shows in full.
		var areaClips = area && /hidden|clip|auto|scroll/.test(view.getComputedStyle(el).overflow);
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
				if (area && areaClips && area.w >= 1 && area.h >= 1 && (b.x + b.w <= area.x + 1 || b.x >= area.x + area.w - 1 || b.y + b.h <= area.y + 1 || b.y >= area.y + area.h - 1)) { return; }
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

	/** Whether an element holds only text, or a single thing and its text. */
	function simple(el, view) {
		var things = 0;
		var all = el.querySelectorAll('*');
		for (var i = 0; i < all.length; i++) {
			var cs = view.getComputedStyle(all[i]);
			if (cs.display === 'none' || cs.visibility === 'hidden' || cs.display === 'inline' || all[i].tagName === 'BR') { continue; }
			if (all[i].children.length === 0 && ++things > 1) { return false; }
		}
		return true;
	}

	/** Declarations every placed piece shares. */
	var PIECE = 'position:absolute!important;right:auto!important;bottom:auto!important;margin:0!important;transform:none!important;box-sizing:border-box!important;visibility:visible!important;opacity:1!important;overflow:hidden!important;z-index:1';

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

	/**
	 * The margin of an element's first or last child that, in normal flow,
	 * shows outside the element instead of inside it.
	 */
	function collapsed(el, view, edge) {
		var largest = 0;
		var node = el;
		for (var depth = 0; depth < 4 && node; depth++) {
			var cs = view.getComputedStyle(node);
			if (cs.display !== 'block' || parseFloat(cs['padding-' + edge]) > 0 || parseFloat(cs['border-' + edge + '-width']) > 0) { break; }
			if (cs.overflow !== 'visible' && cs.overflow !== 'clip') { break; }
			var kids = node.children;
			var child = null;
			for (var i = 0; i < kids.length; i++) {
				var k = kids[edge === 'top' ? i : kids.length - 1 - i];
				var ks = view.getComputedStyle(k);
				if (ks.display === 'none' || ks.position === 'absolute' || ks.position === 'fixed' || ks.float !== 'none' || k.tagName === 'SCRIPT' || k.tagName === 'STYLE') { continue; }
				child = k;
				break;
			}
			if (!child) { break; }
			var margin = parseFloat(view.getComputedStyle(child)['margin-' + edge]) || 0;
			if (margin > largest) { largest = margin; }
			node = child;
		}
		return largest;
	}

	/**
	 * The margin an element shows at one edge once its children's margins
	 * collapse through it, as a declaration, or '' when that is its own.
	 * Collapsing adds the largest positive margin to the most negative one:
	 * a section pulled up by -340px over the one before it has to keep that
	 * pull, not be set to its children's 0.
	 */
	function carried(el, view, edge) {
		var own = parseFloat(view.getComputedStyle(el)['margin-' + edge]) || 0;
		var shown = Math.max(own, collapsed(el, view, edge), 0) + Math.min(own, 0);
		return Math.abs(shown - own) > 0.5 ? 'margin-' + edge + ':' + round(shown) + 'px!important' : '';
	}

	/** Styles that make one area of the held page look finished. */
	function rules(el, held, done, doc, doneView) {
		var sel = selector(el, doc);
		if (!sel) { return null; }
		var n = el.getAttribute('data-smao-n');
		var a = held[n];
		var b = done[n];
		var view = doc.defaultView;
		if (!b.shown && b.w < 1 && b.h < 1) {
			return { selector: sel, css: sel + '{display:none!important}', pieces: [] };
		}
		// Invisible in the finished page yet taking up room, as a block that
		// waits to fade in does: it keeps that room and stays invisible.
		// Removed instead, a 310px block took the whole page below it up.
		var invisible = !b.shown;
		// An area as tall as the screen follows the screen, not this frame.
		var screenHeight = doneView.innerHeight;
		var height = Math.abs(b.h - screenHeight) <= TOLERANCE ? '100vh' : round(b.h) + 'px';
		// A box with no width or height shows its contents only by letting
		// them overflow, so it must not clip them. clip rather than hidden,
		// which would also change how margins behave at the area's edges.
		var clip = b.w >= 1 && b.h >= 1 && /hidden|clip|auto|scroll/.test(doneView.getComputedStyle(b.el).overflow) ? 'clip' : 'visible';
		// Layout containment keeps what the area holds inside it: floated
		// carousel slides spilling out of the fixed height made the widget
		// around it 398px taller. Margins at its edges are carried below.
		var decl = ['height:' + height + '!important', 'min-height:0!important', 'max-height:none!important', 'overflow:' + clip + '!important', 'contain:layout!important'].concat(invisible ? ['visibility:hidden!important'] : ['visibility:visible!important', 'opacity:1!important']);
		if (view.getComputedStyle(el).display === 'none') {
			decl.push('display:' + (doneView.getComputedStyle(b.el).display || 'block') + '!important');
		}
		if (view.getComputedStyle(el).position === 'static') { decl.push('position:relative!important'); }
		// An inline element ignores a width and height, so it could not keep
		// its finished size: a logo link stayed zero wide until its image
		// loaded. As an inline block it can.
		if (view.getComputedStyle(el).display === 'inline') { decl.push('display:inline-block!important', 'vertical-align:top!important'); }
		// In the finished page the margin of the area's first or last element
		// can show outside the area, pushing it and everything after it. While
		// scripts wait those elements are pinned in place and their margins
		// no longer count, so the area carries them itself. A heading's margin
		// moved every such area, and the rest of the page, by 20px.
		['top', 'bottom'].forEach(function (edge) {
			var carry = carried(b.el, doneView, edge);
			if (carry) { decl.push(carry); }
		});
		if (Math.abs(a.w - b.w) > TOLERANCE || Math.abs(a.x - b.x) > TOLERANCE) {
			var docWidth = doc.documentElement.clientWidth;
			if (Math.abs(b.w - docWidth) <= TOLERANCE && b.x <= TOLERANCE) {
				// 100vw includes a desktop scrollbar the page itself does not
					// have; --smao-sb, set before the first paint, takes it off.
					decl.push('width:calc(100vw - var(--smao-sb,0px))!important', 'max-width:none!important', 'margin-left:calc(50% - 50vw + var(--smao-sb,0px) / 2)!important', 'margin-right:calc(50% - 50vw + var(--smao-sb,0px) / 2)!important', 'left:auto!important');
			} else {
				// Its parent may also differ until the script runs, so a share
				// of the parent would be wrong; use the finished width.
				decl.push('width:' + round(b.w) + 'px!important', 'max-width:100%!important');
			}
		}
		var lines = [sel + '{' + decl.join(';') + '}', sel + ' *{visibility:hidden!important;animation:none!important;transition:none!important}'];
		// Once the scripts run, the area keeps at least its finished height.
		// Many widgets only build as they scroll into view; without this the
		// area would fall back to empty until then and the page below it
		// would move twice, up now and down again in front of the visitor.
		lines.push(RAN + sel + '{min-height:' + height + '!important}');
		return { selector: sel, css: lines.join(NL), region: el, done: b, pieces: invisible ? [] : pieces(b.el, done, doneView) };
	}

	/**
	 * Put a piece's selector under its area's selector, so its rules always
	 * outrank the area's rule that hides everything else inside it.
	 */
	function scoped(area, sel, el, doc) {
		var only = function (candidate) {
			try {
				var hits = doc.querySelectorAll(candidate);
				return hits.length === 1 && hits[0] === el;
			} catch (error) {
				return false;
			}
		};
		if (sel && sel.indexOf(area.selector + ' ') === 0) { return sel; }
		if (sel && only(area.selector + ' ' + sel)) { return area.selector + ' ' + sel; }
		// The piece's own selector can start at the area itself, which then
		// appears twice and matches nothing; a live page lost its main
		// paragraph that way. Its exact place below the area always works.
		var steps = [];
		for (var node = el; node && node !== area.region; node = node.parentElement) {
			var index = 1;
			for (var sib = node.previousElementSibling; sib; sib = sib.previousElementSibling) { index++; }
			steps.unshift(node.tagName.toLowerCase() + ':nth-child(' + index + ')');
			if (!node.parentElement) { return ''; }
		}
		var path = area.selector + ' > ' + steps.join(' > ');
		return steps.length && only(path) ? path : '';
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
		var placedSelectors = [];
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
			// What every piece shares is written once for the area, below.
			placedSelectors.push(sel);
			var decl = ['left:' + round(fb.x - regionBox.x) + 'px!important', 'top:' + round(fb.y - regionBox.y) + 'px!important', 'width:' + round(fb.w) + 'px!important', 'height:' + round(fb.h) + 'px!important'];
			// What the finished page draws past a piece's own box stays whole
			// when the piece holds only text or one thing: a slider title with
			// a 9px line height for 32px letters, and a logo a theme shifts up
			// inside its link, were both cut off.
			if (finished.tagName !== 'IMG' && cs.overflow === 'visible' && simple(finished, doneView)) { decl.push('overflow:visible!important'); }
			if (hs.display === 'none' || hs.display === 'inline' || hs.display === 'contents') { decl.push('display:block!important'); }
			// Below the first screen nobody sees a piece before interacting,
			// and scrolling is interacting, which starts the real scripts. So
			// there a piece only holds its place: a picture would still be
			// downloaded, and on a slow phone every one of them competes with
			// the page's main image. 3 MB of them delayed a live home page.
			var below = fb.y >= doneView.innerHeight * 1.1;
			COPY.forEach(function (prop) {
				var value = cs.getPropertyValue(prop);
				if (!value || value === hs.getPropertyValue(prop)) { return; }
				if (prop === 'background-image') {
					if (value === 'none' || below) { return; }
					var url = firstUrl(value);
					if (url && perf && !perf(url)) { return; }
				}
				decl.push(prop + ':' + value.replace(/;/g, '') + '!important');
			});
			if (finished.tagName === 'IMG') {
				var src = finished.currentSrc || finished.src || '';
				var shows = target.naturalWidth > 8 && (target.currentSrc || target.src) === src;
				if (!below && !shows && src && src.indexOf('data:') !== 0 && finished.naturalWidth > 8 && (!perf || perf(src))) {
					// A script swaps a placeholder for the real file; show the
					// real file behind the placeholder until it does.
					var real = 'url("' + src.replace(/"/g, '%22') + '")';
					decl.push('content:' + real + '!important', 'background-image:' + real + '!important', 'background-size:100% 100%!important', 'background-repeat:no-repeat!important', 'object-position:-9999px 0!important', 'border:0!important');
				}
			}
			lines.push(sel + '{' + decl.join(';') + '}');
		});
		painted(area, regionBox, doc, doneView, perf).forEach(function (item) {
			var sel = scoped(area, inside(area.region, area.selector, item.img, doc), item.img, doc);
			if (!sel || placedSelectors.indexOf(sel) !== -1) { return; }
			for (var up = item.img.parentElement; up && up !== area.region; up = up.parentElement) {
				if (between.indexOf(up) === -1) { between.push(up); }
			}
			placedSelectors.push(sel);
			var real = 'url("' + item.src.replace(/"/g, '%22') + '")';
			lines.push(sel + '{left:' + round(item.x) + 'px!important;top:' + round(item.y) + 'px!important;width:' + round(item.w) + 'px!important;height:' + round(item.h) + 'px!important;display:block!important;max-width:none!important;max-height:none!important;border:0!important;content:' + real + '!important;object-fit:cover!important;object-position:center!important}');
		});
		if (placedSelectors.length) {
			lines.unshift(
				placedSelectors.join(',') + '{' + PIECE + '}',
				placedSelectors.map(function (s) { return s + ' *'; }).join(',') + '{visibility:visible!important}'
			);
		}
		var chain = between.map(function (el) { return inside(area.region, area.selector, el, doc); }).filter(Boolean);
		if (chain.length) {
			lines.unshift(chain.join(',') + '{position:static!important;transform:none!important;filter:none!important;contain:none!important;opacity:1!important;clip-path:none!important;overflow:visible!important;visibility:hidden!important}');
			// Hidden containers still lay out; one that is display:none would
			// take its pieces with it.
			var gone = between.filter(function (el) { return view.getComputedStyle(el).display === 'none'; })
				.map(function (el) { return inside(area.region, area.selector, el, doc); }).filter(Boolean);
			if (gone.length) { lines.unshift(gone.join(',') + '{display:block!important}'); }
			// A container that paints a solid backdrop, like a header bar's
			// white, keeps showing it; only what it holds stays hidden. Its
			// place is checked with the pieces.
			area.backdrops = [];
			between.forEach(function (el) {
				var n = el.getAttribute('data-smao-n');
				var box = n && done[n];
				if (!box || !box.visible || box.w * box.h < 10000 || box.x < -2 || box.x + box.w > doc.documentElement.clientWidth + 2) { return; }
				// Only where it already stands: a carousel's cards, stacked
				// while scripts wait, would paint their backdrops elsewhere.
				var now = el.getBoundingClientRect();
				if (Math.abs(now.left + view.scrollX - box.x) > 4 || Math.abs(now.top + view.scrollY - box.y) > 4 || Math.abs(now.width - box.w) > 4) { return; }
				var color = doneView.getComputedStyle(box.el).backgroundColor;
				var alpha = /rgba\([^)]*,\s*([\d.]+)\s*\)/.exec(color || '');
				if (!color || color === 'transparent' || (alpha && Number(alpha[1]) < 0.5)) { return; }
				var sel = scoped(area, inside(area.region, area.selector, el, doc), el, doc);
				if (!sel) { return; }
				// Its contents are placed on their own, so it keeps its finished size.
				lines.push(sel + '{visibility:visible!important;background-color:' + color + '!important;box-sizing:border-box!important;width:' + round(box.w) + 'px!important;height:' + round(box.h) + 'px!important;min-height:0!important;max-width:none!important}');
				area.backdrops.push(n);
			});
		}
		return lines;
	}

	/** Attributes lazy loaders keep an image's real file in. */
	var LAZY = ['data-lazyload', 'data-lazy-src', 'data-src'];

	/**
	 * Pictures a script paints onto a canvas in the first screen.
	 *
	 * Slider Revolution draws each slide's photo on a canvas it creates; the
	 * page itself only holds a placeholder image with the real file in a data
	 * attribute. With no piece for the canvas, a live home page showed a grey
	 * block where its photo belongs while scripts waited. For each large
	 * canvas, the biggest lazy image of the element it belongs to is shown in
	 * its place, filling it as the slider does.
	 */
	function painted(area, regionBox, doc, doneView, perf) {
		var found = [];
		var region = area.done && area.done.el;
		if (!region || !regionBox) { return found; }
		var canvases = region.getElementsByTagName('canvas');
		for (var i = 0; i < canvases.length && found.length < 3; i++) {
			var canvas = canvases[i];
			var r = canvas.getBoundingClientRect();
			var cs = doneView.getComputedStyle(canvas);
			var top = r.top + doneView.scrollY;
			if (r.width * r.height < regionBox.w * regionBox.h * 0.3 || cs.visibility === 'hidden' || Number(cs.opacity) < 0.5 || top >= doneView.innerHeight * 1.1) { continue; }
			var owner = canvas.parentElement;
			while (owner && owner !== region && !owner.hasAttribute('data-smao-n')) { owner = owner.parentElement; }
			var held = owner && doc.querySelector('[data-smao-n="' + owner.getAttribute('data-smao-n') + '"]');
			if (!held) { continue; }
			var best = null;
			var bestSize = -1;
			var images = held.getElementsByTagName('img');
			for (var j = 0; j < images.length; j++) {
				var src = '';
				for (var k = 0; k < LAZY.length && !src; k++) { src = images[j].getAttribute(LAZY[k]) || ''; }
				if (!src || src.indexOf('data:') === 0) { continue; }
				var size = (parseFloat(images[j].getAttribute('width')) || 1) * (parseFloat(images[j].getAttribute('height')) || 1);
				if (size > bestSize) { best = { img: images[j], src: src }; bestSize = size; }
			}
			if (!best) { continue; }
			try { best.src = new URL(best.src, doc.baseURI).href; } catch (error) { continue; }
			if (perf && !perf(best.src)) { continue; }
			found.push({ img: best.img, src: best.src, x: r.left + doneView.scrollX - regionBox.x, y: top - regionBox.y, w: r.width, h: r.height });
		}
		return found;
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
	 * Background pictures below the first screen wait while the scripts do.
	 *
	 * A browser downloads the background of everything it lays out, on
	 * screen or not, as soon as the styles arrive. Page builders put a
	 * background on nearly every section, so a slow phone fetched pictures
	 * from the whole page while the main one waited its turn. Nobody sees
	 * below the first screen before scrolling, and scrolling starts the
	 * scripts, which lifts these rules. Backgrounds never affect layout.
	 */
	function laterBackgrounds(doc, done, doneView) {
		var view = doc.defaultView;
		var fold = doneView.innerHeight * 1.1;
		var picked = [];
		var list = doc.querySelectorAll('[data-smao-n]');
		for (var i = 0; i < list.length && picked.length < 150; i++) {
			var el = list[i];
			var bg = view.getComputedStyle(el).backgroundImage;
			if (!bg || bg.indexOf('url(') === -1) { continue; }
			var box = done[el.getAttribute('data-smao-n')];
			if (!box || box.y < fold) { continue; }
			var sel = selector(el, doc);
			if (sel) { picked.push(sel); }
		}
		return picked.length ? [picked.join(',') + '{background-image:none!important}'] : [];
	}

	/**
	 * Page preloaders: a layer covering the whole screen while the page loads,
	 * which the theme's script removes once it has. With scripts waiting, that
	 * script waits too, and the page stayed hidden behind the layer: a blank
	 * screen for nearly four seconds on a live phone test. A layer that covers
	 * the held page but is gone from the finished one is hidden while scripts
	 * wait.
	 */
	function loaders(doc, doneView) {
		var view = doc.defaultView;
		var doneDoc = doneView.document;
		var w = view.innerWidth;
		var h = view.innerHeight;
		var lines = [];
		var all = doc.body ? doc.body.getElementsByTagName('*') : [];
		for (var i = 0; i < all.length && lines.length < 5; i++) {
			var el = all[i];
			var cs = view.getComputedStyle(el);
			if ((cs.position !== 'fixed' && cs.position !== 'absolute') || cs.display === 'none' || cs.visibility === 'hidden' || Number(cs.opacity) < 0.5) { continue; }
			var r = el.getBoundingClientRect();
			if (r.width < w * 0.8 || r.height < h * 0.8 || r.top > h * 0.1) { continue; }
			var n = el.getAttribute('data-smao-n');
			var finished = n ? doneDoc.querySelector('[data-smao-n="' + n + '"]') : null;
			var gone = !finished;
			if (finished) {
				var fs = doneView.getComputedStyle(finished);
				var fr = finished.getBoundingClientRect();
				gone = fs.display === 'none' || fs.visibility === 'hidden' || Number(fs.opacity) < 0.05 || fr.width * fr.height < w * h * 0.05;
			}
			if (!gone) { continue; }
			var sel = selector(el, doc);
			if (sel) { lines.push(sel + '{display:none!important}'); }
		}
		return lines;
	}

	/**
	 * Large blocks entirely below the first screen, which the browser may skip
	 * drawing until they come near the screen.
	 *
	 * Style and layout of everything on a long page was most of a phone's
	 * blocking time before the first paint. Each block keeps its finished
	 * height, and carries the margins its first and last element would
	 * otherwise have pushed outside it, so the page around it is unchanged.
	 * A block holding anything fixed or sticky is left alone, since skipping
	 * would pin that to the block instead of the screen.
	 */
	function laterSections(doc, done, doneView) {
		var fold = doneView.innerHeight * 1.1;
		var width = doc.documentElement.clientWidth;
		var lines = [];
		var view = doc.defaultView;
		function pinned(el) {
			var all = el.getElementsByTagName('*');
			for (var i = 0; i < all.length && i < 3000; i++) {
				var p = view.getComputedStyle(all[i]).position;
				if (p === 'fixed' || p === 'sticky') { return true; }
			}
			return all.length >= 3000;
		}
		function walk(el, depth) {
			for (var i = 0; i < el.children.length && lines.length < 40; i++) {
				var child = el.children[i];
				var n = child.getAttribute('data-smao-n');
				var box = n ? done[n] : null;
				if (!box || !box.visible) { continue; }
				if (box.y >= fold && box.h >= 150 && box.w >= width * 0.5) {
					var cs = view.getComputedStyle(child);
					if (cs.display === 'contents' || cs.display.indexOf('inline') === 0 || cs.position === 'fixed' || cs.position === 'sticky' || pinned(child)) { continue; }
					var sel = selector(child, doc);
					if (!sel) { continue; }
					var decl = ['content-visibility:auto!important', 'contain-intrinsic-size:auto ' + round(box.h) + 'px!important'];
					['top', 'bottom'].forEach(function (edge) {
						var carry = carried(box.el, doneView, edge);
						if (carry) { decl.push(carry); }
					});
					lines.push(sel + '{' + decl.join(';') + '}');
				} else if (box.y + box.h > fold && depth < 14) {
					walk(child, depth + 1);
				}
			}
		}
		if (doc.body) { walk(doc.body, 0); }
		return lines;
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
		var text = built.map(function (r) { return r.css; }).concat(placed, laterBackgrounds(heldDoc, done, doneView), loaders(heldDoc, doneView)).join(NL);
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
			(r.backdrops || []).forEach(function (key) { pieceKeys.push(key); });
		});
		var considered = {};
		Object.keys(done).forEach(function (key) { if (!inner[key]) { considered[key] = done[key]; } });
		pieceKeys.forEach(function (key) { if (done[key]) { considered[key] = done[key]; } });
		var shift = compare(after, considered, heldDoc);
		var missing = fidelity(after, considered, heldDoc, {});
		var off = offTarget(after, done, pieceKeys);
		var height = Math.abs(pageHeight(heldDoc) - doneHeight) / Math.max(doneHeight, 1);
		var result = { css: text, areas: areas.length, pieces: pieceKeys.length, skipped: skipped, shift: shift + (skipped ? 1 : 0), height: height, missing: missing, off: off, inner: inner, skippedRendering: 0 };
		// Then, if the page passed, let the browser skip drawing what is below
		// the first screen, and check again; keep it only if nothing changed.
		var later = passes(result) ? laterSections(heldDoc, done, doneView) : [];
		if (later.length) {
			var withLater = text + NL + later.join(NL);
			probe.textContent = withLater + NL + 'body{overflow-x:clip!important}';
			var again = boxes(heldDoc);
			var trial = {
				shift: compare(again, considered, heldDoc) + (skipped ? 1 : 0),
				height: Math.abs(pageHeight(heldDoc) - doneHeight) / Math.max(doneHeight, 1),
				missing: fidelity(again, considered, heldDoc, {}),
				off: offTarget(again, done, pieceKeys)
			};
			if (passes(trial) && trial.shift <= result.shift + 0.001 && trial.height <= result.height + 0.001) {
				result.css = withLater;
				result.skippedRendering = later.length;
			}
		}
		probe.parentNode.removeChild(probe);
		return result;
	}

	/** Screen height to measure with, for each width. */
	function screenFor(width) {
		if (width <= 600) { return 823; }
		if (width <= 1024) { return 1024; }
		if (width <= 1600) { return 940; }
		return 1080;
	}

	/** Split a selector list on its top-level commas. */
	function list(selectors) {
		var out = [];
		var depth = 0;
		var start = 0;
		for (var i = 0; i < selectors.length; i++) {
			var c = selectors.charAt(i);
			if (c === '(' || c === '[') { depth++; }
			else if (c === ')' || c === ']') { depth--; }
			else if (c === ',' && depth === 0) { out.push(selectors.slice(start, i)); start = i + 1; }
		}
		out.push(selectors.slice(start));
		return out.map(function (s) { return s.trim(); }).filter(Boolean);
	}

	/**
	 * Wrap each width's styles so they only apply while scripts wait.
	 *
	 * Every selector in a list is scoped, not just the first: an unscoped one
	 * would keep applying after the scripts run, and hide or pin the very
	 * elements the scripts go on to show.
	 */
	function scopeLine(line) {
		var brace = line.indexOf('{');
		if (brace < 0) { return ''; }
		var scope = 'html:not(.smao-ran):not(.smao-open) ';
		if (line.indexOf(RAN) === 0) {
			scope = 'html.smao-ran:not(.smao-open) ';
			line = line.slice(RAN.length);
			brace = line.indexOf('{');
		}
		return list(line.slice(0, brace)).map(function (sel) { return scope + sel; }).join(',') + line.slice(brace);
	}

	/**
	 * Every width's styles in one sheet, each rule written once for all the
	 * widths it belongs to. Most rules are the same at several widths.
	 *
	 * Each width's rules keep their order: a rule is only shared with an
	 * earlier width's copy when that copy comes after everything already
	 * placed for this width, otherwise it is written again.
	 *
	 * @param {Object} byWidth width => styles from prepare().
	 */
	function combine(byWidth) {
		var entries = [];
		Object.keys(byWidth).map(Number).sort(function (a, b) { return a - b; }).forEach(function (width) {
			var css = byWidth[width];
			if (!css) { return; }
			var last = -1;
			css.split(NL).concat(['body{overflow-x:clip}']).forEach(function (line) {
				if (line.indexOf('{') < 0) { return; }
				var at = -1;
				for (var i = last + 1; i < entries.length; i++) {
					if (entries[i].line === line) { at = i; break; }
				}
				if (at === -1) {
					at = last + 1;
					entries.splice(at, 0, { line: line, widths: [] });
				}
				entries[at].widths.push(width);
				last = at;
			});
		});
		var out = [];
		var group = null;
		entries.forEach(function (entry) {
			var key = entry.widths.slice().sort(function (a, b) { return a - b; }).join(',');
			if (!group || group.key !== key) {
				group = { key: key, widths: entry.widths, lines: [] };
				out.push(group);
			}
			group.lines.push(scopeLine(entry.line));
		});
		return out.map(function (g) {
			var queries = g.widths.slice().sort(function (a, b) { return a - b; }).map(range);
			return '@media ' + queries.join(', ') + '{' + NL + g.lines.join(NL) + NL + '}';
		}).join(NL);
	}

	function wrap(width, css) {
		if (!css) { return ''; }
		var scoped = css.split(NL).map(scopeLine).join(NL);
		return '@media ' + range(width) + '{' + NL + scoped + NL + 'html:not(.smao-ran):not(.smao-open) body{overflow-x:clip}' + NL + '}';
	}

	/** The same limits the server applies (Prebuild::MAX_*). */
	var LIMITS = { shift: 0.02, height: 0.01, missing: 0.01, off: 0.05 };

	/** Whether one width's result is good enough, and finite at all. */
	function passes(result) {
		return Object.keys(LIMITS).every(function (key) {
			return isFinite(result[key]) && result[key] >= 0 && result[key] <= LIMITS[key];
		});
	}

	/** How far over its limits a result is, for keeping the better of two tries. */
	function badness(result) {
		return Object.keys(LIMITS).reduce(function (sum, key) {
			var value = result[key];
			return sum + (isFinite(value) ? Math.max(0, value - LIMITS[key]) / LIMITS[key] : 1e6);
		}, 0);
	}

	var api = { range: range, screenFor: screenFor, boxes: boxes, origins: origins, selector: selector, prepare: prepare, wrap: wrap, combine: combine, list: list, compare: compare, pageHeight: pageHeight, pieces: pieces, inside: inside, passes: passes, badness: badness, LIMITS: LIMITS, WIDTHS: [412, 768, 1350, 1920] };
	if (typeof module !== 'undefined' && module.exports) {
		module.exports = api;
	} else {
		root.SMAOPrebuild = api;
	}
})(typeof window !== 'undefined' ? window : this);
