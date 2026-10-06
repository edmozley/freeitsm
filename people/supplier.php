<?php
/**
 * People — one supplier, its contacts, and everything it has to do with
 * (#153 step 3, #162).
 *
 * Read only. supplierDetail() decides what this analyst may see: the page needs
 * People and Contracts (which owns suppliers), and each section its own module.
 * Changes are made in Contracts, one click away.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
require_once '../includes/people.php';
require_once '../includes/recent_trail.php';
require_once 'includes/render.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('people');

$conn = connectToDatabase();
$analystId = (int)$_SESSION['analyst_id'];
$supplierId = (int)($_GET['id'] ?? 0);
$data = supplierDetail($conn, $analystId, $supplierId);
if ($data) entityVisit('supplier', $supplierId, $conn);

$current_page = 'suppliers';
$path_prefix = '../';
$sup = $data['supplier'] ?? null;
$sections = $data['sections'] ?? [];
?>
<!DOCTYPE html>
<html lang="<?php echo pplE(I18n::getLocale()); ?>" data-theme="<?php echo pplE(Theme::active()); ?>" data-theme-mode="<?php echo pplE(Theme::mode()); ?>">
<head>
    <?php pplHead($sup ? $sup['name'] : t('people.title')); ?>
</head>
<body data-mobile-module="people" data-mobile-page="people-supplier">
    <?php include 'includes/header.php'; ?>

    <main class="ppl-page">
    <?php if (!$sup): ?>
        <div class="ppl-card"><div class="ppl-empty"><?php echo pplE(t('people.supplier.not_found')); ?></div></div>
    <?php else: ?>
        <div class="ppl-hero">
            <div class="ppl-avatar company">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
            </div>
            <div class="ppl-hero-main">
                <h1><?php echo pplE($sup['name']); ?>
                    <?php if (!$sup['is_active']): ?><?php echo pplPill(t('people.supplier.inactive'), null, 'bad'); ?><?php endif; ?>
                </h1>
                <div class="ppl-hero-sub"><?php echo pplE(implode(' · ', array_filter([$sup['type'], $sup['status'], $sup['address'] ? end($sup['address']) : null]))); ?></div>
            </div>
            <div class="ppl-hero-actions">
                <a class="ppl-btn" href="<?php echo BASE_URL; ?>contracts/suppliers/view/?id=<?php echo (int)$sup['id']; ?>"><?php echo pplE(t('people.supplier.edit')); ?></a>
            </div>
        </div>

        <?php $labels = ['contracts' => t('people.supplier.contracts'), 'assets' => t('people.supplier.assets'), 'domains' => t('people.supplier.domains')]; ?>
        <?php echo pplStats($sections, false, $labels); ?>

        <div class="ppl-grid">
            <aside class="ppl-side">
                <section class="ppl-card">
                    <div class="ppl-card-h"><h3><?php echo pplE(t('people.supplier.details')); ?></h3></div>
                    <dl class="ppl-fields">
                        <?php
                        $f = function (string $key, $value, bool $raw = false) {
                            if ($value === null || $value === '' || $value === []) return;
                            echo '<dt>' . pplE(t('people.supplier.' . $key)) . '</dt><dd>' . ($raw ? $value : pplE($value)) . '</dd>';
                        };
                        $f('legal_name', $sup['legal_name'] !== $sup['name'] ? $sup['legal_name'] : null);
                        $f('type', $sup['type']);
                        $f('status', $sup['status']);
                        $f('reg_number', $sup['reg_number']);
                        $f('vat_number', $sup['vat_number']);
                        $f('address', $sup['address'] ? implode('', array_map(fn($l) => '<div>' . pplE($l) . '</div>', $sup['address'])) : null, true);
                        ?>
                    </dl>
                </section>
            </aside>
            <div class="ppl-main">
                <section class="ppl-card" id="sec-contacts">
                    <div class="ppl-card-h"><h3><?php echo pplE(t('people.supplier.contacts')); ?></h3><span class="ppl-count"><?php echo count($sections['contacts']); ?></span></div>
                    <?php if (!$sections['contacts']): ?>
                        <div class="ppl-empty"><?php echo pplE(t('people.section.empty')); ?></div>
                    <?php else: ?>
                    <div class="ppl-table-wrap"><table class="ppl-table">
                        <thead><tr>
                            <th><?php echo pplE(t('people.supplier.col_name')); ?></th>
                            <th><?php echo pplE(t('people.supplier.col_role')); ?></th>
                            <th><?php echo pplE(t('people.supplier.col_email')); ?></th>
                            <th><?php echo pplE(t('people.supplier.col_phone')); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($sections['contacts'] as $c): ?>
                            <tr>
                                <td data-label="<?php echo pplE(t('people.supplier.col_name')); ?>"><a href="contact.php?id=<?php echo $c['id']; ?>"><?php echo pplE($c['name']); ?></a><?php echo $c['is_active'] ? '' : ' ' . pplPill(t('people.supplier.inactive'), null, 'muted'); ?></td>
                                <td data-label="<?php echo pplE(t('people.supplier.col_role')); ?>"><?php echo pplE($c['job_title']); ?></td>
                                <td data-label="<?php echo pplE(t('people.supplier.col_email')); ?>"><?php echo $c['email'] ? '<a href="mailto:' . pplE($c['email']) . '">' . pplE($c['email']) . '</a>' : ''; ?></td>
                                <td data-label="<?php echo pplE(t('people.supplier.col_phone')); ?>"><?php echo pplE($c['phone']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <?php endif; ?>
                </section>
                <?php
                if (isset($sections['contracts'])) echo pplSectionContracts($sections['contracts'], false, t('people.supplier.contracts'));
                if (isset($sections['assets']))    echo pplSectionAssets($sections['assets'], false, t('people.supplier.assets'));
                if (isset($sections['domains']))   echo pplSectionDomains($sections['domains'], false, t('people.supplier.domains'));
                ?>
            </div>
        </div>
    <?php endif; ?>
    </main>
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=76"></script>
</body>
</html>
