<?php
/**
 * People — one company, and everything that belongs to it (#153 step 2).
 *
 * Read only. companyDetail() decides what this analyst may see: the company must
 * be one they can access, and each section must belong to a module they can open.
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
$tenantId = (int)($_GET['id'] ?? 0);
$data = companyDetail($conn, $analystId, $tenantId);
if ($data) entityVisit('company', $tenantId, $conn);

$current_page = 'companies';
$path_prefix = '../';
$company = $data['company'] ?? null;
$sections = $data['sections'] ?? [];
?>
<!DOCTYPE html>
<html lang="<?php echo pplE(I18n::getLocale()); ?>" data-theme="<?php echo pplE(Theme::active()); ?>" data-theme-mode="<?php echo pplE(Theme::mode()); ?>">
<head>
    <?php pplHead($company ? $company['name'] : t('people.title')); ?>
</head>
<body data-mobile-module="people" data-mobile-page="people-company">
    <?php include 'includes/header.php'; ?>

    <main class="ppl-page">
    <?php if (!$company): ?>
        <div class="ppl-card"><div class="ppl-empty"><?php echo pplE(t('people.company.not_found')); ?></div></div>
    <?php else: ?>
        <div class="ppl-hero">
            <div class="ppl-avatar company">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"></path><path d="M5 21V7l8-4v18"></path><path d="M19 21V11l-6-4"></path><path d="M9 9v.01"></path><path d="M9 12v.01"></path><path d="M9 15v.01"></path><path d="M9 18v.01"></path></svg>
            </div>
            <div class="ppl-hero-main">
                <h1><?php echo pplE($company['name']); ?>
                    <?php if ($company['is_default'] && $company['multi_company']): ?><?php echo pplPill(t('people.companies.default'), null, 'muted'); ?><?php endif; ?>
                    <?php if (!$company['is_active']): ?><?php echo pplPill(t('people.companies.inactive'), null, 'bad'); ?><?php endif; ?>
                </h1>
                <div class="ppl-hero-sub">
                    <?php
                    $bits = [];
                    if ($company['ticket_code']) $bits[] = pplE(t('people.company.ticket_code')) . ': <strong>' . pplE($company['ticket_code']) . '</strong>';
                    if ($company['email_domains']) $bits[] = pplE(t('people.company.email_domains')) . ': ' . pplE(implode(', ', $company['email_domains']));
                    if ($company['people_leavers']) $bits[] = pplE(t('people.company.leavers', ['n' => $company['people_leavers']]));
                    echo implode(' · ', $bits);
                    ?>
                </div>
                <?php if ($company['is_default'] && $company['multi_company']): ?><div class="ppl-hero-contact ppl-dim"><?php echo pplE(t('people.company.default_note')); ?></div><?php endif; ?>
            </div>
        </div>

        <?php echo pplStats($sections, false); ?>

        <div class="ppl-stack">
            <section class="ppl-card" id="sec-people">
                <div class="ppl-card-h">
                    <h3><?php echo pplE(t('people.company.people')); ?></h3><span class="ppl-count"><?php echo count($sections['people']); ?></span>
                    <input type="search" class="ppl-filter" id="pplFilter" placeholder="<?php echo pplE(t('people.company.filter_people')); ?>" aria-label="<?php echo pplE(t('people.company.filter_people')); ?>">
                </div>
                <?php if (!$sections['people']): ?>
                    <div class="ppl-empty"><?php echo pplE(t('people.section.empty')); ?></div>
                <?php else: ?>
                <div class="ppl-table-wrap ppl-people-wrap"><table class="ppl-table" id="pplPeople">
                    <thead><tr>
                        <th><?php echo pplE(t('people.list.col_name')); ?></th>
                        <th><?php echo pplE(t('people.list.col_email')); ?></th>
                        <th><?php echo pplE(t('people.list.col_role')); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($sections['people'] as $r): ?>
                        <tr data-text="<?php echo pplE(mb_strtolower($r['name'] . ' ' . $r['email'] . ' ' . $r['job_title'] . ' ' . $r['department'])); ?>">
                            <td data-label="<?php echo pplE(t('people.list.col_name')); ?>"><a href="person.php?id=<?php echo $r['id']; ?>"><?php echo pplE($r['name']); ?></a><?php echo $r['is_active'] ? '' : ' ' . pplPill(t('people.list.leaver'), null, 'muted'); ?></td>
                            <td data-label="<?php echo pplE(t('people.list.col_email')); ?>"><?php echo pplE($r['email']); ?></td>
                            <td data-label="<?php echo pplE(t('people.list.col_role')); ?>"><?php echo pplE(implode(' · ', array_filter([$r['job_title'], $r['department']]))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php endif; ?>
            </section>
            <?php echo pplSections($sections, false); ?>
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
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=71"></script>
</body>
</html>
