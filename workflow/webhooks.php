<?php
/**
 * Workflows — outbound webhook delivery log.
 *
 * Read-only view over the webhook_deliveries queue: what the send_webhook action
 * has queued, its status, retries, the response, and a Replay button. Mirrors
 * the tickets-settings chrome (shared inbox.css primitives).
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
I18n::initFromSession();

$current_page = 'webhooks';
$path_prefix  = '../';
$translationNamespaces = ['common', 'workflow'];
$apiBase = BASE_URL . 'api/workflow';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webhook deliveries</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/workflow.css?v=4">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js"></script>
    <style>
        .container { height: calc(100vh - 48px); overflow-y: auto; max-width: none; }
        .wh-filters { display: flex; gap: 8px; flex-wrap: wrap; margin: 0 0 14px; }
        .wh-chip { padding: 5px 12px; border: 1px solid #d7dce1; border-radius: 16px; background: #fff; font-size: 12.5px; color: #445; cursor: pointer; }
        .wh-chip.active { background: #546e7a; color: #fff; border-color: #546e7a; }
        .wh-chip .n { opacity: 0.7; margin-left: 4px; }
        table.wh { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        table.wh th { text-align: left; color: #78909c; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px; padding: 8px 10px; border-bottom: 1px solid #e8ecef; }
        table.wh td { padding: 8px 10px; border-bottom: 1px solid #f2f4f6; vertical-align: middle; }
        table.wh code { font-size: 11.5px; }
        .st { display: inline-block; padding: 2px 9px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .st.delivered { background: #e6f4ea; color: #1e7e34; }
        .st.pending, .st.delivering { background: #e8eef2; color: #465a66; }
        .st.failed { background: #fdf0e2; color: #b26a00; }
        .st.dead { background: #fce8e8; color: #c0392b; }
        .wh-url { font-family: Consolas, Monaco, monospace; font-size: 11.5px; color: #37474f; max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; vertical-align: bottom; }
        .wh-empty { padding: 30px; text-align: center; color: #90a4ae; font-size: 13px; }
        .wh-setup { background: #f8fafb; border: 1px solid #e8eef1; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 12.5px; color: #55606a; }
        .wh-setup code { background: #eef2f4; padding: 1px 5px; border-radius: 3px; }
        .modal-body pre { background: #263238; color: #eceff1; border-radius: 6px; padding: 12px; font-size: 11.5px; overflow: auto; max-height: 260px; white-space: pre-wrap; word-break: break-word; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="container">
        <div class="tab-content active">
            <div class="section-header">
                <h2>Webhook deliveries</h2>
                <button class="add-btn" id="refreshBtn" type="button">Refresh</button>
            </div>

            <div class="wh-setup">
                Deliveries are queued by the <code>Send a webhook</code> workflow action and sent by a background worker with automatic retries.
                Schedule <code>cron/webhook_deliveries.php</code> to run every minute (CLI, or HTTP with <code>?token=&lt;webhook_cron_token&gt;</code>) — see <code>docs/webhook-cron-setup.md</code>.
            </div>

            <div class="wh-filters" id="filters"></div>

            <div id="tableWrap">
                <table class="wh">
                    <thead>
                        <tr>
                            <th>When</th><th>Workflow</th><th>Format</th><th>URL</th>
                            <th>Status</th><th>Attempts</th><th>Last code</th><th>Next retry</th><th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="rows"></tbody>
                </table>
                <div class="wh-empty" id="empty" style="display:none;">No webhook deliveries yet. Add a <em>Send a webhook</em> action to a workflow and trigger it.</div>
            </div>
        </div>
    </div>

    <!-- Payload modal -->
    <div class="modal" id="payloadModal" style="display:none;">
        <div class="modal-content" style="max-width: 620px;">
            <div class="modal-header"><h3 id="pmTitle">Delivery</h3><button class="modal-close" id="pmClose" type="button">&times;</button></div>
            <div class="modal-body" id="pmBody"></div>
        </div>
    </div>

    <script>
    const API = '<?php echo htmlspecialchars($apiBase); ?>';
    let filter = '';
    const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
    const host = u => { try { return new URL(u).host; } catch (e) { return u; } };
    const fmt = s => s ? s.replace('T', ' ').replace(/\.\d+Z?$/, '') + ' UTC' : '';
    let cache = [];

    async function load() {
        const res = await fetch(API + '/deliveries.php' + (filter ? '?status=' + filter : ''), { credentials: 'same-origin' });
        const data = await res.json();
        if (!data.success) { document.getElementById('rows').innerHTML = '<tr><td colspan="9">' + esc(data.error || 'Error') + '</td></tr>'; return; }
        cache = data.deliveries;
        renderFilters(data.summary);
        renderRows(data.deliveries);
    }

    function renderFilters(summary) {
        const total = Object.values(summary).reduce((a, b) => a + b, 0);
        const defs = [['', 'All', total], ['pending', 'Pending', summary.pending || 0], ['delivered', 'Delivered', summary.delivered || 0],
                      ['failed', 'Retrying', summary.failed || 0], ['dead', 'Failed', summary.dead || 0]];
        document.getElementById('filters').innerHTML = defs.map(([v, l, n]) =>
            `<button class="wh-chip ${filter === v ? 'active' : ''}" data-f="${v}">${l}<span class="n">${n}</span></button>`).join('');
        document.querySelectorAll('.wh-chip').forEach(c => c.onclick = () => { filter = c.dataset.f; load(); });
    }

    function renderRows(rows) {
        document.getElementById('empty').style.display = rows.length ? 'none' : 'block';
        document.getElementById('rows').innerHTML = rows.map(r => {
            const statusLabel = r.status === 'failed' ? 'retrying' : (r.status === 'dead' ? 'failed' : r.status);
            const replay = (r.status === 'delivered' || r.status === 'failed' || r.status === 'dead')
                ? `<button class="table-action-btn" data-replay="${r.id}" title="Send again">Replay</button>` : '';
            return `<tr>
                <td>${esc(fmt(r.created))}</td>
                <td>${esc(r.workflow)}</td>
                <td>${esc(r.preset || 'custom')}</td>
                <td><span class="wh-url" title="${esc(r.url)}">${esc(host(r.url))}</span></td>
                <td><span class="st ${r.status}">${esc(statusLabel)}</span></td>
                <td>${r.attempts}/${r.max_attempts}</td>
                <td>${r.last_status !== null ? r.last_status : '—'}</td>
                <td>${r.status === 'failed' && r.next_attempt ? esc(fmt(r.next_attempt)) : '—'}</td>
                <td style="text-align:right; white-space:nowrap;">
                    <button class="table-action-btn" data-view="${r.id}">View</button> ${replay}
                </td></tr>`;
        }).join('');
        document.querySelectorAll('[data-view]').forEach(b => b.onclick = () => view(+b.dataset.view));
        document.querySelectorAll('[data-replay]').forEach(b => b.onclick = () => replay(+b.dataset.replay));
    }

    function view(id) {
        const r = cache.find(x => x.id === id);
        if (!r) return;
        document.getElementById('pmTitle').textContent = 'Delivery #' + r.id + ' — ' + (r.preset || 'custom');
        document.getElementById('pmBody').innerHTML =
            '<p style="font-size:12px;color:#667;margin:0 0 8px;">' + esc(r.method) + ' ' + esc(r.url) + '</p>'
            + '<strong style="font-size:12px;">Request headers</strong><pre>' + esc((r.headers || []).join('\n')) + '</pre>'
            + '<strong style="font-size:12px;">Request body</strong><pre>' + esc(r.body || '') + '</pre>'
            + (r.response ? '<strong style="font-size:12px;">Last response' + (r.last_status ? ' (HTTP ' + r.last_status + ')' : '') + '</strong><pre>' + esc(r.response) + '</pre>' : '')
            + (r.last_error ? '<strong style="font-size:12px;color:#c0392b;">Last error</strong><pre>' + esc(r.last_error) + '</pre>' : '');
        document.getElementById('payloadModal').style.display = 'flex';
    }

    async function replay(id) {
        const res = await fetch(API + '/deliveries_replay.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id })
        });
        const data = await res.json();
        if (!data.success) alert(data.error || 'Replay failed');
        load();
    }

    document.getElementById('refreshBtn').onclick = load;
    document.getElementById('pmClose').onclick = () => document.getElementById('payloadModal').style.display = 'none';
    document.getElementById('payloadModal').onclick = e => { if (e.target.id === 'payloadModal') e.target.style.display = 'none'; };
    load();
    </script>
</body>
</html>
