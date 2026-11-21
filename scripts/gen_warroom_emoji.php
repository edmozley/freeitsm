<?php
/**
 * Builds assets/js/war-room-emoji.json - the emoji the war room's reaction picker
 * offers - from Unicode's emoji-test.txt.
 *
 *   php scripts/gen_warroom_emoji.php path/to/emoji-test.txt
 *
 * Get emoji-test.txt from https://unicode.org/Public/emoji/<version>/emoji-test.txt.
 * Downloading it is a one-off step when building FreeITSM; the generated file is
 * committed, because the war room must never load anything from the internet.
 *
 * Kept: fully-qualified emoji up to MAX_VERSION (newer ones show as empty boxes on
 * older Windows and phones), without skin-tone variants (the default yellow only -
 * one reaction per emoji stays one reaction), and without the Component group.
 * The same file is what api/war-room/message.php checks a reaction against, so the
 * picker and the server can never disagree about what is allowed.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const MAX_VERSION = 14.0;
// Unicode's group names -> our i18n key under war-room.emoji.group
const GROUPS = [
    'Smileys & Emotion' => 'smileys', 'People & Body' => 'people', 'Animals & Nature' => 'nature',
    'Food & Drink' => 'food', 'Travel & Places' => 'travel', 'Activities' => 'activities',
    'Objects' => 'objects', 'Symbols' => 'symbols', 'Flags' => 'flags',
];

$src = $argv[1] ?? '';
if ($src === '' || !is_file($src)) { fwrite(STDERR, "Usage: php scripts/gen_warroom_emoji.php emoji-test.txt\n"); exit(1); }

$out = [];
$group = null;
foreach (file($src, FILE_IGNORE_NEW_LINES) as $line) {
    if (preg_match('/^# group: (.+)$/', $line, $m)) { $group = GROUPS[trim($m[1])] ?? null; continue; }
    if ($group === null || $line === '' || $line[0] === '#') continue;
    // 1F600 ; fully-qualified # 😀 E1.0 grinning face
    if (!preg_match('/^([0-9A-F ]+?)\s*;\s*fully-qualified\s*#\s*(\S+)\s+E(\d+\.\d+)\s+(.+)$/u', $line, $m)) continue;
    if ((float)$m[3] > MAX_VERSION) continue;
    $cps = preg_split('/\s+/', trim($m[1]));
    $skin = false;
    foreach ($cps as $cp) { $n = hexdec($cp); if ($n >= 0x1F3FB && $n <= 0x1F3FF) { $skin = true; break; } }
    if ($skin) continue;
    $out[$group][] = [$m[2], trim($m[4])];
}

$data = [];
foreach (GROUPS as $key) if (!empty($out[$key])) $data[] = ['group' => $key, 'emoji' => $out[$key]];
$dest = dirname(__DIR__) . '/assets/js/war-room-emoji.json';
file_put_contents($dest, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$n = array_sum(array_map(fn($g) => count($g['emoji']), $data));
echo "Wrote $n emoji in " . count($data) . " groups to $dest (" . round(filesize($dest) / 1024) . " KB)\n";
