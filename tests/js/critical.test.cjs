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
