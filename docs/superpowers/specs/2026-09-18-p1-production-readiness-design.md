# P1: Production readiness (2.0.0)

Status: approved 2026-09-18. Supersedes the 1.2.0 admin layer.

## Product context

Four sub-projects. This spec covers P1 only.

| Release | Contains |
| --- | --- |
| **P1 / 2.0.0** (this spec) | 9 screens to 4, all audited defects, safe-by-default delivery, wp.org compliance |
| P2a / 2.1.0 | Accuracy: Elementor, Divi, Beaver, WPBakery, ACF, WooCommerce galleries, theme mods |
| P2b / 2.2.0 | Per-page weight attribution, waste-aware queue, oversized-in-context |
| P3 / Pro 1.0 | Site-wide attribution, scheduling, branded client reports, WP-CLI, multisite |

Positioning: the only WordPress media plugin that knows *where* every image is
used and turns that into per-page speed fixes. The `evidence` table is the moat:
competitors optimise blindly because they have no reference graph.

Distribution: free on wordpress.org; Pro ships separately so the free plugin
keeps its "no external service, no telemetry" guarantee.

## Constraints

1. Clean break. No migration from 1.x. Nothing is deployed.
2. The full 2.x schema lands in P1 so 2.1.0 and 2.2.0 need no migrations.
3. wordpress.org compliance is part of "done": Plugin Check clean, unminified
   asset sources, readme.txt to spec.
4. Safe-by-default: features that cannot alter visual output are on at
   activation. Anything that changes how a page looks, or that rewrites files,
   is opt-in. A single kill switch disables all delivery.

## Architecture

Keep the engine, rebuild the surfaces. Every audited defect sits in the admin
layer; none sit in the scanner or vault.

```
includes/
  core/      plugin, database, settings
  engine/    scanner, matcher, media, vault, optimizer   (KEEP)
  admin/     admin (router + REST), screen-audit, screen-cleanup,
             screen-optimize, screen-settings, report-table
  delivery/  viewport (CLS, LCP, lazy), delivery (picture rewriting)   NEW
assets/
  src/admin.css   human-readable source
  admin.css       build artifact
```

`class-i18n.php` is deleted: 284 lines of identity mappings replaced by direct
`__()` calls and `_n()` for counts.

## Screens

| Screen | Slug | Absorbs |
| --- | --- | --- |
| Audit | `smao-audit` | Dashboard, Scan media |
| Clean up | `smao-cleanup` | Remove unused images, Recovery |
| Optimize | `smao-optimize` | Optimize images, Performance |
| Settings | `smao-settings` | Settings, Activity Log, System Status (tabs) |

One navigation: the WordPress submenu. The duplicate custom tab bar is deleted.
The workflow progress strip renders on all four screens.

## Render strategy

PHP owns all markup and the table works with JavaScript disabled. JavaScript
never rebuilds the table. It polls a `report/digest` endpoint returning
`{signature, total}`; when the signature changes it swaps in a server-rendered
fragment, **unless** the user has an evidence panel open or rows selected, in
which case it surfaces a "New results available. Refresh." control instead.

This resolves the double render, label drift, the N+1 evidence queries, and the
defect where evidence panels collapse every two seconds during a scan.

## Defect resolution

- **By deletion:** duplicate Dashboard, naming conflicts, tab ordering,
  vanishing nav, scan card repeated on three screens, recovery screen refresh,
  the i18n class.
- **One owner per setting:** throughput lives only on Audit; `coverage_reviewed`
  lives only on Clean up. Kills the hidden-input lost update, the dual `batch`
  field, and the scan-invalidating Settings path.
- **Selection integrity:** selection keyed to scan id + filter signature + page;
  cleared on action success, filter change and page change. Acknowledgement
  resets after every action.
- **Honest controls:** destructive buttons ship disabled until state is known;
  `retry`/`tick` hidden until a scan exists; one label per command.
- **Proportional friction:** typed confirmation for quarantine and purge only.
  Optimize and thumbnails get a plain confirm; restore confirms without typing.
- **Feedback at the action**, with focus moved, not at the top of the page.
- **Adaptive polling:** 2s scanning, 30s idle, suspended on hidden tab with an
  empty queue. The 300ms no-op work loop stops.
- Unknown slug redirects to Audit. Table headers and empty states everywhere.
  Filters and pagination write to the URL. Badge rules for all seven statuses.

## Delivery subsystem

`Viewport` (on at activation, cannot alter appearance):

- Inject `width`/`height` on content images missing them (CLS).
- Detect the LCP candidate server-side (featured image, else first content image
  above a size threshold) and emit `<link rel="preload">`.
- Lazy-load correction: the LCP image never gets `loading="lazy"`; below-fold
  images do.

`Delivery` (opt-in, changes markup):

- Rewrite `the_content` images to `<picture>` with WebP/AVIF sources and the
  original `<img>` as fallback. This closes the 1.2.0 hole where only
  `wp_get_attachment_image` was filtered, so content images were never served
  modern formats.

Kill switch: `smao_delivery_enabled`. Admin-bar indicator, one-click disable.

## Schema (full 2.x, landed now)

Existing: `media`, `evidence`, `jobs`, `log`, `vault`, `tokens`.

Added in P1, populated later:

- `evidence.provider` VARCHAR(32) — which scanner found the reference, so P2a
  scanners are attributable and independently re-runnable.
- `media.impact_score` INT — waste-aware queue ordering (P2b).
- `smao_pages` — per-URL aggregate: url_hash, url, image_count, image_bytes,
  lcp_attachment_id, measured_at (P2b).
- `smao_render` — attachment_id, page_hash, rendered_width, rendered_height,
  dpr: the oversized-in-context probe (P2b).

## Verification

Live harness: WordPress 7.1, PHP 8.3.33, MariaDB 10.4, GD with WebP and AVIF,
no Imagick. Seeded with 50 attachments, 293 files, 55 MB of uploads, 18
unreferenced (36% waste), 14 posts and pages.

Measured baseline across 4 pages: 4.0 MB image payload, 17 images, 0
`<picture>`, 12 eager. Acceptance requires a re-measured after figure plus
`phpunit` unit and integration suites, the Node tests, and Plugin Check clean.

Note: the active theme already emits width/height, so the CLS fix will not move
this particular benchmark. It is retained for page-builder sites and reported
honestly rather than claimed.

## Out of scope

Page caching, CSS/JS minification, critical CSS, font optimisation, CDN,
licensing. Recorded as P3 or explicitly rejected.
