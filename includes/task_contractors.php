<?php
/**
 * Contractors on tasks (3.3.0) - work given to a THIRD PARTY: a supplier from
 * Contracts -> Suppliers, and optionally one of its contacts.
 *
 *   tasks.assigned_supplier_id   the supplier doing the work
 *   tasks.assigned_contact_id    the person there (a contacts row of that supplier)
 *
 * Alongside the analyst and the team, not instead of them: the analyst on a
 * contractor's task is the person here who CHASES it. So capacity counts a
 * contractor's task against the contractor, never against that analyst
 * (includes/projects/capacity.php), and the overdue digest, health and the
 * project manager's view treat it as ordinary project work, marked as a contractor's.
 *
 * The contractor has no login. What they hear is project_contractor_email
 * (Projects -> Settings -> General, OFF by default): when 'on', the contact is
 * emailed when given a task (tasksContractorEmail(..., 'assigned')), two days
 * before it is due and once the day after it is missed (tasksContractorReminders(),
 * run by the hourly scan), through the system mailbox - email log route
 * "Task for a contractor". Every task with a contractor, in a project or not.
 *
 * Suppliers and contacts are install-wide (Contracts has no companies), so a task
 * in any company may name any supplier. CHOOSING one needs the Contracts module
 * (where suppliers live - api/tasks/contractors.php); SEEING the name on a task
 * you can already see does not, like a project or team name.
 */

/** Has Database Verification added the two columns? Before it, there are no contractors and no errors. */
function tasksContractorReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $conn->query("SELECT assigned_supplier_id, assigned_contact_id FROM tasks LIMIT 0"); $ready = true; }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

/** The name a supplier goes by. */
function tasksSupplierNameSql(string $alias = 'sp'): string
{
    return "COALESCE(NULLIF($alias.trading_name, ''), $alias.legal_name)";
}

/**
 * The contractor on each of these tasks: [task_id => {supplier_id, supplier_name,
 * contact_id, contact_name, contact_email}]. Tasks without one are absent.
 */
function tasksContractors(PDO $conn, array $taskIds): array
{
    $taskIds = array_values(array_filter(array_map('intval', $taskIds)));
    if (!$taskIds || !tasksContractorReady($conn)) return [];
    $in = implode(',', $taskIds);
    $out = [];
    foreach ($conn->query("SELECT t.id, t.assigned_supplier_id, " . tasksSupplierNameSql() . " AS supplier_name, t.assigned_contact_id,
                                  TRIM(CONCAT(c.first_name, ' ', c.surname)) AS contact_name, c.email AS contact_email
                             FROM tasks t JOIN suppliers sp ON sp.id = t.assigned_supplier_id
                        LEFT JOIN contacts c ON c.id = t.assigned_contact_id
                            WHERE t.id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['id']] = ['supplier_id' => (int)$r['assigned_supplier_id'], 'supplier_name' => $r['supplier_name'],
            'contact_id' => $r['assigned_contact_id'] !== null ? (int)$r['assigned_contact_id'] : null, 'contact_name' => $r['contact_name'] ?: null, 'contact_email' => $r['contact_email'] ?: null];
    }
    return $out;
}

/** Add the contractor fields to task rows (each with an 'id'). */
function tasksWithContractors(PDO $conn, array $rows): array
{
    $c = tasksContractors($conn, array_column($rows, 'id'));
    foreach ($rows as &$r) {
        $x = $c[(int)$r['id']] ?? null;
        $r['supplier_id'] = $x['supplier_id'] ?? null; $r['supplier_name'] = $x['supplier_name'] ?? null;
        $r['contact_id'] = $x['contact_id'] ?? null; $r['contact_name'] = $x['contact_name'] ?? null;
    }
    unset($r);
    return $rows;
}

/**
 * Check a supplier / contact pair: the supplier exists; the contact (if any) works
 * for it. A contact alone brings their supplier. Returns [supplierId, contactId].
 */
function tasksContractorValidate(PDO $conn, $supplierId, $contactId): array
{
    $sid = ($supplierId === '' || $supplierId === null) ? null : (int)$supplierId;
    $cid = ($contactId === '' || $contactId === null) ? null : (int)$contactId;
    if ($cid !== null) {
        $st = $conn->prepare("SELECT supplier_id FROM contacts WHERE id = ?");
        $st->execute([$cid]);
        $of = $st->fetchColumn();
        if ($of === false) throw new ServiceError('validation', 'invalid_field', 'That contact does not exist.');
        if ($of === null) throw new ServiceError('validation', 'invalid_field', 'That contact does not work for a supplier.');
        if ($sid === null) $sid = (int)$of;
        if ((int)$of !== $sid) throw new ServiceError('validation', 'invalid_field', 'That contact does not work for that supplier.');
    }
    if ($sid !== null) {
        $st = $conn->prepare("SELECT 1 FROM suppliers WHERE id = ?");
        $st->execute([$sid]);
        if (!$st->fetchColumn()) throw new ServiceError('validation', 'invalid_field', 'That supplier does not exist.');
    }
    return [$sid, $cid];
}

/** Suppliers to choose from, each with its active contacts. */
function tasksContractorChoices(PDO $conn): array
{
    $sup = $conn->query("SELECT id, " . tasksSupplierNameSql('s') . " AS name FROM suppliers s ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $con = $conn->query("SELECT id, supplier_id, TRIM(CONCAT(first_name, ' ', surname)) AS name, job_title, email FROM contacts WHERE supplier_id IS NOT NULL AND is_active = 1 ORDER BY first_name, surname")->fetchAll(PDO::FETCH_ASSOC);
    $by = [];
    foreach ($con as $c) $by[(int)$c['supplier_id']][] = ['id' => (int)$c['id'], 'name' => $c['name'], 'job_title' => $c['job_title'], 'has_email' => (bool)$c['email']];
    return array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name'], 'contacts' => $by[(int)$s['id']] ?? []], $sup);
}

/** Is project_contractor_email on? */
function tasksContractorEmailOn(PDO $conn): bool
{
    try {
        require_once __DIR__ . '/projects/settings.php';
        return projectSetting($conn, 'project_contractor_email') === 'on';
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Email a task's contractor contact - $kind 'assigned', 'due' (due soon) or
 * 'overdue'. Quiet: false when the setting is off, there is no contact email or
 * the send fails (the email log says which). Never throws.
 */
function tasksContractorEmail(PDO $conn, int $taskId, string $kind): bool
{
    try {
        if (!tasksContractorReady($conn) || !tasksContractorEmailOn($conn)) return false;
        $st = $conn->prepare("SELECT t.id, t.title, t.description, t.start_date, t.due_date, t.project_id,
                                     c.first_name, c.email, " . tasksSupplierNameSql() . " AS supplier_name,
                                     COALESCE(own.full_name, cre.full_name) AS ours_name, COALESCE(own.email, cre.email) AS ours_email
                                FROM tasks t JOIN contacts c ON c.id = t.assigned_contact_id
                                JOIN suppliers sp ON sp.id = t.assigned_supplier_id
                           LEFT JOIN analysts own ON own.id = t.assigned_analyst_id
                           LEFT JOIN analysts cre ON cre.id = t.created_by_id
                               WHERE t.id = ?");
        $st->execute([$taskId]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t || !filter_var((string)$t['email'], FILTER_VALIDATE_EMAIL)) return false;
        $project = null;
        if ($t['project_id']) {
            $p = $conn->prepare("SELECT name FROM projects WHERE id = ?");
            $p->execute([(int)$t['project_id']]);
            $project = $p->fetchColumn() ?: null;
        }
        $org = function_exists('systemName') ? systemName() : 'FreeITSM';
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $lead = [
            'assigned' => 'You have been given a task by ' . $org . '.',
            'due'      => 'A reminder: this task for ' . $org . ' is due ' . ($t['due_date'] ?: 'soon') . '.',
            'overdue'  => 'This task for ' . $org . ' was due ' . ($t['due_date'] ?: '') . ' and is not marked done.',
        ][$kind] ?? '';
        $subject = ['assigned' => 'New task: ', 'due' => 'Due soon: ', 'overdue' => 'Overdue: '][$kind] . $t['title'];
        $desc = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", (string)$t['description'])), ENT_QUOTES, 'UTF-8'));
        $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.5;color:#1f2937;max-width:640px">'
            . '<p>' . $e('Hello ' . ($t['first_name'] ?: '') . ',') . '</p><p>' . $e($lead) . '</p>'
            . '<table style="border-collapse:collapse;margin:12px 0">'
            . '<tr><td style="padding:4px 12px 4px 0;color:#6b7280">Task</td><td style="padding:4px 0"><strong>' . $e($t['title']) . '</strong></td></tr>'
            . ($project ? '<tr><td style="padding:4px 12px 4px 0;color:#6b7280">Project</td><td style="padding:4px 0">' . $e($project) . '</td></tr>' : '')
            . '<tr><td style="padding:4px 12px 4px 0;color:#6b7280">For</td><td style="padding:4px 0">' . $e($t['supplier_name']) . '</td></tr>'
            . ($t['start_date'] ? '<tr><td style="padding:4px 12px 4px 0;color:#6b7280">Start</td><td style="padding:4px 0">' . $e($t['start_date']) . '</td></tr>' : '')
            . ($t['due_date'] ? '<tr><td style="padding:4px 12px 4px 0;color:#6b7280">Due</td><td style="padding:4px 0"><strong>' . $e($t['due_date']) . '</strong></td></tr>' : '')
            . '</table>'
            . ($desc !== '' ? '<p style="white-space:pre-wrap;border-left:3px solid #e5e7eb;padding-left:12px">' . $e(mb_substr($desc, 0, 4000)) . '</p>' : '')
            . ($t['ours_name'] ? '<p>' . $e('Your contact at ' . $org . ' is ' . $t['ours_name'] . ($t['ours_email'] ? ' (' . $t['ours_email'] . ')' : '') . '. Please reply to them, not to this email.') . '</p>' : '')
            . '<p style="color:#6b7280;font-size:12px;margin-top:20px">' . $e('Sent by ' . $org . '.') . '</p></div>';
        require_once __DIR__ . '/self_service_email.php';
        require_once __DIR__ . '/template_email.php';
        return ssSendSystemEmail($conn, (string)$t['email'], $subject, $html, 'task_contractor');
    } catch (Throwable $e) {
        error_log('task contractor email: ' . $e->getMessage());
        return false;
    }
}

/**
 * The reminders, from the hourly scan (projectAlertsScan): two days before a
 * contractor's task is due, and once the day after it is missed - each once per
 * due date (the ledger), so moving the date re-arms them. Open tasks with a
 * contact only; nothing when the setting is off. Returns how many were sent.
 */
function tasksContractorReminders(PDO $conn): int
{
    if (!tasksContractorReady($conn) || !tasksContractorEmailOn($conn)) return 0;
    $rows = $conn->query("SELECT t.id, t.due_date,
                                 CASE WHEN t.due_date < UTC_DATE() THEN 'overdue' ELSE 'due' END AS kind
                            FROM tasks t LEFT JOIN task_statuses s ON s.id = t.status_id
                           WHERE t.assigned_contact_id IS NOT NULL AND COALESCE(s.is_closed, 0) = 0 AND t.due_date IS NOT NULL
                             AND t.due_date BETWEEN DATE_SUB(UTC_DATE(), INTERVAL 7 DAY) AND DATE_ADD(UTC_DATE(), INTERVAL 2 DAY)
                             AND t.due_date <> UTC_DATE()")->fetchAll(PDO::FETCH_ASSOC);
    $claim = $conn->prepare("INSERT IGNORE INTO workflow_scheduled_emissions (trigger_event, entity_key, fingerprint, emitted_datetime) VALUES ('task.contractor_reminder', ?, ?, UTC_TIMESTAMP())");
    $sent = 0;
    foreach ($rows as $r) {
        try { $claim->execute(['task_contractor:' . (int)$r['id'] . ':' . $r['kind'], (string)$r['due_date']]); }
        catch (Throwable $e) { return $sent; }   // no ledger: sending without one would repeat every run
        if ($claim->rowCount() !== 1) continue;
        if (tasksContractorEmail($conn, (int)$r['id'], $r['kind'])) $sent++;
    }
    return $sent;
}
