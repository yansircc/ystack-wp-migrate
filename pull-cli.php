<?php
/**
 * WP Migrate Lite — CLI Pull (single-process, no WordPress session dependency)
 *
 * Usage:
 *   wp eval-file pull-cli.php -- \
 *     --worker=URL --token=TOKEN \
 *     --site-id=ID --batch-id=BATCH \
 *     --search=https://old --replace=https://new \
 *     [--search-path=/old/path --replace-path=/new/path]
 */

// Parse args
$args = [];
foreach ($GLOBALS['argv'] ?? [] as $arg) {
    if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) $args[$m[1]] = $m[2];
}

$worker       = rtrim($args['worker'] ?? '', '/');
$token        = $args['token'] ?? '';
$site_id      = $args['site-id'] ?? '';
$batch_id     = $args['batch-id'] ?? '';
$search       = $args['search'] ?? '';
$replace      = $args['replace'] ?? '';
$search_path  = $args['search-path'] ?? '';
$replace_path = $args['replace-path'] ?? '';

if (!$worker || !$token || !$site_id || !$batch_id || !$search || !$replace) {
    WP_CLI::error('Required: --worker, --token, --site-id, --batch-id, --search, --replace');
}

// Load plugin classes
require_once WP_PLUGIN_DIR . '/wp-migrate-lite/includes/class-db.php';
require_once WP_PLUGIN_DIR . '/wp-migrate-lite/includes/class-r2.php';

global $wpdb;
$r2 = new ML_R2($worker, $token);
$prefix = "{$site_id}/{$batch_id}";
$tmp_dir = ML_DB::tmp_dir();

// ============================================================
// Step 1: Download manifest + artifacts
// ============================================================
WP_CLI::log('=== Download ===');

$manifest_path = "{$tmp_dir}/manifest.json";
$code = $r2->get("{$prefix}/manifest.json", $manifest_path);
if ($code !== 200) WP_CLI::error("Manifest not found (HTTP {$code})");

$manifest = json_decode(file_get_contents($manifest_path), true);
unlink($manifest_path);
if (!$manifest || ($manifest['batch_id'] ?? '') !== $batch_id) {
    WP_CLI::error('Invalid or mismatched manifest');
}

foreach ($manifest['artifacts'] as $f) {
    $dest = "{$tmp_dir}/migrate-{$f}";
    $code = $r2->get("{$prefix}/{$f}", $dest);
    if ($code !== 200) WP_CLI::error("Failed to download {$f} (HTTP {$code})");
    WP_CLI::log('  ' . $f . ': ' . round(filesize($dest) / 1048576, 1) . ' MB');
}

// ============================================================
// Step 2: Import DB (prefix-remapping, fail-stop)
// ============================================================
WP_CLI::log('=== Import DB ===');

$dump = "{$tmp_dir}/migrate-dump.sql";
$error = ML_DB::import($dump);
unlink($dump);
if ($error !== null) WP_CLI::error($error);
WP_CLI::log('  OK');

// ============================================================
// Step 3: Extract files (staging + atomic swap)
// ============================================================
WP_CLI::log('=== Extract ===');

foreach (['uploads', 'themes', 'plugins'] as $dir) {
    $zip_path = "{$tmp_dir}/migrate-{$dir}.zip";
    if (!file_exists($zip_path)) { WP_CLI::log("  {$dir}: skipped"); continue; }

    $live    = WP_CONTENT_DIR . "/{$dir}";
    $staging = ML_DB::storage_dir() . "/staging-{$dir}";
    $backup  = ML_DB::storage_dir() . "/backup-{$dir}";

    if (is_dir($staging)) rmdir_recursive($staging);
    if (is_dir($backup))  rmdir_recursive($backup);
    mkdir($staging, 0755, true);

    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) WP_CLI::error("{$dir}: zip open failed");
    $ok = $zip->extractTo($staging);
    $num = $zip->numFiles;
    $zip->close();
    unlink($zip_path);
    if (!$ok) { rmdir_recursive($staging); WP_CLI::error("{$dir}: extract failed"); }

    if (is_dir($live)) rename($live, $backup);
    rename($staging, $live);
    if (is_dir($backup)) rmdir_recursive($backup);

    WP_CLI::log("  {$dir}: {$num} files");
}

// ============================================================
// Step 4: Search-replace (serialization-aware, mysqli direct)
// ============================================================
WP_CLI::log('=== Search-replace ===');

$replacements = [[$search, $replace]];
$http = str_replace('https://', 'http://', $search);
if ($http !== $search) $replacements[] = [$http, $replace];
if ($search_path && $replace_path) $replacements[] = [$search_path, $replace_path];

$tables = ML_DB::owned_tables();
$grand_total = 0;

foreach ($tables as $table) {
    $columns = [];
    foreach ($wpdb->get_results("SHOW COLUMNS FROM `{$table}`") as $col) {
        if (preg_match('/(char|text|blob|enum|set)/i', $col->Type)) $columns[] = $col->Field;
    }
    if (empty($columns)) continue;

    $pk_cols = [];
    foreach ($wpdb->get_results("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'") as $k) {
        $pk_cols[] = $k->Column_name;
    }
    if (empty($pk_cols)) continue;

    $total = 0;
    $offset = 0;
    while (true) {
        $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT 1000 OFFSET {$offset}", ARRAY_A);
        if (empty($rows)) break;

        foreach ($rows as $row) {
            $updates = [];
            foreach ($columns as $col) {
                if (!isset($row[$col]) || $row[$col] === null) continue;
                $needs = false;
                foreach ($replacements as $p) { if (strpos($row[$col], $p[0]) !== false) { $needs = true; break; } }
                if (!$needs) continue;
                $new = $row[$col];
                foreach ($replacements as $p) $new = ml_recursive_replace($p[0], $p[1], $new);
                if ($new !== $row[$col]) $updates[$col] = $new;
            }
            if (!empty($updates)) {
                $set = []; foreach ($updates as $c => $v) $set[] = "`{$c}` = \"" . ML_DB::esc($v) . "\"";
                $where = []; foreach ($pk_cols as $pk) $where[] = "`{$pk}` = \"" . ML_DB::esc($row[$pk]) . "\"";
                mysqli_query($wpdb->dbh, "UPDATE `{$table}` SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $where));
                $total += count($updates);
            }
        }
        $offset += 1000;
    }
    if ($total > 0) WP_CLI::log("  {$table}: {$total}");
    $grand_total += $total;
}
WP_CLI::log("  Total: {$grand_total} replacements");

// ============================================================
// Step 5: Flush
// ============================================================
WP_CLI::log('=== Flush ===');

wp_cache_flush();
mysqli_query($wpdb->dbh, "DELETE FROM {$wpdb->options} WHERE option_name = 'rewrite_rules'");
mysqli_query($wpdb->dbh, "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");

// Clean tmp
$tmp = ML_DB::tmp_dir();
if (is_dir($tmp)) { @array_map('unlink', glob("{$tmp}/*")); @rmdir($tmp); }

WP_CLI::success('Migration complete. Log in with the source site credentials.');

// ============================================================
// Helpers
// ============================================================

function ml_recursive_replace($s, $r, $d) {
    if (is_string($d) && is_serialized($d)) {
        if (preg_match('/r:\d+;/i', $d)) return $d;
        $u = @unserialize($d, ['allowed_classes' => false]);
        if ($u !== false || $d === 'b:0;') return serialize(ml_recursive_replace($s, $r, $u));
    }
    if (is_string($d) && strlen($d) > 1 && ($d[0] === '{' || $d[0] === '[')) {
        $j = json_decode($d, true);
        if (is_array($j)) return json_encode(ml_recursive_replace($s, $r, $j), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    if (is_array($d)) { foreach ($d as $k => $v) $d[$k] = ml_recursive_replace($s, $r, $v); return $d; }
    if (is_object($d)) {
        try { if (!(new ReflectionClass($d))->isCloneable()) return $d; }
        catch (ReflectionException $e) { return $d; }
        foreach (get_object_vars($d) as $k => $v) { if (!is_int($k)) $d->$k = ml_recursive_replace($s, $r, $v); }
        return $d;
    }
    if (is_string($d)) return str_replace($s, $r, $d);
    return $d;
}

function rmdir_recursive(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dir);
}
