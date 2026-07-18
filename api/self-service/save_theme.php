<?php
/**
 * API: save the signed-in portal user's colour palette.
 *
 * Analysts keep their palette in `user_preferences`, which is keyed by
 * analyst_id and therefore unusable for portal users — so a portal user's
 * choice lives on their own row (`users.theme_preference`). Theme::active()
 * reads it, with the session as a per-request cache.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $data  = json_decode(file_get_contents('php://input'), true) ?: [];
    $theme = trim((string) ($data['theme'] ?? ''));

    // Only ids the app actually registers — never store an arbitrary string that
    // would end up in a data-theme attribute.
    if (!Theme::isValid($theme)) {
        echo json_encode(['success' => false, 'error' => 'Unknown theme']);
        exit;
    }

    $conn = connectToDatabase();
    $stmt = $conn->prepare("UPDATE users SET theme_preference = ? WHERE id = ?");
    $stmt->execute([$theme, (int) $_SESSION['ss_user_id']]);

    // Keep the session cache in step, or the next page render would still show
    // the previous palette until the cache expired.
    $_SESSION['ss_theme'] = $theme;

    echo json_encode(['success' => true, 'theme' => $theme]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
