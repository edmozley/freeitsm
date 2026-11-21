<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * War room - doing things with a message, piece 1 (wiki War-Room-Message-Actions-Design):
 * reactions, quotes, permanent links, and the poll re-sending a changed message.
 *
 * Run:  FREEITSM_TEST_DATABASE=<throwaway db> php tests/warroom-message-actions.php
 *
 * Everything happens in one transaction that is always rolled back.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/warroom.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}
/** The message with this id from a warRoomMessages() list, or null. */
function msgOf(array $list, int $id): ?array { foreach ($list as $m) if ($m['id'] === $id) return $m; return null; }

echo "\nWar room - message actions\n" . str_repeat('=', 70) . "\n";

$conn = connectToDatabase();
$expected = getenv('FREEITSM_TEST_DATABASE');
if (!$expected || $conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) {
    exit("Set FREEITSM_TEST_DATABASE to the throwaway database config.php points at - this test writes messages.\n");
}
try { $conn->query("SELECT 1 FROM warroom_reactions LIMIT 0"); $conn->query("SELECT quote_of_id FROM warroom_messages LIMIT 0"); }
catch (Throwable $e) { echo "  SKIP  run Database Verification first\n"; exit(0); }

$a = (int) $conn->query("SELECT id FROM analysts WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$b = (int) $conn->query("SELECT id FROM analysts WHERE is_active = 1 AND id <> $a ORDER BY id LIMIT 1")->fetchColumn();
$all = (int) $conn->query("SELECT id FROM warroom_channels WHERE kind = 'all' LIMIT 1")->fetchColumn();
if (!$a || !$b || !$all) { echo "  SKIP  needs two analysts and the Everyone channel\n"; exit(0); }

$conn->beginTransaction();
try {
    $THUMB = "\u{1F44D}"; $GRIN = "\u{1F600}"; $JOY = "\u{1F602}"; $HEART = "\u{2764}\u{FE0F}";

    $m1 = warRoomSend($conn, $a, $all, 'ZZ first message');
    $m2 = warRoomSend($conn, $b, $all, 'ZZ second message');
    ok('two messages posted', $m1 > 0 && $m2 > $m1);

    // ---- reactions
    ok('a reaction from the quick list is accepted', warRoomReact($conn, $a, $m1, $THUMB, true));
    ok('a two-codepoint emoji (red heart) is accepted', warRoomReact($conn, $b, $m1, $HEART, true));
    $r = msgOf(warRoomMessages($conn, $all, 0, 200, $a), $m1)['reactions'] ?? [];
    ok('the message carries its reactions, in the order given', array_column($r, 'emoji') === [$THUMB, $HEART], json_encode($r));
    ok("'mine' is the viewer's own reaction", $r[0]['mine'] === true && $r[1]['mine'] === false);
    $rb = msgOf(warRoomMessages($conn, $all, 0, 200, $b), $m1)['reactions'] ?? [];
    ok("...and another viewer sees it the other way round", $rb[0]['mine'] === false && $rb[1]['mine'] === true);
    ok('names come with each reaction, for the hover', count($r[0]['names']) === 1 && $r[0]['names'][0] !== '');

    warRoomReact($conn, $a, $m1, $THUMB, true);
    $r = msgOf(warRoomMessages($conn, $all, 0, 200, $a), $m1)['reactions'];
    ok('reacting twice with the same emoji still counts once', $r[0]['count'] === 1);

    // TRAP control: unicode_ci weighs many emoji the same; the column is utf8mb4_bin.
    ok('two different emoji from one person are both kept (collation)',
       warRoomReact($conn, $a, $m2, $GRIN, true) && warRoomReact($conn, $a, $m2, $JOY, true)
       && count(msgOf(warRoomMessages($conn, $all, 0, 200, $a), $m2)['reactions']) === 2);

    warRoomReact($conn, $b, $m1, $THUMB, true);
    $r = msgOf(warRoomMessages($conn, $all, 0, 200, $a), $m1)['reactions'];
    ok('two people with the same emoji count 2', $r[0]['count'] === 2 && count($r[0]['names']) === 2);
    warRoomReact($conn, $a, $m1, $THUMB, false);
    $r = msgOf(warRoomMessages($conn, $all, 0, 200, $a), $m1)['reactions'];
    ok('taking a reaction away removes only yours', $r[0]['count'] === 1 && $r[0]['mine'] === false);

    ok('text that is not an emoji is refused', !warRoomReact($conn, $a, $m1, 'x', true));
    ok('markup is refused', !warRoomReact($conn, $a, $m1, '<img src=x>', true));
    ok('an emoji not in the list is refused (two emoji glued together)', !warRoomReact($conn, $a, $m1, $THUMB . $THUMB, true));
    ok('a message that does not exist is refused', !warRoomReact($conn, $a, 999999999, $THUMB, true));

    // A private channel the other analyst is not in.
    $priv = warRoomCreateChannel($conn, $a, 'ZZ private ' . bin2hex(random_bytes(3)), '', true);
    $pm = warRoomSend($conn, $a, $priv, 'ZZ secret');
    ok('nobody can react in a channel they cannot post in', $pm > 0 && !warRoomReact($conn, $b, $pm, $THUMB, true));

    $m3 = warRoomSend($conn, $a, $all, 'ZZ to delete');
    warRoomDeleteMessage($conn, $a, $m3);
    ok('a deleted message cannot be reacted to', !warRoomReact($conn, $a, $m3, $THUMB, true));

    // ---- the poll re-sends a message whose reactions changed
    $m4 = warRoomSend($conn, $a, $all, 'ZZ watched');
    $m5 = warRoomSend($conn, $a, $all, 'ZZ after');
    $before = array_column(warRoomMessages($conn, $all, $m5, 200, $a), 'id');
    ok('control: an untouched older message is not re-sent', !in_array($m4, $before, true), json_encode($before));
    warRoomReact($conn, $b, $m4, $THUMB, true);
    $after = warRoomMessages($conn, $all, $m5, 200, $a);
    ok('after a reaction the poll re-sends it, with the reaction',
       msgOf($after, $m4) !== null && (msgOf($after, $m4)['reactions'][0]['emoji'] ?? '') === $THUMB);

    // ---- quotes
    $q1 = warRoomSend($conn, $b, $all, 'ZZ replying', $m1);
    $q = msgOf(warRoomMessages($conn, $all, 0, 200, $a), $q1)['quote'];
    ok('a reply carries what it quotes: author, time, text', $q && $q['id'] === $m1 && !$q['gone'] && !$q['deleted'] && $q['text'] === 'ZZ first message' && $q['author'] !== '');
    $q2 = warRoomSend($conn, $b, $all, 'ZZ quoting elsewhere', $pm);
    ok('a quote of a message in another channel is dropped, not shown', msgOf(warRoomMessages($conn, $all, 0, 200, $a), $q2)['quote'] === null);
    $q3 = warRoomSend($conn, $b, $all, 'ZZ quoting a deleted one', $m3);
    ok('a deleted message cannot be quoted', msgOf(warRoomMessages($conn, $all, 0, 200, $a), $q3)['quote'] === null);
    warRoomDeleteMessage($conn, $a, $m1);
    $q = msgOf(warRoomMessages($conn, $all, 0, 200, $a), $q1)['quote'];
    ok('when the original is deleted afterwards, the quote says so', $q['deleted'] === true && $q['text'] === '');
    $conn->prepare("DELETE FROM warroom_messages WHERE id = ?")->execute([$m2]);   // as retention does
    $q4 = warRoomSend($conn, $a, $all, 'ZZ quoting a live one', $m4);
    $conn->prepare("UPDATE warroom_messages SET quote_of_id = ? WHERE id = ?")->execute([$m2, $q4]);
    $q = msgOf(warRoomMessages($conn, $all, 0, 200, $a), $q4)['quote'];
    ok('when the original is gone (retention), the quote says it is no longer available', $q['gone'] === true);

    // ---- permanent links
    ok('a link opens the channel of a message you can read', warRoomChannelOfMessage($conn, $b, $m4) === $all);
    ok('a link to a channel you cannot read gives nothing', warRoomChannelOfMessage($conn, $b, $pm) === 0);
    ok('a link to a message that is gone gives nothing', warRoomChannelOfMessage($conn, $a, $m2) === 0);
    $around = array_column(warRoomMessages($conn, $all, 0, 200, $a, $m4), 'id');
    ok('loading around a message includes it and everything after it', in_array($m4, $around, true) && in_array($m5, $around, true) && in_array($q4, $around, true));
} finally {
    $conn->rollBack();
}

echo str_repeat('-', 70) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
