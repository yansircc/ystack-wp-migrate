<?php
/**
 * mu-plugin deployer.
 *
 * After DB import, the main plugin is "deactivated" (active_plugins changed).
 * The mu-plugin survives because mu-plugins load unconditionally.
 * It handles post-import AJAX steps using a file-based token for auth.
 *
 * The mu-plugin code is self-contained (no dependencies on main plugin classes)
 * and deletes itself after the flush step completes.
 */
class ML_MU_Deployer {

    public static function deploy(): void {
        $mu_dir = WP_CONTENT_DIR . '/mu-plugins';
        if (!is_dir($mu_dir)) mkdir($mu_dir, 0755, true);
        file_put_contents($mu_dir . '/migrate-lite-pull.php', self::code());
    }

    private static function code(): string {
        return <<<'PHP'
<?php
/**
 * Migrate Lite Pull Handler (mu-plugin)
 * Auto-deployed before DB import, auto-deleted after flush.
 * Handles AJAX steps that run after DB import destroys the normal plugin session.
 */
add_action('wp_ajax_migrate_lite_pull_step', 'ml_mu_handle');
add_action('wp_ajax_nopriv_migrate_lite_pull_step', 'ml_mu_handle');

function ml_mu_handle() {
    $token_file = WP_CONTENT_DIR . '/.migrate-pull-token';
    if (!file_exists($token_file)) return;
    if (($_POST['pull_token'] ?? '') !== trim(file_get_contents($token_file))) return;

    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    $step = sanitize_text_field($_POST['step'] ?? '');

    switch ($step) {
        case 'extract':
            $tmp = WP_CONTENT_DIR . '/.migrate-tmp';
            $r = [];
            foreach (['uploads', 'themes', 'plugins'] as $d) {
                $zp = "{$tmp}/migrate-{$d}.zip";
                if (!file_exists($zp)) { $r[] = "{$d}: skipped"; continue; }
                $live    = WP_CONTENT_DIR . "/{$d}";
                $staging = WP_CONTENT_DIR . "/.migrate-staging-{$d}";
                $backup  = WP_CONTENT_DIR . "/.migrate-backup-{$d}";
                if (is_dir($staging)) ml_mu_rmdir($staging);
                if (is_dir($backup))  ml_mu_rmdir($backup);
                mkdir($staging, 0755, true);

                // Open + extract + validate
                $zip = new ZipArchive();
                if ($zip->open($zp) !== true) {
                    ml_mu_rmdir($staging);
                    $r[] = "{$d}: zip open failed";
                    continue;
                }
                $ok = $zip->extractTo($staging);
                $num = $zip->numFiles;
                $zip->close();
                unlink($zp);

                if (!$ok || $num === 0) {
                    ml_mu_rmdir($staging);
                    $r[] = "{$d}: extract failed, live dir preserved";
                    continue;
                }

                // Atomic swap: only if staging looks valid
                if (is_dir($live)) {
                    if (!@rename($live, $backup)) {
                        ml_mu_rmdir($staging);
                        $r[] = "{$d}: swap failed, live dir preserved";
                        continue;
                    }
                }
                if (!@rename($staging, $live)) {
                    // Rollback: restore backup to live
                    if (is_dir($backup)) @rename($backup, $live);
                    $r[] = "{$d}: swap failed, rolled back";
                    continue;
                }
                if (is_dir($backup)) ml_mu_rmdir($backup);
                $r[] = "{$d}: {$num} files";
            }
            wp_send_json_success(implode(', ', $r));
            break;

        case 'get_tables':
            global $wpdb;
            // Only owned tables — same boundary as export
            $all = $wpdb->get_col("SHOW TABLES");
            $prefix = $wpdb->prefix;
            $owned = array_values(array_filter($all, function($t) use ($prefix) { return strpos($t, $prefix) === 0; }));
            wp_send_json_success($owned);
            break;

        case 'replace_table':
            global $wpdb;
            $table = sanitize_text_field($_POST['table'] ?? '');
            $src   = esc_url_raw($_POST['source_url'] ?? '');
            $sp    = sanitize_text_field($_POST['source_path'] ?? '');
            $lu    = home_url();
            $lp    = rtrim(ABSPATH, '/');

            $reps = [[$src, $lu]];
            $h = str_replace('https://', 'http://', $src);
            if ($h !== $src) $reps[] = [$h, $lu];
            if ($sp) $reps[] = [$sp, $lp];

            // Inline text columns + PK detection
            $cols = [];
            foreach ($wpdb->get_results("SHOW COLUMNS FROM `{$table}`") as $c) {
                if (preg_match('/(char|text|blob|enum|set)/i', $c->Type)) $cols[] = $c->Field;
            }
            if (!$cols) { wp_send_json_success("{$table}: 0 replacements"); return; }

            $pks = [];
            foreach ($wpdb->get_results("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'") as $k) {
                $pks[] = $k->Column_name;
            }
            if (!$pks) { wp_send_json_success("{$table}: no PK"); return; }

            $tot = 0;
            $off = 0;
            while (true) {
                $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT 1000 OFFSET {$off}", ARRAY_A);
                if (!$rows) break;
                foreach ($rows as $row) {
                    $upd = [];
                    foreach ($cols as $c) {
                        if (!isset($row[$c]) || $row[$c] === null) continue;
                        $need = false;
                        foreach ($reps as $p) { if (strpos($row[$c], $p[0]) !== false) { $need = true; break; } }
                        if (!$need) continue;
                        $n = $row[$c];
                        foreach ($reps as $p) $n = ml_mu_rr($p[0], $p[1], $n);
                        if ($n !== $row[$c]) $upd[$c] = $n;
                    }
                    if ($upd) {
                        $s = [];
                        foreach ($upd as $c => $v) $s[] = "`{$c}` = \"" . ml_mu_esc($v) . "\"";
                        $w = [];
                        foreach ($pks as $pk) $w[] = "`{$pk}` = \"" . ml_mu_esc($row[$pk]) . "\"";
                        $ok = mysqli_query($wpdb->dbh, "UPDATE `{$table}` SET " . implode(', ', $s) . " WHERE " . implode(' AND ', $w));
                        if ($ok === false) wp_send_json_error("{$table}: SQL error — " . mysqli_error($wpdb->dbh));
                        $tot += count($upd);
                    }
                }
                $off += 1000;
            }
            wp_send_json_success("{$table}: {$tot} replacements");
            break;

        case 'flush':
            global $wpdb;
            // Restore plugins
            $of = WP_CONTENT_DIR . '/.migrate-original-plugins';
            if (file_exists($of)) {
                $escaped = mysqli_real_escape_string($wpdb->dbh, file_get_contents($of));
                mysqli_query($wpdb->dbh, "UPDATE {$wpdb->options} SET option_value = '{$escaped}' WHERE option_name = 'active_plugins'");
                unlink($of);
            }
            wp_cache_flush();
            mysqli_query($wpdb->dbh, "DELETE FROM {$wpdb->options} WHERE option_name = 'rewrite_rules'");
            mysqli_query($wpdb->dbh, "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");
            // Clean up
            if (file_exists($token_file)) unlink($token_file);
            $tmp = WP_CONTENT_DIR . '/.migrate-tmp';
            if (is_dir($tmp)) { array_map('unlink', glob("{$tmp}/*")); @rmdir($tmp); }
            @unlink(__FILE__);
            wp_send_json_success('Done — reload to see migrated site');
            break;

        default:
            return; // Unknown step — let main plugin handle
    }
}

/** Recursive serialization-aware replace (standalone, no class deps). */
function ml_mu_rr($s, $r, $d) {
    if (is_string($d) && is_serialized($d)) {
        if (preg_match('/r:\d+;/i', $d)) return $d;
        $u = @unserialize($d, ['allowed_classes' => false]);
        if ($u !== false || $d === 'b:0;') return serialize(ml_mu_rr($s, $r, $u));
    }
    if (is_string($d) && strlen($d) > 1 && ($d[0] === '{' || $d[0] === '[')) {
        $j = json_decode($d, true);
        if (is_array($j)) return json_encode(ml_mu_rr($s, $r, $j), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    if (is_array($d))  { foreach ($d as $k => $v) $d[$k] = ml_mu_rr($s, $r, $v); return $d; }
    if (is_object($d)) { try { if (!(new ReflectionClass($d))->isCloneable()) return $d; } catch (ReflectionException $e) { return $d; } foreach (get_object_vars($d) as $k => $v) { if (!is_int($k)) $d->$k = ml_mu_rr($s, $r, $v); } return $d; }
    if (is_string($d)) return str_replace($s, $r, $d);
    return $d;
}

/** MySQL escape without $wpdb (standalone). */
function ml_mu_esc($i) {
    return is_string($i)
        ? str_replace(['\\', "\0", "\n", "\r", "'", '"', "\x1a"], ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'], $i)
        : $i;
}

/** Recursively delete a directory. */
function ml_mu_rmdir($dir) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($dir);
}
PHP;
    }
}
