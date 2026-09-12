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
];
$activeTab = in_array($_GET['tab'] ?? '', ['connection', 'contacts'], true)
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
        .prov-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin: 10px 0 14px; }
        .prov-title { font-size: 22px; font-weight: 600; margin: 0; color: var(--text, #263238); }
        .prov-sub { font-size: 13px; color: var(--text-dim, #888); margin-top: 3px; }
        .prov-card { background: var(--surface, #fff); border-radius: 8px; padding: 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .back-link { font-size: 13px; color: var(--sys-accent, #546e7a); text-decoration: none; }
        .back-link:hover { text-decoration: underline; }

        .fld { margin-bottom: 18px; }
        .fld label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; color: var(--text, #333); }
        .fld .hint { font-size: 12px; color: var(--text-dim, #888); margin-bottom: 6px; line-height: 1.5; }
        .fld input[type=text], .fld input[type=password], .fld select {
            width: 100%; box-sizing: border-box; padding: 9px 11px; font-size: 13px;
            border: 1px solid var(--border, #ddd); border-radius: 6px;
            background: var(--surface, #fff); color: var(--text, #333);
        }
        .result { margin-top: 8px; font-size: 12px; padding: 9px 11px; border-radius: 6px; display: none; white-space: pre-wrap; line-height: 1.5; }
        .result.ok  { display: block; background: var(--success-bg, #e8f5e9); color: var(--success-text, #2e7d32); }
        .result.err { display: block; background: var(--danger-bg, #ffebee); color: var(--danger-text, #c62828); }
        .btn { padding: 10px 18px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .btn-primary { background: var(--accent); color: var(--on-accent); }
        .btn-test { background: var(--surface-3, #eceff1); color: var(--text, #37474f); border: 1px solid var(--border, #ddd); }
        .btn:disabled { opacity: .55; cursor: not-allowed; }
        .save-bar {
            position: sticky; bottom: 0; background: var(--app-bg, #f5f5f5);
            padding: 14px 0 18px; margin-top: 6px; display: flex; gap: 10px;
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
        </div>

        <div class="save-bar">
            <button class="btn btn-primary" id="saveBtn" type="button"><?php echo htmlspecialchars(t('system.sso.save')); ?></button>
        </div>
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
</script>
</body>
</html>
