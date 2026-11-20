<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Font-size tokens (discussion #363) - which files they apply to, and how to find a
 * hard-coded size. Shared by scripts/tokenise_font_sizes.php (the converter) and
 * tests/font-size-guard.php (the guard), so the two can never disagree about scope.
 *
 * The scale lives in assets/css/theme.css: --font-size-xs .. --font-size-4xl.
 * The approved pattern keeps the size as the var() fallback:
 *
 *     font-size: var(--font-size-md, 13px);
 *
 * so a page that does not load theme.css (setup, an embedded widget, a print window,
 * TinyMCE's iframe) still shows exactly what it showed before.
 *
 * @wiki UI-Style-Guide
 */

/** px => token, the eight steps of the scale (theme.css). */
const FONT_SIZE_TOKENS = [
    11 => '--font-size-xs',
    12 => '--font-size-sm',
    13 => '--font-size-md',
    14 => '--font-size-lg',
    16 => '--font-size-xl',
    18 => '--font-size-2xl',
    20 => '--font-size-3xl',
    24 => '--font-size-4xl',
];

/**
 * Files the tokens apply to: everything a BROWSER renders.
 *
 * ⚠️ TRAP: never tokenise HTML that leaves the browser. Email clients (Outlook above
 * all) do not understand var() and drop the whole declaration - the fallback does not
 * help there - so an email would fall back to the client's default size. Every file
 * that builds an email body, or HTML stored and later emailed (ticket notes), is
 * listed below and keeps literal sizes.
 */
function fontSizeScopeFiles(string $root): array
{
    $skipDir = '#^(vendor|local|docs|tests|lang|api|cron|scripts|data|browser-extension|uploads|logs|recordings|samples|releases|deploy|docker|node_modules|\.git|\.github)/'
             . '|^assets/js/tinymce/|^assets/css/vendor/|^includes/services/#';
    $skipFile = [
        'assets/css/theme.css',                 // where the tokens are defined
        // Email bodies (or HTML that is emailed / stored as a note):
        'includes/attachment_limits.php', 'includes/uploads.php', 'includes/managers.php',
        'includes/task_contractors.php', 'includes/ticket_merge.php', 'includes/notifications_router.php',
        'includes/self_service_email.php', 'includes/sla_notifications.php', 'includes/lms_reminders.php',
        'includes/template_email.php', 'includes/contract_report.php', 'includes/domains/alerts.php',
    ];
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if (!preg_match('/\.(css|php|js|html)$/', $rel) || preg_match('/\.min\.(css|js)$/', $rel)) continue;
        if (preg_match($skipDir, $rel) || in_array($rel, $skipFile, true)) continue;
        if (preg_match('#(^|/)[^/]*(email|mail_|notification)[^/]*\.php$#', $rel) && !str_contains($rel, '/settings/')) continue;
        $out[] = $rel;
    }
    sort($out);
    return $out;
}

/**
 * Lines a size on them must stay literal, even inside the scope:
 *  - TinyMCE content_style: it styles the editor iframe, which has no theme.css;
 *  - inline email-ish HTML (font-family "Segoe UI, Arial" in a style attribute);
 *  - 16px set to stop iOS zooming into a field (GH #1388) - that must never move
 *    with a token, or the zoom comes back.
 */
function fontSizeLineIsExempt(string $line, string $rel, int $px): bool
{
    if (stripos($line, 'content_style') !== false) return true;
    if (preg_match('/style\s*=\s*\\\\?["\'][^"\']*font-family:\s*[\'"]?Segoe UI[\'"]?\s*,\s*Arial/i', $line)) return true;
    if ($px === 16 && ($rel === 'assets/css/mobile.css' || preg_match('/zoom|ios|coarse|focus-zoom/i', $line))) return true;
    return false;
}

/** A literal px font-size not inside var() - the thing the guard counts. */
const FONT_SIZE_LITERAL_RE = '/font-size\s*:\s*(\d+(?:\.\d+)?)px/i';

/** Count literal px font sizes in a file's text (exempt lines not counted). */
function fontSizeLiteralCount(string $text, string $rel): int
{
    $n = 0;
    foreach (preg_split('/\R/', $text) as $line) {
        if (!preg_match_all(FONT_SIZE_LITERAL_RE, $line, $m)) continue;
        foreach ($m[1] as $px) {
            if (fontSizeLineIsExempt($line, $rel, (int)$px)) continue;
            $n++;
        }
    }
    return $n;
}
