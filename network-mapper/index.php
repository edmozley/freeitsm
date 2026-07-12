<?php
/**
 * Network Mapper â Diagrams landing page.
 *
 * Lists the current (leaf) version of every diagram chain. From here the user
 * either opens an existing diagram or creates a brand-new one. Versions of a
 * given diagram are managed inside diagram.php.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../login.php');
    exit;
}

requireModuleAccess('network-mapper');

$current_page = 'diagrams';
$path_prefix = '../';
$translationNamespaces = ['common', 'network-mapper'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('network-mapper.index.browser_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=21">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <style>
        body { --accent: var(--nm-accent, #06b6d4); background: var(--app-bg, #f5f5f5); height: 100vh; overflow: hidden; }

        .nm-page {
            height: calc(100vh - 60px);
            display: flex;
            flex-direction: column;
            background: var(--app-bg, #f5f5f5);
        }

        .nm-toolbar {
            padding: 16px 24px;
            background: var(--surface, #fff);
            border-bottom: 1px solid var(--border, #e5e7eb);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        .nm-toolbar h1 { margin: 0; font-size: 20px; color: var(--text, #111827); }
        .nm-toolbar .actions { display: flex; gap: 12px; align-items: center; }
        .nm-toolbar input[type="text"] {
            padding: 8px 12px;
            border: 1px solid var(--border, #d1d5db);
            border-radius: 4px;
            font-size: 13px;
            width: 260px;
        }
        .nm-toolbar input[type="text"]:focus { outline: none; border-color: var(--nm-accent, #06b6d4); box-shadow: 0 0 0 3px rgba(6,182,212,0.12); }

        .nm-btn {
            padding: 8px 16px;
            background: var(--nm-accent, #06b6d4);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            font-size: 13px;
        }
        .nm-btn:hover { background: var(--nm-accent-hover, #0891b2); }
        .nm-btn.secondary { background: var(--surface, #fff); color: var(--text-muted, #374151); border: 1px solid var(--border, #d1d5db); }
        .nm-btn.secondary:hover { background: var(--surface-hover, #f9fafb); }
        .nm-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .nm-list-wrap { flex: 1; overflow: auto; padding: 20px 24px 32px 24px; }

        .nm-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 16px;
        }

        .nm-card {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 8px;
            padding: 16px 18px;
            cursor: pointer;
            transition: border-color 0.12s, box-shadow 0.12s, transform 0.12s;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .nm-card:hover {
            border-color: var(--nm-accent, #06b6d4);
            box-shadow: 0 8px 18px rgba(6,182,212,0.16);
            transform: translateY(-3px);
        }
        .nm-card-title-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }
        .nm-card-title {
            font-size: 15px;
            font-weight: 600;
            color: var(--text, #111827);
            margin: 0;
            line-height: 1.3;
        }
        .nm-version-pill {
            display: inline-block;
            background: var(--nm-accent-soft, #ecfeff);
            color: #0e7490;
            border: 1px solid #a5f3fc;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }
        .nm-card-desc {
            color: var(--text-dim, #6b7280);
            font-size: 13px;
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .nm-card-desc.empty { color: var(--text-faint, #9ca3af); font-style: italic; }
        .nm-card-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: var(--text-dim, #6b7280);
            padding-top: 8px;
            border-top: 1px solid var(--border-soft, #f3f4f6);
        }
        .nm-card-stats {
            display: flex;
            gap: 12px;
        }
        .nm-card-stats span strong { color: var(--text-muted, #374151); }
        .nm-card-actions { display: flex; gap: 6px; }
        .nm-card-action-btn {
            background: transparent;
            border: 1px solid var(--border, #e5e7eb);
            color: var(--text-dim, #6b7280);
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            transition: all 0.12s;
        }
        .nm-card-action-btn:hover { background: var(--surface-hover, #f9fafb); color: var(--text, #111827); border-color: var(--border, #d1d5db); }
        .nm-card-action-btn.danger:hover { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }

        .nm-empty {
            text-align: center;
            padding: 80px 40px;
            color: var(--text-dim, #6b7280);
        }
        .nm-empty h2 { color: var(--text-muted, #374151); font-weight: 600; margin: 0 0 8px 0; }
        .nm-empty p { margin: 0 0 18px 0; font-size: 14px; }

        /* Modal */
        .nm-modal-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.4);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .nm-modal-overlay.active { display: flex; }
        .nm-modal {
            background: var(--surface, #fff);
            border-radius: 8px;
            width: 480px;
            max-width: 95vw;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
        }
        .nm-modal-header {
            padding: 16px 22px;
            border-bottom: 1px solid var(--border, #e5e7eb);
            font-weight: 600;
            font-size: 16px;
            color: var(--text, #111827);
        }
        .nm-modal-body { padding: 22px; flex: 1; overflow-y: auto; }
        .nm-modal-actions {
            padding: 14px 22px;
            border-top: 1px solid var(--border, #e5e7eb);
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .nm-form-group { margin-bottom: 14px; }
        .nm-form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-muted, #374151);
            margin-bottom: 5px;
        }
        .nm-form-group input, .nm-form-group textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid var(--border, #d1d5db);
            border-radius: 4px;
            font-size: 14px;
            font-family: inherit;
            box-sizing: border-box;
        }
        .nm-form-group textarea { resize: vertical; min-height: 70px; }
        .nm-form-group input:focus, .nm-form-group textarea:focus {
            outline: none;
            border-color: var(--nm-accent, #06b6d4);
            box-shadow: 0 0 0 3px rgba(6,182,212,0.12);
        }
        .nm-form-group small { color: var(--text-dim, #6b7280); font-size: 12px; display: block; margin-top: 4px; }

        /* Pale-red danger-hover wash → dark red in dark mode so it does not glow. */
        [data-theme-mode="dark"] .nm-card-action-btn.danger:hover { background: #3a1a1a; color: #fca5a5; border-color: #5a2a2a; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="nm-page">
        <div class="nm-toolbar">
            <h1><?php echo htmlspecialchars(t('network-mapper.index.heading')); ?></h1>
            <div class="actions">
                <input type="text" id="searchInput" placeholder="<?php echo htmlspecialchars(t('network-mapper.index.filter_placeholder')); ?>" oninput="filterDiagrams(this.value)">
                <button class="nm-btn" onclick="openNewModal()">+ <?php echo htmlspecialchars(t('network-mapper.index.new')); ?></button>
            </div>
        </div>

        <div class="nm-list-wrap">
            <div id="diagramListContainer">
                <div class="nm-empty"><p><?php echo htmlspecialchars(t('network-mapper.index.loading')); ?></p></div>
            </div>
        </div>
    </div>

    <!-- New diagram modal -->
    <div class="nm-modal-overlay" id="newModal">
        <div class="nm-modal">
            <div class="nm-modal-header"><?php echo htmlspecialchars(t('network-mapper.index.modal_title')); ?></div>
            <div class="nm-modal-body">
                <div class="nm-form-group">
                    <label for="newTitle"><?php echo htmlspecialchars(t('network-mapper.index.field_title')); ?></label>
                    <input type="text" id="newTitle" maxlength="255" placeholder="<?php echo htmlspecialchars(t('network-mapper.index.field_title_ph')); ?>">
                </div>
                <div class="nm-form-group">
                    <label for="newDescription"><?php echo htmlspecialchars(t('network-mapper.index.field_description')); ?></label>
                    <textarea id="newDescription" maxlength="2000" placeholder="<?php echo htmlspecialchars(t('network-mapper.index.field_description_ph')); ?>"></textarea>
                </div>
                <div class="nm-form-group">
                    <label for="newVersionLabel"><?php echo htmlspecialchars(t('network-mapper.index.field_version')); ?></label>
                    <input type="text" id="newVersionLabel" maxlength="50" value="v1" placeholder="<?php echo htmlspecialchars(t('network-mapper.index.field_version_ph')); ?>">
                    <small><?php echo htmlspecialchars(t('network-mapper.index.field_version_help')); ?></small>
                </div>
            </div>
            <div class="nm-modal-actions">
                <button class="nm-btn secondary" onclick="closeNewModal()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button class="nm-btn" id="createBtn" onclick="createDiagram()"><?php echo htmlspecialchars(t('network-mapper.index.create')); ?></button>
            </div>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <script>
        const API = '../api/network-mapper/';
        let allDiagrams = [];

        async function loadDiagrams() {
            const container = document.getElementById('diagramListContainer');
            container.innerHTML = '<div class="nm-empty"><p>' + escapeHtml(t('network-mapper.index.loading')) + '</p></div>';
            try {
                const resp = await fetch(API + 'list_diagrams.php');
                const data = await resp.json();
                if (!data.success) throw new Error(data.error || 'Failed to load');
                allDiagrams = data.diagrams || [];
                renderDiagrams(allDiagrams);
            } catch (e) {
                container.innerHTML = '<div class="nm-empty"><p style="color:#b91c1c;">' + escapeHtml(t('network-mapper.index.load_failed', { message: e.message })) + '</p></div>';
            }
        }

        function renderDiagrams(list) {
            const container = document.getElementById('diagramListContainer');
            if (!list.length) {
                container.innerHTML = `
                    <div class="nm-empty">
                        <h2>${escapeHtml(t('network-mapper.index.empty_heading'))}</h2>
                        <p>${escapeHtml(t('network-mapper.index.empty_body'))}</p>
                        <button class="nm-btn" onclick="openNewModal()">${escapeHtml(t('network-mapper.index.empty_create'))}</button>
                    </div>`;
                return;
            }
            const html = '<div class="nm-grid">' + list.map(diagramCardHtml).join('') + '</div>';
            container.innerHTML = html;
        }

        function diagramCardHtml(d) {
            const desc = d.description ? escapeHtml(d.description) : '<span class="empty">' + escapeHtml(t('network-mapper.index.no_description')) + '</span>';
            const updated = d.updated_datetime ? parseUTCDate(d.updated_datetime).toLocaleString(undefined, tzOpts({})) : '';
            const author = d.author_name ? d.author_name : t('network-mapper.index.author_unknown');
            const versionLabel = d.version_label ? escapeHtml(d.version_label) : escapeHtml(t('network-mapper.index.version_unknown'));
            const versionCount = d.version_count > 1 ? escapeHtml(t('network-mapper.index.versions_suffix', { count: d.version_count })) : '';
            return `
                <div class="nm-card" onclick="openDiagram(${d.id})">
                    <div class="nm-card-title-row">
                        <h3 class="nm-card-title">${escapeHtml(d.title)}</h3>
                        <span class="nm-version-pill">${versionLabel}${versionCount}</span>
                    </div>
                    <div class="nm-card-desc">${desc}</div>
                    <div class="nm-card-meta">
                        <div class="nm-card-stats">
                            <span><strong>${d.node_count}</strong> ${escapeHtml(t('network-mapper.index.nodes'))}</span>
                            <span><strong>${d.connector_count}</strong> ${escapeHtml(t('network-mapper.index.connectors'))}</span>
                        </div>
                        <div class="nm-card-actions">
                            <button class="nm-card-action-btn danger" onclick="event.stopPropagation(); deleteDiagram(${d.id}, '${escapeAttr(d.title)}')">${escapeHtml(t('common.delete'))}</button>
                        </div>
                    </div>
                    <div style="font-size:11px;color:var(--text-faint, #9ca3af);">${escapeHtml(t('network-mapper.index.meta_by', { author: author, date: updated }))}</div>
                </div>`;
        }

        function filterDiagrams(q) {
            const lower = (q || '').toLowerCase().trim();
            if (!lower) { renderDiagrams(allDiagrams); return; }
            renderDiagrams(allDiagrams.filter(d =>
                (d.title || '').toLowerCase().includes(lower) ||
                (d.description || '').toLowerCase().includes(lower)
            ));
        }

        function openDiagram(id) { window.location.href = 'diagram.php?id=' + id; }

        function openNewModal() {
            document.getElementById('newTitle').value = '';
            document.getElementById('newDescription').value = '';
            document.getElementById('newVersionLabel').value = 'v1';
            document.getElementById('newModal').classList.add('active');
            setTimeout(() => document.getElementById('newTitle').focus(), 50);
        }
        function closeNewModal() { document.getElementById('newModal').classList.remove('active'); }

        async function createDiagram() {
            const title = document.getElementById('newTitle').value.trim();
            const description = document.getElementById('newDescription').value.trim();
            const versionLabel = document.getElementById('newVersionLabel').value.trim() || 'v1';
            if (!title) { if (window.showToast) showToast(t('network-mapper.index.title_required'), 'error'); return; }
            const btn = document.getElementById('createBtn');
            btn.disabled = true;
            try {
                const resp = await fetch(API + 'create_diagram.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ title, description, version_label: versionLabel })
                });
                const data = await resp.json();
                if (!data.success) throw new Error(data.error || 'Failed to create');
                window.location.href = 'diagram.php?id=' + data.id;
            } catch (e) {
                if (window.showToast) showToast(t('network-mapper.index.create_failed', { message: e.message }), 'error');
                btn.disabled = false;
            }
        }

        async function deleteDiagram(id, title) {
            if (!(await showConfirm({ title: t('network-mapper.index.delete_title'), message: t('network-mapper.index.delete_confirm', { title: title }), okLabel: t('common.delete'), okClass: 'danger' }))) return;
            try {
                const resp = await fetch(API + 'delete_diagram.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await resp.json();
                if (!data.success) throw new Error(data.error || 'Failed to delete');
                if (window.showToast) showToast(t('network-mapper.index.deleted'), 'success');
                loadDiagrams();
            } catch (e) {
                if (window.showToast) showToast(t('network-mapper.index.delete_failed', { message: e.message }), 'error');
            }
        }

        function escapeHtml(s) {
            return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        function escapeAttr(s) { return escapeHtml(s).replace(/'/g, "\\'"); }

        document.getElementById('newModal').addEventListener('click', e => { if (e.target === e.currentTarget) closeNewModal(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeNewModal(); });

        loadDiagrams();
    </script>
</body>
</html>
