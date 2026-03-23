# YStack WP Migrate

Push/Pull WordPress sites via S3-compatible storage (Cloudflare R2).

One-click push from source site, one-command (or one-click installer) pull on target site.

## Requirements

- PHP 8.0+
- WordPress 6.0+
- `curl` and `ZipArchive` PHP extensions
- A Cloudflare R2 bucket with a Worker proxy (see [Setup](#r2-setup))
- **Pull via CLI**: WP-CLI on the target site
- **Pull via Installer**: No extra requirements — just upload `installer.php`

## Installation

1. Download or clone this repo into `wp-content/plugins/ystack-wp-migrate/`
2. Activate the plugin in WordPress admin
3. Configure R2 credentials (see below)

## R2 Setup

Add to `wp-config.php` on **both** source and target sites:

```php
define('YSWM_R2_WORKER', 'https://your-worker.workers.dev');
define('YSWM_R2_TOKEN', 'your-bearer-token');
```

You need a Cloudflare Worker that proxies PUT/GET/DELETE requests to an R2 bucket with bearer token auth. A minimal Worker example is in [`docs/worker-example.js`](docs/worker-example.js) *(coming soon)*.

## Usage

### Push (source site)

1. Go to **Tools → YStack WP Migrate**
2. Click **Push Full Site**
3. Copy the migration code from the output

### Pull — Option A: WP-CLI (target site)

Run on the target site:

```bash
wp eval-file wp-content/plugins/ystack-wp-migrate/pull-cli.php -- --code='MIGRATION_CODE'
```

### Pull — Option B: Installer (target site)

1. Click **Download installer.php** (migration code + R2 credentials are pre-baked)
2. Upload `installer.php` to the target site's root directory
3. Open `https://target-site.com/installer.php` in your browser
4. Click **Start Migration**
5. The installer self-deletes after completion

## What gets migrated

- Database (all tables, prefix-remapped)
- Uploads (`wp-content/uploads/`)
- Themes (`wp-content/themes/`)
- Plugins (`wp-content/plugins/`)
- Serialization-aware search-replace (URLs + file paths)

## Configuration

| Constant | Required | Description |
|---|---|---|
| `YSWM_R2_WORKER` | Yes | Cloudflare Worker URL |
| `YSWM_R2_TOKEN` | Yes | Bearer token for Worker auth |
| `YSWM_STORAGE_DIR` | No | Override migration temp storage path |

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
