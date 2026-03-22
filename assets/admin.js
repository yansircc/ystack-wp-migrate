/* global migrateLite */

function mlLog(id, msg, cls) {
    var el = document.getElementById(id);
    el.style.display = 'block';
    el.innerHTML += '<div class="' + (cls || '') + '">' + msg + '</div>';
    el.scrollTop = el.scrollHeight;
}

function mlAjax(action, data) {
    data.action = 'migrate_lite_' + action;
    data.nonce = migrateLite.nonce;
    return fetch(migrateLite.ajaxurl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(data)
    }).then(function (r) { return r.json(); });
}

function mlSaveSettings() {
    mlAjax('save_settings', {
        worker_url: document.getElementById('ml-worker-url').value,
        auth_token: document.getElementById('ml-auth-token').value
    }).then(function (r) { alert(r.success ? 'Saved!' : 'Error: ' + r.data); });
}

async function mlPush() {
    var log = 'ml-push-log';
    document.getElementById(log).innerHTML = '';

    var r = await mlAjax('push_step', { step: 'init' });
    if (!r.success) { mlLog(log, '✗ ' + r.data, 'ml-err'); return; }
    var batchId = r.data.batch_id;
    mlLog(log, 'Batch: ' + batchId, 'ml-info');

    var steps = [
        { step: 'db',      label: 'Database' },
        { step: 'uploads', label: 'Uploads' },
        { step: 'themes',  label: 'Themes' },
        { step: 'plugins', label: 'Plugins' }
    ];

    for (var i = 0; i < steps.length; i++) {
        mlLog(log, '⏳ ' + steps[i].label + '...', 'ml-step');
        r = await mlAjax('push_step', { step: steps[i].step, batch_id: batchId });
        if (!r.success) { mlLog(log, '✗ ' + r.data, 'ml-err'); return; }
        mlLog(log, '✓ ' + r.data, 'ml-ok');
    }

    mlLog(log, '⏳ Manifest...', 'ml-step');
    r = await mlAjax('push_step', { step: 'manifest', batch_id: batchId });
    if (!r.success) { mlLog(log, '✗ ' + r.data, 'ml-err'); return; }
    mlLog(log, '✓ ' + r.data, 'ml-ok');
    mlLog(log, '🎉 Push complete! Site: ' + migrateLite.siteId + ' / Batch: ' + batchId, 'ml-ok');

    // Auto-fill pull command batch ID
    var batchInput = document.getElementById('ml-pull-batch-id');
    if (batchInput) batchInput.value = batchId;
}

function mlGenPullCmd() {
    var el = document.getElementById('ml-pull-cmd');
    var siteId = document.getElementById('ml-pull-site-id').value;
    var batchId = document.getElementById('ml-pull-batch-id').value;
    var srcUrl = document.getElementById('ml-pull-source-url').value;
    var srcPath = document.getElementById('ml-pull-source-path').value;
    var workerUrl = document.getElementById('ml-worker-url').value;
    var token = document.getElementById('ml-auth-token').value;

    if (!siteId || !batchId || !srcUrl) {
        alert('Site ID, Batch ID, and Source URL are required');
        return;
    }

    var cmd = 'wp eval-file ' + migrateLite.pullScript + ' -- \\\n'
        + '  --worker=' + workerUrl + ' \\\n'
        + '  --token=' + token + ' \\\n'
        + '  --site-id=' + siteId + ' \\\n'
        + '  --batch-id=' + batchId + ' \\\n'
        + '  --search=' + srcUrl + ' \\\n'
        + '  --replace=$(wp option get siteurl)';

    if (srcPath) {
        cmd += ' \\\n  --search-path=' + srcPath + ' \\\n'
            + '  --replace-path=$(wp eval "echo rtrim(ABSPATH, \'/\');")';
    }

    el.style.display = 'block';
    el.innerHTML = '<div class="ml-info">Run this on the target site:</div>'
        + '<pre style="white-space:pre-wrap;word-break:break-all;user-select:all">' + cmd + '</pre>';
}
