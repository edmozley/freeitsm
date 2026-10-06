<?php
/**
 * System — Portal profile (#133).
 *
 * What a person may change about themselves in the self-service portal, and
 * whether a customer from an address book may do so too, their change being
 * sent to the address book (GDPR Art. 16).
 *
 * Only ever narrows USER_SELF_EDITABLE_FIELDS: manager, employee ID and
 * department are not offered at all, and the page says why.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
I18n::initFromSession();

$current_page = 'portal-profile';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];
requireModuleAccess('system');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName()); ?> - <?php echo htmlspecialchars(t('system.portal_profile.heading')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=24">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=76">
    <style>
        /* System accent, pinned with --on-accent alongside: System's accent is a
           LIGHT colour in dark mode, so without it buttons render white-on-light. */
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }

        /* Full width, edge to edge — the house style for settings screens. */
        .pp-container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            width: 100%;
            box-sizing: border-box;
            padding: 24px 32px 40px;
        }
        .pp-header { margin-bottom: 22px; }
        .pp-header h2 { margin: 0; font-size: 22px; color: var(--text, #333); }
        .pp-header p  { margin: 5px 0 0 0; font-size: 13px; color: var(--text-dim, #888); line-height: 1.55; }

        /* inbox.css's .btn-primary sets only background and colour. */
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: all .15s; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover:not(:disabled) { background: #455a64; }
        .btn:disabled { opacity: .55; cursor: progress; }

        .pp-panel {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 18px;
        }
        .pp-panel h3 {
            margin: 0 0 4px 0; font-size: 13px; text-transform: uppercase;
            letter-spacing: .5px; color: var(--text-dim, #888);
        }
        .pp-panel > p { margin: 0 0 14px; font-size: 13px; color: var(--text-muted, #666); line-height: 1.55; }

        .pp-row { display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; border-top: 1px solid var(--border-soft, #f1f1f1); }
        .pp-row input { margin-top: 3px; }
        .pp-row-name { font-size: 13.5px; font-weight: 600; color: var(--text, #333); }
        .pp-row-desc { font-size: 12.5px; color: var(--text-muted, #666); line-height: 1.5; }
        .pp-fixed { color: var(--text-muted, #666); }
        .pp-fixed .pp-row-name { color: var(--text-muted, #666); }
        .pp-tag { display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 500;
                  background: var(--surface-2, #f0f0f0); color: var(--text-muted, #777); }

        .pp-books { margin: 10px 0 0 26px; font-size: 12.5px; color: var(--text-muted, #666); line-height: 1.6; }
        .pp-books a { color: var(--accent); }
        .pp-warn {
            background: var(--warning-bg, #fef3c7);
            border: 1px solid var(--warning-border, #f0d9a8);
            color: var(--warning-text, #92400e);
            border-radius: 6px; padding: 10px 12px; margin: 10px 0 0 26px;
            font-size: 12.5px; line-height: 1.55;
        }
        .pp-saved { font-size: 13px; color: var(--success-text, #166534); margin-left: 12px; }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=168">
</head>
<body data-mobile-module="system" data-mobile-page="portal-profile">
    <?php include '../includes/header.php'; ?>

    <div class="pp-container">
        <div class="pp-header">
            <h2><?php echo htmlspecialchars(t('system.portal_profile.heading')); ?></h2>
            <p><?php echo t('system.portal_profile.intro'); ?></p>
        </div>

        <div class="pp-panel">
            <h3><?php echo htmlspecialchars(t('system.portal_profile.fields_heading')); ?></h3>
            <p><?php echo t('system.portal_profile.fields_desc'); ?></p>

            <?php /* Always on, and not a setting: it is how the portal and every
                     email address them, and no directory has an opinion on it. */ ?>
            <div class="pp-row pp-fixed">
                <input type="checkbox" checked disabled>
                <span>
                    <span class="pp-row-name"><?php echo htmlspecialchars(t('system.portal_profile.f_preferred_name')); ?></span>
                    <span class="pp-tag"><?php echo htmlspecialchars(t('system.portal_profile.always')); ?></span><br>
                    <span class="pp-row-desc"><?php echo htmlspecialchars(t('system.portal_profile.f_preferred_name_desc')); ?></span>
                </span>
            </div>

            <div id="ppFields"></div>

            <?php /* Never offered, and said so - an administrator looking for
                     "department" should find the reason, not an absence. */ ?>
            <?php foreach (['department', 'employee_id', 'manager_id', 'email'] as $never): ?>
            <div class="pp-row pp-fixed">
                <input type="checkbox" disabled>
                <span>
                    <span class="pp-row-name"><?php echo htmlspecialchars(t('system.portal_profile.f_' . $never)); ?></span>
                    <span class="pp-tag"><?php echo htmlspecialchars(t('system.portal_profile.never')); ?></span><br>
                    <span class="pp-row-desc"><?php echo htmlspecialchars(t('system.portal_profile.f_' . $never . '_desc')); ?></span>
                </span>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="pp-panel">
            <h3><?php echo htmlspecialchars(t('system.portal_profile.directory_heading')); ?></h3>
            <p><?php echo t('system.portal_profile.directory_desc'); ?></p>

            <label class="pp-row">
                <input type="checkbox" id="ppAddressBook" onchange="ppPaintBooks()">
                <span>
                    <span class="pp-row-name"><?php echo htmlspecialchars(t('system.portal_profile.ab_label')); ?></span><br>
                    <span class="pp-row-desc"><?php echo t('system.portal_profile.ab_desc'); ?></span>
                </span>
            </label>
            <div id="ppBooks" class="pp-books"></div>
        </div>

        <div>
            <button class="btn btn-primary" id="ppSave" onclick="ppSave()"><?php echo htmlspecialchars(t('common.save')); ?></button>
            <span class="pp-saved" id="ppSaved" hidden><?php echo htmlspecialchars(t('system.portal_profile.saved')); ?></span>
        </div>
    </div>

    <script>
        const PP_API = '../../api/system/portal_profile.php';
        let ppState = null;

        function ppEsc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g,
                c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }

        function ppPaint(d) {
            ppState = d;
            document.getElementById('ppFields').innerHTML = (d.fields || []).map(f => `
                <label class="pp-row">
                    <input type="checkbox" data-field="${ppEsc(f.key)}" ${f.enabled ? 'checked' : ''}>
                    <span>
                        <span class="pp-row-name">${ppEsc(t('system.portal_profile.f_' + f.key))}</span><br>
                        <span class="pp-row-desc">${ppEsc(t('system.portal_profile.f_' + f.key + '_desc'))}</span>
                    </span>
                </label>`).join('');
            document.getElementById('ppAddressBook').checked = !!d.address_book;
            ppPaintBooks();
        }

        /**
         * Which address books the second setting would reach. Said plainly,
         * because "on" does nothing at all until an address book has write-back
         * switched on, and a switch that silently does nothing reads as broken.
         */
        function ppPaintBooks() {
            const box = document.getElementById('ppBooks');
            const books = (ppState && ppState.address_books) || [];
            const on = document.getElementById('ppAddressBook').checked;
            const writing = books.filter(b => b.write_back);
            let html = '';
            if (!books.length) {
                html = ppEsc(t('system.portal_profile.ab_none'));
            } else if (!writing.length) {
                html = ppEsc(t('system.portal_profile.ab_no_writeback'));
            } else {
                html = ppEsc(t('system.portal_profile.ab_books', { list: writing.map(b => b.name).join(', ') }));
            }
            html += ' <a href="../sso/">' + ppEsc(t('system.portal_profile.ab_manage')) + '</a>';
            if (on && writing.length) {
                html += '<div class="pp-warn">' + ppEsc(t('system.portal_profile.ab_warn')) + '</div>';
            }
            box.innerHTML = html;
        }

        async function ppLoad() {
            try {
                const d = await (await fetch(PP_API, { credentials: 'same-origin' })).json();
                if (d.success) { ppPaint(d); return; }
            } catch (e) {}
            // ⚠️ Never leave an unloaded form saveable: unticked boxes would be
            // saved as "offer nothing".
            document.getElementById('ppSave').disabled = true;
            if (typeof showToast === 'function') showToast(t('system.portal_profile.load_failed'), 'error');
        }

        async function ppSave() {
            const btn = document.getElementById('ppSave');
            btn.disabled = true;
            try {
                const fields = Array.from(document.querySelectorAll('#ppFields input[data-field]'))
                    .filter(i => i.checked).map(i => i.dataset.field);
                const d = await (await fetch(PP_API, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ fields: fields, address_book: document.getElementById('ppAddressBook').checked })
                })).json();
                if (!d.success) {
                    if (typeof showToast === 'function') showToast(d.error || t('system.portal_profile.save_failed'), 'error');
                    return;
                }
                // What was STORED, not what was sent.
                ppPaint(d);
                const saved = document.getElementById('ppSaved');
                saved.hidden = false;
                setTimeout(() => { saved.hidden = true; }, 2500);
            } catch (e) {
                if (typeof showToast === 'function') showToast(t('system.portal_profile.save_failed'), 'error');
            } finally {
                btn.disabled = false;
            }
        }

        ppLoad();
    </script>
    <script src="../../assets/js/mobile.js?v=70"></script>
</body>
</html>
