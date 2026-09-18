const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const model = require('../../assets/model.js');

/**
 * Build a minimal DOM/browser stub that admin.js can run inside.
 */
function harness({ hidden, fragmentFails }) {
  const calls = [];
  const timers = [];

  const element = {
    addEventListener() {},
    removeAttribute() {},
    setAttribute() {},
    insertAdjacentElement() {},
    focus() {},
    closest() { return null; },
    classList: { toggle() {} },
    dataset: {},
    textContent: '',
    className: '',
    hidden: false,
    innerHTML: '',
    replaceChildren() {}
  };

  const context = {
    SMAOModel: model,
    wp: { i18n: { __: (s) => s } },
    smaoConfig: { root: 'http://test/wp-json/smao/v1/', nonce: 'test' },
    URLSearchParams,
    AbortController,
    location: { search: '' },
    document: {
      hidden,
      createElement: () => Object.assign({}, element),
      querySelector: () => element,
      querySelectorAll: () => [],
      addEventListener() {}
    },
    window: {
      setTimeout(fn, ms) { const timer = { fn, ms, cancelled: false }; timers.push(timer); return timer; },
      clearTimeout(timer) { if (timer) { timer.cancelled = true; } }
    },
    fetch: async (url, options) => {
      calls.push({ url, options });
      if (fragmentFails && (url.includes('/fragment') || url.includes('/digest'))) {
        return { ok: false, status: 500, json: async () => ({ message: 'Report unavailable' }) };
      }
      if (url.includes('/digest')) {
        return { ok: true, json: async () => ({ signature: 'abc', total: 1 }) };
      }
      if (url.includes('/fragment')) {
        return { ok: true, json: async () => ({ html: '<tbody></tbody>', digest: { signature: 'abc', total: 1 } }) };
      }
      return {
        ok: true,
        json: async () => ({
          scan: { state: 'running', total: 1, source_totals: [1] },
          jobs: [],
          jobs_paused: false,
          live: { runtime: { browser_worker: true, poll_interval: 2 }, queue_counts: { queued: 1 }, summary: {} }
        })
      };
    }
  };

  vm.runInNewContext(fs.readFileSync(require.resolve('../../assets/admin.js'), 'utf8'), context);
  return { calls, timers };
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

for (const hidden of [false, true]) {
  test(`worker keeps advancing when the report endpoint is broken (hidden=${hidden})`, async () => {
    const { calls, timers } = harness({ hidden, fragmentFails: true });

    // Let the first status poll land so the worker knows there is work.
    await flush();
    await flush();

    // Both the poll loop and the work loop schedule themselves; fire whatever
    // is pending rather than guessing which timer is which.
    const pending = timers.filter((timer) => !timer.cancelled);
    assert.ok(pending.length > 0, 'a loop timer should be scheduled');
    for (const timer of pending) {
      timer.cancelled = true;
      await timer.fn();
      await flush();
    }
    await flush();

    assert.ok(
      calls.some((call) => call.url.endsWith('/control') && JSON.parse(call.options.body).command === 'tick'),
      'a failing report must never stop the worker from ticking'
    );
  });
}

test('an idle tab is not polled every couple of seconds', async () => {
  const { timers } = harness({ hidden: false, fragmentFails: false });
  await flush();
  await flush();

  // Whatever else is scheduled, nothing should re-poll faster than the
  // active-scan interval, and the idle path must back right off.
  const delays = timers.filter((timer) => !timer.cancelled).map((timer) => timer.ms);
  assert.ok(delays.length > 0, 'timers should be scheduled');
  assert.ok(
    delays.every((ms) => ms >= 400),
    `no timer should fire faster than 400ms, saw ${JSON.stringify(delays)}`
  );
});
