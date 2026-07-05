<?php
/**
 * System - Orphaned tickets.
 *
 * Lists tickets whose department_id points at a department that no longer
 * exists (so they're hidden from every team-filtered queue) and lets an admin
 * reassign them to a real department, one at a time or in bulk.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/functions.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'orphaned-tickets';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Orphaned tickets</title>
    <link rel="stylesheet" href="<?php echo $path_prefix; ?>assets/css/inbox.css">
    <style>
        .orph-wrap { height: calc(100vh - 48px); overflow-y: auto; background: #f5f7fa; padding: 24px 28px 60px; }
        .orph-wrap h2 { font-size: 22px; color: #333; margin: 0 0 6px; }
        .orph-sub { font-size: 13px; color: #888; margin: 0 0 18px; max-width: 760px; line-height: 1.5; }

        .orph-bulkbar { display: none; align-items: center; gap: 10px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; flex-wrap: wrap; }
        .orph-bulkbar.show { display: flex; }
        .orph-bulkbar .sel { font-size: 13px; color: #555; }
        select.orph-select { padding: 7px 10px; border: 1px solid #d6dde3; border-radius: 6px; font-size: 13px; background: #fff; }
        .orph-btn { background: #546e7a; color: #fff; border: none; padding: 8px 16px; border-radius: 6px; font-size: 13px; cursor: pointer; }
        .orph-btn:hover { background: #37474f; }
        .orph-btn:disabled { background: #bbb; cursor: not-allowed; }
        .orph-btn.small { padding: 6px 12px; font-size: 12px; }

        table.orph { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
        table.orph th, table.orph td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #f2f2f2; font-size: 13px; color: #444; vertical-align: middle; }
        table.orph th { background: #f9fafb; color: #1f2330; font-weight: 600; font-size: 12px; }
        table.orph tr:last-child td { border-bottom: none; }
        .orph-ref { font-family: 'Consolas', monospace; font-size: 12px; color: #555; }
        .orph-subject { max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .orph-badwd { font-family: 'Consolas', monospace; font-size: 11px; background: #fdecea; color: #b71c1c; padding: 2px 7px; border-radius: 4px; }
        .orph-row-action { display: flex; gap: 6px; align-items: center; }

        .orph-empty { background: #ecfdf5; border: 1px solid #6ee7b7; color: #065f46; border-radius: 10px; padding: 20px; font-size: 14px; }
        .orph-loading, .orph-error { color: #888; font-size: 13px; padding: 18px; }
        .orph-error { color: #c0392b; }
        .orph-note { font-size: 12px; color: #999; margin-top: 10px; }
    </style>
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo $path_prefix; ?>assets/js/tz.js?v=1"></script>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="orph-wrap">
        <h2>Orphaned tickets</h2>
        <p class="orph-sub">Tickets assigned to a department that no longer exists. They're hidden from every team's queue (they're neither "no department" nor in anyone's departments), so reassign them to a real department — or to "No department" — to make them visible again.</p>

        <div class="orph-bulkbar" id="bulkBar">
            <span class="sel" id="selCount">0 selected</span>
            <span>→</span>
            <select class="orph-select" id="bulkDept"></select>
            <button class="orph-btn" id="bulkAssign">Assign selected</button>
        </div>

        <div id="orphBody"><div class="orph-loading">Loading…</div></div>
    </div>

    <script>
    const API = '<?php echo $path_prefix; ?>api/system/';
    let DEPTS = [];
    let MULTI = false;

    function esc(s) { return (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
    function deptOptions(includeNone) {
        let html = '<option value="">— choose —</option>';
        if (includeNone) html = '<option value="">— No department —</option>';
        return html + DEPTS.map(d => `<option value="${d.id}">${esc(d.name)}</option>`).join('');
    }

    async function load() {
        const body = document.getElementById('orphBody');
        document.getElementById('bulkBar').classList.remove('show');
        body.innerHTML = '<div class="orph-loading">Loading…</div>';
        try {
            const r = await fetch(API + 'get_orphaned_tickets.php', { credentials: 'same-origin' });
            const d = await r.json();
            if (!d.success) throw new Error(d.error || 'failed');
            DEPTS = d.departments || [];
            MULTI = d.multi_tenant;

            if (!d.tickets.length) {
                body.innerHTML = '<div class="orph-empty">✅ No orphaned tickets — every ticket\'s department resolves correctly.</div>';
                return;
            }

            document.getElementById('bulkDept').innerHTML = deptOptions(true);
            document.getElementById('bulkBar').classList.add('show');

            const rows = d.tickets.map(t => `
                <tr data-id="${t.id}">
                    <td><input type="checkbox" class="rowChk"></td>
                    <td class="orph-ref">${esc(t.ticket_number)}</td>
                    <td class="orph-subject" title="${esc(t.subject)}">${esc(t.subject || '(no subject)')}</td>
                    ${MULTI ? `<td>${esc(t.company || '—')}</td>` : ''}
                    <td>${esc(t.requester || '—')}</td>
                    <td>${esc(t.status || '—')}</td>
                    <td><span class="orph-badwd">dept #${t.department_id}</span></td>
                    <td class="orph-row-action">
                        <select class="orph-select rowDept">${deptOptions(false)}</select>
                        <button class="orph-btn small rowAssign">Assign</button>
                    </td>
                </tr>`).join('');

            body.innerHTML = `
                ${d.truncated ? `<p class="orph-note">Showing the first ${d.limit} — reassign these, then reload for more.</p>` : ''}
                <table class="orph">
                    <thead><tr>
                        <th><input type="checkbox" id="chkAll"></th>
                        <th>Ticket</th><th>Subject</th>${MULTI ? '<th>Company</th>' : ''}<th>Requester</th><th>Status</th><th>Bad department</th><th>Reassign to</th>
                    </tr></thead>
                    <tbody id="orphRows">${rows}</tbody>
                </table>
                <p class="orph-note">${d.tickets.length} orphaned ticket(s).</p>`;
            wire();
        } catch (e) {
            body.innerHTML = '<div class="orph-error">Could not load: ' + esc(e.message) + '</div>';
        }
    }

    async function assign(ticketIds, departmentId, btn) {
        if (!ticketIds.length) return;
        if (btn) btn.disabled = true;
        try {
            const r = await fetch(API + 'assign_ticket_department.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ticket_ids: ticketIds, department_id: departmentId === '' ? null : departmentId })
            });
            const d = await r.json();
            if (!d.success) { alert('Failed: ' + d.error); if (btn) btn.disabled = false; return; }
            load(); // refresh
        } catch (e) {
            alert('Request failed: ' + e.message);
            if (btn) btn.disabled = false;
        }
    }

    function selectedIds() {
        return Array.prototype.slice.call(document.querySelectorAll('#orphRows .rowChk:checked'))
            .map(chk => parseInt(chk.closest('tr').getAttribute('data-id'), 10));
    }
    function refreshSelCount() {
        const n = selectedIds().length;
        document.getElementById('selCount').textContent = n + ' selected';
    }

    function wire() {
        document.querySelectorAll('#orphRows .rowAssign').forEach(btn => {
            btn.addEventListener('click', function () {
                const tr = this.closest('tr');
                const dept = tr.querySelector('.rowDept').value;
                assign([parseInt(tr.getAttribute('data-id'), 10)], dept, this);
            });
        });
        document.querySelectorAll('#orphRows .rowChk').forEach(chk => chk.addEventListener('change', refreshSelCount));
        const chkAll = document.getElementById('chkAll');
        if (chkAll) chkAll.addEventListener('change', function () {
            document.querySelectorAll('#orphRows .rowChk').forEach(c => c.checked = this.checked);
            refreshSelCount();
        });
    }

    document.getElementById('bulkAssign').addEventListener('click', function () {
        const ids = selectedIds();
        if (!ids.length) { alert('Select at least one ticket.'); return; }
        assign(ids, document.getElementById('bulkDept').value, this);
    });

    load();
    </script>
</body>
</html>
