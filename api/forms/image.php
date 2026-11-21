<?php
/**
 * Serve an image block's picture.
 * GET ?field=<form_fields.id>
 *
 * 🔴 IT TAKES A FIELD ID, NOT A PATH. The path is read out of that field's own
 * config, server-side. That removes directory traversal as a CATEGORY rather
 * than filtering for it: there is no caller-supplied string anywhere near the
 * filesystem, so `?path=1/../../config.php` has nothing to attach itself to.
 * The shape of the stored path is re-checked anyway, because a value that came
 * from our own upload endpoint is still a value from a database row.
 *
 * 🔴 WHO MAY SEE IT. Two different answers, and conflating them is how a form
 * that was never published leaks:
 *
 *   an analyst with Forms access   — any form's image
 *   a signed-in portal user        — ONLY a form that is portal-visible, active
 *                                    and the current version
 *
 * Anything else is a 404, never a 403: a refusal that distinguishes "not yours"
 * from "does not exist" tells an unauthenticated person which ids are real.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/uploads.php';
require_once '../../includes/services/forms.php';   // portalAudienceSql() - the ONE audience decision

/** Everything that is not a served image ends here, identically. */
function imageNotFound(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$fieldId = (int)($_GET['field'] ?? 0);
if ($fieldId <= 0) imageNotFound();

$isAnalyst = isset($_SESSION['analyst_id']);
$isPortal  = isset($_SESSION['ss_user_id']);
if (!$isAnalyst && !$isPortal) imageNotFound();

try {
    $conn = connectToDatabase();

    /* ⚠️ The visibility gate is in the QUERY for a portal user, not in a branch
       afterwards — the same shape get_catalogue_form.php uses, so a form that is
       not in the catalogue is indistinguishable from one that does not exist.
       A retired field still serves its image: it is retired from being ASKED,
       and an old submission being read back should still draw the form it was. */
    $sql = "SELECT ff.config, ff.field_type, ff.form_id
              FROM form_fields ff
              JOIN forms f ON f.id = ff.form_id
             WHERE ff.id = :fieldId";
    $params = [':fieldId' => $fieldId];
    if (!$isAnalyst) {
        /* 🔴 The AUDIENCE gate too (GH #145). A picture on a form restricted to
           one group must not be fetchable by a customer outside it — an image
           can carry the very thing the restriction exists for, a price list or
           a site plan. Same clause the catalogue and the form endpoint use. */
        $sql .= " AND f.is_portal_visible = 1
                  AND f.is_active = 1
                  AND NOT EXISTS (SELECT 1 FROM forms ch WHERE ch.parent_form_id = f.id)
                  AND " . FormsService::portalAudienceSql($conn, 'f');
        $params[':audUser'] = (int)$_SESSION['ss_user_id'];
    }
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) imageNotFound();

    /* An analyst still needs the module. Checked AFTER the row lookup so both
       refusals are the same 404 — requireModuleAccessJson() would emit a JSON
       403, which here would both break the <img> and distinguish "you may not"
       from "it is not there". Fails closed on any error, as that helper does. */
    if ($isAnalyst) {
        try {
            if (!analystCanAccessModule($conn, (int)$_SESSION['analyst_id'], 'forms')) imageNotFound();
        } catch (Throwable $e) {
            imageNotFound();
        }
    }

    if (($row['field_type'] ?? '') !== 'image') imageNotFound();

    $config = json_decode((string)($row['config'] ?? ''), true);
    $rel    = is_array($config) ? (string)($config['image_path'] ?? '') : '';

    /* Defence in depth. The only shape this endpoint ever writes is
       "<formId>/<32 hex>.<ext>", and the extension must be one WE named — never
       one read off the file. A row edited by hand cannot widen either. */
    if (!preg_match('~^([0-9]+)/([0-9a-f]{32})\.([a-z0-9]{1,5})$~', $rel, $m)) imageNotFound();
    if ((int)$m[1] !== (int)$row['form_id'])        imageNotFound();
    if (!isset(UPLOAD_TYPES_IMAGE[$m[3]]))          imageNotFound();

    $path = fileStorePath('form-images', $rel);
    if (!is_file($path)) imageNotFound();

    /* The type comes from OUR extension whitelist, not from the file and not
       from finfo. nosniff on top, so a browser cannot decide for itself that
       something is HTML and run it. */
    $mime = UPLOAD_TYPES_IMAGE[$m[3]][0];

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline');
    header('X-Content-Type-Options: nosniff');
    /* Private: the image may belong to a form only some people may see, so a
       shared proxy must not hold it. Still cacheable by the person's own
       browser, because a form redraws this on every keystroke that changes
       conditional visibility. */
    header('Cache-Control: private, max-age=3600');

    readfile($path);
} catch (Exception $e) {
    imageNotFound();
}
