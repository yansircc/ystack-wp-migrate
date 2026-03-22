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
        if (!is_dir($mu_dir) && !mkdir($mu_dir, 0755, true)) {
            throw new RuntimeException('Failed to create mu-plugins directory');
        }
        $resolved_storage = ML_DB::storage_dir();
        $code = str_replace('__ML_STORAGE_DIR__', addslashes($resolved_storage), self::code());

        // Atomic deploy: write to temp in same dir, then rename into place.
        // Prevents WordPress from autoloading a truncated PHP file.
        $live = $mu_dir . '/migrate-lite-pull.php';
        $tmp  = $mu_dir . '/migrate-lite-pull.php.tmp';
        ML_DB::write_file($tmp, $code);
        if (!@rename($tmp, $live)) {
            @unlink($tmp);
            throw new RuntimeException('Atomic rename failed for mu-plugin');
        }
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

function ml_mu_storage() {
    // Injected at deploy time by ML_MU_Deployer::deploy()
    return '__ML_STORAGE_DIR__';
}

function ml_mu_handle() {
    // Route on step first — only handle owned steps
    $step = sanitize_text_field($_POST['step'] ?? '');
    $owned_steps = ['import_db', 'prepare', 'extract', 'get_tables', 'replace_table', 'flush'];
    if (!in_array($step, $owned_steps, true)) return;

    // Owned step — require token auth
    $storage = ml_mu_storage();
    $token_file = $storage . '/pull-token';
    if (!file_exists($token_file)) {
        wp_send_json_error('Pull session missing — restart migration from Download');
    }
    $stored = @file_get_contents($token_file);
    if ($stored === false) wp_send_json_error('Cannot read pull token');
    if (($_POST['pull_token'] ?? '') !== trim($stored)) {
        wp_send_json_error('Invalid pull token');
    }

    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    switch ($step) {
        case 'import_db':
            global $wpdb;
            $dump = ml_mu_storage() . '/tmp/migrate-dump.sql';
            if (!file_exists($dump)) wp_send_json_error('dump.sql not found');
            $prefix = $wpdb->prefix;
            $fp = @fopen($dump, 'r');
            if ($fp === false) wp_send_json_error('Cannot open dump file');
            $query = '';
            $count = 0;
            while (($line = fgets($fp)) !== false) {
                $trimmed = trim($line);
                if ($trimmed === '' || strpos($trimmed, '--') === 0) continue;
                $line = preg_replace_callback(
                    '/^(DROP\s+TABLE(?:\s+IF\s+EXISTS)?\s+|CREATE\s+TABLE\s+|INSERT\s+INTO\s+)`([^`]+)`/i',
                    function($m) use ($prefix) { return $m[1] . '`' . $prefix . $m[2] . '`'; },
                    $line
                );
                $query .= $line;
                if (substr($trimmed, -1) === ';') {
                    $result = mysqli_query($wpdb->dbh, $query);
                    if ($result === false) {
                        fclose($fp);
                        wp_send_json_error("SQL error at statement {$count}: " . mysqli_error($wpdb->dbh));
                    }
                    $query = '';
                    $count++;
                }
            }
            if (ferror($fp)) { fclose($fp); wp_send_json_error("Read error at statement {$count}"); }
            fclose($fp);
            @unlink($dump);
            wp_send_json_success("Imported {$count} statements");
            break;

        case 'prepare':
            // Post-import: restore operator admin access + park plugins.
            // Consumes state files written by download step BEFORE the cutover.
            global $wpdb;

            // Restore operator
            $op_file = ml_mu_storage() . '/operator';
            if (!file_exists($op_file)) wp_send_json_error('Operator state file missing');
            $op_data = @file_get_contents($op_file);
            if ($op_data === false) wp_send_json_error('Cannot read operator state');
            $op = json_decode($op_data, true);
            if (!$op || !isset($op['login'])) wp_send_json_error('Invalid operator state');

            // Upsert operator user
            $esc_login = mysqli_real_escape_string($wpdb->dbh, $op['login']);
            $res = mysqli_query($wpdb->dbh, "SELECT ID FROM {$wpdb->users} WHERE user_login = '{$esc_login}'");
            if ($res === false) wp_send_json_error('DB error: ' . mysqli_error($wpdb->dbh));
            $existing = mysqli_fetch_assoc($res);
            $esc_pass  = mysqli_real_escape_string($wpdb->dbh, $op['pass']);
            $esc_email = mysqli_real_escape_string($wpdb->dbh, $op['email']);

            if ($existing) {
                $uid = (int)$existing['ID'];
                $ok = mysqli_query($wpdb->dbh, "UPDATE {$wpdb->users} SET user_pass = '{$esc_pass}', user_email = '{$esc_email}' WHERE ID = {$uid}");
                if ($ok === false) wp_send_json_error('Failed to update operator: ' . mysqli_error($wpdb->dbh));
            } else {
                $ok = mysqli_query($wpdb->dbh, "INSERT INTO {$wpdb->users} (user_login, user_pass, user_email, user_registered) VALUES ('{$esc_login}', '{$esc_pass}', '{$esc_email}', NOW())");
                if ($ok === false) wp_send_json_error('Failed to create operator: ' . mysqli_error($wpdb->dbh));
                $uid = mysqli_insert_id($wpdb->dbh);
                if (!$uid) wp_send_json_error('Failed to get operator ID');
            }

            // Ensure admin caps (delete + insert as atomic unit)
            $cap_key = $wpdb->prefix . 'capabilities';
            $ok0 = mysqli_query($wpdb->dbh, "DELETE FROM {$wpdb->usermeta} WHERE user_id = {$uid} AND meta_key IN ('{$cap_key}', '{$wpdb->prefix}user_level')");
            if ($ok0 === false) wp_send_json_error('Failed to clear old caps: ' . mysqli_error($wpdb->dbh));
            $ok1 = mysqli_query($wpdb->dbh, "INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) VALUES ({$uid}, '{$cap_key}', 'a:1:{s:13:\"administrator\";b:1;}')");
            $ok2 = mysqli_query($wpdb->dbh, "INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value) VALUES ({$uid}, '{$wpdb->prefix}user_level', '10')");
            if ($ok1 === false || $ok2 === false) wp_send_json_error('Failed to set admin caps: ' . mysqli_error($wpdb->dbh));

            // Park plugins: save source's list, activate only our plugin
            $bn_file = ml_mu_storage() . '/plugin-basename';
            if (!file_exists($bn_file)) wp_send_json_error('Plugin basename file missing');
            $plugin_bn = trim(@file_get_contents($bn_file));
            if (!$plugin_bn) wp_send_json_error('Cannot read plugin basename');

            $ap_res = mysqli_query($wpdb->dbh, "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins'");
            if ($ap_res === false) wp_send_json_error('Failed to read active_plugins: ' . mysqli_error($wpdb->dbh));
            $ap_row = mysqli_fetch_assoc($ap_res);
            $orig_plugins = $ap_row ? $ap_row['option_value'] : 'a:0:{}';
            $op_path = ml_mu_storage() . '/original-plugins';
            $op_len = strlen($orig_plugins);
            $op_written = @file_put_contents($op_path, $orig_plugins);
            if ($op_written === false || $op_written < $op_len) {
                wp_send_json_error('Failed to save source plugin list');
            }

            $parked = serialize([$plugin_bn]);
            $ok = mysqli_query($wpdb->dbh, sprintf(
                "UPDATE {$wpdb->options} SET option_value = '%s' WHERE option_name = 'active_plugins'",
                mysqli_real_escape_string($wpdb->dbh, $parked)
            ));
            if ($ok === false) wp_send_json_error('Failed to park plugins: ' . mysqli_error($wpdb->dbh));

            // Commit: all prepare work succeeded — now clean up state files
            @unlink($op_file);
            @unlink($bn_file);

            wp_send_json_success('Post-import prepare complete');
            break;

        case 'extract':
            $tmp = ml_mu_storage() . '/tmp';
            $r = [];
            foreach (['uploads', 'themes', 'plugins'] as $d) {
                $zp = "{$tmp}/migrate-{$d}.zip";
                if (!file_exists($zp)) { $r[] = "{$d}: skipped"; continue; }
                $live    = WP_CONTENT_DIR . "/{$d}";
                $staging = ml_mu_storage() . "/staging-{$d}";
                $backup  = ml_mu_storage() . "/backup-{$d}";
                if (is_dir($staging)) ml_mu_rmdir($staging);
                if (is_dir($backup))  ml_mu_rmdir($backup);
                if (!@mkdir($staging, 0755, true)) {
                    wp_send_json_error("{$d}: cannot create staging directory");
                }

                // Open + extract + validate
                $zip = new ZipArchive();
                if ($zip->open($zp) !== true) {
                    ml_mu_rmdir($staging);
                    wp_send_json_error("{$d}: zip open failed");
                }
                $ok = $zip->extractTo($staging);
                $num = $zip->numFiles;
                $zip->close();
                unlink($zp);

                if (!$ok) {
                    ml_mu_rmdir($staging);
                    wp_send_json_error("{$d}: extract failed");
                }

                // Atomic swap: only if staging looks valid
                if (is_dir($live)) {
                    if (!@rename($live, $backup)) {
                        ml_mu_rmdir($staging);
                        wp_send_json_error("{$d}: swap failed, live dir preserved");
                    }
                }
                if (!@rename($staging, $live)) {
                    $rolled_back = is_dir($backup) && @rename($backup, $live);
                    if ($rolled_back) {
                        wp_send_json_error("{$d}: swap failed, rolled back to previous state");
                    } else {
                        wp_send_json_error("{$d}: CRITICAL — swap and rollback both failed, {$d}/ directory may be missing");
                    }
                }
                if (is_dir($backup)) ml_mu_rmdir($backup);
                $r[] = "{$d}: {$num} files";
            }
            wp_send_json_success(implode(', ', $r));
            break;

        case 'get_tables':
            global $wpdb;
            $all = $wpdb->get_col("SHOW TABLES");
            if ($all === null || $wpdb->last_error) {
                wp_send_json_error('DB error listing tables: ' . $wpdb->last_error);
            }
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
            $col_results = $wpdb->get_results("SHOW COLUMNS FROM `{$table}`");
            if ($col_results === null || $wpdb->last_error) {
                wp_send_json_error("{$table}: DB error reading columns — " . $wpdb->last_error);
            }
            $cols = [];
            foreach ($col_results as $c) {
                if (preg_match('/(char|text|blob|enum|set)/i', $c->Type)) $cols[] = $c->Field;
            }
            if (!$cols) { wp_send_json_success("{$table}: 0 replacements"); return; }

            $key_results = $wpdb->get_results("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
            if ($key_results === null || $wpdb->last_error) {
                wp_send_json_error("{$table}: DB error reading keys — " . $wpdb->last_error);
            }
            $pks = [];
            foreach ($key_results as $k) {
                $pks[] = $k->Column_name;
            }
            if (!$pks) { wp_send_json_success("{$table}: no PK"); return; }

            $tot = 0;
            $off = 0;
            while (true) {
                $rows = $wpdb->get_results("SELECT * FROM `{$table}` LIMIT 1000 OFFSET {$off}", ARRAY_A);
                if ($rows === null && $wpdb->last_error) {
                    wp_send_json_error("{$table}: DB read error — " . $wpdb->last_error);
                }
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

            // Best-effort cleanup BEFORE commit (failure here is recoverable)
            wp_cache_flush();
            @mysqli_query($wpdb->dbh, "DELETE FROM {$wpdb->options} WHERE option_name = 'rewrite_rules'");
            @mysqli_query($wpdb->dbh, "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");

            // === COMMIT POINT: restore source site's active_plugins ===
            // After this, the main plugin is back in the activation list.
            // Everything after this is best-effort cleanup.
            $of = ml_mu_storage() . '/original-plugins';
            if (!file_exists($of)) {
                wp_send_json_error('Plugin state file missing — cannot restore active_plugins');
            }
            $pdata = @file_get_contents($of);
            if ($pdata === false) wp_send_json_error('Cannot read original plugin list');
            $escaped = mysqli_real_escape_string($wpdb->dbh, $pdata);
            $ok = mysqli_query($wpdb->dbh, "UPDATE {$wpdb->options} SET option_value = '{$escaped}' WHERE option_name = 'active_plugins'");
            if ($ok === false) wp_send_json_error('Failed to restore plugins: ' . mysqli_error($wpdb->dbh));
            @unlink($of);

            // Best-effort cleanup (control plane is already restored)
            @unlink($token_file);
            $tmp = ml_mu_storage() . '/tmp';
            if (is_dir($tmp)) { @array_map('unlink', glob("{$tmp}/*")); @rmdir($tmp); }
            @unlink(__FILE__);
            wp_send_json_success('Done — reload to see migrated site');
            break;

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
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dir);
}
PHP;
    }
}
