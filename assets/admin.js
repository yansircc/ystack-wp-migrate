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

    var migrateCode = migrateLite.siteId + '/' + batchId;
    mlLog(log, '🎉 Push complete!', 'ml-ok');
    mlLog(log, 'Migration code: ' + migrateCode, 'ml-info');

    mlShowPullCmd(batchId);
}

function mlShowPullCmd(batchId) {
    var card = document.getElementById('ml-pull-card');
    var el = document.getElementById('ml-pull-cmd');
    function sq(s) { return "'" + s.replace(/'/g, "'\\''") + "'"; }

    var cmd = 'wp eval-file ' + sq(migrateLite.pullScript) + ' -- \\\n'
        + '  --site-id=' + sq(migrateLite.siteId) + ' \\\n'
        + '  --batch-id=' + sq(batchId) + ' \\\n'
        + '  --search=' + sq(migrateLite.siteUrl) + ' \\\n'
        + '  --replace="$(wp option get siteurl)" \\\n'
        + '  --search-path=' + sq(migrateLite.abspath) + ' \\\n'
        + '  --replace-path="$(wp eval \'echo rtrim(ABSPATH, \"/\");\')"';

    if (card) card.style.display = '';
    el.style.display = 'block';
    el.innerHTML = '<pre style="white-space:pre-wrap;word-break:break-all;user-select:all;margin:0">' + cmd + '</pre>';
}

// Show pull command on page load if last batch exists
if (migrateLite.lastBatch) {
    mlShowPullCmd(migrateLite.lastBatch);
}
