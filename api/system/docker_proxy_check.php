<?php
/**
 * System -> Docker -> Behind a reverse proxy -> Test (GH #410).
 *
 * Answers one question about the request that called it: which address did FreeITSM
 * record, and what does that mean for the TRUSTED_PROXIES setting? Read-only - it
 * changes nothing; the setting lives in docker-compose.yml (includes/docker_proxy.php).
 *
 * Administrators only: it shows the forwarding headers this request carried, which
 * name addresses on the network.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only
require_once '../../includes/storage_persistence.php';
require_once '../../includes/docker_proxy.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}
if (!storagePersistenceInContainer()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'This only works when FreeITSM runs in its Docker image.']);
    exit;
}

$state = dockerProxyState();
echo json_encode(['success' => true, 'state' => $state['state'], 'header' => $state['header']] + dockerProxyCheck($state, $_SERVER));
