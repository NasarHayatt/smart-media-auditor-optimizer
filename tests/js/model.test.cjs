const test = require('node:test');
const assert = require('node:assert/strict');
const model = require('../../assets/model.js');

test('selection rejects invalid IDs and de-duplicates', () => {
  assert.deepEqual(model.selected(['4', '4', '-1', '0', 'bad', '2.5', '7', '9007199254740993']), [4, 7]);
});
test('destructive action requires selection, acknowledgement and exact confirmation', () => {
  assert.equal(model.confirmation('purge', [2], true, 'PURGE'), true);
  assert.equal(model.confirmation('purge', [2], false, 'PURGE'), false);
  assert.equal(model.confirmation('purge', [], true, 'PURGE'), false);
  assert.equal(model.confirmation('purge', [2], true, 'purge'), false);
  assert.equal(model.confirmation('unknown', [2], true, 'UNKNOWN'), false);
  assert.equal(model.confirmation('purge', Array(51).fill(1), true, 'PURGE'), false);
});
test('progress always exposes stale and incomplete coverage', () => {
  const message = model.progress({ state: 'complete', processed: 400, records: 2000, stale: true, incomplete: true });
  assert.match(message, /STALE/);
  assert.match(message, /INCOMPLETE/);
  assert.match(message, /400/);
});
test('progress uses real work counts and only finishes at scan completion', () => {
  assert.equal(model.percent({state:'running',total:10,source_totals:[20],processed:10,records:10,classified:0}),50);
  assert.equal(model.percent({state:'running',total:10,source_totals:[20],processed:20,records:30,classified:20}),99);
  assert.equal(model.percent({state:'complete'}),100);
  assert.equal(model.percent({state:'running',processed:200}),null);
});
test('browser worker respects explicit runtime preference and paused queues', () => {
  const data={scan:{state:'paused'},jobs_paused:true,live:{runtime:{browser_worker:true},queue_counts:{queued:2}}};
  assert.equal(model.shouldWork(data),false);
  data.jobs_paused=false;assert.equal(model.shouldWork(data),true);
  data.live.runtime.browser_worker=false;assert.equal(model.shouldWork(data),false);
  data.scan.state='running';assert.equal(model.shouldWork(data),false);
  data.live.runtime.browser_worker=true;assert.equal(model.shouldWork(data),true);
});
test('storage formatting handles zero and megabytes', () => {
  assert.equal(model.bytes(0),'0 B');assert.equal(model.bytes(1048576),'1.0 MB');
});
test('live report URLs support both default and pretty WordPress permalinks', () => {
 assert.equal(model.endpoint('http://site/index.php?rest_route=/smao/v1/','report?mime=image%2F'),'http://site/index.php?rest_route=/smao/v1/report&mime=image%2F');
 assert.equal(model.endpoint('http://site/wp-json/smao/v1/','report?paged=2'),'http://site/wp-json/smao/v1/report?paged=2');
 assert.equal(model.endpoint('http://site/index.php?rest_route=/smao/v1/','status'),'http://site/index.php?rest_route=/smao/v1/status');
});
test('stalled scans are distinct from connected polling and paused scans', () => {
 assert.equal(model.stalled({state:'running',updated:100},131),true);
 assert.equal(model.stalled({state:'running',updated:100},120),false);
 assert.equal(model.stalled({state:'paused',updated:100},200),false);
});
