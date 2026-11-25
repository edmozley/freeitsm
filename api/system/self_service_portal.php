<?php
/**
 * API: how the self-service portal looks and what it lets people do.
 *
 * GET   the current settings
 * POST  save them
 *
 * 🔑 WHY THIS IS NOT PART OF api/system/branding.php.
 *
 * Branding owns the ONE logo that the app, the login screen and the emails all
 * share. These settings are the portal's own: a separate logo for customers, a
 * header colour, and two switches that change what the portal lets somebody do.
 * Folding them into branding would put "may a customer close their own ticket?"
 * on a screen about logos.
 *
 * 🔴 Gated on System administrators like every other System endpoint. Two of
 * these decide what CUSTOMERS may do, and one of them closes tickets.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php';
require_once '../../includes/functions.php';
require_once '../../includes/self_service_settings.php';
require_once '../../includes/uploads.php';         // the ONE home for file writes

header('Content-Type: application/json');

try {
    $conn = connectToDatabase();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['success' => true, 'settings' => selfServicePortalSettings($conn)]);
        exit;
    }

    /* A file cannot ride in a JSON body, so the logo arrives as multipart and
       the text settings arrive as JSON. Which one this is decides how the body
       is read; everything after this point works on the same $in array. */
    $isMultipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');

    if ($isMultipart) {
        $in = ['__multipart' => true];

        $uploadDir = __DIR__ . '/../../system/uploads/branding/portal';
        $hasFile   = isset($_FILES['logo']) && is_array($_FILES['logo'])
                     && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE;
        $remove    = ($_POST['remove_logo'] ?? '') === '1';

        // Its OWN directory, not branding's. They are two different logos and
        // the tear-down below deletes everything in the folder - pointed at the
        // shared one, replacing the portal logo would silently remove the main
        // one too.
        if ($hasFile || $remove) {
            uploadPrepareWebServableDir($uploadDir);

            // Clear the previous file before writing the next, so switching
            // from PNG to JPG does not leave the old one on disk for ever.
            $prev = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'self_service_logo_path'");
            $prev->execute();
            $prevPath = (string)($prev->fetchColumn() ?: '');
            if ($prevPath !== '' && str_starts_with($prevPath, 'system/uploads/branding/portal/')) {
                @unlink(__DIR__ . '/../../' . $prevPath);
            }
        }

        if ($hasFile) {
            // 2MB, PNG/JPG only - UPLOAD_TYPES_IMAGE excludes SVG on purpose:
            // an SVG is XML that can carry <script>, and a logo is served to
            // every visitor of the portal.
            // Caught HERE rather than by the handler at the bottom: the uploads
            // helper throws messages written for the person doing the uploading
            // ("That file type is not allowed. Accepted: png, jpg..."), and the
            // generic "Could not save the settings" throws that away - leaving
            // somebody to guess why a perfectly good-looking file was refused.
            try {
                $stored = uploadStoreFile($_FILES['logo'], $uploadDir, UPLOAD_TYPES_IMAGE, 2 * 1024 * 1024);
            } catch (Throwable $upEx) {
                echo json_encode(['success' => false, 'error' => $upEx->getMessage()]);
                exit;
            }
            $in['logo_path'] = 'system/uploads/branding/portal/' . $stored['stored_name'];
        } elseif ($remove) {
            $in['logo_path'] = '';   // empty = fall back to the main logo
        }
    } else {
        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in)) {
            echo json_encode(['success' => false, 'error' => 'Invalid request body']);
            exit;
        }
    }

    $clean = [];

    // ── appearance ──────────────────────────────────────────────────────
    if (array_key_exists('logo_path', $in)) {
        // Empty means "use the main logo", which is the default and is how an
        // admin turns a portal-specific logo back off.
        $clean['self_service_logo_path'] = trim((string)$in['logo_path']);
    }
    if (array_key_exists('logo_position', $in)) {
        $v = trim((string)$in['logo_position']);
        if (!in_array($v, SELF_SERVICE_LOGO_POSITIONS, true)) {
            echo json_encode(['success' => false, 'error' => 'Unknown logo position.']);
            exit;
        }
        $clean['self_service_logo_position'] = $v;
    }
    if (array_key_exists('header_colour', $in)) {
        $v = trim((string)$in['header_colour']);
        // Empty = follow the theme. Otherwise a hex colour and nothing else:
        // this value is written into a style attribute.
        if ($v !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
            echo json_encode(['success' => false, 'error' => 'Header colour must be a 6-digit hex value like #336699']);
            exit;
        }
        $clean['self_service_header_colour'] = $v;
    }
    if (array_key_exists('table_header_colour', $in)) {
        $v = trim((string)$in['table_header_colour']);
        if ($v !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
            echo json_encode(['success' => false, 'error' => 'Table header colour must be a 6-digit hex value like #336699']);
            exit;
        }
        $clean['self_service_table_header_colour'] = $v;
    }
    if (array_key_exists('background_pattern', $in)) {
        $v = trim((string)$in['background_pattern']);
        // A fixed list, never a free string: this picks a CSS class.
        if ($v !== '' && !in_array($v, SELF_SERVICE_PATTERNS, true)) {
            echo json_encode(['success' => false, 'error' => 'Unknown background pattern']);
            exit;
        }
        $clean['self_service_background_pattern'] = $v;
    }

    // ── what the portal lets people do ──────────────────────────────────
    foreach (['allow_self_close' => 'self_service_allow_self_close',
              'show_my_assets'   => 'self_service_show_my_assets'] as $field => $key) {
        if (array_key_exists($field, $in)) {
            $clean[$key] = !empty($in[$field]) ? '1' : '0';
        }
    }

    // GH #414: the organisation chart - a fixed choice, and only real People group ids.
    if (array_key_exists('org_chart', $in)) {
        if (!in_array($in['org_chart'], SELF_SERVICE_ORG_CHART_MODES, true)) {
            echo json_encode(['success' => false, 'error' => 'Unknown organisation chart choice']);
            exit;
        }
        $clean['self_service_org_chart'] = $in['org_chart'];
    }
    if (array_key_exists('org_chart_groups', $in)) {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$in['org_chart_groups']))));
        if ($ids) {
            $st = $conn->prepare("SELECT id FROM directory_groups WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
            $st->execute($ids);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            sort($ids);
        }
        $clean['self_service_org_chart_groups'] = implode(',', $ids);
    }

    // 5.11.0: Starters & leavers cases - the same shape, without "everyone".
    if (array_key_exists('jml_cases', $in)) {
        if (!in_array($in['jml_cases'], SELF_SERVICE_JML_MODES, true)) {
            echo json_encode(['success' => false, 'error' => 'Unknown Starters & leavers choice']);
            exit;
        }
        $clean['self_service_jml_cases'] = $in['jml_cases'];
    }
    if (array_key_exists('jml_cases_groups', $in)) {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)$in['jml_cases_groups']))));
        if ($ids) {
            $st = $conn->prepare("SELECT id FROM directory_groups WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
            $st->execute($ids);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            sort($ids);
        }
        $clean['self_service_jml_cases_groups'] = implode(',', $ids);
    }

    if (!$clean) {
        echo json_encode(['success' => false, 'error' => 'Nothing to save']);
        exit;
    }

    $stmt = $conn->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, updated_datetime)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                 updated_datetime = VALUES(updated_datetime)"
    );
    foreach ($clean as $k => $v) {
        $stmt->execute([$k, $v]);
    }

    // Read back from the database rather than echoing what was sent: the page
    // then shows what is stored, not what it hoped was stored.
    echo json_encode(['success' => true, 'settings' => selfServicePortalSettings($conn)]);

} catch (Throwable $e) {
    error_log('self_service_portal.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not save the settings']);
}
