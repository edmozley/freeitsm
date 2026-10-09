<?php
/**
 * Domains — help (#154). Numbered sections with a scroll-spy sidebar, the house
 * layout from assets/css/help.css. The words live in lang/<locale>/domains.php
 * under help.*, one entry per paragraph, so every section is translatable and
 * this file is only the shape.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
require_once '../includes/tenancy.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
requireModuleAccess('domains');

$current_page = 'help';
$path_prefix = '../';
$translationNamespaces = ['common', 'domains'];
$multi = isMultiTenant(connectToDatabase());

// id => number of paragraphs (help.<id>.p1 … pN). The company section only
// appears where there is more than one company, like every other help page.
$sections = [
    'overview'   => 4,
    'adding'     => 5,
    'grade'      => 4,
    'email'      => 5,
    'certs'      => 5,
    'lookalikes' => 3,
    'changes'    => 3,
    'alerts'     => 7,
    'connections'=> 3,
    'status'     => 4,
    'rightclick' => 1,
    'people'     => 4,
    'accounts'   => 4,
    'schedule'   => 5,
    'companies'  => 4,
    'api'        => 4,
];
if (!$multi) unset($sections['companies']);
$h = fn(string $k, array $p = []) => htmlspecialchars(t('domains.help.' . $k, $p));
/** Paragraph text may carry `code` in backticks and **bold**. */
$para = fn(string $k) => preg_replace(['/\*\*([^*]+)\*\*/', '/`([^`]+)`/'], ['<strong>$1</strong>', '<code>$1</code>'], htmlspecialchars(t('domains.help.' . $k)));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName() . ' - ' . t('domains.title') . ' - ' . t('domains.nav.help')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=25">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=76">
    <link rel="stylesheet" href="../assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--dom-accent, #4d7c0f);
            --accent-hover: var(--dom-accent-hover, #3f6212);
            --accent-soft:  var(--dom-accent-soft, #ecfccb);
            --on-accent:    var(--dom-on-accent, #fff);
        }
    </style>
    <link rel="stylesheet" href="../assets/css/mobile.css?v=189">
</head>
<body data-mobile-module="domains" data-mobile-page="domains-help">
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
    <script src="../assets/js/mobile.js?v=78"></script>
</body>
</html>
