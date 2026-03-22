<?php
/**
 * WP Migrate Lite — Standalone Installer
 *
 * Upload to target site root. Access via browser. Self-deletes after migration.
 * Only requires: Installer Token + Migration Code (from push output).
 */

define('INSTALLER_TOKEN', ''); // Set before uploading
define('R2_WORKER', 'https://wp-migrate-proxy.yansir.workers.dev');
define('R2_TOKEN', '0e7ddc9b3956aafba3b24a1c39d7775edbcb6887f20bc873594ce376b8e219dc');

error_reporting(E_ALL);
ini_set('display_errors', '0');
set_time_limit(0);
ini_set('memory_limit', '512M');

$self_path = __FILE__;
$site_root = dirname($self_path);

// Parse wp-config.php
$wp_config_path = null;
if (file_exists($site_root . '/wp-config.php')) $wp_config_path = $site_root . '/wp-config.php';
elseif (file_exists(dirname($site_root) . '/wp-config.php')) $wp_config_path = dirname($site_root) . '/wp-config.php';
if (!$wp_config_path) die(json_encode(['error' => 'wp-config.php not found']));

$config = file_get_contents($wp_config_path);
$db = [];
foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'] as $c) {
    preg_match("/define\s*\(\s*['\"]" . $c . "['\"]\s*,\s*['\"]([^'\"]*)/", $config, $m);
    $db[$c] = $m[1] ?? '';
}
$prefix = 'wp_';
if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)/', $config, $m)) $prefix = $m[1];

// Resolve wp-content
$wp_content = $site_root . '/wp-content';
$wp_content_proven = false;
$has_define = (bool) preg_match("/define\s*\(\s*['\"]WP_CONTENT_DIR['\"]/",$config);
if (preg_match("/define\s*\(\s*['\"]WP_CONTENT_DIR['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $config, $m)) {
    if (is_dir($m[1])) { $wp_content = $m[1]; $wp_content_proven = true; }
}
if (!$wp_content_proven && !$has_define && is_dir($wp_content)) $wp_content_proven = true;

// Storage
$install_hash = substr(md5(realpath($site_root) ?: $site_root), 0, 12);
$tmp_dir = sys_get_temp_dir() . '/.migrate-installer-' . $install_hash;

// ============================================================
// AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');

    if (INSTALLER_TOKEN === '' || ($_POST['token'] ?? '') !== INSTALLER_TOKEN) {
        die(json_encode(['error' => 'Invalid or unconfigured token']));
    }

    // wp-content override
    $wc_post = trim($_POST['wp_content_dir'] ?? '');
    if ($wc_post !== '') {
        if (!is_dir($wc_post)) die(json_encode(['error' => "WP Content Path does not exist: {$wc_post}"]));
        $wp_content = $wc_post;
    } elseif (!$wp_content_proven) {
        die(json_encode(['error' => 'Cannot auto-detect wp-content. Specify WP Content Path.']));
    }

    $mysqli = @new mysqli($db['DB_HOST'], $db['DB_USER'], $db['DB_PASSWORD'], $db['DB_NAME']);
    if ($mysqli->connect_error) die(json_encode(['error' => 'DB: ' . $mysqli->connect_error]));
    $mysqli->set_charset('utf8mb4');

    if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0755, true);
    $engine = new ML_Pull_Engine($mysqli, $prefix, $wp_content, $tmp_dir);
    $step = $_POST['step'] ?? '';

    try {
        switch ($step) {
            case 'migrate':
                // Parse migration code: site_id/batch_id
                $code = trim($_POST['migrate_code'] ?? '');
                $parts = explode('/', $code, 2);
                if (count($parts) !== 2 || !$parts[0] || !$parts[1]) {
                    throw new RuntimeException('Invalid migration code');
                }
                $site_id = $parts[0];
                $batch_id = $parts[1];

                // Step 1: Capture target siteurl (write-once)
                $tu_path = $tmp_dir . '/target-siteurl';
                if (!file_exists($tu_path)) {
                    $su = $mysqli->query("SELECT option_value FROM `{$prefix}options` WHERE option_name = 'siteurl'");
                    if (!$su) throw new RuntimeException('Cannot read target siteurl');
                    $row = $su->fetch_assoc();
                    if (!$row || empty($row['option_value'])) throw new RuntimeException('Target siteurl empty');
                    $len = strlen($row['option_value']);
                    $written = @file_put_contents($tu_path, $row['option_value']);
                    if ($written === false || $written < $len) throw new RuntimeException('Failed to save target siteurl');
                }

                // Step 2: Download
                $dl = $engine->download(R2_WORKER, R2_TOKEN, $site_id, $batch_id);

                // Read source info from manifest
                $manifest = json_decode(@file_get_contents($tmp_dir . '/manifest-cache.json') ?: '{}', true);
                // Engine doesn't cache manifest, re-fetch it
                $manifest_raw = file_get_contents(R2_WORKER . "/{$site_id}/{$batch_id}/manifest.json" .
                    "?" . http_build_query([])); // This won't work without auth header
                // Better: read from already-downloaded artifacts metadata
                // Actually, download() already validated manifest. We stored source_url in it at push time.
                // Re-download manifest to get source_url/source_path
                $ch = curl_init(R2_WORKER . "/{$site_id}/{$batch_id}/manifest.json");
                curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ["Authorization: Bearer " . R2_TOKEN],
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
                $mdata = curl_exec($ch); curl_close($ch);
                $manifest = json_decode($mdata, true) ?: [];

                echo json_encode(['ok' => true, 'msg' => implode(', ', $dl),
                    'source_url' => $manifest['source_url'] ?? '',
                    'source_path' => $manifest['source_path'] ?? '']);
                break;

            case 'import':
                echo json_encode(['ok' => true, 'msg' => $engine->import_db() . ' statements']);
                break;

            case 'extract':
                echo json_encode(['ok' => true, 'msg' => implode(', ', $engine->extract())]);
                break;

            case 'replace':
                $tu = trim(@file_get_contents($tmp_dir . '/target-siteurl') ?: '');
                if (!$tu) throw new RuntimeException('Target siteurl missing — restart migration');
                $source_url = $_POST['source_url'] ?? '';
                $source_path = $_POST['source_path'] ?? '';
                if (!$source_url) throw new RuntimeException('source_url required');

                $pairs = [[$source_url, $tu]];
                $h = str_replace('https://', 'http://', $source_url);
                if ($h !== $source_url) $pairs[] = [$h, $tu];
                if ($source_path) $pairs[] = [$source_path, rtrim($site_root, '/')];

                echo json_encode(['ok' => true, 'msg' => $engine->search_replace($pairs) . ' replacements']);
                break;

            case 'flush':
                $engine->flush();
                $engine->cleanup();
                @unlink($self_path);
                echo json_encode(['ok' => true, 'msg' => 'Done']);
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
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>WP Migrate Lite — Installer</title>
<meta name="robots" content="noindex,nofollow">
<style>
*{box-sizing:border-box;margin:0;padding:0}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#1e1e2e;color:#cdd6f4;padding:2rem}
h1{color:#89b4fa;margin-bottom:1.5rem;font-size:1.5rem}.card{background:#313244;border-radius:8px;padding:1.5rem;margin-bottom:1rem}
label{display:block;font-size:.85rem;color:#a6adc8;margin:.8rem 0 .3rem}input{width:100%;padding:.5rem;background:#45475a;border:1px solid #585b70;border-radius:4px;color:#cdd6f4;font-size:.9rem}
button{background:#89b4fa;color:#1e1e2e;border:none;padding:.6rem 1.5rem;border-radius:4px;font-weight:600;cursor:pointer;margin-top:1rem}button:hover{background:#74c7ec}
.log{background:#11111b;border-radius:4px;padding:1rem;margin-top:1rem;font-family:monospace;font-size:.85rem;max-height:400px;overflow-y:auto;display:none;line-height:1.6}
.ok{color:#a6e3a1}.err{color:#f38ba8}.info{color:#89b4fa}.step{color:#f9e2af;font-weight:bold}
.hint{font-size:.8rem;color:#6c7086;margin-top:.2rem}
</style>
</head>
<body>
<h1>WP Migrate Lite — Installer</h1>
<div class="card">
    <label>Installer Token</label>
    <input type="password" id="token">
    <label>Migration Code</label>
    <input type="text" id="code" placeholder="site-id/batch-id (from push output)">
    <div class="hint">Paste the migration code shown after Push completes</div>
<?php if (!$wp_content_proven): ?>
    <label>WP Content Path</label>
    <input type="text" id="wpcontentdir" placeholder="e.g. <?php echo htmlspecialchars($wp_content); ?>">
    <div class="hint">Auto-detection failed — please specify the path</div>
<?php endif; ?>
    <button onclick="run()">Start Migration</button>
</div>
<div class="log" id="log"></div>
<script>
function log(m,c){var e=document.getElementById('log');e.style.display='block';e.innerHTML+='<div class="'+(c||'')+'">'+m+'</div>';e.scrollTop=e.scrollHeight}
function post(d){
    d.token=document.getElementById('token').value;
    var wc=document.getElementById('wpcontentdir');
    if(wc)d.wp_content_dir=wc.value;
    return fetch(location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams(d)}).then(r=>r.json());
}
async function run(){
    document.getElementById('log').innerHTML='';
    var code=document.getElementById('code').value;
    if(!code){alert('Enter the migration code');return}

    log('⏳ Downloading...','step');
    var r=await post({step:'migrate',migrate_code:code});
    if(r.error){log('✗ '+r.error,'err');return}
    log('✓ '+r.msg,'ok');
    var srcUrl=r.source_url,srcPath=r.source_path;

    log('⏳ Importing database...','step');
    r=await post({step:'import'});
    if(r.error){log('✗ '+r.error,'err');return}
    log('✓ '+r.msg,'ok');

    log('⏳ Extracting files...','step');
    r=await post({step:'extract'});
    if(r.error){log('✗ '+r.error,'err');return}
    log('✓ '+r.msg,'ok');

    log('⏳ Search-replace...','step');
    r=await post({step:'replace',source_url:srcUrl,source_path:srcPath});
    if(r.error){log('✗ '+r.error,'err');return}
    log('✓ '+r.msg,'ok');

    log('⏳ Finalizing...','step');
    r=await post({step:'flush'});
    if(r.error){log('✗ '+r.error,'err');return}
    log('✓ '+r.msg,'ok');

    log('🎉 Migration complete! Log in with source site credentials.','ok');
    log('Installer has been auto-deleted.','info');
}
</script>
</body>
</html>
<?php
// ============================================================
// Embedded Pull Engine
// ============================================================
/**
 * Pull Engine — runtime-agnostic migration core.
 *
 * Shared by pull-cli.php (WP-CLI) and installer.php (standalone).
 * No WordPress dependency. Requires a mysqli connection and wp-content path.
 */
class ML_Pull_Engine {

    private $mysqli;
    private string $prefix;
    private string $wp_content;
    private string $tmp_dir;

    public function __construct(mysqli $mysqli, string $prefix, string $wp_content, string $tmp_dir) {
        $this->mysqli = $mysqli;
        $this->prefix = $prefix;
        $this->wp_content = $wp_content;
        $this->tmp_dir = $tmp_dir;
        if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0755, true);
    }

    // ============================================================
    // Download
    // ============================================================

    public function download(string $worker, string $token, string $site_id, string $batch_id): array {
        $prefix = "{$site_id}/{$batch_id}";

        // Manifest
        $manifest_raw = $this->r2_get($worker, $token, "{$prefix}/manifest.json");
        $manifest = json_decode($manifest_raw, true);
        if (!$manifest || ($manifest['batch_id'] ?? '') !== $batch_id) {
            throw new RuntimeException('Invalid manifest');
        }
        if (($manifest['site_id'] ?? '') !== $site_id) {
            throw new RuntimeException('Manifest site_id mismatch');
        }

        $results = [];
        foreach ($manifest['artifacts'] as $f) {
            $dest = "{$this->tmp_dir}/{$f}";
            $this->r2_download($worker, $token, "{$prefix}/{$f}", $dest);
            $results[] = $f . ': ' . round(filesize($dest) / 1048576, 1) . 'MB';
        }
        return $results;
    }

    // ============================================================
    // Import DB
    // ============================================================

    public function import_db(): int {
        $dump = "{$this->tmp_dir}/dump.sql";
        if (!file_exists($dump)) throw new RuntimeException('dump.sql not found');

        $fp = @fopen($dump, 'r');
        if (!$fp) throw new RuntimeException('Cannot open dump');
        $query = '';
        $count = 0;
        $prefix = $this->prefix;

        while (($line = fgets($fp)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '--') === 0) continue;

            $line = preg_replace_callback(
                '/^(DROP\s+TABLE(?:\s+IF\s+EXISTS)?\s+|CREATE\s+TABLE\s+|INSERT\s+INTO\s+)`([^`]+)`/i',
                function ($m) use ($prefix) { return $m[1] . '`' . $prefix . $m[2] . '`'; },
                $line
            );

            $query .= $line;
            if (substr($trimmed, -1) === ';') {
                if (!$this->mysqli->query($query)) {
                    fclose($fp);
                    throw new RuntimeException("SQL error at {$count}: " . $this->mysqli->error);
                }
                $query = '';
                $count++;
            }
        }
        if (!feof($fp)) { fclose($fp); throw new RuntimeException("Read error at {$count}"); }
        fclose($fp);
        unlink($dump);
        return $count;
    }

    // ============================================================
    // Extract files
    // ============================================================

    public function extract(): array {
        if (!class_exists('ZipArchive')) throw new RuntimeException('ZipArchive not available');

        $results = [];
        foreach (['uploads', 'themes', 'plugins'] as $d) {
            $zp = "{$this->tmp_dir}/{$d}.zip";
            if (!file_exists($zp)) { $results[] = "{$d}: skipped"; continue; }

            $live    = "{$this->wp_content}/{$d}";
            $staging = "{$this->tmp_dir}/staging-{$d}";
            $backup  = "{$this->tmp_dir}/backup-{$d}";

            if (is_dir($staging)) self::rmdir_r($staging);
            if (is_dir($backup))  self::rmdir_r($backup);
            mkdir($staging, 0755, true);

            $zip = new ZipArchive();
            if ($zip->open($zp) !== true) { self::rmdir_r($staging); throw new RuntimeException("{$d}: zip open failed"); }
            $ok  = $zip->extractTo($staging);
            $num = $zip->numFiles;
            $zip->close();
            unlink($zp);
            if (!$ok) { self::rmdir_r($staging); throw new RuntimeException("{$d}: extract failed"); }

            if (is_dir($live)) {
                if (!@rename($live, $backup)) {
                    self::rmdir_r($staging);
                    throw new RuntimeException("{$d}: swap failed, live preserved");
                }
            }
            if (!@rename($staging, $live)) {
                $rolled_back = is_dir($backup) && @rename($backup, $live);
                throw new RuntimeException($rolled_back
                    ? "{$d}: swap failed, rolled back"
                    : "{$d}: CRITICAL — swap and rollback both failed, {$d}/ may be missing. Manual recovery required."
                );
            }
            if (is_dir($backup)) self::rmdir_r($backup);
            $results[] = "{$d}: {$num} files";
        }
        return $results;
    }

    // ============================================================
    // Search-replace
    // ============================================================

    public function search_replace(array $pairs): int {
        $tables = [];
        $res = $this->mysqli->query("SHOW TABLES");
        if (!$res) throw new RuntimeException('Cannot list tables: ' . $this->mysqli->error);
        while ($row = $res->fetch_row()) {
            if (strpos($row[0], $this->prefix) === 0) $tables[] = $row[0];
        }

        $grand = 0;
        foreach ($tables as $table) {
            $cols = [];
            $cr = $this->mysqli->query("SHOW COLUMNS FROM `{$table}`");
            if (!$cr) throw new RuntimeException("{$table}: column error — " . $this->mysqli->error);
            while ($c = $cr->fetch_assoc()) {
                if (preg_match('/(char|text|blob|enum|set)/i', $c['Type'])) $cols[] = $c['Field'];
            }
            if (!$cols) continue;

            $pks = [];
            $kr = $this->mysqli->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
            if (!$kr) throw new RuntimeException("{$table}: key error — " . $this->mysqli->error);
            while ($k = $kr->fetch_assoc()) $pks[] = $k['Column_name'];
            if (!$pks) continue;

            $total = 0;
            $off = 0;
            while (true) {
                $dr = $this->mysqli->query("SELECT * FROM `{$table}` LIMIT 1000 OFFSET {$off}");
                if (!$dr) throw new RuntimeException("{$table}: read error — " . $this->mysqli->error);
                if ($dr->num_rows === 0) break;

                while ($row = $dr->fetch_assoc()) {
                    $upd = [];
                    foreach ($cols as $c) {
                        if (!isset($row[$c]) || $row[$c] === null) continue;
                        $needs = false;
                        foreach ($pairs as $p) { if (strpos($row[$c], $p[0]) !== false) { $needs = true; break; } }
                        if (!$needs) continue;
                        $new = $row[$c];
                        foreach ($pairs as $p) $new = self::sr_recursive($p[0], $p[1], $new);
                        if ($new !== $row[$c]) $upd[$c] = $new;
                    }
                    if ($upd) {
                        $s = []; foreach ($upd as $c => $v) $s[] = "`{$c}` = \"" . self::sql_esc($v) . "\"";
                        $w = []; foreach ($pks as $pk) $w[] = "`{$pk}` = \"" . self::sql_esc($row[$pk]) . "\"";
                        $ok = $this->mysqli->query("UPDATE `{$table}` SET " . implode(', ', $s) . " WHERE " . implode(' AND ', $w));
                        if (!$ok) throw new RuntimeException("{$table}: update error — " . $this->mysqli->error);
                        $total += count($upd);
                    }
                }
                $off += 1000;
            }
            $grand += $total;
        }
        return $grand;
    }

    // ============================================================
    // Flush
    // ============================================================

    public function flush(): void {
        $p = $this->prefix;
        @$this->mysqli->query("DELETE FROM `{$p}options` WHERE option_name = 'rewrite_rules'");
        @$this->mysqli->query("DELETE FROM `{$p}options` WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");
    }

    public function cleanup(): void {
        if (is_dir($this->tmp_dir)) self::rmdir_r($this->tmp_dir);
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function r2_get(string $worker, string $token, string $key): string {
        $ch = curl_init(rtrim($worker, '/') . '/' . $key);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 600,
        ]);
        $body = curl_exec($ch);
        if ($body === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException("cURL: {$e}"); }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) throw new RuntimeException("R2 GET {$key}: HTTP {$code}");
        return $body;
    }

    private function r2_download(string $worker, string $token, string $key, string $dest): void {
        $fp = @fopen($dest, 'w');
        if (!$fp) throw new RuntimeException("Cannot write: {$dest}");
        $ch = curl_init(rtrim($worker, '/') . '/' . $key);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 600,
        ]);
        $ok = curl_exec($ch);
        if ($ok === false) { $e = curl_error($ch); curl_close($ch); fclose($fp); @unlink($dest); throw new RuntimeException("cURL: {$e}"); }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if ($code !== 200) { @unlink($dest); throw new RuntimeException("R2 GET {$key}: HTTP {$code}"); }
    }

    public static function sr_recursive($s, $r, $d) {
        if (is_string($d) && self::is_serialized($d)) {
            if (preg_match('/r:\d+;/i', $d)) return $d;
            $u = @unserialize($d, ['allowed_classes' => false]);
            if ($u !== false || $d === 'b:0;') return serialize(self::sr_recursive($s, $r, $u));
        }
        if (is_string($d) && strlen($d) > 1 && ($d[0] === '{' || $d[0] === '[')) {
            $j = json_decode($d, true);
            if (is_array($j)) return json_encode(self::sr_recursive($s, $r, $j), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (is_array($d)) { foreach ($d as $k => $v) $d[$k] = self::sr_recursive($s, $r, $v); return $d; }
        if (is_object($d)) {
            try { if (!(new ReflectionClass($d))->isCloneable()) return $d; } catch (ReflectionException $e) { return $d; }
            foreach (get_object_vars($d) as $k => $v) { if (!is_int($k)) $d->$k = self::sr_recursive($s, $r, $v); }
            return $d;
        }
        if (is_string($d)) return str_replace($s, $r, $d);
        return $d;
    }

    public static function is_serialized(string $data): bool {
        if (strlen($data) < 4) return false;
        $last = $data[strlen($data) - 1];
        if ($last !== ';' && $last !== '}') return false;
        $f = $data[0];
        return ($f === 's' || $f === 'a' || $f === 'O' || $f === 'i' || $f === 'd') && $data[1] === ':'
            || ($f === 'b' && $data[2] === ':')
            || ($f === 'N' && $data === 'N;');
    }

    public static function sql_esc(string $input): string {
        return str_replace(
            ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            $input
        );
    }

    public static function rmdir_r(string $dir): void {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($dir);
    }
}
