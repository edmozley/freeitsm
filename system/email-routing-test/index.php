<?php
/**
 * System - Email routing test (dry-run diagnostic)
 *
 * "If an email came from <X> to <mailbox>, where would it land?" Runs the real
 * inbound-routing function (api/system/email_routing_test.php) without creating
 * anything, and shows which rule decided the result. Only useful once a second
 * company exists; on a single-company install it simply explains that all mail
 * goes to the Default company.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'email-routing-test';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.routing_test.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        /* inbox.css gives body height:100vh + overflow:hidden but no flex
           column, so flex:1 children can't bound their height. Make body a
           column here so the content area scrolls under a header of any
           height (the #535 reasoning — no hardcoded 100vh-48px). */
        body { display: flex; flex-direction: column; }
        .rt-container { flex: 1; min-height: 0; overflow-y: auto; padding: 30px 20px; }
        .page-title { font-size: 22px; font-weight: 600; color: #333; margin: 0 0 6px 0; }
        .page-subtitle { font-size: 13px; color: #888; margin: 0 0 24px 0; }

        /* Two columns: the form on the left, the result on the right. Wraps to
           a single column when there isn't room. */
        .rt-grid { display: flex; gap: 24px; align-items: flex-start; flex-wrap: wrap; }
        .rt-col-form   { flex: 0 0 400px; max-width: 100%; }
        .rt-col-result { flex: 1 1 360px; min-width: 0; }

        .settings-card { background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }

        .form-field { margin-bottom: 16px; }
        .form-field label { display: block; font-size: 13px; font-weight: 600; color: #444; margin-bottom: 4px; }
        .form-field .hint { font-size: 12px; color: #999; font-weight: 400; margin-bottom: 6px; }
        .form-field input[type=text], .form-field select { width: 100%; padding: 9px 11px; border: 1px solid #ddd; border-radius: 5px; font-size: 13px; font-family: inherit; box-sizing: border-box; background: #fff; }
        .form-field input:focus, .form-field select:focus { outline: none; border-color: #546e7a; }

        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: all 0.15s; }
        .btn-primary { background: #546e7a; color: #fff; }
        .btn-primary:hover { background: #455a64; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .rt-note { font-size: 13px; color: #8a5a00; background: #fff8e1; border: 1px solid #ffe0a3; border-radius: 6px; padding: 12px 14px; margin-bottom: 24px; max-width: 720px; }

        /* Result */
        .rt-placeholder { display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 10px; color: #b0b8bd; background: #fafbfc; border: 1px dashed #dde3e6; border-radius: 8px; padding: 48px 24px; }
        .rt-placeholder svg { opacity: 0.6; }
        .rt-result { display: none; }
        .rt-result.show { display: block; }
        .rt-headline { display: flex; align-items: center; gap: 12px; padding: 16px 18px; border-radius: 8px; margin-bottom: 18px; }
        .rt-headline.company { background: #e8f5e9; border: 1px solid #c8e6c9; }
        .rt-headline.triage  { background: #fff3e0; border: 1px solid #ffe0b2; }
        .rt-headline .rt-h-icon { font-size: 22px; }
        .rt-headline .rt-h-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: #777; }
        .rt-headline .rt-h-value { font-size: 17px; font-weight: 600; color: #333; }

        .rt-steps-title { font-size: 12px; font-weight: 600; color: #888; text-transform: uppercase; letter-spacing: 0.04em; margin: 0 0 10px 0; }
        .rt-step { display: flex; align-items: flex-start; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f2f2f2; }
        .rt-step:last-child { border-bottom: none; }
        .rt-step .rt-s-icon { flex: 0 0 auto; width: 18px; text-align: center; font-size: 14px; line-height: 1.4; }
        .rt-step .rt-s-body { flex: 1; min-width: 0; }
        .rt-step .rt-s-name { font-size: 13px; font-weight: 600; color: #444; }
        .rt-step .rt-s-detail { font-size: 12px; color: #777; margin-top: 1px; }
        .rt-step.fired .rt-s-name { color: #2e7d32; }
        .rt-step.skipped, .rt-step.not_evaluated { opacity: 0.7; }
        .rt-step strong { color: #444; font-weight: 600; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="rt-container">
        <h1 class="page-title"><?php echo htmlspecialchars(t('system.routing_test.title')); ?></h1>
        <p class="page-subtitle"><?php echo htmlspecialchars(t('system.routing_test.subtitle')); ?></p>

        <div class="rt-note" id="singleCompanyNote" style="display:none;">
            <?php echo htmlspecialchars(t('system.routing_test.single_company_note')); ?>
        </div>

        <div class="rt-grid">
            <div class="rt-col-form">
                <div class="settings-card">
                    <div class="form-field">
                        <label for="fromInput"><?php echo htmlspecialchars(t('system.routing_test.from_label')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.routing_test.from_hint')); ?></div>
                        <input type="text" id="fromInput" placeholder="<?php echo htmlspecialchars(t('system.routing_test.from_placeholder')); ?>">
                    </div>
                    <div class="form-field">
                        <label for="mailboxSelect"><?php echo htmlspecialchars(t('system.routing_test.mailbox_label')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.routing_test.mailbox_hint')); ?></div>
                        <select id="mailboxSelect"><option value=""><?php echo htmlspecialchars(t('system.routing_test.mailbox_loading')); ?></option></select>
                    </div>
                    <button class="btn btn-primary" id="testBtn"><?php echo htmlspecialchars(t('system.routing_test.run')); ?></button>
                </div>
            </div>

            <div class="rt-col-result">
                <div class="rt-placeholder" id="resultPlaceholder">
                    <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                        <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path>
                    </svg>
                    <div><?php echo htmlspecialchars(t('system.routing_test.placeholder')); ?></div>
                </div>

                <div class="settings-card rt-result" id="resultCard">
                    <div class="rt-headline" id="rtHeadline">
                        <span class="rt-h-icon" id="rtHeadIcon"></span>
                        <div>
                            <div class="rt-h-label" id="rtHeadLabel"></div>
                            <div class="rt-h-value" id="rtHeadValue"></div>
                        </div>
                    </div>
                    <p class="rt-steps-title"><?php echo htmlspecialchars(t('system.routing_test.steps_title')); ?></p>
                    <div id="rtSteps"></div>
                </div>
            </div>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js"></script>
    <script>
    const API = '<?php echo $path_prefix; ?>api/';
    function esc(s) { return (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    let companiesById = {};

    async function loadMailboxes() {
        const select = document.getElementById('mailboxSelect');
        let mailboxes = [], companies = [];
        try {
            const [mr, cr] = await Promise.all([
                fetch(API + 'tickets/get_mailboxes.php').then(r => r.json()),
                fetch(API + 'system/get_tenants.php').then(r => r.json())
            ]);
            mailboxes = mr.success ? mr.mailboxes : [];
            companies = cr.success ? cr.companies : [];
        } catch (e) { /* leave empty */ }

        companies.forEach(c => { companiesById[c.id] = c.name; });
        // More than one company → the routing machinery is awake.
        if (companies.length <= 1) {
            document.getElementById('singleCompanyNote').style.display = '';
        }

        if (!mailboxes.length) {
            select.innerHTML = '<option value="">' + esc(window.t('system.routing_test.no_mailboxes')) + '</option>';
            return;
        }
        select.innerHTML = '<option value="">' + esc(window.t('system.routing_test.mailbox_choose')) + '</option>' +
            mailboxes.map(m => {
                const kind = (m.tenant_id != null)
                    ? window.t('system.routing_test.opt_pinned', { company: companiesById[m.tenant_id] || ('#' + m.tenant_id) })
                    : window.t('system.routing_test.opt_shared');
                const label = (m.target_mailbox || m.name) + ' — ' + kind;
                return '<option value="' + m.id + '">' + esc(label) + '</option>';
            }).join('');
    }

    async function runTest() {
        const mailboxId = document.getElementById('mailboxSelect').value;
        const from = document.getElementById('fromInput').value.trim();
        if (!mailboxId) { showToast(window.t('system.routing_test.pick_mailbox'), 'error'); return; }
        const btn = document.getElementById('testBtn');
        btn.disabled = true;
        let data;
        try {
            const r = await fetch(API + 'system/email_routing_test.php?mailbox_id=' + encodeURIComponent(mailboxId) + '&from_address=' + encodeURIComponent(from));
            data = await r.json();
        } catch (e) { data = null; }
        btn.disabled = false;
        if (!data || !data.success) {
            showToast((data && data.error) || window.t('system.routing_test.failed'), 'error');
            return;
        }
        renderResult(data);
    }

    function stepText(s) {
        switch (s.key) {
            case 'reply':          return { name: window.t('system.routing_test.step_reply'), detail: window.t('system.routing_test.step_reply_detail') };
            case 'single_company': return { name: window.t('system.routing_test.step_single'), detail: window.t('system.routing_test.step_single_detail', { company: '<strong>' + esc(s.company) + '</strong>' }) };
            case 'pinned':
                if (s.status === 'fired') return { name: window.t('system.routing_test.step_pinned'), detail: window.t('system.routing_test.step_pinned_fired', { mailbox: '<strong>' + esc(s.mailbox) + '</strong>', company: '<strong>' + esc(s.company) + '</strong>' }) };
                return { name: window.t('system.routing_test.step_pinned'), detail: window.t('system.routing_test.step_pinned_skipped', { mailbox: '<strong>' + esc(s.mailbox) + '</strong>' }) };
            case 'sender':
                if (s.status === 'fired')      return { name: window.t('system.routing_test.step_sender'), detail: window.t('system.routing_test.step_sender_fired', { address: '<strong>' + esc(s.address) + '</strong>', company: '<strong>' + esc(s.company) + '</strong>' }) };
                if (s.status === 'no_address') return { name: window.t('system.routing_test.step_sender'), detail: window.t('system.routing_test.step_sender_noaddress') };
                return { name: window.t('system.routing_test.step_sender'), detail: window.t('system.routing_test.step_sender_nomatch', { address: '<strong>' + esc(s.address) + '</strong>' }) };
            case 'domain':
                if (s.status === 'fired')     return { name: window.t('system.routing_test.step_domain'), detail: window.t('system.routing_test.step_domain_fired', { domain: '<strong>' + esc(s.domain) + '</strong>', company: '<strong>' + esc(s.company) + '</strong>' }) };
                if (s.status === 'freemail')  return { name: window.t('system.routing_test.step_domain'), detail: window.t('system.routing_test.step_domain_freemail', { domain: '<strong>' + esc(s.domain) + '</strong>' }) };
                if (s.status === 'no_domain') return { name: window.t('system.routing_test.step_domain'), detail: window.t('system.routing_test.step_domain_nodomain') };
                return { name: window.t('system.routing_test.step_domain'), detail: window.t('system.routing_test.step_domain_nomatch', { domain: '<strong>' + esc(s.domain) + '</strong>' }) };
            case 'triage':         return { name: window.t('system.routing_test.step_triage'), detail: window.t('system.routing_test.step_triage_detail') };
        }
        return { name: s.key, detail: '' };
    }

    function stepIcon(status) {
        if (status === 'fired') return '✓';
        if (status === 'not_evaluated') return '–';
        return '○';
    }

    function renderResult(data) {
        const card = document.getElementById('resultCard');
        document.getElementById('resultPlaceholder').style.display = 'none';
        const triage = data.result.is_triage;

        const headline = document.getElementById('rtHeadline');
        headline.className = 'rt-headline ' + (triage ? 'triage' : 'company');
        document.getElementById('rtHeadIcon').textContent = triage ? '🗂️' : '🏢';
        document.getElementById('rtHeadLabel').textContent = triage
            ? window.t('system.routing_test.result_triage_label')
            : window.t('system.routing_test.result_company_label');
        document.getElementById('rtHeadValue').textContent = triage
            ? window.t('system.routing_test.result_triage_value')
            : (data.result.tenant_name || '');

        document.getElementById('rtSteps').innerHTML = data.steps.map(s => {
            const txt = stepText(s);
            return '<div class="rt-step ' + esc(s.status) + '">' +
                '<span class="rt-s-icon">' + stepIcon(s.status) + '</span>' +
                '<div class="rt-s-body"><div class="rt-s-name">' + esc(txt.name) + '</div>' +
                '<div class="rt-s-detail">' + txt.detail + '</div></div></div>';
        }).join('');

        card.classList.add('show');
    }

    document.getElementById('testBtn').addEventListener('click', runTest);
    document.getElementById('fromInput').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); runTest(); } });

    loadMailboxes();
    </script>
</body>
</html>
