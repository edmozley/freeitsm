<?php
/**
 * People — one directory group and the people in it.
 *
 * Read only. peopleGroupDetail() decides what this analyst may see: the group
 * must belong to a provider they can see, and only members in companies they
 * can access are listed. Out of reach reads exactly like "no such group".
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
$group = peopleGroupDetail($conn, $analystId, (int)($_GET['id'] ?? 0));
$current_page = 'groups';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="<?php echo pplE(I18n::getLocale()); ?>" data-theme="<?php echo pplE(Theme::active()); ?>" data-theme-mode="<?php echo pplE(Theme::mode()); ?>">
<head>
    <?php pplHead($group ? $group['name'] : t('people.title')); ?>
</head>
<body data-mobile-module="people" data-mobile-page="people-group">
    <?php include 'includes/header.php'; ?>

    <main class="ppl-page">
    <?php if (!$group): ?>
        <div class="ppl-card"><div class="ppl-empty"><?php echo pplE(t('people.groups.not_found')); ?></div></div>
    <?php else: ?>
        <div class="ppl-hero">
            <div class="ppl-avatar company">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="3"></circle><circle cx="5" cy="19" r="3"></circle><circle cx="19" cy="19" r="3"></circle><path d="M12 8v4"></path><path d="M12 12l-5 4.5"></path><path d="M12 12l5 4.5"></path></svg>
            </div>
            <div class="ppl-hero-main">
                <h1><?php echo pplE($group['name']); ?>
                    <?php if (!$group['is_active']): ?><?php echo pplPill(t('people.groups.inactive'), null, 'muted'); ?><?php endif; ?>
                </h1>
                <div class="ppl-hero-sub">
                    <?php
                    $bits = [];
                    if ($kind = pplGroupKind($group['kind'])) $bits[] = pplE($kind);
                    if ($group['mail']) $bits[] = '<a href="mailto:' . pplE($group['mail']) . '">' . pplE($group['mail']) . '</a>';
                    $bits[] = pplE(t('people.groups.from', ['source' => $group['source'] . ($multi && $group['source_company'] ? ' · ' . $group['source_company'] : '')]));
                    if ($group['last_seen']) $bits[] = pplE(t('people.groups.last_seen', ['date' => fmt_date($group['last_seen'])]));
                    echo implode(' · ', $bits);
                    ?>
                </div>
                <?php if ($group['description']): ?><div class="ppl-hero-contact ppl-dim"><?php echo pplE($group['description']); ?></div><?php endif; ?>
                <?php if (!$group['is_active']): ?><div class="ppl-hero-contact ppl-dim"><?php echo pplE(t('people.groups.inactive_note')); ?></div><?php endif; ?>
            </div>
        </div>

        <div class="ppl-stack">
            <section class="ppl-card" id="sec-people">
                <div class="ppl-card-h">
                    <h3><?php echo pplE(t('people.groups.members')); ?></h3><span class="ppl-count"><?php echo count($group['members']); ?></span>
                    <input type="search" class="ppl-filter" id="pplFilter" placeholder="<?php echo pplE(t('people.company.filter_people')); ?>" aria-label="<?php echo pplE(t('people.company.filter_people')); ?>">
                </div>
                <p class="ppl-dim ppl-group-note"><?php echo pplE(t('people.groups.members_note', ['n' => $group['in_source']])); ?></p>
                <?php if (!$group['members']): ?>
                    <div class="ppl-empty"><?php echo pplE(t('people.section.empty')); ?></div>
                <?php else: ?>
                <div class="ppl-table-wrap ppl-people-wrap"><table class="ppl-table" id="pplPeople">
                    <thead><tr>
                        <th><?php echo pplE(t('people.list.col_name')); ?></th>
                        <th><?php echo pplE(t('people.list.col_email')); ?></th>
                        <th><?php echo pplE(t('people.list.col_role')); ?></th>
                        <?php if ($multi): ?><th><?php echo pplE(t('people.list.col_company')); ?></th><?php endif; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($group['members'] as $r): ?>
                        <tr data-text="<?php echo pplE(mb_strtolower($r['name'] . ' ' . $r['email'] . ' ' . $r['job_title'] . ' ' . $r['department'])); ?>">
                            <td data-label="<?php echo pplE(t('people.list.col_name')); ?>"><a href="person.php?id=<?php echo $r['id']; ?>"><?php echo pplE($r['name']); ?></a><?php echo $r['is_active'] ? '' : ' ' . pplPill(t('people.list.leaver'), null, 'muted'); ?></td>
                            <td data-label="<?php echo pplE(t('people.list.col_email')); ?>"><?php echo pplE($r['email']); ?></td>
                            <td data-label="<?php echo pplE(t('people.list.col_role')); ?>"><?php echo pplE(implode(' · ', array_filter([$r['job_title'], $r['department']]))); ?></td>
                            <?php if ($multi): ?><td data-label="<?php echo pplE(t('people.list.col_company')); ?>"><?php echo pplE($r['company']); ?></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
    </main>
    <script>
    (function () {
        var input = document.getElementById('pplFilter');
        if (!input) return;
        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase();
            document.querySelectorAll('#pplPeople tbody tr').forEach(function (tr) {
                tr.hidden = q !== '' && tr.dataset.text.indexOf(q) === -1;
            });
        });
    })();
    </script>
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=83"></script>
</body>
</html>
