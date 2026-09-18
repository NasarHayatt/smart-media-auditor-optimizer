=== Smart Media Auditor & Optimizer ===
Contributors: smao-contributors
Tags: media, images, performance, optimization, cleanup
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find out where every image is actually used, remove what is not, and make the pages that remain load faster.

== Description ==

Most media plugins do one of two things. Cleaners tell you which files look
unused. Optimizers compress everything you own, whether anything uses it or not.

This plugin builds a reference graph first: for every file, it records exactly
where it was found, which database table, which row, which field, and how
strong the match is. Everything else is built on that evidence.

**Auditing you can check**

Every result shows its working. Open any file and you see the precise locations
that reference it, so you are never asked to trust a verdict you cannot verify.
Files with no reference are candidates, not conclusions, and the plugin says so.

**Removal you can undo**

Nothing is ever deleted automatically. Removing a file moves it into private
recovery storage outside your web root, keeps the attachment record, and writes
a journal entry. Restoring puts it back. Permanent deletion is a separate,
deliberate action with its own confirmation.

**Speed that is on from the moment you activate**

Three corrections are enabled by default because none of them can change how
your pages look:

* Missing width and height are added to images, which stops layout shifting as
  the page loads.
* The largest image on each page is detected and preloaded, so it starts
  downloading sooner.
* Lazy loading is corrected, so the main image is never deferred and the images
  further down are.

WebP and AVIF delivery is available too, but stays off until you enable it,
because it changes the markup your theme emits. When enabled it wraps images in
a picture element and always keeps the original as a fallback, covering images
inside post content as well as those rendered by your theme.

A single switch turns every speed correction off again, without touching a file.

**No external services**

No telemetry, no remote scanning, no API keys, no third-party optimization
service. Everything runs on your own server and nothing leaves it.

== Installation ==

1. Back up your database and uploads folder. Test on a staging copy first.
2. Install and activate the plugin. Speed corrections start working immediately.
3. Open **Media Auditor → Audit** and run a scan. Scanning only reads; it never
   changes a file.
4. Review the results, then use **Clean up** when you are ready to remove
   anything. Removal requires private recovery storage to be configured first.

== Frequently Asked Questions ==

= Will this delete my images? =

Not on its own, and never permanently in one step. Removal moves files into
private recovery storage and keeps the attachment record so the file can be
restored. Permanently deleting a recovery copy is a separate action that asks
you to type a confirmation word.

= Why does it say "possibly used" instead of "unused"? =

Because it cannot prove a negative. A file is only called an unused candidate
when a complete, current scan found no reference and you have confirmed you
reviewed the things a database scan cannot see, such as custom code, hard-coded
theme paths and external sites linking to your files.

= Does it speed up my site straight away? =

The layout-stability, preload and lazy-loading corrections apply as soon as you
activate it. The larger win, serving WebP or AVIF, needs you to generate those
formats on the Optimize screen and then switch delivery on.

= Do I need ImageMagick? =

Only for lossless compression, which verifies that no pixel changed. With GD
alone you can still use lossy compression and generate WebP and AVIF.

= Does it work with page builders? =

Images placed by page builders are covered by the speed corrections. Reference
detection for page-builder layouts is being expanded; until then, files those
builders reference are reported conservatively rather than as unused.

== Screenshots ==

1. The Audit screen: scan progress and a full media inventory with evidence.
2. Clean up: prerequisites, unused candidates and recovery in one place.
3. Optimize: the compression queue and oversized image report.
4. Settings: speed corrections, with safe defaults separated from anything that
   changes your markup.

== Changelog ==

= 2.0.1 =
* Fixed a fatal error after updating from 1.x. 2.0 moved every class into
  subdirectories, and a site still running the previous plugin bootstrap, which
  an opcode cache can easily cause, looked for them at their old paths and died.
  The old paths now forward to the new ones, so the update cannot half-apply.

= 2.0.0 =
* Rebuilt the interface around four screens instead of nine, with one
  navigation and a clear scan, clean up, optimize workflow.
* Added front-end speed corrections that are safe to run by default: intrinsic
  dimensions, automatic largest-image preloading and lazy-loading correction.
* WebP and AVIF delivery now covers images inside post content, not only those
  rendered by the theme.
* Fixed a major accuracy problem: WordPress internals such as rewrite rules,
  cron schedules, user roles and transients were being scanned for media
  references, producing weak matches for files nothing used and suppressing
  almost every unused result.
* Every setting now has a single owner, so saving one screen can no longer
  reset or invalidate another.
* Typed confirmations are now required only for irreversible actions.
* Tables no longer rebuild themselves while you are reading them; open evidence
  panels survive a refresh.
* Polling backs off when nothing is happening instead of querying continuously.
* Filters and pagination are reflected in the URL, so views can be shared and
  the back button works.
* Removed the internal translation catalogue in favour of standard WordPress
  translation functions.

= 1.2.0 =
* Scan and remove workflow, recovery storage configuration in the interface.

= 1.1.0 =
* Live workspace with progress, evidence and activity.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.0.0 =
A substantial rebuild. Menu locations have changed and scan results should be
regenerated with a fresh scan, which is more accurate than before.
