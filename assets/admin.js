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

    // Init batch
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
        mlLog(log, '⏳ Pushing ' + steps[i].label + '...', 'ml-step');
        r = await mlAjax('push_step', { step: steps[i].step, batch_id: batchId });
        if (r.success) {
            mlLog(log, '✓ ' + r.data, 'ml-ok');
        } else {
            mlLog(log, '✗ ' + r.data, 'ml-err');
            return;
        }
    }

    // Commit manifest
    mlLog(log, '⏳ Committing manifest...', 'ml-step');
    r = await mlAjax('push_step', { step: 'manifest', batch_id: batchId });
    if (!r.success) { mlLog(log, '✗ ' + r.data, 'ml-err'); return; }
    mlLog(log, '✓ ' + r.data, 'ml-ok');
    mlLog(log, '🎉 Push complete! Site ID: ' + migrateLite.siteId + ', Batch: ' + batchId, 'ml-ok');
}

async function mlPull() {
    var log = 'ml-pull-log';
    document.getElementById(log).innerHTML = '';
    var siteId     = document.getElementById('ml-pull-site-id').value;
    var batchId    = document.getElementById('ml-pull-batch-id').value;
    var sourceUrl  = document.getElementById('ml-pull-source-url').value;
    var sourcePath = document.getElementById('ml-pull-source-path').value;
    var pullToken  = '';

    if (!siteId || !batchId || !sourceUrl) { alert('Site ID, Batch ID, and Source URL are required'); return; }

    // 1. Download from R2 by manifest (creates pull token)
    mlLog(log, '⏳ Downloading from R2...', 'ml-step');
    var r = await mlAjax('pull_step', { step: 'download', site_id: siteId, batch_id: batchId });
    if (!r.success) { mlLog(log, '✗ ' + r.data, 'ml-err'); return; }
    pullToken = r.data.pull_token;
    mlLog(log, '✓ ' + r.data.msg, 'ml-ok');

    // 2. Import DB (after this, nonce + session invalid — use pullToken)
    mlLog(log, '⏳ Importing database...', 'ml-step');
    r = await mlAjax('pull_step', { step: 'import_db', site_id: siteId, pull_token: pullToken });
    if (!r.success) { mlLog(log, '✗ ' + r.data, 'ml-err'); return; }
    mlLog(log, '✓ ' + r.data, 'ml-ok');

    // 3. Post-import prepare: restore operator + park plugins (mu-plugin)
    mlLog(log, '⏳ Preparing environment...', 'ml-step');
    r = await mlAjax('pull_step', { step: 'prepare', site_id: siteId, pull_token: pullToken });
    if (!r.success) { mlLog(log, '✗ ' + r.data, 'ml-err'); return; }
    mlLog(log, '✓ ' + r.data, 'ml-ok');

    // 4. Extract files (mu-plugin)
    mlLog(log, '⏳ Extracting files...', 'ml-step');
    r = await mlAjax('pull_step', { step: 'extract', site_id: siteId, pull_token: pullToken });
    if (!r.success) { mlLog(log, '✗ ' + r.data, 'ml-err'); return; }
    mlLog(log, '✓ ' + r.data, 'ml-ok');

    // 5. Search-replace per table (mu-plugin)
    mlLog(log, '⏳ Starting search-replace...', 'ml-step');
    r = await mlAjax('pull_step', { step: 'get_tables', site_id: siteId, source_url: sourceUrl, pull_token: pullToken });
    if (!r.success) { mlLog(log, '✗ ' + r.data, 'ml-err'); return; }
    var tables = r.data;
    mlLog(log, tables.length + ' tables to process', 'ml-info');

    for (var i = 0; i < tables.length; i++) {
        var pct = Math.round((i + 1) / tables.length * 100);
        r = await mlAjax('pull_step', {
            step: 'replace_table',
            table: tables[i],
            source_url: sourceUrl,
            source_path: sourcePath,
            site_id: siteId,
            pull_token: pullToken
        });
        if (!r.success) { mlLog(log, '✗ ' + r.data, 'ml-err'); return; }
        if (r.data.indexOf(': 0 ') === -1) {
            mlLog(log, '  [' + pct + '%] ' + r.data, 'ml-ok');
        }
    }

    // 5. Flush (handled by mu-plugin, which self-deletes)
    mlLog(log, '⏳ Flushing caches...', 'ml-step');
    r = await mlAjax('pull_step', { step: 'flush', site_id: siteId, pull_token: pullToken });
    if (!r.success) { mlLog(log, '✗ ' + r.data, 'ml-err'); return; }
    mlLog(log, '✓ ' + r.data, 'ml-ok');

    mlLog(log, '🎉 Pull complete! Site migrated.', 'ml-ok');
}
