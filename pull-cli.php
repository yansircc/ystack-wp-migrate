<?php
/**
 * YStack WP Migrate — CLI Pull
 *
 * Usage:  wp eval-file pull-cli.php -- --code=MIGRATION_CODE
 *
 * The migration code (from Push output) contains site_id/batch_id/installer_token.
 * Source URL and path are read from the manifest; target values auto-detected.
 */

$args = [];
foreach ($GLOBALS['argv'] ?? [] as $arg) {
    if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) $args[$m[1]] = $m[2];
}

require_once __DIR__ . '/includes/class-db.php';
require_once __DIR__ . '/includes/class-r2.php';
require_once __DIR__ . '/includes/class-pull-engine.php';

$worker = rtrim(getenv('YSWM_R2_WORKER') ?: ($args['worker'] ?? YSWM_R2::worker()), '/');
$token  = getenv('YSWM_R2_TOKEN') ?: ($args['token'] ?? YSWM_R2::token());

$code = getenv('YSWM_CODE') ?: ($args['code'] ?? '');
if (!$code) {
    WP_CLI::error('Required: --code=MIGRATION_CODE');
}
$parts = explode('/', $code, 3);
if (count($parts) !== 3 || !$parts[0] || !$parts[1] || !$parts[2]) {
    WP_CLI::error('Invalid migration code format (expected: site_id/batch_id/token)');
}
$site_id         = $parts[0];
$batch_id        = $parts[1];
$installer_token = $parts[2];

global $wpdb;
$tmp_dir = YSWM_DB::storage_dir() . '/tmp';
$engine = new YSWM_Pull_Engine($wpdb->dbh, $wpdb->prefix, WP_CONTENT_DIR, $tmp_dir);

try {
    WP_CLI::log('=== Download ===');
    foreach ($engine->download($worker, $token, $site_id, $batch_id, $installer_token) as $line) {
        WP_CLI::log("  {$line}");
    }

    // Auto-derive search/replace from manifest + target site
    $manifest = json_decode(@file_get_contents($tmp_dir . '/manifest.json'), true) ?: [];
    $search       = $manifest['source_url'] ?? '';
    $replace      = home_url();
    $search_path  = $manifest['source_path'] ?? '';
    $replace_path = rtrim(ABSPATH, '/');

    if (!$search) {
        WP_CLI::error('Manifest missing source_url — cannot determine search/replace.');
    }

    $pairs = [[$search, $replace]];
    $http = str_replace('https://', 'http://', $search);
    if ($http !== $search) $pairs[] = [$http, $replace];
    if ($search_path && $replace_path) $pairs[] = [$search_path, $replace_path];

    WP_CLI::log('=== Import DB ===');
    WP_CLI::log('  ' . $engine->import_db() . ' statements');

    WP_CLI::log('=== Extract ===');
    foreach ($engine->extract() as $line) WP_CLI::log("  {$line}");

    WP_CLI::log('=== Search-replace ===');
    WP_CLI::log("  {$search} → {$replace}");
    if ($search_path && $replace_path) WP_CLI::log("  {$search_path} → {$replace_path}");
    WP_CLI::log('  ' . $engine->search_replace($pairs) . ' replacements');

    WP_CLI::log('=== Flush ===');
    $engine->flush();
    wp_cache_flush();

    // Cleanup R2 artifacts
    WP_CLI::log('=== Cleanup R2 ===');
    $r2 = new YSWM_R2($worker, $token);
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
