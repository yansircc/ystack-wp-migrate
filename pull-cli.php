<?php
/**
 * WP Migrate Lite — CLI Pull (WP-CLI thin wrapper)
 *
 * Usage: MIGRATE_WORKER=URL MIGRATE_TOKEN=TOKEN ... wp eval-file pull-cli.php
 *   or:  wp eval-file pull-cli.php -- --worker=URL --token=TOKEN ...
 */

// Parse args: env vars take precedence, CLI args as fallback
$args = [];
foreach ($GLOBALS['argv'] ?? [] as $arg) {
    if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) $args[$m[1]] = $m[2];
}

$worker       = rtrim(getenv('MIGRATE_WORKER') ?: ($args['worker'] ?? ''), '/');
$token        = getenv('MIGRATE_TOKEN') ?: ($args['token'] ?? '');
$site_id      = getenv('MIGRATE_SITE_ID') ?: ($args['site-id'] ?? '');
$batch_id     = getenv('MIGRATE_BATCH_ID') ?: ($args['batch-id'] ?? '');
$search       = getenv('MIGRATE_SEARCH') ?: ($args['search'] ?? '');
$replace      = getenv('MIGRATE_REPLACE') ?: ($args['replace'] ?? '');
$search_path  = getenv('MIGRATE_SEARCH_PATH') ?: ($args['search-path'] ?? '');
$replace_path = getenv('MIGRATE_REPLACE_PATH') ?: ($args['replace-path'] ?? '');

if (!$worker || !$token || !$site_id || !$batch_id || !$search || !$replace) {
    WP_CLI::error('Required: --worker, --token, --site-id, --batch-id, --search, --replace');
}

require_once __DIR__ . '/includes/class-db.php';
require_once __DIR__ . '/includes/class-pull-engine.php';

global $wpdb;
$tmp_dir = ML_DB::storage_dir() . '/tmp';
$engine = new ML_Pull_Engine($wpdb->dbh, $wpdb->prefix, WP_CONTENT_DIR, $tmp_dir);

// Build replacement pairs
$pairs = [[$search, $replace]];
$http = str_replace('https://', 'http://', $search);
if ($http !== $search) $pairs[] = [$http, $replace];
if ($search_path && $replace_path) $pairs[] = [$search_path, $replace_path];

try {
    WP_CLI::log('=== Download ===');
    $dl = $engine->download($worker, $token, $site_id, $batch_id);
    foreach ($dl as $line) WP_CLI::log("  {$line}");

    WP_CLI::log('=== Import DB ===');
    $count = $engine->import_db();
    WP_CLI::log("  {$count} statements");

    WP_CLI::log('=== Extract ===');
    $ex = $engine->extract();
    foreach ($ex as $line) WP_CLI::log("  {$line}");

    WP_CLI::log('=== Search-replace ===');
    $total = $engine->search_replace($pairs);
    WP_CLI::log("  {$total} replacements");

    WP_CLI::log('=== Flush ===');
    $engine->flush();
    wp_cache_flush();

    $engine->cleanup();
    WP_CLI::success('Migration complete. Log in with source site credentials.');
} catch (Throwable $e) {
    WP_CLI::error($e->getMessage());
}
