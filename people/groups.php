<?php
/**
 * People — every directory group the analyst can see, with how many people are in it.
 *
 * Groups are imported from Microsoft Entra ID (includes/entra_sync.php) and are
 * read here, never edited: they change in Entra and arrive with the next import.
 * peopleGroupRows() decides which groups and which members this analyst may see.
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
$groups = peopleGroupRows($conn, $analystId);
$current_page = 'groups';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="<?php echo pplE(I18n::getLocale()); ?>" data-theme="<?php echo pplE(Theme::active()); ?>" data-theme-mode="<?php echo pplE(Theme::mode()); ?>">
<head>
    <?php pplHead(t('people.nav.groups')); ?>
</head>
<body data-mobile-module="people" data-mobile-page="people-groups">
    <?php include 'includes/header.php'; ?>

    <main class="ppl-page">
        <div class="ppl-co-bar">
            <p class="ppl-dim ppl-lead"><?php echo pplE(t('people.groups.intro')); ?></p>
            <input type="search" class="ppl-co-search" id="pplGrpSearch" placeholder="<?php echo pplE(t('people.groups.search_ph')); ?>" aria-label="<?php echo pplE(t('people.groups.search_ph')); ?>" autocomplete="off">
            <select id="pplGrpStatus" class="ppl-co-search ppl-sup-status" aria-label="<?php echo pplE(t('people.list.show')); ?>">
                <option value="active"><?php echo pplE(t('people.groups.status_active')); ?></option>
                <option value="all"><?php echo pplE(t('people.groups.status_all')); ?></option>
            </select>
        </div>

        <section class="ppl-card">
            <?php if (!$groups): ?>
                <div class="ppl-empty"><?php echo pplE(t('people.groups.none_imported')); ?></div>
            <?php else: ?>
            <div class="ppl-table-wrap"><table class="ppl-table" id="pplGroups">
                <thead><tr>
                    <th><?php echo pplE(t('people.groups.col_name')); ?></th>
                    <th><?php echo pplE(t('people.groups.col_kind')); ?></th>
                    <th><?php echo pplE(t('people.groups.col_source')); ?></th>
                    <th><?php echo pplE(t('people.groups.col_people')); ?></th>
                    <th><?php echo pplE(t('people.groups.col_in_source')); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($groups as $g): ?>
                    <?php $source = $g['source'] . ($multi && $g['source_company'] ? ' · ' . $g['source_company'] : ''); ?>
                    <tr data-active="<?php echo $g['is_active'] ? '1' : '0'; ?>"
                        data-text="<?php echo pplE(mb_strtolower($g['name'] . ' ' . $g['description'] . ' ' . $source)); ?>">
                        <td data-label="<?php echo pplE(t('people.groups.col_name')); ?>"><a href="group.php?id=<?php echo $g['id']; ?>"><?php echo pplE($g['name']); ?></a><?php echo $g['is_active'] ? '' : ' ' . pplPill(t('people.groups.inactive'), null, 'muted'); ?>
                            <?php if ($g['description']): ?><div class="ppl-dim"><?php echo pplE($g['description']); ?></div><?php endif; ?></td>
                        <td data-label="<?php echo pplE(t('people.groups.col_kind')); ?>"><?php echo pplE(pplGroupKind($g['kind'])); ?></td>
                        <td data-label="<?php echo pplE(t('people.groups.col_source')); ?>"><?php echo pplE($source); ?></td>
                        <td data-label="<?php echo pplE(t('people.groups.col_people')); ?>"><?php echo (int)$g['people']; ?></td>
                        <td data-label="<?php echo pplE(t('people.groups.col_in_source')); ?>"><?php echo (int)$g['in_source']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="ppl-empty" id="pplGrpNone" hidden><?php echo pplE(t('people.groups.none')); ?></div>
            <?php endif; ?>
        </section>
    </main>
    <script>
    (function () {
        var input = document.getElementById('pplGrpSearch');
        var status = document.getElementById('pplGrpStatus');
        var none = document.getElementById('pplGrpNone');
        var rows = document.querySelectorAll('#pplGroups tbody tr');
        // Remember Show for this browser only - a convenience, never relied on.
        try { var saved = localStorage.getItem('people.groups.status'); if (saved) status.value = saved; } catch (e) {}
        function apply() {
            var q = input.value.trim().toLowerCase(), all = status.value === 'all', shown = 0;
            rows.forEach(function (tr) {
                var hit = (all || tr.dataset.active === '1') && (q === '' || tr.dataset.text.indexOf(q) !== -1);
                tr.hidden = !hit;
                if (hit) shown++;
            });
            if (none) none.hidden = shown > 0 || !rows.length;
            try { localStorage.setItem('people.groups.status', status.value); } catch (e) {}
        }
        input.addEventListener('input', apply);
        status.addEventListener('change', apply);
        apply();
    })();
    </script>
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=83"></script>
</body>
</html>
