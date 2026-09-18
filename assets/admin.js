(function () {
  'use strict';
  const $ = (selector) => document.querySelector(selector);
  const $$ = (selector) => [...document.querySelectorAll(selector)];
  const t = (s) => wp.i18n.__(s, 'smart-media-auditor-optimizer');
  const model = SMAOModel;
  let latest, working = false, busy = false, polling = false, stopped = false, reportBusy = false, failures = 0, reportSignature = '', recentSignature = '', scanId;
  let page = Number(new URLSearchParams(location.search).get('paged')) || 1;
  const selected = new Set();
  function node(tag, text, cls) { const e = document.createElement(tag); if (text !== undefined) e.textContent = text; if (cls) e.className = cls; return e; }
  function put(selector, text) { const e = $(selector); if (e) e.textContent = text; }
  function show(message, error = false) { const e = $('#smao-notice'); e.className = `notice ${error ? 'notice-error' : 'notice-success'}`; e.textContent = message; }
  async function request(route, payload) {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), payload ? 60000 : 20000);
    try {
      const response = await fetch(model.endpoint(smaoConfig.root, route), { signal: controller.signal, method: payload ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': smaoConfig.nonce }, ...(payload ? { body: JSON.stringify(payload) } : {}) });
      const data = await response.json();
      if (!response.ok) { const e = new Error(data.message || t('Request failed. Please retry.')); e.status = response.status; e.code = data.code; if ([401, 403].includes(response.status)) stopped = true; throw e; }
      return data;
    } catch (e) {
      if (e.name === 'AbortError') throw new Error(t('Request timed out. The server may still be working. Check the latest checkpoint before retrying a file action.'));
      throw e;
    } finally { window.clearTimeout(timeout); }
  }
  function image(row) { const img = node('img'); img.src = row.thumbnail; img.alt = ''; img.loading = 'lazy'; img.width = 48; img.height = 48; img.className = 'smao-thumb'; return img; }
  function badge(value) { return node('span', value === 'unused' ? t('Unused candidate') : value, `smao-badge smao-status-${value}`); }
  function selection() {
    put('#smao-selection-count', `${selected.size} ${t('files selected')}`);
    const boxes = $$('.smao-id'); const all = $('#smao-select-all');
    if (all) { all.checked = boxes.length > 0 && boxes.every(e => e.checked); all.indeterminate = boxes.some(e => e.checked) && !all.checked; }
  }
  function render(data) {
    latest = data;
    const s = data.scan, live = data.live || {}, summary = live.summary || {};
    if (scanId !== undefined && scanId !== s.id) { selected.clear(); reportSignature = ''; if ($('#smao-reviewed')) $('#smao-reviewed').checked = false; }
    scanId = s.id;
    $$('[data-metric]').forEach(e => { const k = e.dataset.metric; e.textContent = ['bytes', 'saved'].includes(k) ? model.bytes(summary[k]) : Number(summary[k] || 0).toLocaleString(); });
    const stalled = model.stalled(s, live.server_time);
    put('#smao-state', stalled ? t('Waiting for worker') : s.state || t('Ready'));
    put('#smao-progress', model.progress(s));
    put('#smao-review-progress', s.state === 'complete' ? `${t('Scan complete')} · ${s.processed || 0} ${t('files checked')} · ${summary.unused || 0} ${t('unused candidates')}` : `${t('Scan status')}: ${s.state} · ${s.processed || 0} ${t('files')} · ${s.records || 0} ${t('source records checked')}`);
    const percent = model.percent(s);
    const bar = $('#smao-progress-bar'); if (bar) { if (percent === null && s.state === 'running') bar.removeAttribute('value'); else bar.value = percent || 0; }
    put('#smao-percent', percent === null ? '—' : `${percent}%`);
    const labels = { inventory: t('Discovering media files'), sources: t('Tracing reference sources'), classify: t('Classifying your media') };
    put('#smao-phase-label', s.state === 'complete' ? t('Scan complete — ready for review') : (labels[s.phase] || t('Ready to scan')));
    put('#smao-work-count', `${s.processed || 0} ${t('files')} · ${s.records || 0} ${t('source records')} · ${s.classified || 0} ${t('classified')}`);
    put('#smao-elapsed', s.started ? `${Math.max(0, Math.floor(((s.finished || live.server_time) - s.started) / 60))} ${t('min since start')}` : '');
    ['inventory', 'sources', 'classify'].forEach((phase, i) => {
      const e = $(`[data-phase="${phase}"]`); if (e) e.classList.toggle('active', s.phase === phase && s.state !== 'complete');
      const counts = [s.processed || 0, s.records || 0, s.classified || 0];
      put(`[data-phase-count="${phase}"]`, Number(counts[i]).toLocaleString());
    });
    put('#smao-current-file', s.current || t('Waiting for the first batch'));
    put('#smao-freshness', s.updated ? new Date(s.updated * 1000).toLocaleTimeString() : '');
    put('#smao-connection', stalled ? t('No recent progress — retry or restart the scan') : t('Status connected'));
    put('#smao-worker-mode', live.runtime?.browser_worker ? t('Automatic processing enabled') : t('WP-Cron processing'));
    renderCleanup(data);
    const warning = $('#smao-scan-warning'); if (warning) { warning.textContent = [stalled ? t('Processing has not advanced for over 30 seconds. Use Retry processing. If the same checkpoint keeps returning, restart the scan.') : '', s.stale ? t('Site changed: rescan before file actions.') : '', s.incomplete ? t('Incomplete coverage: unused conclusions are disabled.') : '', s.message || ''].filter(Boolean).join(' '); warning.hidden = !warning.textContent; }
    const active = ['running', 'paused'].includes(s.state);
    $$('.smao-scan-actions [data-command]').forEach(e => { const c = e.dataset.command; e.hidden = c === 'start' ? active : c === 'pause' ? s.state !== 'running' : c === 'resume' ? s.state !== 'paused' : c === 'cancel' ? !active : c === 'restart' ? !active && !s.stale && !s.incomplete : false; });
    const activity = $('#smao-activity'); if (activity && s.activity?.length) activity.replaceChildren(...s.activity.slice().reverse().map(a => { const li = node('li'); li.append(node('small', `${new Date(a.time * 1000).toLocaleTimeString()} · ${a.event.replaceAll('_', ' ')}`), node('strong', a.label)); return li; }));
    const recent = $('#smao-recent'); const recentKey = JSON.stringify(live.recent); if (recent && recentKey !== recentSignature) { recentSignature = recentKey; recent.replaceChildren(...(live.recent?.length ? live.recent.map(r => { const e = node('article'); e.append(image(r), node('strong', r.filename), node('small', `${model.bytes(r.bytes)} · #${r.attachment_id}`), badge(r.status)); return e; }) : [node('p', t('No files discovered yet.'), 'smao-empty')])); }
    put('#smao-queue-counts', `${data.jobs_paused ? t('Paused') : t('Active')} · ${Object.entries(live.queue_counts || {}).map(([k, v]) => `${v} ${k}`).join(' · ')} (${t('retained jobs')})`);
    const jobs = $('#smao-jobs'); if (jobs) jobs.replaceChildren(...(data.jobs?.length ? data.jobs.slice(0, 8).map(j => node('p', `#${j.attachment_id} · ${j.action} · ${j.state}${j.message ? ' — ' + j.message : ''}`)) : [node('p', t('Select images below to add optimization jobs.'), 'smao-muted')]));
  }
  function renderCleanup(data) {
    const c = data.live?.cleanup; if (!c) return;
    if ($('#smao-scope')) $('#smao-scope').hidden = c.ready;
    put('#smao-cleanup-title', c.ready ? t('Ready to review unused images') : t('Before removing unused images'));
    const list = $('#smao-cleanup-reasons');
    if (list) list.replaceChildren(...(c.blockers.length ? c.blockers.map(reason => node('li', reason)) : [node('li', t('Review the evidence, select files below, then remove them with a recovery copy.'))]));
    const button = $('[data-action="quarantine"]');
    if (button) { button.disabled = busy || !c.ready; button.title = c.blockers.join(' '); }
    if (data.scan.state !== 'complete') $$('[data-metric="unused"]').forEach(e => e.textContent = t('Pending'));
  }
  async function report(force = false) {
    if (!$('#smao-report-body') || reportBusy) return;
    reportBusy = true;
    try {
      const params = new URLSearchParams(new FormData($('.smao-filters'))); params.set('paged', page);
      const data = await request('report?' + params);
      const signature = JSON.stringify(data);
      if (!force && signature === reportSignature) return;
      reportSignature = signature; page = data.page;
      const rows = data.rows.map(r => {
        const tr = node('tr'), check = node('input'); check.type = 'checkbox'; check.className = 'smao-id'; check.value = r.attachment_id; check.checked = selected.has(Number(r.attachment_id)); check.setAttribute('aria-label', `${t('Select')} ${r.filename}`);
        const first = node('td'); first.append(check); tr.append(first);
        const media = node('td'); media.append(image(r), node('strong', r.filename), node('small', `#${r.attachment_id} · ${r.mime}`)); tr.append(media);
        tr.append(node('td', `${model.bytes(r.bytes)}\n${r.width} × ${r.height}`), node('td', `${r.uploaded} UTC`));
        const status = node('td'); status.append(badge(r.status)); tr.append(status, node('td', `${Number(r.optimized) ? t('Optimized') : t('Not optimized')}\n${model.bytes(r.saved)} ${t('saved')}`));
        const evidence = node('td'), details = node('details'), label = node('summary', t('Review evidence')); details.append(label, node('p', r.reason));
        details.addEventListener('toggle', async () => { if (!details.open || details.dataset.loaded) return; details.dataset.loaded = '1'; try { const locations = await request('evidence?id=' + r.attachment_id); details.append(...(locations.length ? locations.map(l => node('p', `${l.source} #${l.source_id} / ${l.field} · ${Number(l.strength) === 2 ? t('strong') : t('possible')} · ${l.kind}`)) : [node('p', t('No indexed references. External use cannot be ruled out.'))])); } catch (e) { details.dataset.loaded = ''; details.append(node('p', e.message)); } });
        evidence.append(details); tr.append(evidence); return tr;
      });
      if (!rows.length) { const tr = node('tr'), td = node('td', t('No matching media. Run a scan or adjust the filters.'), 'smao-empty'); td.colSpan = 7; tr.append(td); rows.push(tr); }
      $('#smao-report-body').replaceChildren(...rows);
      put('#smao-result-count', `${data.total.toLocaleString()} ${t('matching files')}`);
      if ($('#smao-export')) $('#smao-export').href = data.export;
      const pager = $('#smao-pagination'); pager.replaceChildren();
      [[t('Previous'), -1], [t('Next'), 1]].forEach(([label, offset], i) => { if (i) pager.append(node('span', `${data.page} / ${data.pages}`)); const b = node('button', label, 'button'); b.type = 'button'; b.disabled = offset < 0 ? page <= 1 : page >= data.pages; b.onclick = () => { page += offset; report(true).catch(e => show(e.message, true)); }; pager.append(b); });
      selection();
    } finally { reportBusy = false; }
  }
  async function run(task) { if (busy) return; busy = true; const controls = $$('[data-command], [data-action], #smao-runtime button, #smao-settings button'); controls.forEach(b => b.disabled = true); try { await task(); } catch (e) { show(e.message, true); } finally { busy = false; controls.forEach(b => b.disabled = false); if (latest) renderCleanup(latest); } }
  $$('[data-command]').forEach(b => b.addEventListener('click', () => run(async () => { if (b.dataset.command === 'retry') { stopped = false; render(await request('status')); drive(); return; }
      if (b.dataset.command === 'restart' && !window.confirm(t('Restart this scan from the beginning? No media files will be changed.'))) return;
      render(await request('control', { command: b.dataset.command })); report(true).catch(e => show(e.message, true)); })));
  document.addEventListener('change', e => { if (e.target.matches('.smao-id')) { const id = Number(e.target.value); if (e.target.checked) selected.add(id); else selected.delete(id); selection(); } });
  $('#smao-select-all')?.addEventListener('change', e => { $$('.smao-id').forEach(c => { c.checked = e.target.checked; if (c.checked) selected.add(Number(c.value)); else selected.delete(Number(c.value)); }); selection(); });
  $('.smao-filters')?.addEventListener('submit', e => { e.preventDefault(); page = 1; report(true).catch(err => show(err.message, true)); });
  function confirmAction(action, ids) {
    return new Promise(resolve => {
      const dialog = node('dialog', undefined, 'smao-dialog'), form = node('form'); form.method = 'dialog';
      const input = node('input'); input.required = true; input.autocomplete = 'off'; input.setAttribute('aria-label', t('Type the confirmation word'));
      const cancel = node('button', t('Cancel'), 'button'); cancel.type = 'button'; cancel.onclick = () => dialog.close();
      const submit = node('button', t('Confirm action'), 'button button-primary');
      form.append(node('h2', action === 'quarantine' ? t('Remove selected files with a recovery copy') : `${t('Confirm')} ${action}`), node('p', `${ids.length} ${t('selected files')}: ${ids.join(', ')}`), node('p', action === 'purge' ? t('Permanent purge cannot be undone.') : t('This action applies only to the selected files.')), node('label', `${t('Type')} ${action.toUpperCase()}`), input, cancel, submit);
      form.onsubmit = e => { e.preventDefault(); if (input.value !== action.toUpperCase()) { input.setCustomValidity(t('Confirmation does not match.')); input.reportValidity(); return; } resolve(input.value); dialog.close(); }; input.oninput = () => input.setCustomValidity('');
      dialog.append(form); document.body.append(dialog); dialog.onclose = () => { resolve(null); dialog.remove(); }; dialog.showModal(); input.focus();
    });
  }
  $$('[data-action]').forEach(b => b.addEventListener('click', () => run(async () => {
    const ids = model.selected((selected.size ? [...selected] : $$('.smao-id:checked').map(c => c.value))), action = b.dataset.action;
    const reviewed = $('#smao-reviewed')?.checked;
    if (!reviewed || !ids.length || ids.length > 50) throw new Error(t('Select 1–50 files and acknowledge review and backup first.'));
    const typed = await confirmAction(action, ids); if (typed === null) return;
    if (!model.confirmation(action, ids, reviewed, typed)) return;
    const result = await request('action', { action, ids, confirmation: typed });
    show(result.results ? result.results.map(i => `#${i.id}: ${i.message}`).join('\n') : result.message, result.results?.some(i => !i.ok));
    render(await request('status')); await report(true);
  })));
  $('#smao-cleanup')?.addEventListener('click', () => run(async () => { const confirmation = await confirmAction('cleanup', []); if (!confirmation) return; const result = await request('control', { command: 'cleanup_records', confirmation }); show(`${result.removed} ${t('old records removed')}`); }));
  $('#smao-scope')?.addEventListener('submit', e => { e.preventDefault(); run(async () => { render(await request('control', {command:'review_scan', scope_reviewed: true})); show(t('Scope confirmed. Scan results update automatically below.')); }); });
  $('#smao-storage')?.addEventListener('submit', e => { e.preventDefault(); run(async () => { await request('storage', Object.fromEntries(new FormData(e.target))); show(t('Recovery folder validated and saved. Return to Remove unused images.')); }); });
  ['settings', 'runtime'].forEach(route => $(`#smao-${route}`)?.addEventListener('submit', e => { e.preventDefault(); run(async () => { const form = new FormData(e.target), payload = Object.fromEntries(form); if (route === 'settings') { payload.disabled_sizes = form.getAll('disabled_sizes[]'); delete payload['disabled_sizes[]']; } else payload.browser_worker = form.has('browser_worker'); const result = await request(route, payload); if (route === 'runtime') render(result); show(route === 'runtime' ? t('Processing options applied to the next batch.') : t('Settings saved. Run a new scan before quarantine actions.')); }); }));
  async function drive() {
    if (working || busy || stopped || !latest || !model.shouldWork(latest)) return;
    working = true;
    try { render(await request('control', { command: 'tick' })); workerFailures = 0; }
    catch (e) { if (e.code !== 'smao_busy') { show(e.message, true); workerFailures++; } }
    finally { working = false; }
  }
  let workerFailures = 0;
  async function workLoop() {
    await drive();
    window.setTimeout(workLoop, Math.min(15000, workerFailures ? workerFailures * 3000 : 300));
  }
  async function poll() {
    if (!polling && !stopped) {
      polling = true;
      try { if (!working) render(await request('status')); failures = 0; }
      catch (e) { failures++; put('#smao-connection', t('Status unavailable — retrying')); if (stopped) show(t('Session expired. Reload this page to reconnect.'), true); }
      finally { polling = false; }
      if (!document.hidden) report().catch(e => { put('#smao-result-count', t('Report refresh failed — processing continues')); });
    }
    window.setTimeout(poll, Math.min(15000, (Number(latest?.live?.runtime?.poll_interval) || 2) * 1000 * Math.max(1, failures)));
  }
  if ($('#smao-progress') || $('#smao-jobs') || $('#smao-report-body')) { poll(); workLoop(); }
})();
