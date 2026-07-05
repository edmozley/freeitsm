<?php
/**
 * Shared boot for System help topic pages. Include at the very TOP of a topic
 * page (top-level scope, not inside a function) so config/i18n globals and the
 * waffle-menu chain resolve correctly:
 *
 *   <?php require __DIR__ . '/_init.php';
 *         $helpHero = '…'; $helpSub = '…'; $helpNav = [ ['id'=>…,'label'=>…], … ];
 *         require __DIR__ . '/_top.php'; ?>
 *       <div class="syshelp-section" id="…"> … </div>
 *   <?php require __DIR__ . '/_bottom.php'; ?>
 *
 * System help is English-only (consistent with the System module), so content
 * is written inline rather than via i18n keys.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once __DIR__ . '/../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$path_prefix  = '../../';
$current_page = 'help';
