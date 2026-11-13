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
