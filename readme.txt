=== Smart Media Auditor & Optimizer ===
Contributors: smao-contributors
Tags: media, audit, images, optimization, quarantine
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Conservative media usage scans, recoverable quarantine and backup-first image compression.

== Description ==

Nine administrator screens provide read-only usage audits, evidence, filters,
CSV exports, an image queue, private recovery journals and performance guidance.
No telemetry or third-party service is required. No automatic permanent deletion.
Unmatched files remain possibly used until custom/external usage has been reviewed.

File actions require private recovery storage outside all public web roots.
Configure an existing folder in Settings or define SMAO_VAULT_DIR.
Multisite and offloaded media are report-only. Lossless JPEG recompression is not
supported; unsupported compression is rejected, never silently made lossy.
See the included README.md and docs for installation and complete safety limits.

== Installation ==

1. Upload and activate the plugin.
2. Review Smart Media Auditor > System Status.
3. Run a dry-run scan and review evidence.
4. Configure private recovery storage before optional file actions.
5. Back up and test on staging before production use.

== Changelog ==

= 1.2.0 =
* Decouple processing from report polling; continue in background tabs with request timeouts and stall recovery.
* Process source pages within the time budget and checkpoint completed work during batches.
* Dedicated cleanup workflow, visible blockers, scope review and recovery-folder setup.
* Ignore editor locks and login-session metadata when invalidating scan coverage.

= 1.1.0 =
* Redesigned dashboard with live progress, file discovery previews, activity checkpoints and automatically refreshed reports.
* Browser-assisted batches and expert throughput, time-budget and refresh controls.
* Live optimization queue, selection-preserving reports, evidence disclosure and action confirmation dialogs.
* Retains PHP 8.0 compatibility.

= 1.0.1 =
Support PHP 8.0.30. Correct the package PHP requirement and fix PCRE JIT
reference matching on PHP 8.0 without changing server settings. Bound matching
work on large non-path values. Verify the installer and activation on PHP 8.0.30.

= 1.0.0 =
Initial conservative scanner, recovery journals, image optimizer and administrator tools.

== Privacy ==

No telemetry or external requests. Plugin reports store attachment IDs, reference
locations and administrator action logs in this site's WordPress database.
