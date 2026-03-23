<?php
/**
 * YStack WP Migrate — Standalone Runner
 *
 * Template for both manual installer and auto-deployed browser runner.
 * Does NOT bootstrap WordPress. Reads wp-config.php directly, uses mysqli.
 *
 * Constants injected at build time by YSWM_Admin::prepare_installer_body():
 *   YSWM_R2_WORKER, YSWM_R2_TOKEN, YSWM_CODE, YSWM_RUNNER_TOKEN
 *
 * Pull engine injected at build time from includes/class-pull-engine.php
 * at the YSWM_ENGINE_SOURCE placeholder near the end of this file.
 */

define('YSWM_R2_WORKER', '');
define('YSWM_R2_TOKEN', '');
if (!defined('YSWM_CODE')) define('YSWM_CODE', '');
if (!defined('YSWM_RUNNER_TOKEN')) define('YSWM_RUNNER_TOKEN', '');
if (!defined('YSWM_RUN_ID')) define('YSWM_RUN_ID', '');

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

// Storage — install_hash computed here; tmp_dir is run-scoped (computed per request)
$install_hash = substr(md5(realpath($site_root) ?: $site_root), 0, 12);

// ============================================================
// AJAX
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');

    // Runner token auth (when auto-deployed, non-empty; manual installer: empty, skipped)
    if (YSWM_RUNNER_TOKEN !== '') {
        if (trim($_POST['runner_token'] ?? '') !== YSWM_RUNNER_TOKEN) {
            die(json_encode(['error' => 'Invalid runner token']));
        }
    }

    // Parse migration code: site_id/batch_id/installer_token
    $migrate_code = trim($_POST['migrate_code'] ?? '');
    $code_parts = explode('/', $migrate_code, 3);
    if (count($code_parts) !== 3 || !$code_parts[0] || !$code_parts[1] || !$code_parts[2]) {
        die(json_encode(['error' => 'Invalid migration code']));    }
    $mc_site_id = $code_parts[0];
    $mc_batch_id = $code_parts[1];
    $mc_token = $code_parts[2];

    // Run-scoped storage: YSWM_RUN_ID (auto-deploy) or migrate_code hash (manual)
    $run_id = YSWM_RUN_ID !== '' ? YSWM_RUN_ID : substr(md5($migrate_code), 0, 16);
    $tmp_dir = sys_get_temp_dir() . '/.yswm-run-' . $install_hash . '-' . $run_id;

    // Verify token against manifest on first step (download)
    // For subsequent steps, token is verified via cached value
    $token_file = $tmp_dir . '/installer-token';
    $step = $_POST['step'] ?? '';
    if ($step === 'migrate') {
        // First step: download manifest and verify token
    } else {
        // Subsequent steps: verify against cached token
        if (!file_exists($token_file) || trim(@file_get_contents($token_file)) !== $mc_token) {
            die(json_encode(['error' => 'Invalid token — restart migration']));
        }
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
    $engine = new YSWM_Pull_Engine($mysqli, $prefix, $wp_content, $tmp_dir);

    try {
        switch ($step) {
            case 'migrate':
                // Capture target siteurl (write-once)
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

                // Download with token verification (fails before artifacts if token is wrong)
                $dl = $engine->download(YSWM_R2_WORKER, YSWM_R2_TOKEN, $mc_site_id, $mc_batch_id, $mc_token);

                // Read manifest from saved file
                $mraw = @file_get_contents($tmp_dir . '/manifest.json');
                if ($mraw === false) throw new RuntimeException('Cannot read persisted manifest');
                $manifest = json_decode($mraw, true);
                if (!$manifest || empty($manifest['source_url']) || empty($manifest['source_path'])) {
                    throw new RuntimeException('Persisted manifest missing source_url or source_path');
                }

                // Cache token for subsequent steps
                $tw = @file_put_contents($token_file, $mc_token);
                if ($tw === false || $tw !== strlen($mc_token)) {
                    throw new RuntimeException('Failed to cache installer token');
                }

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
<title>YStack WP Migrate — Installer</title>
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
<h1>YStack WP Migrate — Installer</h1>
<div class="card">
<?php if (YSWM_CODE): ?>
    <input type="hidden" id="code" value="<?php echo htmlspecialchars(YSWM_CODE); ?>">
    <p style="color:#a6e3a1;font-size:.9rem">Migration code pre-configured. Click to start.</p>
<?php else: ?>
    <label>Migration Code</label>
    <input type="text" id="code" placeholder="Paste the code from Push output">
    <div class="hint">One code contains everything needed to migrate</div>
<?php endif; ?>
<?php if (!$wp_content_proven): ?>
    <label>WP Content Path</label>
    <input type="text" id="wpcontentdir" placeholder="e.g. <?php echo htmlspecialchars($wp_content); ?>">
    <div class="hint">Auto-detection failed — please specify</div>
<?php endif; ?>
    <button onclick="run()">Start Migration</button>
</div>
<div class="log" id="log"></div>
<script>
function log(m,c){var e=document.getElementById('log');e.style.display='block';e.innerHTML+='<div class="'+(c||'')+'">'+m+'</div>';e.scrollTop=e.scrollHeight}
function post(d){
    d.migrate_code=document.getElementById('code').value;
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
// Pull Engine — injected at build time from includes/class-pull-engine.php
// DO NOT manually edit below this line.
// ============================================================
/* YSWM_ENGINE_SOURCE */
