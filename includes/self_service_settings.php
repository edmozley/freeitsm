<?php
/**
 * Self-service portal settings — read from one place, by both sides.
 *
 * 🔑 The settings screen and the portal itself both need these, and a second
 * copy of the defaults is how the two drift: the screen shows a switch as off
 * while the portal behaves as though it were on. One function, both callers.
 *
 * Every value degrades to the behaviour the portal had before these settings
 * existed, so an install that has never opened the screen is unchanged.
 */

/**
 * The background patterns the portal offers.
 *
 * A fixed list because the stored value picks a CSS class. Never a free string:
 * this reaches a class attribute, and "none" is the absence of a value rather
 * than an entry, so an install that has never chosen one gets a plain page.
 */
// 'waves' was removed after review: it drew a chain of overlapping ovals
// rather than texture, and no amount of lightening made it calm. An install
// that had already chosen it degrades to no pattern, because the getter below
// blanks any value not on this list.
const SELF_SERVICE_PATTERNS = ['dots', 'grid', 'diagonal', 'flow', 'mesh'];
/** Who sees the portal's organisation chart (GH #414): nobody, everybody, or chosen People groups. */
const SELF_SERVICE_ORG_CHART_MODES = ['off', 'all', 'groups'];
/** Who sees Starters & leavers cases on the portal (5.11.0): nobody, or chosen People groups - never everyone. */
const SELF_SERVICE_JML_MODES = ['off', 'groups'];

/** Where the portal logo sits. 'header' is the nav bar, and the default. */
const SELF_SERVICE_LOGO_POSITIONS = ['header', 'page'];

/**
 * Every self-service portal setting, with its default.
 *
 * @param bool $fresh  read the table again rather than this request's copy (for tests)
 *
 * @return array{
 *   logo_path:string, header_colour:string, table_header_colour:string,
 *   background_pattern:string, allow_self_close:bool, show_my_assets:bool,
 *   org_chart:string, org_chart_groups:int[], jml_cases:string, jml_cases_groups:int[]
 * }
 */
function selfServicePortalSettings(PDO $conn, bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) return $cache;   // $fresh: a test that changes a setting mid-run

    $defaults = [
        'self_service_logo_path'           => '',
        'self_service_logo_position'       => 'header',
        'self_service_header_colour'       => '',   // empty = follow the theme
        'self_service_table_header_colour' => '',
        'self_service_background_pattern'  => '',
        'self_service_allow_self_close'    => '0',  // off: closing a ticket is a real action
        'self_service_show_my_assets'      => '0',
        // GH #414: the organisation chart - 'off' | 'all' | 'groups' (only members of
        // org_chart_groups, People group ids). Off: an MSP's customers must not see each
        // other's staff lists by surprise. See includes/portal_org_chart.php.
        'self_service_org_chart'           => 'off',
        'self_service_org_chart_groups'    => '',
        // 5.11.0: Starters & leavers cases - 'off' | 'groups' (only members of
        // jml_cases_groups). No 'everyone': a leaver's case can be a dismissal.
        // See includes/portal_starters.php.
        'self_service_jml_cases'           => 'off',
        'self_service_jml_cases_groups'    => '',
    ];

    $rows = [];
    try {
        $in = implode(',', array_fill(0, count($defaults), '?'));
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($in)");
        $stmt->execute(array_keys($defaults));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rows[$r['setting_key']] = (string)$r['setting_value'];
        }
    } catch (Throwable $e) {
        // No system_settings table yet, or the database is unreachable. The
        // portal still has to render, so every setting falls back to its
        // pre-existing behaviour rather than the page failing.
        error_log('self_service_settings: ' . $e->getMessage());
    }

    $get = fn(string $k): string => $rows[$k] ?? $defaults[$k];

    $pattern = $get('self_service_background_pattern');
    if ($pattern !== '' && !in_array($pattern, SELF_SERVICE_PATTERNS, true)) {
        $pattern = '';   // a value from a newer version, or hand-edited
    }

    $colour = function (string $k) use ($get): string {
        $v = $get($k);
        // Re-validated on the way OUT as well as in. The API checks it, but a
        // value can also arrive by hand-editing the table, and this one is
        // written into a style attribute.
        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? $v : '';
    };

    return $cache = [
        'logo_path'           => $get('self_service_logo_path'),
        // Anything unrecognised falls back to the nav bar rather than
        // rendering nowhere - a logo that vanishes reads as a broken upload.
        'logo_position'       => in_array($get('self_service_logo_position'), SELF_SERVICE_LOGO_POSITIONS, true)
                                 ? $get('self_service_logo_position') : 'header',
        'header_colour'       => $colour('self_service_header_colour'),
        'table_header_colour' => $colour('self_service_table_header_colour'),
        'background_pattern'  => $pattern,
        'allow_self_close'    => $get('self_service_allow_self_close') === '1',
        'show_my_assets'      => $get('self_service_show_my_assets') === '1',
        'org_chart'           => in_array($get('self_service_org_chart'), SELF_SERVICE_ORG_CHART_MODES, true)
                                 ? $get('self_service_org_chart') : 'off',
        'org_chart_groups'    => array_values(array_filter(array_map('intval', explode(',', $get('self_service_org_chart_groups'))))),
        'jml_cases'           => in_array($get('self_service_jml_cases'), SELF_SERVICE_JML_MODES, true)
                                 ? $get('self_service_jml_cases') : 'off',
        'jml_cases_groups'    => array_values(array_filter(array_map('intval', explode(',', $get('self_service_jml_cases_groups'))))),
    ];
}

/**
 * The portal logo as a URL the browser can actually fetch, or '' when there is
 * none (the caller then falls back to the main branding logo).
 *
 * 🔴 The setting stores a path relative to the APP ROOT. Writing it straight
 * into src="" works only from a page at the root; from /self-service/ it
 * resolves one directory too deep and 404s. Everything that renders this logo
 * must come through here.
 *
 * Mirrors brandingLogoUrl(), including the existence check: a pointer left
 * behind by a deleted file would render a broken image in the portal header.
 */
function selfServicePortalLogoUrl(PDO $conn): string
{
    $s = selfServicePortalSettings($conn);
    $rel = $s['logo_path'] ?? '';
    if ($rel === '') {
        return '';
    }

    // Only ever a file we put in the portal's own upload directory.
    if (!str_starts_with($rel, 'system/uploads/branding/portal/')
        || str_contains($rel, '..')
        || str_contains($rel, "\0")
        || !preg_match('#^[A-Za-z0-9._/-]+$#', $rel)) {
        return '';
    }

    if (!file_exists(dirname(__DIR__) . '/' . $rel)) {
        return '';
    }

    return (defined('BASE_URL') ? BASE_URL : '/') . $rel;
}
