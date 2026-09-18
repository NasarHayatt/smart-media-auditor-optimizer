# User guide

## Dashboard

Counts and storage figures describe the cached attachment inventory. Run a fresh
scan after making changes. Used, download, possible, external, broken and
quarantined are separate categories. “Unused candidates” are not conclusively
unused on every external website. Storage is the sum of locally accessible group
files; remote bytes are unknown, and files shared between attachment records may
be counted twice. Backup and derivative disk costs are additional.

## Media Usage Scanner

**Start dry-run scan** creates a new inventory, indexes local references and then
classifies the inventory. It does not move, recompress or delete files. All post
statuses are scanned, including drafts/revisions/trash, because restoring them
may need their media. Pause preserves checkpoints. Resume continues. Cancel
retains partial reports but disallows quarantine until a new complete scan.

Reference details show source table family, object ID, field name, match strength
and reference/download type. The source ID is the post, user, term or comment ID;
options use their database option ID and name. A maximum of 50 strong and 50 weak
locations is retained per attachment. Exact text and credentials are not copied
into reports. ACF/Elementor fields can be inspected in their own editing screens.

Filter by status, optimization, MIME prefix, date or bytes; search filenames or
attachment IDs; sort and paginate. CSV exports the matching rows in ID order
using bounded reads, including retained evidence locations. Formula-like cells
are prefixed with an apostrophe to avoid spreadsheet formula execution.

If a source value exceeds 256 KiB, or parsing fails, the scan does not authorize
unused conclusions. Run a quieter scan when the site changes during processing.
Content written directly to SQL by third-party software may bypass change hooks;
quarantine also performs a synchronous reference recheck.

## Unused Media Review

1. Review custom theme/plugin code, custom tables, external links and consumers.
2. Configure exclusions. Never assume “unattached” means “unused.”
3. Enable the reviewed-scope acknowledgement in Settings, then rescan.
4. Inspect the reason and evidence of each candidate and check recent uploads.
5. Back up the database/uploads independently and select reviewed IDs.
6. Acknowledge manual review and type `QUARANTINE` in the confirmation dialog.

A complete unchanged scan less than 24 hours old is required. The plugin refuses
protected, remotely stored, shared, symlinked or missing groups. Public attachment
pages or parent relationships prevent an unused classification. With WordPress
attachment pages enabled, many attachments will correctly stay “possibly used.”

Quarantine makes the original URLs unavailable. Use a maintenance window and
verify the public site immediately afterward. Recovery is designed for mistakes,
but cannot make any unused-media detector infallible.

## Image Optimizer

Choose settings first, select image IDs, acknowledge backup/review, and type
`OPTIMIZE`. The queue processes one attachment group at a time. Requesting users
must still have administrator/media permissions when a job runs. Automatic
optimization applies only to new uploads by administrators; anonymous/customer
uploads and ordinary author uploads are not silently authorized.

An operation backs up every metadata-listed group file before changing images.
The main file and sizes keep their original names and MIME formats. Only a
smaller valid encoded output replaces an existing file. Resizing affects the
main image only, updates dimensions and retains existing thumbnails. Originals
are recoverable, including image metadata. Quality settings cannot guarantee
that lossy changes are invisible: inspect faces, gradients, text and transparency.

Lossless mode checks decoded-pixel signatures with ImageMagick. Lossless JPEG
recompression, lossless resizing and codecs that fail pixel verification are
rejected. Unsupported encoders fail with a visible job message, and the operation
rolls back. Animation, multi-frame input, CMYK, excessive file/pixel sizes and
unsafe metadata/orientation handling are rejected rather than silently changed.

Optional WebP/AVIF outputs are kept only when smaller. Delivery uses a `picture`
source plus the unchanged WordPress `img` fallback. Images inserted as static
HTML, CSS backgrounds or bespoke theme markup keep their original format.
GD cannot inspect WebP/AVIF input animations safely, so those inputs require
ImageMagick. If a failed process leaves a journal, restore it before retrying.

**Generate missing thumbnails** delegates to WordPress's missing-size API. It
does not replace existing thumbnails or remove files. Disabled sizes affect
future generation only. Restore any optimization/quarantine record first.

Live bytes saved = original group bytes minus optimized same-format group bytes.
This is not net disk recovery: private originals and alternate files use space.
Before/after values are recorded in the Activity Log. Before encoding, no
quality-dependent savings estimate is invented.

## Performance Recommendations

The report flags source images above configured dimensions. On the public site,
logged-in administrators can choose **Inspect image sizing** in the toolbar to
compare loaded images' intrinsic sizes with their current rendered boxes. The
local dialog transmits nothing. Recheck representative mobile/desktop viewports
and high-DPI devices; lazy images that have not loaded and CSS backgrounds are
not included.

WordPress continues to handle native lazy loading, width/height attributes,
`srcset` and `sizes`. The plugin does not rewrite arbitrary HTML or force lazy
loading on the primary image. Optional front-page primary-image preloading is
suppressed when alternate delivery is enabled to avoid downloading two formats.
No caching, JavaScript optimization, CSS optimization or server configuration is
changed.

## Quarantine and Restore

All pending/prepared/interrupted/optimized/quarantined recovery records appear
here. Select a record, acknowledge review and type `RESTORE`. Backup hashes are
verified before restoration. A live original path containing newer bytes blocks
the entire restore rather than overwriting someone else's work. Keep both files
and involve your site administrator to resolve that collision.

After the minimum retention period, you may explicitly type `PURGE` to delete
quarantined backups. This is irreversible. Recheck references first; the plugin
also checks current local references. It retains the attachment database record
as an audit tombstone; it does not execute third-party attachment-deletion hooks.
Nothing purges automatically. Restore optimization backups before deactivation
if you want all original bytes and metadata back.

## Activity Log, Settings and System Status

Activity Log identifies the user, attachment, UTC timestamp, action and result.
Settings describes each opt-in behavior. System Status checks available encoders,
memory, multisite mode, WP-Cron and private vault availability. Job failures are
reported on the optimizer screen; fix their stated cause before retrying.

Settings also offers explicit cleanup of plugin logs and finished jobs older
than 90 days. Each confirmed click removes at most 500 of each; it never removes
queued jobs, recovery manifests, uploads or core/third-party metadata.

Use server cron to invoke WordPress scheduled events if traffic is too low.
`wp cron event run smao_worker` can advance the queue when WP-CLI is installed.
The plugin does not require Action Scheduler. Pause/cancel controls never kill
a running filesystem operation halfway through; they take effect after it
releases the per-site lock.
