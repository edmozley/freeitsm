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

    // ---- piece 2: pins
    ok('anyone who can post may pin', warRoomPin($conn, $b, $m4, true) === 'ok');
    $pm4 = msgOf(warRoomMessages($conn, $all, 0, 200, $a), $m4)['pinned'];
    ok('the message says who pinned it and when', $pm4 && $pm4['by'] !== '' && $pm4['at'] !== '');
    ok('the channel counts its pins', warRoomPinCount($conn, $all) === 1);
    ok('pinning twice still counts once', warRoomPin($conn, $a, $m4, true) === 'ok' && warRoomPinCount($conn, $all) === 1);
    $list = warRoomPins($conn, $all);
    ok('the pinned list has it, with the text', count($list) === 1 && $list[0]['id'] === $m4 && $list[0]['text'] === 'ZZ watched');
    ok('nobody can pin in a channel they cannot post in', warRoomPin($conn, $b, $pm, true) === 'no');
    $after2 = warRoomMessages($conn, $all, $q4, 200, $a);
    ok('a pin makes the poll re-send the message', msgOf($after2, $m4) !== null);
    // The limit: fill the private channel to WARROOM_MAX_PINS, then one more.
    $ids = [];
    for ($i = 0; $i < WARROOM_MAX_PINS; $i++) { $id = warRoomSend($conn, $a, $priv, "ZZ pin $i"); warRoomPin($conn, $a, $id, true); $ids[] = $id; }
    $extra = warRoomSend($conn, $a, $priv, 'ZZ one too many');
    ok('past ' . WARROOM_MAX_PINS . ' pins a conversation refuses another', warRoomPin($conn, $a, $extra, true) === 'full');
    ok('...but re-pinning one already pinned is fine', warRoomPin($conn, $a, $ids[0], true) === 'ok');
    ok('unpinning works', warRoomPin($conn, $a, $m4, false) === 'ok' && warRoomPinCount($conn, $all) === 0);

    // ---- saved
    ok('saving a message you can read works', warRoomSave($conn, $b, $m5, true));
    ok("'saved' is the viewer's own", msgOf(warRoomMessages($conn, $all, 0, 200, $b), $m5)['saved'] === true
        && msgOf(warRoomMessages($conn, $all, 0, 200, $a), $m5)['saved'] === false);
    $sv = warRoomSavedList($conn, $b);
    ok('the saved list names the conversation', count($sv) === 1 && $sv[0]['id'] === $m5 && $sv[0]['channel'] !== '');
    ok('you cannot save a message you cannot read', !warRoomSave($conn, $b, $pm, true));
    warRoomSave($conn, $a, $pm, true);
    ok('saved messages show only from conversations you can read', count(warRoomSavedList($conn, $a)) === 1);
    $conn->prepare("DELETE FROM warroom_channel_members WHERE channel_id = ? AND analyst_id = ?")->execute([$priv, $a]);
    ok('...and leaving the conversation hides them', !warRoomCanAccessChannel($conn, $a, $priv) && count(warRoomSavedList($conn, $a)) === 0);
    ok('taking it off works', warRoomSave($conn, $b, $m5, false) && count(warRoomSavedList($conn, $b)) === 0);

    // ---- deleting removes pins and saves
    $m6 = warRoomSend($conn, $a, $all, 'ZZ pin then delete');
    warRoomPin($conn, $a, $m6, true); warRoomSave($conn, $b, $m6, true);
    warRoomDeleteMessage($conn, $a, $m6);
    ok('deleting a message unpins it and takes it off saved lists', warRoomPinCount($conn, $all) === 0 && count(warRoomSavedList($conn, $b)) === 0);

    // ---- mark as unread
    warRoomMarkRead($conn, $b, $all, $m6);
    $unreadOf = function () use ($conn, $b, $all) { foreach (warRoomChannelList($conn, $b) as $c) if ($c['id'] === $all) return $c['unread']; return -1; };
    ok('control: read to the end, nothing is unread', $unreadOf() === 0);
    ok('mark as unread moves the marker back', warRoomMarkUnread($conn, $b, $m4));
    $last = (int) $conn->query("SELECT last_read_message_id FROM warroom_reads WHERE analyst_id = $b AND channel_id = $all")->fetchColumn();
    ok('...to the message just before it', $last > 0 && $last < $m4 && (int) $conn->query("SELECT COUNT(*) FROM warroom_messages WHERE channel_id = $all AND id > $last AND id < $m4")->fetchColumn() === 0);
    ok('...so the channel shows as unread from there', $unreadOf() > 0);
    ok('you cannot mark a message in a channel you cannot read', !warRoomMarkUnread($conn, $b, $pm));

    // ---- piece 3: threads
    $root = warRoomSend($conn, $a, $all, 'ZZ thread root');
    $mid  = warRoomSend($conn, $a, $all, 'ZZ after the root');
    $t1 = warRoomSend($conn, $b, $all, 'ZZ first reply', 0, $root);
    ok('a thread reply gets a real id (TRAP: the reply-count update runs after the id is read)', $t1 > $mid);
    ok('it is stored against its first message', warRoomThreadRootOf($conn, $t1) === $root);
    $main = warRoomMessages($conn, $all, 0, 200, $a);
    ok('a thread reply is not in the conversation', msgOf($main, $t1) === null);
    $r0 = msgOf($main, $root);
    ok('the first message says it has 1 reply, and when', ($r0['thread']['count'] ?? 0) === 1 && !empty($r0['thread']['last']));
    $t2 = warRoomSend($conn, $a, $all, 'ZZ reply to the reply', 0, $t1);
    ok('replying to a reply joins the same thread (one level)', warRoomThreadRootOf($conn, $t2) === $root);
    $t3 = warRoomSend($conn, $b, $all, 'ZZ also for everyone', 0, $root, true);
    $main = warRoomMessages($conn, $all, 0, 200, $a);
    ok('a reply also sent to the conversation appears there, marked as from a thread',
       ($x = msgOf($main, $t3)) !== null && $x['thread_root'] === $root && $x['in_channel'] === true);
    $th = warRoomMessages($conn, $all, 0, 500, $a, 0, '', $root);
    ok('the thread is its first message and every reply, in order', array_column($th, 'id') === [$root, $t1, $t2, $t3], json_encode(array_column($th, 'id')));
    ok('...and nothing else from the conversation', msgOf($th, $mid) === null);
    $poll = warRoomMessages($conn, $all, $t3, 200, $a);
    ok('a reply makes the poll re-send the first message with the new count', ($p = msgOf($poll, $root)) !== null && $p['thread']['count'] === 3);
    $thSince = warRoomMessages($conn, $all, $t2, 500, $a, 0, '', $root);
    ok('the open thread polls from its own watermark', array_column($thSince, 'id') === [$root, $t3] || array_column($thSince, 'id') === [$t3], json_encode(array_column($thSince, 'id')));
    $other = warRoomSend($conn, $a, $all, 'ZZ not a thread', 0, $pm);
    ok('a "thread" on a message in another channel is posted as an ordinary message', warRoomThreadRootOf($conn, $other) === 0);
    // Unread: a thread-only reply does not make the conversation unread; one also sent does.
    warRoomMarkRead($conn, $b, $all, $other);
    $u0 = $unreadOf();
    warRoomSend($conn, $a, $all, 'ZZ quiet thread reply', 0, $root);
    ok('a thread-only reply does not count as unread in the conversation', $unreadOf() === $u0);
    warRoomSend($conn, $a, $all, 'ZZ loud thread reply', 0, $root, true);
    ok('...a reply also sent to it does', $unreadOf() === $u0 + 1);

    // ---- piece 4: forward
    $priv2 = warRoomCreateChannel($conn, $a, 'ZZ fwd ' . bin2hex(random_bytes(3)), '', true);   // only $a is in it
    $orig = warRoomSend($conn, $a, $all, 'ZZ @' . explode(' ', (string) $conn->query("SELECT full_name FROM analysts WHERE id = $b")->fetchColumn())[0] . ' please check srv-db01');
    $mentionsBefore = (int) $conn->query("SELECT COUNT(*) FROM warroom_mentions")->fetchColumn();
    // a real file on the original, to prove it is copied
    $dir = rtrim(WARROOM_ATTACH_DIR, '/\\') . DIRECTORY_SEPARATOR;
    $srcName = 'zz' . bin2hex(random_bytes(8)) . '.txt';
    file_put_contents($dir . $srcName, 'ZZ attachment bytes');
    $conn->prepare("INSERT INTO warroom_attachments (message_id, stored_name, original_name, size_bytes) VALUES (?, ?, 'note.txt', 19)")->execute([$orig, $srcName]);
    $copies = [];
    try {
        $fw = warRoomForward($conn, $a, $orig, $priv2, 'ZZ FYI');
        ok('a message can be forwarded to a conversation you can post in', $fw > 0);
        $fr = msgOf(warRoomMessages($conn, $priv2, 0, 200, $a), $fw);
        ok('the forward carries the text and where it came from', $fr && str_contains($fr['body'], 'srv-db01')
            && $fr['forward']['from'] === $orig && $fr['forward']['author'] !== '' && $fr['forward']['channel'] !== '');
        $before = array_column(warRoomMessages($conn, $priv2, 0, 200, $a), 'body');
        ok('the comment is posted just before it, as the forwarder', in_array('ZZ FYI', $before, true));
        $att = $conn->query("SELECT stored_name FROM warroom_attachments WHERE message_id = $fw")->fetchAll(PDO::FETCH_COLUMN);
        $copies = $att;
        ok('its file is copied under a new name, byte for byte', count($att) === 1 && $att[0] !== $srcName
            && @file_get_contents($dir . $att[0]) === 'ZZ attachment bytes');
        ok('@names in the forwarded text notify nobody again', (int) $conn->query("SELECT COUNT(*) FROM warroom_mentions WHERE message_id = $fw")->fetchColumn() === 0);
        ok('nobody can forward into a conversation they cannot post in', warRoomForward($conn, $b, $orig, $priv2) === 0);
        $pmsg = warRoomSend($conn, $a, $priv2, 'ZZ private');
        ok('nobody can forward a message they cannot read', warRoomForward($conn, $b, $pmsg, $all) === 0);
        $dm = warRoomOpenDm($conn, $a, $b);
        ok('...and to a person, through their direct message', warRoomForward($conn, $a, $orig, $dm) > 0);
        foreach ($conn->query("SELECT stored_name FROM warroom_attachments WHERE message_id IN (SELECT id FROM warroom_messages WHERE channel_id = $dm)")->fetchAll(PDO::FETCH_COLUMN) as $n) $copies[] = $n;
    } finally {
        @unlink($dir . $srcName);
        foreach ($copies as $n) @unlink($dir . $n);
    }

    // ---- piece 4: Seen by
    $set = fn($v) => $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('warroom_seen_by', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$v]);
    $set('1'); warRoomSeenByEnabled($conn, true);
    $s1 = warRoomSend($conn, $a, $all, 'ZZ seen-by test');
    warRoomMarkRead($conn, $b, $all, $s1);
    $sb = warRoomSeenBy($conn, $a, $s1);
    $bName = (string) $conn->query("SELECT full_name FROM analysts WHERE id = $b")->fetchColumn();
    ok('Seen by lists who has read it, with a time', $sb && in_array($bName, array_column($sb['seen'], 'name'), true) && !empty($sb['seen'][0]['at']));
    ok('...and never the author', !in_array((string) $conn->query("SELECT full_name FROM analysts WHERE id = $a")->fetchColumn(), array_column($sb['seen'], 'name'), true));
    $firstAt = $sb['seen'][array_search($bName, array_column($sb['seen'], 'name'))]['at'];
    warRoomMarkUnread($conn, $b, $s1);
    $conn->exec("UPDATE warroom_read_marks SET read_datetime = DATE_SUB(read_datetime, INTERVAL 1 HOUR) WHERE analyst_id = $b AND up_to_message_id >= $s1");
    $earlier = warRoomSeenBy($conn, $a, $s1)['seen'];
    warRoomMarkRead($conn, $b, $all, $s1);   // reading it again later
    $again = warRoomSeenBy($conn, $a, $s1)['seen'];
    ok('marking unread and reading again keeps the FIRST read time',
       $again[array_search($bName, array_column($again, 'name'))]['at'] === $earlier[array_search($bName, array_column($earlier, 'name'))]['at']);
    $tOnly = warRoomSend($conn, $a, $all, 'ZZ thread-only', 0, $s1);
    ok('a reply only inside a thread has no Seen by (reading the conversation is not reading the thread)', warRoomSeenBy($conn, $a, $tOnly) === null);
    ok('nobody sees Seen by for a conversation they cannot read', warRoomSeenBy($conn, $b, $pmsg) === null);
    $set('0'); warRoomSeenByEnabled($conn, true);
    $s2 = warRoomSend($conn, $a, $all, 'ZZ seen-by off');
    $marks = (int) $conn->query("SELECT COUNT(*) FROM warroom_read_marks WHERE analyst_id = $b")->fetchColumn();
    warRoomMarkRead($conn, $b, $all, $s2);
    ok('switched off: no read time is recorded at all', (int) $conn->query("SELECT COUNT(*) FROM warroom_read_marks WHERE analyst_id = $b")->fetchColumn() === $marks);
    ok('...and Seen by answers nothing', warRoomSeenBy($conn, $a, $s1) === null);
} finally {
    $conn->rollBack();
}

echo str_repeat('-', 70) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
