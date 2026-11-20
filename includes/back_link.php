<?php
/**
 * The "Back to ..." link (3.5.0, proposed in PR #174): System -> Branding can
 * put a link back to your own site - an intranet, a community website the portal
 * is reached from - at the top of the portal, the analyst screens, or both.
 *
 * Three system_settings keys, all owned by System -> Branding:
 *   back_link_label   the words, e.g. "Back to the intranet" (max 60)
 *   back_link_url     http(s) only - checked on save AND here, so a bad stored
 *                     value never becomes a javascript: link in every header
 *   back_link_where   portal | analyst | both
 * Nothing shows until both the label and the address are set.
 */

const BACK_LINK_PLACES = ['portal', 'analyst', 'both'];

/**
 * True when $url is a plain http(s) address - the only kind ever rendered.
 *
 * @param string $url  the stored or posted address to check
 */
function backLinkUrlValid(string $url): bool
{
    return (bool)preg_match('#^https?://[^\s<>"]+$#i', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/** The three settings, raw - for System -> Branding. */
function backLinkSettings(PDO $conn): array
{
    $out = ['label' => '', 'url' => '', 'where' => 'portal'];
    try {
        $rows = $conn->query("SELECT setting_key, setting_value FROM system_settings
                               WHERE setting_key IN ('back_link_label', 'back_link_url', 'back_link_where')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $out['label'] = (string)($rows['back_link_label'] ?? '');
        $out['url']   = (string)($rows['back_link_url'] ?? '');
        $out['where'] = in_array($rows['back_link_where'] ?? '', BACK_LINK_PLACES, true) ? $rows['back_link_where'] : 'portal';
    } catch (Throwable) { /* nothing to show */ }
    return $out;
}

/**
 * The link's HTML for a header ('portal' or 'analyst'), or '' when it is not set
 * up, not meant for this side, or its address is not a plain http(s) one.
 *
 * @param string $side  which header is asking - 'portal' or 'analyst' (also used in the link's class name)
 */
function backLinkHtml(PDO $conn, string $side): string
{
    static $cache = null;
    $s = $cache ?? ($cache = backLinkSettings($conn));
    if ($s['label'] === '' || $s['url'] === '' || !backLinkUrlValid($s['url'])) return '';
    if ($s['where'] !== 'both' && $s['where'] !== $side) return '';
    // The analyst navbars are already full, so there it is just the arrow below
    // 1700px wide, with the words in the tooltip.
    return '<a class="back-link back-link-' . $side . '" href="' . htmlspecialchars($s['url']) . '" title="' . htmlspecialchars($s['label'] . ' - ' . $s['url']) . '" aria-label="' . htmlspecialchars($s['label']) . '">'
         . '<span aria-hidden="true">&larr;</span> <span class="back-link-label">' . htmlspecialchars($s['label']) . '</span></a>'
         . '<style>.back-link{display:inline-flex;align-items:center;gap:4px;margin-right:12px;padding:4px 10px;border-radius:14px;'
         . 'font-size:var(--font-size-md, 13px);text-decoration:none;color:inherit;opacity:.85;border:1px solid currentColor;border-color:rgba(127,127,127,.45);white-space:nowrap}'
         . '.back-link:hover{opacity:1}@media (max-width:1700px){.back-link-analyst .back-link-label{display:none}}@media (max-width:700px){.back-link .back-link-label{display:none}}</style>';
}
