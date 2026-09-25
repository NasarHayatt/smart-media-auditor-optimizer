=== Smart Media Auditor & Optimizer ===
Contributors: smao-contributors
Tags: media, images, performance, optimization, cleanup
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.3.8
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

These corrections are enabled by default because none of them can change how
your pages look:

* Missing width and height are added to images, which stops layout shifting as
  the page loads.
* The largest image on each page is detected and preloaded, so it starts
  downloading sooner.
* Lazy loading is corrected, so the main image is never deferred and the images
  further down are.
* Once you have measured your layout, images are requested at the size they are
  actually shown rather than the size of your browser window.

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
3. Open **Media Auditor → Overview**. It tells you the one thing to do next and
   gives you a single button to do it.
4. Follow it. Scanning only reads and never changes a file. When something is
   found, Clean up shows you each image before anything moves.

== Frequently Asked Questions ==

= Will this delete my images? =

Not on its own, and never permanently in one step. Removal moves files into
private recovery storage and keeps the attachment record so the file can be
restored. Permanently deleting a recovery copy is a separate action that asks
you to type a confirmation word.

= Why does it say "not sure about" instead of "unused"? =

Because it cannot prove a negative. An image is only listed as unused when a
complete, current scan found nothing referring to it and you have confirmed you
thought about what a database scan cannot see: custom code and other websites
linking straight to your files. Anything ambiguous is left alone and is never
offered for removal.

= Does it speed up my site straight away? =

The layout-stability, preload and lazy-loading corrections apply as soon as you
activate it. The larger win, serving WebP or AVIF, needs you to make the smaller
copies first under Advanced, then switch delivery on under Speed.

= Do I need ImageMagick? =

Only for lossless compression, which verifies that no pixel changed. With GD
alone you can still use lossy compression and generate WebP and AVIF.

= Does it work with page builders? =

Images placed by page builders are covered by the speed corrections. Reference
detection for page-builder layouts is being expanded; until then, files those
builders reference are reported conservatively rather than as unused.

== Screenshots ==

1. Overview: the one thing to do next, and what it is worth in megabytes.
2. Clean up: every unused image shown as a picture, with what was searched.
3. Speed: plain-language switches, each explained by what a visitor gets.
4. Advanced: the scan detail, compression queue, activity log and system status.

== Changelog ==

= 2.3.8 =
* Fixed: "Save" and "Clear everything" failed with "another media operation
  is running" while WebP copies were being made, so visitors kept getting
  pages built with the old settings.
* Changing any speed setting now clears stored pages at once.
* After the Google check applies a setting, the switch on screen shows it, so
  a later Save does not undo it.
* Updating clears stored pages.

= 2.3.7 =
* New: Check with Google PageSpeed. With a free Google API key, the plugin
  runs Google's own test on your home page with your current settings and with
  each setting that can go either way, and keeps whichever Google scores
  highest. The last result is shown on the Speed screen.
* New: "Hold back all scripts until the page has appeared", under Worth
  testing first, for script-heavy pages. The Google check tests it for you.
* New: pages can be loaded once with speed settings switched, by adding
  ?smao-test=setting.on or setting.off to the address. Only speed settings can
  change, only for that one page view, and such pages are never cached.
* Fixed: "Measure again" failed with "another media operation is running"
  while WebP copies were being made.
* WebP copies for the whole library are queued at once.
* Images deleted before 2.3.6 are now removed from the results as well.

= 2.3.6 =
* Fixed: on measured pages the main image was lazy loaded and lost its high
  priority, so phones fetched it last (largest paint 20s on a live site).
  The measurement now records every image in the first screen; none of them
  is ever lazy loaded, and the largest one is preloaded and fetched first,
  even when the biggest visual is a CSS background.
* New: WebP images, automatically. A WebP copy of every JPG and PNG is made in
  the background, new uploads included, and every image in the page is
  switched to its copy: image tags, sliders, page-builder data and inline
  backgrounds. Originals are never changed. Browsers that do not accept WebP
  get the originals from their own cached copy of the page.
* Fixed: deleting an image in the Media Library was silently refused when the
  plugin held a recovery copy of it, and deleted images stayed in the results.
  Deleting now works, the image leaves the results at once, and its recovery
  copy is removed.
* The overview follows changes as they happen and every action shows a
  confirmation that stays on screen.
* The page cache settings file is refreshed as soon as the plugin updates.

= 2.3.5 =
* Changed: "Stop stylesheets blocking the first paint" is now off by default
  and listed under "Worth testing first". On an image-heavy Elementor site it
  lowered the mobile PageSpeed score from 65 to 47: with no stylesheet holding
  the page back, large images started downloading at once and delayed the
  largest paint. Updating switches it off; switch it back on only if
  PageSpeed scores higher with it.

= 2.3.4 =
* Fixed: the check behind "Stop stylesheets blocking the first paint" failed
  pages that were fine. It tested the captured styles after the theme's own
  inline styles instead of before them, where visitors get them, so themes
  that adjust layout with inline styles were always refused.
* New: the page measurement records the largest image in the first screen on
  phones and desktops, including section backgrounds set in a page builder's
  CSS, and "Load the main image first" preloads exactly that image. Before, the
  image was guessed from the featured image or the first image in the content,
  which misses builder backgrounds and preloaded the wrong file.

= 2.3.3 =
* Fixed: pages built with Elementor and similar builders jumped on phones.
  Builders print the styles for headers and widgets at the end of the page,
  so the header was drawn unstyled and then snapped into shape. Those styles
  now move to the head, in their original order, so the finished page looks
  exactly the same but is styled from the first frame. On the site where this
  was found, layout shift fell from 0.97 to 0.003. Part of "Stop pages jumping
  about".
* Fixed: when held-back scripts loaded, the page's own "loaded" events were
  replayed to every script on the page, re-running theme and builder code and
  moving the layout. Only the held-back scripts now receive them, and nothing
  is replayed when no script was held back.
* Fixed: cached pages were stored before the script and stylesheet changes
  were applied, so visitors served from the cache missed them.

= 2.3.2 =
* Fixed: on some sites, "Stop stylesheets blocking the first paint" made the
  page appear unstyled and then jump into place, lowering the PageSpeed score.
  Stylesheets could be switched to background loading even when the styles for
  the top of the page had not been added.
* Styles for the top of the page are now captured per page, at phone and
  desktop widths, without the admin bar, and with image and font addresses
  kept intact. Each page is then laid out again using only those styles and
  compared with the real layout. Only pages where nothing moves load their
  stylesheets in the background; every other page is left exactly as it was.
  Styles captured by earlier versions are discarded on update.
* Fixed: the scan reported "could not finish properly" on most live sites.
  Long page-builder content is now read in full, and only changes that could
  add a media reference count as the site changing. If one happens during a
  scan, each unused file is checked again against live data instead of the
  whole result being thrown away.
* Page cache now serves stored pages even when the host does not allow
  WP_CACHE to be switched on. The Speed screen shows which serving mode is
  active and the one line that makes it faster.

= 2.3.1 =
* Fixed two fatal errors that took down the front end while leaving the admin
  working, so the problem was easy to miss. Sites behind a CDN saw stale pages
  rather than errors, hiding it further. Update immediately if you are on 2.3.0.
* The first affected Apache and LiteSpeed hosts: environment detection called a
  function that only exists inside the WordPress admin.
* The second affected every host: the caching rules were loaded later in the
  request than the first code that needed them.

= 2.3.0 =
* New: page cache. Finished pages are stored and served by a drop-in that runs
  before WordPress loads, so a repeat visit costs a file read instead of a full
  page build. Measured on the test site, server response time fell from 332ms
  to 16ms.
* New: compressed copies are stored alongside each page, cutting the HTML sent
  to browsers that accept them by around 80 percent.
* New: targeted invalidation. Editing a post clears that post, the front page
  and the archives it appears on, rather than emptying the whole cache.
* New: background rebuilding after a change, a few pages at a time, so the next
  visitor still gets a stored page without the site being hammered to achieve it.
* New: browser caching. Images, stylesheets, scripts and fonts are given long
  lifetimes with correct validators, written to .htaccess where the server reads
  it and shown for pasting where it does not.
* Never cached: logged-in visitors, carts, checkouts, accounts, searches, feeds,
  password-protected posts, and anything carrying a personal cookie.

= 2.2.0 =
* New: holds third-party scripts until a visitor interacts. Analytics, chat
  widgets, pixels and social embeds contribute nothing to the first view of a
  page, and they are the largest single cause of a poor performance score. They
  now run on the first scroll, tap or key press, and after a few seconds
  regardless, so nothing is lost for visitors who never interact.
* New: remaining scripts are deferred so they stop holding up text and images.
  jQuery and its dependants are left alone.
* New: detects the site it is installed on, including the theme, page builders,
  WooCommerce, sliders that keep media in private tables, and other performance
  plugins. When another plugin already handles an area, this one stands down
  rather than rewriting the same page twice.
* New: records the styles the top of each template needs, so stylesheets can
  load without blocking the first paint. Only applies where it would actually
  help; a theme that already inlines its CSS is left alone.
* New: asks webfonts to show text immediately, and connects to font hosts early.
* New: per-script and per-stylesheet exclusions for anything that needs to be
  left untouched.

= 2.1.1 =
* Fixed: when no main image had been identified, the first image on the page was
  given high loading priority. On sites that begin with a tracking pixel, a
  spacer, or a placeholder left by a JavaScript lazy loader, that was the wrong
  element. Only a visible image of a credible size is chosen now.

= 2.1.0 =
* New: measures how large each image is actually displayed, by loading your own
  pages in a hidden frame inside the dashboard at phone, tablet and desktop
  widths. Nothing is added to the pages your visitors see.
* New: uses those measurements to tell the browser the real display size, so it
  picks a file that fits instead of the largest one available. No image is
  altered and no new file is created. On the test site this took the main image
  from 587 KB to 168 KB.
* New: shows exactly which images are being sent larger than they are shown,
  and how much that costs on every page load.
* Fixed: with WebP delivery on, the main image was downloaded twice, once as
  WebP for the picture element and again as the original for the preload hint.
  The preload now points at the file the browser will actually use.
* Fixed: WebP copies are only made where they beat the original, so a set can
  cover large sizes and skip small ones. Delivery now declines an alternate
  that is wider than the image is ever drawn, instead of sending more bytes
  than the correctly sized original would have.

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

= 2.3.8 =
Fixes Save and Clear everything being blocked while WebP copies are made.

= 2.3.7 =
Adds the Google PageSpeed check and fixes page measurement while WebP copies
are being made.

= 2.3.6 =
Fixes the main image loading last on measured pages, adds automatic WebP
images, and fixes deleting images. Run the page measurement under Speed again
after updating.

= 2.3.5 =
Switches off background stylesheet loading, which lowered PageSpeed scores on
image-heavy pages. Clear the page cache after updating.

= 2.3.4 =
Lets stylesheets load in the background on themes it previously refused, and
preloads the real main image. Run the page measurement under Speed again after
updating.

= 2.3.3 =
Fixes pages built with Elementor and similar builders jumping on phones, and
cached pages missing the speed changes. Clear the page cache after updating.

= 2.3.2 =
Fixes layout jumping and a lower PageSpeed score on some sites with background
stylesheet loading switched on, and the scan reporting that it could not finish.
Run the page measurement under Speed again after updating.

= 2.3.1 =
Fixes a fatal error on Apache and LiteSpeed hosts in 2.3.0. Update immediately.

= 2.3.0 =
Adds page and browser caching, both on by default. If another caching plugin is
active this one stands down automatically.

= 2.2.0 =
Adds JavaScript and CSS optimisation. Check a few pages after updating, and use
the exclusion boxes under Speed if anything needs to be left alone.

= 2.1.0 =
Adds measured right-sizing. Open Speed and run "Measure my pages" to switch it on.

= 2.0.1 =
Fixes a fatal error some sites hit when updating from 1.x. Update straight away
if you are on 2.0.0.

= 2.0.0 =
A substantial rebuild. Menu locations have changed and scan results should be
regenerated with a fresh scan, which is more accurate than before.
