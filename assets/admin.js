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

    // Auto-fill pull fields from push context
    var fields = {
        'ml-pull-site-id': migrateLite.siteId,
        'ml-pull-batch-id': batchId,
        'ml-pull-source-url': location.origin,
        'ml-pull-source-path': migrateLite.abspath
    };
    for (var id in fields) {
        var el = document.getElementById(id);
        if (el) el.value = fields[id];
    }
    mlGenPullCmd();
}

function mlGenPullCmd() {
    var el = document.getElementById('ml-pull-cmd');
    var siteId = document.getElementById('ml-pull-site-id').value;
    var batchId = document.getElementById('ml-pull-batch-id').value;
    var srcUrl = document.getElementById('ml-pull-source-url').value;
    var srcPath = document.getElementById('ml-pull-source-path').value;

    if (!siteId || !batchId || !srcUrl) {
        alert('Site ID, Batch ID, and Source URL are required');
        return;
    }

    function sq(s) { return "'" + s.replace(/'/g, "'\\''") + "'"; }
    var wpPath = ' --path=' + sq(migrateLite.abspath);

    var cmd = 'wp' + wpPath + ' eval-file ' + sq(migrateLite.pullScript) + ' -- \\\n'
        + '  --site-id=' + sq(siteId) + ' \\\n'
        + '  --batch-id=' + sq(batchId) + ' \\\n'
        + '  --search=' + sq(srcUrl) + ' \\\n'
        + '  --replace="$(wp' + wpPath + ' option get siteurl)"';

    if (srcPath) {
        cmd += ' \\\n  --search-path=' + sq(srcPath)
            + ' \\\n  --replace-path=' + sq(migrateLite.abspath);
    }

    el.style.display = 'block';
    el.innerHTML = '<div class="ml-info">Run on the target site:</div>'
        + '<pre style="white-space:pre-wrap;word-break:break-all;user-select:all">' + cmd + '</pre>';
}
