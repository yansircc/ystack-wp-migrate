<?php
/**
 * WP Migrate Lite — Standalone Installer (thin wrapper over pull engine)
 *
 * Upload to target site root. Access via browser. Self-deletes after migration.
 * Does NOT require WordPress or WP-CLI.
 */

define('INSTALLER_TOKEN', ''); // Set before uploading

error_reporting(E_ALL);
ini_set('display_errors', '0');
set_time_limit(0);
ini_set('memory_limit', '512M');

$self_path = __FILE__;
$site_root = dirname($self_path);

// Load shared pull engine
require_once $site_root . '/wp-content/plugins/wp-migrate-lite/includes/class-pull-engine.php';

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

$wp_content = $site_root . '/wp-content';
if (preg_match("/define\s*\(\s*['\"]WP_CONTENT_DIR['\"]\s*,\s*(.+?)\s*\)/", $config, $m)) {
    $expr = trim($m[1], "'\"\t\n\r ");
    if (is_dir($expr)) $wp_content = $expr;
}

// Storage: install-scoped temp dir (outside webroot when possible)
$install_hash = substr(md5(realpath($site_root) ?: $site_root), 0, 12);
$tmp_dir = sys_get_temp_dir() . '/.migrate-installer-' . $install_hash;

// ============================================================
// AJAX handler
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');

    if (INSTALLER_TOKEN === '' || ($_POST['token'] ?? '') !== INSTALLER_TOKEN) {
        die(json_encode(['error' => 'Invalid or unconfigured token']));
    }

    $mysqli = @new mysqli($db['DB_HOST'], $db['DB_USER'], $db['DB_PASSWORD'], $db['DB_NAME']);
    if ($mysqli->connect_error) die(json_encode(['error' => 'DB: ' . $mysqli->connect_error]));
    $mysqli->set_charset('utf8mb4');

    $engine = new ML_Pull_Engine($mysqli, $prefix, $wp_content, $tmp_dir);
    $step = $_POST['step'] ?? '';

    try {
        switch ($step) {
            case 'download':
                $dl = $engine->download($_POST['worker'] ?? '', $_POST['r2_token'] ?? '', $_POST['site_id'] ?? '', $_POST['batch_id'] ?? '');
                echo json_encode(['ok' => true, 'msg' => implode(', ', $dl)]);
                break;
            case 'import':
                echo json_encode(['ok' => true, 'msg' => $engine->import_db() . ' statements']);
                break;
            case 'extract':
                echo json_encode(['ok' => true, 'msg' => implode(', ', $engine->extract())]);
                break;
            case 'replace':
                $pairs = [[$_POST['search'] ?? '', $_POST['replace'] ?? '']];
                $h = str_replace('https://', 'http://', $pairs[0][0]);
                if ($h !== $pairs[0][0]) $pairs[] = [$h, $pairs[0][1]];
                if (!empty($_POST['search_path']) && !empty($_POST['replace_path'])) {
                    $pairs[] = [$_POST['search_path'], $_POST['replace_path']];
                }
                echo json_encode(['ok' => true, 'msg' => $engine->search_replace($pairs) . ' replacements']);
                break;
            case 'flush':
                $engine->flush();
                $engine->cleanup();
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
// UI
// ============================================================
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
</style>
</head>
<body>
<h1>WP Migrate Lite — Installer</h1>
<div class="card">
    <label>Installer Token</label><input type="password" id="token">
    <label>Worker URL</label><input type="url" id="worker">
    <label>R2 Auth Token</label><input type="text" id="r2token">
    <label>Source Site ID</label><input type="text" id="siteid">
    <label>Batch ID</label><input type="text" id="batchid">
    <label>Source URL</label><input type="url" id="search">
    <label>Source Server Path (optional)</label><input type="text" id="searchpath">
    <button onclick="run()">Start Migration</button>
</div>
<div class="log" id="log"></div>
<script>
function log(m,c){var e=document.getElementById('log');e.style.display='block';e.innerHTML+='<div class="'+(c||'')+'">'+m+'</div>';e.scrollTop=e.scrollHeight}
function post(d){d.token=document.getElementById('token').value;return fetch(location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams(d)}).then(r=>r.json())}
async function run(){
    document.getElementById('log').innerHTML='';
    var w=document.getElementById('worker').value,t=document.getElementById('r2token').value,
        si=document.getElementById('siteid').value,bi=document.getElementById('batchid').value,
        s=document.getElementById('search').value,sp=document.getElementById('searchpath').value;
    if(!w||!t||!si||!bi||!s){alert('Fill required fields');return}
    var steps=[
        {step:'download',label:'Downloading',data:{worker:w,r2_token:t,site_id:si,batch_id:bi}},
        {step:'import',label:'Importing DB'},
        {step:'extract',label:'Extracting files'},
        {step:'replace',label:'Search-replace',data:{search:s,replace:location.origin,search_path:sp,replace_path:''}},
        {step:'flush',label:'Finalizing'}
    ];
    for(var i=0;i<steps.length;i++){
        log('⏳ '+steps[i].label+'...','step');
        var r=await post(Object.assign({step:steps[i].step},steps[i].data||{}));
        if(r.error){log('✗ '+r.error,'err');return}
        log('✓ '+r.msg,'ok');
    }
    log('🎉 Migration complete! Log in with source site credentials.','ok');
    log('Installer has been auto-deleted.','info');
}
</script>
</body>
</html>
