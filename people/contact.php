<?php
/**
 * People — one supplier contact (#153 step 3, #162).
 *
 * Read only, and NOT a user: a supplier's contact stays in Contracts → Contacts,
 * so it cannot sign in, raise a ticket or be picked up by directory sync.
 * supplierContactDetail() needs People and Contracts, and each section its own
 * module.
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
$contactId = (int)($_GET['id'] ?? 0);
$data = supplierContactDetail($conn, $analystId, $contactId);
if ($data) entityVisit('supplier_contact', $contactId, $conn);

$current_page = 'suppliers';
$path_prefix = '../';
$ct = $data['contact'] ?? null;
$sections = $data['sections'] ?? [];
?>
<!DOCTYPE html>
<html lang="<?php echo pplE(I18n::getLocale()); ?>" data-theme="<?php echo pplE(Theme::active()); ?>" data-theme-mode="<?php echo pplE(Theme::mode()); ?>">
<head>
    <?php pplHead($ct ? $ct['name'] : t('people.title')); ?>
</head>
<body data-mobile-module="people" data-mobile-page="people-contact">
    <?php include 'includes/header.php'; ?>

    <main class="ppl-page">
    <?php if (!$ct): ?>
        <div class="ppl-card"><div class="ppl-empty"><?php echo pplE(t('people.contact.not_found')); ?></div></div>
    <?php else: ?>
        <div class="ppl-hero">
            <div class="ppl-avatar<?php echo $ct['is_active'] ? '' : ' leaver'; ?>"><?php echo pplE(pplInitials($ct['name'])); ?></div>
            <div class="ppl-hero-main">
                <h1><?php echo pplE($ct['name']); ?>
                    <?php if (!$ct['is_active']): ?><?php echo pplPill(t('people.contact.inactive'), null, 'bad'); ?><?php endif; ?>
                </h1>
                <div class="ppl-hero-sub">
                    <?php echo pplE($ct['job_title'] ?: t('people.contact.is_contact')); ?>
                    <?php if ($ct['supplier_id']): ?> · <a href="supplier.php?id=<?php echo (int)$ct['supplier_id']; ?>"><?php echo pplE($ct['supplier']); ?></a><?php endif; ?>
                </div>
                <?php if ($ct['email']): ?><div class="ppl-hero-contact"><a href="mailto:<?php echo pplE($ct['email']); ?>"><?php echo pplE($ct['email']); ?></a><?php echo ($ct['direct_dial'] ?: $ct['mobile']) ? ' · ' . pplE($ct['direct_dial'] ?: $ct['mobile']) : ''; ?></div><?php endif; ?>
            </div>
            <div class="ppl-hero-actions">
                <a class="ppl-btn" href="<?php echo BASE_URL; ?>contracts/contacts/"><?php echo pplE(t('people.contact.edit')); ?></a>
            </div>
        </div>

        <?php echo pplStats($sections, false, ['domains' => t('people.contact.domains')]); ?>

        <div class="ppl-grid">
            <aside class="ppl-side">
                <section class="ppl-card">
                    <div class="ppl-card-h"><h3><?php echo pplE(t('people.contact.details')); ?></h3></div>
                    <dl class="ppl-fields">
                        <?php
                        $f = function (string $key, $value, bool $raw = false) {
                            if ($value === null || $value === '' || $value === []) return;
                            echo '<dt>' . pplE(t('people.contact.' . $key)) . '</dt><dd>' . ($raw ? $value : pplE($value)) . '</dd>';
                        };
                        $f('supplier', $ct['supplier_id'] ? '<a href="supplier.php?id=' . (int)$ct['supplier_id'] . '">' . pplE($ct['supplier']) . '</a>' : null, true);
                        $f('email', $ct['email'] ? '<a href="mailto:' . pplE($ct['email']) . '">' . pplE($ct['email']) . '</a>' : null, true);
                        $f('direct_dial', $ct['direct_dial']);
                        $f('mobile', $ct['mobile']);
                        $f('switchboard', $ct['switchboard']);
                        $f('added', $ct['created'] ? fmt_date($ct['created']) : null);
                        ?>
                    </dl>
                </section>
            </aside>
            <div class="ppl-main">
                <?php if (isset($sections['domains'])) echo pplSectionDomains($sections['domains'], false, t('people.contact.domains')); ?>
            </div>
        </div>
    <?php endif; ?>
    </main>
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=76"></script>
</body>
</html>
