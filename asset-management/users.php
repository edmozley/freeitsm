<?php
/**
 * Who holds what — every person with equipment assigned to them, and a
 * handover document for any of them (discussion #56).
 *
 * Deliberately mirrors tickets/users.php: search a list of people on the left,
 * click one, see their things on the right. That page already taught everybody
 * this shape, so this is the same shape with assets in it rather than tickets.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
require_once '../includes/person_editor.php';   // the shared person editor
I18n::initFromSession();
Tz::init();

requireModuleAccess('assets');

$current_page = 'users';
$translationNamespaces = ['common', 'asset-management'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('asset-management.users.page_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=24">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=76">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=3"></script>
    <script src="../assets/js/address-book-add.js?v=1"></script>
    <!-- Neither was loaded here before: the page had no action that could fail,
         so it needed neither a toast nor a confirmation. Both self-guard against
         being loaded twice. -->
    <script src="../assets/js/toast.js"></script>
    <script src="../assets/js/confirm.js"></script>
    <style>
        /* ⚠️ --app-bg, not --bg. There is no --bg token in theme.css, so the
           fallback applied on every theme and the page kept a light background
           while the rest of the module went dark. Same trap as --input-bg. */
        body { margin: 0; background: var(--app-bg, #f5f5f5); }
        .au-wrap {
            display: grid;
            grid-template-columns: 330px 1fr;
            gap: 16px;
            padding: 16px;
            align-items: start;
        }
        .au-panel {
            background: var(--surface, #fff);
            border: 1px solid var(--border, #e0e0e0);
            border-radius: 8px;
            overflow: hidden;
        }
        .au-panel-head {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border, #eee);
            font-weight: 600;
            color: var(--text, #333);
        }
        .au-search { padding: 12px 16px; border-bottom: 1px solid var(--border, #eee); }
        .au-search input {
            width: 100%; box-sizing: border-box;
            padding: 8px 10px;
            border: 1px solid var(--border, #d5dbe1);
            border-radius: 5px;
            background: var(--surface, #fff);
            color: var(--text, #333);
            font-size: 14px;
        }
        .au-list { max-height: calc(100vh - 220px); overflow-y: auto; }
        .au-person {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            padding: 10px 16px;
            border-bottom: 1px solid var(--border-soft, #f2f2f2);
            cursor: pointer;
            transition: background 150ms ease;
        }
        .au-person:hover { background: var(--surface-hover, #f6f6f6); }
        .au-person.selected { background: var(--surface-hover, #eef4fb); box-shadow: inset 3px 0 0 var(--accent, #0078d4); }
        .au-person-name { font-size: 14px; font-weight: 600; color: var(--text, #333); }
        .au-person-email { font-size: 12px; color: var(--text-muted, #666); overflow-wrap: anywhere; }
        .au-count {
            flex-shrink: 0;
            min-width: 22px; padding: 1px 7px; border-radius: 10px;
            background: var(--accent, #0078d4); color: #fff;
            font-size: 11px; font-weight: 700; text-align: center;
        }
        .au-detail-head {
            display: flex; align-items: flex-start; justify-content: space-between;
            gap: 12px; flex-wrap: wrap;
            padding: 16px;
            border-bottom: 1px solid var(--border, #eee);
        }
        .au-detail-name { font-size: 18px; font-weight: 700; color: var(--text, #333); }
        .au-detail-sub { font-size: 13px; color: var(--text-muted, #666); }
        .au-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .au-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px; border-radius: 5px; border: 1px solid var(--border, #d5dbe1);
            background: var(--surface, #fff); color: var(--text, #333);
            font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none;
            transition: background 150ms ease, transform 140ms cubic-bezier(0.23,1,0.32,1);
        }
        .au-btn:hover { background: var(--surface-hover, #f6f6f6); }
        .au-btn:active { transform: scale(0.97); }
        .au-btn.primary { background: var(--accent, #0078d4); border-color: var(--accent, #0078d4); color: #fff; }
        table.au-table { width: 100%; border-collapse: collapse; }
        table.au-table th {
            text-align: left; padding: 10px 16px;
            font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px;
            color: var(--text-muted, #666); border-bottom: 1px solid var(--border, #eee);
            white-space: nowrap;
        }
        table.au-table td {
            padding: 10px 16px; font-size: 13px;
            border-bottom: 1px solid var(--border-soft, #f4f4f4);
            color: var(--text, #333);
        }
        .au-type-pill {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            background: var(--surface-hover, #eef1f4); color: var(--text-muted, #555);
            font-size: 11px; font-weight: 600;
        }
        .au-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
        .au-empty { padding: 44px 20px; text-align: center; color: var(--text-muted, #666); }
        .au-empty-title { font-size: 15px; font-weight: 600; margin-bottom: 4px; color: var(--text, #333); }
        /* --- people directory (directory sync slice 1) --- */
        .au-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .au-head-btn { padding: 4px 12px; font-size: 12px; }
        .au-search { display: flex; gap: 8px; }
        .au-search input[type=search] { flex: 1; }
        .au-search select {
            padding: 7px 10px; border: 1px solid var(--border, #ddd); border-radius: 6px;
            background: var(--surface, #fff); color: var(--text, #333); font-size: 13px;
        }
        .au-person.inactive .au-person-name { color: var(--text-muted, #888); }
        .au-flag {
            display: inline-block; margin-left: 6px; padding: 1px 6px; border-radius: 8px;
            font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
        }
        .au-flag.left    { background: #f8d7da; color: #721c24; }
        .au-flag.managed { background: #dbeafe; color: #1e40af; }
        /* The person's own details, above their equipment. */
        .au-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px 20px; padding: 16px 20px; border-bottom: 1px solid var(--border-soft, #f0f0f0); }
        .au-fact-label { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted, #888); margin-bottom: 2px; }
        .au-fact-value { font-size: 13px; color: var(--text, #333); }
        .au-link { color: var(--accent, #0078d4); text-decoration: none; }
        .au-link:hover { text-decoration: underline; }
        .au-reports { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
        .au-report {
            display: inline-flex; align-items: center;
            padding: 3px 10px; border-radius: 12px;
            background: var(--surface-hover, #eef1f4); color: var(--text, #333);
            font-size: 12px; text-decoration: none;
        }
        .au-report:hover { background: var(--accent, #0078d4); color: #fff; }
        .au-report.inactive { opacity: .65; }
        /* ⚠️ `var(--surface-hover, #eef4ff)` never rendered the blue it looks
           like it asks for: the token exists (#f0f0f0 light / #2a3039 dark) so
           it always won, and only the FALLBACK was tinted. The note was grey,
           and grey on a form is easy to read straight past — which matters here
           because it is the only thing explaining why the fields below will not
           accept typing. Now the same accent tint and left bar as
           `.user-managed-note` on tickets/users.php, so the two people editors
           present an identical record identically.
           🔴 Shared with the leaver note below, which overrides background and
           colour inline — it overrides `border-left-color` too, or it would be
           an amber box wearing a blue bar. */
        .au-managed-note {
            margin: 0 20px 14px; padding: 8px 12px; border-radius: 6px; font-size: 12px;
            background: var(--accent-soft, #e8f4fd); color: var(--text, #333);
            border-left: 3px solid var(--accent, #0078d4);
        }
        /* The person editor brings its own styles: includes/person_editor.php. */

        @media (max-width: 900px) {
            /* One column below tablet — a 330px sidebar beside a table does not
               fit, and a horizontally scrolling page is worse than stacking. */
            .au-wrap { grid-template-columns: 1fr; }
            .au-list { max-height: 320px; }
        }
    </style>
    <!-- Mobile layer: AFTER the page's own <style> so its @media rules win on ties (Techniques §9). -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=175">
</head>
<body data-mobile-module="assets" data-mobile-page="assets-users">
<?php include 'includes/header.php'; ?>

<div class="au-wrap">
    <div class="au-panel">
        <div class="au-panel-head">
            <?php echo htmlspecialchars(t('asset-management.users.list_title')); ?>
            <button type="button" class="au-btn primary au-head-btn" onclick="openPerson(null)">
                <?php echo htmlspecialchars(t('asset-management.users.add')); ?>
            </button>
        </div>
        <div class="au-search">
            <input type="search" id="auSearch" placeholder="<?php echo htmlspecialchars(t('asset-management.users.search_placeholder')); ?>" autocomplete="off">
            <!-- Current is the DEFAULT, not "holding equipment": you cannot issue a
                 laptop to somebody the list refuses to show you, and a new starter
                 holds nothing by definition.
                 ⚠️ "Everyone" genuinely means everyone, leavers included. It used to
                 be the default AND exclude leavers, which is a contradiction — and
                 a filter that says Everyone while hiding people teaches you not to
                 trust any of the others either. -->
            <select id="auScope" onchange="loadPeople(document.getElementById('auSearch').value.trim())">
                <option value="current"><?php echo htmlspecialchars(t('asset-management.users.scope_current')); ?></option>
                <option value="leavers"><?php echo htmlspecialchars(t('asset-management.users.scope_leavers')); ?></option>
                <option value="everyone"><?php echo htmlspecialchars(t('asset-management.users.scope_everyone')); ?></option>
                <option value="holding"><?php echo htmlspecialchars(t('asset-management.users.scope_holding')); ?></option>
            </select>
            <?php /* Where people's details come from. Hidden until anybody is
                     linked to a directory or address book. */ ?>
            <select id="auSource" hidden onchange="loadPeople(document.getElementById('auSearch').value.trim())">
                <option value=""><?php echo htmlspecialchars(t('asset-management.users.source_all')); ?></option>
            </select>
        </div>
        <div class="au-list" id="auList"></div>
    </div>

    <div class="au-panel" id="auDetail">
        <div class="au-empty">
            <div class="au-empty-title"><?php echo htmlspecialchars(t('asset-management.users.select_title')); ?></div>
            <div><?php echo htmlspecialchars(t('asset-management.users.select_hint')); ?></div>
        </div>
    </div>
</div>

<?php /* The person editor - shared with Tickets -> Users (discussion #62). */ ?>
<?php personEditorRender(); ?>

<script>
const API = '../api/assets/';
// Everything about this person, from every module (#153) - for analysts who can open People.
const PERSON_PAGE = <?php echo json_encode(analystCanAccessModule(connectToDatabase(), (int)$_SESSION['analyst_id'], 'people') ? BASE_URL . 'people/person.php?id=' : null); ?>;
// Resolved here: this page does not export the tickets namespace to JavaScript.
const MANAGER_ACCESS_LABEL = <?php echo json_encode(t('tickets.manager_access.button'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
let people = [];
let selectedId = null;

const esc = s => String(s ?? '').replace(/[&<>"']/g, c =>
    ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));

/**
 * The date a thing was issued, or a dash.
 *
 * 🔴 This was a top-level `function fmtDate`, which SHADOWED tz.js's global
 * fmtDate for the whole page - so its last line called itself with a Date,
 * which never parses, and the Assigned column printed "—" for everybody from
 * #1195 onwards. The same commit renamed triage's copy for exactly this
 * reason. tz.js's fmtDate already reads a stored UTC string as UTC.
 */
function fmtAssigned(d) {
    return (d && window.fmtDate(d)) || '—';
}

/** "Baikal (address book)" - a source by name and by what kind it is. */
function sourceKind(protocol) {
    return window.t('asset-management.users.kind_' + (protocol || 'ldap'));
}

/**
 * Where this person's details come from, by name, and what that means for
 * editing. The "cannot be edited" half only when something actually is locked:
 * an address book with write-back on locks nothing, and saying otherwise sent
 * people to the address book for a change they could have made here.
 */
function sourceNote(p) {
    if (!p.source_name && !p.is_managed) return '';
    let text = '';
    if (p.source_name) {
        text = window.t(p.is_managed ? 'asset-management.users.source_line' : 'asset-management.users.signs_in_line',
                        { name: p.source_name, kind: sourceKind(p.managed_protocol) });
    }
    if (p.is_managed) {
        const locked = (p.managed_fields || []).length > 0;
        // The long note names "your directory" itself, so it is only for a
        // source whose name is unknown (a provider since deleted).
        text += (text ? ' ' : '') + window.t(!locked
            ? 'asset-management.users.managed_note_book'
            : (p.source_name ? 'asset-management.users.managed_locked' : 'asset-management.users.managed_note'));
    }
    return '<div class="au-managed-note">' + esc(text) + '</div>';
}

/** The Source filter's options, kept in step with what exists. */
function paintSources(sources) {
    const sel = document.getElementById('auSource');
    const keep = sel.value;
    sel.innerHTML = '';
    const add = (value, label) => {
        const o = document.createElement('option');
        o.value = value; o.textContent = label;
        sel.appendChild(o);
    };
    add('', window.t('asset-management.users.source_all'));
    if (sources.length) {
        add('local', window.t('asset-management.users.source_local'));
        sources.forEach(s => add(String(s.id), window.t('asset-management.users.source_option', {
            name: s.name, kind: sourceKind(s.protocol), n: s.people
        })));
    }
    sel.value = Array.from(sel.options).some(o => o.value === keep) ? keep : '';
    sel.hidden = !sources.length && sel.value === '';
}

async function loadPeople(search) {
    const list = document.getElementById('auList');
    list.innerHTML = '<div class="au-empty">' + esc(window.t('asset-management.users.loading')) + '</div>';
    try {
        const scope = document.getElementById('auScope').value;
        const source = document.getElementById('auSource').value;
        const url = API + 'get_people.php?include_sources=1&scope=' + encodeURIComponent(scope)
                  + (search ? '&search=' + encodeURIComponent(search) : '')
                  + (source ? '&source=' + encodeURIComponent(source) : '');
        const d = await (await fetch(url)).json();
        if (!d.success) throw new Error(d.error || 'error');
        people = d.users || [];
        paintSources(d.sources || []);
        renderPeople();
    } catch (e) {
        list.innerHTML = '<div class="au-empty">' + esc(window.t('asset-management.users.load_failed')) + '</div>';
    }
}

function renderPeople() {
    const list = document.getElementById('auList');
    if (!people.length) {
        list.innerHTML = '<div class="au-empty">' + esc(window.t('asset-management.users.none')) + '</div>';
        return;
    }
    list.innerHTML = people.map(p => `
        <div class="au-person ${selectedId === p.id ? 'selected' : ''} ${p.is_active ? '' : 'inactive'}" onclick="selectPerson(${p.id})">
            <div>
                <div class="au-person-name">${esc(p.name)}${
                    p.is_active ? '' : '<span class="au-flag left">' + esc(window.t('asset-management.users.flag_left')) + '</span>'
                }${
                    // "Address book" or "Directory" - they are not the same thing,
                    // and the name is in the tooltip.
                    p.is_managed ? '<span class="au-flag managed" title="' + esc(p.source_name || '') + '">'
                        + esc(window.t(p.managed_protocol === 'carddav'
                            ? 'asset-management.users.flag_carddav'
                            : 'asset-management.users.flag_managed')) + '</span>' : ''
                }</div>
                <div class="au-person-email">${esc(p.email || p.username || p.directory_username || '')}</div>
            </div>
            <span class="au-count">${p.asset_count}</span>
        </div>`).join('');
}

/** The row we already hold for a person, so the editor and detail panel do not
 *  each need their own round trip for facts the list already fetched. */
function personById(id) { return people.find(p => p.id === id) || null; }

async function selectPerson(id) {
    selectedId = id;
    renderPeople();
    const panel = document.getElementById('auDetail');
    panel.innerHTML = '<div class="au-empty">' + esc(window.t('asset-management.users.loading')) + '</div>';
    try {
        const d = await (await fetch(API + 'get_user_assets.php?user_id=' + encodeURIComponent(id))).json();
        if (!d.success) throw new Error(d.error || 'error');
        renderDetail(d.user, d.assets || []);
        // "Add to address book" - only asked for somebody linked to nothing; the
        // server decides whether anything is offered.
        if (!d.user.source_name && window.AddressBookAdd) {
            AddressBookAdd.mount(document.getElementById('addToBookHost'), d.user.id,
                d.user.name || d.user.email || '', '../api/tickets/', async () => {
                    await loadPeople(document.getElementById('auSearch').value.trim());
                    selectPerson(d.user.id);
                });
        }
    } catch (e) {
        panel.innerHTML = '<div class="au-empty">' + esc(window.t('asset-management.users.load_failed')) + '</div>';
    }
}

/**
 * What to call a piece of equipment (discussion #85).
 *
 * ⚠️ The fallbacks matter. This column used to print the hostname or an em
 * dash, so a device with no hostname — a monitor, a docking station, a phone —
 * had no name at all, and "make the asset name clickable" would have given it
 * a link with nothing to click. Same order as the contract and ticket pickers.
 */
function assetLabel(a) {
    return a.hostname
        || a.asset_tag
        || a.service_tag
        || [a.manufacturer, a.model].filter(Boolean).join(' ')
        || ('#' + a.id);
}

function renderDetail(user, assets) {
    const panel = document.getElementById('auDetail');
    const rows = assets.length ? assets.map(a => `
        <tr>
            <td>${a.asset_type ? '<span class="au-type-pill">' + esc(a.asset_type) + '</span>' : '—'}</td>
            <td><strong><a class="au-link" href="index.php?asset_id=${a.id}">${esc(assetLabel(a))}</a></strong></td>
            <td>${esc([a.manufacturer, a.model].filter(Boolean).join(' ') || '—')}</td>
            <td class="au-mono">${esc(a.service_tag || '—')}</td>
            <td class="au-mono">${esc(a.asset_tag || '—')}</td>
            <td>${esc(fmtAssigned(a.assigned_datetime))}</td>
        </tr>`).join('')
        : `<tr><td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted,#666);">
             ${esc(window.t('asset-management.users.no_assets'))}</td></tr>`;

    // The endpoint now returns the whole person, so the panel is complete however
    // you got here — including by clicking somebody who is not in the current
    // filter, which used to give a detail panel with no details.
    const p = user;
    const fact = (labelKey, value) => value
        ? `<div><div class="au-fact-label">${esc(window.t(labelKey))}</div>
                <div class="au-fact-value">${esc(value)}</div></div>` : '';
    // The manager is a link, not text: the reporting line is only useful if you
    // can walk it, in both directions.
    const managerFact = p.manager_name
        ? `<div><div class="au-fact-label">${esc(window.t('asset-management.users.f_manager'))}</div>
                <div class="au-fact-value"><a href="#" class="au-link" onclick="selectPerson(${p.manager_id});return false;">${esc(p.manager_name)}</a></div>
           </div>` : '';
    const facts = [
        fact('asset-management.users.f_job_title',  p.job_title),
        fact('asset-management.users.f_department', p.department),
        fact('asset-management.users.f_office',     p.office),
        managerFact,
        fact('asset-management.users.f_phone',      p.phone),
        fact('asset-management.users.f_mobile',     p.mobile),
        fact('asset-management.users.f_employee_id',p.employee_id),
        fact('asset-management.users.f_username',   p.username || p.directory_username),
    ].join('');

    // Who reports to them. Stored once pointing upwards, so this is the only
    // place the downward view exists.
    const reports = (p.reports || []);
    const reportsBlock = reports.length ? `
        <div class="au-facts" style="padding-top:0;">
            <div style="grid-column:1/-1;">
                <div class="au-fact-label">${esc(window.t('asset-management.users.manages', { n: reports.length }))}</div>
                <div class="au-reports">${reports.map(r =>
                    `<a href="#" class="au-report ${r.is_active ? '' : 'inactive'}" onclick="selectPerson(${r.id});return false;">${esc(r.name)}${
                        r.is_active ? '' : ' <span class="au-flag left">' + esc(window.t('asset-management.users.flag_left')) + '</span>'
                    }</a>`).join('')}</div>
            </div>
        </div>` : '';

    panel.innerHTML = `
        <div class="au-detail-head">
            <div>
                <div class="au-detail-name">${esc(user.name)}${
                    p.is_active === false ? '<span class="au-flag left">' + esc(window.t('asset-management.users.flag_left')) + '</span>' : ''
                }</div>
                <div class="au-detail-sub">${esc(user.email || '')}</div>
                <div class="au-detail-sub">${esc(window.t('asset-management.users.holding', { n: assets.length }))}</div>
            </div>
            <div class="au-actions">
                <span id="addToBookHost" data-user-id="${user.id}" data-btn-class="au-btn" style="display:contents;"></span>
                ${PERSON_PAGE ? `<a class="au-btn" href="${PERSON_PAGE}${user.id}">${esc(window.t('common.modules.people.name'))}</a>` : ''}
                <button type="button" class="au-btn" onclick="openPerson(${user.id})">
                    ${esc(window.t('asset-management.users.edit'))}
                </button>
                <button type="button" class="au-btn" onclick="toggleActive(${user.id})">
                    ${esc(window.t(p.is_active === false ? 'asset-management.users.reactivate' : 'asset-management.users.deactivate'))}
                </button>
                <a class="au-btn" href="manager-access.php?user_id=${user.id}">${esc(MANAGER_ACCESS_LABEL)}</a>
                <a class="au-btn primary" href="handover.php?user_id=${user.id}" target="_blank" rel="noopener">
                    ${esc(window.t('asset-management.users.handover'))}
                </a>
            </div>
        </div>
        ${facts ? '<div class="au-facts">' + facts + '</div>' : ''}
        ${reportsBlock}
        ${sourceNote(p)}
        ${(p.is_active === false && assets.length)
            ? '<div class="au-managed-note" style="background:#fff4ce;color:#6b5900;border-left-color:#b45309;">'
              + esc(window.t('asset-management.users.leaver_holding', { n: assets.length })) + '</div>'
            : ''}
        <table class="au-table">
            <thead><tr>
                <th>${esc(window.t('asset-management.users.col_type'))}</th>
                <th>${esc(window.t('asset-management.users.col_name'))}</th>
                <th>${esc(window.t('asset-management.users.col_model'))}</th>
                <th>${esc(window.t('asset-management.users.col_serial'))}</th>
                <th>${esc(window.t('asset-management.users.col_tag'))}</th>
                <th>${esc(window.t('asset-management.users.col_assigned'))}</th>
            </tr></thead>
            <tbody>${rows}</tbody>
        </table>`;
}

/* ------------------------------------------------------------------
   Person editor: the ONE shared editor (includes/person_editor.php),
   also opened from Tickets -> Users (discussion #62). This page only says
   what to do after a save - refresh the list and reselect the person.
   ------------------------------------------------------------------ */
function openPerson(id) {
    PersonEditor.open(id || null, {
        onSaved: async function (savedId) {
            await loadPeople(document.getElementById('auSearch').value.trim());
            if (savedId) selectPerson(savedId);
        }
    });
}

async function toggleActive(id) {
    const p = personById(id);
    if (!p) return;
    const goingInactive = p.is_active !== false;

    // Holding equipment is the whole reason deactivation matters here, so it is
    // said out loud rather than discovered later in a report.
    const msg = goingInactive
        ? window.t(p.asset_count
            ? 'asset-management.users.confirm_deactivate_holding'
            : 'asset-management.users.confirm_deactivate', { name: p.name, n: p.asset_count })
        : window.t('asset-management.users.confirm_reactivate', { name: p.name });

    // showConfirm takes an OPTIONS OBJECT, not a string — passing a string
    // silently produces an empty dialog with a default title.
    const confirmed = await showConfirm({
        title:    window.t(goingInactive ? 'asset-management.users.deactivate' : 'asset-management.users.reactivate'),
        message:  msg,
        okLabel:  window.t(goingInactive ? 'asset-management.users.deactivate' : 'asset-management.users.reactivate'),
        okClass:  goingInactive ? 'danger' : 'primary'
    });
    if (!confirmed) return;

    try {
        const r = await fetch('../api/tickets/save_user.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, is_active: goingInactive ? 0 : 1 })
        });
        const d = await r.json();
        if (!d.success) { showToast(d.error || 'Failed', 'error'); return; }
        await loadPeople(document.getElementById('auSearch').value.trim());
        selectPerson(id);
        showToast(window.t(goingInactive ? 'asset-management.users.deactivated'
                                         : 'asset-management.users.reactivated'), 'success');
    } catch (e) {
        showToast(String(e.message || e), 'error');
    }
}

// Debounced so typing does not fire a request per keystroke.
let searchTimer = null;
document.getElementById('auSearch').addEventListener('input', function () {
    clearTimeout(searchTimer);
    const v = this.value.trim();
    searchTimer = setTimeout(() => loadPeople(v), 250);
});

/**
 * Deep link: ?user_id=N opens straight to that person (Ed, alongside #85).
 *
 * ⚠️ ONE spelling. The asset page accepts both ?asset_id= and ?asset= only
 * because the second was already in the wild; its own comment says a reader
 * that quietly takes both spellings is how the two drifted apart. Nothing
 * links here yet, so there is nothing to be tolerant of — `user_id` and
 * nothing else.
 *
 * ⚠️ selectPerson() is called WITHOUT waiting for the list, and deliberately.
 * It fetches the whole person itself, so it works for somebody who is not in
 * the current scope at all — a leaver, say, whom the default "current" filter
 * hides. Gating it on the list would make exactly those links dead.
 */
loadPeople('');

(function () {
    var n = parseInt(new URLSearchParams(window.location.search).get('user_id') || '', 10);
    if (n > 0) selectPerson(n);
})();
</script>
<script src="../assets/js/mobile.js?v=73"></script>
</body>
</html>
