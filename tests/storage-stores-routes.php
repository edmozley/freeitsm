<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Every store (#284) through the pages people use: a file is put into the store - on a
 * server folder, then in S3 - and fetched back over HTTP from the endpoint that serves it:
 * ticket attachments, change attachments, documents, screen recordings (ranges) and
 * war-room attachments.
 *
 * Writes ZZ rows (removed at the end) and storage_* settings (put back). Needs
 * FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL (a web server on that database); the S3
 * half needs FREEITSM_TEST_S3_ENDPOINT/KEY/SECRET/BUCKET and is skipped without them.
 * Run as the web server's user (both write the same folders).
 */

$root = dirname(__DIR__);
require_once "$root/config.php";
require_once "$root/includes/functions.php";
require_once "$root/includes/storage/stores.php";

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-70s\n", $label); }
    else       { $fail++; printf("  FAIL %-70s %s\n", $label, $detail); }
}
function section(string $t): void { echo "\n$t\n" . str_repeat('-', 72) . "\n"; }

echo "\nStores over HTTP\n" . str_repeat('=', 72) . "\n";
$conn = connectToDatabase();
$dbName = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
if (getenv('FREEITSM_TEST_DATABASE') !== $dbName || !getenv('FREEITSM_TEST_URL')) {
    echo "\nSKIPPED: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL.\n\n$pass passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/') . '/';
$sessDir = rtrim(ini_get('session.save_path') ?: sys_get_temp_dir(), '/\\');
$admin = (int)$conn->query('SELECT id FROM analysts WHERE is_admin = 1 ORDER BY id LIMIT 1')->fetchColumn();
$sid = bin2hex(random_bytes(16));
file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $admin . ';is_admin|i:1;');

$get = function (string $path, array $headers = []) use ($base, $sid): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60,
                            CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_HTTPHEADER => $headers]);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [$code, substr($raw, $hs), substr($raw, 0, $hs)];
};

$snap = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'storage\\_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$cleanup = [];
$s3 = getenv('FREEITSM_TEST_S3_ENDPOINT') ? [
    'endpoint' => (string)getenv('FREEITSM_TEST_S3_ENDPOINT'), 'key' => (string)getenv('FREEITSM_TEST_S3_KEY'),
    'secret' => (string)getenv('FREEITSM_TEST_S3_SECRET'), 'bucket' => (string)getenv('FREEITSM_TEST_S3_BUCKET'),
] : null;
$prefix = 'zz-routes-' . bin2hex(random_bytes(3)) . '/';

/** Points every store at $where ('local' or 's3') for this process and the web server. */
$useLocation = function (string $where) use ($conn, $s3, $prefix) {
    $conn->exec("DELETE FROM system_settings WHERE setting_key LIKE 'storage\\_%'");
    storeReset();
    if ($where === 's3') {
        foreach (['endpoint' => $s3['endpoint'], 'region' => 'us-east-1', 'bucket' => $s3['bucket'], 'access_key_id' => $s3['key'],
                  'secret' => encryptValue($s3['secret']), 'prefix' => $prefix, 'path_style' => '1'] as $k => $v) {
            storeSaveSetting($conn, "storage_s3_$k", $v);
        }
        foreach (array_keys(STORAGE_STORES) as $st) storeSaveSetting($conn, "storage_{$st}_location", 's3');
    }
    storeReset();
};
/** Puts $bytes into $store under $key (through the layer, as a restore or a move would). */
$put = function (string $store, string $key, string $bytes) use ($conn, &$cleanup) {
    $tmp = tempnam(sys_get_temp_dir(), 'zzr');
    file_put_contents($tmp, $bytes);
    storePutFile($conn, $store, $key, $tmp, true);
    $cleanup[] = fn() => storeDelete($conn, $store, $key);
};

try {
    // Fixtures: a ticket with an email, a change, a document on the ticket, the all-hands war room.
    $conn->prepare("INSERT INTO tickets (ticket_number, subject, created_datetime) VALUES (?, 'ZZ stores routes', UTC_TIMESTAMP())")
         ->execute(['ZZST-' . bin2hex(random_bytes(3))]);
    $ticket = (int)$conn->lastInsertId();
    $cleanup[] = fn() => $conn->prepare('DELETE FROM tickets WHERE id = ?')->execute([$ticket]);
    $conn->prepare("INSERT INTO emails (ticket_id, subject, received_datetime) VALUES (?, 'ZZ', UTC_TIMESTAMP())")->execute([$ticket]);
    $email = (int)$conn->lastInsertId();
    $cleanup[] = fn() => $conn->prepare('DELETE FROM emails WHERE id = ?')->execute([$email]);
    $conn->prepare("INSERT INTO changes (title) VALUES ('ZZ stores routes')")->execute();
    $change = (int)$conn->lastInsertId();
    $cleanup[] = fn() => $conn->prepare('DELETE FROM changes WHERE id = ?')->execute([$change]);
    $conn->exec("INSERT INTO warroom_channels (kind, created_datetime) SELECT 'all', UTC_TIMESTAMP() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM warroom_channels WHERE kind = 'all')");
    $channel = (int)$conn->query("SELECT id FROM warroom_channels WHERE kind = 'all' LIMIT 1")->fetchColumn();
    $conn->prepare("INSERT INTO warroom_messages (channel_id, analyst_id, body) VALUES (?, ?, 'ZZ stores routes')")->execute([$channel, $admin]);
    $message = (int)$conn->lastInsertId();
    $cleanup[] = fn() => $conn->prepare('DELETE FROM warroom_messages WHERE id = ?')->execute([$message]);

    foreach (['local', 's3'] as $where) {
        section($where === 'local' ? 'Every store on a server folder' : 'Every store in S3');
        if ($where === 's3' && $s3 === null) { echo "  SKIP (no FREEITSM_TEST_S3_* settings)\n"; continue; }
        $useLocation($where);
        $tag = bin2hex(random_bytes(4));

        // Ticket attachment: {floor(id/1000)}/{id}/{name}, a name with a space and an accent.
        $tKey = intdiv($email, 1000) . "/$email/zz rapport é-$tag.pdf";
        $tBytes = "%PDF-1.4 ticket $where $tag";
        $put('tickets', $tKey, $tBytes);
        $conn->prepare("INSERT INTO email_attachments (email_id, filename, content_type, file_path, file_size) VALUES (?, ?, 'application/pdf', ?, ?)")
             ->execute([$email, "Rapport é $tag.pdf", $tKey, strlen($tBytes)]);
        $att = (int)$conn->lastInsertId();
        [$code, $body] = $get("api/tickets/get_attachment.php?id=$att");
        ok('ticket attachment: downloaded', $code === 200 && $body === $tBytes, "$code " . substr($body, 0, 80));

        // Change attachment: {changeId}/{name}.
        $cKey = "$change/$tag.txt";
        $cBytes = "change $where $tag";
        $put('changes', $cKey, $cBytes);
        $conn->prepare("INSERT INTO change_attachments (change_id, file_name, file_path, file_type, file_size) VALUES (?, ?, ?, 'text/plain', ?)")
             ->execute([$change, "c-$tag.txt", $cKey, strlen($cBytes)]);
        $catt = (int)$conn->lastInsertId();
        [$code, $body] = $get("api/change-management/get_attachment.php?id=$catt");
        ok('change attachment: downloaded', $code === 200 && $body === $cBytes, "$code " . substr($body, 0, 80));

        // Document on the ticket: a flat key.
        $dKey = "$tag.pdf";
        $dBytes = "%PDF-1.4 document $where $tag";
        $put('documents', $dKey, $dBytes);
        $conn->prepare("INSERT INTO documents (kind, title, storage_key, original_name, mime_type, size_bytes) VALUES ('file', ?, ?, ?, 'application/pdf', ?)")
             ->execute(["ZZ doc $tag", $dKey, "doc-$tag.pdf", strlen($dBytes)]);
        $doc = (int)$conn->lastInsertId();
        $cleanup[] = fn() => $conn->prepare('DELETE FROM documents WHERE id = ?')->execute([$doc]);
        $conn->prepare("INSERT INTO document_links (document_id, parent_type, parent_id) VALUES (?, 'ticket', ?)")->execute([$doc, $ticket]);
        [$code, $body] = $get("api/documents/download.php?id=$doc");
        ok('document: downloaded', $code === 200 && $body === $dBytes, "$code " . substr($body, 0, 80));

        // Recording: the row holds "recordings/<ticket>/<name>".
        $rName = "$tag.webm";
        $rBytes = str_repeat("webm-$tag-", 50);
        $put('recordings', "$ticket/$rName", $rBytes);
        $conn->prepare("INSERT INTO ticket_recordings (ticket_id, filename, content_type, file_path, file_size) VALUES (?, ?, 'video/webm', ?, ?)")
             ->execute([$ticket, $rName, "recordings/$ticket/$rName", strlen($rBytes)]);
        $rec = (int)$conn->lastInsertId();
        [$code, $body] = $get("api/self-service/get_recording.php?id=$rec");
        ok('recording: played in full', $code === 200 && $body === $rBytes, "$code " . strlen($body));
        [$code, $body, $hdr] = $get("api/self-service/get_recording.php?id=$rec", ['Range: bytes=5-14']);
        ok('recording: a range (206, Content-Range)', $code === 206 && $body === substr($rBytes, 5, 10)
           && stripos($hdr, 'Content-Range: bytes 5-14/' . strlen($rBytes)) !== false, "$code $body");
        [$code] = $get("api/self-service/get_recording.php?id=$rec", ['Range: bytes=99999-']);
        ok('recording: a range past the end (416)', $code === 416, (string)$code);

        // War-room attachment: a flat stored_name.
        $wKey = "$tag.txt";
        $wBytes = "war room $where $tag";
        $put('warroom', $wKey, $wBytes);
        $conn->prepare("INSERT INTO warroom_attachments (message_id, stored_name, original_name, size_bytes) VALUES (?, ?, ?, ?)")
             ->execute([$message, $wKey, "w-$tag.txt", strlen($wBytes)]);
        $watt = (int)$conn->lastInsertId();
        [$code, $body] = $get("api/war-room/attachment.php?id=$watt");
        ok('war-room attachment: downloaded', $code === 200 && $body === $wBytes, "$code " . substr($body, 0, 80));

        // A pending recording claimed by its ticket moves within the store.
        require_once "$root/includes/ticket_recordings.php";
        $conn->prepare("INSERT INTO users (email, display_name) VALUES (?, 'ZZ stores routes')")->execute(['zz-st-' . $tag . '@example.invalid']);
        $portalUser = (int)$conn->lastInsertId();
        $cleanup[] = fn() => $conn->prepare('DELETE FROM users WHERE id = ?')->execute([$portalUser]);
        if ($portalUser > 0) {
            $pName = "p-$tag.webm";
            $put('recordings', "pending/$pName", 'pending bytes ' . $tag);
            $conn->prepare("INSERT INTO ticket_recordings (ticket_id, recorded_by_user_id, filename, content_type, file_path, file_size) VALUES (NULL, ?, ?, 'video/webm', ?, 20)")
                 ->execute([$portalUser, $pName, "recordings/pending/$pName"]);
            $pRec = (int)$conn->lastInsertId();
            $cleanup[] = fn() => storeDelete($conn, 'recordings', "$ticket/$pName");
            $n = claimPendingRecordings($conn, [$pRec], $ticket, $portalUser);
            $row = $conn->query("SELECT file_path FROM ticket_recordings WHERE id = $pRec")->fetchColumn();
            ok('recording claimed: moved to its ticket in the store, row updated', $n === 1 && $row === "recordings/$ticket/$pName"
               && storeSize($conn, 'recordings', "$ticket/$pName") !== null && storeSize($conn, 'recordings', "pending/$pName") === null, (string)$row);
        } else {
            echo "  SKIP recording claim (no portal user to own it)\n";
        }

        // Deleting a war-room message removes its file from the store.
        require_once "$root/includes/warroom.php";
        $conn->prepare("INSERT INTO warroom_messages (channel_id, analyst_id, body) VALUES (?, ?, 'ZZ to delete')")->execute([$channel, $admin]);
        $delMsg = (int)$conn->lastInsertId();
        $cleanup[] = fn() => $conn->prepare('DELETE FROM warroom_messages WHERE id = ?')->execute([$delMsg]);
        $put('warroom', "del-$tag.txt", 'to be deleted');
        $conn->prepare("INSERT INTO warroom_attachments (message_id, stored_name, original_name, size_bytes) VALUES (?, ?, 'del.txt', 13)")
             ->execute([$delMsg, "del-$tag.txt"]);
        ok('war-room message deleted: its file is gone from the store', warRoomDeleteMessage($conn, $admin, $delMsg, true)
           && storeSize($conn, 'warroom', "del-$tag.txt") === null);

        // A row whose bytes are gone answers as before: not a 500.
        storeDelete($conn, 'warroom', $wKey);
        [$code] = $get("api/war-room/attachment.php?id=$watt");
        ok('war-room attachment gone from storage: 404 (as before)', $code === 404, (string)$code);
        storeDelete($conn, 'documents', $dKey);
        [$code] = $get("api/documents/download.php?id=$doc");
        ok('document gone from storage: 410 (as before)', $code === 410, (string)$code);
    }
} catch (Throwable $e) {
    ok('no unexpected error', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    foreach (array_reverse($cleanup) as $c) { try { $c(); } catch (Throwable $ignored) {} }
    $conn->exec("DELETE FROM system_settings WHERE setting_key LIKE 'storage\\_%'");
    $i = $conn->prepare('INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)');
    foreach ($snap as $k => $v) $i->execute([$k, $v]);
    @unlink("$sessDir/sess_$sid");
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
