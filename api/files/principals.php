<?php
/**
 * GET ?q= - analysts and teams to add in the Permissions window. Active only.
 * Analysts are listed regardless of whether they can open Files: a grant for
 * someone without the module simply does nothing until they are given it.
 */
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';

filesApiRun(function () use ($conn) {
    $q = trim((string)($_GET['q'] ?? ''));
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

    $st = $conn->prepare("SELECT id, name FROM teams WHERE COALESCE(is_active, 1) = 1 AND name LIKE ? ORDER BY name LIMIT 20");
    $st->execute([$like]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['principal_type' => 'team', 'principal_id' => (int)$r['id'], 'name' => $r['name']];
    }
    $st = $conn->prepare("SELECT id, full_name, username FROM analysts WHERE is_active = 1 AND (full_name LIKE ? OR username LIKE ?) ORDER BY full_name LIMIT 30");
    $st->execute([$like, $like]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['principal_type' => 'analyst', 'principal_id' => (int)$r['id'], 'name' => $r['full_name'] ?: $r['username']];
    }
    filesApiOk(['principals' => $out]);
});
