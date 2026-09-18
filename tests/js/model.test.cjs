const test = require('node:test');
const assert = require('node:assert/strict');
const model = require('../../assets/model.js');

test('selection rejects invalid IDs and de-duplicates', () => {
  assert.deepEqual(model.selected(['4', '4', '-1', '0', 'bad', '2.5', '7', '9007199254740993']), [4, 7]);
});

test('destructive actions are exactly the irreversible ones', () => {
  assert.equal(model.destructive('quarantine'), true);
  assert.equal(model.destructive('purge'), true);
  assert.equal(model.destructive('optimize'), false);
  assert.equal(model.destructive('thumbnails'), false);
  assert.equal(model.destructive('restore'), false);
});

test('destructive action requires selection, acknowledgement and exact confirmation', () => {
  assert.equal(model.confirmation('purge', [2], true, 'PURGE'), true);
  assert.equal(model.confirmation('purge', [2], false, 'PURGE'), false);
  assert.equal(model.confirmation('purge', [], true, 'PURGE'), false);
  assert.equal(model.confirmation('purge', [2], true, 'purge'), false);
  assert.equal(model.confirmation('unknown', [2], true, 'UNKNOWN'), false);
  assert.equal(model.confirmation('purge', Array(51).fill(1), true, 'PURGE'), false);
});

test('only irreversible actions ask the user to type a word', () => {
  // Permanent deletion cannot be undone, so it keeps the typed confirmation.
  assert.equal(model.requiresTyping('purge'), true);
  // Everything else is recoverable and must not carry deletion-grade friction.
  assert.equal(model.requiresTyping('quarantine'), false);
  assert.equal(model.requiresTyping('restore'), false);
  assert.equal(model.requiresTyping('optimize'), false);
  assert.equal(model.requiresTyping('thumbnails'), false);
});

test('recoverable actions pass without a typed word or acknowledgement', () => {
  assert.equal(model.confirmation('quarantine', [2], false, true), true);
  assert.equal(model.confirmation('optimize', [2], false, true), true);
  assert.equal(model.confirmation('thumbnails', [2], false, true), true);
  assert.equal(model.confirmation('restore', [2], false, true), true);
  // Bounds still apply to every action.
  assert.equal(model.confirmation('quarantine', [], false, true), false);
  assert.equal(model.confirmation('quarantine', Array(51).fill(1), false, true), false);
});

test('percent uses real work counts and only finishes at scan completion', () => {
  assert.equal(model.percent({ state: 'running', total: 10, source_totals: [20], processed: 10, records: 10, classified: 0 }), 50);
  assert.equal(model.percent({ state: 'running', total: 10, source_totals: [20], processed: 20, records: 30, classified: 20 }), 99);
  assert.equal(model.percent({ state: 'complete' }), 100);
  assert.equal(model.percent({ state: 'running', processed: 200 }), null);
});

test('browser worker respects explicit runtime preference and paused queues', () => {
  const data = { scan: { state: 'paused' }, jobs_paused: true, live: { runtime: { browser_worker: true }, queue_counts: { queued: 2 } } };
  assert.equal(model.shouldWork(data), false);
  data.jobs_paused = false; assert.equal(model.shouldWork(data), true);
  data.live.runtime.browser_worker = false; assert.equal(model.shouldWork(data), false);
  data.scan.state = 'running'; assert.equal(model.shouldWork(data), false);
  data.live.runtime.browser_worker = true; assert.equal(model.shouldWork(data), true);
});

test('polling backs off when nothing is happening', () => {
  const idle = { scan: { state: 'complete' }, live: { runtime: { poll_interval: 2 }, queue_counts: { queued: 0 } } };
  const busy = { scan: { state: 'running' }, live: { runtime: { poll_interval: 2 }, queue_counts: { queued: 0 } } };

  // An idle, open tab must not poll every two seconds forever.
  assert.equal(model.pollDelay(idle, 0, false), 30000);
  assert.equal(model.pollDelay(idle, 0, true), 120000);

  // An active scan stays responsive.
  assert.equal(model.pollDelay(busy, 0, false), 2000);
  assert.equal(model.pollDelay(busy, 0, true), 15000);

  // Failures back off, but never past the ceiling.
  assert.equal(model.pollDelay(busy, 2, false), 6000);
  assert.equal(model.pollDelay(busy, 50, false), 15000);

  // A queued job counts as busy even with no scan running.
  const queued = { scan: { state: 'complete' }, live: { runtime: { poll_interval: 2 }, queue_counts: { queued: 3 } } };
  assert.equal(model.pollDelay(queued, 0, false), 2000);
});

test('scan controls are only offered when they can do something', () => {
  const fresh = model.visibleCommands({ state: 'idle' });
  assert.equal(fresh.start, true);
  assert.equal(fresh.retry, false, 'retry is meaningless before a scan exists');
  assert.equal(fresh.restart, false);
  assert.equal(fresh.cancel, false);

  const running = model.visibleCommands({ state: 'running' });
  assert.equal(running.start, false);
  assert.equal(running.pause, true);
  assert.equal(running.cancel, true);
  assert.equal(running.resume, false);

  const paused = model.visibleCommands({ state: 'paused' });
  assert.equal(paused.resume, true);
  assert.equal(paused.pause, false);

  const done = model.visibleCommands({ state: 'complete' });
  assert.equal(done.restart, true);
  assert.equal(done.cancel, false);

  const stalled = model.visibleCommands({ state: 'running', stalled: true });
  assert.equal(stalled.retry, true);
});

test('storage formatting handles zero and megabytes', () => {
  assert.equal(model.bytes(0), '0 B');
  assert.equal(model.bytes(1048576), '1.0 MB');
});

test('REST URLs support both default and pretty WordPress permalinks', () => {
  assert.equal(
    model.endpoint('http://site/index.php?rest_route=/smao/v1/', 'fragment?mime=image%2F'),
    'http://site/index.php?rest_route=/smao/v1/fragment&mime=image%2F'
  );
  assert.equal(
    model.endpoint('http://site/wp-json/smao/v1/', 'fragment?paged=2'),
    'http://site/wp-json/smao/v1/fragment?paged=2'
  );
  assert.equal(
    model.endpoint('http://site/index.php?rest_route=/smao/v1/', 'status'),
    'http://site/index.php?rest_route=/smao/v1/status'
  );
});

test('stalled scans are distinct from connected polling and paused scans', () => {
  assert.equal(model.stalled({ state: 'running', updated: 100 }, 131), true);
  assert.equal(model.stalled({ state: 'running', updated: 100 }, 120), false);
  assert.equal(model.stalled({ state: 'paused', updated: 100 }, 200), false);
});
