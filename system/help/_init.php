<?php
/**
 * Shared boot for System help topic pages. Include at the very TOP of a topic
 * page (top-level scope, not inside a function) so config/i18n globals and the
 * waffle-menu chain resolve correctly:
 *
 *   <?php require __DIR__ . '/_init.php';
 *         $helpSlug = 'security';           // the entry in _registry.php
 *         require __DIR__ . '/_top.php'; ?>
 *       <div class="help-section" id="passwords">
 *           <div class="help-section-header"><?php echo helpSectionNum('passwords'); ?>
 *               <div>
 *                   <h3>Passwords</h3>
 *                   <p>The section's standfirst.</p>
 *               </div>
 *           </div>
 *           <p>Body copy, indented automatically — see the heading model.</p>
 *       </div>
 *   <?php require __DIR__ . '/_bottom.php'; ?>
 *
 * The hero, standfirst and sidebar nav all come from the page's registry entry,
 * so the landing page's search index and the page's own sections cannot drift
 * apart. A page may still set $helpHero / $helpSub / $helpNav explicitly to
 * override the registry.
 *
 * 🎨 These pages use the SHARED house style in assets/css/help.css — the same
 * `help-*` classes as the 26 module guides, not a private copy. They were
 * built six weeks before that stylesheet existed and were missed by the sweep
 * that moved everything else onto it, so until 1.8.0 they carried their own
 * 118-line block under a `syshelp-` prefix. Do not reintroduce one: layout CSS
 * belongs in help.css so every guide gets it. See the wiki page Help Page
 * House Style before adding anything here.
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
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit;
}

$path_prefix  = '../../';
$current_page = 'help';

/**
 * The number badge for a section, derived from the page's registry entry.
 *
 * 🔑 The number is NOT written into the page and NOT patched in by JavaScript.
 * Both of those were tried elsewhere and both drift: a hardcoded number is
 * wrong the moment a section is inserted above it, and a script that counts
 * `.help-section` nodes numbers whatever is in the DOM rather than what the
 * sidebar lists — so a section present on the page but absent from the
 * registry silently shifts every number after it. Looking the id up in the
 * same array that builds the sidebar means the two cannot disagree.
 *
 * An id with no registry entry returns nothing rather than a wrong number. A
 * missing badge is a visible, honest signal that the entry needs adding — see
 * Help Page House Style §5.
 */
function helpSectionNum(string $id): string
{
    global $helpNav;
    foreach (($helpNav ?? []) as $i => $s) {
        if (($s['id'] ?? '') === $id) {
            return '<span class="help-section-num">' . ($i + 1) . '</span>';
        }
    }
    return '';
}
