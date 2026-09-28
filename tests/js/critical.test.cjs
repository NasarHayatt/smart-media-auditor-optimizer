const test = require('node:test');
const assert = require('node:assert/strict');
const critical = require('../../assets/critical.js');

test('relative urls are rewritten against the stylesheet, absolute ones kept', () => {
  const base = 'https://example.com/wp-content/themes/t/css/style.css';
  assert.equal(
    critical.absolutize('.a{background:url(../img/bg.png)}', base),
    '.a{background:url("https://example.com/wp-content/themes/t/img/bg.png")}'
  );
  assert.equal(
    critical.absolutize("@font-face{src:url('fonts/x.woff2') format('woff2')}", base),
    "@font-face{src:url(\"https://example.com/wp-content/themes/t/css/fonts/x.woff2\") format('woff2')}"
  );
  const kept = '.a{background:url(data:image/png;base64,AAA)} .b{background:url("https://cdn.example.com/x.png")} .c{mask:url(#m)}';
  assert.equal(critical.absolutize(kept, base), kept);
  assert.equal(critical.absolutize('.a{background:url(x.png)}', null), '.a{background:url(x.png)}');
});

test('only font faces that the captured rules use are kept', () => {
  const fonts = [
    { family: 'barlow', text: '@font-face{font-family:Barlow}' },
    { family: 'dashicons', text: '@font-face{font-family:dashicons;src:url(data:...)}' },
    { family: '', text: '@font-face{}' }
  ];
  assert.deepEqual(critical.usedFonts(fonts, 'body{font-family:"Barlow",sans-serif}'), ['@font-face{font-family:Barlow}']);
  assert.deepEqual(critical.usedFonts(fonts, 'body{color:red}'), []);
});

test('group headers keep the condition and drop the body', () => {
  assert.equal(critical.groupHeader({ cssText: '@media (max-width: 767px) { .a { color: red; } }' }), '@media (max-width: 767px)');
  assert.equal(critical.groupHeader({ cssText: '@layer base { .a { color: red; } }' }), '@layer base');
  assert.equal(critical.groupHeader({ cssText: '' }), '');
});

test('an identical layout scores zero', () => {
  const boxes = [{ left: 0, top: 0, width: 1000, height: 100 }, { left: 0, top: 100, width: 500, height: 300 }];
  assert.equal(critical.score(boxes, boxes.map((b) => ({ ...b })), 1000, 1100), 0);
});

test('sub-pixel and two-pixel differences are not shifts', () => {
  const before = [{ left: 0, top: 100, width: 500, height: 300 }];
  const after = [{ left: 1.5, top: 102, width: 500, height: 300 }];
  assert.equal(critical.score(before, after, 1000, 1100), 0);
});

test('a large block moving far scores as a large shift', () => {
  const before = [{ left: 0, top: 0, width: 1000, height: 1100 }];
  const after = [{ left: 0, top: 600, width: 1000, height: 1100 }];
  const shift = critical.score(before, after, 1000, 1100);
  assert.ok(shift > 0.5, 'expected a large shift, got ' + shift);
});

test('a small element moving a little stays under the acceptance limit', () => {
  const before = [{ left: 0, top: 500, width: 40, height: 20 }];
  const after = [{ left: 0, top: 510, width: 40, height: 20 }];
  const shift = critical.score(before, after, 1000, 1100);
  assert.ok(shift > 0 && shift < 0.01, 'expected a tiny shift, got ' + shift);
});

test('content that disappears counts as a shift', () => {
  const before = [{ left: 0, top: 0, width: 1000, height: 400 }];
  const after = [{ left: 0, top: 0, width: 0, height: 0 }];
  assert.ok(critical.score(before, after, 1000, 1100) > 0.1);
});

test('off-screen elements do not count', () => {
  const before = [{ left: -400, top: 0, width: 300, height: 900 }];
  const after = [{ left: -380, top: 50, width: 300, height: 900 }];
  assert.equal(critical.score(before, after, 375, 900), 0);
});

test('the first screenful is taller on desktop than on a phone', () => {
  assert.equal(critical.fold(390), 900);
  assert.equal(critical.fold(1280), 1100);
});

test('background urls are read from computed styles, gradients and data uris ignored', () => {
  assert.equal(critical.backgroundUrl('url("https://x.test/a.png")'), 'https://x.test/a.png');
  assert.equal(critical.backgroundUrl('linear-gradient(red, blue), url(https://x.test/b.jpg)'), 'https://x.test/b.jpg');
  assert.equal(critical.backgroundUrl('none'), '');
  assert.equal(critical.backgroundUrl('url("data:image/png;base64,AAA")'), '');
});

const prebuild = require('../../assets/prebuild.js');

test('pre-built styles only apply while scripts wait, per width range', () => {
  const css = prebuild.wrap(412, '#slider{height:275px!important}\n#slider *{visibility:hidden!important}');
  assert.match(css, /^@media \(max-width: 600px\)\{/);
  assert.match(css, /html:not\(\.smao-ran\) #slider\{height:275px!important\}/);
  assert.match(css, /html:not\(\.smao-ran\) #slider \*\{visibility:hidden!important\}/);
  assert.equal(prebuild.wrap(1350, ''), '');
  assert.equal(prebuild.range(1920), '(min-width: 1601px)');
  assert.equal(prebuild.screenFor(412), 823);
  assert.deepEqual(prebuild.WIDTHS, [412, 768, 1350, 1920]);
});

test('every selector in a list is scoped, so nothing outlives the scripts', () => {
  const css = prebuild.wrap(1350, '#menu > li:nth-of-type(2),#menu > li:nth-of-type(3){visibility:hidden!important}\n#a *,#b *{visibility:visible!important}');
  assert.match(css, /html:not\(\.smao-ran\) #menu > li:nth-of-type\(2\),html:not\(\.smao-ran\) #menu > li:nth-of-type\(3\)\{/);
  assert.match(css, /html:not\(\.smao-ran\) #a \*,html:not\(\.smao-ran\) #b \*\{/);
  // No rule inside the media block may apply once html.smao-ran is set.
  css.split('\n').slice(1, -1).forEach((line) => {
    const brace = line.indexOf('{');
    if (brace < 0) { return; }
    prebuild.list(line.slice(0, brace)).forEach((sel) => assert.ok(sel.startsWith('html:not(.smao-ran) ') || sel.startsWith('html.smao-ran '), 'unscoped: ' + sel));
  });
  assert.deepEqual(prebuild.list('a:not(.x,.y),b[data-a="1,2"] , c'), ['a:not(.x,.y)', 'b[data-a="1,2"]', 'c']);
});

test('a width passes only when every measure is finite and within its limit', () => {
  const ok = { shift: 0, height: 0.005, missing: 0, off: 0.02 };
  assert.equal(prebuild.passes(ok), true);
  assert.equal(prebuild.passes({ ...ok, height: 0.05 }), false);
  assert.equal(prebuild.passes({ ...ok, shift: NaN }), false);
  assert.equal(prebuild.passes({ ...ok, off: Infinity }), false);
  assert.ok(prebuild.badness({ ...ok, height: 0.05 }) < prebuild.badness({ ...ok, height: 0.3 }));
  assert.equal(prebuild.badness(ok), 0);
  assert.deepEqual(prebuild.LIMITS, { shift: 0.02, height: 0.01, missing: 0.01, off: 0.05 });
});

test('rules shared by several widths are written once, and each width keeps its order', () => {
  const byWidth = {
    412: '#a{height:1px!important}\n#a *{visibility:hidden!important}\n#p{left:1px!important}',
    768: '#a{height:1px!important}\n#a *{visibility:hidden!important}\n#p{left:2px!important}',
    1350: '#b{height:9px!important}\n#a{height:1px!important}',
    1920: '',
  };
  const css = prebuild.combine(byWidth);
  assert.equal(css.split('#a *{').length - 1, 1, 'shared rule written once');
  assert.match(css, /@media \(max-width: 600px\), \(min-width: 601px\) and \(max-width: 1024px\), \(min-width: 1025px\) and \(max-width: 1600px\)\{\nhtml:not\(\.smao-ran\) #a\{height:1px!important\}/);
  assert.equal(css.split('#a{').length - 1, 1);
  assert.ok(!/1601/.test(css), 'a width with nothing to rebuild adds nothing');
  // Rebuild what each width sees, in order, and compare with its own rules.
  const blocks = css.split(/\n(?=@media)/).map((b) => ({ q: b.slice(7, b.indexOf('{')), lines: b.split('\n').slice(1, -1) }));
  for (const [width, own] of Object.entries(byWidth)) {
    if (!own) { continue; }
    const q = prebuild.range(Number(width));
    const seen = blocks.filter((b) => b.q.split(', ').includes(q)).flatMap((b) => b.lines);
    const expected = own.split('\n').concat('body{overflow-x:clip}').map((l) => 'html:not(.smao-ran) ' + l);
    assert.deepEqual(seen, expected, 'width ' + width);
  }
});

test('after the scripts run, a rebuilt area only keeps a minimum height', () => {
  const css = prebuild.wrap(1350, '#grid{height:900px!important}\n#grid *{visibility:hidden!important}\n@smao-ran #grid{min-height:900px!important}');
  assert.match(css, /html\.smao-ran #grid\{min-height:900px!important\}/);
  const after = css.split('\n').filter((l) => l.startsWith('html.smao-ran'));
  assert.equal(after.length, 1);
  assert.ok(!/visibility/.test(after[0]), 'nothing but the minimum height survives the scripts');
  const merged = prebuild.combine({ 412: '#grid{height:1px!important}\n@smao-ran #grid{min-height:1px!important}' });
  assert.match(merged, /html\.smao-ran #grid\{min-height:1px!important\}/);
});
