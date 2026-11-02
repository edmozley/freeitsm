<?php
/**
 * Follow-up work from a task occurrence (GH #190 phase 3): a ticket or task that
 * fixes what a check found, raised from the Work view or linked to it.
 *
 * Completing the check means the review was done and its findings recorded; the
 * remediation can stay open. Whether an OPEN follow-up stops the occurrence
 * completing is a setting (Tasks -> Settings -> Evidence), allowed by default
 * (decision 5), enforced through the one task completion gate.
 *
 * Tickets are raised through TicketsService::createTicket(), the way the Projects
 * RAID log raises them, so they are numbered, routed and notified like any other.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/task_history.php';

class TaskFollowUps
{
    /** Has the table been created yet (Database Verification)? Cached per request. */
    public static function ready(PDO $conn): bool
    {
        static $ready = null;
        if ($ready !== null) return $ready;
        try { $conn->query("SELECT id FROM task_follow_ups LIMIT 0"); return $ready = true; }
        catch (Throwable) { return $ready = false; }
    }

    /** Tasks -> Settings -> Evidence -> "An open follow-up at completion": allow (default) | warn | block. */
    public static function policy(PDO $conn): string
    {
        try {
            $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'tasks_evidence_open_followups'");
            $s->execute();
            $v = (string)$s->fetchColumn();
            return in_array($v, ['warn', 'block'], true) ? $v : 'allow';
        } catch (Throwable) {
            return 'allow';
        }
    }

    /**
     * A task's follow-ups with their live state. A ticket or task the viewer cannot see is named
     * by number only - the same rule as the #181 evidence report.
     */
    public static function forTask(PDO $conn, int $taskId, int $viewerId): array
    {
        if (!self::ready($conn)) return [];
        $st = $conn->prepare("SELECT f.*, a.full_name AS created_by FROM task_follow_ups f LEFT JOIN analysts a ON a.id = f.created_by_id
                               WHERE f.task_id = ? ORDER BY f.id");
        $st->execute([$taskId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = self::describe($conn, $r, $viewerId);
        }
        return $out;
    }

    /** One follow-up row with the record's number, title and whether it is still open. */
    private static function describe(PDO $conn, array $r, int $viewerId): array
    {
        $kind = (string)$r['kind'];
        $id = (int)$r['record_id'];
        $o = ['id' => (int)$r['id'], 'kind' => $kind, 'record_id' => $id, 'created_here' => (int)$r['created_here'] === 1,
              'source_kind' => $r['source_kind'], 'note' => $r['note'], 'created_by' => $r['created_by'], 'created_utc' => $r['created_datetime'],
              'number' => null, 'title' => null, 'status' => null, 'open' => null, 'visible' => false, 'exists' => false];
        if ($kind === 'ticket') {
            $q = $conn->prepare("SELECT t.ticket_number, t.subject, s.name AS status, s.is_closed FROM tickets t LEFT JOIN ticket_statuses s ON s.id = t.status_id WHERE t.id = ?");
            $q->execute([$id]);
            if ($t = $q->fetch(PDO::FETCH_ASSOC)) {
                $o['exists'] = true;
                $o['number'] = (string)$t['ticket_number'];
                $o['open'] = (int)$t['is_closed'] !== 1;
                if ($viewerId > 0 && function_exists('analystCanAccessTicket') && analystCanAccessTicket($conn, $viewerId, $id)) {
                    $o['visible'] = true; $o['title'] = (string)$t['subject']; $o['status'] = (string)$t['status'];
                }
            }
        } else {
            $q = $conn->prepare("SELECT t.title, s.name AS status, s.is_closed FROM tasks t LEFT JOIN task_statuses s ON s.id = t.status_id WHERE t.id = ?");
            $q->execute([$id]);
            if ($t = $q->fetch(PDO::FETCH_ASSOC)) {
                $o['exists'] = true;
                $o['number'] = 'TASK-' . $id;
                $o['open'] = (int)$t['is_closed'] !== 1;
                if ($viewerId > 0 && function_exists('analystCanAccessTask') && analystCanAccessTask($conn, $viewerId, $id)) {
                    $o['visible'] = true; $o['title'] = (string)$t['title']; $o['status'] = (string)$t['status'];
                }
            }
        }
        return $o;
    }

    /**
     * Raise a follow-up TASK, in the occurrence's company. A retry with the same client
     * reference returns the follow-up already made.
     */
    public static function createTask(PDO $conn, ActorContext $ctx, int $taskId, string $title, string $note = '',
                                      ?string $sourceKind = null, ?int $sourceId = null, string $clientRef = ''): array
    {
        if ($replay = self::replay($conn, $taskId, $clientRef)) return $replay;
        $occ = self::guardWrite($conn, $ctx, $taskId);
        require_once __DIR__ . '/tasks.php';
        $title = trim($title) !== '' ? trim($title) : ('Follow-up: ' . $occ['title']);
        $desc = trim($note) !== '' ? $note : '';
        $desc .= ($desc !== '' ? "\n\n" : '') . 'Raised from TASK-' . $taskId . ' ' . $occ['title'] . ($occ['scheduled_date'] ? ' (' . $occ['scheduled_date'] . ')' : '') . '.';
        $res = TasksService::saveTask($conn, $ctx, ['title' => mb_substr($title, 0, 255), 'description' => nl2br(htmlspecialchars($desc)),
                                                    'tenant_id' => $occ['tenant_id']]);
        return self::insert($conn, $ctx, $taskId, 'task', (int)$res['id'], true, $sourceKind, $sourceId, $note, $clientRef);
    }

    /**
     * Raise a follow-up TICKET through TicketsService::createTicket(): the analyst is the requester
     * (it is the IT team's own work) and the assignee, in the occurrence's company. Needs Tickets.
     */
    public static function createTicket(PDO $conn, ActorContext $ctx, int $taskId, string $subject, string $note = '',
                                        ?string $sourceKind = null, ?int $sourceId = null, string $clientRef = ''): array
    {
        if ($replay = self::replay($conn, $taskId, $clientRef)) return $replay;
        $occ = self::guardWrite($conn, $ctx, $taskId);
        if ($ctx->actorId <= 0 || !analystCanAccessModule($conn, $ctx->actorId, 'tickets')) {
            throw new ServiceError('forbidden', 'forbidden', 'Raising a ticket needs access to Tickets.');
        }
        $st = $conn->prepare("SELECT full_name, email FROM analysts WHERE id = ?");
        $st->execute([$ctx->actorId]);
        $me = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $email = trim((string)($me['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ServiceError('validation', 'missing_field', 'Your account has no email address, so it cannot be the ticket\'s requester. Add one under your profile.');
        }
        require_once __DIR__ . '/tickets.php';
        $subject = trim($subject) !== '' ? trim($subject) : ('Follow-up: ' . $occ['title']);
        $desc = trim($note) !== '' ? $note : '';
        $desc .= ($desc !== '' ? "\n\n" : '') . 'Raised from TASK-' . $taskId . ' ' . $occ['title'] . ($occ['scheduled_date'] ? ' (' . $occ['scheduled_date'] . ')' : '') . '.';
        $tenant = $occ['tenant_id'] === null ? (int)getDefaultTenantId($conn) : (int)$occ['tenant_id'];
        $ticketId = TicketsService::createTicket($conn, $ctx, $tenant, [
            'subject' => mb_substr($subject, 0, 255), 'description' => $desc,
            'requester_email' => $email, 'requester_name' => (string)($me['full_name'] ?? ''),
        ], $ctx->actorId, 'Raised from task TASK-' . $taskId . ' (follow-up)', 'internal');
        return self::insert($conn, $ctx, $taskId, 'ticket', $ticketId, true, $sourceKind, $sourceId, $note, $clientRef);
    }

    /**
     * Link an existing ticket (by number or id) or task (by id) as a follow-up. Only one the
     * analyst can see - a link must not reveal another company's record.
     */
    public static function link(PDO $conn, ActorContext $ctx, int $taskId, string $kind, string $ref,
                                ?string $sourceKind = null, ?int $sourceId = null): array
    {
        self::guardWrite($conn, $ctx, $taskId);
        $ref = trim($ref);
        if ($kind === 'ticket') {
            $q = $conn->prepare("SELECT id FROM tickets WHERE ticket_number = ? OR id = ? LIMIT 1");
            $q->execute([$ref, ctype_digit($ref) ? (int)$ref : 0]);
            $id = (int)$q->fetchColumn();
            if (!$id || !analystCanAccessTicket($conn, $ctx->actorId, $id)) throw new ServiceError('not_found', 'not_found', 'Ticket not found.');
        } else {
            $id = (int)preg_replace('/\D/', '', $ref);
            if (!$id || $id === $taskId || !analystCanAccessTask($conn, $ctx->actorId, $id)) throw new ServiceError('not_found', 'not_found', 'Task not found.');
            $kind = 'task';
        }
        $dup = $conn->prepare("SELECT 1 FROM task_follow_ups WHERE task_id = ? AND kind = ? AND record_id = ?");
        $dup->execute([$taskId, $kind, $id]);
        if ($dup->fetchColumn()) throw new ServiceError('conflict', 'conflict', 'That is already a follow-up of this task.');
        return self::insert($conn, $ctx, $taskId, $kind, $id, false, $sourceKind, $sourceId, '', '');
    }

    /** Remove the link. The ticket or task itself is untouched. */
    public static function unlink(PDO $conn, ActorContext $ctx, int $followUpId): int
    {
        $st = $conn->prepare("SELECT * FROM task_follow_ups WHERE id = ?");
        $st->execute([$followUpId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new ServiceError('not_found', 'not_found', 'That follow-up is no longer on this task.');
        self::guardWrite($conn, $ctx, (int)$r['task_id']);
        $conn->prepare("DELETE FROM task_follow_ups WHERE id = ?")->execute([$followUpId]);
        TaskHistory::record($conn, (int)$r['task_id'], 'follow_up_removed', $ctx, $r['kind'] . ':' . $r['record_id'], null,
            ['kind' => $r['kind'], 'record_id' => (int)$r['record_id']]);
        return (int)$r['task_id'];
    }

    /** The task a follow-up belongs to, or 0. */
    public static function taskIdFor(PDO $conn, int $followUpId): int
    {
        $st = $conn->prepare("SELECT task_id FROM task_follow_ups WHERE id = ?");
        $st->execute([$followUpId]);
        return (int)$st->fetchColumn();
    }

    /**
     * Open follow-ups for the one completion gate, when the setting says they matter:
     * [['label' => 'INC-123', 'mode' => warn|block], ...]. Empty when allowed.
     */
    public static function outstanding(PDO $conn, int $taskId): array
    {
        if (!self::ready($conn)) return [];
        $policy = self::policy($conn);
        if ($policy === 'allow') return [];
        $out = [];
        foreach (self::forTask($conn, $taskId, 0) as $f) {
            if ($f['exists'] && $f['open']) $out[] = ['label' => (string)$f['number'], 'mode' => $policy];
        }
        return $out;
    }

    /** Remove the links of deleted tasks. The tickets and tasks stay. */
    public static function deleteForTasks(PDO $conn, array $taskIds): void
    {
        if (!$taskIds) return;
        try {
            $place = implode(',', array_fill(0, count($taskIds), '?'));
            $conn->prepare("DELETE FROM task_follow_ups WHERE task_id IN ($place)")->execute(array_values($taskIds));
        } catch (Throwable) { /* before Database Verification */ }
    }

    /** For the #181 frozen record. */
    public static function snapshot(PDO $conn, int $taskId, bool $strict = false): array
    {
        if (!self::ready($conn)) return [];
        try {
            return array_map(fn($f) => ['kind' => $f['kind'], 'record_id' => $f['record_id'], 'number' => $f['number'],
                                         'open' => $f['open'], 'created_here' => $f['created_here'], 'source_kind' => $f['source_kind']],
                             self::forTask($conn, $taskId, 0));
        } catch (Throwable $e) {
            if ($strict) throw $e;
            return [];
        }
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /** A retried create: the follow-up the same client reference already made. */
    private static function replay(PDO $conn, int $taskId, string $clientRef): ?array
    {
        $clientRef = substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', $clientRef), 0, 64);
        if ($clientRef === '') return null;
        $q = $conn->prepare("SELECT * FROM task_follow_ups WHERE task_id = ? AND client_reference = ?");
        $q->execute([$taskId, $clientRef]);
        $r = $q->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id' => (int)$r['id'], 'kind' => $r['kind'], 'record_id' => (int)$r['record_id'], 'replayed' => true] : null;
    }

    private static function insert(PDO $conn, ActorContext $ctx, int $taskId, string $kind, int $recordId, bool $createdHere,
                                   ?string $sourceKind, ?int $sourceId, string $note, string $clientRef): array
    {
        $clientRef = substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', $clientRef), 0, 64);
        $conn->prepare("INSERT INTO task_follow_ups (task_id, kind, record_id, created_here, source_kind, source_id, note, client_reference, created_by_id, created_datetime)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$taskId, $kind, $recordId, $createdHere ? 1 : 0, in_array($sourceKind, ['step', 'form'], true) ? $sourceKind : null,
                        $sourceId, $note !== '' ? $note : null, $clientRef !== '' ? $clientRef : null, $ctx->actorId ?: null]);
        $id = (int)$conn->lastInsertId();
        TaskHistory::record($conn, $taskId, $createdHere ? 'follow_up_created' : 'follow_up_linked', $ctx, null, $kind . ':' . $recordId,
            ['kind' => $kind, 'record_id' => $recordId, 'source_kind' => $sourceKind, 'source_id' => $sourceId]);
        return ['id' => $id, 'kind' => $kind, 'record_id' => $recordId, 'replayed' => false];
    }

    /** Tasks write, the occurrence visible; returns its title, date and company. */
    private static function guardWrite(PDO $conn, ActorContext $ctx, int $taskId): array
    {
        require_once __DIR__ . '/tasks.php';
        TasksService::assertCanWrite($conn, $ctx);
        if ($ctx->actorId > 0 && function_exists('analystCanAccessTask') && !analystCanAccessTask($conn, $ctx->actorId, $taskId)) {
            throw new ServiceError('not_found', 'not_found', 'Task not found.');
        }
        $st = $conn->prepare("SELECT id, title, scheduled_date, tenant_id FROM tasks WHERE id = ?");
        $st->execute([$taskId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) throw new ServiceError('not_found', 'not_found', 'Task not found.');
        return $t;
    }
}
