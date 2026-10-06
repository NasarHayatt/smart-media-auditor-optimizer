=== Smart Media Auditor & Optimizer ===
Contributors: smao-contributors
Tags: media, images, performance, optimization, cleanup
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.8.0
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

= 2.8.0 =
Built and verified against a full audit of a WPBakery + Movedo site whose
phone score had dropped to 10, with its page jumping (layout shift 1.0).
A simulation of the live page with these changes scored 98 on phones and 97
on desktop.
* Fix: stylesheets only wait while the page's scripts wait too, and held
  scripts start only once every stylesheet is on. A theme script that sizes
  sections measured a half-styled page and the whole page jumped.
* Fix: Google's desktop test reports an 800px screen for a 1350px window, and
  2.7.9 took that as the width, so desktop tests never held their scripts.
  The screen width is now only used on touch screens, before the page's
  viewport tag.
* New: released scripts all start downloading at once and still run in page
  order. One by one, a visitor's first touch took 8 to 16 seconds to bring a
  page to life; now about 1.5.
* New: YouTube videos show their picture until someone presses play, instead
  of loading about 850 KB of player scripts on every visit.
* New: stylesheets on open public hosts (such as code.jquery.com) are read,
  measured and loaded in the background like the site's own.
* Fix: Google Fonts were never written into the page; the fetch now runs as
  a background task and refreshes stored pages afterwards.
* Fix: an image the page lazy-loads was missed as the first screen's main
  image, so it kept its lazy loading and started late. It now loads first.

= 2.7.9 =
* Fix: on themes that print their viewport tag after this plugin's styles,
  every phone was taken as an unchecked screen width, so phones and
  Google's mobile test never held their scripts. A phone reports the
  browser's default 980px until that tag is read; no window is taken as
  wider than the screen now.
* Fix: a slider layer taller than its slider, cropped in the finished page,
  showed as a grey band below the slider while scripts waited. Pieces keep
  the crop of the containers around them.

= 2.7.8 =
* Fix: pages built with WPBakery and the Movedo theme failed the look-the-same
  check at every width, so their scripts never waited. A live home page now
  passes at all four widths. What was wrong, and is fixed for every theme:
  * two sections with the same id made everything inside them impossible to
    target;
  * a piece whose own selector started at its area was silently dropped,
    taking a whole paragraph with it;
  * a header bar pinned to the top was treated like a chat bubble and never
    compared, so a theme's header that stays invisible until its script runs
    was missing while scripts waited. It now shows, with its background;
  * text a theme draws past its own box, such as a slider title, and a logo
    shifted up inside its link, were cut off.
* Fix: the Speed screen's "saved on every page load" used each image's
  total size on disk, all copies together, as if it were one file (85 MB
  shown for one page). It now reads each copy's real size.

= 2.7.7 =
* New: on measured pages, image preloads added by a theme, a snippet or
  another plugin are dropped when the page never shows that file. A live
  site preloaded an older copy of a photo from another folder, fetched
  ahead of the page's main image for nothing; without it the phone score
  stayed at 96 to 97 instead of dropping as low as 82.

= 2.7.6 =
* Fix: the look-the-same check failed on phones and tablets on themes whose
  carousels float their slides, whose sections overlap with a negative
  margin, or that hide blocks until they fade in. Those pages then held no
  scripts on phones at all. All three are now rebuilt correctly; a live
  Elementor home page went from passing at 1 of 4 widths to all 4.
* Fix: the first-screen styles of pages using Elementor's local Google Fonts
  were too large to use (254 font faces), so every stylesheet kept blocking
  the first paint. Only the font faces the page actually loads are kept now:
  177 KB became 44 KB.
* New: Google Fonts stylesheets are written into the page instead of being
  linked, fetched once in the background and kept for a week. Two of them
  held a live page's first paint back by about a second on phones.
* New: Slider Revolution slides, which the slider paints on a canvas, show
  their photo while scripts wait instead of a grey block, from the smallest
  copy WordPress keeps that still fills the slide (110 KB instead of 393 KB
  on phones).
* Changed: "Stop stylesheets blocking the first paint" is on by default again,
  also on existing sites. It only changes pages whose first-screen styles
  passed the look-the-same check. A live home page scored 97 on a phone with
  it and 81 without.
* New: after an update, pages that were not fully sped up are measured again
  automatically the next time the Speed screen is open.
* Fix: the old-address image fix now also handles addresses that start with
  "//", as Slider Revolution saves them, and leaves Jetpack's image CDN
  copies of this site alone so their resizing keeps working.

= 2.7.5 =
* New: images a page still loads from an old site address, such as a
  staging or temporary address kept in a page builder's settings, are served
  from this site when the same file exists here. They then get WebP and the
  right size, and the browser needs no second connection. A live site's main
  image, an 829 KB PNG, was loading from its old temporary address.
* Fix: "Show text while webfonts load" now also corrects Google Fonts links
  that ask for display=auto or block, as page builders often do.

= 2.7.4 =
* Fix: the page cache never switched WordPress's WP_CACHE setting on, so on
  a site without it (another cache plugin had removed it) stored pages were
  only sent after every plugin had loaded: 700 ms instead of under 100 ms.
  It is now added to wp-config.php when the file can be written, and the
  file is restored at once if anything about the write looks wrong.
* New: page preloaders (a layer covering the screen until the theme's script
  removes it) are hidden while scripts wait. The page was otherwise blank
  for several seconds, and the time until it looked complete halved when
  the layer was hidden.

= 2.7.3 =
* Fix: the styles for the first screen left out the rule that hides a
  "skip to content" link, which almost every theme has and places far off
  screen. Without it the link showed at the top and pushed the page down, so
  pages failed the check and kept every stylesheet blocking.
* Fix: the page check rebuilt floating widgets (chat bubbles, cookie bars,
  popups) as if they were part of the layout and failed pages for them. They
  are left out now.
* Fix: content drawn larger than its container, such as a logo bigger than
  its link, counted as missing; and an inline area could not keep its size.
  A live page that failed at every width now passes at all of them.

= 2.7.2 =
* New: when another speed plugin is active, the Speed screen offers to switch
  it off in one click. This plugin then takes over the page cache it leaves
  behind and measures the pages straight away. An installed but idle speed
  plugin had left a site with nothing optimised at all.
* New: WebP Converter for Media is recognised, so two plugins never rewrite
  the same images.

= 2.7.1 =
* Fix: other speed plugins are recognised by their folder, not one exact
  file name. 10Web Booster's renamed main file went unnoticed, so both
  plugins rewrote the same pages and broke each other's work.
* New: the Speed screen says at the top when another speed plugin is active
  and what this plugin leaves to it.
* New: a page cache file left behind by a cache plugin that is no longer
  active is replaced (a copy is kept beside it). A host's own cache file,
  or one belonging to an active plugin, is never touched.

= 2.7.0 =
* New: the page check now also starts the held page's scripts, as a
  visitor's first tap would, and compares what follows with the finished
  page. A screen width where the page would move never holds its scripts.
* New: a page that passes at some screen widths and not others holds its
  scripts where it passed and loads normally where it did not. A visitor
  who sees a page move switches off only their screen width, not the page.
* New: while scripts wait, the browser skips drawing large blocks below the
  first screen until they come near it, with their size kept, and only
  where the page check confirms nothing changes.
* New: leave emoji to the browser (WordPress's emoji script removed), load
  maps and embeds only when needed, and send smaller pages (comments and
  spare space in styles removed). Text whitespace is never changed: a theme
  may draw it as written.

= 2.6.5 =
* Fix: page measurements were cleared, and pages quietly lost their speed-up,
  on changes that do not affect how a page looks: every donation, order or
  other private record, routine theme-setting writes, and any plugin update,
  including automatic ones. Only layout changes clear them now: the theme,
  the Customizer, menus, widgets, shared templates, and updates to the theme,
  page builders and sliders.
* New: when measurements are cleared, the admin says why, and the Speed
  screen measures again by itself as soon as it is opened.

= 2.6.4 =
* Fix: a visitor who touched the page before its background stylesheets had
  arrived saw nothing move, but the page grew as they arrived, and that
  switched the page back. A change in length now only counts when the
  stylesheets were already on; movement on screen always counts.
* Faster: stylesheets the page does not need for its first screen are not
  even fetched until the page has loaded or the visitor acts, so they no
  longer compete with the main image on a slow phone.

= 2.6.3 =
* Fix: a page switched itself back when its scripts were started by a timer
  or by the browser tab becoming visible, as happens during speed tests,
  rather than by a visitor. Only a visitor's own scroll, tap or mouse
  movement can now switch a page back, and pages that hold every script
  start them for nothing else.
* Improved: when a page is switched back, the Speed screen says on how wide
  a screen, by how much the page length changed and how much moved.

= 2.6.2 =
* Fix: a desktop scrollbar made the page 15px narrower than its window, so
  Google's desktop test never got the held page. Screens are now matched by
  window width, as the page's own width rules are.
* Fix: pages that hold every script no longer start them on a timer. They
  already look finished; the visitor's first scroll, tap or mouse movement
  starts them. On a timer, a slider built six seconds in and a test that
  never interacts took that as the page's main content. The timer still
  applies to third-party scripts held on their own.
* Faster: stylesheets loading in the background are switched on together,
  once all have arrived, instead of one at a time. A page with 26 of them was
  laid out 26 times over, most of its blocking time on a slow phone.

= 2.6.1 =
* Fix: a pre-built page is only known to look right at the screen widths it
  was checked at. Sliders scale with the screen and text wraps differently a
  few pixels narrower, so visitors on other widths saw the page move when its
  scripts started, and the page then switched itself back. Scripts now wait
  only on screens as wide as a checked one, which includes the widths
  Google's tests use; everyone else gets the page as it normally loads.
* Faster: below the first screen, rebuilt pieces keep their place without
  downloading pictures, and section backgrounds there wait until the visitor
  scrolls. On a live Elementor home page these had been downloading 3 MB
  before the main image could load.

= 2.6.0 =
* Fix: the page check loaded pages in frames placed off screen, where nothing
  ever counts as scrolled into view. Anything a page builds as it scrolls into
  view, and sliders that wait to be seen, never started, so the check compared
  two unfinished pages. Pages are now measured on screen, invisibly, and
  scrolled through before they are compared.
* New: visitors' pages check themselves. When scripts start on a page that
  holds them, the page compares its length and movement before and after. If
  it changed, that page stops holding its scripts straight away and its stored
  copy is rebuilt, so no visitor keeps getting a page that jumps.
* New: when every script waits, jQuery and WordPress's own libraries wait too,
  first in line, unless a script you excluded needs them. Stylesheets can then
  load in the background as well. Together these are the largest remaining
  cost of the first paint on a slow phone.
* Fix: the Google check judged page layout by an average that counted runs in
  which Google left out the full-page screenshot as a page with no height.

= 2.5.4 =
* Fix: the page check ran in frames that, in a desktop browser on Windows,
  have a 17px scrollbar. Every layout was measured narrower than a phone or
  Google's test sees it, and full-width areas such as sliders landed out of
  place, so pages failed the check. The frames no longer show a scrollbar.
* Fix: rebuilt full-width areas subtract a visitor's desktop scrollbar,
  measured before the first paint, so they line up exactly.
* Fix: the Google check ran every test of one setting before the next, so the
  current settings always met a cold server and could lose to timing alone.
  Settings now take turns, three runs each.

= 2.5.3 =
* Fix: an element with no width whose contents overflow it, such as a
  carousel column in some phone layouts, was treated as hidden. The page check
  then hid it while scripts waited, which also removed its height and moved
  everything below it, so the page never passed. Only elements the page
  actually hides count as hidden now, and such a column keeps its height and
  shows its contents.

= 2.5.2 =
* Fix: a page's pre-built layout was turned away as "too many extra styles"
  by its uncompressed size. The rules repeat the same selectors and compress
  about twenty times, so a home page whose styles cost visitors under 3 KB
  kept its scripts running. The limit now applies to the size actually sent.
* Improved: rules shared by several screen widths are written once, about a
  quarter less to read.

= 2.5.1 =
* Fix: on pages that hold their scripts, some pre-built styles did not switch
  off once the scripts ran, so a menu could lose items after the first tap.
  Every rule now switches off together.
* Fix: images outside the post content, such as header logos, page-builder
  widgets and anything a builder serves from its own element cache, were sent
  at full size and could take priority over the main image. Every image in
  the finished page is now sized from the measurement and loaded in the right
  order.
* Improved: the page check waits until sliders and carousels have finished
  building, and checks a screen width again before giving up on it.
* Improved: each measured page now says whether its scripts wait, and if not,
  why, at which screen width and by how much.
* Improved: the Google check says why holding scripts had no effect, and the
  image table leaves out vector (SVG) files, which are the same file at every
  size, and shows the size now sent.

= 2.5.0 =
* New: pages look finished while their scripts wait. "Hold back all scripts"
  gives the fastest first paint, but anything a script builds, such as a
  carousel or a slider, used to look broken until the visitor interacted. The
  page measurement now loads each page finished and with scripts held, at
  phone, tablet, laptop and desktop widths, and writes styles that rebuild what
  the scripts would build: every text block, button, image and shape in its
  finished place, and every such area at its finished size. It then lays the
  held page out again and compares it with the finished page. Only pages where
  nothing moves, nothing is missing and the page length is the same hold their
  scripts; every other page runs its scripts normally. When the scripts start,
  the styles switch off and the real carousel and slider take over. This works
  from how the page looks, not from knowing any particular theme or plugin.
* Fixed: the Google check could apply a setting that changed nothing, because
  the difference was only Google's run-to-run variation. It now skips settings
  that make no difference to the page, and only applies a setting when every
  run beats every run of the current settings.

= 2.4.0 =
* Fixed: "Stop other scripts blocking the page" deferred WordPress scripts
  that have inline code straight after them, such as moment and the editor
  packages GiveWP loads. That code then ran first and failed ("moment is not
  defined"). Such scripts, and everything they depend on, now stay in place,
  the rule WordPress itself uses.
* Fixed: the Google check could switch on a setting that scored higher while
  leaving the page broken, such as carousels left unbuilt. It now compares the
  length of the whole page as Google renders it and never applies a setting
  that changes the layout.
* "Hold back all scripts" is switched off on update and its description now
  says plainly that it can leave carousels and sliders unbuilt until the
  visitor interacts.

= 2.3.9 =
* Fixed: images used only by plugins that keep their own database tables,
  such as Slider Revolution, were reported as not used anywhere and could be
  removed. The scan and the check made right before any removal now read
  every table in the site's database that can hold text, apart from logs and
  sessions. Existing scan results are marked out of date on update, so
  nothing can be removed until a new scan has run.
* "Recently removed" under Clean up lists every removed image, with Select all,
  so everything can be put back in one go.

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

= 2.7.5 =
Serves images that still point at an old site address from this site, and
shows text straight away with Google Fonts.

= 2.7.4 =
Faster page cache on sites missing WP_CACHE, and theme preloaders no longer
hide the page. Measure again after updating.

= 2.7.3 =
Pages that failed the checks on many themes now pass them. Measure again
after updating.

= 2.7.2 =
One click to switch off another speed plugin and let this one do the work.

= 2.7.1 =
Recognises 10Web Booster and other speed plugins reliably, and takes over
the page cache they leave behind when switched off.

= 2.7.0 =
Checks the moment scripts start, keeps pages fast at every screen width that
passes, and adds emoji, embed and page-size savings. Open Speed after
updating; it measures again by itself.

= 2.6.5 =
Pages keep their speed-up through donations, background saves and plugin
updates. Open Speed after updating; it measures again by itself.

= 2.6.4 =
Stops pages switching themselves back when visitors tap early. Measure again
after updating.

= 2.6.3 =
Speed tests can no longer switch a page back. Measure again after updating.

= 2.6.2 =
Desktop tests now get the fast page, and pages no longer start their scripts
on a timer. No need to measure again.

= 2.6.1 =
Pages that switched themselves back hold their scripts again, and the main
image loads sooner. Measure again after updating.

= 2.6.0 =
The page check now sees content built as the page scrolls, and pages that
change on a real visit switch themselves back. Measure again after updating.

= 2.5.4 =
Pages measured from a Windows desktop now pass the page check. Measure again,
then run the Google check.

= 2.5.3 =
Fixes pages with carousels in zero-width columns failing the page check on
phones. Run the page measurement under Speed again.

= 2.5.2 =
Pages with larger layouts can now hold their scripts. Run the page measurement
under Speed again.

= 2.5.1 =
Fixes pre-built styles that stayed on after scripts ran, and sizes every image
in the page. Run the page measurement under Speed again.

= 2.5.0 =
Pages can now hold their scripts without looking broken. Run the page
measurement under Speed again, then the Google check.

= 2.4.0 =
Fixes script errors caused by "Stop other scripts blocking the page" and stops
the Google check choosing settings that change how the page looks.

= 2.3.9 =
Important if you use Slider Revolution or another plugin with its own tables:
images used only there could be reported as unused. Update, put back anything
recently removed, and run a new scan before removing more.

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
