<?php
// FIRST: this page loads an includes/ file before config.php, so the version guard
// must run before that file is read - see includes/php_version_guard.php.
require_once __DIR__ . '/../includes/php_version_guard.php';
/**
 * Self-Service Portal - Organisation chart (GH #414).
 *
 * The SAME chart as People -> Organisation, not a copy: the markup is
 * includes/org_chart_view.php, the script assets/js/people-org.js, the queries the
 * peopleOrg*() functions in includes/people.php. This page asks for it without the
 * leavers box and the gaps, and points the script at api/self-service/org.php, which
 * answers as the portal reader (own company, current staff) and offers no contact
 * details. Names are plain text - there is no person page to open here.
 *
 * TRAP: nothing here decides what somebody may see; the endpoint does. The guard
 * below runs BEFORE the header for the reason my-equipment.php records: after it,
 * the redirect has nothing to send.
 *
 * @wiki Organisation-Chart-on-the-Portal-Developer-Guide
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/portal_org_chart.php';
require_once __DIR__ . '/../includes/org_chart_view.php';

if (empty($_SESSION['ss_user_id'])) {
    header('Location: login.php');
    exit;
}
// A hidden nav link is not a permission.
if (!portalOrgChartAllowed(connectToDatabase(), (int)$_SESSION['ss_user_id'])) {
    header('Location: index.php');
    exit;
}

$pageTitleKey = 'self-service.org_chart.title';
$activeNav    = 'org_chart';
// The chart's own words live with it, in the People module's strings.
$translationNamespaces = ['common', 'self-service', 'people'];
$bodyClass = 'oc-page';
$pageHead = '<link rel="stylesheet" href="../assets/css/people.css?v=36">';

// Only the fit to the portal: its header is not the analyst one, and a phone stacks
// the sidebar above the chart. Everything else is people.css, as on People.
$pageStyles = <<<'CSS'
body.oc-page { --ppl-accent: var(--accent, #10b981); --ppl-accent-soft: var(--accent-soft, rgba(16,185,129,0.1)); }
/* Edge to edge under the portal's bar, like People: no page padding, and exactly the
   height left, so the page itself never scrolls - the chart and the tree scroll inside.
   --ss-chrome is the bar (and the logo band, when the logo sits on the page). */
.oc-page .portal-layout { padding: 0; }
.oc-page .ppl-layout { height: calc(100vh - var(--ss-chrome, 48px)); }
/* A name is plain text here (no person page to open), so it does not pretend to be a link. */
.oc-page span.ppl-org-name { color: var(--text, #333); }
.oc-page span.ppl-org-name:hover { text-decoration: none; }
@media (max-width: 760px) {
    .oc-page .ppl-layout { flex-direction: column; height: auto; min-height: 0; }
    .oc-page .ppl-sidebar { width: auto; border-right: none; border-bottom: 1px solid var(--border, #ddd); padding: 12px 16px; }
    .oc-page .ppl-main-list { padding: 12px 8px 24px; overflow: visible; }
    .oc-page .ppl-org-side-foot, .oc-page .ppl-intro { display: none; }
    .oc-page .ppl-sidebar input { font-size: var(--font-size-xl, 16px); }   /* 16px or more: iOS zooms into a smaller field */
}
CSS;

require_once __DIR__ . '/includes/header.php';

orgChartViewRender(['leavers' => false, 'gaps' => false, 'intro' => t('self-service.org_chart.subtitle'),
                     'search_ph' => t('people.org.search_ph_names')]);

// What the shared chart script needs (assets/js/people-org.js), as window.PAGE.peopleOrg.
$pageData = ['peopleOrg' => [
    'api'           => '../api/self-service/org.php',
    'list'          => '../api/self-service/org.php?action=search',
    'levelsDefault' => PEOPLE_ORG_CHART_LEVELS,
    'levelsMax'     => PEOPLE_ORG_CHART_LEVELS_MAX,
    'personPage'    => false,
    'contact'       => false,
    'me'            => (int)$_SESSION['ss_user_id'],
]];
// TRAP: the script is loaded from HERE, not with a <script src> in the page. It calls
// window.t() as it starts, and the portal loads i18n.js in the footer - before
// $pageScripts, after anything the page prints itself.
$pageScripts = <<<'JS'
window.PEOPLE_ORG = window.PAGE.peopleOrg;
['../assets/js/people-org.js?v=9', '../assets/js/pdf-writer.js?v=4'].forEach(function (src) {
    var s = document.createElement('script');
    s.src = src;
    s.async = false;   // in this order
    document.body.appendChild(s);
});
JS;
require_once __DIR__ . '/includes/footer.php';
