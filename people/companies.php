<?php
/**
 * People — the companies this analyst can see (#153 step 2).
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
$companies = peopleCompanyCards($conn, $analystId);
$multi = isMultiTenant($conn);
$current_page = 'companies';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="<?php echo pplE(I18n::getLocale()); ?>" data-theme="<?php echo pplE(Theme::active()); ?>" data-theme-mode="<?php echo pplE(Theme::mode()); ?>">
<head>
    <?php pplHead(t('people.nav.companies')); ?>
</head>
<body data-mobile-module="people" data-mobile-page="people-companies">
    <?php include 'includes/header.php'; ?>

    <main class="ppl-page">
        <div class="ppl-co-bar">
            <p class="ppl-dim ppl-lead"><?php echo pplE(t($multi ? 'people.companies.intro' : 'people.companies.single')); ?></p>
            <?php if (count($companies) > 1): ?>
            <input type="search" class="ppl-co-search" id="pplCoSearch" placeholder="<?php echo pplE(t('people.companies.search_ph')); ?>" aria-label="<?php echo pplE(t('people.companies.search_ph')); ?>" autocomplete="off">
            <?php endif; ?>
        </div>

        <div class="ppl-co-grid" id="pplCoGrid">
        <?php foreach ($companies as $co):
            // Two letters, and a hue from the name, so each company is recognisable
            // at a glance rather than one more identical building icon.
            $hue = crc32(mb_strtolower($co['name'])) % 360;
            $meta = [];
            if (!empty($co['ticket_code'])) $meta[] = t('people.companies.code', ['code' => $co['ticket_code']]);
            if ($co['domains'] > 0) $meta[] = $co['domains'] === 1 ? t('people.companies.domains_one') : t('people.companies.domains_n', ['n' => $co['domains']]);
            $stats = [[$co['people'], t('people.companies.stat_people'), false]];
            if ($co['open_tickets'] !== null) $stats[] = [$co['open_tickets'], t('people.companies.stat_open'), $co['open_tickets'] > 0];
            if ($co['assets'] !== null)       $stats[] = [$co['assets'], t('people.companies.stat_assets'), false];
        ?>
            <a class="ppl-co-card<?php echo $co['is_active'] ? '' : ' inactive'; ?>" href="company.php?id=<?php echo $co['id']; ?>"
               data-search="<?php echo pplE(mb_strtolower($co['name'] . ' ' . ($co['ticket_code'] ?? ''))); ?>">
                <span class="ppl-co-top">
                    <span class="ppl-co-avatar" style="--co-hue: <?php echo (int)$hue; ?>" aria-hidden="true"><?php echo pplE(pplInitials($co['name'])); ?></span>
                    <span class="ppl-co-id">
                        <span class="ppl-co-name"><?php echo pplE($co['name']); ?>
                            <?php if ($co['is_default'] && $multi): ?><?php echo pplPill(t('people.companies.default'), null, 'muted'); ?><?php endif; ?>
                            <?php if (!$co['is_active']): ?><?php echo pplPill(t('people.companies.inactive'), null, 'bad'); ?><?php endif; ?>
                        </span>
                        <?php if ($meta): ?><span class="ppl-co-meta"><?php echo pplE(implode(' · ', $meta)); ?></span><?php endif; ?>
                    </span>
                    <svg class="ppl-co-go" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg>
                </span>
                <span class="ppl-co-stats">
                    <?php foreach ($stats as [$v, $label, $hot]): ?>
                    <span class="ppl-co-stat<?php echo $hot ? ' hot' : ''; ?>"><span class="v"><?php echo (int)$v; ?></span><span class="l"><?php echo pplE($label); ?></span></span>
                    <?php endforeach; ?>
                </span>
            </a>
        <?php endforeach; ?>
        </div>
        <div class="ppl-card ppl-co-none" id="pplCoNone" hidden><div class="ppl-empty"><?php echo pplE(t('people.companies.none_match')); ?></div></div>
    </main>
    <script>
    (function () {
        var input = document.getElementById('pplCoSearch');
        if (!input) return;
        var cards = document.querySelectorAll('#pplCoGrid .ppl-co-card');
        var none = document.getElementById('pplCoNone');
        input.addEventListener('input', function () {
            var q = input.value.trim().toLowerCase(), shown = 0;
            cards.forEach(function (c) {
                var hit = q === '' || c.getAttribute('data-search').indexOf(q) !== -1;
                c.hidden = !hit;
                if (hit) shown++;
            });
            none.hidden = shown > 0;
        });
    })();
    </script>
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=72"></script>
</body>
</html>
