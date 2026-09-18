# Compatibility and known limitations

## Coverage matrix

| Surface | Detection | Action policy |
|---|---|---|
| Posts/pages/CPTs, Gutenberg, patterns and reusable blocks | Content, excerpts, explicit image IDs, URLs and weak numeric references | Local groups only |
| Featured images, galleries, playlists | Known metadata and shortcode IDs | Strong references prevent quarantine |
| Elementor and other builders | JSON/serialized metadata, URLs and IDs without executing the builder | Ambiguous IDs are possible use |
| ACF/custom fields | All post/term/user meta values; URLs and numeric IDs | Unknown field meanings stay conservative |
| WooCommerce | Products/CPTs, thumbnails, gallery metadata, term images and downloadable-file metadata | Dedicated custom order/plugin tables are outside scope |
| Widgets, Customizer, menus, logos, favicon | Options/theme mods and metadata | Site identity remains protected |
| CSS backgrounds/srcset | URLs in stored database content/options/meta | Filesystem CSS and runtime CSS require manual review |
| PDFs, audio, video, archives/documents | URL/filename and link/download evidence | No compression for non-images |
| CDN/object storage | Filtered URL/path, remote metadata and protection filters | Report-only, no cloud deletion or upload |
| Multisite | Per-blog lazy schema and read-only scans | File mutations disabled to avoid cross-blog breakage |
| External inbound links | Not observable without external data | Never claim confirmed absence |

WordPress 7.1 with PHP 8.0.30 and PHP 8.3 are used for local validation. PHP 8.0 is
the declared minimum; other PHP/WordPress combinations require a deployment
matrix test. GD and ImageMagick capabilities depend on their compiled codecs.
See VALIDATION.md for executed tests, not just intended compatibility.

## Deliberate safety boundaries

* No plugin can prove all external/dynamic use is absent. No “confirmed unused
  everywhere” label is used. Unmatched objects default to possibly used.
* Custom tables, PHP files, filesystem CSS/JS, base64/compressed data, dynamically
  composed URLs, transformed CDN paths and remote sites are not exhaustively
  decoded. Add protected IDs or adapter rules and keep scope acknowledgement off
  until these sources have been reviewed.
* Database scans are not transactional snapshots across an entire site. Change
  hooks and immediate rechecks reduce risk, but an unrelated concurrent process
  can still race a filesystem action. Use a maintenance window.
* Metadata must enumerate group files. Unregistered orphan thumbnails and files
  outside the Media Library are not automatically discovered or deleted.
* Full filename matches and numeric matches deliberately produce false “possible”
  results; scans include old drafts, revisions, comments and caches. This loses
  recoverable-space opportunities in favor of avoiding deletion of valid files.
* The synchronous pre-action recheck uses bounded-result `LIKE` scans over the
  supported tables. It may be slow on large databases and can reject harmless
  numeric/filename matches. It never overrides uncertainty to delete faster.
* Inventory and sources use keyset pagination. Report pages use a capped 25-row
  page with SQL OFFSET; very deep report pages may be slower. CSV is streamed by
  ID. Starting a new scan clears only the two plugin-owned reference tables.
* Source values over 256 KiB make the scan incomplete. Metadata groups over 250
  files require manual handling. Images are limited to 40 megapixels/64 MiB, and
  workers may require additional server execution time for large groups.
* Quarantine is not a transparent URL redirect: files become unavailable until
  restored. Private vault bytes remain until explicit purge after retention.
* Purge removes recovery bytes, not attachment database rows. WordPress attachment
  deletion is blocked while a recovery record exists and this plugin is active.
* Lossless JPEG optimization is unavailable. It is never simulated with quality
  100. Exact decoded-pixel verification can reject nominally lossless WebP/AVIF
  conversions when the encoder changes representation.
* Resizing is opt-in and affects only the main image. Existing thumbnails are
  preserved to avoid breaking hardcoded URLs; missing sizes can be regenerated.
* EXIF stripping is opt-in. ICC profiles are retained with ImageMagick. GD rejects
  identified profile/animation/orientation dependencies and requires stripping
  consent. Unsupported color/profile situations need a specialist workflow.
* Browser fallback delivery applies to `wp_get_attachment_image` output. It does
  not rewrite content HTML, caching layers, third-party picture tags or CDN URLs.
* No predicted compression savings are presented as measured savings. The
  dashboard's recoverable number is the total size of reviewed unused candidates,
  not a promise of net free space after backups or derivatives.
* No automatic cleanup of core/third-party records occurs. Retention never
  schedules permanent deletion. Plugin reporting data is cleaned on rescan,
  explicit bounded cleanup of old logs/finished jobs, or a safe uninstall;
  recovery records always take precedence.

## Integration filters

`smao_protect_remote_attachment( bool $protect, int $attachment_id )` can mark
an additional storage provider as report-only. It cannot override baseline
remote checks. `smao_protected_reason( string $reason, int $attachment_id,
array $group )` can return a nonempty reason for custom protected assets.

These filters execute trusted installed PHP code, not user-provided expressions.
No adapter grants cloud filesystem mutation support. Custom scanners should
produce conservative evidence using `Scanner::match()` during a controlled
scan extension; do not modify stored scan completion/epoch flags to bypass safety.
