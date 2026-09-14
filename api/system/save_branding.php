<?php
/**
 * API: Save organisation-wide branding settings (text slots + optional logo upload).
 *
 * Accepts multipart/form-data so the logo file can ride along in the same
 * request as the text slots. Logo file is optional — pass `remove_logo=1` to
 * explicitly clear an existing logo, otherwise omit the file to leave the
 * existing logo unchanged.
 *
 * Text slots are limited to 200 chars each — these are toolbar-strip text,
 * not paragraphs. Tokens (`{{title}}` etc.) are passed through verbatim and
 * resolved at render time client-side.
 *
 * POST fields:
 *   header_left, header_center, header_right
 *   footer_left, footer_center, footer_right
 *   remove_logo (optional, '1' to clear the stored logo)
 *   logo (optional file upload)
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';
require_once '../../includes/uploads.php';         // the ONE home for file writes
require_once '../../includes/branding.php';        // the login designer's field table
require_once '../../includes/landing.php';         // install-wide landing default (#63)

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $textKeys = [
        'header_left', 'header_center', 'header_right',
        'footer_left', 'footer_center', 'footer_right',
    ];
    $maxLen = 200;
    $values = [];
    foreach ($textKeys as $k) {
        $v = (string)($_POST[$k] ?? '');
        if (mb_strlen($v) > $maxLen) {
            throw new Exception("'$k' is too long (max $maxLen characters)");
        }
        $values[$k] = $v;
    }

    $conn = connectToDatabase();

    $upsert = function (PDO $conn, string $key, ?string $value): void {
        $stmt = $conn->prepare(
            "INSERT INTO system_settings (setting_key, setting_value)
                  VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute([':k' => $key, ':v' => $value]);
    };

    // Landing page (#63). Only sent when the form includes it, so an older
    // client posting just the text slots leaves the choice alone.
    //
    // ⚠️ Stored as a KEY ('analyst' | 'portal'), never a path. This drives a
    // redirect on "/", the most-visited URL in the product — accepting a path
    // here would put an open redirect on the front door. Anything unrecognised
    // is rejected outright rather than silently falling back, so a typo in an
    // integration surfaces instead of quietly changing where users land.
    //
    // ⚠️ Validated BEFORE anything is written. This endpoint has no transaction,
    // so a throw partway through leaves whatever it had already saved — which
    // meant a request rejected for a bad landing_page still blanked the six
    // header/footer slots on its way out.
    $landing = null;
    if (array_key_exists('landing_page', $_POST)) {
        $landing = (string)$_POST['landing_page'];
        if (!landingIsValid($landing)) {
            throw new Exception("'landing_page' must be one of: " . implode(', ', array_keys(landingTargets())));
        }
    }

    foreach ($values as $k => $v) {
        $upsert($conn, 'branding_' . $k, $v);
    }
    if ($landing !== null) {
        $upsert($conn, LANDING_SETTING_KEY, $landing);
    }

    // Logo handling — three paths: upload new / explicitly remove / leave alone
    $removeLogo = ($_POST['remove_logo'] ?? '') === '1';
    $hasFile = isset($_FILES['logo']) && is_array($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE;

    if ($hasFile) {
        // ⚠️ SVG IS NO LONGER ACCEPTED, AND THIS NO LONGER MOVES THE FILE ITSELF.
        //
        // Two problems, one cause: this endpoint had its own upload code and its own
        // type list, so it never inherited the rule the rest of the app settled on.
        //
        //  1. It accepted SVG. An SVG is XML that can carry <script>, and the logo is
        //     served from our own origin. Inside the <img> tags that render it that
        //     script stays dormant — but navigating straight to the file makes it a
        //     top-level document on our origin, and it runs. The path was guessable
        //     (`logo.svg`), so the link needed no discovery. UPLOAD_TYPES_IMAGE has
        //     deliberately excluded SVG since the F-round for exactly this reason;
        //     branding was simply not using it. Reported by Erlend Volden.
        //
        //  2. It called move_uploaded_file() directly, under a name it chose from the
        //     uploaded extension — the pattern includes/uploads.php exists to be the
        //     single home for.
        //
        // PNG and JPG remain, and lose nothing a logo needs. Vector sharpness is a
        // real loss and it is a deliberate trade: one exception to the app-wide type
        // rule is how the rule stops meaning anything.
        //
        // uploadPrepareWebServableDir() rather than the deny-all: the browser has to
        // be able to fetch a logo. It blocks execution and adds a sandbox CSP, which
        // also neutralises any logo.svg already sitting on disk from before today.
        $uploadDir = __DIR__ . '/../../system/uploads/branding';
        uploadPrepareWebServableDir($uploadDir);

        // Tear down any previous logo before saving the new one. Both the legacy
        // fixed names (logo.png / logo.svg / …) and whatever random name the
        // current pointer holds, so switching format never leaves a stale file.
        $prev = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'branding_logo_path'");
        $prev->execute();
        $prevPath = (string)($prev->fetchColumn() ?: '');
        if ($prevPath !== '' && strpos($prevPath, 'system/uploads/branding/') === 0) {
            @unlink(__DIR__ . '/../../' . $prevPath);
        }
        foreach (glob($uploadDir . '/logo.*') as $old) {
            @unlink($old);
        }

        $stored  = uploadStoreFile($_FILES['logo'], $uploadDir, UPLOAD_TYPES_IMAGE, 2 * 1024 * 1024);
        $relPath = 'system/uploads/branding/' . $stored['stored_name'];
        $upsert($conn, 'branding_logo_path', $relPath);
    } elseif ($removeLogo) {
        // Explicit remove — delete file(s) and clear the DB pointer
        $uploadDir = __DIR__ . '/../../system/uploads/branding';
        if (is_dir($uploadDir)) {
            foreach (glob($uploadDir . '/logo.*') as $old) {
                @unlink($old);
            }
        }
        $upsert($conn, 'branding_logo_path', null);
    }

    /* ---------------------------------------------------------------------
       The login screen designer.

       🔴 EVERY value goes through brandingLoginValidate() against the SAME
       field table the login page renders from. Not a second copy of the rules
       — the same function. A colour that is not #rrggbb, a position that is
       not one of the listed words and a number outside its range are all
       replaced by the default rather than stored.

       That is belt and braces, because the page validates again when it
       renders. Both, deliberately: this endpoint stops bad values being
       stored, and the renderer stops bad values being shown if they ever
       arrive some other way. The login screen is not the place to rely on
       one of them.
       --------------------------------------------------------------------- */
    // All three screens. The POST names them by scope (login_… / portal_… /
    // home_…), and each is validated against ITS OWN field table — the landing
    // page has no form position, so a request that tries to set one is ignored
    // rather than stored.
    foreach (array_keys(brandingScopes()) as $scope) {
        $scopeFields = brandingLoginFields($scope);
        foreach ($scopeFields as $field => $spec) {
            if ($field === 'bg_image_path') continue;   // owned by the upload branch
            $postKey = $scope . '_' . $field;
            if (!array_key_exists($postKey, $_POST)) continue;
            $upsert($conn, brandingLoginKey($field, $scope), (string)brandingLoginValidate($field, $_POST[$postKey], $spec));
        }
    }

    // The background image, if chosen, follows the same upload pipeline as the
    // logo — refusing SVG because SVG is XML that can carry a script.
    foreach (array_keys(brandingScopes()) as $scope) {
        $bgInputKey = $scope . '_bg';
        if (isset($_FILES[$bgInputKey]) && $_FILES[$bgInputKey]['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../../system/uploads/branding';
            uploadPrepareWebServableDir($uploadDir);

            $settingKey = brandingLoginKey('bg_image_path', $scope);
            $prev = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
            $prev->execute([$settingKey]);
            $prevPath = (string)($prev->fetchColumn() ?: '');
            if ($prevPath !== '' && brandingPathIsSafe($prevPath)) {
                @unlink(__DIR__ . '/../../' . $prevPath);
            }

            $stored = uploadStoreFile($_FILES[$bgInputKey], $uploadDir, UPLOAD_TYPES_IMAGE, 4 * 1024 * 1024);
            $upsert($conn, $settingKey, BRANDING_UPLOAD_DIR . $stored['stored_name']);
        }
    }
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
