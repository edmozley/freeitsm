<?php
/**
 * Knowledge — the assistant.
 *
 * Reads what the service desk has actually been answering and reports, in plain
 * language, what the knowledge base is missing. Not a dashboard of metrics: a
 * short statement ("I read 340 closed tickets. Your knowledge base is missing
 * six things.") followed by the six things, each with the evidence behind it and
 * two decisions — write it, or say it isn't needed.
 *
 * The framing is deliberate. "This ticket could be an article" is unknowable
 * from one ticket, and a button offering it on every ticket produces a knowledge
 * base full of "reset the user's password". "You have answered this fourteen
 * times and have no article" is a fact, and it is the one worth acting on.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
require_once '../../includes/rbac.php';
I18n::initFromSession();
Tz::init();
requireModuleAccess('knowledge');

$current_page = 'assistant';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'knowledge'];

// Running the analysis spends money on embeddings, so the button is gated on the
// same capability that guards re-embedding articles. Everyone with Knowledge
// access can still READ the findings and act on them.
$canAnalyse = analystHasCapability(connectToDatabase(), (int)$_SESSION['analyst_id'], Cap::KNOWLEDGE_EMBEDDINGS);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Knowledge assistant</title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=62">
    <link rel="stylesheet" href="../../assets/css/knowledge.css?v=2">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <script src="../../assets/js/safe-html.js?v=2"></script>
    <style>
        .ka-page { padding:16px 30px 24px; height:calc(100vh - 48px); display:flex; flex-direction:column; overflow:hidden; }
        .ka-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:14px; flex-shrink:0; }
        .ka-head h1 { font-size:24px; font-weight:600; margin:0 0 4px; color:var(--text); }
        .ka-sub { font-size:13px; color:var(--text-muted); max-width:640px; line-height:1.5; }

        /* The assistant's own voice — one short statement, not a stat tile. */
        .ka-say { background:var(--surface-hover); border:1px solid var(--border); border-radius:10px;
                  padding:14px 16px; margin-bottom:14px; flex-shrink:0; }
        .ka-say p { margin:0; font-size:14px; line-height:1.55; color:var(--text); }
        .ka-say .ka-meta { margin-top:6px; font-size:12px; color:var(--text-muted); }

        .ka-tabs { display:flex; gap:4px; margin-bottom:12px; flex-shrink:0; }
        .ka-tab { padding:6px 12px; border-radius:6px; border:1px solid transparent; background:none;
                  cursor:pointer; font-size:13px; color:var(--text-muted); }
        .ka-tab.active { background:var(--surface); border-color:var(--border); color:var(--text); font-weight:600; }

        .ka-list { flex:1; min-height:0; overflow-y:auto; }
        .ka-card { background:var(--surface); border:1px solid var(--border); border-radius:10px;
                   padding:14px 16px; margin-bottom:10px; }
        .ka-card-top { display:flex; justify-content:space-between; align-items:flex-start; gap:14px; }
        .ka-card h3 { margin:0 0 4px; font-size:15px; font-weight:600; color:var(--text); }
        .ka-count { font-size:13px; color:var(--text-muted); }
        .ka-count strong { color:var(--text); }
        .ka-actions { display:flex; gap:8px; flex-shrink:0; }
        .ka-btn { padding:6px 14px; border-radius:6px; border:1px solid var(--border); background:var(--surface);
                  color:var(--text); cursor:pointer; font-size:13px; }
        .ka-btn:hover { background:var(--surface-hover); }
        .ka-btn-primary { background:var(--accent); border-color:var(--accent); color:var(--on-accent); }
        .ka-btn-primary:hover { filter:brightness(1.08); }
        .ka-btn:disabled { opacity:.55; cursor:default; }

        .ka-evidence { margin-top:10px; font-size:12px; }
        .ka-evidence summary { cursor:pointer; color:var(--text-muted); }
        .ka-evidence ul { margin:8px 0 0; padding-left:18px; }
        .ka-evidence li { margin-bottom:3px; color:var(--text-muted); }
        .ka-evidence a { color:var(--accent); text-decoration:none; }
        .ka-evidence a:hover { text-decoration:underline; }

        .ka-empty { text-align:center; padding:56px 20px; color:var(--text-muted); font-size:14px; }

        .ka-progress { height:6px; background:var(--surface-hover); border-radius:3px; overflow:hidden; margin-top:10px; }
        .ka-progress i { display:block; height:100%; background:var(--accent); width:0; transition:width .25s; }

        /* Draft modal */
        .ka-modal { position:fixed; inset:0; background:rgba(0,0,0,.5); display:none; z-index:1000;
                    align-items:center; justify-content:center; }
        .ka-modal.open { display:flex; }
        .ka-modal-box { background:var(--surface); border-radius:12px; width:min(820px,92vw); max-height:88vh;
                        display:flex; flex-direction:column; overflow:hidden; }
        .ka-modal-head { padding:16px 20px; border-bottom:1px solid var(--border); display:flex;
                         justify-content:space-between; align-items:center; }
        .ka-modal-head h2 { margin:0; font-size:16px; font-weight:600; color:var(--text); }
        .ka-modal-body { padding:18px 20px; overflow-y:auto; flex:1; min-height:0; }
        .ka-modal-foot { padding:14px 20px; border-top:1px solid var(--border); display:flex;
                         justify-content:flex-end; gap:8px; }
        .ka-status { font-size:13px; color:var(--text-muted); margin-bottom:12px; }

        /* The refusal is a first-class outcome, not an error state. */
        .ka-refusal { border-left:3px solid var(--accent); padding:2px 0 2px 14px; margin-bottom:16px; }
        .ka-refusal p { margin:0 0 10px; font-size:14px; line-height:1.55; color:var(--text); }
        .ka-q { margin-bottom:14px; }
        .ka-q label { display:block; font-size:13px; color:var(--text); margin-bottom:5px; line-height:1.45; }
        .ka-q textarea { width:100%; box-sizing:border-box; min-height:58px; padding:8px 10px; font-size:14px;
                         font-family:inherit; border:1px solid var(--border); border-radius:6px;
                         background:var(--surface); color:var(--text); resize:vertical; }

        .ka-preview { border:1px solid var(--border); border-radius:8px; padding:16px 18px; background:var(--surface);
                      font-size:14px; line-height:1.6; color:var(--text); }
        .ka-preview h1 { font-size:19px; margin:0 0 12px; }
        .ka-preview h2 { font-size:16px; margin:18px 0 8px; }
        .ka-preview h3 { font-size:14px; margin:14px 0 6px; }
        .ka-preview p, .ka-preview li { line-height:1.6; }
        .ka-preview code { background:var(--surface-hover); padding:1px 5px; border-radius:4px; font-size:13px; }
        .ka-preview pre { background:var(--surface-hover); padding:10px 12px; border-radius:6px; overflow-x:auto; }
        .ka-draft-note { font-size:12px; color:var(--text-muted); margin-top:12px; }
    </style>
    <!-- Mobile: LAYER 17g. -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=130">
</head>
<body>
<?php require_once '../includes/header.php'; ?>

<div class="ka-page">
    <div class="ka-head">
        <div>
            <h1>Assistant</h1>
            <div class="ka-sub">
                Reads what the service desk has been answering and tells you what the knowledge
                base is missing. It only speaks up when the same question keeps coming back.
            </div>
        </div>
        <?php if ($canAnalyse): ?>
        <button class="ka-btn ka-btn-primary" id="kaRun" onclick="kaRun()">Look for gaps</button>
        <?php endif; ?>
    </div>

    <div class="ka-say" id="kaSay">
        <p id="kaSayText">Checking…</p>
        <div class="ka-meta" id="kaSayMeta"></div>
        <div class="ka-progress" id="kaProgress" style="display:none;"><i id="kaProgressBar"></i></div>
    </div>

    <div class="ka-tabs">
        <button class="ka-tab active" data-status="open"      onclick="kaTab('open', this)">To write</button>
        <button class="ka-tab"        data-status="written"   onclick="kaTab('written', this)">Written</button>
        <button class="ka-tab"        data-status="dismissed" onclick="kaTab('dismissed', this)">Not needed</button>
    </div>

    <div class="ka-list" id="kaList"></div>
</div>

<!-- Draft modal -->
<div class="ka-modal" id="kaModal">
    <div class="ka-modal-box">
        <div class="ka-modal-head">
            <h2 id="kaModalTitle">Writing this up</h2>
            <button class="ka-btn" onclick="kaCloseModal()">Close</button>
        </div>
        <div class="ka-modal-body">
            <div class="ka-status" id="kaModalStatus">Reading the ticket…</div>
            <div id="kaModalContent"></div>
        </div>
        <div class="ka-modal-foot" id="kaModalFoot"></div>
    </div>
</div>

<script>
const API = '<?php echo BASE_URL; ?>api/knowledge/';
const CAN_ANALYSE = <?php echo $canAnalyse ? 'true' : 'false'; ?>;

let kaStatus = 'open';
let kaCtx = null;      // { clusterId, ticketId, label } for the open modal
let kaDraftHtml = '';  // accumulated article HTML from the stream

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function fmtDate(s) {
    if (!s) return '';
    try { return fmtDate(s); }
    catch (e) { return s; }
}

/* ---------------------------------------------------------------- *
 * Loading what the assistant already knows
 * ---------------------------------------------------------------- */
async function kaLoad() {
    const res = await fetch(API + 'gap_clusters.php?status=' + encodeURIComponent(kaStatus));
    const data = await res.json();
    const list = document.getElementById('kaList');

    if (!data.success) {
        list.innerHTML = '<div class="ka-empty">' + esc(data.error || 'Could not load') + '</div>';
        return;
    }
    if (data.needs_db_verify) {
        document.getElementById('kaSayText').textContent = data.summary;
        list.innerHTML = '';
        return;
    }

    renderClusters(data.clusters || []);
    if (data.last_run) {
        document.getElementById('kaSayMeta').textContent = 'Last looked ' + fmtDate(data.last_run) + '.';
    }
}

function renderClusters(clusters) {
    const list = document.getElementById('kaList');
    if (!clusters.length) {
        list.innerHTML = '<div class="ka-empty">' + (
            kaStatus === 'open'      ? 'Nothing to write. Either the assistant has not looked yet, or your knowledge base already covers what people keep asking.' :
            kaStatus === 'written'   ? 'Nothing written from a gap yet.' :
                                       'Nothing set aside.'
        ) + '</div>';
        return;
    }

    list.innerHTML = clusters.map(c => {
        const span = (c.first_ticket_datetime && c.last_ticket_datetime)
            ? fmtDate(c.first_ticket_datetime) + ' – ' + fmtDate(c.last_ticket_datetime) : '';

        const evidence = (c.tickets || []).map(t =>
            '<li><a href="<?php echo BASE_URL; ?>tickets/?ticket_id=' + t.ticket_id + '" target="_blank">' +
            esc(t.ticket_ref) + '</a> — ' + esc(t.subject) + '</li>').join('');

        let actions = '';
        if (c.status === 'open') {
            actions = '<button class="ka-btn ka-btn-primary" onclick="kaDraft(' + c.id + ', ' + JSON.stringify(c.label).replace(/"/g, '&quot;') + ')">Draft</button>' +
                      '<button class="ka-btn" onclick="kaDismiss(' + c.id + ', false)">Not needed</button>';
        } else if (c.status === 'dismissed') {
            actions = '<button class="ka-btn" onclick="kaDismiss(' + c.id + ', true)">Bring back</button>';
        } else if (c.status === 'written' && c.article_id) {
            actions = '<a class="ka-btn" href="<?php echo BASE_URL; ?>knowledge/?article=' + c.article_id + '">Open article</a>';
        }

        // "asked N times" is the headline because it is the only part that is a
        // fact rather than a judgement.
        return '<div class="ka-card">' +
            '<div class="ka-card-top">' +
              '<div>' +
                '<h3>' + esc(c.label) + '</h3>' +
                '<div class="ka-count">Asked <strong>' + c.ticket_count + ' times</strong>' +
                  (span ? ' &middot; ' + esc(span) : '') +
                  (c.status === 'written' && c.article_title
                     ? ' &middot; written up as “' + esc(c.article_title) + '”' +
                       (Number(c.is_published) === 0 ? ' (still a draft)' : '') : '') +
                '</div>' +
              '</div>' +
              '<div class="ka-actions">' + actions + '</div>' +
            '</div>' +
            (evidence ? '<details class="ka-evidence"><summary>Show the tickets</summary><ul>' + evidence + '</ul></details>' : '') +
        '</div>';
    }).join('');
}

function kaTab(status, el) {
    kaStatus = status;
    document.querySelectorAll('.ka-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    kaLoad();
}

/* ---------------------------------------------------------------- *
 * Running the analysis — embed in batches, then cluster
 * ---------------------------------------------------------------- */
async function kaPost(action, extra) {
    const res = await fetch(API + 'analyse_gaps.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ action }, extra || {}))
    });
    return res.json();
}

async function kaRun() {
    const btn = document.getElementById('kaRun');
    const say = document.getElementById('kaSayText');
    const bar = document.getElementById('kaProgress');
    const fill = document.getElementById('kaProgressBar');
    if (btn) { btn.disabled = true; btn.textContent = 'Reading…'; }

    try {
        const st = await kaPost('status');
        if (!st.success) { say.textContent = st.error || 'Could not start.'; return; }

        if (!st.tickets) {
            say.textContent = 'There are no closed tickets in the last ' + st.lookback_days + ' days to read.';
            return;
        }

        // Embedding is the slow, paid part. Show honest progress rather than a
        // spinner that says nothing about how long this will take.
        if (st.remaining > 0) {
            bar.style.display = 'block';
            const total = st.tickets;
            let remaining = st.remaining;
            while (remaining > 0) {
                say.textContent = 'Reading your closed tickets… ' + (total - remaining) + ' of ' + total + '.';
                fill.style.width = Math.round(((total - remaining) / total) * 100) + '%';
                const r = await kaPost('embed', { batch: 20 });
                if (!r.success) { say.textContent = r.error || 'Stopped early.'; break; }
                if (r.stalled) {
                    say.textContent = 'Could not read the tickets — check the OpenAI key in Knowledge → Settings.';
                    bar.style.display = 'none';
                    return;
                }
                remaining = r.remaining;
            }
            fill.style.width = '100%';
        }

        say.textContent = 'Working out what is missing…';
        const c = await kaPost('cluster');
        bar.style.display = 'none';
        if (!c.success) { say.textContent = c.error || 'Could not finish.'; return; }

        say.textContent = c.message;
        document.getElementById('kaSayMeta').textContent =
            c.mode === 'wording'
                ? 'Matched on wording. Add an OpenAI key in Knowledge → Settings to match on meaning instead.'
                : '';
        kaStatus = 'open';
        document.querySelectorAll('.ka-tab').forEach(t => t.classList.toggle('active', t.dataset.status === 'open'));
        kaLoad();
    } finally {
        if (btn) { btn.disabled = false; btn.textContent = 'Look for gaps'; }
    }
}

/* ---------------------------------------------------------------- *
 * Dismissing
 * ---------------------------------------------------------------- */
async function kaDismiss(clusterId, undo) {
    const res = await fetch(API + 'gap_dismiss.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ cluster_id: clusterId, undo: !!undo })
    });
    const data = await res.json();
    if (!data.success) { alert(data.error || 'Could not update'); return; }
    kaLoad();
}

/* ---------------------------------------------------------------- *
 * Drafting — the streaming write-up
 * ---------------------------------------------------------------- */
function kaCloseModal() {
    document.getElementById('kaModal').classList.remove('open');
    kaCtx = null;
}

function kaDraft(clusterId, label) {
    kaCtx = { clusterId, label };
    document.getElementById('kaModalTitle').textContent = label;
    document.getElementById('kaModalStatus').textContent = 'Reading the most detailed ticket…';
    document.getElementById('kaModalContent').innerHTML = '';
    document.getElementById('kaModalFoot').innerHTML = '';
    document.getElementById('kaModal').classList.add('open');
    kaStream({ cluster_id: clusterId });
}

function kaRetryWithAnswers() {
    const answers = Array.from(document.querySelectorAll('.ka-q')).map(q => {
        const label = q.querySelector('label').textContent;
        const val = q.querySelector('textarea').value.trim();
        return val ? ('Q: ' + label + '\nA: ' + val) : '';
    }).filter(Boolean).join('\n\n');

    if (!answers) { alert('Answer at least one question first.'); return; }

    document.getElementById('kaModalStatus').textContent = 'Writing it up…';
    document.getElementById('kaModalContent').innerHTML = '';
    document.getElementById('kaModalFoot').innerHTML = '';
    kaStream({ cluster_id: kaCtx.clusterId, answers });
}

/**
 * Stream the write-up. The first line of the model's response is a verdict, and
 * the server holds it back and sends it as its own event — so nothing is
 * rendered until we know whether we are showing an article or a refusal.
 */
async function kaStream(payload) {
    kaDraftHtml = '';
    const statusEl = document.getElementById('kaModalStatus');
    const contentEl = document.getElementById('kaModalContent');
    const footEl = document.getElementById('kaModalFoot');
    let verdict = null;
    let buffer = '';

    let res;
    try {
        res = await fetch(API + 'writeup_stream.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
    } catch (e) {
        statusEl.textContent = 'Could not reach the assistant.';
        return;
    }

    const reader = res.body.getReader();
    const decoder = new TextDecoder();
    let sseBuf = '';

    const handle = (event, data) => {
        if (event === 'error') {
            statusEl.textContent = data.message || 'Something went wrong.';
            return;
        }
        if (event === 'verdict') {
            verdict = data.verdict;
            statusEl.textContent = verdict === 'article'
                ? 'Writing it up…'
                : 'There is not enough in these tickets yet.';
            contentEl.innerHTML = verdict === 'article'
                ? '<div class="ka-preview" id="kaPreview"></div>'
                : '<div class="ka-refusal" id="kaRefusal"></div>';
            return;
        }
        if (event === 'text') {
            buffer += (data.delta || '');
            if (verdict === 'article') {
                kaDraftHtml = buffer;
                // Model output is not user input, but it is still untrusted text
                // being assigned to innerHTML — it goes through the one shared
                // sanitiser like every other HTML in the product.
                const p = document.getElementById('kaPreview');
                if (p) p.innerHTML = safeHtmlFragment(buffer);
            } else {
                const r = document.getElementById('kaRefusal');
                if (r) r.textContent = buffer;
            }
            return;
        }
        if (event === 'done') {
            kaFinish(data);
        }
    };

    // eslint-disable-next-line no-constant-condition
    while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        sseBuf += decoder.decode(value, { stream: true });
        const chunks = sseBuf.split('\n\n');
        sseBuf = chunks.pop();
        chunks.forEach(chunk => {
            const ev = (chunk.match(/^event: (.+)$/m) || [])[1];
            const dm = (chunk.match(/^data: (.+)$/m) || [])[1];
            if (!ev || !dm) return;
            try { handle(ev, JSON.parse(dm)); } catch (e) { /* partial frame */ }
        });
    }
}

function kaFinish(data) {
    const statusEl = document.getElementById('kaModalStatus');
    const contentEl = document.getElementById('kaModalContent');
    const footEl = document.getElementById('kaModalFoot');

    if (data.verdict === 'article') {
        statusEl.textContent = 'Draft ready. Read it before you publish — it was written from ticket ' + esc(data.ticket_ref) + '.';
        contentEl.insertAdjacentHTML('beforeend',
            '<div class="ka-draft-note">Saving puts this in your knowledge base as an unpublished draft. ' +
            'Nobody can read it until you publish it.</div>');
        footEl.innerHTML =
            '<button class="ka-btn" onclick="kaCloseModal()">Cancel</button>' +
            '<button class="ka-btn ka-btn-primary" id="kaSaveBtn" onclick="kaSaveDraft()">Save draft</button>';
        return;
    }

    // A refusal, with the questions that would turn it into an article.
    statusEl.textContent = 'Not enough to write from yet.';
    const refusal = document.getElementById('kaRefusal');
    if (refusal) {
        refusal.innerHTML = '<p>' + esc(data.explanation || 'The tickets do not say what caused this or how it was fixed.') + '</p>';
    }
    if ((data.questions || []).length) {
        contentEl.insertAdjacentHTML('beforeend',
            '<p style="font-size:13px;color:var(--text-muted);margin:0 0 14px;">' +
            'Answer what you can and it will try again. You were there; it was not.</p>' +
            data.questions.map(q =>
                '<div class="ka-q"><label>' + esc(q) + '</label><textarea></textarea></div>').join(''));
        footEl.innerHTML =
            '<button class="ka-btn" onclick="kaCloseModal()">Close</button>' +
            '<button class="ka-btn ka-btn-primary" onclick="kaRetryWithAnswers()">Try again</button>';
    } else {
        footEl.innerHTML = '<button class="ka-btn" onclick="kaCloseModal()">Close</button>';
    }
}

async function kaSaveDraft() {
    const btn = document.getElementById('kaSaveBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }

    // The <h1> the model opened with is the title; the rest is the body.
    const tmp = document.createElement('div');
    tmp.innerHTML = safeHtmlFragment(kaDraftHtml);
    const h1 = tmp.querySelector('h1');
    const title = h1 ? h1.textContent.trim() : (kaCtx && kaCtx.label) || 'Untitled';
    if (h1) h1.remove();

    const res = await fetch(API + 'writeup_save.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            title,
            body_html: tmp.innerHTML,
            cluster_id: kaCtx ? kaCtx.clusterId : 0,
            ticket_id: kaCtx ? (kaCtx.ticketId || 0) : 0
        })
    });
    const data = await res.json();
    if (!data.success) {
        if (btn) { btn.disabled = false; btn.textContent = 'Save draft'; }
        alert(data.error || 'Could not save');
        return;
    }
    kaCloseModal();
    kaStatus = 'open';
    kaLoad();
    window.open('<?php echo BASE_URL; ?>knowledge/?article=' + data.article_id + '&edit=1', '_blank');
}

/* ---------------------------------------------------------------- *
 * Boot
 * ---------------------------------------------------------------- */
(async function init() {
    await kaLoad();
    // The status call sits behind the same capability as running the analysis,
    // so don't make it for someone who could only be told "no".
    if (!CAN_ANALYSE) {
        const has = document.getElementById('kaList').querySelector('.ka-card');
        if (!has) {
            document.getElementById('kaSayText').textContent =
                'Nothing to show yet. Someone with permission to run the assistant needs to look for gaps first.';
        }
        return;
    }
    try {
        const st = await kaPost('status');
        if (st.success) {
            const el = document.getElementById('kaSayText');
            const has = document.getElementById('kaList').querySelector('.ka-card');
            if (!has) {
                el.textContent = st.tickets
                    ? 'There are ' + st.tickets + ' closed tickets from the last ' + st.lookback_days +
                      ' days I have not read yet.' + (CAN_ANALYSE ? ' Press “Look for gaps”.' : '')
                    : 'No closed tickets in the last ' + st.lookback_days + ' days to read.';
            }
        }
    } catch (e) { /* the list still works without the status line */ }
})();
</script>
    <script src="../../assets/js/mobile.js?v=53"></script>
</body>
</html>
