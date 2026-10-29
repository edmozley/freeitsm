<?php
/**
 * Starters & leavers - the cases (4.6.0).
 *
 * Every joining, moving and leaving case this analyst's companies have, soonest
 * first while they are open, with how far each has got - counted from its tasks
 * in Tasks, never stored twice. Behaviour is in assets/js/starters-list.js; the
 * shared helpers and the New case dialog in assets/js/starters.js.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
require_once '../includes/tenancy.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('starters');

$current_page = 'cases';
$path_prefix = '../';
$translationNamespaces = ['common', 'starters'];
$multi = isMultiTenant(connectToDatabase());
$tt = fn(string $k, array $p = []) => htmlspecialchars(t('starters.' . $k, $p));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('starters')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=28">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=85">
    <link rel="stylesheet" href="../assets/css/starters.css?v=4">
    <link rel="stylesheet" href="../assets/css/mobile.css?v=196">
</head>
<body data-mobile-module="starters" data-mobile-page="starters-cases">
    <?php include 'includes/header.php'; ?>
    <?php require_once __DIR__ . '/../includes/action_row.php';
          renderActionRow(t('starters.title'), [actionRowNew('JmlNew.open()', t('starters.list.new_tip'))], ['count_id' => 'jmlCount']); ?>

    <div class="jml-layout">
        <aside class="jml-sidebar">
            <h3><?php echo $tt('list.search'); ?></h3>
            <input type="text" id="jmlSearch" placeholder="<?php echo $tt('list.search_ph'); ?>" autocomplete="off">

            <h3><?php echo $tt('list.col_kind'); ?></h3>
            <ul class="jml-views" id="jmlKinds">
                <?php foreach (['', 'starter', 'mover', 'leaver'] as $k): ?>
                <li data-kind="<?php echo $k; ?>"<?php echo $k === '' ? ' class="active"' : ''; ?>><span><?php echo $tt('kinds.' . ($k ?: 'all')); ?></span><b data-count-kind="<?php echo $k; ?>"></b></li>
                <?php endforeach; ?>
            </ul>

            <h3><?php echo $tt('list.col_status'); ?></h3>
            <ul class="jml-views" id="jmlStatuses">
                <?php foreach (['open', 'done', 'cancelled', ''] as $s): ?>
                <li data-status="<?php echo $s; ?>"<?php echo $s === 'open' ? ' class="active"' : ''; ?>><span><?php echo $tt('status.' . ($s ?: 'all')); ?></span><b data-count-status="<?php echo $s; ?>"></b></li>
                <?php endforeach; ?>
            </ul>
        </aside>

        <main class="jml-main">
            <div class="jml-card" id="jmlTableCard" hidden>
                <table class="jml-table">
                    <thead><tr>
                        <th><?php echo $tt('list.col_person'); ?></th>
                        <th><?php echo $tt('list.col_kind'); ?></th>
                        <th><?php echo $tt('list.col_date'); ?></th>
                        <th><?php echo $tt('list.col_template'); ?></th>
                        <th><?php echo $tt('list.col_progress'); ?></th>
                        <th><?php echo $tt('list.col_owner'); ?></th>
                        <?php if ($multi): ?><th><?php echo $tt('list.col_company'); ?></th><?php endif; ?>
                        <th><?php echo $tt('list.col_status'); ?></th>
                    </tr></thead>
                    <tbody id="jmlRows"></tbody>
                </table>
                <div class="jml-filtered-empty" id="jmlFilteredEmpty" hidden><?php echo $tt('list.empty_filtered'); ?></div>
            </div>

            <div class="jml-empty" id="jmlEmpty" hidden>
                <h2><?php echo $tt('list.empty_title'); ?></h2>
                <p><?php echo $tt('list.empty_body'); ?></p>
                <button type="button" class="jml-btn primary" onclick="JmlNew.open()"><?php echo $tt('list.new_tip'); ?></button>
            </div>
        </main>
    </div>

    <?php include 'includes/new_case.php'; ?>

    <script>window.JML_MULTI = <?php echo $multi ? 'true' : 'false'; ?>;</script>
    <script src="../assets/js/starters.js?v=2"></script>
    <script src="../assets/js/starters-list.js?v=1"></script>
    <script src="../assets/js/mobile.js?v=83"></script>
</body>
</html>
