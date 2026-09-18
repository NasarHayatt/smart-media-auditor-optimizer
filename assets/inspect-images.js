(function () {
  'use strict';
  const trigger = document.querySelector('#wp-admin-bar-smao-inspect a');
  if (!trigger) return;
  const { __ } = wp.i18n;
  const domain = 'smart-media-auditor-optimizer';
  trigger.addEventListener('click', (event) => {
    event.preventDefault();
    document.querySelector('#smao-image-report')?.remove();
    const dialog = document.createElement('dialog');
    dialog.id = 'smao-image-report';
    dialog.style.cssText = 'max-width:90vw;max-height:80vh;overflow:auto;padding:24px;background:white;color:#17212b;border:2px solid #17212b;font:16px/1.5 system-ui;';
    const title = document.createElement('h2');
    title.id = 'smao-report-title';
    title.textContent = __('Images larger than their displayed dimensions', domain);
    dialog.setAttribute('aria-labelledby', title.id);
    const explanation = document.createElement('p');
    explanation.textContent = __('Local browser snapshot: intrinsic dimensions exceed the rendered box by more than 1.5× in either direction. Inspect srcset, device pixel ratio and visual quality before changing a file. Hidden/unloaded images, CSS backgrounds and frames are excluded. Nothing is transmitted or saved.', domain);
    const close = document.createElement('button');
    close.textContent = __('Close report', domain);
    close.addEventListener('click', () => { dialog.close(); dialog.remove(); trigger.focus(); });
    const table = document.createElement('table');
    const header = document.createElement('tr');
    [__('Image URL', domain), __('Intrinsic dimensions', domain), __('Displayed CSS dimensions', domain)].forEach((label) => {
      const cell = document.createElement('th'); cell.textContent = label; cell.scope = 'col'; header.append(cell);
    });
    table.append(header);
    let count = 0;
    document.querySelectorAll('img').forEach((image) => {
      const rect = image.getBoundingClientRect();
      if (!image.complete || !image.naturalWidth || rect.width < 1 || rect.height < 1 || (image.naturalWidth <= rect.width * 1.5 && image.naturalHeight <= rect.height * 1.5)) return;
      const row = document.createElement('tr');
      [image.currentSrc || image.src, `${image.naturalWidth} × ${image.naturalHeight}`, `${Math.round(rect.width)} × ${Math.round(rect.height)}`].forEach((value) => {
        const cell = document.createElement('td'); cell.textContent = value; cell.style.cssText = 'padding:8px;border-bottom:1px solid #aaa;overflow-wrap:anywhere;max-width:55vw;'; row.append(cell);
      });
      table.append(row); count += 1;
    });
    dialog.append(title, explanation, close, table);
    if (!count) { const empty = document.createElement('p'); empty.textContent = __('No oversized loaded images found at this viewport.', domain); dialog.append(empty); }
    document.body.append(dialog); dialog.showModal(); close.focus();
  });
})();
