<?php
/**
 * Serve a catalogue item's card picture (GH #348).
 * GET ?form=<forms.id>
 *
 * The same rules as image.php, which serves an image block's picture:
 *
 * 🔴 IT TAKES A FORM ID, NOT A PATH. The path is read from the form's own row,
 * so there is no caller-supplied string anywhere near the filesystem. The stored
 * path is re-checked anyway - its shape, an extension WE named, and that it was
 * uploaded for this form or an earlier version of it - because a value from our
 * own upload endpoint is still a value from a database row.
 *
 * 🔴 WHO MAY SEE IT:
 *   an analyst with Forms access   - any form's picture
 *   a signed-in portal user        - ONLY an item in their catalogue: portal-visible,
 *                                    active, the current version, and for them
 * Anything else is a 404, never a 403, so a refusal never says which ids are real.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/uploads.php';
require_once '../../includes/services/forms.php';   // portalAudienceSql()
require_once '../../includes/catalogue_card.php';

/** Everything that is not a served picture ends here, identically. */
function cardImageNotFound(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$formId = (int)($_GET['form'] ?? 0);
if ($formId <= 0) cardImageNotFound();

$isAnalyst = isset($_SESSION['analyst_id']);
$isPortal  = isset($_SESSION['ss_user_id']);
if (!$isAnalyst && !$isPortal) cardImageNotFound();

try {
    $conn = connectToDatabase();
    if (!catalogueCardAvailable($conn)) cardImageNotFound();

    // The visibility gate is in the QUERY for a portal user, as in image.php.
    $sql = "SELECT f.catalogue_image_path FROM forms f WHERE f.id = :formId";
    $params = [':formId' => $formId];
    if (!$isAnalyst) {
        $sql .= " AND f.is_portal_visible = 1
                  AND f.is_active = 1
                  AND NOT EXISTS (SELECT 1 FROM forms ch WHERE ch.parent_form_id = f.id)
                  AND " . FormsService::portalAudienceSql($conn, 'f');
        $params[':audUser'] = (int)$_SESSION['ss_user_id'];
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rel = $stmt->fetchColumn();
    if ($rel === false || $rel === null || $rel === '') cardImageNotFound();

    // An analyst still needs the module - after the lookup, so both refusals are the same 404.
    if ($isAnalyst) {
        try {
            if (!analystCanAccessModule($conn, (int)$_SESSION['analyst_id'], 'forms')) cardImageNotFound();
        } catch (Throwable $e) {
            cardImageNotFound();
        }
    }

    if (!preg_match(CATALOGUE_IMAGE_PATH_RE, (string)$rel, $m)) cardImageNotFound();
    if (!isset(UPLOAD_TYPES_IMAGE[$m[3]]))                         cardImageNotFound();
    if (!catalogueImageBelongs($conn, $formId, (int)$m[1]))         cardImageNotFound();

    $path = dirname(dirname(__DIR__)) . '/forms/images/' . $rel;
    if (!is_file($path)) cardImageNotFound();

    // The type from OUR whitelist, never the file; nosniff on top.
    header('Content-Type: ' . UPLOAD_TYPES_IMAGE[$m[3]][0]);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline');
    header('X-Content-Type-Options: nosniff');
    // Private: the item may be for some people only, so no shared proxy keeps it.
    header('Cache-Control: private, max-age=3600');
    readfile($path);
} catch (Throwable $e) {
    cardImageNotFound();
}
