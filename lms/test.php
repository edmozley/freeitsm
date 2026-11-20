<?php
/**
 * A candidate's competency test - the public page a test link opens.
 *
 * NO authentication: the link's token is the credential (only its hash is
 * stored). Rendered entirely from the sitting's frozen snapshot, and NEVER with
 * the marks or explanations in it - the page holds the questions and answer
 * texts and nothing a candidate could use to work out the key.
 *
 * States: ready (intro + Start) -> in_progress (questions, timer, autosave) ->
 * submitted. A cancelled or unknown link shows one message; an out-of-date
 * unused link says so. Time is the server's: the remaining seconds are worked
 * out here and every save is checked again in api/lms/test_public.php.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n_guarded.php';
require_once __DIR__ . '/../includes/lms/competency_tests.php';

i18nGuardedInit('lms.candidate');

// The token is in this page's URL: never hand it to another site in a Referer,
// and keep the page out of caches and search engines.
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

$raw  = (string)($_GET['t'] ?? '');
$conn = connectToDatabase();
// GH #217: with the LMS switched off the link reads as not valid, like a cancelled one.
$s    = moduleIsEnabled($conn, 'lms') ? lmsCtFindSitting($conn, $raw) : null;
$state = (!$s || (int)$s['is_cancelled']) ? 'invalid' : lmsCtState($s);
if ($state === 'timeup') {
    lmsCtFinalise($conn, $s, 'time_up');
    $st = $conn->prepare("SELECT * FROM lms_ct_sittings WHERE id = ?");
    $st->execute([(int)$s['id']]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    $state = 'submitted';
}

$snap      = $s ? (json_decode((string)$s['snapshot_json'], true) ?: ['questions' => []]) : ['questions' => []];
$responses = $s ? (json_decode((string)$s['responses_json'], true) ?: []) : [];
$count     = count($snap['questions']);
$limit     = $s && $s['time_limit_minutes'] !== null ? (int)$s['time_limit_minutes'] : 0;
$remaining = ($state === 'in_progress') ? max(0, lmsCtDeadline($s) - time()) : 0;
$settings  = lmsCtSettings($conn);
$showScore = $settings[LMS_CT_SHOW_SCORE] === '1';
$sysName   = function_exists('systemName') ? systemName() : 'FreeITSM';
?>
<!DOCTYPE html>
<html lang="<?= trLocale() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
<title><?php echo htmlspecialchars(pageTitle(null, $state === 'invalid' ? tr('title', 'Competency test') : ($snap['title'] ?? tr('title', 'Competency test')))); ?></title>
<style>
:root {
    --bg: #f4f6fb; --card: #fff; --text: #1f2933; --muted: #5f6b7a; --line: #dde3ec;
    --accent: #2563eb; --accent-soft: #e8efff; --ok: #1e7a3c; --warn: #b45309;
}
@media (prefers-color-scheme: dark) {
    :root { --bg: #11151c; --card: #1a2029; --text: #e6eaf0; --muted: #9aa5b4; --line: #2c3542;
            --accent: #6d9bff; --accent-soft: #1f2b44; --ok: #5cc98a; --warn: #f0a640; }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
.wrap { max-width: 760px; margin: 0 auto; padding: 24px 16px 120px; }
.brand { font-size: var(--font-size-md, 13px); color: var(--muted); letter-spacing: .3px; margin-bottom: 14px; }
.card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 24px; margin-bottom: 16px; }
h1 { font-size: var(--font-size-4xl, 24px); margin: 0 0 6px; line-height: 1.3; }
.sub { color: var(--muted); margin: 0 0 18px; }
.facts { display: flex; flex-wrap: wrap; gap: 10px; margin: 16px 0 20px; padding: 0; list-style: none; }
.facts li { background: var(--accent-soft); border-radius: 999px; padding: 6px 14px; font-size: var(--font-size-lg, 14px); }
.rules { margin: 0 0 22px; padding-left: 20px; color: var(--muted); }
.rules li { margin-bottom: 6px; }
.btn { appearance: none; border: 0; border-radius: 8px; background: var(--accent); color: #fff; font: 600 16px/1 inherit; font-family: inherit; padding: 14px 26px; cursor: pointer; }
.btn:disabled { opacity: .6; cursor: default; }
.btn-ghost { background: transparent; color: var(--accent); border: 1px solid var(--line); }
.q { scroll-margin-top: 80px; }
.q-head { display: flex; justify-content: space-between; gap: 12px; font-size: var(--font-size-md, 13px); color: var(--muted); margin-bottom: 8px; }
.q-text { font-size: 17px; margin: 0 0 14px; white-space: pre-wrap; }
.opt { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border: 1px solid var(--line); border-radius: 8px; margin-bottom: 8px; cursor: pointer; }
.opt:hover { border-color: var(--accent); }
.opt input { width: 20px; height: 20px; margin: 2px 0 0; flex: 0 0 auto; accent-color: var(--accent); }
.opt.on { border-color: var(--accent); background: var(--accent-soft); }
.saved { color: var(--ok); }
.bar { position: fixed; left: 0; right: 0; bottom: 0; background: var(--card); border-top: 1px solid var(--line); padding: 12px 16px; }
.bar-in { max-width: 760px; margin: 0 auto; display: flex; align-items: center; gap: 14px; }
.bar-in .grow { flex: 1; min-width: 0; font-size: var(--font-size-lg, 14px); color: var(--muted); }
.clock { font: 700 20px/1 ui-monospace, Consolas, monospace; }
.clock.low { color: var(--warn); }
.msg { text-align: center; padding: 40px 24px; }
.msg .big { font-size: 44px; margin-bottom: 8px; }
.err { color: var(--warn); font-size: var(--font-size-lg, 14px); margin-top: 10px; min-height: 1em; }
</style>
</head>
<body>
<div class="wrap">
    <div class="brand"><?= htmlspecialchars($sysName) ?></div>

<?php if ($state === 'invalid' || $state === 'expired'): ?>
    <div class="card msg">
        <div class="big">🔒</div>
        <h1><?= $state === 'expired' ? trh('expired_title', 'This link has expired') : trh('invalid_title', 'This link does not work') ?></h1>
        <p class="sub"><?= $state === 'expired'
            ? trh('expired_body', 'The test was not started in time. Please ask the person who sent it for a new link.')
            : trh('invalid_body', 'The link may have been mistyped, replaced or withdrawn. Please ask the person who sent it.') ?></p>
    </div>

<?php elseif ($state === 'submitted'): ?>
    <div class="card msg">
        <div class="big">✅</div>
        <h1><?= trh('done_title', 'Thank you - your answers are in') ?></h1>
        <p class="sub"><?= $s['finish_reason'] === 'time_up'
            ? trh('done_timeup', 'The time ran out, so the answers you had given were submitted for you.')
            : trh('done_body', 'There is nothing more to do. The person who invited you will be in touch.') ?></p>
        <?php if ($showScore && $s['score_percent'] !== null): ?>
            <p><strong><?= trh('your_score', 'Your score: {pct}%', ['pct' => rtrim(rtrim(number_format((float)$s['score_percent'], 1), '0'), '.')]) ?></strong></p>
        <?php endif; ?>
    </div>

<?php elseif ($state === 'ready'): ?>
    <div class="card">
        <h1><?= htmlspecialchars($snap['title']) ?></h1>
        <p class="sub"><?= trh('hello', 'Hello {name}', ['name' => $s['candidate_name']]) ?></p>
        <ul class="facts">
            <li><?= trh('fact_questions', '{n} questions', ['n' => $count]) ?></li>
            <li><?= $limit ? trh('fact_minutes', '{n} minutes', ['n' => $limit]) : trh('fact_untimed', 'No time limit') ?></li>
            <li><?= trh('fact_once', 'One attempt') ?></li>
        </ul>
        <ul class="rules">
            <li><?= trh('rule_pick', 'Each question has one best answer - choose the one you think fits best.') ?></li>
            <li><?= trh('rule_save', 'Your answers save as you go, so a dropped connection loses nothing.') ?></li>
            <?php if ($limit): ?><li><?= trh('rule_clock', 'The clock starts when you press Start and keeps running if you close the page. When it reaches zero, the answers you have given are submitted.') ?></li><?php endif; ?>
            <li><?= trh('rule_own', 'Please work on your own and do not share the questions.') ?></li>
        </ul>
        <button class="btn" id="startBtn"><?= trh('start', 'Start') ?></button>
        <div class="err" id="err"></div>
    </div>

<?php else: /* in_progress */ ?>
    <div class="card">
        <h1><?= htmlspecialchars($snap['title']) ?></h1>
        <p class="sub" style="margin:0"><?= trh('in_progress_sub', 'Choose one answer for each question, then press Submit.') ?></p>
    </div>
    <?php foreach ($snap['questions'] as $i => $q): $picked = $responses[(string)$i] ?? null; ?>
    <div class="card q" id="q<?= $i ?>" data-q="<?= $i ?>">
        <div class="q-head">
            <span><?= trh('q_of', 'Question {n} of {total}', ['n' => $i + 1, 'total' => $count]) ?></span>
            <span class="state"><?= $picked !== null ? '<span class="saved">' . trh('saved', 'Saved') . '</span>' : '' ?></span>
        </div>
        <p class="q-text"><?= htmlspecialchars($q['text']) ?></p>
        <?php foreach ($q['answers'] as $j => $a): ?>
        <label class="opt<?= $picked !== null && (int)$picked === $j ? ' on' : '' ?>">
            <input type="radio" name="q<?= $i ?>" value="<?= $j ?>"<?= $picked !== null && (int)$picked === $j ? ' checked' : '' ?>>
            <span><?= htmlspecialchars($a['text']) ?></span>
        </label>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <div class="bar">
        <div class="bar-in">
            <?php if ($limit): ?><span class="clock" id="clock">--:--</span><?php endif; ?>
            <span class="grow" id="progress"></span>
            <button class="btn" id="submitBtn"><?= trh('submit', 'Submit') ?></button>
        </div>
    </div>
<?php endif; ?>
</div>

<script>
(function () {
    var T = <?= json_encode($raw) ?>;
    var API = <?= json_encode((defined('BASE_URL') ? BASE_URL : '/') . 'api/lms/test_public.php') ?>;
    var S = <?= json_encode([
        'saved'      => tr('saved', 'Saved'),
        'saving'     => tr('saving', 'Saving…'),
        'not_saved'  => tr('not_saved', 'Not saved - check your connection'),
        'answered'   => tr('answered', '{n} of {total} answered'),
        'confirm'    => tr('confirm_submit', 'Submit your answers? You will not be able to change them.'),
        'confirm_gap'=> tr('confirm_gap', '{n} question(s) are unanswered. Submit anyway?'),
        'failed'     => tr('failed', 'That did not work - please try again.'),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    function post(body) {
        body.t = T;
        return fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), credentials: 'omit' })
            .then(function (r) { return r.json(); });
    }
    function fmt(s, p) { return s.replace(/\{(\w+)\}/g, function (m, k) { return p[k] != null ? p[k] : m; }); }

    var start = document.getElementById('startBtn');
    if (start) {
        start.addEventListener('click', function () {
            start.disabled = true;
            post({ action: 'start' }).then(function () { location.reload(); })
                .catch(function () { start.disabled = false; document.getElementById('err').textContent = S.failed; });
        });
        return;
    }

    var submitBtn = document.getElementById('submitBtn');
    if (!submitBtn) return;
    var total = document.querySelectorAll('.q').length;
    function answered() { return document.querySelectorAll('.q input:checked').length; }
    function progress() { document.getElementById('progress').textContent = fmt(S.answered, { n: answered(), total: total }); }
    progress();

    document.querySelectorAll('.q').forEach(function (card) {
        var qi = +card.dataset.q, st = card.querySelector('.state');
        card.addEventListener('change', function (e) {
            if (!e.target.matches('input[type=radio]')) return;
            card.querySelectorAll('.opt').forEach(function (o) { o.classList.toggle('on', o.contains(e.target)); });
            progress();
            st.textContent = S.saving;
            post({ action: 'save', q: qi, a: +e.target.value }).then(function (d) {
                if (d.success) { st.innerHTML = '<span class="saved"></span>'; st.firstChild.textContent = S.saved; }
                else if (d.error === 'finished') location.reload();
                else st.textContent = S.not_saved;
            }).catch(function () { st.textContent = S.not_saved; });
        });
    });

    var done = false;
    function submit(ask) {
        if (done) return;
        if (ask) {
            var gap = total - answered();
            if (!confirm(gap ? fmt(S.confirm_gap, { n: gap }) : S.confirm)) return;
        }
        done = true; submitBtn.disabled = true;
        post({ action: 'submit' }).then(function () { location.reload(); })
            .catch(function () { done = false; submitBtn.disabled = false; alert(S.failed); });
    }
    submitBtn.addEventListener('click', function () { submit(true); });

    var clock = document.getElementById('clock');
    if (clock) {
        // Counted from the server's figure against this device's monotonic
        // clock, so changing the device's time changes nothing.
        var ends = performance.now() + <?= (int)$remaining ?> * 1000;
        (function tick() {
            var left = Math.max(0, Math.round((ends - performance.now()) / 1000));
            var h = Math.floor(left / 3600), m = Math.floor(left % 3600 / 60), s = left % 60;
            clock.textContent = (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0');
            clock.classList.toggle('low', left <= 120);
            if (left <= 0) { submit(false); return; }
            setTimeout(tick, 500);
        })();
    }
})();
</script>
</body>
</html>
