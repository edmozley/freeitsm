<?php
/**
 * People — one person, and everything about them from every module (#153 step 2).
 *
 * Read only. personDetail() decides what this analyst may see: the person must be
 * in a company they can access, and each section must belong to a module they can
 * open. Out of reach reads exactly like "no such person".
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
$userId = (int)($_GET['id'] ?? 0);
$data = personDetail($conn, $analystId, $userId);
if ($data) entityVisit('person', $userId, $conn);

$current_page = 'people';
$path_prefix = '../';
$p = $data['person'] ?? null;
$sections = $data['sections'] ?? [];
$canTickets = analystCanAccessModule($conn, $analystId, 'tickets');
$canAssets  = analystCanAccessModule($conn, $analystId, 'assets');
$multi = isMultiTenant($conn);
?>
<!DOCTYPE html>
<html lang="<?php echo pplE(I18n::getLocale()); ?>" data-theme="<?php echo pplE(Theme::active()); ?>" data-theme-mode="<?php echo pplE(Theme::mode()); ?>">
<head>
    <?php pplHead($p ? $p['name'] : t('people.title')); ?>
</head>
<body data-mobile-module="people" data-mobile-page="people-person">
    <?php include 'includes/header.php'; ?>

    <main class="ppl-page">
    <?php if (!$p): ?>
        <div class="ppl-card"><div class="ppl-empty"><?php echo pplE(t('people.person.not_found')); ?></div></div>
    <?php else: ?>
        <div class="ppl-hero">
            <div class="ppl-avatar<?php echo $p['is_active'] ? '' : ' leaver'; ?>"><?php echo pplE(pplInitials($p['name'])); ?></div>
            <div class="ppl-hero-main">
                <h1><?php echo pplE($p['name']); ?>
                    <?php if (!$p['is_active']): ?><?php echo pplPill(t('people.person.leaver'), null, 'bad'); ?><?php endif; ?>
                </h1>
                <div class="ppl-hero-sub">
                    <?php echo pplE(implode(' · ', array_filter([$p['job_title'], $p['department']]))); ?>
                    <?php if ($multi && $p['company']): ?>
                        <?php echo ($p['job_title'] || $p['department']) ? ' · ' : ''; ?><a href="company.php?id=<?php echo (int)$p['company_id']; ?>"><?php echo pplE($p['company']); ?></a>
                    <?php endif; ?>
                </div>
                <?php if ($p['email']): ?><div class="ppl-hero-contact"><a href="mailto:<?php echo pplE($p['email']); ?>"><?php echo pplE($p['email']); ?></a><?php echo $p['phone'] ? ' · ' . pplE($p['phone']) : ''; ?></div><?php endif; ?>
            </div>
            <?php if ($canTickets || $canAssets): ?>
            <div class="ppl-hero-actions">
                <span class="ppl-dim"><?php echo pplE(t('people.person.edit_in')); ?></span>
                <?php if ($canTickets): ?><a class="ppl-btn" href="<?php echo BASE_URL; ?>tickets/users.php?user_id=<?php echo (int)$p['id']; ?>"><?php echo pplE(t('people.person.tickets_app')); ?></a><?php endif; ?>
                <?php if ($canAssets): ?><a class="ppl-btn" href="<?php echo BASE_URL; ?>asset-management/users.php?user_id=<?php echo (int)$p['id']; ?>"><?php echo pplE(t('people.person.assets_app')); ?></a><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php echo pplStats($sections, true); ?>

        <div class="ppl-grid">
            <aside class="ppl-side">
                <section class="ppl-card">
                    <div class="ppl-card-h"><h3><?php echo pplE(t('people.person.details')); ?></h3></div>
                    <dl class="ppl-fields">
                        <?php
                        $f = function (string $key, $value, bool $raw = false) {
                            if ($value === null || $value === '' || $value === []) return;
                            echo '<dt>' . pplE(t('people.person.' . $key)) . '</dt><dd>' . ($raw ? $value : pplE($value)) . '</dd>';
                        };
                        $f('email', $p['email'] ? '<a href="mailto:' . pplE($p['email']) . '">' . pplE($p['email']) . '</a>' : null, true);
                        $f('username', $p['username'] !== $p['email'] ? $p['username'] : null);
                        $f('phone', $p['phone']);
                        $f('mobile', $p['mobile']);
                        $f('office', $p['office']);
                        $f('department', $p['department']);
                        $f('employee_id', $p['employee_id']);
                        if ($multi) $f('company', $p['company'] ? '<a href="company.php?id=' . (int)$p['company_id'] . '">' . pplE($p['company']) . '</a>' : null, true);
                        $f('manager', $p['manager'] ? '<a href="person.php?id=' . $p['manager']['id'] . '">' . pplE($p['manager']['name']) . '</a>' : null, true);
                        if ($p['reports']) {
                            $f('reports', implode('', array_map(fn($r) => '<div><a href="person.php?id=' . $r['id'] . '">' . pplE($r['name']) . '</a>' . ($r['is_active'] ? '' : ' <span class="ppl-dim">(' . pplE(t('people.person.leaver')) . ')</span>') . '</div>', $p['reports'])), true);
                        }
                        $f('source', $p['source']);
                        $f('first_seen', fmt_date($p['created_at']));
                        if (!$p['is_active']) $f('left', fmt_date($p['deactivated_datetime']));
                        ?>
                    </dl>
                </section>
            </aside>
            <div class="ppl-main">
                <?php echo pplSections($sections, true); ?>
            </div>
        </div>
    <?php endif; ?>
    </main>
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=77"></script>
</body>
</html>
