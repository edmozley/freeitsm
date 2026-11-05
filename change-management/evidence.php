<?php
/**
 * Evidence export for one change (#180 Part 2 §6): a server-rendered, printable page
 * (print to PDF from the browser) holding everything an auditor asks for -
 *
 *   1. the change as it stands, every field;
 *   2. every approval round: route, state, who decided and when, whether it was
 *      independent, each vote with whether it counted and whether it was cast against
 *      the current version, and - for a pre-authorised change - the template version
 *      that approved it, in full;
 *   3. every saved version of every long-text field, in full, oldest first, with the
 *      baseline labelled "as found when history began";
 *   4. the whole audit trail and every comment, including deleted ones;
 *   5. the attachments by name, size, uploader, time and sha256.
 *
 * WHY SERVER-RENDERED: an evidence pack can run to hundreds of pages of revisions, and it
 * must be the same document whoever produces it. The server decides what goes in; the
 * browser only prints it. Times are UTC and say so - evidence must not depend on the
 * reader's time zone.
 *
 * WHO: anybody who can read the change - module access (Full or Read only) and the
 * change's company. Taking a copy of the evidence is itself written to the audit trail.
 *
 * TRAP: every stored text is author-written. It is printed through sanitiseUserHtml() or
 * escaped - never echoed raw.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/tenancy.php';
require_once '../includes/html_sanitise.php';
require_once '../includes/changes/approvals.php';
require_once '../includes/changes/revisions.php';
require_once '../includes/changes/templates.php';
require_once '../includes/changes/risk_policy.php';

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
requireModuleAccess('changes');
I18n::initFromSession();

$conn = connectToDatabase();
$me = (int)$_SESSION['analyst_id'];
$changeId = (int)($_GET['id'] ?? 0);
if (!$changeId || !analystCanAccessChange($conn, $me, $changeId)) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><p>Change not found.</p>';
    exit;
}

$s = $conn->prepare(
    "SELECT c.*, ct.name AS type_name, cs.name AS status_name, cs.stage AS status_stage, cp.name AS priority_name,
            ci.name AS impact_name, cc.name AS category_name, rq.full_name AS requester_name, asg.full_name AS assigned_name,
            ap.full_name AS approver_name, cb.full_name AS created_by_name
       FROM changes c
       LEFT JOIN change_types ct ON ct.id = c.change_type_id
       LEFT JOIN change_statuses cs ON cs.id = c.status_id
       LEFT JOIN change_priorities cp ON cp.id = c.priority_id
       LEFT JOIN change_impacts ci ON ci.id = c.impact_id
       LEFT JOIN change_categories cc ON cc.id = c.category_id
       LEFT JOIN analysts rq ON rq.id = c.requester_id
       LEFT JOIN analysts asg ON asg.id = c.assigned_to_id
       LEFT JOIN analysts ap ON ap.id = c.approver_id
       LEFT JOIN analysts cb ON cb.id = c.created_by_id
      WHERE c.id = ?"
);
$s->execute([$changeId]);
$c = $s->fetch(PDO::FETCH_ASSOC);

// Who took a copy of the evidence is part of the evidence.
$meName = (string)($conn->query("SELECT full_name FROM analysts WHERE id = {$me}")->fetchColumn() ?: 'Unknown');
$conn->prepare("INSERT INTO change_audit (change_id, analyst_id, action_type, field_name, old_value, new_value, created_datetime)
                VALUES (?, ?, 'evidence_export', 'Evidence', NULL, ?, UTC_TIMESTAMP())")
     ->execute([$changeId, $me, 'Exported by ' . $meName]);

$rounds = changeApprovalRounds($conn, $changeId);
$revisions = $conn->prepare(
    "SELECT r.*, a.full_name AS editor_name FROM change_text_revisions r LEFT JOIN analysts a ON a.id = r.editor_id
      WHERE r.change_id = ? ORDER BY r.field_key, r.id"
);
$revisions->execute([$changeId]);
$revisions = $revisions->fetchAll(PDO::FETCH_ASSOC);
$audit = $conn->prepare(
    "SELECT a.*, an.full_name AS analyst_name FROM change_audit a LEFT JOIN analysts an ON an.id = a.analyst_id
      WHERE a.change_id = ? ORDER BY a.created_datetime, a.id"
);
$audit->execute([$changeId]);
$audit = $audit->fetchAll(PDO::FETCH_ASSOC);
$comments = $conn->prepare(
    "SELECT cm.*, an.full_name AS analyst_name FROM change_comments cm LEFT JOIN analysts an ON an.id = cm.analyst_id
      WHERE cm.change_id = ? ORDER BY cm.created_datetime"
);
$comments->execute([$changeId]);
$comments = $comments->fetchAll(PDO::FETCH_ASSOC);
$attachments = $conn->prepare(
    "SELECT at.*, an.full_name AS uploader FROM change_attachments at LEFT JOIN analysts an ON an.id = at.uploaded_by_id
      WHERE at.change_id = ? ORDER BY at.uploaded_datetime"
);
$attachments->execute([$changeId]);
$attachments = $attachments->fetchAll(PDO::FETCH_ASSOC);

$e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
$utc = fn($v) => $v ? $e(substr((string)$v, 0, 16)) . ' UTC' : '-';
$rich = function ($html, string $format = 'html') use ($e) {
    if ($html === null || $html === '') return '<em>(empty)</em>';
    return $format === 'html' ? sanitiseUserHtml((string)$html) : nl2br($e($html));
};
$ref = 'CHG-' . str_pad((string)$changeId, 4, '0', STR_PAD_LEFT);
$fieldLabel = fn($k) => ChangeRevisions::FIELDS[$k][1] ?? ($k === 'comment' ? 'Deleted comment' : $k);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $e($ref); ?> - evidence</title>
<style>
    body { font-family: 'Segoe UI', Tahoma, sans-serif; color: #111; background: #fff; margin: 24px; font-size: 13px; line-height: 1.5; }
    h1 { font-size: 20px; margin: 0 0 4px; } h2 { font-size: 16px; margin: 28px 0 8px; border-bottom: 2px solid #333; padding-bottom: 4px; }
    h3 { font-size: 14px; margin: 16px 0 6px; }
    table { border-collapse: collapse; width: 100%; margin: 6px 0 12px; } th, td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
    th { background: #f3f3f3; width: 22%; }
    .meta { color: #555; } .box { border: 1px solid #ccc; padding: 8px 10px; margin: 4px 0 12px; }
    .baseline { background: #fff8e1; border-color: #e0c06a; }
    .flag { display: inline-block; padding: 0 6px; border: 1px solid #999; border-radius: 8px; font-size: 11px; margin-left: 4px; }
    .noprint { margin-bottom: 16px; } @media print { .noprint { display: none; } h2 { page-break-after: avoid; } .box, tr { page-break-inside: avoid; } }
</style>
</head>
<body>
<div class="noprint"><button onclick="window.print()">Print</button></div>
<h1><?php echo $e($ref); ?>: <?php echo $e($c['title']); ?></h1>
<div class="meta">Evidence exported by <?php echo $e($meName); ?> on <?php echo $e(gmdate('Y-m-d H:i')); ?> UTC. Times are UTC throughout. Images inside the text are not reproduced.</div>

<h2>1. The change as it stands</h2>
<table>
    <tr><th>Status</th><td><?php echo $e($c['status_name']); ?> (stage: <?php echo $e($c['status_stage'] ?? 'not classified'); ?>)</td></tr>
    <tr><th>Type / priority / impact</th><td><?php echo $e($c['type_name']); ?> / <?php echo $e($c['priority_name']); ?> / <?php echo $e($c['impact_name']); ?></td></tr>
    <tr><th>Category</th><td><?php echo $e($c['category_name'] ?? $c['category']); ?></td></tr>
    <tr><th>Requester / assigned to / approver</th><td><?php echo $e($c['requester_name']); ?> / <?php echo $e($c['assigned_name']); ?> / <?php echo $e($c['approver_name']); ?></td></tr>
    <tr><th>CAB</th><td><?php echo $c['cab_required'] ? 'Required (' . $e($c['cab_approval_type']) . ')' : 'Not required'; ?></td></tr>
    <?php // #246: the ratings in the words of the policy they were given under, and which policy that was.
    $rpVer = isset($c['risk_policy_version_id']) && $c['risk_policy_version_id'] !== null ? (int)$c['risk_policy_version_id'] : null;
    $rpc = ChangeRiskPolicy::content($conn, $rpVer);
    $rpLv = function (string $axis, $n) use ($rpc) { $n = (int)$n; $lv = $rpc[$axis][$n - 1] ?? null; return $n ? ($lv ? $lv['label'] . ' (' . $n . ' of ' . count($rpc[$axis]) . ')' : (string)$n) : ''; }; ?>
    <tr><th>Risk</th><td>Likelihood <?php echo $e($rpLv('likelihood', $c['risk_likelihood'])); ?>, impact <?php echo $e($rpLv('impact', $c['risk_impact_score'])); ?>, score <?php echo $e($c['risk_score']); ?> (<?php echo $e($c['risk_level']); ?>)<br>Risk policy: <?php echo $e(ChangeRiskPolicy::describeVersion($conn, $rpVer)); ?></td></tr>
    <tr><th>Work window</th><td><?php echo $e($c['work_start_datetime']); ?> to <?php echo $e($c['work_end_datetime']); ?> (local time as entered)</td></tr>
    <tr><th>Outage window</th><td><?php echo $e($c['outage_start_datetime']); ?> to <?php echo $e($c['outage_end_datetime']); ?> (local time as entered)</td></tr>
    <tr><th>Approved</th><td><?php echo $utc($c['approval_datetime']); ?></td></tr>
    <tr><th>Created</th><td><?php echo $utc($c['created_datetime']); ?> by <?php echo $e($c['created_by_name']); ?>; last modified <?php echo $utc($c['modified_datetime']); ?></td></tr>
</table>
<?php foreach (ChangeRevisions::FIELDS as $col => [$format, $label]): ?>
<h3><?php echo $e($label); ?></h3>
<div class="box"><?php echo $rich($c[$col] ?? null, $format); ?></div>
<?php endforeach; ?>

<h2>2. Approval rounds (<?php echo count($rounds); ?>)</h2>
<?php if (!$rounds): ?><p>No approval round has been recorded for this change.</p><?php endif; ?>
<?php foreach ($rounds as $r): ?>
<h3>Round <?php echo (int)$r['round_no']; ?>: <?php echo $e($r['route']); ?>, <?php echo $e($r['state']); ?></h3>
<table>
    <tr><th>Opened</th><td><?php echo $utc($r['opened_at']); ?><?php echo $r['opened_by_name'] ? ' by ' . $e($r['opened_by_name']) : ''; ?></td></tr>
    <tr><th>Decided</th><td><?php echo $r['decided_at'] ? $utc($r['decided_at']) . ($r['decided_by_name'] ? ' by ' . $e($r['decided_by_name']) : '') : '-'; ?></td></tr>
    <tr><th>Independent</th><td><?php echo $r['is_independent'] === null ? '-' : ($r['is_independent'] ? 'Yes' : 'No - one of the change\'s own people decided'); ?></td></tr>
    <?php if ($r['decision_note']): ?><tr><th>Decision note</th><td><?php echo $e($r['decision_note']); ?></td></tr><?php endif; ?>
    <?php if ($r['closed_reason']): ?><tr><th>Closed because</th><td><?php echo $e($r['closed_reason']); ?></td></tr><?php endif; ?>
    <tr><th>Content fingerprint</th><td><code><?php echo $e($r['content_hash'] ?? '-'); ?></code></td></tr>
    <?php if (!empty($r['snapshot']['revisions'])): ?>
    <tr><th>Text it was given</th><td><?php foreach ($r['snapshot']['revisions'] as $f => $rid): ?><?php echo $e($fieldLabel($f)); ?>: revision #<?php echo (int)$rid; ?><br><?php endforeach; ?></td></tr>
    <?php endif; ?>
</table>
<?php if ($r['votes']): ?>
<table>
    <tr><th>Voter</th><th>Vote</th><th>When</th><th>Counted</th><th>Version</th></tr>
    <?php foreach ($r['votes'] as $v): ?>
    <tr><td><?php echo $e($v['analyst_name'] ?? 'Former analyst'); ?><?php echo $v['is_required'] ? '' : ' (optional)'; ?></td>
        <td><?php echo $e($v['vote']); ?><?php echo $v['comment'] ? ': ' . $e($v['comment']) : ''; ?></td>
        <td><?php echo $utc($v['voted_at']); ?></td>
        <td><?php echo $v['is_counted'] ? 'Yes' : 'No - one of the change\'s own people'; ?></td>
        <td><?php echo $v['was_current'] === null ? 'Not stated' : ($v['was_current'] ? 'Current' : 'An earlier version'); ?></td></tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>
<?php if ($r['route'] === 'pre_authorised' && !empty($r['snapshot']['template_version_id'])):
    $tv = ChangeTemplates::version($conn, (int)$r['snapshot']['template_version_id']); if ($tv): ?>
<div class="box">
    <strong>Pre-authorised by template "<?php echo $e($tv['template_name']); ?>" version <?php echo (int)$tv['version_no']; ?></strong><br>
    Approved by <?php echo $e($tv['approved_by_name']); ?> on <?php echo $utc($tv['approved_datetime']); ?><?php echo $tv['approval_note'] ? ': ' . $e($tv['approval_note']) : ''; ?>.
    Submitted by <?php echo $e($tv['created_by_name']); ?>.<br>
    <strong>Use it for:</strong> <?php echo $e($tv['content']['scope_use'] ?? '-'); ?><br>
    <strong>Not for:</strong> <?php echo $e($tv['content']['scope_not'] ?? '-'); ?><br>
    <strong>Completed per change:</strong> <?php echo $e(implode(', ', $tv['content']['open'] ?? [])); ?>
    <?php foreach (($tv['content']['values'] ?? []) as $col => $val): if ($val === null || $val === '') continue; ?>
    <div style="margin-top:6px;"><em><?php echo $e($col); ?></em>: <?php echo is_numeric($val) ? $e($val) : $rich($val); ?></div>
    <?php endforeach; ?>
</div>
<?php endif; endif; ?>
<?php endforeach; ?>

<h2>3. Text history (<?php echo count($revisions); ?> versions)</h2>
<?php $lastField = null; foreach ($revisions as $rv): if ($rv['field_key'] !== $lastField): $lastField = $rv['field_key']; ?>
<h3><?php echo $e($fieldLabel($rv['field_key'])); ?></h3>
<?php endif; ?>
<div class="box<?php echo $rv['is_baseline'] ? ' baseline' : ''; ?>">
    <div class="meta">Revision #<?php echo (int)$rv['id']; ?> -
    <?php if ($rv['is_baseline']): ?>As found on <?php echo $utc($rv['created_datetime']); ?>, when history began. Earlier versions of this text were not kept, so who wrote it and when is not known.
    <?php elseif ($rv['field_key'] === 'comment'): ?>Comment by <?php echo $e($rv['editor_name'] ?? 'unknown'); ?>, written <?php echo $utc($rv['created_datetime']); ?>, later deleted.
    <?php else: ?><?php echo $e($rv['editor_name'] ?? ($rv['source'] === 'system' ? 'Changed outside the editor' : 'Unknown')); ?>, <?php echo $utc($rv['created_datetime']); ?> (<?php echo $e($rv['source']); ?>)<?php endif; ?>
    - sha256 <code><?php echo $e(substr($rv['content_hash'], 0, 16)); ?>…</code></div>
    <?php echo $rich($rv['content'], $rv['content_format']); ?>
</div>
<?php endforeach; ?>

<h2>4. Audit trail and comments</h2>
<table>
    <tr><th>When</th><th>Who</th><th>What</th></tr>
    <?php foreach ($audit as $a): ?>
    <tr><td><?php echo $utc($a['created_datetime']); ?></td><td><?php echo $e($a['analyst_name']); ?></td>
        <td><?php echo $e($a['action_type']); ?><?php echo $a['field_name'] ? ' - ' . $e($a['field_name']) : ''; ?>
            <?php echo $a['old_value'] !== null ? ': ' . $e($a['old_value']) : ''; ?><?php echo $a['new_value'] !== null ? ' → ' . $e($a['new_value']) : ''; ?></td></tr>
    <?php endforeach; ?>
</table>
<?php foreach ($comments as $cm): ?>
<div class="box"><div class="meta"><?php echo $e($cm['analyst_name']); ?>, <?php echo $utc($cm['created_datetime']); ?></div><?php echo nl2br($e($cm['comment_text'])); ?></div>
<?php endforeach; ?>

<h2>5. Attachments (<?php echo count($attachments); ?>)</h2>
<table>
    <tr><th>File</th><th>Size</th><th>Uploaded</th><th>sha256</th></tr>
    <?php foreach ($attachments as $at): ?>
    <tr><td><?php echo $e($at['file_name']); ?></td><td><?php echo $e($at['file_size']); ?> bytes</td>
        <td><?php echo $utc($at['uploaded_datetime']); ?> by <?php echo $e($at['uploader']); ?></td>
        <td><code><?php echo $e($at['sha256'] ?? 'not recorded (uploaded before #180)'); ?></code></td></tr>
    <?php endforeach; ?>
</table>
</body>
</html>
