<?php
/* 🔴 NEVER OVER THE WEB - it writes to the database. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Importing a comment from Jira / Azure DevOps as a ticket note (GitHub #281).
 *
 * The import wrote the note with analyst_id 0, which the fk_notes_analysts
 * foreign key refuses - and every install created from freeitsm.sql has that
 * key - so imported comments never arrived. Worse, the comment-map row went in
 * first, so each refused comment was marked "already imported" and never retried.
 *
 * Checks: the note arrives with analyst_id NULL (System) and the map points at it;
 * a second import of the same comment is skipped; an import whose note is
 * refused leaves NO map row behind (positive control: the error still surfaces);
 * Database Verify turns a 0 author into NULL and clears a stuck map row.
 *
 *   FREEITSM_TEST_DATABASE=<a throwaway database> php tests/tracker-comment-import.php
 *
 * Runs only against the database named, which must be the one config.php uses.
 * Everything it creates is removed in `finally`.
 */

chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . "\n"; $ok ? $pass++ : $fail++; }

$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected) { echo "(skipped: set FREEITSM_TEST_DATABASE to a throwaway database)\n"; exit(0); }
require_once 'config.php';
require_once 'includes/functions.php';
require_once 'includes/integrations/integrations.php';
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(skipped: config.php does not use $expected)\n"; exit(0); }

$ticketId = (int)$conn->query("SELECT id FROM tickets ORDER BY id LIMIT 1")->fetchColumn();
if (!$ticketId) { check('there is a ticket to import onto', false); exit(1); }
$conn->exec("INSERT INTO integration_connections (name, base_url) VALUES ('ZZ test tracker #281', 'https://zz.invalid')");
$connId = (int)$conn->lastInsertId();
$conn->prepare("INSERT INTO integration_links (connection_id, entity_type, entity_id, external_id, external_key) VALUES (?, 'ticket', ?, 'zz-1', 'ZZ-1')")->execute([$connId, $ticketId]);
$linkId = (int)$conn->lastInsertId();
$conn->prepare("INSERT INTO integration_links (connection_id, entity_type, entity_id, external_id, external_key) VALUES (?, 'ticket', ?, 'zz-2', 'ZZ-2')")->execute([$connId, 2147480000]);
$badLinkId = (int)$conn->lastInsertId();
$connection = ['id' => $connId, 'account_identity' => null, 'name' => 'ZZ test tracker #281', 'provider' => 'jira'];
$link = $conn->query("SELECT * FROM integration_links WHERE id = $linkId")->fetch(PDO::FETCH_ASSOC);
$badLink = $conn->query("SELECT * FROM integration_links WHERE id = $badLinkId")->fetch(PDO::FETCH_ASSOC);
$event = fn(string $id) => ['type' => 'comment_added', 'comment_id' => $id, 'comment_body' => 'Fixed in build 42.', 'author_name' => 'A Developer', 'author_identity' => 'dev-1', 'external_id' => 'zz-1'];
$noteIds = [];

try {
    echo "Importing a comment\n";
    $r = null; $err = '';
    try { $r = integrationsApplyCommentEvent($conn, $connection, $link, $event('c-281-a')); } catch (Throwable $e) { $err = $e->getMessage(); }
    check('the comment is imported' . ($err ? " (error: $err)" : ''), $r === '');
    $map = $conn->query("SELECT * FROM integration_comment_map WHERE link_id = $linkId AND external_comment_id = 'c-281-a'")->fetch(PDO::FETCH_ASSOC);
    $note = $map && $map['local_note_id'] ? $conn->query("SELECT * FROM ticket_notes WHERE id = " . (int)$map['local_note_id'])->fetch(PDO::FETCH_ASSOC) : null;
    if ($note) $noteIds[] = (int)$note['id'];
    check('...as a note on the ticket, with the comment\'s text', $note && (int)$note['ticket_id'] === $ticketId && str_contains($note['note_text'], 'Fixed in build 42.'));
    check('...written by System (analyst_id NULL, not 0)', $note && $note['analyst_id'] === null);
    check('...and internal', $note && (int)$note['is_internal'] === 1);
    check('the same comment again is skipped as already imported', integrationsApplyCommentEvent($conn, $connection, $link, $event('c-281-a')) === 'already_imported');

    echo "\nA note that is refused\n";
    $thrown = false;
    try { integrationsApplyCommentEvent($conn, $connection, $badLink, $event('c-281-b')); } catch (Throwable) { $thrown = true; }
    check('the failure still surfaces, so the poll keeps its window (control)', $thrown);
    check('...and leaves NO map row, so the comment is tried again next time', (int)$conn->query("SELECT COUNT(*) FROM integration_comment_map WHERE link_id = $badLinkId")->fetchColumn() === 0);
    check('...and no transaction left open', !$conn->inTransaction());

    echo "\nDatabase Verify cleans up after the old bug\n";
    $conn->exec("SET FOREIGN_KEY_CHECKS = 0");
    $conn->prepare("INSERT INTO ticket_notes (ticket_id, analyst_id, note_text, is_internal, created_datetime) VALUES (?, 0, 'ZZ old import #281', 1, UTC_TIMESTAMP())")->execute([$ticketId]);
    $oldNote = (int)$conn->lastInsertId(); $noteIds[] = $oldNote;   // TRAP: before the SET below - any statement resets lastInsertId to 0
    $conn->exec("SET FOREIGN_KEY_CHECKS = 1");
    $conn->prepare("INSERT INTO integration_comment_map (link_id, direction, external_comment_id, created_datetime) VALUES (?, 'in', 'c-281-stuck', UTC_TIMESTAMP())")->execute([$linkId]);
    exec(escapeshellarg(PHP_BINARY) . ' scripts/db_verify_cli.php --apply 2>&1', $out, $code);
    check('Database Verify ran', $code === 0);
    check('an old note with author 0 is now System (NULL)', $conn->query("SELECT analyst_id FROM ticket_notes WHERE id = $oldNote")->fetchColumn() === null);
    check('a comment stuck as imported with no note can be collected again', (int)$conn->query("SELECT COUNT(*) FROM integration_comment_map WHERE external_comment_id = 'c-281-stuck'")->fetchColumn() === 0);
    check('...while a properly imported comment keeps its map row (control)', (int)$conn->query("SELECT COUNT(*) FROM integration_comment_map WHERE external_comment_id = 'c-281-a'")->fetchColumn() === 1);
    check('the column now allows NULL', $conn->query("SELECT IS_NULLABLE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ticket_notes' AND column_name = 'analyst_id'")->fetchColumn() === 'YES');
} finally {
    if ($conn->inTransaction()) $conn->rollBack();
    $conn->exec("DELETE FROM integration_comment_map WHERE link_id IN ($linkId, $badLinkId)");
    foreach ($noteIds as $id) $conn->exec("DELETE FROM ticket_notes WHERE id = $id");
    $conn->exec("DELETE FROM ticket_notes WHERE note_text = 'ZZ old import #281'");   // belt and braces: never leave the fake old note
    $conn->exec("DELETE FROM integration_links WHERE connection_id = $connId");
    $conn->exec("DELETE FROM integration_connections WHERE id = $connId");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
