<?php
/**
 * The ONE editor for a person (a `users` row: a requester, not an analyst).
 *
 * WHY THERE IS ONE
 * ----------------
 * Tickets -> Users and Assets -> Users each grew their own editor, both posting
 * to api/tickets/save_user.php, and they drifted exactly as includes/users.php
 * warned field lists would:
 *   - Assets had no preferred name, password or company;
 *   - Assets CLEARED a manager the analyst could not see (no option matched, so
 *     the select posted "no manager") - Tickets had long since guarded that;
 *   - Assets posted to a Tickets-only endpoint, so an analyst with Assets and
 *     not Tickets was offered Edit and refused on Save.
 * Managers (discussion #62) make the reporting line a question of who may read
 * whose tickets, so two editors disagreeing about it stopped being tolerable.
 * This is the superset of both, and each screen opens it:
 *
 *     PersonEditor.open(id | null, { onSaved: async function (savedId) {...} })
 *
 * WHAT IT MUST KEEP DOING (each one was a bug once)
 * -------------------------------------------------
 *  - Directory-owned fields are PER RECORD (managed_fields from the server):
 *    LDAP owns all seven, an address book five, one with write-back none. They
 *    are shown disabled and LEFT OUT of the payload - save_user.php refuses the
 *    whole save if a managed record's body mentions an owned key.
 *  - A manager the analyst cannot see is kept: the key is omitted, never sent
 *    as "no manager".
 *  - Company: blank means "work it out from their email" for a NEW person
 *    (key omitted) and "no company" for an existing one (sent). Hidden, and never
 *    sent, on a single-company install.
 *  - The address book is a SEPARATE outcome from the save and is always said
 *    out loud: written / conflict (stays up) / failed (stays up).
 *  - Absent means "don't touch" on the server, so only shown fields are sent.
 *  - JSON Content-Type on every write, or request_guard.php refuses it.
 *
 * Deliberately NOT here: Delete (Tickets -> Users) and Deactivate/Reactivate
 * (Assets -> Users) stay on their screens' own buttons.
 */

require_once __DIR__ . '/users.php';   // USER_PERSON_FIELDS

/** Print the editor's markup, styles and script. Call once, inside <body>. */
function personEditorRender(): void
{
    $base = defined('BASE_URL') ? BASE_URL : '/';

    // How each person field is drawn. Which fields EXIST is USER_PERSON_FIELDS'
    // decision; a field added there without a row here is skipped, not a crash.
    $fieldDefs = [
        'job_title'   => ['type' => 'text', 'max' => 150, 'help' => null],
        'department'  => ['type' => 'text', 'max' => 150, 'help' => null],
        'office'      => ['type' => 'text', 'max' => 150, 'help' => 'office_help'],
        'phone'       => ['type' => 'tel',  'max' => 50,  'help' => null],
        'mobile'      => ['type' => 'tel',  'max' => 50,  'help' => null],
        'employee_id' => ['type' => 'text', 'max' => 64,  'help' => 'employee_id_help'],
        'manager_id'  => ['type' => 'select', 'max' => null, 'help' => 'manager_help'],
    ];
    $m = function (string $k) { return t('tickets.users.modal.' . $k); };

    // Strings the script needs, resolved here: the page may not have exported
    // the tickets namespace to JavaScript (Assets -> Users does not).
    $js = [
        'add_title'          => $m('add_title'),
        'edit_title'         => $m('edit_title'),
        'company_auto'       => $m('company_auto'),
        'company_none'       => $m('company_none'),
        'cost_centre_none'   => $m('cost_centre_none'),
        'location_none'      => $m('location_none'),
        'cost_centre_inactive' => $m('cost_centre_inactive'),
        'manager_none'       => $m('manager_none'),
        'manager_search'     => $m('manager_search'),
        'manager_hidden'     => $m('manager_hidden'),
        'ab_written'         => $m('ab_written'),
        'ab_conflict'        => $m('ab_conflict'),
        'ab_failed'          => $m('ab_failed'),
        'saved'              => $m('saved'),
        'need_name_or_email' => $m('need_name_or_email'),
        'load_failed'        => $m('load_failed'),
        'unknown_name'       => t('tickets.users.unknown_name'),
    ];
    ?>
    <style>
        /* Scoped: the editor lands on pages with their own modal styles. */
        #personEditor .modal-content { max-width: 560px; }
        /* Accent blue on purpose, NOT amber: Assets -> Users spends amber on "a
           leaver is still holding equipment", something to act on. This note says
           nothing is wrong, the details simply belong to the directory. */
        #personEditor .pe-managed-note {
            background-color: var(--accent-soft, #e8f4fd);
            border: 1px solid var(--border, #e0e0e0);
            border-left: 3px solid var(--accent, #0078d4);
            border-radius: 4px;
            padding: 10px 12px;
            margin-bottom: 16px;
            font-size: var(--font-size-md, 13px);
            color: var(--text, #333);
        }
        #personEditor .pe-help { color: var(--text-muted, #666); display: block; margin-top: 4px; }
        /* Manager type-ahead: searched on the server a few rows at a time,
           because on a large organisation a <select> of everyone is thousands
           of options (and was a full list download every time it opened). */
        #personEditor .pe-mgr { position: relative; }
        #personEditor .pe-mgr-row { display: flex; gap: 6px; }
        #personEditor .pe-mgr-row input { flex: 1; min-width: 0; }
        #personEditor .pe-mgr-clear { border: 1px solid var(--border, #ccc); background: var(--surface, #fff); color: var(--text-muted, #666);
            border-radius: 4px; padding: 0 10px; cursor: pointer; font-size: var(--font-size-xl, 16px); line-height: 1; }
        #personEditor .pe-mgr-clear[hidden] { display: none; }
        #personEditor .pe-mgr-list { position: absolute; left: 0; right: 0; top: 100%; z-index: 5; margin: 2px 0 0; padding: 0; list-style: none;
            background: var(--surface, #fff); border: 1px solid var(--border, #ccc); border-radius: 4px; box-shadow: 0 4px 12px var(--shadow, rgba(0,0,0,.15));
            max-height: 220px; overflow: auto; }
        #personEditor .pe-mgr-list[hidden] { display: none; }
        #personEditor .pe-mgr-list li { padding: 7px 10px; cursor: pointer; font-size: var(--font-size-md, 13px); color: var(--text, #333); }
        #personEditor .pe-mgr-list li small { display: block; color: var(--text-muted, #666); }
        #personEditor .pe-mgr-list li.active, #personEditor .pe-mgr-list li:hover { background: var(--surface-hover, #f3f3f3); }
        #personEditor .pe-err { color: var(--danger-text, #b3261e); font-size: var(--font-size-md, 13px); min-height: 1em; margin: 4px 0 0; }
        /* inbox.css has NO :disabled rule for form controls, so a disabled input
           inherits the browser default - which in the dark theme is very nearly
           indistinguishable from an editable one. The managed note explains WHY
           fields are locked; the fields themselves have to LOOK locked too, or
           the note reads as a mistake. Scoped here rather than app-wide. */
        #personEditor input:disabled,
        #personEditor select:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            background-color: var(--surface-3, #f8f8f8);
        }
    </style>

    <div class="modal" id="personEditor" role="dialog" aria-modal="true" aria-labelledby="peTitle">
        <div class="modal-content">
            <div class="modal-header" id="peTitle"></div>
            <form id="peForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" id="peId">

                    <div class="form-group">
                        <label for="peEmail"><?php echo htmlspecialchars($m('email')); ?></label>
                        <input type="email" id="peEmail" autocomplete="off" placeholder="<?php echo htmlspecialchars($m('email_placeholder')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="peDisplayName"><?php echo htmlspecialchars($m('display_name')); ?></label>
                        <input type="text" id="peDisplayName" autocomplete="off" placeholder="<?php echo htmlspecialchars($m('display_name_placeholder')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="pePreferredName"><?php echo htmlspecialchars($m('preferred_name')); ?></label>
                        <input type="text" id="pePreferredName" autocomplete="off" placeholder="<?php echo htmlspecialchars($m('preferred_name_placeholder')); ?>">
                    </div>

                    <div id="peManagedNote" class="pe-managed-note" hidden><?php echo htmlspecialchars($m('managed_note')); ?></div>

                    <?php foreach (USER_PERSON_FIELDS as $f):
                        if (!isset($fieldDefs[$f])) continue;
                        $d = $fieldDefs[$f]; ?>
                    <div class="form-group">
                        <label for="pe_<?php echo $f; ?>"><?php echo htmlspecialchars($m($f === 'manager_id' ? 'manager' : $f)); ?></label>
                        <?php if ($d['type'] === 'select'): ?>
                            <?php /* The value lives in the hidden input (the id the save reads);
                                     the text box is only for finding somebody. */ ?>
                            <div class="pe-mgr">
                                <input type="hidden" id="pe_<?php echo $f; ?>" data-field="<?php echo $f; ?>">
                                <div class="pe-mgr-row">
                                    <input type="text" id="peMgrInput" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="peMgrList" aria-expanded="false"
                                           placeholder="<?php echo htmlspecialchars($m('manager_search')); ?>">
                                    <button type="button" class="pe-mgr-clear" id="peMgrClear" aria-label="<?php echo htmlspecialchars($m('manager_clear')); ?>" title="<?php echo htmlspecialchars($m('manager_clear')); ?>">&times;</button>
                                </div>
                                <ul class="pe-mgr-list" id="peMgrList" role="listbox" hidden></ul>
                            </div>
                        <?php else: ?>
                            <input type="<?php echo $d['type']; ?>" id="pe_<?php echo $f; ?>" data-field="<?php echo $f; ?>" autocomplete="off"
                                   maxlength="<?php echo (int)$d['max']; ?>" placeholder="<?php echo htmlspecialchars($m($f . '_placeholder')); ?>">
                        <?php endif; ?>
                        <?php if ($d['help']): ?><small class="pe-help"><?php echo htmlspecialchars($m($d['help'])); ?></small><?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <div class="form-group" id="peCompanyGroup" hidden>
                        <label for="peCompany"><?php echo htmlspecialchars($m('company')); ?></label>
                        <select id="peCompany"></select>
                        <small class="pe-help"><?php echo htmlspecialchars($m('company_help')); ?></small>
                    </div>

                    <!-- GH #160: the cost centre this person is charged to - one of their
                         company's active ones (no company = the Default company's). -->
                    <div class="form-group" id="peCostCentreGroup" hidden>
                        <label for="peCostCentre"><?php echo htmlspecialchars($m('cost_centre')); ?></label>
                        <select id="peCostCentre"></select>
                    </div>

                    <!-- GH #311: where this person sits - one of their company's locations or a
                         shared one (System -> Locations). Separate from Office, which a
                         directory may fill in. Hidden while there are no locations at all. -->
                    <div class="form-group" id="peLocationGroup" hidden>
                        <label for="peLocation"><?php echo htmlspecialchars($m('location')); ?></label>
                        <select id="peLocation"></select>
                        <small class="pe-help"><?php echo htmlspecialchars($m('location_help')); ?></small>
                    </div>

                    <!-- GH #279: custom fields (System -> Custom fields) - the shared ones and the
                         person's company's own, drawn by assets/js/ticket-fields.js. -->
                    <div id="peCustomFields" class="pe-custom-fields"></div>

                    <div class="form-group">
                        <label for="pePassword"><?php echo htmlspecialchars($m('password')); ?></label>
                        <input type="password" id="pePassword" autocomplete="new-password" minlength="8" placeholder="<?php echo htmlspecialchars($m('password_placeholder')); ?>">
                        <small class="pe-help"><?php echo htmlspecialchars($m('password_help')); ?></small>
                    </div>
                    <p class="pe-err" id="peErr"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="PersonEditor.close()"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary" id="peSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <?php /* GH #279: the shared field renderer (also loaded by the Tickets page). */ ?>
    <script src="<?php echo htmlspecialchars($base); ?>assets/js/ticket-fields.js?v=2"></script>
    <script>
    window.PersonEditor = (function () {
        const API = <?php echo json_encode($base . 'api/'); ?>;
        const T = <?php echo json_encode($js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
        const PERSON_FIELDS = <?php echo json_encode(array_values(array_filter(USER_PERSON_FIELDS, fn($f) => isset($fieldDefs[$f])))); ?>;
        const $ = id => document.getElementById(id);
        const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };
        const fmt = (s, p) => String(s).replace(/\{(\w+)\}/g, (_, k) => (p && k in p) ? p[k] : '{' + k + '}');
        const toast = (msg, type, ms) => { if (typeof window.showToast === 'function') window.showToast(msg, type, ms); };

        let companies = null;     // loaded once: the companies THIS analyst can reach
        let owned = [];           // directory-owned fields for the person open now
        let onSaved = null;
        let isNew = true;

        async function getJson(url) {
            const r = await fetch(url, { credentials: 'same-origin' });
            return r.json();
        }

        async function loadCompanies() {
            if (companies !== null) return companies;
            try {
                const d = await getJson(API + 'system/get_tenants.php?accessible=1');
                companies = d.success ? (d.companies || []) : [];
            } catch (e) { companies = []; }
            return companies;
        }

        function paintCompanies(tenantId) {
            const group = $('peCompanyGroup'), sel = $('peCompany');
            if (!companies || companies.length < 2) { group.hidden = true; sel.innerHTML = ''; return; }
            let html = '<option value="">' + esc(isNew ? T.company_auto : T.company_none) + '</option>';
            companies.forEach(c => {
                if (!c.is_active && String(c.id) !== String(tenantId)) return;   // retired, unless already filed there
                html += '<option value="' + c.id + '">' + esc(c.name) + '</option>';
            });
            sel.innerHTML = html;
            sel.value = (tenantId === null || tenantId === undefined) ? '' : String(tenantId);
            group.hidden = false;
        }

        // ── Cost centre (GH #160) ───────────────────────────────────────────
        // The company the person is (or will be) in decides the list; changing
        // the company refills it. Hidden while there are no cost centres at all.
        let ccCurrent = null;
        async function paintCostCentres(keepChoice) {
            const group = $('peCostCentreGroup'), sel = $('peCostCentre');
            const want = keepChoice ? (sel.value || ccCurrent || '') : (ccCurrent || '');
            const company = (companies && companies.length >= 2) ? $('peCompany').value : '';
            try {
                const d = await getJson(API + 'cost-centres/options.php?' + new URLSearchParams({ company_id: company, keep: ccCurrent || '' }));
                const list = d.success ? d.cost_centres : [];
                sel.innerHTML = '<option value="">' + esc(T.cost_centre_none) + '</option>'
                    + list.map(c => '<option value="' + c.id + '">' + esc(c.code + ' ' + c.name) + (c.is_active ? '' : ' (' + esc(T.cost_centre_inactive) + ')') + '</option>').join('');
                sel.value = list.some(c => String(c.id) === String(want)) ? String(want) : '';
                group.hidden = list.length === 0 && !ccCurrent;
            } catch (e) { group.hidden = true; }
        }
        $('peCompany').addEventListener('change', () => paintCostCentres(true));

        // ── Location (GH #311) ──────────────────────────────────────────────
        // Same shape as the cost centre: the company decides the list, changing
        // it refills it, and a location the new company cannot use is dropped.
        let locCurrent = null, locCurrentPath = null;
        async function paintLocations(keepChoice) {
            const group = $('peLocationGroup'), sel = $('peLocation');
            const want = keepChoice ? (sel.value || '') : (locCurrent || '');
            const company = (companies && companies.length >= 2) ? $('peCompany').value : '';
            try {
                const d = await getJson(API + 'locations/options.php?' + new URLSearchParams({ company_id: company }));
                const list = d.success ? d.locations : [];
                sel.innerHTML = '<option value="">' + esc(T.location_none) + '</option>'
                    + list.map(l => '<option value="' + l.id + '">' + esc(l.path) + '</option>').join('');
                sel.value = list.some(l => String(l.id) === String(want)) ? String(want) : '';
                group.hidden = list.length === 0 && !locCurrent;
            } catch (e) { group.hidden = true; }
        }
        $('peCompany').addEventListener('change', () => paintLocations(true));

        // ── Custom fields (GH #279) ─────────────────────────────────────────
        // The shared person fields plus the company's own. A different company
        // means a different set, so changing it fetches them again - keeping what
        // was typed into any field the two companies share - and the save sends
        // exactly the fields it will accept.
        function paintCustom(fields) {
            const box = $('peCustomFields');
            if (!box) return;
            box.innerHTML = (window.TicketFields && fields && fields.length)
                ? TicketFields.render(fields, { inputClass: '', groupClass: 'form-group' }) : '';
        }
        async function refetchCustom() {
            const box = $('peCustomFields');
            if (!box || !window.TicketFields) return;
            const typed = TicketFields.collect(box);
            const company = (companies && companies.length >= 2) ? $('peCompany').value : '';
            try {
                const d = await getJson(API + 'people/person_fields.php?' + new URLSearchParams({ company_id: company }));
                const fields = d.success ? d.fields : [];
                fields.forEach(f => { if (Object.prototype.hasOwnProperty.call(typed, f.key) && typed[f.key] !== '') f.value = f.type === 'boolean' ? (typed[f.key] === '1' ? true : typed[f.key] === '0' ? false : null) : typed[f.key]; });
                paintCustom(fields);
            } catch (e) { /* the form keeps what it had */ }
        }
        $('peCompany').addEventListener('change', refetchCustom);

        // ── Manager type-ahead ──────────────────────────────────────────────
        // Searched on get_users.php, which is scoped to the people THIS analyst
        // may see - so only they can be offered (save_user.php re-checks). A few
        // rows per pause in typing; never the whole directory.
        //
        // The chosen manager lives in the hidden #pe_manager_id; data-name is
        // their name, so leaving the box can put it back; data-unresolved keeps a
        // manager this analyst cannot see (see paintManager).
        let mgrSelf = null, mgrTimer = null, mgrSeq = 0, mgrRows = [], mgrActive = -1;

        function mgrClose() {
            $('peMgrList').hidden = true;
            $('peMgrInput').setAttribute('aria-expanded', 'false');
            mgrActive = -1;
        }

        function mgrSet(id, name) {
            const hid = $('pe_manager_id');
            hid.value = id ? String(id) : '';
            hid.dataset.unresolved = '';
            hid.dataset.name = name || '';
            $('peMgrInput').value = name || '';
            $('peMgrInput').placeholder = T.manager_search;
            $('peMgrClear').hidden = false;
            mgrClose();
        }

        function mgrPaint() {
            $('peMgrList').innerHTML = mgrRows.map((u, i) =>
                '<li role="option" data-i="' + i + '"' + (i === mgrActive ? ' class="active" aria-selected="true"' : '') + '>'
                + esc(u.display_name || u.email || u.username || T.unknown_name)
                + ((u.email && u.display_name) ? '<small>' + esc(u.email) + '</small>' : '') + '</li>').join('');
            $('peMgrList').hidden = mgrRows.length === 0;
            $('peMgrInput').setAttribute('aria-expanded', mgrRows.length ? 'true' : 'false');
        }

        async function mgrSearch(q) {
            const mine = ++mgrSeq;
            let rows = [];
            try {
                const d = await getJson(API + 'tickets/get_users.php?limit=20&search=' + encodeURIComponent(q));
                rows = d.success ? (d.users || []) : [];
            } catch (e) { rows = []; }
            if (mine !== mgrSeq) return;                  // a newer search has started
            // Not their own manager, and not people who have left.
            mgrRows = rows.filter(u => String(u.id) !== String(mgrSelf ?? '') && Number(u.is_active ?? 1)).slice(0, 12);
            mgrActive = mgrRows.length ? 0 : -1;
            mgrPaint();
        }

        function mgrPick(i) {
            const u = mgrRows[i];
            if (u) mgrSet(u.id, u.display_name || u.email || u.username || T.unknown_name);
        }

        $('peMgrInput').addEventListener('input', function () {
            clearTimeout(mgrTimer);
            const q = this.value.trim();
            if (!q) { mgrRows = []; mgrClose(); return; }
            mgrTimer = setTimeout(() => mgrSearch(q), 200);
        });
        $('peMgrInput').addEventListener('keydown', function (e) {
            const open = !$('peMgrList').hidden;
            if (!open) return;
            if (e.key === 'ArrowDown')    { e.preventDefault(); mgrActive = Math.min(mgrRows.length - 1, mgrActive + 1); mgrPaint(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); mgrActive = Math.max(0, mgrActive - 1); mgrPaint(); }
            else if (e.key === 'Enter' && mgrActive >= 0) { e.preventDefault(); mgrPick(mgrActive); }
            // Escape closes the list only - not the whole editor behind it.
            else if (e.key === 'Escape')  { e.preventDefault(); e.stopPropagation(); mgrClose(); }
        });
        // mousedown, not click: it lands before the text box's blur.
        $('peMgrList').addEventListener('mousedown', function (e) {
            const li = e.target.closest('li[data-i]');
            if (!li) return;
            e.preventDefault();
            mgrPick(Number(li.dataset.i));
        });
        // Leaving the box puts back whoever is actually chosen, so typed-but-not-
        // picked text never looks like a manager that is about to be saved.
        $('peMgrInput').addEventListener('blur', function () {
            setTimeout(() => {
                mgrClose();
                const hid = $('pe_manager_id');
                this.value = hid.dataset.unresolved ? '' : (hid.dataset.name || '');
            }, 0);
        });
        $('peMgrClear').addEventListener('click', function () { mgrSet(null, ''); $('peMgrInput').focus(); });

        function paintManager(selfId, managerId, managerName) {
            mgrSelf = selfId;
            mgrRows = [];
            clearTimeout(mgrTimer);
            mgrSeq++;                                     // ignore any search still in flight
            if (!$('pe_manager_id')) return;
            const wanted = (managerId === null || managerId === undefined) ? '' : String(managerId);
            if (wanted !== '' && !managerName) {
                // A manager this analyst cannot see: get_person.php gives no name.
                // Keep their id aside and leave the key out on save - never post it
                // as "no manager". Choosing somebody else is the way to change it.
                mgrSet(null, '');
                $('pe_manager_id').dataset.unresolved = wanted;
                $('peMgrInput').placeholder = T.manager_hidden;
                $('peMgrClear').hidden = true;
            } else {
                mgrSet(wanted, managerName || '');
            }
        }

        function paintPerson(u) {
            $('peEmail').value = u.email || '';
            $('peDisplayName').value = u.display_name || '';
            $('pePreferredName').value = u.preferred_name || '';
            owned = Array.isArray(u.managed_fields) ? u.managed_fields : [];
            PERSON_FIELDS.forEach(f => {
                const el = $('pe_' + f);
                if (!el) return;
                if (f !== 'manager_id') el.value = u[f] || '';
                // disabled, not readonly: it is somebody else's to change, and a
                // readonly field still looks typeable and still submits.
                el.disabled = owned.includes(f);
            });
            // Explains the greyed fields, so it follows them rather than is_managed:
            // an address-book contact is managed and still has editable fields.
            $('peManagedNote').hidden = owned.length === 0;
        }

        async function open(id, opts) {
            onSaved = (opts && opts.onSaved) || null;
            isNew = !id;
            $('peForm').reset();
            $('peErr').textContent = '';
            $('peId').value = id || '';
            $('peTitle').textContent = isNew ? T.add_title : T.edit_title;

            let person = { managed_fields: [] };
            await loadCompanies();
            if (!isNew) {
                try {
                    const d = await getJson(API + 'tickets/get_person.php?id=' + encodeURIComponent(id));
                    if (!d.success) { toast(d.error || T.load_failed, 'error'); return; }
                    person = d.user;
                } catch (e) { toast(T.load_failed, 'error'); return; }
            }
            paintPerson(person);
            paintManager(id, person.manager_id, person.manager_name);
            // Directory-owned: shown, not changeable (and never sent - see the save).
            if ($('peMgrInput')) {
                $('peMgrInput').disabled = owned.includes('manager_id');
                $('peMgrClear').disabled = owned.includes('manager_id');
            }
            paintCompanies(person.tenant_id ?? null);
            ccCurrent = person.cost_centre_id || null;
            await paintCostCentres(false);
            locCurrent = person.location_id || null;
            locCurrentPath = person.location_path || null;
            await paintLocations(false);
            if (isNew) await refetchCustom(); else paintCustom(person.custom_fields || []);
            $('pePassword').value = '';
            $('personEditor').classList.add('active');
            $('peEmail').focus();
        }

        function close() { $('personEditor').classList.remove('active'); }

        $('peForm').addEventListener('submit', async function (e) {
            e.preventDefault();
            const err = $('peErr');
            err.textContent = '';
            const id = $('peId').value;
            const payload = {
                id: id || null,
                email: $('peEmail').value.trim(),
                display_name: $('peDisplayName').value.trim(),
                preferred_name: $('pePreferredName').value.trim(),
                password: $('pePassword').value
            };
            if (!payload.display_name && !payload.email) { err.textContent = T.need_name_or_email; return; }

            // Person fields: owned ones left out entirely (see the header).
            PERSON_FIELDS.forEach(f => {
                if (f === 'manager_id' || owned.includes(f)) return;
                const el = $('pe_' + f);
                if (el) payload[f] = el.value.trim();
            });
            const mgr = $('pe_manager_id');
            if (mgr && !owned.includes('manager_id') && !mgr.dataset.unresolved) {
                payload.manager_id = mgr.value || null;
            }
            // Company only when the picker is in play; a blank on a NEW person is
            // omitted so the server works it out from the email domain.
            if (companies && companies.length >= 2) {
                const c = $('peCompany').value;
                if (c || id) payload.tenant_id = c || null;
            }

            // The cost centre, whenever the box is in play (GH #160).
            if (!$('peCostCentreGroup').hidden) payload.cost_centre_id = $('peCostCentre').value || null;
            // Custom fields, whenever there are any (GH #279). An empty box is sent as
            // '' - clear it - never left out.
            if (window.TicketFields && $('peCustomFields') && $('peCustomFields').querySelector('[data-tf-key]')) {
                payload.custom_fields = TicketFields.collect($('peCustomFields'));
            }
            // The location, whenever the box is in play (GH #311).
            if (!$('peLocationGroup').hidden) payload.location_id = $('peLocation').value || null;

            const btn = $('peSave');
            btn.disabled = true;
            try {
                const r = await fetch(API + 'tickets/save_user.php', {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const d = await r.json();
                if (!d.success) { err.textContent = d.error || 'Save failed'; return; }

                // The address book is its own outcome, always said out loud.
                const ab = d.address_book;
                if (ab && ab.conflict)      toast(ab.error || T.ab_conflict, 'warning', 12000);
                else if (ab && !ab.ok)      toast(fmt(T.ab_failed, { error: ab.error || '' }), 'error', 12000);
                else if (ab && ab.ok && ab.changed && ab.changed.length) toast(T.ab_written, 'success');
                else                        toast(T.saved, 'success');

                close();
                if (onSaved) await onSaved(d.id || (id ? Number(id) : null));
            } catch (ex) {
                err.textContent = String(ex.message || ex);
            } finally {
                btn.disabled = false;
            }
        });

        // Escape closes, and so does a click on the backdrop (not inside the
        // dialog) - Assets -> Users' editor did both, so the shared one keeps them.
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && $('personEditor').classList.contains('active')) close();
        });
        $('personEditor').addEventListener('click', e => {
            if (e.target.id === 'personEditor') close();
        });

        return { open, close };
    })();
    </script>
    <?php
}
