<?php
/**
 * WP Migrate Lite — Standalone Installer
 *
 * Upload this file to the target site's root directory,
 * then access it via browser: https://target-site.com/installer.php
 *
 * Does NOT require WordPress or WP-CLI.
 * Reads wp-config.php for DB credentials, connects via mysqli directly.
 * Self-deletes after successful migration.
 */

// ============================================================
// Security: one-time access token (set before uploading)
// ============================================================
define('INSTALLER_TOKEN', ''); // Set a random string before uploading

// ============================================================
// Bootstrap
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', '0');
set_time_limit(0);
ini_set('memory_limit', '512M');

$self_path = __FILE__;
$site_root = dirname($self_path);

// Find wp-config.php
$wp_config_path = null;
if (file_exists($site_root . '/wp-config.php')) {
    $wp_config_path = $site_root . '/wp-config.php';
} elseif (file_exists(dirname($site_root) . '/wp-config.php')) {
    $wp_config_path = dirname($site_root) . '/wp-config.php';
}

if (!$wp_config_path) {
    die(json_encode(['error' => 'wp-config.php not found. Place installer.php in WordPress root.']));
}

// Extract DB credentials from wp-config.php without loading WordPress
$config_content = file_get_contents($wp_config_path);
$db = [];
foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET'] as $const) {
    if (preg_match("/define\s*\(\s*['\"]" . $const . "['\"]\s*,\s*['\"]([^'\"]*)/", $config_content, $m)) {
        $db[$const] = $m[1];
    }
}
if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)/', $config_content, $m)) {
    $db['prefix'] = $m[1];
} else {
    $db['prefix'] = 'wp_';
}

if (empty($db['DB_NAME']) || empty($db['DB_USER'])) {
    die(json_encode(['error' => 'Cannot parse DB credentials from wp-config.php']));
}

// Detect wp-content path
$wp_content_dir = $site_root . '/wp-content';
if (preg_match("/define\s*\(\s*['\"]WP_CONTENT_DIR['\"]\s*,\s*(.+?)\s*\)/", $config_content, $m)) {
    // Try to resolve simple path expressions
    $expr = trim($m[1], "'\"\t\n\r ");
    if (is_dir($expr)) $wp_content_dir = $expr;
}

// ============================================================
// Handle requests
// ============================================================
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_ajax) {
    header('Content-Type: application/json');

    // Token auth
    if (INSTALLER_TOKEN === '') {
        die(json_encode(['error' => 'INSTALLER_TOKEN not configured']));
    }
    if (($_POST['token'] ?? '') !== INSTALLER_TOKEN) {
        die(json_encode(['error' => 'Invalid token']));
    }

    $step = $_POST['step'] ?? '';

    // Connect to DB
    $mysqli = @new mysqli($db['DB_HOST'], $db['DB_USER'], $db['DB_PASSWORD'], $db['DB_NAME']);
    if ($mysqli->connect_error) {
        die(json_encode(['error' => 'DB connection failed: ' . $mysqli->connect_error]));
    }
    $mysqli->set_charset('utf8mb4');
    $prefix = $db['prefix'];

    $tmp_dir = $site_root . '/.migrate-installer-tmp';
    if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0755, true);

    try {
        switch ($step) {
            case 'download':
                $worker = $_POST['worker'] ?? '';
                $token_r2 = $_POST['r2_token'] ?? '';
                $site_id = $_POST['site_id'] ?? '';
                $batch_id = $_POST['batch_id'] ?? '';

                if (!$worker || !$token_r2 || !$site_id || !$batch_id) {
                    throw new RuntimeException('Missing parameters');
                }

                $r2_prefix = "{$site_id}/{$batch_id}";

                // Download manifest
                $manifest_raw = r2_get($worker, $token_r2, "{$r2_prefix}/manifest.json");
                $manifest = json_decode($manifest_raw, true);
                if (!$manifest || ($manifest['batch_id'] ?? '') !== $batch_id) {
                    throw new RuntimeException('Invalid manifest');
                }
                if (($manifest['site_id'] ?? '') !== $site_id) {
                    throw new RuntimeException('Manifest site_id mismatch');
                }

                // Download artifacts
                $results = [];
                foreach ($manifest['artifacts'] as $f) {
                    $dest = "{$tmp_dir}/{$f}";
                    r2_download($worker, $token_r2, "{$r2_prefix}/{$f}", $dest);
                    $results[] = $f . ': ' . round(filesize($dest) / 1048576, 1) . 'MB';
                }

                echo json_encode(['ok' => true, 'msg' => implode(', ', $results)]);
                break;

            case 'import':
                $dump = "{$tmp_dir}/dump.sql";
                if (!file_exists($dump)) throw new RuntimeException('dump.sql not found');

                $fp = @fopen($dump, 'r');
                if (!$fp) throw new RuntimeException('Cannot open dump');
                $query = '';
                $count = 0;

                while (($line = fgets($fp)) !== false) {
                    $trimmed = trim($line);
                    if ($trimmed === '' || strpos($trimmed, '--') === 0) continue;

                    // Prefix remap (statement-level only)
                    $line = preg_replace_callback(
                        '/^(DROP\s+TABLE(?:\s+IF\s+EXISTS)?\s+|CREATE\s+TABLE\s+|INSERT\s+INTO\s+)`([^`]+)`/i',
                        function ($m) use ($prefix) { return $m[1] . '`' . $prefix . $m[2] . '`'; },
                        $line
                    );

                    $query .= $line;
                    if (substr($trimmed, -1) === ';') {
                        if (!$mysqli->query($query)) {
                            fclose($fp);
                            throw new RuntimeException("SQL error at {$count}: " . $mysqli->error);
                        }
                        $query = '';
                        $count++;
                    }
                }
                if (!feof($fp)) { fclose($fp); throw new RuntimeException("Read error at {$count}"); }
                fclose($fp);
                unlink($dump);

                echo json_encode(['ok' => true, 'msg' => "{$count} statements"]);
                break;

            case 'extract':
                if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive not available');

                $results = [];
                foreach (['uploads', 'themes', 'plugins'] as $d) {
                    $zp = "{$tmp_dir}/{$d}.zip";
                    if (!file_exists($zp)) { $results[] = "{$d}: skipped"; continue; }

                    $live = "{$wp_content_dir}/{$d}";
                    $staging = "{$tmp_dir}/staging-{$d}";
                    $backup = "{$tmp_dir}/backup-{$d}";

                    if (is_dir($staging)) rmdir_r($staging);
                    if (is_dir($backup)) rmdir_r($backup);
                    mkdir($staging, 0755, true);

                    $zip = new ZipArchive();
                    if ($zip->open($zp) !== true) throw new RuntimeException("{$d}: zip open failed");
                    $ok = $zip->extractTo($staging);
                    $num = $zip->numFiles;
                    $zip->close();
                    unlink($zp);
                    if (!$ok) { rmdir_r($staging); throw new RuntimeException("{$d}: extract failed"); }

                    if (is_dir($live)) {
                        if (!@rename($live, $backup)) {
                            rmdir_r($staging);
                            throw new RuntimeException("{$d}: swap failed");
                        }
                    }
                    if (!@rename($staging, $live)) {
                        if (is_dir($backup)) @rename($backup, $live);
                        throw new RuntimeException("{$d}: swap failed");
                    }
                    if (is_dir($backup)) rmdir_r($backup);
                    $results[] = "{$d}: {$num} files";
                }
                echo json_encode(['ok' => true, 'msg' => implode(', ', $results)]);
                break;

            case 'replace':
                $search = $_POST['search'] ?? '';
                $replace = $_POST['replace'] ?? '';
                $search_path = $_POST['search_path'] ?? '';
                $replace_path = $_POST['replace_path'] ?? '';

                if (!$search || !$replace) throw new RuntimeException('search/replace required');

                $pairs = [[$search, $replace]];
                $http = str_replace('https://', 'http://', $search);
                if ($http !== $search) $pairs[] = [$http, $replace];
                if ($search_path && $replace_path) $pairs[] = [$search_path, $replace_path];

                // Get owned tables
                $res = $mysqli->query("SHOW TABLES");
                if (!$res) throw new RuntimeException('Cannot list tables');
                $tables = [];
                while ($row = $res->fetch_row()) {
                    if (strpos($row[0], $prefix) === 0) $tables[] = $row[0];
                }

                $grand = 0;
                foreach ($tables as $table) {
                    $cols = [];
                    $cr = $mysqli->query("SHOW COLUMNS FROM `{$table}`");
                    if (!$cr) throw new RuntimeException("{$table}: column read error");
                    while ($c = $cr->fetch_assoc()) {
                        if (preg_match('/(char|text|blob|enum|set)/i', $c['Type'])) $cols[] = $c['Field'];
                    }
                    if (!$cols) continue;

                    $pks = [];
                    $kr = $mysqli->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
                    if (!$kr) throw new RuntimeException("{$table}: key read error");
                    while ($k = $kr->fetch_assoc()) $pks[] = $k['Column_name'];
                    if (!$pks) continue;

                    $total = 0;
                    $off = 0;
                    while (true) {
                        $dr = $mysqli->query("SELECT * FROM `{$table}` LIMIT 1000 OFFSET {$off}");
                        if (!$dr) throw new RuntimeException("{$table}: read error");
                        if ($dr->num_rows === 0) break;

                        while ($row = $dr->fetch_assoc()) {
                            $upd = [];
                            foreach ($cols as $c) {
                                if (!isset($row[$c]) || $row[$c] === null) continue;
                                $needs = false;
                                foreach ($pairs as $p) { if (strpos($row[$c], $p[0]) !== false) { $needs = true; break; } }
                                if (!$needs) continue;
                                $new = $row[$c];
                                foreach ($pairs as $p) $new = sr_recursive($p[0], $p[1], $new);
                                if ($new !== $row[$c]) $upd[$c] = $new;
                            }
                            if ($upd) {
                                $s = []; foreach ($upd as $c => $v) $s[] = "`{$c}` = \"" . sql_esc($v) . "\"";
                                $w = []; foreach ($pks as $pk) $w[] = "`{$pk}` = \"" . sql_esc($row[$pk]) . "\"";
                                $ok = $mysqli->query("UPDATE `{$table}` SET " . implode(', ', $s) . " WHERE " . implode(' AND ', $w));
                                if (!$ok) throw new RuntimeException("{$table}: update error — " . $mysqli->error);
                                $total += count($upd);
                            }
                        }
                        $off += 1000;
                    }
                    $grand += $total;
                }
                echo json_encode(['ok' => true, 'msg' => "{$grand} replacements"]);
                break;

            case 'flush':
                $mysqli->query("DELETE FROM `{$prefix}options` WHERE option_name = 'rewrite_rules'");
                $mysqli->query("DELETE FROM `{$prefix}options` WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");

                // Cleanup
                if (is_dir($tmp_dir)) { rmdir_r($tmp_dir); }

                // Self-delete
                @unlink($self_path);

                echo json_encode(['ok' => true, 'msg' => 'Done — installer deleted']);
                break;

            default:
                echo json_encode(['error' => 'Unknown step']);
        }
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }

    $mysqli->close();
    exit;
}

// ============================================================
// UI (GET request)
// ============================================================
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WP Migrate Lite — Installer</title>
<meta name="robots" content="noindex,nofollow">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #1e1e2e; color: #cdd6f4; padding: 2rem; }
h1 { color: #89b4fa; margin-bottom: 1.5rem; font-size: 1.5rem; }
.card { background: #313244; border-radius: 8px; padding: 1.5rem; margin-bottom: 1rem; }
label { display: block; font-size: 0.85rem; color: #a6adc8; margin-bottom: 0.3rem; margin-top: 0.8rem; }
input { width: 100%; padding: 0.5rem; background: #45475a; border: 1px solid #585b70; border-radius: 4px; color: #cdd6f4; font-size: 0.9rem; }
button { background: #89b4fa; color: #1e1e2e; border: none; padding: 0.6rem 1.5rem; border-radius: 4px; font-weight: 600; cursor: pointer; margin-top: 1rem; }
button:hover { background: #74c7ec; }
.log { background: #11111b; border-radius: 4px; padding: 1rem; margin-top: 1rem; font-family: monospace; font-size: 0.85rem; max-height: 400px; overflow-y: auto; display: none; line-height: 1.6; }
.ok { color: #a6e3a1; } .err { color: #f38ba8; } .info { color: #89b4fa; } .step { color: #f9e2af; font-weight: bold; }
</style>
</head>
<body>
<h1>WP Migrate Lite — Installer</h1>

<div class="card">
    <label>Installer Token</label>
    <input type="password" id="token" placeholder="Token set in installer.php">
    <label>Worker URL</label>
    <input type="url" id="worker" placeholder="https://wp-migrate-proxy.xxx.workers.dev/">
    <label>R2 Auth Token</label>
    <input type="text" id="r2token" placeholder="R2 bearer token">
    <label>Source Site ID</label>
    <input type="text" id="siteid" placeholder="e.g. my-site-com">
    <label>Batch ID</label>
    <input type="text" id="batchid" placeholder="from push output">
    <label>Source URL</label>
    <input type="url" id="search" placeholder="https://source-site.com">
    <label>Source Server Path (optional)</label>
    <input type="text" id="searchpath" placeholder="/home/user/public_html">
    <button onclick="run()">Start Migration</button>
</div>

<div class="log" id="log"></div>

<script>
function log(msg, cls) {
    var el = document.getElementById('log');
    el.style.display = 'block';
    el.innerHTML += '<div class="' + (cls||'') + '">' + msg + '</div>';
    el.scrollTop = el.scrollHeight;
}

function post(data) {
    data.token = document.getElementById('token').value;
    return fetch(location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams(data)
    }).then(function(r) { return r.json(); });
}

async function run() {
    document.getElementById('log').innerHTML = '';
    var worker = document.getElementById('worker').value;
    var r2token = document.getElementById('r2token').value;
    var siteid = document.getElementById('siteid').value;
    var batchid = document.getElementById('batchid').value;
    var search = document.getElementById('search').value;
    var searchpath = document.getElementById('searchpath').value;

    if (!worker || !r2token || !siteid || !batchid || !search) {
        alert('All fields except Source Server Path are required'); return;
    }

    var steps = [
        { step: 'download', label: 'Downloading from R2', data: { worker: worker, r2_token: r2token, site_id: siteid, batch_id: batchid } },
        { step: 'import', label: 'Importing database' },
        { step: 'extract', label: 'Extracting files' },
        { step: 'replace', label: 'Search-replace', data: { search: search, replace: location.origin, search_path: searchpath, replace_path: '' } },
        { step: 'flush', label: 'Finalizing' }
    ];

    for (var i = 0; i < steps.length; i++) {
        log('⏳ ' + steps[i].label + '...', 'step');
        var data = Object.assign({ step: steps[i].step }, steps[i].data || {});
        var r = await post(data);
        if (r.error) { log('✗ ' + r.error, 'err'); return; }
        log('✓ ' + r.msg, 'ok');
    }
    log('🎉 Migration complete! Log in with source site credentials.', 'ok');
    log('This installer has been auto-deleted.', 'info');
}
</script>
</body>
</html>
<?php

// ============================================================
// Standalone helpers (no WordPress dependency)
// ============================================================

function r2_get(string $worker, string $token, string $key): string {
    $ch = curl_init(rtrim($worker, '/') . '/' . $key);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 600,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false) { $err = curl_error($ch); curl_close($ch); throw new RuntimeException("cURL: {$err}"); }
    curl_close($ch);
    if ($code !== 200) throw new RuntimeException("R2 GET {$key}: HTTP {$code}");
    return $body;
}

function r2_download(string $worker, string $token, string $key, string $dest): void {
    $fp = @fopen($dest, 'w');
    if (!$fp) throw new RuntimeException("Cannot write: {$dest}");
    $ch = curl_init(rtrim($worker, '/') . '/' . $key);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$token}"],
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 600,
    ]);
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($ok === false) { $err = curl_error($ch); curl_close($ch); fclose($fp); @unlink($dest); throw new RuntimeException("cURL: {$err}"); }
    curl_close($ch);
    fclose($fp);
    if ($code !== 200) { @unlink($dest); throw new RuntimeException("R2 GET {$key}: HTTP {$code}"); }
}

function sr_recursive($s, $r, $d) {
    if (is_string($d) && is_serialized_string($d)) {
        if (preg_match('/r:\d+;/i', $d)) return $d;
        $u = @unserialize($d, ['allowed_classes' => false]);
        if ($u !== false || $d === 'b:0;') return serialize(sr_recursive($s, $r, $u));
    }
    if (is_string($d) && strlen($d) > 1 && ($d[0] === '{' || $d[0] === '[')) {
        $j = json_decode($d, true);
        if (is_array($j)) return json_encode(sr_recursive($s, $r, $j), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    if (is_array($d)) { foreach ($d as $k => $v) $d[$k] = sr_recursive($s, $r, $v); return $d; }
    if (is_object($d)) {
        try { if (!(new ReflectionClass($d))->isCloneable()) return $d; } catch (ReflectionException $e) { return $d; }
        foreach (get_object_vars($d) as $k => $v) { if (!is_int($k)) $d->$k = sr_recursive($s, $r, $v); }
        return $d;
    }
    if (is_string($d)) return str_replace($s, $r, $d);
    return $d;
}

// Standalone is_serialized (WordPress function reimplemented)
function is_serialized_string($data): bool {
    if (!is_string($data) || strlen($data) < 4) return false;
    $first = $data[0];
    $last = $data[strlen($data) - 1];
    if ($last !== ';' && $last !== '}') return false;
    if ($first === 's' && $data[1] === ':') return true;
    if ($first === 'a' && $data[1] === ':') return true;
    if ($first === 'O' && $data[1] === ':') return true;
    if ($first === 'b' && $data[2] === ':') return true;
    if ($first === 'i' && $data[1] === ':') return true;
    if ($first === 'd' && $data[1] === ':') return true;
    if ($first === 'N' && $data === 'N;') return true;
    return false;
}

function sql_esc(string $input): string {
    return str_replace(
        ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
        ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
        $input
    );
}

function rmdir_r(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($dir);
}
