<?php
/** Same-origin native editor, scoped to an accessible linked ticket/task. */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/tenancy.php';
require_once '../includes/task_embed.php';
requireModuleAccess('tickets');
requireModuleAccess('tasks');
$taskId = filter_var($_GET['task'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$ticketId = filter_var($_GET['ticket_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
try {
    if (!$taskId || !$ticketId || !taskEmbedCanOpen(connectToDatabase(), (int)$_SESSION['analyst_id'], $taskId, $taskId, $ticketId)) {
        http_response_code(404); exit;
    }
} catch (Throwable $error) {
    http_response_code(503); exit;
}
$taskEmbedContext = ['taskId'=>$taskId, 'ticketId'=>$ticketId];
require __DIR__.'/index.php';
