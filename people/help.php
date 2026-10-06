<?php
/**
 * People — help. Numbered sections with a scroll-spy sidebar, the house layout
 * from assets/css/help.css (the Domains guide's model). The words live in
 * lang/<locale>/people.php under help.*, one entry per paragraph, so every
 * section is translatable and this file is only the shape.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
require_once '../includes/tenancy.php';
require_once 'includes/render.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('people');

$current_page = 'help';
$path_prefix = '../';
$multi = isMultiTenant(connectToDatabase());

// id => number of plain paragraphs (help.<id>.p1 … pN). Sections with cards,
// steps or definitions draw those as well, below.
$sections = [
    'overview'  => 1,
    'people'    => 3,
    'person'    => 3,
    'companies' => 3,
    'suppliers' => 4,
    'getting'   => 0,
    'access'    => 2,
    'edit'      => 2,
    'who'       => 2,
];
// The third companies paragraph is about the Default company, which only means
// something where there is more than one.
if (!$multi) $sections['companies'] = 2;
$h = fn(string $k, array $p = []) => htmlspecialchars(t('people.help.' . $k, $p));
/** Paragraph text may carry **bold** and `code`. */
$para = fn(string $k) => preg_replace(['/\*\*([^*]+)\*\*/', '/`([^`]+)`/'], ['<strong>$1</strong>', '<code>$1</code>'],
                                      htmlspecialchars(t('people.help.' . $k)));
// ⚠️ Not $icon: includes/header.php loops its nav as [$href, $label, $icon] and would overwrite it.
$pplHelpIcon = fn(string $paths) => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
?>
<!DOCTYPE html>
<html lang="<?php echo pplE(I18n::getLocale()); ?>" data-theme="<?php echo pplE(Theme::active()); ?>" data-theme-mode="<?php echo pplE(Theme::mode()); ?>">
<head>
    <?php pplHead(t('people.help.title'), ['common', 'people'], ['assets/css/help.css?v=3']); ?>
    <style>
        /* The only thing a help page should need to say for itself: its colour.
           People's accent is local to people.css (--ppl-accent), lifted on dark. */
        body {
            --accent:       var(--ppl-accent, #a21caf);
            --accent-hover: var(--ppl-accent, #86198f);
            --accent-soft:  var(--ppl-accent-soft, rgba(162, 28, 175, .1));
            --on-accent:    #fff;
        }
    </style>
</head>
<body data-mobile-module="people" data-mobile-page="people-help">
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

                    <?php /* person.p3 follows its list of sections, below */ ?>
                    <?php for ($i = 1; $i <= ($id === 'person' ? 2 : $paras); $i++): ?>
                    <p><?php echo $para($id . '.p' . $i); ?></p>
                    <?php endfor; ?>

                    <?php if ($id === 'overview'): ?>
                    <div class="help-cards">
                        <div class="help-card">
                            <div class="help-card-icon"><?php echo $pplHelpIcon('<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>'); ?></div>
                            <h4><?php echo $h('overview.card_person_title'); ?></h4>
                            <p><?php echo $h('overview.card_person_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon"><?php echo $pplHelpIcon('<path d="M3 21h18"></path><path d="M5 21V7l8-4v18"></path><path d="M19 21V11l-6-4"></path>'); ?></div>
                            <h4><?php echo $h('overview.card_company_title'); ?></h4>
                            <p><?php echo $h('overview.card_company_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon"><?php echo $pplHelpIcon('<rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle>'); ?></div>
                            <h4><?php echo $h('overview.card_supplier_title'); ?></h4>
                            <p><?php echo $h('overview.card_supplier_desc'); ?></p>
                        </div>
                        <div class="help-card">
                            <div class="help-card-icon"><?php echo $pplHelpIcon('<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>'); ?></div>
                            <h4><?php echo $h('overview.card_read_title'); ?></h4>
                            <p><?php echo $h('overview.card_read_desc'); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($id === 'person'): ?>
                    <div class="help-defs">
                        <?php foreach (['tickets', 'assets', 'contracts', 'domains', 'courses', 'forms'] as $s): ?>
                        <div class="help-def">
                            <div class="help-def-term"><?php echo $h('person.sec_' . $s); ?></div>
                            <div class="help-def-desc"><?php echo $h('person.sec_' . $s . '_d'); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <p><?php echo $para('person.p3'); ?></p>
                    <?php endif; ?>

                    <?php if ($id === 'getting'): ?>
                    <div class="help-steps">
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                        <div class="help-step">
                            <div class="help-step-num"><?php echo $i; ?></div>
                            <div><?php echo $para('getting.step' . $i); ?></div>
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
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=74"></script>
</body>
</html>
