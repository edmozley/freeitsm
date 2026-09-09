<?php
/**
 * LMS API: everything a course can be assigned TO, as one flat list.
 *
 * 🔑 ONE PICKER, NOT A TYPE-THEN-THING PAIR. A manager knows they want "Finance"
 * — they do not want to first declare whether Finance is a learning group or a
 * people group, which is an implementation detail of where the members are
 * stored. Same reasoning as the Knowledge permission picker, which searches four
 * principal tables at once for exactly this reason.
 *
 * Each row carries its `type` back, so the caller returns the pair the
 * assignment table actually stores without having to work it out.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/lms_access.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireCapabilityJson(Cap::LMS_MANAGE);

try {
    $conn = connectToDatabase();
    $out  = [];

    // Everyone on the portal. First, because "push this to all our staff" is the
    // thing people come to this screen wanting to do, and burying it under a list
    // of groups makes it look unsupported.
    $out[] = [
        'type'         => 'all_users',
        'id'           => 0,
        'name'         => 'Everyone on the portal',
        'kind'         => 'Portal',
        'member_count' => (int)$conn->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn(),
    ];

    $st = $conn->query("SELECT g.id, g.name,
                               (SELECT COUNT(*) FROM lms_learning_group_members m WHERE m.group_id = g.id) AS member_count
                          FROM lms_learning_groups g
                         WHERE g.is_active = 1
                      ORDER BY g.name");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $out[] = ['type' => 'learning_group', 'id' => (int)$g['id'], 'name' => $g['name'],
                  'kind' => 'Learning group', 'member_count' => (int)$g['member_count']];
    }

    if (lmsUserGroupsAvailable($conn)) {
        // ⚠️ The count excludes lapsed memberships, because they are not people
        // the course will reach — showing "12 members" beside a group that will
        // deliver to nine is how a manager concludes the push half-failed.
        $st = $conn->query("SELECT g.id, g.name,
                                   (SELECT COUNT(*) FROM knowledge_user_group_members m
                                     WHERE m.group_id = g.id
                                       AND (m.expires_at IS NULL OR m.expires_at > UTC_TIMESTAMP())) AS member_count
                              FROM knowledge_user_groups g
                             WHERE g.is_active = 1
                          ORDER BY g.name");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $out[] = ['type' => 'user_group', 'id' => (int)$g['id'], 'name' => $g['name'],
                      'kind' => 'People group', 'member_count' => (int)$g['member_count']];
        }
    }

    echo json_encode(['success' => true, 'targets' => $out]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
