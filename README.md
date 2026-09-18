# Smart Media Auditor & Optimizer

Conservative WordPress media auditing, recoverable quarantine and backup-first
image optimization. PHP 8.0+, WordPress 6.5+. No external service, telemetry,
API key, build step or Composer dependency is required in production.

**Read-only by default. Never automatically deletes media.** An unused result
means “no reference found in the administrator-reviewed scope,” not a guarantee
that nobody uses the file. The default classification for unmatched media is
“possibly used.”

## Install

1. Back up your WordPress database and uploads. Test on a staging copy first.
2. Upload `smart-media-auditor-optimizer.zip` through **Plugins → Add New → Upload
   Plugin**, then activate. Alternatively copy this directory into `wp-content/plugins`.
3. Open **Smart Media Auditor → System Status** and review the environment.
4. Open **Scan media** and start the read-only scan. The open dashboard helps
   WP-Cron advance bounded batches and displays live checkpoints.
5. Open **Remove unused images**. The page lists every prerequisite, including
   scan completion, scope review, and private recovery storage.
6. Review evidence before selecting files. Removal first moves files into
   recoverable private storage; permanent deletion is a separate retained-copy
   action.

## Private recovery storage

Read-only scanning works without recovery storage. Removal and optimization
require an existing writable directory **outside all publicly served
directories**. Create the directory with permissions limited to the PHP service
account, then save its absolute path under **Settings → Private recovery
storage**. Administrators may instead enforce the path in `wp-config.php`:

```php
define( 'SMAO_VAULT_DIR', '/srv/private/smao-my-site' );
```

On Windows, use a private absolute directory such as `D:/private/smao-my-site`.
Do not use uploads, the plugin directory, an aliased public directory or a
symlink. The plugin checks the WordPress and reported document roots; your
server administrator must check additional virtual hosts and aliases. Back up
the vault **together with the database** so recovery manifests stay available.

## Configure

Start with batch size 50, recent-upload protection 30 days, retention 30 days,
scheduled scans off, and compression lossless. Minimum protection/retention is
seven days. Lossless processing requires ImageMagick; JPEG lossless
recompression is deliberately rejected. Choose lossy explicitly if required,
visually review representative files, and restore originals if quality suffers.

EXIF removal, resizing, automatic optimization, alternate-format delivery,
scheduled scans, size suppression and front-page preloading are all opt-in.
With GD, optimization requires lossy mode and explicit metadata-removal consent;
color-profiled/oriented images that cannot be safely handled are rejected.

Exclusions accept one rule per line:

```text
id:123
name:company-logo.png
path:2026/protected/
type:application/pdf
```

Only after reviewing custom code, custom database tables and external consumers
should you check **coverage reviewed**. That permits unused candidates, but never
automatic quarantine. Any relevant content/settings changes invalidate the scan.

## Documentation

* [Architecture, algorithm and database schema](docs/ARCHITECTURE.md)
* [User guide](docs/USER-GUIDE.md)
* [Compatibility and limitations](docs/COMPATIBILITY.md)
* [Security and performance release checklists](docs/RELEASE-CHECKLIST.md)
* [Validation results](docs/VALIDATION.md)

## Development

```sh
composer install
composer test
npm test
composer lint
python tools/build-release.py
```

Integration tests modify a **disposable installed WordPress database**. Configure
its private vault, then:

```sh
SMAO_WP_LOAD=/path/to/test-wordpress/wp-load.php SMAO_ALLOW_TEST_WRITES=1 \
  vendor/bin/phpunit -c phpunit.integration.xml.dist
```

The test suite creates and removes its fixtures. Never point it at production.
See the validation report for what was actually run and untested environments.

## Folder structure

```text
smart-media-auditor-optimizer/
├── smart-media-auditor-optimizer.php   Plugin header, autoload, lifecycle
├── uninstall.php                     Recovery-first uninstall policy
├── readme.txt                        WordPress package information
├── README.md                         Installation and configuration
├── LICENSE                           GPL-2.0-or-later notice
├── includes/
│   ├── class-plugin.php              Hooks, queue, delivery and lifecycle
│   ├── class-database.php            Schema, checked DB operations, lock
│   ├── class-settings.php            Defaults and validation
│   ├── class-matcher.php             Reference tokens and CSV safety
│   ├── class-media.php               Grouping and path/storage policy
│   ├── class-scanner.php             Resumable three-phase scanner
│   ├── class-vault.php               Durable file-operation journals
│   ├── class-optimizer.php           Compression, alternates, thumbnails
│   ├── class-admin.php               Nine screens, REST and CSV
│   └── class-i18n.php                Extractable dynamic-label catalogue
├── assets/
│   ├── admin.css
│   ├── admin.js
│   ├── model.js
│   └── inspect-images.js             Local administrator sizing inspector
├── languages/                        Translation catalogue
├── docs/                             Technical and user documentation
├── tests/
│   ├── bootstrap.php
│   ├── unit/                         Tokenizer, CSV and path tests
│   ├── integration/                  Disposable WordPress workflow tests
│   └── js/                           Selection and confirmation tests
├── tools/build-release.py            Reproducible ZIP and translation template
├── composer.json / composer.lock     Development dependencies only
├── package.json                      Node test command, no dependencies
├── phpcs.xml.dist                    Coding standards configuration
├── phpunit.xml.dist                  Pure unit suite
└── phpunit.integration.xml.dist      WordPress integration suite
```

## Uninstall

Deactivation stops current-site scheduled work and retains all media and recovery
data. Uninstall preserves data by default. To remove plugin tables/options on a
single site, restore or purge all recovery records first, then explicitly define
`SMAO_REMOVE_DATA` as `true` before uninstalling. If a recovery record remains,
the uninstall routine preserves everything. Multisite uninstall always retains
site data for deliberate per-site maintenance. Original uploads and generated
alternates are never deleted by uninstall.


## Live workspace (1.1.0)

Live discovery counts, reference evidence, image previews, phase progress and recent activity refresh after each batch. Reports refresh automatically and preserve selections. Progress estimates attachment and source-row work, not elapsed time; only finalization reaches 100%. Older scans without totals show indeterminate progress until restarted.

Expert controls set attachments per batch (1-200), source rows per batch (1-100), cooperative time budget (1-8 seconds), refresh interval (1-15 seconds), and browser processing. One source record or image operation may exceed the time budget. The browser advances short batches while its tab is open (background tabs may be throttled); WP-Cron handles work after closing the tab. Runtime tuning does not invalidate coverage. Refreshing never starts scans or queues file actions. All live endpoints require administrator permissions and REST nonces. Bulk actions retain review acknowledgement, typed confirmation and a maximum of 50 files.


## Scan and remove workflow (1.2.0)

Open **Remove unused images** to see all prerequisites. Confirm the custom/external usage review and start a fresh scan. The removal control stays visible and explains when completion, unchanged content, scope review, or private storage is missing. Select eligible rows, acknowledge review/backup, and choose **Remove selected (keep recovery copy)**. Confirm by typing QUARANTINE. Files leave uploads; their WordPress attachment records remain for audit and recovery. Recovery copies can be restored or permanently purged after the configured retention period.

In **Settings > Private recovery storage**, save an existing writable absolute folder outside all public web roots. This avoids editing wp-config.php; SMAO_VAULT_DIR remains an optional administrative override. Do not change storage folders while recovery journals depend on the existing folder.

Processing runs independently of report refreshes. Source scanning handles up to ten source pages per request within the cooperative time budget (100 rows per page by default). Checkpoints persist during batches; report errors do not block work. Status distinguishes stalled checkpoints from successful status connections. Use Retry processing after a network failure, or Restart fresh scan for stale/incomplete scans. Background browser tabs may be throttled; closed tabs rely on WP-Cron and site traffic. No guaranteed completion time is implied.
