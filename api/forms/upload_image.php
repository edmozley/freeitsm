<?php
/**
 * API: store an image for a form's image block.
 * POST multipart: form_id, image
 *
 * 🔒 Every byte goes through uploadStoreFile(), which is the ONE place the
 * upload rules live: an extension AND mime whitelist, a random stored filename
 * drawn from our own list rather than the caller's string, and an .htaccess in
 * the destination that disables execution. None of the three is trusted alone.
 *
 * 🔴 UPLOAD_TYPES_IMAGE, not UPLOAD_TYPES_ATTACHMENT. The default whitelist is
 * the ceiling for a ticket attachment and includes documents and archives; an
 * image block can only ever be an image. Narrower is the right default and the
 * constant is shared, so SVG stays excluded — it is XML, it can carry <script>,
 * and a browser will run it if the file is ever served inline. Branding accepts
 * one because an administrator uploads the logo; nothing a form shows to a
 * customer should.
 *
 * 🔑 Writing is analyst-only: building a form is not something a portal user
 * does. READING is a different and wider question — see image.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/uploads.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('forms');
requireModuleWriteJson('forms');   // Read only access (#180) changes nothing here
require_once '../../includes/rbac.php';
requireActionJson(Cap::FORMS_DESIGN);   // designing - #180; module access is enough until Forms → Settings → Access restricts it

/* An image block's picture is at most this big. Deliberately well under the
   general attachment ceiling: this is drawn on every render of the form, for
   every person filling it in, and a 20MB photograph on a purchase requisition
   is a slow form rather than a rich one. */
const FORM_IMAGE_MAX_BYTES = 4 * 1024 * 1024;

$formId = (int)($_POST['form_id'] ?? 0);
if ($formId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing form ID']);
    exit;
}

if (!isset($_FILES['image'])) {
    echo json_encode(['success' => false, 'error' => 'No image was chosen.']);
    exit;
}

try {
    $conn = connectToDatabase();

    /* The form must exist. Checked before anything is written, so a bad id
       cannot leave a file on disk that nothing will ever reference. */
    $chk = $conn->prepare("SELECT id FROM forms WHERE id = ?");
    $chk->execute([$formId]);
    if (!$chk->fetchColumn()) {
        echo json_encode(['success' => false, 'error' => 'Form not found']);
        exit;
    }

    $stored = uploadStoreFile(
        $_FILES['image'],
        fileStorePath('form-images', (string)$formId),
        UPLOAD_TYPES_IMAGE,
        FORM_IMAGE_MAX_BYTES
    );

    /* What goes into the field's config. The path is relative to the images
       root and names OUR generated file; the name the author recognises is kept
       separately and is only ever displayed.
       ⚠️ The stored path is returned to the builder and saved as part of the
       field, NOT written to the database here — the image belongs to a field
       that may not exist until the author presses Save. An orphaned file is a
       wasted few hundred kilobytes; a field pointing at a file that was never
       stored is a broken form. */
    echo json_encode([
        'success' => true,
        'image'   => [
            'path' => $formId . '/' . $stored['stored_name'],
            'name' => $stored['original_name'],
            'size' => $stored['size'],
        ],
    ]);
} catch (Exception $e) {
    /* uploadStoreFile() throws with a message written for the person choosing
       the file ("That file type is not allowed. Accepted: png, jpg, …"), so it
       is passed through rather than replaced with something vaguer. */
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
