<?php
/**
 * WP Migrate Lite — CLI Pull
 *
 * Usage: wp eval-file pull-cli.php -- --site-id=ID --batch-id=BATCH --search=URL --replace=URL
 *   Optional: --search-path=PATH --replace-path=PATH --worker=URL --token=TOKEN
 *
 * Worker URL and token are built-in; only override for custom R2 setups.
 */

$args = [];
foreach ($GLOBALS['argv'] ?? [] as $arg) {
    if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) $args[$m[1]] = $m[2];
}

require_once __DIR__ . '/includes/class-db.php';
require_once __DIR__ . '/includes/class-r2.php';
require_once __DIR__ . '/includes/class-pull-engine.php';

$worker       = rtrim(getenv('MIGRATE_WORKER') ?: ($args['worker'] ?? ML_R2::default_worker()), '/');
$token        = getenv('MIGRATE_TOKEN') ?: ($args['token'] ?? ML_R2::default_token());
$site_id      = getenv('MIGRATE_SITE_ID') ?: ($args['site-id'] ?? '');
$batch_id     = getenv('MIGRATE_BATCH_ID') ?: ($args['batch-id'] ?? '');
$search       = getenv('MIGRATE_SEARCH') ?: ($args['search'] ?? '');
$replace      = getenv('MIGRATE_REPLACE') ?: ($args['replace'] ?? '');
$search_path  = getenv('MIGRATE_SEARCH_PATH') ?: ($args['search-path'] ?? '');
$replace_path = getenv('MIGRATE_REPLACE_PATH') ?: ($args['replace-path'] ?? '');

if (!$site_id || !$batch_id || !$search || !$replace) {
    WP_CLI::error('Required: --site-id, --batch-id, --search, --replace');
}

global $wpdb;
$tmp_dir = ML_DB::storage_dir() . '/tmp';
$engine = new ML_Pull_Engine($wpdb->dbh, $wpdb->prefix, WP_CONTENT_DIR, $tmp_dir);

$pairs = [[$search, $replace]];
$http = str_replace('https://', 'http://', $search);
if ($http !== $search) $pairs[] = [$http, $replace];
if ($search_path && $replace_path) $pairs[] = [$search_path, $replace_path];

try {
    WP_CLI::log('=== Download ===');
    foreach ($engine->download($worker, $token, $site_id, $batch_id) as $line) WP_CLI::log("  {$line}");

    WP_CLI::log('=== Import DB ===');
    WP_CLI::log('  ' . $engine->import_db() . ' statements');

    WP_CLI::log('=== Extract ===');
    foreach ($engine->extract() as $line) WP_CLI::log("  {$line}");

    WP_CLI::log('=== Search-replace ===');
    WP_CLI::log('  ' . $engine->search_replace($pairs) . ' replacements');

    WP_CLI::log('=== Flush ===');
    $engine->flush();
    wp_cache_flush();

    // Cleanup R2 artifacts
    WP_CLI::log('=== Cleanup R2 ===');
    $r2 = new ML_R2($worker, $token);
    $prefix = "{$site_id}/{$batch_id}";
    foreach (['manifest.json', 'dump.sql', 'uploads.zip', 'themes.zip', 'plugins.zip'] as $f) {
        $r2->delete("{$prefix}/{$f}");
    }
    WP_CLI::log('  Batch artifacts deleted from R2');

    $engine->cleanup();
    WP_CLI::success('Migration complete. Log in with source site credentials.');
} catch (Throwable $e) {
    WP_CLI::error($e->getMessage());
}
