<?php
/**
 * Save to Files (GH #303) - copy an email attachment or a stored document into Files.
 *
 *   GET  ?targets=1      the folders you can save into (Upload or better) and the
 *                        Files -> Settings -> Sharing defaults
 *   POST {source: email_attachment|document, id, folder_id, ticket_folder?: bool,
 *         on_conflict?: ask|version|rename|overwrite|skip}
 *        -> {item_id, version_no, name, folder_id, open_url}
 *        or {conflict: true, name, choices, default} - ask, then send the choice
 *   POST multipart: source=export, file=<the export>, folder_id, on_conflict?
 *        -> the same; for something the page has just made (a diagram's PNG or PDF).
 *        Export formats only, checked by their bytes - FilesSave::saveExport().
 *
 * The source is checked as downloading it is; the folder needs Upload. The rules are in
 * includes/services/files_save.php.
 */
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';   // filesFreeName(), filesNameTaken() live here
require_once __DIR__ . '/../../includes/services/files_save.php';

filesApiRun(function () use ($conn, $analystId) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!empty($_GET['targets'])) filesApiOk(FilesSave::targets($conn, $analystId));
        filesApiFail('Unknown request.');
    }
    csrfRequireWriteMethod();
    if (isset($_FILES['file'])) {
        // Something the page made in the browser - sent with the request, not held here yet.
        $f = $_FILES['file'];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) filesApiFail('The file did not arrive.');
        try {
            $r = FilesSave::saveExport($conn, $analystId, $f['tmp_name'], (string)($_POST['name'] ?? $f['name'] ?? ''),
                                       (int)($_POST['folder_id'] ?? 0), (string)($_POST['on_conflict'] ?? 'ask'));
            if (!empty($r['item_id'])) $r['open_url'] = BASE_URL . 'files/?item=' . (int)$r['item_id'];
            filesApiOk($r);
        } catch (ServiceError $e) {
            filesApiFail($e->getMessage(), $e->kind === 'forbidden' ? 403 : ($e->kind === 'not_found' ? 404 : 200));
        }
    }
    $in = filesApiBody();
    try {
        $r = FilesSave::save($conn, $analystId, analystAllowedModulesNow(),
                             (string)($in['source'] ?? ''), (int)($in['id'] ?? 0), (int)($in['folder_id'] ?? 0),
                             !empty($in['ticket_folder']), (string)($in['on_conflict'] ?? 'ask'));
        if (!empty($r['item_id'])) $r['open_url'] = BASE_URL . 'files/?item=' . (int)$r['item_id'];
        filesApiOk($r);
    } catch (ServiceError $e) {
        filesApiFail($e->getMessage(), $e->kind === 'forbidden' ? 403 : ($e->kind === 'not_found' ? 404 : 200));
    }
});
