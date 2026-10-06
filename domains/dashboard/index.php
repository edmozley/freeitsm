<?php
/**
 * Domains → Dashboard (#154): the portfolio at a glance — what needs doing,
 * the next twelve months of renewals and what they will cost, how well the
 * domains are protected, and how email security stands across all of them.
 * Numbers from api/domains/dashboard.php, over the same scoped register the
 * list shows.
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

$current_page = 'dashboard';
$path_prefix = '../../';
$translationNamespaces = ['common', 'domains'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName() . ' - ' . t('domains.title') . ' - ' . t('domains.nav.dashboard')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=25">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=76">
    <link rel="stylesheet" href="../../assets/css/domains.css?v=7">
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=181">
    <script src="../../assets/js/vendor/chart.umd.min.js"></script>
</head>
<body data-mobile-module="domains" data-mobile-page="domains-dashboard">
    <?php include '../includes/header.php'; ?>
    <div class="dom-shell">
        <div class="dom-shell-pad">
            <div class="dom-stats" id="dStats"><div class="dom-sub"><?php echo htmlspecialchars(t('common.loading')); ?></div></div>
            <div class="dom-dash">
                <div class="dom-card w8"><div class="dom-card-h"><h3><?php echo htmlspecialchars(t('domains.dash.forecast')); ?></h3><span class="dom-sub" id="dCostNote"></span></div>
                    <div class="dom-card-b" style="height:290px"><canvas id="cForecast"></canvas></div></div>
                <div class="dom-card w4"><div class="dom-card-h"><h3><?php echo htmlspecialchars(t('domains.dash.grades')); ?></h3></div>
                    <div class="dom-card-b" style="height:290px"><canvas id="cGrades"></canvas></div></div>
                <div class="dom-card w6"><div class="dom-card-h"><h3><?php echo htmlspecialchars(t('domains.dash.attention')); ?></h3><a class="dom-sub" href="../?view=attention"><?php echo htmlspecialchars(t('domains.dash.see_all')); ?></a></div>
                    <div class="dom-card-b" id="dAttention" style="max-height:340px;overflow-y:auto"></div></div>
                <div class="dom-card w6"><div class="dom-card-h"><h3><?php echo htmlspecialchars(t('domains.dash.email')); ?></h3></div>
                    <div class="dom-card-b" id="dEmail"></div></div>
                <div class="dom-card w6"><div class="dom-card-h"><h3><?php echo htmlspecialchars(t('domains.dash.by_registrar')); ?></h3></div>
                    <div class="dom-card-b" id="dRegistrar"></div></div>
                <div class="dom-card w6" id="dCompanyCard" style="display:none"><div class="dom-card-h"><h3><?php echo htmlspecialchars(t('domains.dash.by_company')); ?></h3></div>
                    <div class="dom-card-b" id="dCompany"></div></div>
                <div class="dom-card w6" id="dPurposeCard"><div class="dom-card-h"><h3><?php echo htmlspecialchars(t('domains.dash.by_purpose')); ?></h3></div>
                    <div class="dom-card-b" id="dPurpose"></div></div>
            </div>
        </div>
    </div>

    <script src="../../assets/js/domains.js?v=1"></script>
    <script>
    (function () {
        'use strict';
        const { T, esc, api, grade, daysPill, money, purpose } = window.Dom;
        const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();

        function bars(list, total, fmt) {
            const max = Math.max(1, ...list.map(x => x.count));
            return list.map(x => '<div class="dom-bar-row"><div style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + esc(x.name) + '">' + esc(x.name) + '</div>'
                + '<div class="dom-bar"><div style="width:' + Math.round(x.count / max * 100) + '%"></div></div><div style="text-align:right">' + x.count + (fmt && x.cost ? '<div class="dom-sub">' + esc(fmt(x.cost)) + '</div>' : '') + '</div></div>').join('')
                || '<div class="dom-sub">' + esc(T('dash.nothing')) + '</div>';
        }

        document.addEventListener('DOMContentLoaded', async () => {
            let d;
            try { d = await api('dashboard.php'); } catch (e) { document.getElementById('dStats').innerHTML = '<div class="dom-empty">' + esc(e.message) + '</div>'; return; }
            const s = d.stats, cur = d.currency;
            const M = v => money(v, cur);
            const card = (v, l, cls, href) => '<a class="dom-stat ' + cls + ' clickable" style="text-decoration:none" href="' + href + '"><div class="v">' + esc(v) + '</div><div class="l">' + esc(l) + '</div></a>';
            document.getElementById('dStats').innerHTML =
                card(s.total, T('stat.total'), 'accent', '../')
                + card(s.expired, T('stat.expired'), s.expired ? 'red' : 'green', '../?view=expired')
                + card(s.expiring_30, T('stat.expiring30'), s.expiring_30 ? 'amber' : 'green', '../?view=expiring30')
                + card(s.unlocked, T('stat.unlocked'), s.unlocked ? 'red' : 'green', '../?view=unlocked')
                + card(s.ssl_expiring, T('dash.ssl_expiring'), s.ssl_expiring ? 'amber' : 'green', '../?view=certificate')
                + card(s.lookalikes + s.new_certificates, T('dash.watch_hits'), (s.lookalikes + s.new_certificates) ? 'amber' : 'green', '../?view=lookalikes')
                + (s.annual_cost ? card(M(s.annual_cost), T('stat.annual_cost'), 'accent', '#') : '');
            if (d.mixed_currency) document.getElementById('dCostNote').textContent = T('dash.mixed_currency');

            // 12-month forecast: domains renewing per month (bars) and their cost (line).
            // Mid-month, in UTC: a label built from midnight on the 1st can be shifted into
            // the previous month by the viewer's timezone handling (it was: "Aug" for September).
            const labels = d.months.map(m => { const [y, mo] = m.month.split('-').map(Number); return new Date(Date.UTC(y, mo - 1, 15, 12)).toLocaleDateString(undefined, { month: 'short', year: '2-digit', timeZone: 'UTC' }); });
            const accent = css('--dom-accent') || '#4d7c0f';
            new Chart(document.getElementById('cForecast'), {
                data: { labels, datasets: [
                    { type: 'bar', label: T('dash.renewing'), data: d.months.map(m => m.count), backgroundColor: accent, borderRadius: 4, yAxisID: 'y' },
                    { type: 'line', label: T('dash.cost'), data: d.months.map(m => Math.round(m.cost * 100) / 100), borderColor: '#d97706', backgroundColor: '#d97706', tension: .3, yAxisID: 'y1' },
                ] },
                options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { callback: v => M(v) } } } },
            });

            const gk = ['A+', 'A', 'B', 'C', 'D', 'F', 'none'];
            const gc = { 'A+': '#16a34a', A: '#22c55e', B: '#65a30d', C: '#d97706', D: '#ea580c', F: '#dc2626', none: '#cbd5e1' };
            new Chart(document.getElementById('cGrades'), {
                type: 'doughnut',
                data: { labels: gk.map(g => g === 'none' ? T('grade.none') : g), datasets: [{ data: gk.map(g => d.grades[g] || 0), backgroundColor: gk.map(g => gc[g]) }] },
                options: { maintainAspectRatio: false, plugins: { legend: { position: 'right' } } },
            });

            document.getElementById('dAttention').innerHTML = d.attention.length ? d.attention.map(a =>
                '<div style="display:flex;gap:10px;align-items:center;padding:8px 0;border-bottom:1px solid var(--border-soft,#eee)">' + grade(a.security_grade)
                + '<div style="flex:1;min-width:0"><a href="../view.php?id=' + a.id + '" style="font-weight:600;text-decoration:none;color:var(--text,#222)">' + esc(a.domain_name) + '</a>'
                + (a.company_name && d.multi_company ? ' <span class="dom-sub">' + esc(a.company_name) + '</span>' : '')
                + '<div class="dom-sub">' + a.reasons.map(r => esc(T('reason.' + r))).join(' · ') + '</div></div>'
                + (a.days_left !== null ? daysPill(a.days_left) : '') + '</div>').join('')
                : '<div class="dom-empty" style="padding:20px">' + esc(T('dash.all_clear')) + '</div>';

            const e = d.email;
            const line = (l, n, cls) => '<div class="dom-bar-row" style="grid-template-columns:1fr 60px"><div>' + esc(l) + '</div><div style="text-align:right"><span class="dom-pill ' + cls + '">' + n + '</span></div></div>';
            document.getElementById('dEmail').innerHTML =
                line(T('dash.spf_ok'), e.spf_ok, 'green') + line(T('dash.spf_bad'), e.spf_bad, e.spf_bad ? 'red' : 'grey')
                + line(T('dash.dmarc_enforced'), e.dmarc_enforced, 'green') + line(T('dash.dmarc_monitor'), e.dmarc_monitor, e.dmarc_monitor ? 'amber' : 'grey')
                + line(T('dash.dmarc_missing'), e.dmarc_missing, e.dmarc_missing ? 'red' : 'grey')
                + line(T('dash.lockdown_ok'), e.lockdown_ok, 'green') + line(T('dash.lockdown_bad'), e.lockdown_bad, e.lockdown_bad ? 'red' : 'grey')
                + '<div class="dom-hint" style="margin-top:8px">' + esc(T('dash.email_note')) + '</div>';

            document.getElementById('dRegistrar').innerHTML = bars(d.by_registrar, s.total, M);
            if (d.multi_company && d.by_company.length) {
                document.getElementById('dCompanyCard').style.display = '';
                document.getElementById('dCompany').innerHTML = bars(d.by_company, s.total, M);
            }
            document.getElementById('dPurpose').innerHTML = bars(Object.entries(d.by_purpose).map(([k, v]) => ({ name: purpose(k), count: v })), s.total);
            window.Dom.tick();
        });
    })();
    </script>
    <script src="../../assets/js/mobile.js?v=76"></script>
</body>
</html>
