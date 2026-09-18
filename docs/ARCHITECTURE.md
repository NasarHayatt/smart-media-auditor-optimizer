# Architecture and detection contract

Smart Media Auditor & Optimizer (SMAO) requires PHP 8.0 and WordPress 6.5+.
Production deployment requires staging validation against the site's WordPress,
plugins, storage and server; source generation is not a compatibility certification.

## Detection risks, before implementation

False unused results can arise from PHP-generated URLs, JavaScript concatenation,
encoded/compressed values, private plugin tables, remote consumers, cross-site
references, unattached galleries and external storage. False used results can
arise from bare numbers, filename collisions, revisions, drafts, old settings or
cached markup. External inbound links cannot be discovered without external data.

The scanner indexes each attachment's full upload-relative paths and basenames,
including originals, scaled images, backups and metadata-listed thumbnails.
Core database rows are streamed by primary key. Explicit attachment markup and
known relationship fields are strong evidence; bare IDs/basenames are weak
evidence. Weak evidence always prevents an unused classification. No external
requests, PHP unserialization or execution of shortcodes occurs.

A completed scan is an observation of local database references, not proof of
absence everywhere. Unmatched files remain `possible` unless an administrator
explicitly attests that custom code/tables/external consumers have been reviewed.
Even then `unused` means **unused candidate within the reviewed scope**. The UI
does not claim universal confirmation. Truncated rows, a changing database,
errors, parent relationships, public attachment pages, protected files and recent
uploads prevent unused classification. Quarantine requires a current clean scan,
the explicit review acknowledgement, private storage, and unshared local paths.

## Components and phases

1. Bootstrap, schema and settings, then read-only inventory and dashboard.
2. Core source scanning and evidence, followed by builder/custom-field adapters.
3. Private journaled quarantine and recovery; no scheduled permanent deletion.
4. Backup-first same-format image compression and optional alternate derivatives.
5. Keyset batches, a database lock, WP-Cron worker and REST progress controls.
6. Unit/security tests, staging integration tests and release checklists.

`Database` owns per-site tables, checked writes and advisory locks. `Scanner`
owns inventory → sources → classification phases and persistent checkpoints.
`Matcher` is a pure token/evidence engine. `Media` defines physical groups and
safety policy. `Vault` provides backup-first journals outside the web root.
`Optimizer` handles image groups. `Plugin` wires lifecycle, jobs and safe image
delivery. `Admin` provides nine screens, REST controls and streaming CSV.

## Database schema (per blog prefix)

* `smao_media`: attachment_id primary key; scan_id; status; mime; filename;
  uploaded; bytes; width; height; optimized; saved; reason; JSON details.
  Indexes: (scan_id,status), (mime), (uploaded), (bytes).
* `smao_tokens`: SHA-256 token_hash, attachment_id, kind; composite primary key.
  Kinds `path`, `name`, `id`. Index attachment_id. Exact indexed lookups.
* `smao_evidence`: attachment_id, source, source_id, field, strength, kind,
  fingerprint; unique attachment/fingerprint; index attachment/strength.
  At most 100 locations per attachment are retained; this never removes the
  strongest evidence. Source text is not copied into the database or logs.
* `smao_vault`: attachment_id primary key, operation, state, created (Unix UTC),
  manifest (JSON of relative paths, hashes, metadata, backup names).
* `smao_log`: auto-increment id, created UTC, user_id, attachment_id, action,
  message. No source values or credentials are logged.
* `smao_jobs`: auto-increment id, attachment_id, action, state, created, message;
  index (state,id). Queued jobs are re-authorized against their requesting user.

Options hold settings, scan state, schema version and a change epoch. Tables are
created lazily per site, so network activation does not enumerate the network.
Multisite scans are isolated; automatic unused conclusions are disabled because
other blogs may share media. Custom network storage needs a supported adapter.

## Runtime and recovery

One MySQL advisory lock per blog serializes plugin scans and mutations. Locks
are connection-scoped; there is no expiring lease that can overlap a slow job.
Sources are queried in primary-key order, capped at 256 KiB per record, and
inventory/sources/classification use configurable bounded batches and a time
budget. Oversized records mark the scan incomplete. A failed worker pauses with
its checkpoint intact. Scheduled polling resumes via WP-Cron; REST polling is
read-only. A stalled cron can be advanced with an explicit administrator tick.

File mutations use a durable manifest before touching originals. Every original
has a verified SHA-256 backup. Interrupted transactions are visible in Quarantine
and Restore and recoverable; originals are never silently overwritten on restore
when their content has changed. A configured private vault is mandatory and must
be outside ABSPATH and DOCUMENT_ROOT, with no symlink components. No web-server
configuration is edited. Retention is a minimum wait for manual purge, never a
timer that automatically deletes files.

Filesystem safety cannot eliminate concurrent writes by unrelated software.
Use a maintenance window for file actions and take independent database/uploads
backups. PHP process termination can leave a journal pending; recovery is
explicit. The plugin fails closed on missing backups, shared paths, unsupported
storage, stale scans and permission failures.
