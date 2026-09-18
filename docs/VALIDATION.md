# Validation report

Version 1.2.0 validation environment: Windows, PHP 8.0.30, WordPress 7.1,
MariaDB 10.4.32 and GD in an isolated workspace installation. The existing
user website was not modified. WordPress outbound HTTP was blocked.

## Executed checks

Results: **50 PHP tests / 211 assertions** (24 unit tests / 33 assertions,
26 WordPress integration tests / 178 assertions), plus **10 JavaScript tests**.
The configured WordPress coding-standards audit passes.

New coverage checks scan telemetry, runtime bounds, unchanged coverage epoch,
filtered reports, hostile search text, pagination clamping, pause/resume,
unoptimized image reporting, progress completion, browser-worker preferences,
default and pretty permalink REST URLs, worker independence from report failures,
background-tab processing, restart recovery, and 6,000-row source throughput.

Browser verification on the isolated WordPress admin confirmed automatic discovery
and reference updates, thumbnail previews, live report rows, pause/resume,
applying expert throughput settings, completion at 100% (13 files, 198 source
records), filtering while retaining the selected image, the dedicated cleanup
workflow, completion blockers, eligible unused image rows, and the recovery-copy
confirmation dialog. The removal confirmation was cancelled; no test image was
removed during visual inspection. Mobile breakpoints are provided but were not
separately browser-tested. Counts describe scanned scope, not proof of external
non-use.

The original 1.0.0 release was tested on PHP 8.3.33 and mistakenly required PHP
8.1 despite PHP 8.0.30 being available locally. Version 1.0.1 corrects the minimum
to PHP 8.0 and fixes a PHP 8.0 PCRE JIT false-negative in CSS/JSON path matching.
Regression tests cover JIT-enabled matching and large non-path source values.

* PHP unit suite: tokenizer/reference strength, CSS/srcset/CDN paths, encoded URLs,
  CSV injection prevention, traversal/stream rejection and directory containment.
* WordPress integration suite: grouped thumbnail usage, Elementor-shaped metadata,
  custom fields, downloads, reviewed/unreviewed scope, stale-scan refusal, recent
  uploads, protected identity assets, filtered CDN URLs, oversized records,
  duplicate paths, quarantine, retention, interrupted restore and collision refusal.
* Image workflows: JPEG lossy compression with measured savings, hash-identical
  original restoration, metadata restoration, refusal/rollback of unsupported
  lossless JPEG, PNG-to-WebP alternatives, and original-format picture fallbacks.
* Access/control: nonce and administrator checks, revoked background permissions,
  pause/resume/cancel, missing action confirmation and malformed attachment IDs.
* Retention/purge: explicit purge after retention leaves an attachment tombstone;
  a new reference prevents purge. Plugin-record cleanup preserves pending jobs
  and recovery records.
* All nine admin screens rendered through WordPress without PHP warnings.
* JavaScript tests: ID validation, deduplication, confirmation requirements and
  visibility of incomplete/stale scan state. Syntax checks cover all JS assets.
* PHP syntax checks cover production code and test PHP files.
* The release ZIP was extracted into the isolated WordPress plugins directory
  and activated/deactivated successfully without development dependencies.
  Packaged plugin metadata and autoloading were checked; ZIP CRCs and SHA-256
  are verified by the release builder.
* Configured WordPress Coding Standards audit passes. The ruleset documents why
  live custom-table queries, native atomic filesystem operations, caught exception
  messages, read-only GET filters and a one-minute worker cadence have focused
  exceptions. Individual dynamic SQL sites are annotated after verifying fixed
  identifier maps, whitelisted order clauses and prepared request values.

## Limits of this validation

This is not an independent security audit or a universal production certification.
ImageMagick/lossless pixel checks, AVIF codecs, PHP 8.1 specifically, Linux hosting,
network installations, actual cloud-storage plugins, the full Elementor/ACF/
WooCommerce products, browser visual/accessibility behavior and 100k-item load
benchmarks were not certified by these local tests. Generic metadata fixtures
exercise their storage shapes, not every vendor version or runtime behavior.

Run the staging matrix in RELEASE-CHECKLIST.md for the target deployment. In
particular, verify private storage across virtual-host aliases, visually inspect
lossy outputs and check the public site after a small reversible quarantine trial.

## API references used

Implementation follows the supported WordPress image-editor and missing-size
APIs, rather than rewriting server configuration:

* [wp_get_image_editor](https://developer.wordpress.org/reference/functions/wp_get_image_editor/)
* [wp_generate_attachment_metadata](https://developer.wordpress.org/reference/functions/wp_generate_attachment_metadata/)
* [register_rest_route](https://developer.wordpress.org/reference/functions/register_rest_route/)

The downloaded stable WordPress package used in this run identifies itself as
7.1. That is the tested package version, not a promise about all future releases.
