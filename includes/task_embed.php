<?php
/** Validate the native task editor's ticket context, including a linked task's subtasks. */
function taskEmbedCanOpen(PDO $conn, int $analystId, int $taskId, int $rootId, int $ticketId): bool
{
    if ($taskId <= 0 || $rootId <= 0 || $ticketId <= 0) return false;
    if (!analystCanAccessTicket($conn, $analystId, $ticketId)
        || !analystCanAccessTask($conn, $analystId, $rootId)
        || !analystCanAccessTask($conn, $analystId, $taskId)) return false;
    $root = $conn->prepare('SELECT t.id FROM tasks t JOIN tickets tk ON tk.id = t.ticket_id WHERE t.id = ? AND tk.id = ? AND tk.deleted_datetime IS NULL');
    $root->execute([$rootId, $ticketId]);
    if (!$root->fetchColumn()) return false;
    $parent = $conn->prepare('SELECT parent_task_id FROM tasks WHERE id = ?');
    $seen = [];
    while ($taskId !== $rootId) {
        if (isset($seen[$taskId]) || count($seen) >= 100 || !analystCanAccessTask($conn, $analystId, $taskId)) return false;
        $seen[$taskId] = true;
        $parent->execute([$taskId]);
        $taskId = (int)$parent->fetchColumn();
        if (!$taskId) return false;
    }
    return true;
}
