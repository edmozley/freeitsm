<?php
/**
 * Shared boot for System help topic pages. Include at the very TOP of a topic
 * page (top-level scope, not inside a function) so config/i18n globals and the
 * waffle-menu chain resolve correctly:
 *
 *   <?php require __DIR__ . '/_init.php';
 *         $helpSlug = 'security';           // the entry in _registry.php
 *         require __DIR__ . '/_top.php'; ?>
 *       <div class="syshelp-section" id="…"> … </div>
 *   <?php require __DIR__ . '/_bottom.php'; ?>
 *
 * The hero, standfirst and sidebar nav all come from the page's registry entry,
 * so the landing page's search index and the page's own sections cannot drift
 * apart. A page may still set $helpHero / $helpSub / $helpNav explicitly to
 * override the registry.
 *
 * System help is English-only (consistent with the System module), so content
 * is written inline rather than via i18n keys.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once __DIR__ . '/../../includes/timezone.php';
require_once __DIR__ . '/../../includes/theme.php';
require_once __DIR__ . '/../includes/areas.php';
require_once __DIR__ . '/_registry.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$path_prefix  = '../../';
$current_page = 'help';
