<?php
/**
 * Branding — the organisation's logo, and the login screen designer.
 *
 * TWO JOBS, ONE FILE, because they answer the same question from different
 * angles: "what does this install look like to somebody who has not logged in?"
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  1. brandingLogoUrl()  — the logo, wherever it is shown
 * ─────────────────────────────────────────────────────────────────────────────
 * Branding has been able to store a logo for a long time, and almost nothing
 * read it: `assets/images/CompanyLogo.png` was hardcoded in NINE places and
 * exactly one page (the asset handover) looked the setting up. So an
 * administrator uploaded a logo, saw it saved, and it appeared essentially
 * nowhere (GH #87). One function now answers it for every caller.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  2. The login screen designer
 * ─────────────────────────────────────────────────────────────────────────────
 * 🔴 THE SECURITY RULE THIS FILE EXISTS TO ENFORCE:
 *
 *      THE ADMINISTRATOR SUPPLIES VALUES, NEVER SYNTAX.
 *
 * The login page is the one page an attacker can view anonymously, and the one
 * page where every user types a password. Stored XSS there is the worst kind
 * there is: it runs unauthenticated, for everybody, on the credential form. So
 * nothing an administrator types is ever treated as HTML or as CSS.
 *
 * Every setting below declares a TYPE and its permitted values, and
 * brandingLoginDesign() refuses anything else — a colour is `#rrggbb` or it is
 * the default, a position is one of a fixed list or it is the default, a number
 * is clamped, and text is plain text that the page escapes on the way out. The
 * page turns those values into CSS; the administrator never writes any.
 *
 * ⚠️ Validation happens at RENDER as well as at save. Saving is guarded, but a
 * value that reached the row by some other route — a restored backup, a direct
 * UPDATE, an injection elsewhere — still cannot reach the page. This mirrors
 * includes/landing.php, which took the same position for the same reason: "the
 * stored value is a KEY, never a path… anything unrecognised falls back to the
 * default". That is the doctrine for anything the front door reads.
 *
 * ⚠️ And the line NOT to cross: no "custom CSS" and no "custom HTML" field,
 * however often it is asked for. Every guarantee above is void the moment one
 * exists. If something cannot be expressed, the answer is another structured
 * control, not an escape hatch.
 *
 * 🔑 LOCKOUT IS A REAL HAZARD. White text on a white background, or a logo
 * scaled over the form, and nobody can sign in to undo it — including the
 * administrator who did it. `login.php?nobranding=1` always renders the stock
 * screen. It is not a secret and it is not a bypass: it changes appearance
 * only, and is documented next to the settings.
 */

require_once __DIR__ . '/functions.php';

/** The bundled logo, used when no custom one is configured. */
const BRANDING_DEFAULT_LOGO = 'assets/images/CompanyLogo.png';

/** Where an uploaded logo or background may live. Anything outside is refused. */
const BRANDING_UPLOAD_DIR = 'system/uploads/branding/';

/**
 * The organisation's logo as a web URL, with the bundled one as the fallback.
 *
 * ⚠️ Never throws and never returns empty: a page that cannot reach the database
 * still has to render. A missing logo is cosmetic; a fatal on the login page is
 * not.
 */
function brandingLogoUrl(?PDO $conn = null): string
{
    static $cached = null;
    if ($cached !== null) return $cached;

    $base = defined('BASE_URL') ? BASE_URL : '/';
    $cached = $base . BRANDING_DEFAULT_LOGO;

    try {
        $conn = $conn ?: connectToDatabase();
        $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'branding_logo_path'");
        $s->execute();
        $rel = (string)($s->fetchColumn() ?: '');
        // The stored value must be a file we put there, and must still exist —
        // a pointer to a deleted upload would render a broken image on the login
        // page, which looks like a broken install.
        if ($rel !== '' && brandingPathIsSafe($rel) && file_exists(__DIR__ . '/../' . $rel)) {
            $cached = $base . $rel;
        }
    } catch (Exception $e) {
        // Fall through to the bundled logo.
    }
    return $cached;
}

/** A stored upload pointer is only ever a file inside the branding directory. */
function brandingPathIsSafe(string $rel): bool
{
    if (strpos($rel, BRANDING_UPLOAD_DIR) !== 0) return false;
    if (strpos($rel, '..') !== false) return false;
    if (strpos($rel, "\0") !== false) return false;
    return (bool)preg_match('/^[A-Za-z0-9._\/-]+$/', $rel);
}

/**
 * THE THREE SCREENS, and what each one has to style.
 *
 * They are not identical: a landing page has no sign-in form, so asking where
 * to put one — or whether its panel should be frosted glass — is a control that
 * cannot do anything. `only` names the fields a scope leaves out.
 *
 * ⚠️ The KEY PREFIX for the analyst login stays `branding_login_` rather than
 * becoming `branding_analyst_`. Renaming it would orphan the settings of anyone
 * who has already used the designer, for tidiness.
 */
function brandingScopes(): array
{
    return [
        'login'  => ['prefix' => 'branding_login_',  'page' => 'auth/login.php'],
        'portal' => ['prefix' => 'branding_portal_', 'page' => 'self-service/login.php'],
        // No form on this one, so no form position and no panel style.
        'home'   => ['prefix' => 'branding_home_',   'page' => 'index.php',
                     'omit'   => ['form_position', 'card_style'],
                     // …and it keeps the theme's own background unless asked otherwise.
                     'defaults' => ['bg_style' => 'theme']],
    ];
}

function brandingScopeValid(string $scope): string
{
    return isset(brandingScopes()[$scope]) ? $scope : 'login';
}
/**
 * THE VALIDATION TABLE — the single source of truth for the login designer.
 *
 * 🔑 One table drives the save endpoint, the renderer AND the settings form, so
 * a control cannot be added to the screen and forgotten in validation. That is
 * the failure this shape exists to make impossible: an unvalidated field on the
 * login page is precisely the hole the rest of this file is about.
 *
 * Types:
 *   enum   one of `values`
 *   colour #rrggbb
 *   int    clamped to min..max
 *   text   plain text, trimmed, cut to `max` characters — NEVER HTML
 *   upload a path inside the branding directory that still exists
 */
function brandingLoginFields(string $scope = 'login'): array
{
    $spec     = brandingScopes()[brandingScopeValid($scope)];
    $omit     = $spec['omit'] ?? [];
    $defaults = $spec['defaults'] ?? [];
    $fields = [
        // ---- layout ----
        'form_position'   => ['type' => 'enum',   'default' => 'centre', 'values' => ['left', 'centre', 'right']],
        'card_style'      => ['type' => 'enum',   'default' => 'solid',  'values' => ['solid', 'glass', 'flat']],

        // ---- background ----
        // ⭐ `theme` means "do not set a background at all", which is the default
        // on the landing page: that page already has a theme-aware background WITH
        // a dark-mode variant, and overriding it by default would break dark mode
        // for every install as a side effect of adding a setting. Opt in, do not
        // opt out.
        'bg_style'        => ['type' => 'enum',   'default' => 'gradient', 'values' => ['theme', 'gradient', 'solid', 'image']],
        // 🔑 The defaults ARE the gradient login.php used to hardcode, so an
        // install that never opens the designer looks exactly as it always did.
        // Shipping a new look to everybody as a side effect of adding a setting
        // is the kind of change nobody asked for and everybody notices.
        'bg_from'         => ['type' => 'colour', 'default' => '#667eea'],
        'bg_to'           => ['type' => 'colour', 'default' => '#764ba2'],
        'bg_direction'    => ['type' => 'enum',   'default' => 'diagonal', 'values' => ['down', 'right', 'diagonal', 'diagonal-up', 'radial']],
        'bg_image_path'   => ['type' => 'upload', 'default' => ''],
        // How much to darken a background image so the form stays legible on top
        // of a busy photograph. 0 = untouched.
        'bg_dim'          => ['type' => 'int',    'default' => 30, 'min' => 0, 'max' => 80],

        // ---- logo ----
        // 250 for the same reason as the gradient above: it is what the page
        // already used, so nothing moves on an install that never touches this.
        //
        // 🔑 BOTH ARE MAXIMA, and the logo is drawn as large as it can be without
        // exceeding either. One control cannot serve both logo shapes: the
        // bundled logo is 1124×301, so its WIDTH is what needs holding back —
        // but a square or portrait logo sized to 250px wide is 250px TALL, and
        // swallows a 400px card. Reported by a customer whose logo is square.
        // Constraining the height is the natural way to think about that one,
        // and a single dimension cannot express it.
        //
        // ⚠️ Two fixed dimensions would DISTORT the logo, so neither is fixed —
        // the aspect ratio is the browser's to keep. The cost is that a logo
        // smaller than the setting is no longer stretched up to meet it, which
        // is the better behaviour anyway: enlarging a bitmap only blurs it.
        'logo_size'       => ['type' => 'int',    'default' => 250, 'min' => 40, 'max' => 400],
        // 0 = no limit, which is the old behaviour exactly — the height was
        // never constrained, so an install that does not touch this sees no
        // change at all.
        'logo_height'     => ['type' => 'int',    'default' => 0,   'min' => 0,  'max' => 400],
        'logo_position'   => ['type' => 'enum',   'default' => 'above', 'values' => ['above', 'top-left', 'top-centre', 'hidden']],

        // ---- words ----
        'heading'         => ['type' => 'text',   'default' => '', 'max' => 80],
        'subheading'      => ['type' => 'text',   'default' => '', 'max' => 160],
        'accent'          => ['type' => 'colour', 'default' => '#2b88d8'],

        // ---- banner strip ----
        'banner_position' => ['type' => 'enum',   'default' => 'off', 'values' => ['off', 'top', 'bottom']],
        'banner_text'     => ['type' => 'text',   'default' => '', 'max' => 160],
        'banner_bg'       => ['type' => 'colour', 'default' => '#111827'],
        'banner_fg'       => ['type' => 'colour', 'default' => '#ffffff'],

        // ---- footer strip ----
        'footer_text'     => ['type' => 'text',   'default' => '', 'max' => 200],
        'footer_fg'       => ['type' => 'colour', 'default' => '#ffffff'],
    ];
    foreach ($omit as $f) unset($fields[$f]);
    foreach ($defaults as $f => $v) if (isset($fields[$f])) $fields[$f]['default'] = $v;
    return $fields;
}

/** `form_position` → the settings key it is stored under, for this screen. */
function brandingLoginKey(string $field, string $scope = 'login'): string
{
    return brandingScopes()[brandingScopeValid($scope)]['prefix'] . $field;
}

/**
 * One value, validated against its declared type. Anything that does not fit
 * becomes the default — never an error, because this runs on the login page.
 */
function brandingLoginValidate(string $field, $raw, array $spec)
{
    $default = $spec['default'];
    if ($raw === null || $raw === '') {
        // An empty text field is a legitimate "nothing here", not a fallback.
        return in_array($spec['type'], ['text', 'upload'], true) ? $default : $default;
    }
    $raw = is_string($raw) ? trim($raw) : $raw;

    switch ($spec['type']) {
        case 'enum':
            return in_array($raw, $spec['values'], true) ? $raw : $default;

        case 'colour':
            // Strict. "Starts with #" is not validation — `#fff; background:url(…)`
            // starts with # too.
            return preg_match('/^#[0-9a-fA-F]{6}$/', $raw) ? strtolower($raw) : $default;

        case 'int':
            if (!preg_match('/^-?\d+$/', (string)$raw)) return $default;
            return max($spec['min'], min($spec['max'], (int)$raw));

        case 'text':
            // Control characters stripped so a stored newline cannot break out of
            // an attribute even if a future caller forgets to escape.
            $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$raw);
            return mb_substr($clean, 0, $spec['max']);

        case 'upload':
            return (brandingPathIsSafe((string)$raw) && file_exists(__DIR__ . '/../' . $raw)) ? $raw : $default;
    }
    return $default;
}

/**
 * The whole design, validated. Safe to call on the login page: it swallows any
 * database failure and returns the defaults.
 */
function brandingLoginDesign(?PDO $conn = null, string $scope = 'login'): array
{
    $scope  = brandingScopeValid($scope);
    $fields = brandingLoginFields($scope);
    $out = [];
    foreach ($fields as $f => $spec) $out[$f] = $spec['default'];

    try {
        $conn = $conn ?: connectToDatabase();
        $keys = array_map(fn($f) => brandingLoginKey($f, $scope), array_keys($fields));
        $place = implode(',', array_fill(0, count($keys), '?'));
        // ⚠️ Only these keys. system_settings also holds encrypted SMTP and OAuth
        // credentials; a widened query here would be a leak on the most public
        // page in the product.
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($place)");
        $stmt->execute($keys);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $prefix = brandingScopes()[$scope]['prefix'];
            $field  = substr($row['setting_key'], strlen($prefix));
            if (!isset($fields[$field])) continue;
            $out[$field] = brandingLoginValidate($field, $row['setting_value'], $fields[$field]);
        }
    } catch (Exception $e) {
        // Defaults. A login screen that cannot be styled still has to be usable.
    }
    return $out;
}

/**
 * The design as CSS custom properties.
 *
 * 🔑 String concatenation is safe HERE and only here, because every value
 * reaching this function has already been through brandingLoginValidate(): a
 * colour matched `#rrggbb`, a direction was one of five words, a number was
 * clamped. Nothing else may be interpolated into CSS.
 */
function brandingLoginCss(array $d): string
{
    $dirs = [
        'down'        => 'linear-gradient(180deg, %1$s, %2$s)',
        'right'       => 'linear-gradient(90deg, %1$s, %2$s)',
        'diagonal'    => 'linear-gradient(135deg, %1$s, %2$s)',
        'diagonal-up' => 'linear-gradient(45deg, %1$s, %2$s)',
        // ⚠️ %% — a literal percent has to be escaped in a sprintf template.
        // Written as `30% 30%` the second one was eaten ('% 3' reads as a format
        // spec) and the server produced `circle at 30%,` while the browser preview,
        // which builds the same string without sprintf, produced the full one.
        // Still valid CSS either way, which is exactly why it would have gone
        // unnoticed — and a preview that disagrees with the page is the one thing
        // this design is supposed to rule out.
        'radial'      => 'radial-gradient(circle at 30%% 30%%, %1$s, %2$s)',
    ];

    // `theme` emits no background at all, leaving the page's own — including
    // its dark-mode variant — exactly as it was.
    $css = [];
    if ($d['bg_style'] === 'theme') {
        $bg = null;
    } elseif ($d['bg_style'] === 'solid') {
        $bg = $d['bg_from'];
    } elseif ($d['bg_style'] === 'image' && $d['bg_image_path'] !== '') {
        $base = defined('BASE_URL') ? BASE_URL : '/';
        // The path is validated to the branding directory and to a conservative
        // character set, so it cannot close the url() or start a new declaration.
        $bg = "url('" . $base . $d['bg_image_path'] . "') center/cover no-repeat";
    } else {
        $bg = sprintf($dirs[$d['bg_direction']] ?? $dirs['diagonal'], $d['bg_from'], $d['bg_to']);
    }

    if ($bg !== null) $css[] = '--login-bg: ' . $bg;
    $css = array_merge($css, [
        '--login-accent: ' . $d['accent'],
        '--login-logo-size: ' . (int)$d['logo_size'] . 'px',
        // `none` rather than a very large number: the page reads this straight
        // into a max-height, and "no limit" is a value CSS already has a word
        // for. 0 is the stored form because the control is a slider.
        '--login-logo-height: ' . ((int)$d['logo_height'] > 0 ? (int)$d['logo_height'] . 'px' : 'none'),
        // ⚠️ ONLY over an image. The dim exists so a form stays legible on top
        // of a busy photograph; applied to a gradient it just darkens the two
        // colours the administrator chose, which looks like the picker is
        // broken. Caught on a screenshot: a #0f172a→#38bdf8 gradient came out
        // almost black.
        '--login-dim: ' . ($d['bg_style'] === 'image' ? ((int)$d['bg_dim'] / 100) : 0),
        '--login-banner-bg: ' . $d['banner_bg'],
        '--login-banner-fg: ' . $d['banner_fg'],
        '--login-footer-fg: ' . $d['footer_fg'],
    ]);
    return implode('; ', $css) . ';';
}

/**
 * One-click starting points.
 *
 * ⭐ Presets are the difference between a settings screen and a designer. Facing
 * eighteen empty controls is a chore; picking "Sunrise" and then nudging it is
 * enjoyable, and it is also how somebody who is not a designer ends up with
 * something that looks deliberate. Every value here goes through the same
 * validation as a typed one — a preset is a shortcut, not a trusted path.
 */
function brandingLoginPresets(): array
{
    return [
        'midnight' => ['label' => 'Midnight',  'bg_style' => 'gradient', 'bg_from' => '#0f172a', 'bg_to' => '#1e3a5f', 'bg_direction' => 'diagonal',    'accent' => '#38bdf8', 'card_style' => 'glass', 'form_position' => 'centre'],
        'sunrise'  => ['label' => 'Sunrise',   'bg_style' => 'gradient', 'bg_from' => '#f97316', 'bg_to' => '#fbbf24', 'bg_direction' => 'diagonal-up', 'accent' => '#b45309', 'card_style' => 'solid', 'form_position' => 'right'],
        'forest'   => ['label' => 'Forest',    'bg_style' => 'gradient', 'bg_from' => '#064e3b', 'bg_to' => '#10b981', 'bg_direction' => 'down',        'accent' => '#059669', 'card_style' => 'glass', 'form_position' => 'left'],
        'ocean'    => ['label' => 'Ocean',     'bg_style' => 'gradient', 'bg_from' => '#0c4a6e', 'bg_to' => '#22d3ee', 'bg_direction' => 'radial',      'accent' => '#0891b2', 'card_style' => 'glass', 'form_position' => 'centre'],
        'plum'     => ['label' => 'Plum',      'bg_style' => 'gradient', 'bg_from' => '#4c1d95', 'bg_to' => '#db2777', 'bg_direction' => 'diagonal',    'accent' => '#a21caf', 'card_style' => 'solid', 'form_position' => 'centre'],
        'mono'     => ['label' => 'Mono',      'bg_style' => 'solid',    'bg_from' => '#f3f4f6', 'bg_to' => '#f3f4f6', 'bg_direction' => 'down',        'accent' => '#111827', 'card_style' => 'flat',  'form_position' => 'centre'],
    ];
}

/**
 * Is this pair of colours legible together? WCAG relative-luminance contrast.
 *
 * Not a gate — an administrator may have a reason — but the designer says so
 * before it is saved, because the failure mode is people who cannot read the
 * login screen and cannot tell you why.
 */
function brandingContrastRatio(string $hexA, string $hexB): float
{
    $lum = function (string $hex): float {
        $c = [];
        foreach ([1, 3, 5] as $i) {
            $v = hexdec(substr($hex, $i, 2)) / 255;
            $c[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    };
    $a = $lum($hexA);
    $b = $lum($hexB);
    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}
