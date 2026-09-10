<?php
/**
 * API Endpoint: create or update a MANUALLY added application (#1549).
 *
 * POST { id?, display_name, publisher?, app_url?, notes? }
 *
 * Cloud platforms — Xero, Canva, Figma, Dropbox — have nothing to install, so
 * nothing discovers them and until now they could not be recorded at all. That
 * also put the whole of `software_licences` out of reach for SaaS, because its
 * `app_id` is NOT NULL: a renewal date, a seat count and a cost had nowhere to
 * hang. This is the front door for both.
 *
 * ---------------------------------------------------------------------------
 * ONLY MANUAL ROWS ARE EDITABLE HERE
 * ---------------------------------------------------------------------------
 * An agent-discovered application is a REPORT of what is installed on somebody's
 * machine. Editing its name here would make the record disagree with the thing
 * it describes, and the next inventory run would overwrite the edit anyway — so
 * the screen would appear to accept a change that silently reverted. Refused
 * with a reason instead.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('software');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) throw new Exception('Invalid request');

    $id        = !empty($data['id']) ? (int) $data['id'] : null;
    $name      = trim((string) ($data['display_name'] ?? ''));
    $publisher = trim((string) ($data['publisher'] ?? ''));
    $appUrl    = trim((string) ($data['app_url'] ?? ''));
    $notes     = trim((string) ($data['notes'] ?? ''));

    if ($name === '')            throw new Exception('Name is required');
    if (mb_strlen($name) > 512)  throw new Exception('Name is too long (512 characters maximum)');
    if (mb_strlen($publisher) > 512) throw new Exception('Publisher is too long (512 characters maximum)');
    if (mb_strlen($appUrl) > 500)    throw new Exception('Web address is too long (500 characters maximum)');

    // A URL is offered as "where you go to administer it", so it has to be one
    // a browser will actually follow. A bare "xero.com" becomes a relative link
    // that resolves against FreeITSM's own host and 404s.
    if ($appUrl !== '') {
        if (!preg_match('#^https?://#i', $appUrl)) $appUrl = 'https://' . $appUrl;
        if (!filter_var($appUrl, FILTER_VALIDATE_URL)) throw new Exception('That web address does not look valid');
    }

    $publisher = $publisher === '' ? null : $publisher;
    $notes     = $notes === '' ? null : $notes;
    $appUrl    = $appUrl === '' ? null : $appUrl;

    $conn      = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];

    // ⚠️ Uniqueness is enforced here as well as by ux_app_display_publisher,
    // because that key is on PREFIXES (display_name(400), publisher(360)) and
    // MySQL allows unlimited NULLs in it — two apps both named "Xero" with no
    // publisher would slip straight through. It is also the only way to give
    // the reason in words rather than as a duplicate-key error.
    $clashSql = "SELECT id, source FROM software_inventory_apps
                  WHERE LOWER(display_name) = LOWER(?)
                    AND " . ($publisher === null ? "publisher IS NULL" : "LOWER(publisher) = LOWER(?)");
    $params = [$name];
    if ($publisher !== null) $params[] = $publisher;
    if ($id) { $clashSql .= " AND id <> ?"; $params[] = $id; }
    $cs = $conn->prepare($clashSql);
    $cs->execute($params);
    if ($clash = $cs->fetch(PDO::FETCH_ASSOC)) {
        throw new Exception($clash['source'] === 'manual'
            ? 'You have already added an application with that name and publisher'
            : 'The inventory agent has already found an application with that name — add the licence to that one instead of creating a second copy.');
    }

    if ($id) {
        $cur = $conn->prepare("SELECT source FROM software_inventory_apps WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Application not found');
        if (($row['source'] ?? 'agent') !== 'manual') {
            throw new Exception('That application was found by the inventory agent, so its details come from the machines it is installed on and cannot be edited here.');
        }

        $conn->prepare(
            "UPDATE software_inventory_apps SET display_name = ?, publisher = ?, app_url = ?, notes = ? WHERE id = ?"
        )->execute([$name, $publisher, $appUrl, $notes, $id]);
        $savedId = $id;
    } else {
        $conn->prepare(
            "INSERT INTO software_inventory_apps (display_name, publisher, app_url, notes, source, created_by, first_detected)
             VALUES (?, ?, ?, ?, 'manual', ?, UTC_TIMESTAMP())"
        )->execute([$name, $publisher, $appUrl, $notes, $analystId]);
        $savedId = (int) $conn->lastInsertId();
    }

    wf_emit('software_app', $id ? 'updated' : 'created', $savedId, $name);
    echo json_encode(['success' => true, 'id' => $savedId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
