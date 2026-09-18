# Security and performance release checklists

## Security gate

- Run pure PHPUnit, JavaScript and disposable WordPress integration suites.
- Run PHP lint and the configured WordPress coding-standards audit.
- Verify administrator, editor, author, subscriber, anonymous and revoked-user
  access to every REST route, CSV export, scheduled job and admin page.
- Confirm every mutation requires a REST nonce, appropriate capabilities and
  explicit action confirmation; GET requests must not mutate state.
- Test expired nonces, missing confirmations, invalid IDs, ID arrays over 50,
  unknown sort/action names, SQL metacharacters and formula-like CSV fields.
- Test exclusion rules, recent media, logos/favicon, parent/attachment pages,
  shared paths, aliases, symlinks, traversal, stream wrappers and missing files.
- Ensure offloading/CDN adapters and multisite cannot authorize local mutations.
- Verify private backup storage is inaccessible through **every** virtual host,
  alias and web server. Check permissions, available space and backup strategy.
- Interrupt copying, compression, deletion and restore at each journal state.
  Restart workers; ensure no live mutation is blindly retried.
- Alter an original after backup and corrupt/remove a backup: restore must stop
  without overwriting a newer file. Verify restored SHA-256 and metadata.
- Ensure quarantine cannot be purged before retention, without confirmation,
  or after a new local reference appears. Test interrupted manual purge retry.
- Verify no telemetry/network calls, server rewrites or unrequested database
  cleanup. Keep detailed source values and secrets out of reports/logs.
- Test deactivation, reactivation and uninstall with/without active journals.
- Review audit logs, job messages and escaping under malicious attachment names.

## Performance gate

- Benchmark 1k, 10k and 100k attachments, including 10–30 thumbnails per image,
  on the actual host. Record PHP peak memory, wall time, queries and table sizes.
- Include large Elementor/ACF rows, many options, Unicode paths, responsive
  sources, duplicate filenames and mostly unmatched data.
- Confirm source/inventory queries use their primary-key cursors and token
  lookups use the composite hash index. Inspect EXPLAIN plans.
- Measure batch sizes 10, 50 and 200. Keep normal scan ticks below the host's
  timeout and memory ceilings. Oversized rows must disable unused conclusions.
- Run concurrent worker/REST requests; only one per-site operation may hold the
  advisory lock. Kill a worker and verify the database releases its lock.
- Check pause/resume/cancel, cron-disabled hosts, low traffic and delayed cron.
- Export large result sets while monitoring memory and connection timeouts.
- Measure immediate pre-quarantine LIKE rechecks on large tables; plan file
  actions during maintenance windows if they are slow.
- Compare original/optimized quality and sizes across JPEG/PNG/WebP/AVIF, EXIF
  orientations, ICC profiles, alpha, animation, CMYK and malformed input.
- Compare actual disk use including recovery and alternate-format bytes; do not
  confuse live transfer savings with net storage recovery.
- Inspect desktop/mobile/high-DPI image loading and Core Web Vitals. Confirm the
  primary image is not forced lazy, dimensions/srcset remain valid, fallback URLs
  work, and alternate delivery/preloading do not duplicate requests.
- Test active theme plus Elementor/WooCommerce/ACF versions on staging. A generic
  metadata fixture is not certification of every vendor release.
- Have a second human review a sample of unused candidates before production
  quarantine. Restore the sample and verify the public pages again.
