<?php
/**
 * Forms help topic — the chrome.
 *
 * A topic page sets $helpTopic to its slug, requires this, writes its sections,
 * and requires _bottom.php. Everything else — the head, the accent, the sidebar,
 * the section numbers — comes from here and from _registry.php.
 *
 * 🔑 THE SECTION NUMBERS COME FROM THE REGISTRY, NOT FROM COUNTING THE DOM.
 * System help numbered its sections in JavaScript once, so a section missing
 * from the registry silently shifted every number after it. helpNum() below
 * resolves from the same array that builds the sidebar; an id the registry does
 * not know returns an EMPTY badge, because a missing number is honest and a
 * confidently wrong one is not.
 */
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once __DIR__ . '/../../includes/theme.php';
require_once __DIR__ . '/../../includes/timezone.php';
require_once __DIR__ . '/_registry.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../auth/login.php');
    exit;
}
requireModuleAccess('forms');

$topics = formsHelpTopics();
if (!isset($helpTopic) || !isset($topics[$helpTopic])) {
    http_response_code(404);
    exit('Unknown help topic.');
}
$topic = $topics[$helpTopic];

/** A section's number, from the registry. '' when the registry does not know it. */
function helpNum(string $id): string
{
    global $topic;
    $n = array_search($id, array_keys($topic['sections']), true);
    return $n === false ? '' : (string)($n + 1);
}

/** Open a section: the hairline, the number badge, the heading and its lead. */
function helpSection(string $id, string $heading, string $lead = ''): void
{
    echo '<div class="help-section" id="' . htmlspecialchars($id) . '">'
       . '<div class="help-section-header">'
       . '<span class="help-section-num">' . htmlspecialchars(helpNum($id)) . '</span>'
       . '<h3>' . htmlspecialchars($heading) . '</h3>'
       . ($lead !== '' ? '<p>' . $lead . '</p>' : '')
       . '</div>';
}
function helpSectionEnd(): void { echo '</div>'; }

$current_page = 'help';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'forms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($topic['title']); ?> — <?php echo htmlspecialchars(t('forms.title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=24">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=76">
    <link rel="stylesheet" href="../../assets/css/help.css?v=3">
    <style>
        /* The only thing a help page should need to say for itself: its colour. */
        body {
            --accent:       var(--forms-accent);
            --accent-hover: var(--forms-accent-hover);
            --accent-soft:  var(--forms-accent-soft);
            --on-accent:    var(--forms-on-accent);
        }
    </style>
    <!-- Mobile layer. Linked AFTER this page's inline <style> on purpose: the
         mobile rules must win on equal specificity. -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=188">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="help-container">
        <div class="help-sidebar">
            <h3><?php echo htmlspecialchars($topic['title']); ?></h3>
            <?php $i = 0; foreach ($topic['sections'] as $id => $label): $i++; ?>
                <a href="#<?php echo htmlspecialchars($id); ?>"
                   class="help-nav-link<?php echo $i === 1 ? ' active' : ''; ?>"
                   data-section="<?php echo htmlspecialchars($id); ?>">
                    <span class="help-nav-num"><?php echo $i; ?></span>
                    <?php echo htmlspecialchars($label); ?>
                </a>
            <?php endforeach; ?>

            <!-- Back to the guide. A topic page reached from a card needs a way
                 home that is not the browser button. -->
            <a href="../help.php" class="help-nav-link" style="margin-top:14px;opacity:.75">
                &lsaquo; <?php echo htmlspecialchars(t('forms.help.back_to_guide')); ?>
            </a>
        </div>

        <div class="help-main" id="helpMain">
            <div class="help-hero">
                <h1><?php echo htmlspecialchars($topic['title']); ?></h1>
                <p><?php echo htmlspecialchars($topic['sub']); ?></p>
            </div>

            <?php /* ⚠️ .help-content is NOT optional chrome. help.css scopes its
                     table rules to `.help-content table`, so a page that skips
                     this wrapper renders every table with no borders and no cell
                     padding — which is exactly what the first version of these
                     pages did. The markup was right and the styling was absent,
                     and only a screenshot showed it. */ ?>
            <div class="help-content">
