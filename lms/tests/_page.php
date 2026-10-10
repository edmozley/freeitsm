<?php
/**
 * LMS -> Tests: what every analyst page here shares - the gates, and the <head>.
 * The pages are lms/tests/index.php, edit.php and result.php; their behaviour
 * is assets/js/lms-tests.js, chosen by <body data-ct-page>.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once __DIR__ . '/../../includes/theme.php';
require_once __DIR__ . '/../../includes/timezone.php';
require_once __DIR__ . '/../../includes/rbac.php';
require_once __DIR__ . '/../../includes/lms/competency_tests.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('lms');
requireCapability(Cap::LMS_TESTS);

$current_page = 'tests';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'lms'];

/** A translated string, or the English given here while a key is still owed. */
function lt(string $key, string $english, array $params = []): string
{
    $full = 'lms.tests.' . $key;
    $out = t($full, $params);
    if ($out === $full || $out === '') {
        foreach ($params as $k => $v) $english = str_replace('{' . $k . '}', (string)$v, $english);
        return $english;
    }
    return $out;
}

function ctHead(string $title): void
{
    global $translationNamespaces;
    $b = BASE_URL; ?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo $b; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName()); ?> - <?php echo htmlspecialchars($title); ?></title>
    <link rel="stylesheet" href="<?php echo $b; ?>assets/css/theme.css?v=27">
    <link rel="stylesheet" href="<?php echo $b; ?>assets/css/inbox.css?v=78">
    <link rel="stylesheet" href="<?php echo $b; ?>assets/css/lms.css?v=10">
    <link rel="stylesheet" href="<?php echo $b; ?>assets/css/lms-tests.css?v=2">
    <?php /* mobile.css LAST, after every module sheet: its @media rules win ties on load order (wiki Mobile-Friendly-Techniques §9). */ ?>
    <link rel="stylesheet" href="<?php echo $b; ?>assets/css/mobile.css?v=192">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
            window.CT_BASE = <?php echo json_encode($b); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo $b; ?>assets/js/tz.js?v=5"></script>
    <script src="<?php echo $b; ?>assets/js/i18n.js?v=3"></script>
</head>
<?php }

function ctFoot(): void
{ ?>
    <script src="<?php echo BASE_URL; ?>assets/js/lms-tests.js?v=2"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=78"></script>
</body>
</html>
<?php }
