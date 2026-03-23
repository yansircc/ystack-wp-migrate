/* global yswm */

function yswmLog(id, msg, cls) {
    var el = document.getElementById(id);
    el.style.display = 'block';
    el.innerHTML += '<div class="' + (cls || '') + '">' + msg + '</div>';
    el.scrollTop = el.scrollHeight;
}

function yswmAjax(action, data) {
    data.action = 'yswm_' + action;
    data.nonce = yswm.nonce;
    return fetch(yswm.ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(data)
    }).then(function (r) { return r.json(); });
}

async function yswmPush() {
    var log = 'yswm-push-log';
    document.getElementById(log).innerHTML = '';

    var r = await yswmAjax('push_step', { step: 'init' });
    if (!r.success) { yswmLog(log, '✗ ' + r.data, 'yswm-err'); return; }
    var batchId = r.data.batch_id;
    yswmLog(log, 'Batch: ' + batchId, 'yswm-info');

    var steps = [
        { step: 'db',      label: 'Database' },
        { step: 'uploads', label: 'Uploads' },
        { step: 'themes',  label: 'Themes' },
        { step: 'plugins', label: 'Plugins' }
    ];

    for (var i = 0; i < steps.length; i++) {
        yswmLog(log, '⏳ ' + steps[i].label + '...', 'yswm-step');
        r = await yswmAjax('push_step', { step: steps[i].step, batch_id: batchId });
        if (!r.success) { yswmLog(log, '✗ ' + r.data, 'yswm-err'); return; }
        yswmLog(log, '✓ ' + r.data, 'yswm-ok');
    }

    yswmLog(log, '⏳ Manifest...', 'yswm-step');
    r = await yswmAjax('push_step', { step: 'manifest', batch_id: batchId });
    if (!r.success) { yswmLog(log, '✗ ' + r.data, 'yswm-err'); return; }

    var manifestResult = JSON.parse(r.data);
    var migrateCode = manifestResult.migrate_code;

    yswmLog(log, '✓ Manifest committed', 'yswm-ok');
    yswmLog(log, '🎉 Push complete!', 'yswm-ok');
    yswmLog(log, 'Migration code: ' + migrateCode, 'yswm-info');

    yswmShowPull(migrateCode);
}

function yswmShowPull(migrateCode) {
    var card = document.getElementById('yswm-pull-card');
    var el = document.getElementById('yswm-pull-cmd');

    var cmd = "wp eval-file wp-content/plugins/ystack-wp-migrate/pull-cli.php -- --code='" + migrateCode.replace(/'/g, "'\\''") + "'";

    if (card) card.style.display = '';
    el.style.display = 'block';
    el.innerHTML = '<pre id="yswm-cmd-text" style="white-space:pre-wrap;word-break:break-all;user-select:all;margin:0;padding-right:2.5em">' + cmd + '</pre>'
        + '<button onclick="yswmCopyCmd()" title="Copy" style="position:absolute;top:8px;right:8px;background:none;border:1px solid #c3c4c7;border-radius:3px;cursor:pointer;padding:4px 6px;font-size:14px;line-height:1;color:#50575e" id="yswm-copy-btn">&#128203;</button>';

    // Installer download link
    var link = document.getElementById('yswm-installer-link');
    if (link) {
        link.href = yswm.ajaxurl + '?action=yswm_download_installer&nonce=' + encodeURIComponent(yswm.nonce) + '&code=' + encodeURIComponent(migrateCode);
        link.style.display = '';
    }
}

function yswmCopyCmd() {
    var text = document.getElementById('yswm-cmd-text').textContent;
    navigator.clipboard.writeText(text).then(function () {
        var btn = document.getElementById('yswm-copy-btn');
        btn.textContent = '✓';
        setTimeout(function () { btn.innerHTML = '&#128203;'; }, 1500);
    });
}

// Show pull options on page load if last code exists
if (yswm.lastCode) {
    yswmShowPull(yswm.lastCode);
}
