<?php
/**
 * People — every supplier, with its contacts (#153 step 3, #162).
 *
 * Shown here, kept in Contracts: suppliers and their contacts are Contracts'
 * records, so this page needs Contracts as well as People. Nothing is moved -
 * a supplier's contact never becomes a user who can sign in or raise tickets.
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
requireModuleAccess('contracts');

$conn = connectToDatabase();
$analystId = (int)$_SESSION['analyst_id'];
$suppliers = peopleSupplierRows($conn, $analystId, ['status' => 'all']);
$current_page = 'suppliers';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="<?php echo pplE(I18n::getLocale()); ?>" data-theme="<?php echo pplE(Theme::active()); ?>" data-theme-mode="<?php echo pplE(Theme::mode()); ?>">
<head>
    <?php pplHead(t('people.nav.suppliers')); ?>
</head>
<body data-mobile-module="people" data-mobile-page="people-suppliers">
    <?php include 'includes/header.php'; ?>

    <main class="ppl-page">
        <div class="ppl-co-bar">
            <p class="ppl-dim ppl-lead"><?php echo pplE(t('people.suppliers.intro')); ?></p>
            <input type="search" class="ppl-co-search" id="pplSupSearch" placeholder="<?php echo pplE(t('people.suppliers.search_ph')); ?>" aria-label="<?php echo pplE(t('people.suppliers.search_ph')); ?>" autocomplete="off">
            <select id="pplSupStatus" class="ppl-co-search ppl-sup-status" aria-label="<?php echo pplE(t('people.list.show')); ?>">
                <option value="active"><?php echo pplE(t('people.suppliers.status_active')); ?></option>
                <option value="all"><?php echo pplE(t('people.suppliers.status_all')); ?></option>
            </select>
        </div>

        <section class="ppl-card">
            <?php if (!$suppliers): ?>
                <div class="ppl-empty"><?php echo pplE(t('people.section.empty')); ?></div>
            <?php else: ?>
            <div class="ppl-table-wrap"><table class="ppl-table" id="pplSuppliers">
                <thead><tr>
                    <th><?php echo pplE(t('people.suppliers.col_name')); ?></th>
                    <th><?php echo pplE(t('people.suppliers.col_type')); ?></th>
                    <th><?php echo pplE(t('people.suppliers.col_place')); ?></th>
                    <th><?php echo pplE(t('people.suppliers.col_contacts')); ?></th>
                    <th><?php echo pplE(t('people.suppliers.col_contracts')); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($suppliers as $s): ?>
                    <tr data-active="<?php echo $s['is_active'] ? '1' : '0'; ?>"
                        data-text="<?php echo pplE(mb_strtolower($s['name'] . ' ' . $s['legal_name'] . ' ' . $s['place'] . ' ' . $s['contact_text'])); ?>">
                        <td data-label="<?php echo pplE(t('people.suppliers.col_name')); ?>"><a href="supplier.php?id=<?php echo $s['id']; ?>"><?php echo pplE($s['name']); ?></a><?php echo $s['is_active'] ? '' : ' ' . pplPill(t('people.suppliers.inactive'), null, 'muted'); ?></td>
                        <td data-label="<?php echo pplE(t('people.suppliers.col_type')); ?>"><?php echo pplE($s['type']); ?></td>
                        <td data-label="<?php echo pplE(t('people.suppliers.col_place')); ?>"><?php echo pplE($s['place']); ?></td>
                        <td data-label="<?php echo pplE(t('people.suppliers.col_contacts')); ?>"><?php echo (int)$s['contacts']; ?></td>
                        <td data-label="<?php echo pplE(t('people.suppliers.col_contracts')); ?>"><?php echo (int)$s['contracts']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="ppl-empty" id="pplSupNone" hidden><?php echo pplE(t('people.suppliers.none')); ?></div>
            <?php endif; ?>
        </section>
    </main>
    <script>
    (function () {
        var input = document.getElementById('pplSupSearch');
        var status = document.getElementById('pplSupStatus');
        var none = document.getElementById('pplSupNone');
        var rows = document.querySelectorAll('#pplSuppliers tbody tr');
        // Remember Show for this browser only - a convenience, never relied on.
        try { var saved = localStorage.getItem('people.suppliers.status'); if (saved) status.value = saved; } catch (e) {}
        function apply() {
            var q = input.value.trim().toLowerCase(), all = status.value === 'all', shown = 0;
            rows.forEach(function (tr) {
                var hit = (all || tr.dataset.active === '1') && (q === '' || tr.dataset.text.indexOf(q) !== -1);
                tr.hidden = !hit;
                if (hit) shown++;
            });
            if (none) none.hidden = shown > 0 || !rows.length;
            try { localStorage.setItem('people.suppliers.status', status.value); } catch (e) {}
        }
        input.addEventListener('input', apply);
        status.addEventListener('change', apply);
        apply();
    })();
    </script>
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=78"></script>
</body>
</html>
