<?php
/**
 * Manager access, opened from Assets -> Users (discussion #62). The page itself is
 * includes/manager_access_page.php, shared with the other module; this file only
 * gives it this module's header and way back.
 *
 * ?user_id=N              the manager
 * ?user_id=N&from=system  opened from System -> Managers, so Back goes there
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
require_once '../includes/manager_access_page.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('assets');

$current_page = 'users';
$translationNamespaces = ['common', 'asset-management'];
$managerId = (int)($_GET['user_id'] ?? 0);
$backUrl = (($_GET['from'] ?? '') === 'system')
    ? '../system/managers/'
    : 'users.php' . ($managerId > 0 ? '?user_id=' . $managerId : '');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('tickets.manager_access.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=24">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=76">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=3"></script>
    <script src="../assets/js/toast.js"></script>
    <!-- Mobile layer. The shared page prints its own <style> inside <body>, so LAYER 43d wins on specificity, not order (§24). -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=172">
</head>
<body data-mobile-page="manager-access">
<?php include 'includes/header.php'; ?>
<?php managerAccessRender($managerId, $backUrl); ?>
<script src="../assets/js/mobile.js?v=71"></script>
</body>
</html>
