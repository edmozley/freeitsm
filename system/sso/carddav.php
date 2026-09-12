<?php
/**
 * System → Authentication → one CardDAV address book.
 *
 * WHY A PAGE AND NOT THE DIALOG
 * -----------------------------
 * Ed asked the question and the answer was already written down one file over:
 * `provider.php` exists because a directory's settings "had all been bolted onto
 * the modal on the list page, where the import section ended up below the fold
 * and people reasonably concluded it was not there."
 *
 * A CardDAV source went the same way within a day. It has a connection, a book
 * to choose, a test whose result is several lines, and a list of groups or tags
 * to tick — and the modal had already needed sticky chrome to stay usable,
 * which is the same symptom. Phase 4 adds field mapping and a run history,
 * which have nowhere to live in a dialog at all.
 *
 * ⚠️ TWO TABS, NOT FOUR. Field mapping and History are deliberately absent
 * rather than present and empty: a tab that opens onto nothing is worse than a
 * tab that is not there yet, because it reads as broken rather than unbuilt.
 *
 * ⚠️ The page furniture CSS below duplicates provider.php's. That is a known
 * cost, recorded in TODO.local.md rather than pretended away: extracting it is
 * a genuine refactor because provider.php's block interleaves LDAP-specific
 * mapping and history rules with the shared ones, and the right moment is when
 * this page grows those same tabs.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/carddav.php';               // cardDavScopeList()
require_once '../../includes/settings_manifest.php';     // renderSettingsTabBar()

$current_page = 'sso';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'system'];

if (empty($_SESSION['analyst_id'])) {
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/') . 'auth/login.php');
    exit;
}

$conn = connectToDatabase();
if (!analystIsAdmin($conn, (int)$_SESSION['analyst_id'])) {
    http_response_code(403);
    exit('Administrator access required.');
}

$providerId = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT * FROM auth_providers WHERE id = ? AND protocol = 'carddav'");
$stmt->execute([$providerId]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$p) {
    header('Location: index.php');
    exit;
}

// Never send the stored password to the browser. A masked placeholder means
// "leave it alone" — the same contract the dialog used.
$hasPassword = !empty($p['carddav_password']);
$scopeChosen = cardDavScopeList($p['carddav_scope_value'] ?? '');

$tabs = [
    ['id' => 'connection', 'cap' => null, 'label' => t('system.sso.tab_connection')],
    ['id' => 'contacts',   'cap' => null, 'label' => t('system.sso.tab_contacts')],
    ['id' => 'history',    'cap' => null, 'label' => t('system.sso.tab_history')],
];
$activeTab = in_array($_GET['tab'] ?? '', ['connection', 'contacts', 'history'], true)
    ? $_GET['tab'] : 'connection';

/** Print a value into an input safely. */
function v($row, string $k): string { return htmlspecialchars((string)($row[$k] ?? '')); }
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars($p['display_name']); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=70">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <script src="../../assets/js/toast.js"></script>
    <script src="../../assets/js/confirm.js"></script>
    <style>
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
            margin: 0; background: var(--app-bg, #f5f5f5);
            /* A flex column so the scrolling area is "whatever is left below the
               header" and nothing has to know how tall the header is — the
               mistake provider.php records having made with calc(100vh - 48px)
               against a 58px header, which hung the Save button off the bottom. */
            display: flex; flex-direction: column; height: 100vh; overflow: hidden;
        }
        .prov-wrap {
            width: 100%; max-width: none; margin: 0;
            box-sizing: border-box; padding: 24px 32px 0;
            flex: 1 1 auto; min-height: 0; overflow-y: auto;
        }
        /* ⚠️ These match provider.php's values deliberately, down to the
           margins. The two pages are the same screen for two kinds of source,
           and a 1px difference in a label's margin is exactly how "built by two
           different people" happens. Recorded in TODO.local.md as T-8: the
           right fix is one shared stylesheet, and the moment for it is when
           this page grows the mapping and history tabs. */
        .prov-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 6px; }
        .prov-title { font-size: 22px; font-weight: 600; color: var(--text, #333); margin: 0; }
        .prov-sub { font-size: 13px; color: var(--text-dim, #888); margin: 2px 0 18px; }
        .prov-card { background: var(--surface, #fff); border-radius: 8px; padding: 22px; box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.08)); }
        .back-link { font-size: 13px; color: var(--sys-accent, #546e7a); text-decoration: none; }
        .back-link:hover { text-decoration: underline; }

        .fld { margin-bottom: 18px; }
        .fld label { display: block; font-size: 13px; font-weight: 600; color: var(--text, #333); margin-bottom: 3px; }
        .fld .hint { font-size: 12px; color: var(--text-dim, #888); margin-bottom: 6px; line-height: 1.5; }
        .fld input[type=text], .fld input[type=password], .fld select {
            width: 100%; box-sizing: border-box; padding: 9px 11px; font-size: 13px;
            border: 1px solid var(--border, #ddd); border-radius: 6px;
            background: var(--surface, #fff); color: var(--text, #333);
        }
        .result { margin-top: 8px; font-size: 12px; padding: 9px 11px; border-radius: 6px; display: none; white-space: pre-wrap; line-height: 1.5; }
        /* 🔑 Tokens, where provider.php hardcodes the light colours and then
           repeats itself in a `[data-theme-mode="dark"]` block. Same result,
           one rule instead of two, and it cannot drift out of step with the
           theme — so this is the one place the two pages deliberately differ. */
        .result.ok  { display: block; background: var(--success-bg, #e8f5e9); color: var(--success-text, #2e7d32); }
        .result.err { display: block; background: var(--danger-bg, #ffebee); color: var(--danger-text, #c62828); }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-test { background: var(--surface, #fff); color: var(--sys-accent, #546e7a); border: 1px solid var(--border, #cfd8dc); }
        .btn:disabled { opacity: .5; cursor: not-allowed; }
        /* 🔴 The save bar is a FOOTER, and that needs two things this page had
           wrong. It must sit OUTSIDE `.prov-card`, as a sibling — inside it, the
           button just floats at the bottom of the panel with no separation, which
           is exactly what Ed reported. And it needs the negative horizontal
           margin to bleed back out to the wrapper's edges (`.prov-wrap` has
           32px of side padding), so the dividing line runs the full width of the
           page rather than stopping short like an underline. */
        .save-bar {
            position: sticky; bottom: 0; z-index: 5;
            margin: 20px -32px 0; padding: 14px 32px;
            background: var(--app-bg, #f5f5f5);
            border-top: 1px solid var(--border, #e0e0e0);
            display: flex; gap: 10px; align-items: center;
        }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }

        /* The group / tag picker.
           A scrolling list of checkboxes rather than a multi-select: a native
           multi-select needs ctrl-click to add a second item, which is a piece
           of knowledge this screen should not require. Capped in height because
           an address book can legitimately have fifty tags. */
        .pick-list {
            border: 1px solid var(--border, #ddd); border-radius: 6px;
            max-height: 280px; overflow-y: auto; background: var(--surface, #fff);
        }
        /* 🔴 `.fld .pick-row`, not `.pick-row`. These rows are <label> elements
           inside a `.fld`, and `.fld label { display: block; font-weight: 600 }`
           above has specificity 0,1,1 against `.pick-row`'s 0,1,0 — so it won,
           the flex never applied, and every row rendered as bold block text
           with the count jammed against the name. Nothing errored; it just
           looked wrong, which is how a specificity clash always presents. */
        .fld .pick-row { display: flex; align-items: center; gap: 10px; padding: 9px 12px; margin: 0;
            border-bottom: 1px solid var(--border-soft, #f0f0f0); font-size: 13px; font-weight: 400; cursor: pointer; }
        .fld .pick-row:last-child { border-bottom: none; }
        .fld .pick-row:hover { background: var(--surface-hover, rgba(127,127,127,0.05)); }
        .fld .pick-row input { margin: 0; flex-shrink: 0; width: auto; }
        .fld .pick-row .pick-name { color: var(--text, #333); }
        .fld .pick-row .pick-count { margin-left: auto; font-size: 12px; color: var(--text-dim, #888); }
        .pick-empty { padding: 14px 12px; font-size: 12.5px; color: var(--text-dim, #888); }
        .pick-actions { display: flex; gap: 14px; margin-top: 8px; font-size: 12px; }
        .pick-actions button { background: none; border: none; padding: 0; cursor: pointer; color: var(--sys-accent, #546e7a); font-size: 12px; }
        .pick-actions button:hover { text-decoration: underline; }
        .pick-summary { margin-top: 8px; font-size: 12.5px; color: var(--text-dim, #888); }

        /* The run history. Same table and the same pills as provider.php.
           ⚠️ The pill colours are tokens here where provider.php hardcodes the
           light ones — it has a matching dark block further down and this does
           not, so tokens are the only way to get both without repeating
           myself. `stopped` is AMBER on purpose: the safety brake refusing a
           run is the feature working, not a failure, and red would teach
           somebody to treat a refusal as something to fix. */
        table.runs { width: 100%; border-collapse: collapse; font-size: 12.5px; }
        table.runs th { text-align: left; padding: 7px 9px; color: var(--text-dim, #888); font-weight: 600; border-bottom: 1px solid var(--border-soft, #eee); white-space: nowrap; }
        table.runs td { padding: 7px 9px; border-bottom: 1px solid var(--border-soft, #f4f4f4); color: var(--text, #444); white-space: nowrap; }
        .pill { display: inline-block; padding: 1px 9px; border-radius: 10px; font-size: 11px; font-weight: 700; }
        .pill.ok { background: var(--success-bg, #e8f5e9); color: var(--success-text, #2e7d32); }
        .pill.stopped { background: var(--warning-bg, #fff4ce); color: var(--warning-text, #6b5900); }
        .pill.failed, .pill.running { background: var(--danger-bg, #ffebee); color: var(--danger-text, #c62828); }
        @media (max-width: 700px) { .prov-wrap { padding: 14px 12px 50px; } }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=139">
</head>
<body data-mobile-module="system" data-mobile-page="sso-carddav">
<?php include '../includes/header.php'; ?>

<div class="prov-wrap">
    <a class="back-link" href="index.php">&larr; <?php echo htmlspecialchars(t('system.sso.back_to_list')); ?></a>
    <div class="prov-head">
        <div>
            <h1 class="prov-title"><?php echo htmlspecialchars($p['display_name']); ?></h1>
            <div class="prov-sub"><?php echo htmlspecialchars(t('system.sso.carddav_page_sub')); ?></div>
        </div>
    </div>

    <?php renderSettingsTabBar($tabs, $activeTab, 'switchCardDavTab'); ?>

    <div class="prov-card">
        <!-- ================= Connection ================= -->
        <div class="tab-pane<?php echo $activeTab === 'connection' ? ' active' : ''; ?>" id="connection-pane">
            <div class="fld">
                <label for="fDisplayName"><?php echo htmlspecialchars(t('system.sso.field_display_name')); ?></label>
                <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_display_name_hint_carddav')); ?></div>
                <input type="text" id="fDisplayName" value="<?php echo v($p, 'display_name'); ?>">
            </div>
            <div class="fld">
                <label for="fUrl"><?php echo htmlspecialchars(t('system.sso.field_carddav_url')); ?></label>
                <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_carddav_url_hint')); ?></div>
                <input type="text" id="fUrl" value="<?php echo v($p, 'carddav_url'); ?>" placeholder="https://dav.example.com/dav.php/addressbooks/jsmith/">
            </div>
            <div class="fld">
                <label for="fUsername"><?php echo htmlspecialchars(t('system.sso.field_carddav_username')); ?></label>
                <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_carddav_username_hint')); ?></div>
                <input type="text" id="fUsername" value="<?php echo v($p, 'carddav_username'); ?>" autocomplete="off">
            </div>
            <div class="fld">
                <label for="fPassword"><?php echo htmlspecialchars(t('system.sso.field_carddav_password')); ?></label>
                <div class="hint"><?php echo htmlspecialchars($hasPassword ? t('system.sso.carddav_password_stored_hint') : t('system.sso.field_carddav_password_hint')); ?></div>
                <input type="password" id="fPassword" autocomplete="new-password"
                       placeholder="<?php echo $hasPassword ? htmlspecialchars(t('system.sso.secret_stored_placeholder')) : ''; ?>">
            </div>
            <div class="fld">
                <label for="fAuth"><?php echo htmlspecialchars(t('system.sso.field_carddav_auth')); ?></label>
                <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_carddav_auth_hint')); ?></div>
                <select id="fAuth">
                    <?php foreach (['auto', 'digest', 'basic'] as $a): ?>
                        <option value="<?php echo $a; ?>"<?php echo ($p['carddav_auth'] ?? 'auto') === $a ? ' selected' : ''; ?>><?php echo htmlspecialchars(t('system.sso.carddav_auth_' . $a)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fld">
                <label><?php echo htmlspecialchars(t('system.sso.carddav_test')); ?></label>
                <div class="hint"><?php echo htmlspecialchars(t('system.sso.carddav_test_desc')); ?></div>
                <button class="btn btn-test" id="testBtn" type="button"><?php echo htmlspecialchars(t('system.sso.test')); ?></button>
                <div class="result" id="testResult"></div>
            </div>
        </div>

        <!-- ================= Contacts ================= -->
        <div class="tab-pane<?php echo $activeTab === 'contacts' ? ' active' : ''; ?>" id="contacts-pane">
            <div class="fld">
                <label for="fBook"><?php echo htmlspecialchars(t('system.sso.field_carddav_book')); ?></label>
                <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_carddav_book_hint')); ?></div>
                <select id="fBook">
                    <?php if (!empty($p['carddav_addressbook'])): ?>
                        <option value="<?php echo v($p, 'carddav_addressbook'); ?>" selected><?php echo v($p, 'carddav_addressbook'); ?></option>
                    <?php else: ?>
                        <option value=""><?php echo htmlspecialchars(t('system.sso.carddav_book_untested')); ?></option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="fld">
                <label for="fScope"><?php echo htmlspecialchars(t('system.sso.field_carddav_scope')); ?></label>
                <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_carddav_scope_hint')); ?></div>
                <select id="fScope">
                    <?php foreach (['all' => 'carddav_scope_all', 'group' => 'carddav_scope_group', 'category' => 'carddav_scope_category'] as $val => $key): ?>
                        <option value="<?php echo $val; ?>"<?php echo ($p['carddav_scope'] ?? 'all') === $val ? ' selected' : ''; ?>><?php echo htmlspecialchars(t('system.sso.' . $key)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php /* TICK BOXES, and as many as you like.
                     One group was an arbitrary limit — a service desk plausibly
                     wants "itsm" and "partners" and none of the other forty. */ ?>
            <div class="fld" id="pickField" style="display:none;">
                <label id="pickLabel"><?php echo htmlspecialchars(t('system.sso.field_carddav_scope_value')); ?></label>
                <div class="hint" id="pickHint"></div>
                <div class="pick-list" id="pickList"></div>
                <div class="pick-actions" id="pickActions" style="display:none;">
                    <button type="button" id="pickAll"><?php echo htmlspecialchars(t('system.sso.carddav_pick_all')); ?></button>
                    <button type="button" id="pickNone"><?php echo htmlspecialchars(t('system.sso.carddav_pick_none')); ?></button>
                </div>
                <div class="pick-summary" id="pickSummary"></div>
            </div>
            <?php /* Running it lives on the Contacts tab, next to the choices it
                     acts on — not on a tab of its own, and not next to Save,
                     where it would read as part of saving. */ ?>
            <div class="fld" style="border-top:1px solid var(--border-soft,#f0f0f0); padding-top:18px;">
                <label><?php echo htmlspecialchars(t('system.sso.carddav_run_heading')); ?></label>
                <div class="hint"><?php echo htmlspecialchars(t('system.sso.carddav_run_hint')); ?></div>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <?php /* 🔑 Preview FIRST and styled as the quieter button.
                             It runs the identical code path and writes nothing,
                             so it is the sensible thing to press against an
                             address book you have not imported before — and
                             "what would this do to my 600 contacts" is the
                             question somebody actually has. */ ?>
                    <button class="btn btn-test" id="previewBtn" type="button"><?php echo htmlspecialchars(t('system.sso.carddav_preview')); ?></button>
                    <button class="btn btn-primary" id="runBtn" type="button"><?php echo htmlspecialchars(t('system.sso.carddav_run')); ?></button>
                </div>
                <div class="result" id="runResult"></div>
            </div>
        </div>

        <!-- ================= History ================= -->
        <div class="tab-pane<?php echo $activeTab === 'history' ? ' active' : ''; ?>" id="history-pane">
            <div class="fld">
                <label><?php echo htmlspecialchars(t('system.sso.tab_history')); ?></label>
                <div class="hint"><?php echo htmlspecialchars(t('system.sso.carddav_history_hint')); ?></div>
                <div id="runsBox" style="overflow-x:auto;"></div>
            </div>
        </div>
    </div><!-- /.prov-card -->

    <?php /* A SIBLING of the card, not a child — same as provider.php. Inside
             the card the button reads as just another field at the bottom of
             the panel; outside it, with the border-top and the bleed, it reads
             as the page's footer. */ ?>
    <div class="save-bar">
        <button class="btn btn-primary" id="saveBtn" type="button"><?php echo htmlspecialchars(t('system.sso.save')); ?></button>
        <span id="saveMsg" style="font-size:13px;color:var(--text-dim,#888);"></span>
    </div>
</div>

<script>
const API = '<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>api/';
const PROVIDER_ID = <?php echo (int)$p['id']; ?>;
/* What is already saved, so the ticks survive a page load with no test run. */
const SAVED_SCOPE = <?php echo json_encode($scopeChosen); ?>;
const $ = id => document.getElementById(id);

/* renderSettingsTabBar() emits onclick="switchCardDavTab('<id>')" and expects
   THIS page to define it — a settings page that forgets to shipped a dead tab
   once already. */
function switchCardDavTab(id) {
    document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === id));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.toggle('active', p.id === id + '-pane'));
    history.replaceState(null, '', '?id=' + PROVIDER_ID + '&tab=' + id);
    // Loaded on open rather than up front: most visits here are to change a
    // setting, and the history is a query nobody asked for until they click it.
    if (id === 'history') loadRuns();
}

/* ---- running the import ---- */

async function runImport(mode, btn) {
    const box = $('runResult');
    // ⚠️ A live run creates and deactivates people wholesale, so it asks first
    // and names what it is about to read. Preview writes nothing, so it does
    // not — a confirmation on a harmless action teaches people to click through
    // confirmations.
    if (mode === 'live') {
        const scope = $('fScope').value;
        const ok = await showConfirm({
            title:   window.t('system.sso.carddav_run'),
            message: window.t(scope === 'all'
                ? 'system.sso.carddav_run_confirm_all'
                : 'system.sso.carddav_run_confirm_scoped'),
            okLabel: window.t('system.sso.carddav_run'),
            okClass: 'primary'
        });
        if (!ok) return;
    }
    btn.disabled = true;
    box.className = 'result';
    box.textContent = window.t(mode === 'live'
        ? 'system.sso.carddav_running' : 'system.sso.carddav_previewing');
    try {
        const r = await fetch(API + 'system/run_directory_sync.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ provider_id: PROVIDER_ID, mode: mode })
        });
        const d = await r.json();
        if (!d.success) {
            box.className = 'result err';
            box.textContent = d.error || window.t('system.sso.carddav_run_failed');
            return;
        }
        const run = d.run || {};
        // 🔑 'refused' is not a failure and must not be coloured as one — the
        // safety brake stopping a run is the feature working. It gets the error
        // styling only because there is nothing amber here; the WORD is what
        // carries the meaning, and the message says nothing was changed.
        box.className = 'result ' + (run.status === 'ok' ? 'ok' : 'err');
        box.textContent = (mode === 'preview'
            ? window.t('system.sso.carddav_preview_prefix') + ' ' : '') + (run.message || run.status);
        // A live run changes the history, and somebody who just ran one is
        // about to want to look at it.
        if (mode === 'live') loadRuns();
    } catch (e) {
        box.className = 'result err';
        box.textContent = window.t('system.sso.carddav_run_failed') + ' ' + e.message;
    } finally {
        btn.disabled = false;
    }
}
$('previewBtn').addEventListener('click', function () { runImport('preview', this); });
$('runBtn').addEventListener('click', function () { runImport('live', this); });

async function loadRuns() {
    const box = $('runsBox');
    box.textContent = window.t('system.sso.loading');
    try {
        const d = await (await fetch(API + 'system/get_directory_sync_log.php?provider_id=' + PROVIDER_ID)).json();
        if (!d.success || !(d.runs || []).length) {
            box.innerHTML = '';
            const p = document.createElement('div');
            p.className = 'hint';
            p.textContent = window.t('system.sso.history_none');
            box.appendChild(p);
            return;
        }
        const heads = ['when', 'mode', 'result', 'found', 'added', 'changed', 'left', 'issues', 'by'];
        const table = document.createElement('table');
        table.className = 'runs';
        const thead = document.createElement('thead');
        const hr = document.createElement('tr');
        heads.forEach(h => {
            const th = document.createElement('th');
            th.textContent = window.t('system.sso.hist_' + h) || h;
            hr.appendChild(th);
        });
        thead.appendChild(hr); table.appendChild(thead);
        const tbody = document.createElement('tbody');
        d.runs.forEach(r => {
            const tr = document.createElement('tr');
            // ⚠️ textContent throughout, not innerHTML with a template string.
            // A run's message is built from server data including an operator's
            // own address book and group names, and this table is the one place
            // those are displayed back.
            // mode and status are shown as the server records them, exactly as
            // provider.php does — there are no i18n keys for them there, and
            // inventing some here would make the two pages disagree about what
            // a run is called.
            [
                fmtDateTime(r.started_datetime),
                r.mode,
                r.status,
                r.seen_count, r.created_count, r.updated_count,
                r.deactivated_count, r.error_count,
                r.triggered_by || window.t('system.sso.hist_scheduled')
            ].forEach((v, i) => {
                const td = document.createElement('td');
                if (i === 2) {
                    // The status gets the same pill provider.php uses.
                    const pill = document.createElement('span');
                    pill.className = 'pill ' + (v === 'ok' ? 'ok' : (v === 'refused' ? 'stopped' : 'failed'));
                    pill.textContent = String(v);
                    td.appendChild(pill);
                } else {
                    td.textContent = (v === null || v === undefined) ? '—' : String(v);
                }
                tr.appendChild(td);
            });
            tr.title = r.message || '';
            tbody.appendChild(tr);
        });
        table.appendChild(tbody);
        box.innerHTML = '';
        box.appendChild(table);
    } catch (e) {
        box.textContent = window.t('system.sso.request_failed');
    }
}

/* Last scan of the chosen book. null means "not looked yet", which is a
   different state from "looked and found none" and must stay distinguishable. */
let scan = null;

function pickedValues() {
    return [...document.querySelectorAll('#pickList input[type=checkbox]:checked')].map(c => c.value);
}

function renderPicker() {
    const scope = $('fScope').value;
    const field = $('pickField'), list = $('pickList'), hint = $('pickHint');

    if (scope === 'all') { field.style.display = 'none'; return; }
    field.style.display = '';

    const items = scan ? (scope === 'group' ? scan.groups : scan.categories) : null;
    $('pickLabel').textContent = window.t(scope === 'group'
        ? 'system.sso.field_carddav_groups' : 'system.sso.field_carddav_categories');

    // Keep anything already ticked, and anything SAVED that this scan did not
    // return — a tag that exists on the server but on no card right now should
    // not be silently dropped from a working configuration.
    const keep = new Set(pickedValues().length ? pickedValues() : SAVED_SCOPE);

    list.innerHTML = '';
    if (items === null) {
        list.innerHTML = '<div class="pick-empty"></div>';
        list.firstChild.textContent = window.t('system.sso.carddav_scope_untested_hint');
        $('pickActions').style.display = 'none';
        hint.textContent = '';
        updateSummary();
        return;
    }
    if (!items.length) {
        list.innerHTML = '<div class="pick-empty"></div>';
        list.firstChild.textContent = window.t('system.sso.carddav_scope_none_hint');
        $('pickActions').style.display = 'none';
        hint.textContent = '';
        updateSummary();
        return;
    }

    items.forEach(item => {
        const value = scope === 'group' ? (item.uid || item.name) : item.name;
        const count = scope === 'group' ? item.members : item.contacts;
        const row = document.createElement('label');
        row.className = 'pick-row';
        const cb = document.createElement('input');
        cb.type = 'checkbox'; cb.value = value; cb.checked = keep.has(value);
        cb.addEventListener('change', updateSummary);
        const name = document.createElement('span');
        name.className = 'pick-name'; name.textContent = item.name;
        const n = document.createElement('span');
        // Count last and in brackets: t() has no pluralisation, so "1 members"
        // is what "{n} members" would render.
        n.className = 'pick-count'; n.textContent = '(' + count + ')';
        row.append(cb, name, n);
        list.appendChild(row);
    });
    $('pickActions').style.display = '';
    hint.textContent = '';
    updateSummary();
}

function updateSummary() {
    const n = pickedValues().length;
    $('pickSummary').textContent = n
        ? window.t('system.sso.carddav_pick_summary', { n: n })
        : window.t('system.sso.carddav_pick_summary_none');
}

$('fScope').addEventListener('change', renderPicker);
$('fBook').addEventListener('change', function () {
    // A different book's groups say nothing about this one's.
    scan = null;
    renderPicker();
    const box = $('testResult');
    box.className = 'result err';
    box.textContent = window.t('system.sso.carddav_book_changed');
});
$('pickAll').addEventListener('click', () => {
    document.querySelectorAll('#pickList input[type=checkbox]').forEach(c => c.checked = true);
    updateSummary();
});
$('pickNone').addEventListener('click', () => {
    document.querySelectorAll('#pickList input[type=checkbox]').forEach(c => c.checked = false);
    updateSummary();
});

$('testBtn').addEventListener('click', async function () {
    const box = $('testResult');
    const url = $('fUrl').value.trim();
    if (!url) { box.className = 'result err'; box.textContent = window.t('system.sso.carddav_url_required'); return; }
    this.disabled = true;
    box.className = 'result'; box.textContent = window.t('system.sso.carddav_test_running');
    try {
        const r = await fetch(API + 'system/test_carddav_connection.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: PROVIDER_ID,
                carddav_url: url,
                carddav_username: $('fUsername').value.trim(),
                carddav_password: $('fPassword').value,
                carddav_auth: $('fAuth').value,
                carddav_addressbook: $('fBook').value || ''
            })
        });
        const d = await r.json();
        if (!d.success) {
            box.className = 'result err';
            box.textContent = d.error || window.t('system.sso.carddav_test_failed');
            return;
        }
        if (d.warning) { box.className = 'result err'; box.textContent = d.warning; return; }

        // Refill the book list, keeping the current choice if it still exists.
        const book = $('fBook'), previous = book.value;
        book.innerHTML = '';
        d.books.forEach(b => book.appendChild(new Option(b.name + '  (' + b.href + ')', b.href)));
        if (previous && d.books.some(b => b.href === previous)) book.value = previous;

        scan = d.scan || null;
        let txt = d.message;
        if (d.auth_offered) txt += '\n' + window.t('system.sso.carddav_test_auth', { scheme: d.auth_offered });
        if (d.scan) {
            txt += '\n' + window.t('system.sso.carddav_test_scan', {
                contacts: d.scan.contacts, groups: d.scan.groups.length, categories: d.scan.categories.length
            });
        } else if (d.scan_error) {
            txt += '\n' + window.t('system.sso.carddav_scan_failed', { error: d.scan_error });
        } else {
            txt += '\n' + window.t('system.sso.carddav_test_pick');
        }
        box.className = 'result ok';
        box.textContent = txt;
        renderPicker();
    } catch (e) {
        box.className = 'result err';
        box.textContent = window.t('system.sso.carddav_test_failed') + ' ' + e.message;
    } finally {
        this.disabled = false;
    }
});

$('saveBtn').addEventListener('click', async function () {
    const scope = $('fScope').value;
    const picked = pickedValues();
    if (!$('fUrl').value.trim()) { showToast(window.t('system.sso.carddav_url_required'), 'error'); return; }
    if (!$('fBook').value)       { showToast(window.t('system.sso.carddav_book_required'), 'error'); return; }
    // Choosing "only these groups" and ticking none would fall back to importing
    // everything — the exact opposite of what was asked for.
    if (scope !== 'all' && !picked.length) {
        showToast(window.t('system.sso.carddav_scope_required'), 'error');
        return;
    }
    this.disabled = true;
    try {
        const r = await fetch(API + 'system/save_sso_provider.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: PROVIDER_ID,
                protocol: 'carddav',
                display_name: $('fDisplayName').value.trim(),
                carddav_url: $('fUrl').value.trim(),
                carddav_username: $('fUsername').value.trim(),
                carddav_password: $('fPassword').value,
                carddav_auth: $('fAuth').value,
                carddav_addressbook: $('fBook').value,
                carddav_scope: scope,
                // Newline-separated, the same convention as sync_ou_includes.
                carddav_scope_value: scope === 'all' ? '' : picked.join('\n'),
                enabled: 1
            })
        });
        const d = await r.json();
        if (d.success) showToast(window.t('system.sso.provider_saved'), 'success');
        else showToast(window.t('system.sso.error', { error: d.error }), 'error');
    } catch (e) { showToast(window.t('system.sso.save_failed'), 'error'); }
    this.disabled = false;
});

renderPicker();

/* 🔴 And load the history if the page OPENED on that tab. loadRuns() was wired
   only to switchCardDavTab(), so arriving by a link or a refresh with
   ?tab=history showed the heading, the hint and an empty box — the tab looked
   broken, and reloading did not help because reloading was what caused it.
   Caught in a screenshot, not by the harness: the harness clicked the tab, and
   so always took the path that worked. */
if (<?php echo json_encode($activeTab); ?> === 'history') loadRuns();
</script>
</body>
</html>
