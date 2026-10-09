<?php
/**
 * Projects - help. Numbered sections with a scroll-spy sidebar, the house layout
 * from assets/css/help.css (the People and Domains guides' model). The words
 * live in lang/<locale>/projects.php under help.*, one entry per paragraph, so
 * every section is translatable and this file is only the shape.
 *
 * ⚠️ The health rules in section "health" must match projectAutoHealth() in
 * includes/projects/read.php. Change one, change the other.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
require_once '../includes/tenancy.php';
require_once '../includes/projects/methodologies.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('projects');

$current_page = 'help';
$path_prefix = '../';
$multi = isMultiTenant(connectToDatabase());

// id => number of plain paragraphs (help.<id>.p1 ... pN) shown before the
// section's own block (cards, defs, steps). Paragraphs AFTER that block are
// listed in $after.
$sections = [
    'overview'  => 2,   // 3.3.0: p2 the demo data
    'map'       => 1,   // 3.3.0: How it fits together - the interactive map (projects-tour.js)
    'portfolio' => 5,
    'project'   => 4,
    'plan'      => 4,
    'timeline'  => 4,
    'capacity'  => 3,
    'methods'   => 2,
    'templates' => 4,
    'intake'    => 3,
    'tools'     => 1,
    'people'    => 5,
    'scope'     => 1,
    'raci'      => 1,
    'raid'      => 7,
    'gates'     => 3,
    'targets'   => 3,
    'health'    => 1,
    'tasks'     => 2,
    'contractors' => 4,   // 3.3.0: work given to a supplier
    'connections' => 3,
    'calendar'  => 2,
    'alerts'    => 5,
    'announce'  => 2,
    'budget'    => 6,
    'control'   => 3,
    'benefits'  => 3,
    'reports'   => 4,
    'assistant' => 4,   // 3.3.0: Ask AI - the project assistant
    'companies' => 1,
    'settings'  => 1,
    'api'       => 2,
];
if (!$multi) unset($sections['companies']);
$after = [
    'tools'    => ['p2', 'p3'],   // 3.3.0: p3 the Toolbox
    'scope'    => ['p2'],
    'raci'     => ['p2'],
    'gates'    => ['p4', 'p5', 'p6'],
    'health'   => ['p2'],
    'settings' => ['p2', 'p3'],
];
// The tools each method switches on, read from the presets so the help can never disagree with them.
$prjToolList = fn(string $method) => implode(', ', array_map(fn($k) => t('projects.tools.' . $k), projectMethodologies()[$method]['tools']));
$h = fn(string $k, array $p = []) => htmlspecialchars(t('projects.help.' . $k, $p));
/** Paragraph text may carry **bold**. */
$para = fn(string $k) => preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', htmlspecialchars(t('projects.help.' . $k)));
// ⚠️ Not $icon: includes/header.php loops its nav as [$href, $label, $icon] and would overwrite it.
$prjHelpIcon = fn(string $paths) => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName() . ' - ' . t('projects.help.title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs(['common', 'projects']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <?php // The map's method filter reads the presets, so it can never disagree with them (3.3.0). ?>
    <script>window.PRJ_TOUR = <?php echo json_encode(['methods' => array_map(fn($m) => $m['tools'], projectMethodologies()), 'labels' => array_map(fn($k) => t('projects.method.' . $k), array_combine(array_keys(projectMethodologies()), array_keys(projectMethodologies())))], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="<?php echo BASE_URL; ?>assets/js/tz.js?v=5"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/theme.css?v=26">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/inbox.css?v=77">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/projects.css?v=45">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/help.css?v=3">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/mobile.css?v=189">
    <script src="<?php echo BASE_URL; ?>assets/js/projects-tour.js?v=1"></script>
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--prj-accent, #e11d48);
            --accent-hover: var(--prj-accent-hover, #be123c);
            --accent-soft:  var(--prj-accent-soft, rgba(225, 29, 72, .1));
            --on-accent:    #fff;
        }
    </style>
</head>
<body data-mobile-module="projects" data-mobile-page="projects-help">
    <?php include 'includes/header.php'; ?>

    <div class="help-container">
        <div class="help-sidebar">
            <h3><?php echo $h('guide'); ?></h3>
            <?php $n = 0; foreach ($sections as $id => $_): $n++; ?>
            <a href="#<?php echo $id; ?>" class="help-nav-link<?php echo $n === 1 ? ' active' : ''; ?>" data-section="<?php echo $id; ?>">
                <span class="help-nav-num"><?php echo $n; ?></span>
                <?php echo $h($id . '.nav'); ?>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="help-main" id="helpMain">
            <div class="help-hero">
                <h2><?php echo $h('hero_heading'); ?></h2>
                <p><?php echo $h('hero_sub'); ?></p>
            </div>
            <div class="help-content">
                <?php $n = 0; foreach ($sections as $id => $paras): $n++; ?>
                <div class="help-section" id="<?php echo $id; ?>">
                    <div class="help-section-header">
                        <span class="help-section-num"><?php echo $n; ?></span>
                        <div>
                            <h3><?php echo $h($id . '.title'); ?></h3>
                            <p><?php echo $h($id . '.intro'); ?></p>
                        </div>
                    </div>

                    <?php for ($i = 1; $i <= $paras; $i++): ?>
                    <p><?php echo $para($id . '.p' . $i); ?></p>
                    <?php endfor; ?>

                    <?php if ($id === 'overview'): ?>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon"><?php echo $prjHelpIcon('<rect x="3" y="4" width="10" height="4" rx="1"></rect><rect x="7" y="10" width="11" height="4" rx="1"></rect><rect x="11" y="16" width="10" height="4" rx="1"></rect>'); ?></div>
                            <h4><?php echo $h('overview.card_plan_title'); ?></h4>
                            <p><?php echo $h('overview.card_plan_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon"><?php echo $prjHelpIcon('<path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>'); ?></div>
                            <h4><?php echo $h('overview.card_tasks_title'); ?></h4>
                            <p><?php echo $h('overview.card_tasks_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon"><?php echo $prjHelpIcon('<circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path>'); ?></div>
                            <h4><?php echo $h('overview.card_health_title'); ?></h4>
                            <p><?php echo $h('overview.card_health_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon"><?php echo $prjHelpIcon('<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"></path><path d="M4 22v-7"></path>'); ?></div>
                            <h4><?php echo $h('overview.card_method_title'); ?></h4>
                            <p><?php echo $h('overview.card_method_desc'); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($id === 'map'): ?>
                    <div class="prj-tour" id="prjTour"><noscript><p><?php echo $h('map.noscript'); ?></p></noscript></div>
                    <?php endif; ?>
                    <?php if ($id === 'methods'): ?>
                    <div class="help-defs">
                        <?php foreach (['simple', 'staged', 'agile'] as $m): ?>
                        <div class="help-def">
                            <div class="help-def-term"><?php echo htmlspecialchars(t('projects.method.' . $m)); ?></div>
                            <div class="help-def-desc"><?php echo $h('methods.' . $m . '_d'); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($id === 'health'): ?>
                    <div class="help-defs">
                        <?php foreach (['green', 'amber', 'red'] as $c): ?>
                        <div class="help-def">
                            <div class="help-def-term"><span class="prj-health-badge h-<?php echo $c; ?>"><span class="dot"></span><?php echo htmlspecialchars(t('projects.health.' . $c)); ?></span></div>
                            <div class="help-def-desc"><?php echo $h('health.' . $c . '_d'); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($id === 'tools'): ?>
                    <div class="help-defs">
                        <?php foreach (array_keys(projectMethodologies()) as $m): ?>
                        <div class="help-def">
                            <div class="help-def-term"><?php echo htmlspecialchars(t('projects.method.' . $m)); ?></div>
                            <div class="help-def-desc"><?php echo htmlspecialchars($prjToolList($m)); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($id === 'scope'): ?>
                    <div class="help-defs">
                        <?php foreach (['must', 'should', 'could', 'wont'] as $k): ?>
                        <div class="help-def">
                            <div class="help-def-term"><?php echo htmlspecialchars(t('projects.scope.' . $k)); ?></div>
                            <div class="help-def-desc"><?php echo htmlspecialchars(t('projects.scope.' . $k . '_hint')); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($id === 'raci'): ?>
                    <div class="help-defs">
                        <?php foreach (['r', 'a', 'c', 'i'] as $k): ?>
                        <div class="help-def">
                            <div class="help-def-term"><?php echo strtoupper($k) . ' - ' . htmlspecialchars(t('projects.raci.' . $k)); ?></div>
                            <div class="help-def-desc"><?php echo htmlspecialchars(t('projects.raci.' . $k . '_hint')); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($id === 'gates'): ?>
                    <div class="help-defs">
                        <?php foreach (['go', 'go_with_conditions', 'stop'] as $k): ?>
                        <div class="help-def">
                            <div class="help-def-term"><?php echo htmlspecialchars(t('projects.gates.' . $k)); ?></div>
                            <div class="help-def-desc"><?php echo $h('gates.' . $k . '_d'); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($id === 'settings'): ?>
                    <div class="help-defs">
                        <?php foreach (['general', 'health', 'roles', 'raid', 'templates', 'budget', 'ai'] as $k): ?>
                        <div class="help-def">
                            <div class="help-def-term"><?php echo htmlspecialchars(t('projects.settings.tab_' . $k)); ?></div>
                            <div class="help-def-desc"><?php echo $h('settings.' . $k . '_d'); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php foreach ($after[$id] ?? [] as $pk): ?>
                    <p><?php echo $para($id . '.' . $pk); ?></p>
                    <?php endforeach; ?>

                    <?php if ($id === 'plan'): ?>
                    <div class="help-steps">
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                        <div class="help-step">
                            <div class="help-step-num"><?php echo $i; ?></div>
                            <div><?php echo $para('plan.step' . $i); ?></div>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        // Scroll-spy: highlight the section in view (the house help-page script).
        const helpMain = document.getElementById('helpMain');
        const navLinks = document.querySelectorAll('.help-nav-link');
        const sections = [];
        navLinks.forEach(link => {
            const el = document.getElementById(link.dataset.section);
            if (el) sections.push({ id: link.dataset.section, el });
        });
        helpMain.addEventListener('scroll', function () {
            const scrollTop = helpMain.scrollTop;
            let current = sections[0]?.id;
            for (const s of sections) if (s.el.offsetTop - 200 <= scrollTop) current = s.id;
            navLinks.forEach(link => link.classList.toggle('active', link.dataset.section === current));
        });
        navLinks.forEach(link => {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                const el = document.getElementById(this.dataset.section);
                if (el) {
                    const containerTop = helpMain.getBoundingClientRect().top;
                    helpMain.scrollTo({ top: helpMain.scrollTop + (el.getBoundingClientRect().top - containerTop) - 20, behavior: 'smooth' });
                }
                navLinks.forEach(l => l.classList.remove('active'));
                this.classList.add('active');
            });
        });
    </script>
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=78"></script>
</body>
</html>
