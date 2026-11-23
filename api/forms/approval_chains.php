<?php
/**
 * API: named approval chains and the default fallback approver (GH #160 Part C1).
 *
 * GET  -> { success, available, chains: [{id, name, description, steps, is_active, form_count, forms}],
 *           default_fallback, questions: ['Order total', ...] }
 *      Anyone with Forms reads it - Approval settings lists the chains to choose from.
 *      questions: the labels of the questions on current forms, for a chain's
 *      "Only when" (a chain names its question by label - it is shared by many forms).
 * POST { action: 'save', id?, name, description?, steps }     -> { success, id }
 * POST { action: 'delete', id }                                 -> { success }
 *      Refused while any current form uses the chain.
 * POST { action: 'default', fallback: null | {who, approver_id | team_id, approvals_required, rejections_required} }
 * POST { action: 'options', deputy_mode: 'any'|'cover', deputy_days, mailbox_id, remind }   (GH #160 Part C2)
 *      Writes need Forms -> Settings -> Approvals (Cap::FORMS_APPROVALS).
 *
 * GET also returns (Part C2): people (manager / cost-centre-owner steps can be chosen),
 * cc_questions (labels of cost-centre questions on current forms), options (the above)
 * and mailboxes [{id, name, address}] to send approvers' email from.
 *
 * A chain is snapshotted onto each request when it is made, so saving one affects
 * new requests only. See includes/catalogue_approval_steps.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/services/forms.php';
require_once '../../includes/catalogue_approvals.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }
requireModuleAccessJson('forms');

try {
    $conn = connectToDatabase();
    if (!catalogueStepsAvailable($conn)) {
        echo json_encode(['success' => true, 'available' => false, 'chains' => [], 'default_fallback' => null, 'questions' => []]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $chains = $conn->query(
            "SELECT c.id, c.name, c.description, c.steps, c.is_active
               FROM form_approval_chains c ORDER BY c.name"
        )->fetchAll(PDO::FETCH_ASSOC);
        // The current versions (leaves) that use each chain - older versions are frozen.
        $uses = [];
        foreach ($conn->query(
            "SELECT f.approval_chain_id, f.id, f.title FROM forms f
              WHERE f.approval_chain_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM forms ch WHERE ch.parent_form_id = f.id)
              ORDER BY f.title"
        )->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $uses[(int)$u['approval_chain_id']][] = ['id' => (int)$u['id'], 'title' => $u['title']];
        }
        foreach ($chains as &$c) {
            $c['id'] = (int)$c['id'];
            $c['is_active'] = (int)$c['is_active'];
            $c['steps'] = catalogueNormaliseSteps($conn, $c['steps'], false);
            $c['forms'] = $uses[$c['id']] ?? [];
            $c['form_count'] = count($c['forms']);
        }
        unset($c);
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'forms_approval_fallback'");
        $st->execute();
        $fb = json_decode((string)$st->fetchColumn(), true);
        $questions = $conn->query(
            "SELECT DISTINCT TRIM(ff.label) FROM form_fields ff JOIN forms f ON f.id = ff.form_id
              WHERE ff.is_deleted = 0 AND f.is_active = 1 AND TRIM(ff.label) <> ''
                AND ff.field_type IN ('" . implode("','", FormsService::ANSWERABLE_TYPES) . "')
                AND NOT EXISTS (SELECT 1 FROM forms ch WHERE ch.parent_form_id = f.id)
              ORDER BY 1 LIMIT 500"
        )->fetchAll(PDO::FETCH_COLUMN);
        // Who a chain's steps can name, for the chain editor - its own lists, so the
        // settings page needs neither Tickets nor the forms list.
        $analysts = $conn->query("SELECT id, full_name, username FROM analysts WHERE COALESCE(is_active, 1) = 1 ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
        $teams = $conn->query(
            "SELECT t.id, t.name, (SELECT COUNT(*) FROM analyst_teams at JOIN analysts a ON a.id = at.analyst_id AND COALESCE(a.is_active, 1) = 1
                                    WHERE at.team_id = t.id) AS member_count
               FROM teams t WHERE t.is_active = 1 ORDER BY t.name"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($analysts as &$a) $a['id'] = (int)$a['id'];
        unset($a);
        foreach ($teams as &$t) { $t['id'] = (int)$t['id']; $t['member_count'] = (int)$t['member_count']; }
        unset($t);
        // GH #160 Part C2: cost-centre questions (by label, as conditions), the settings,
        // and the mailboxes an approver's email can come from.
        $people = cataloguePeopleAvailable($conn);
        $ccQuestions = [];
        $mailboxes = [];
        if ($people) {
            foreach ($conn->query(
                "SELECT DISTINCT TRIM(ff.label) AS label, ff.config FROM form_fields ff JOIN forms f ON f.id = ff.form_id
                  WHERE ff.is_deleted = 0 AND f.is_active = 1 AND ff.field_type = 'lookup' AND TRIM(ff.label) <> ''
                    AND NOT EXISTS (SELECT 1 FROM forms ch WHERE ch.parent_form_id = f.id)"
            )->fetchAll(PDO::FETCH_ASSOC) as $q) {
                $cfg = json_decode((string)$q['config'], true);
                if (is_array($cfg) && ($cfg['lookup_source'] ?? '') === 'cost_centre' && !in_array($q['label'], $ccQuestions, true)) $ccQuestions[] = $q['label'];
            }
            sort($ccQuestions);
            foreach ($conn->query("SELECT id, name, target_mailbox AS address FROM target_mailboxes WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $mailboxes[] = ['id' => (int)$m['id'], 'name' => (string)$m['name'], 'address' => (string)($m['address'] ?? '')];
            }
        }
        echo json_encode(['success' => true, 'available' => true, 'chains' => $chains,
                          'default_fallback' => is_array($fb) && !empty($fb['who']) ? $fb : null, 'questions' => $questions,
                          'analysts' => $analysts, 'teams' => $teams,
                          'people' => $people, 'cc_questions' => $ccQuestions,
                          'options' => $people ? catalogueApprovalOptions($conn) : null, 'mailboxes' => $mailboxes]);
        exit;
    }

    // Changes data: never on a GET (csrf.php).
    csrfRequireWriteMethod();
    requireCapabilityJson(Cap::FORMS_APPROVALS);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');

    if ($action === 'save') {
        $id = (int)($in['id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) throw new Exception('Give the chain a name of up to 100 characters.');
        $steps = catalogueNormaliseSteps($conn, $in['steps'] ?? [], true);
        if (!$steps) throw new Exception('Add at least one step.');
        $dup = $conn->prepare('SELECT id FROM form_approval_chains WHERE name = ? AND id <> ?');
        $dup->execute([$name, $id]);
        if ($dup->fetchColumn()) throw new Exception('There is already a chain with that name.');
        $desc = trim((string)($in['description'] ?? ''));
        $json = json_encode($steps, JSON_UNESCAPED_UNICODE);
        if ($id > 0) {
            $u = $conn->prepare('UPDATE form_approval_chains SET name = ?, description = ?, steps = ?, modified_date = UTC_TIMESTAMP() WHERE id = ?');
            $u->execute([$name, $desc !== '' ? $desc : null, $json, $id]);
            if (!$u->rowCount() && !$conn->query('SELECT 1 FROM form_approval_chains WHERE id = ' . $id)->fetchColumn()) {
                throw new Exception('That chain was not found.');
            }
        } else {
            $conn->prepare('INSERT INTO form_approval_chains (name, description, steps, created_by, created_date, modified_date) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
                 ->execute([$name, $desc !== '' ? $desc : null, $json, (int)$_SESSION['analyst_id']]);
            $id = (int)$conn->lastInsertId();
        }
        echo json_encode(['success' => true, 'id' => $id]);
    } elseif ($action === 'delete') {
        $id = (int)($in['id'] ?? 0);
        $n = $conn->prepare('SELECT COUNT(*) FROM forms f WHERE f.approval_chain_id = ? AND NOT EXISTS (SELECT 1 FROM forms ch WHERE ch.parent_form_id = f.id)');
        $n->execute([$id]);
        $count = (int)$n->fetchColumn();
        if ($count > 0) throw new Exception("This chain is used by $count form(s). Choose other approval on those forms first.");
        $conn->prepare('DELETE FROM form_approval_chains WHERE id = ?')->execute([$id]);
        echo json_encode(['success' => true]);
    } elseif ($action === 'default') {
        $fb = $in['fallback'] ?? null;
        $value = '';
        if (is_array($fb) && !empty($fb['who'])) {
            $p = catalogueNormaliseStepPolicy($conn, $fb, 'The default', function (string $m) { throw new Exception($m); });
            unset($p['exclude_requester']);
            $value = json_encode($p);
        }
        $u = $conn->prepare("UPDATE system_settings SET setting_value = ?, updated_datetime = UTC_TIMESTAMP() WHERE setting_key = 'forms_approval_fallback'");
        $u->execute([$value]);
        if (!$u->rowCount() && !$conn->query("SELECT 1 FROM system_settings WHERE setting_key = 'forms_approval_fallback'")->fetchColumn()) {
            $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_datetime) VALUES ('forms_approval_fallback', ?, UTC_TIMESTAMP())")->execute([$value]);
        }
        echo json_encode(['success' => true]);
    } elseif ($action === 'options') {
        // GH #160 Part C2: when the deputy decides, the mailbox approvers are emailed
        // from, and whether the requester may Remind.
        if (!cataloguePeopleAvailable($conn)) throw new Exception('Run Database Verification first.');
        $mode = ($in['deputy_mode'] ?? 'any') === 'cover' ? 'cover' : 'any';
        $days = (int)($in['deputy_days'] ?? 0);
        if ($days < 0 || $days > 60) throw new Exception('Choose between 0 and 60 days.');
        $mailboxId = (int)($in['mailbox_id'] ?? 0);
        if ($mailboxId > 0) {
            $m = $conn->prepare('SELECT 1 FROM target_mailboxes WHERE id = ? AND is_active = 1');
            $m->execute([$mailboxId]);
            if (!$m->fetchColumn()) throw new Exception('That mailbox was not found.');
        }
        catalogueApprovalSettingSave($conn, 'forms_approval_deputy_mode', $mode);
        catalogueApprovalSettingSave($conn, 'forms_approval_deputy_days', (string)$days);
        catalogueApprovalSettingSave($conn, 'forms_approval_mailbox_id', $mailboxId > 0 ? (string)$mailboxId : '');
        catalogueApprovalSettingSave($conn, 'forms_approval_remind', !empty($in['remind']) ? '1' : '0');
        echo json_encode(['success' => true, 'options' => catalogueApprovalOptions($conn)]);
    } else {
        throw new Exception('Unknown action');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
