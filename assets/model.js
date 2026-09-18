(function (root) {
  'use strict';
  const api = {
    selected(values) {
      return [...new Set(values.map(Number).filter((id) => Number.isSafeInteger(id) && id > 0))];
    },
    confirmation(action, ids, reviewed, typed) {
      const allowed = ['quarantine', 'restore', 'purge', 'optimize', 'thumbnails'];
      return allowed.includes(action) && reviewed && ids.length > 0 && ids.length <= 50 && typed === action.toUpperCase();
    },
    stalled(scan, now) {
      return scan.state === 'running' && Number(now) - Number(scan.updated || scan.started || now) > 30;
    },
    endpoint(root, route) {
      const [path, query] = route.split('?');
      return root + path + (query ? (root.includes('?') ? '&' : '?') + query : '');
    },
    bytes(value) {
      const n = Math.max(0, Number(value) || 0);
      const unit = Math.min(3, Math.floor(Math.log(n || 1) / Math.log(1024)));
      return `${(n / 1024 ** unit).toFixed(unit ? 1 : 0)} ${['B', 'KB', 'MB', 'GB'][unit]}`;
    },
    percent(scan) {
      if (scan.state === 'complete') return 100;
      if (!Number.isFinite(scan.total) || !Array.isArray(scan.source_totals)) return null;
      const total = scan.total * 2 + scan.source_totals.reduce((a, b) => a + Number(b), 0);
      return Math.min(99, Math.max(0, Math.floor(100 * ((scan.processed || 0) + (scan.records || 0) + (scan.classified || 0)) / (total || 1))));
    },
    shouldWork(data) {
      return !!data.live?.runtime?.browser_worker && (data.scan.state === 'running' || (!data.jobs_paused && Number(data.live?.queue_counts?.queued) > 0));
    },
    progress(scan) {
      return `${scan.state || 'idle'} · ${scan.phase || 'no scan'} · attachments: ${scan.processed || 0} · source records: ${scan.records || 0}${scan.stale ? ' · STALE: site changed; rescan before actions' : ''}${scan.incomplete ? ' · INCOMPLETE COVERAGE: no unused conclusions' : ''}${scan.message ? ` · ${scan.message}` : ''}`;
    }
  };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.SMAOModel = api;
})(typeof window !== 'undefined' ? window : globalThis);
