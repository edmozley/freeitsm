<?php
/**
 * People — everybody in Users the analyst can see (#153 step 2).
 * Search and filter here; open someone for their page.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
require_once '../includes/people.php';
require_once 'includes/render.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('people');

$conn = connectToDatabase();
$analystId = (int)$_SESSION['analyst_id'];
$multi = isMultiTenant($conn);
$companies = $multi ? peopleCompanies($conn, $analystId) : [];
$current_page = 'people';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="<?php echo pplE(I18n::getLocale()); ?>" data-theme="<?php echo pplE(Theme::active()); ?>" data-theme-mode="<?php echo pplE(Theme::mode()); ?>">
<head>
    <?php pplHead(t('people.title')); ?>
</head>
<body data-mobile-module="people" data-mobile-page="people-list">
    <?php include 'includes/header.php'; ?>

    <div class="ppl-layout">
        <aside class="ppl-sidebar">
            <h3><?php echo pplE(t('people.list.search')); ?></h3>
            <input type="search" id="pplSearch" placeholder="<?php echo pplE(t('people.list.search_ph')); ?>" autocomplete="off" aria-label="<?php echo pplE(t('people.list.search')); ?>">
            <h3><?php echo pplE(t('people.list.show')); ?></h3>
            <select id="pplStatus" aria-label="<?php echo pplE(t('people.list.show')); ?>">
                <option value="active"><?php echo pplE(t('people.list.status_active')); ?></option>
                <option value="leavers"><?php echo pplE(t('people.list.status_leavers')); ?></option>
                <option value="all"><?php echo pplE(t('people.list.status_all')); ?></option>
            </select>
            <?php if ($multi): ?>
            <h3><?php echo pplE(t('people.list.company')); ?></h3>
            <select id="pplCompany" aria-label="<?php echo pplE(t('people.list.company')); ?>">
                <option value=""><?php echo pplE(t('people.list.all_companies')); ?></option>
                <?php foreach ($companies as $co): ?>
                <option value="<?php echo $co['id']; ?>"><?php echo pplE($co['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <p class="ppl-dim ppl-intro"><?php echo pplE(t('people.list.intro')); ?></p>
        </aside>
        <main class="ppl-main-list">
            <section class="ppl-card">
                <div class="ppl-table-wrap"><table class="ppl-table ppl-list">
                    <thead><tr>
                        <th><?php echo pplE(t('people.list.col_name')); ?></th>
                        <th><?php echo pplE(t('people.list.col_email')); ?></th>
                        <th><?php echo pplE(t('people.list.col_role')); ?></th>
                        <?php if ($multi): ?><th><?php echo pplE(t('people.list.col_company')); ?></th><?php endif; ?>
                    </tr></thead>
                    <tbody id="pplRows"><tr><td colspan="4" class="ppl-empty"><?php echo pplE(t('people.list.loading')); ?></td></tr></tbody>
                </table></div>
                <div class="ppl-more" id="pplMore" hidden></div>
            </section>
        </main>
    </div>

    <script>
    (function () {
        'use strict';
        var API = <?php echo json_encode(BASE_URL . 'api/people/list.php'); ?>;
        var MULTI = <?php echo $multi ? 'true' : 'false'; ?>;
        var T = function (k, p) { return window.t('people.' + k, p); };
        var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
        var search = document.getElementById('pplSearch');
        var status = document.getElementById('pplStatus');
        var company = document.getElementById('pplCompany');
        var rows = document.getElementById('pplRows');
        var more = document.getElementById('pplMore');
        var cols = MULTI ? 4 : 3, seq = 0, timer = null;

        // Remember the filters for this browser only - a convenience, never relied on.
        try {
            var saved = JSON.parse(localStorage.getItem('people.filters') || '{}');
            if (saved.status) status.value = saved.status;
            if (company && saved.company) company.value = saved.company;
        } catch (e) {}

        function load() {
            var my = ++seq;
            var qs = 'q=' + encodeURIComponent(search.value.trim()) + '&status=' + encodeURIComponent(status.value) + (company && company.value ? '&company=' + encodeURIComponent(company.value) : '');
            try { localStorage.setItem('people.filters', JSON.stringify({ status: status.value, company: company ? company.value : '' })); } catch (e) {}
            fetch(API + '?' + qs, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
                if (my !== seq) return;
                if (!d.success) { rows.innerHTML = '<tr><td colspan="' + cols + '" class="ppl-empty">' + esc(d.error) + '</td></tr>'; return; }
                if (!d.people.length) { rows.innerHTML = '<tr><td colspan="' + cols + '" class="ppl-empty">' + esc(T('list.none')) + '</td></tr>'; more.hidden = true; return; }
                rows.innerHTML = d.people.map(function (p) {
                    return '<tr>'
                        + '<td data-label="' + esc(T('list.col_name')) + '"><a href="person.php?id=' + p.id + '">' + esc(p.name) + '</a>'
                        + (p.is_active ? '' : ' <span class="ppl-pill muted">' + esc(T('list.leaver')) + '</span>') + '</td>'
                        + '<td data-label="' + esc(T('list.col_email')) + '">' + esc(p.email) + '</td>'
                        + '<td data-label="' + esc(T('list.col_role')) + '">' + esc([p.job_title, p.department].filter(Boolean).join(' · ')) + '</td>'
                        + (MULTI ? '<td data-label="' + esc(T('list.col_company')) + '">' + esc(p.company || '') + '</td>' : '')
                        + '</tr>';
                }).join('');
                more.hidden = !d.more;
                more.textContent = d.more ? T('list.limit_note', { n: d.limit }) : '';
            }).catch(function () {
                if (my === seq) rows.innerHTML = '<tr><td colspan="' + cols + '" class="ppl-empty">' + esc(T('list.none')) + '</td></tr>';
            });
        }
        search.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(load, 200); });
        status.addEventListener('change', load);
        if (company) company.addEventListener('change', load);
        load();
    })();
    </script>
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=76"></script>
</body>
</html>
