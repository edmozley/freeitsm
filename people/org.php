<?php
/**
 * People - the organisation chart. Who reports to whom, from users.manager_id.
 *
 * Two views of the same data (api/people/org.php), each with its own layouts:
 *  - the TREE: the whole organisation, one level at a time.
 *      Cards    - a card per person, with an avatar (the default);
 *      Compact  - a line per person, with job title and department in columns;
 *      Columns  - one column per level, like a file browser, with the person
 *                 chosen last in a panel on the right. The columns scroll
 *                 sideways and a path above names every level, so a deep
 *                 organisation (8, 12 levels) works the same as a shallow one.
 *  - the CHART: boxes and lines, the whole organisation or one branch, drawn as
 *    HTML here (no library). Two independent switches:
 *      Cards / Compact            - Compact stacks people with no team of their own
 *                                   under their manager instead of side by side;
 *      Top to bottom / Left to right.
 *    Past a few hundred people a chart is no longer readable, so it never draws
 *    more than peopleOrgBranch() returns.
 *
 * The layouts are remembered in this browser (localStorage); ?person= and
 * ?view= are in the address, so a link opens the same place.
 *
 * Read only. Reporting lines are changed where they always were: Tickets ->
 * Users, or the directory that owns the person.
 *
 * The script is assets/js/people-org.js: this page only hands it the endpoints
 * and the level bounds, as window.PEOPLE_ORG.
 *
 * @wiki Organisation-Chart-Developer-Guide
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
$current_page = 'org';
$path_prefix = '../';
require_once __DIR__ . '/../includes/org_chart_view.php';   // the markup, shared with the portal (GH #414)
?>
<!DOCTYPE html>
<html lang="<?php echo pplE(I18n::getLocale()); ?>" data-theme="<?php echo pplE(Theme::active()); ?>" data-theme-mode="<?php echo pplE(Theme::mode()); ?>">
<head>
    <?php pplHead(t('people.org.title')); ?>
</head>
<body data-mobile-module="people" data-mobile-page="people-org">
    <?php include 'includes/header.php'; ?>

    <?php orgChartViewRender(['leavers' => true, 'gaps' => true]); ?>

    <script>
    // All this page's script needs from the server; the rest is in
    // assets/js/people-org.js, which the browser caches.
    window.PEOPLE_ORG = <?php echo json_encode([
        'api'           => BASE_URL . 'api/people/org.php',
        'list'          => BASE_URL . 'api/people/list.php',
        'levelsDefault' => PEOPLE_ORG_CHART_LEVELS,
        'levelsMax'     => PEOPLE_ORG_CHART_LEVELS_MAX,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    </script>
    <script src="<?php echo BASE_URL; ?>assets/js/people-org.js?v=9"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/pdf-writer.js?v=2"></script>   <!-- the shared PDF writer (GH #254) -->
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=87"></script>
</body>
</html>
