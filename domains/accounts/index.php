<?php
/**
 * Domains → Accounts (#154): the logins at registrars that hold the domains.
 *
 * The question this page answers is the one asked at 11pm when a domain has
 * lapsed: "which account is it in, who has the login, and who has the phone
 * with the second factor on it?" It stores that — never the password itself,
 * which belongs in the organisation's password manager.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('domains');

$current_page = 'accounts';
$path_prefix = '../../';
$translationNamespaces = ['common', 'domains'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName() . ' - ' . t('domains.title') . ' - ' . t('domains.nav.accounts')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=25">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=76">
    <link rel="stylesheet" href="../../assets/css/domains.css?v=7">
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=185">
</head>
<body data-mobile-module="domains" data-mobile-page="domains-accounts">
    <?php include '../includes/header.php'; ?>
    <div class="dom-shell">
        <div class="dom-shell-pad">
            <div class="dom-toolbar">
                <div>
                    <h2 style="margin:0;font-size:20px"><?php echo htmlspecialchars(t('domains.acc.title')); ?></h2>
                    <div class="dom-hint" style="font-size:13px;max-width:760px"><?php echo htmlspecialchars(t('domains.acc.intro')); ?></div>
                </div>
                <span class="spacer"></span>
                <button type="button" class="dom-btn primary" id="accAdd"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <div class="dom-card"><table class="dom-table"><thead><tr>
                <th class="nosort"><?php echo htmlspecialchars(t('domains.acc.account')); ?></th>
                <th class="nosort"><?php echo htmlspecialchars(t('domains.field.registrar')); ?></th>
                <th class="nosort"><?php echo htmlspecialchars(t('domains.acc.reference')); ?></th>
                <th class="nosort"><?php echo htmlspecialchars(t('domains.field.owner')); ?></th>
                <th class="nosort"><?php echo htmlspecialchars(t('domains.acc.two_factor')); ?></th>
                <th class="nosort"><?php echo htmlspecialchars(t('domains.acc.domains')); ?></th>
                <th class="nosort"><?php echo htmlspecialchars(t('domains.acc.next_expiry')); ?></th>
            </tr></thead><tbody id="accBody"><tr><td class="dom-empty" colspan="7"><?php echo htmlspecialchars(t('common.loading')); ?></td></tr></tbody></table></div>
        </div>
    </div>

    <div class="modal" id="mAcc">
        <div class="modal-content" style="max-width:640px">
            <div class="modal-header" id="accTitle"></div>
            <div class="modal-body">
                <div class="dom-form-grid">
                    <div class="form-group full"><label for="acName"><?php echo htmlspecialchars(t('domains.acc.account')); ?></label><input type="text" id="acName" maxlength="150" placeholder="<?php echo htmlspecialchars(t('domains.acc.name_ph')); ?>"></div>
                    <div class="form-group full" id="acCompanyWrap" style="display:none"><label for="acCompany"><?php echo htmlspecialchars(t('domains.field.company')); ?></label><select id="acCompany"></select></div>
                    <div class="form-group"><label for="acSupplier"><?php echo htmlspecialchars(t('domains.field.registrar')); ?></label><select id="acSupplier"></select></div>
                    <div class="form-group"><label for="acRef"><?php echo htmlspecialchars(t('domains.acc.reference')); ?></label><input type="text" id="acRef" maxlength="150"></div>
                    <div class="form-group full"><label for="acUrl"><?php echo htmlspecialchars(t('domains.acc.login_url')); ?></label><input type="text" id="acUrl" placeholder="https://"></div>
                    <div class="form-group"><label for="acOwner"><?php echo htmlspecialchars(t('domains.field.owner')); ?></label><select id="acOwner"></select></div>
                    <div class="form-group"><label for="ac2fa"><?php echo htmlspecialchars(t('domains.acc.two_factor')); ?></label><input type="text" id="ac2fa" placeholder="<?php echo htmlspecialchars(t('domains.acc.two_factor_ph')); ?>"></div>
                    <div class="form-group full"><label for="acNotes"><?php echo htmlspecialchars(t('domains.field.notes')); ?></label><textarea id="acNotes" rows="3"></textarea>
                        <div class="dom-hint"><?php echo htmlspecialchars(t('domains.acc.no_passwords')); ?></div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" style="margin-right:auto;color:#dc2626" id="acDelete"><?php echo htmlspecialchars(t('common.delete')); ?></button>
                <button type="button" class="btn btn-secondary" data-close="mAcc"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="acSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <script src="../../assets/js/domains.js?v=1"></script>
    <script>
    (function () {
        'use strict';
        const { T, esc, api, daysPill, fmtDate } = window.Dom;
        let accounts = [], L = null, editing = null, multi = false;

        async function load() {
            const [a, l] = await Promise.all([api('accounts.php'), L ? Promise.resolve({ lookups: L, multi_company: multi }) : api('lookups.php')]);
            accounts = a.accounts; L = l.lookups; multi = !!l.multi_company;
            const body = document.getElementById('accBody');
            if (!accounts.length) { body.innerHTML = '<tr><td colspan="7"><div class="dom-empty"><h3>' + esc(T('acc.empty_title')) + '</h3><p>' + esc(T('acc.empty_body')) + '</p></div></td></tr>'; return; }
            body.innerHTML = accounts.map(x => {
                const days = x.next_expiry ? Math.round((new Date(x.next_expiry + 'T00:00:00Z') - new Date(new Date().toISOString().slice(0, 10) + 'T00:00:00Z')) / 86400000) : null;
                return '<tr data-id="' + x.id + '"><td><div class="dom-name">' + esc(x.account_name) + '</div>'
                    + (multi && x.company_name ? '<div class="dom-sub">' + esc(x.company_name) + '</div>' : '')
                    + (x.login_url ? '<div class="dom-sub"><a href="' + esc(x.login_url) + '" target="_blank" rel="noopener noreferrer" onclick="event.stopPropagation()">' + esc(x.login_url) + '</a></div>' : '') + '</td>'
                    + '<td>' + esc(x.supplier_name || '—') + '</td><td>' + esc(x.account_reference || '') + '</td><td>' + esc(x.owner_name || '—') + '</td>'
                    + '<td>' + esc(x.two_factor_holder || '') + '</td>'
                    + '<td><a href="../?q=&account=' + x.id + '" onclick="event.stopPropagation()">' + x.domain_count + '</a></td>'
                    + '<td>' + (x.next_expiry ? esc(fmtDate(x.next_expiry)) + ' ' + daysPill(days) : '') + '</td></tr>';
            }).join('');
        }

        function open(x) {
            editing = x ? x.id : null;
            const F = window.Dom.fillSelect;
            F('acSupplier', L.suppliers, { blank: '—' });
            F('acOwner', L.analysts, { blank: T('field.no_owner') });
            document.getElementById('accTitle').textContent = x ? T('acc.edit') : T('acc.add');
            document.getElementById('acName').value = x ? x.account_name : '';
            document.getElementById('acSupplier').value = x && x.supplier_id ? x.supplier_id : '';
            document.getElementById('acRef').value = x ? (x.account_reference || '') : '';
            document.getElementById('acUrl').value = x ? (x.login_url || '') : '';
            document.getElementById('acOwner').value = x && x.owner_analyst_id ? x.owner_analyst_id : '';
            document.getElementById('ac2fa').value = x ? (x.two_factor_holder || '') : '';
            document.getElementById('acNotes').value = x ? (x.notes || '') : '';
            document.getElementById('acDelete').style.display = x ? '' : 'none';
            const cw = document.getElementById('acCompanyWrap');
            if (!x && multi && (L.companies || []).length > 1) { cw.style.display = ''; F('acCompany', L.companies); document.getElementById('acCompany').value = String(L.active_company || ''); }
            else cw.style.display = 'none';
            window.Dom.openModal('mAcc');
        }

        document.addEventListener('DOMContentLoaded', () => {
            load().catch(e => { document.getElementById('accBody').innerHTML = '<tr><td class="dom-empty" colspan="7">' + esc(e.message) + '</td></tr>'; });
            document.getElementById('accAdd').addEventListener('click', () => open(null));
            document.getElementById('accBody').addEventListener('click', e => {
                const tr = e.target.closest('tr[data-id]'); if (!tr) return;
                open(accounts.find(a => String(a.id) === tr.dataset.id));
            });
            document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => window.Dom.closeModal(b.dataset.close)));
            document.getElementById('acSave').addEventListener('click', async () => {
                const body = { action: 'save', id: editing, account_name: document.getElementById('acName').value, supplier_id: document.getElementById('acSupplier').value,
                    account_reference: document.getElementById('acRef').value, login_url: document.getElementById('acUrl').value,
                    owner_analyst_id: document.getElementById('acOwner').value, two_factor_holder: document.getElementById('ac2fa').value, notes: document.getElementById('acNotes').value };
                if (!editing && document.getElementById('acCompanyWrap').style.display !== 'none') body.company_id = document.getElementById('acCompany').value;
                try { await api('accounts.php', body); window.Dom.closeModal('mAcc'); await load(); } catch (err) { showToast(err.message, 'error'); }
            });
            document.getElementById('acDelete').addEventListener('click', async () => {
                const x = accounts.find(a => a.id === editing); if (!x) return;
                if (!(await showConfirm({ title: T('acc.delete'), message: T('acc.delete_body', { name: x.account_name, n: x.domain_count }), okLabel: window.t('common.delete'), okClass: 'danger' }))) return;
                try { await api('accounts.php', { action: 'delete', id: editing }); window.Dom.closeModal('mAcc'); await load(); } catch (err) { showToast(err.message, 'error'); }
            });
            const want = new URLSearchParams(location.search).get('id');
            if (want) setTimeout(() => { const x = accounts.find(a => String(a.id) === want); if (x) open(x); }, 400);
        });
    })();
    </script>
    <script src="../../assets/js/mobile.js?v=78"></script>
</body>
</html>
