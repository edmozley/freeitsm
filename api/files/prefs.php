<?php
/**
 * POST {desktop_colour?, navbar?, view?, windows?} - the person's own desktop.
 * Desktop colour is the ONLY appearance choice a person has (Ed, 2026-10-10);
 * the logo and its corner are System -> Branding's. navbar and view are module
 * behaviour, windows is remembered sizes and positions.
 */
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';

filesApiRun(function () use ($conn, $analystId) {
    $in = filesApiBody();
    if (isset($in['desktop_colour'])) {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string)$in['desktop_colour'])) filesApiFail('That is not a colour.');
        filesSavePref($conn, $analystId, 'files_desktop_colour', strtolower($in['desktop_colour']));
    }
    if (isset($in['navbar'])) {
        if (!in_array($in['navbar'], ['on', 'auto', 'off'], true)) filesApiFail('Unknown navbar setting.');
        filesSavePref($conn, $analystId, 'files_navbar', $in['navbar']);
    }
    if (isset($in['view'])) {
        if (!in_array($in['view'], ['icons', 'details'], true)) filesApiFail('Unknown view.');
        filesSavePref($conn, $analystId, 'files_view', $in['view']);
    }
    if (isset($in['windows']) && is_array($in['windows'])) {
        // Only numbers survive: {kind: {x, y, w, h, max}} - nothing a page could be tricked into rendering.
        $clean = [];
        foreach (array_slice($in['windows'], 0, 20, true) as $kind => $g) {
            if (!preg_match('/^[a-z]{1,20}$/', (string)$kind) || !is_array($g)) continue;
            $clean[$kind] = [
                'x' => (int)($g['x'] ?? 0), 'y' => (int)($g['y'] ?? 0),
                'w' => max(200, min(4000, (int)($g['w'] ?? 800))), 'h' => max(150, min(3000, (int)($g['h'] ?? 500))),
                'max' => !empty($g['max']),
            ];
        }
        filesSavePref($conn, $analystId, 'files_windows', json_encode($clean));
    }
    filesApiOk();
});
