<?php
/**
 * The ONE editor for a company (a `tenants` row) - GH #419.
 *
 * WHY THERE IS ONE
 * ----------------
 * The full form lived inside System -> Companies. For #398 the person editor
 * grew a second, name-only "+ New company" box beside it, so a company made
 * while filing a person still needed a trip to System -> Companies for its
 * tax ID, outside reference and custom fields (@pappas81, #419: those are what
 * his sync with other programs matches on). Two forms for one record drift -
 * the person editor's own history says so (includes/person_editor.php). So the
 * form lives here and every screen opens it:
 *
 *     CompanyEditor.open(company | null, {
 *         onSaved:       function (saved)   { ... },   // { id, name } - after Save
 *         onChanged:     function ()        { ... },   // a domain, sender, logo or support tick changed
 *         onUseExisting: function (company) { ... },   // the duplicate warning's "Use" (person editor)
 *         prefill:       { email: 'jane@acme.example' } // a NEW company for this person
 *     })
 *     CompanyEditor.openById(id, opts)
 *
 * Hosts: System -> Companies (add, edit), the person editor's "+ New company..."
 * (People, Tickets -> Users, Assets -> Users) and People -> a company's Edit.
 *
 * WHAT IT ADDS FOR A NEW COMPANY (#419)
 * -------------------------------------
 *  - From the person's email: a suggested name (acme-engineering.co.uk ->
 *    "Acme Engineering") and a tick to route that domain to the new company,
 *    as Tickets -> Triage does when it files mail to a new company. A public
 *    provider's domain (gmail.com) is never routed - the tick offers the exact
 *    address instead, again as Triage does.
 *  - A duplicate warning as you type: the same tax ID or outside reference
 *    (both unique - the save would refuse them anyway, this says so earlier
 *    and names the company), a very similar name, or a company that already
 *    receives that domain's mail. "Use" picks it instead; on System -> Companies
 *    "Open" edits it.
 *
 * Administrators only, like everything it calls (admin_api_guard.php); for
 * anybody else this prints nothing and window.CompanyEditor stays undefined.
 *
 * @wiki Companies
 */

require_once __DIR__ . '/i18n.php';

/** Print the editor's markup, styles and script once per page, for administrators. */
function companyEditorRender(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (!function_exists('sessionIsAdmin') || !sessionIsAdmin()) return;

    $base = defined('BASE_URL') ? BASE_URL : '/';
    $tc = fn(string $k, array $p = []) => htmlspecialchars(t('system.companies.' . $k, $p));
    // The strings the script needs, resolved here: the host page may not export
    // the system namespace to JavaScript (People, Tickets and Assets do not).
    $strings = I18n::exportForJs(['system'])['system']['companies'] ?? [];
    $cssVer = (int)@filemtime(__DIR__ . '/../assets/css/system-page.css');
    ?>
    <?php /* The System dialog look (.sys-modal), on whichever page this lands. Linked
             again on a System page, which is harmless: the same rules, once cached. */ ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($base); ?>assets/css/system-page.css?v=<?php echo $cssVer; ?>">
    <style>
        /* Scoped to the overlay: this lands on pages with their own .form-field, .hint ... */
        #coeOverlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 2100; align-items: center; justify-content: center; }
        #coeOverlay.open { display: flex; }
        /* TRAP: the scoped display rules below (.checkbox-field: flex ...) beat the [hidden]
           attribute, so a hidden routing tick still showed as an empty box. [hidden] wins here. */
        #coeOverlay [hidden] { display: none !important; }
        #coeOverlay .form-field { margin-bottom: 16px; }
        #coeOverlay .form-field > label { display: block; font-size: var(--font-size-md, 13px); font-weight: 600; color: var(--text, #444); margin-bottom: 4px; }
        #coeOverlay .hint { font-size: var(--font-size-sm, 12px); color: var(--text-faint, #999); font-weight: 400; margin-bottom: 6px; }
        #coeOverlay input[type=text] { width: 100%; padding: 9px 11px; border: 1px solid var(--border, #ddd); border-radius: 5px; font-size: var(--font-size-md, 13px); font-family: inherit; box-sizing: border-box; background: var(--surface, #fff); color: var(--text, #333); }
        #coeOverlay input[type=text]:focus { outline: none; border-color: var(--sys-accent, var(--accent, #546e7a)); }
        /* GH #279: custom fields - the same look for every kind of control. */
        #coeCustomFields input, #coeCustomFields select, #coeCustomFields textarea { width: 100%; max-width: 420px; padding: 9px 11px; border: 1px solid var(--border, #ddd); border-radius: 5px; font-size: var(--font-size-md, 13px); font-family: inherit; box-sizing: border-box; background: var(--surface, #fff); color: var(--text, #333); }
        #coeCustomFields .tf-hint { display: block; font-size: var(--font-size-sm, 12px); color: var(--text-faint, #999); margin-top: 4px; }
        #coeOverlay .checkbox-field { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 14px; }
        #coeOverlay .checkbox-field input { margin-top: 3px; }
        #coeOverlay .cb-label { font-size: var(--font-size-md, 13px); color: var(--text, #444); }
        #coeOverlay .cb-label strong { display: block; }
        #coeOverlay .cb-label span { color: var(--text-faint, #999); font-size: var(--font-size-sm, 12px); }
        /* #419: "Already in FreeITSM?" - amber, like the routing warning: look before you add. */
        #coeOverlay .coe-dup { border: 1px solid #ffe0a3; background: #fff8e1; border-radius: 6px; padding: 10px 12px; margin-bottom: 16px; }
        #coeOverlay .coe-dup[hidden] { display: none; }
        #coeOverlay .coe-dup-h { font-size: var(--font-size-sm, 12px); font-weight: 600; color: #8a5a00; margin-bottom: 6px; }
        #coeOverlay .coe-dup-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 5px 0; border-top: 1px solid #ffe8b8; font-size: var(--font-size-md, 13px); color: var(--text, #333); }
        #coeOverlay .coe-dup-row:first-of-type { border-top: none; }
        #coeOverlay .coe-dup-row small { display: block; color: var(--text-muted, #666); font-size: var(--font-size-sm, 12px); }
        #coeOverlay .coe-dup-row .btn { padding: 5px 12px; flex: 0 0 auto; }
        /* "How email reaches this company" - derived routing summary panel */
        #coeOverlay .routing-panel { background: #f7f9fa; border: 1px solid var(--border, #e3e8ea); border-radius: 6px; padding: 12px 14px; }
        #coeOverlay .routing-path { display: flex; align-items: flex-start; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--border-soft, #eceff1); }
        #coeOverlay .routing-path:last-child { border-bottom: none; }
        #coeOverlay .rp-icon { flex: 0 0 auto; font-size: var(--font-size-xl, 16px); line-height: 1.3; }
        #coeOverlay .rp-body { flex: 1; min-width: 0; }
        #coeOverlay .rp-kind { font-size: var(--font-size-sm, 12px); font-weight: 600; color: var(--text, #455a64); }
        #coeOverlay .rp-flag { font-weight: 600; color: #c62828; font-size: var(--font-size-xs, 11px); margin-left: 6px; }
        #coeOverlay .rp-desc { font-size: var(--font-size-sm, 12px); color: var(--text-muted, #666); margin-top: 2px; }
        #coeOverlay .rp-desc strong { color: var(--text, #444); font-weight: 600; }
        #coeOverlay .routing-note { font-size: var(--font-size-sm, 12px); color: var(--text-dim, #777); margin-top: 10px; font-style: italic; }
        #coeOverlay .routing-warn { display: flex; gap: 8px; font-size: var(--font-size-sm, 12px); color: #8a5a00; background: #fff8e1; border: 1px solid #ffe0a3; border-radius: 5px; padding: 8px 10px; margin-top: 8px; }
        #coeOverlay .routing-empty { font-size: var(--font-size-sm, 12px); color: var(--text-faint, #aaa); font-style: italic; }
        #coeOverlay .domain-row { display: flex; align-items: center; justify-content: space-between; padding: 6px 10px; border: 1px solid var(--border, #eee); border-radius: 5px; margin-bottom: 6px; font-size: var(--font-size-md, 13px); color: var(--text, #333); }
        #coeOverlay .list-none { color: var(--text-faint, #aaa); font-size: var(--font-size-sm, 12px); font-style: italic; padding: 6px 0; }
        #coeOverlay .table-action-btn { background: none; border: none; cursor: pointer; color: #607d8b; padding: 4px 8px; font-size: var(--font-size-md, 13px); border-radius: 4px; }
        #coeOverlay .table-action-btn:hover { background: var(--sys-accent-soft, #eceff1); }
        /* Supports other companies (GH #228) */
        #coeOverlay table.support-table { width: 100%; border-collapse: collapse; font-size: var(--font-size-md, 13px); }
        #coeOverlay table.support-table th { text-align: left; font-size: var(--font-size-sm, 12px); font-weight: 600; color: var(--text-dim, #888); padding: 6px 8px; border-bottom: 1px solid var(--border-soft, #eee); }
        #coeOverlay table.support-table th.tick, #coeOverlay table.support-table td.tick { text-align: center; width: 90px; }
        #coeOverlay table.support-table td { padding: 6px 8px; border-bottom: 1px solid var(--border-soft, #f2f2f2); color: var(--text, #444); }
        #coeOverlay table.support-table tr:last-child td { border-bottom: none; }
        #coeOverlay .support-inactive { color: var(--text-faint, #999); font-size: var(--font-size-xs, 11px); margin-left: 6px; }
        #coeOverlay table.support-table th abbr { text-decoration: underline dotted; cursor: help; }
        [data-theme-mode="dark"] #coeOverlay .routing-panel { background: #20242b; }
        [data-theme-mode="dark"] #coeOverlay .routing-warn,
        [data-theme-mode="dark"] #coeOverlay .coe-dup { color: #fcd34d; background: #3a2e12; border-color: #5a4a1e; }
        [data-theme-mode="dark"] #coeOverlay .coe-dup-h { color: #fcd34d; }
        [data-theme-mode="dark"] #coeOverlay .coe-dup-row { border-color: #5a4a1e; color: var(--text, #eceff1); }
        [data-theme-mode="dark"] #coeOverlay .rp-flag { color: #fca5a5; }
        [data-theme-mode="dark"] #coeOverlay .table-action-btn { color: var(--sys-accent, #90a4ae); }
    </style>

    <div id="coeOverlay">
        <div class="sys-modal" role="dialog" aria-modal="true" aria-labelledby="coeTitle">
            <div class="sys-modal-head" id="coeTitle"><?php echo $tc('modal_add_title'); ?></div>
            <div class="sys-modal-body">
                <input type="hidden" id="coeId">
                <div class="form-field">
                    <label for="coeName"><?php echo $tc('field_name'); ?></label>
                    <div class="hint"><?php echo $tc('field_name_hint'); ?></div>
                    <input type="text" id="coeName" maxlength="150" autocomplete="off" placeholder="<?php echo $tc('field_name_placeholder'); ?>">
                </div>
                <?php /* The short code that stands in for this company in a ticket number. Shown
                         only when it can matter - one company means {COMPANY} distinguishes nothing. */ ?>
                <div class="form-field" id="coeCodeSection" hidden>
                    <label for="coeCode"><?php echo $tc('field_code'); ?></label>
                    <div class="hint"><?php echo $tc('field_code_hint'); ?></div>
                    <input type="text" id="coeCode" maxlength="12" autocomplete="off" spellcheck="false" style="text-transform: uppercase; max-width: 200px;"
                           placeholder="<?php echo $tc('field_code_placeholder'); ?>">
                    <div class="hint" id="coeCodeHint" style="margin-top:6px;"></div>
                </div>
                <?php /* GH #279: the company's key in your ERP / CRM (what the REST API's upsert
                         matches on) and its tax ID. Optional, unique when set. */ ?>
                <div class="form-field">
                    <label for="coeExternalRef"><?php echo $tc('field_external_ref'); ?></label>
                    <div class="hint"><?php echo $tc('field_external_ref_hint'); ?></div>
                    <input type="text" id="coeExternalRef" maxlength="100" autocomplete="off" spellcheck="false" style="max-width: 320px;">
                </div>
                <div class="form-field">
                    <label for="coeTaxId"><?php echo $tc('field_tax_id'); ?></label>
                    <div class="hint"><?php echo $tc('field_tax_id_hint'); ?></div>
                    <input type="text" id="coeTaxId" maxlength="50" autocomplete="off" spellcheck="false" style="max-width: 320px;">
                </div>
                <?php /* #419: a company that may already be here - filled in as you type. */ ?>
                <div class="coe-dup" id="coeDup" hidden role="status" aria-live="polite"></div>
                <?php /* GH #279: the company's custom fields, drawn by assets/js/ticket-fields.js. */ ?>
                <div id="coeCustomFields"></div>
                <?php /* #419: route the person's email domain (or, for a public provider, their
                         exact address) to the NEW company - Triage's tick, here. */ ?>
                <div class="checkbox-field" id="coeRouteField" hidden>
                    <input type="checkbox" id="coeRoute" checked>
                    <label class="cb-label" for="coeRoute"><strong id="coeRouteLabel"></strong><span id="coeRouteHelp"></span></label>
                </div>
                <?php /* The company's own logo (Files desktop). Existing companies only: it saves
                         straight away through api/system/tenant_logo.php. */ ?>
                <div class="form-field" id="coeLogoSection" hidden>
                    <label><?php echo $tc('logo_label'); ?></label>
                    <div class="hint"><?php echo $tc('logo_hint'); ?></div>
                    <div style="display:flex;align-items:center;gap:12px;margin-top:6px;">
                        <div id="coeLogoPreview" style="width:120px;height:48px;border:1px dashed var(--border);border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:var(--font-size-sm, 12px);color:var(--text-dim);overflow:hidden;"></div>
                        <input type="file" id="coeLogoFile" accept=".png,.jpg,.jpeg,.gif,.webp,image/png,image/jpeg,image/gif,image/webp" hidden>
                        <button type="button" class="btn btn-secondary" id="coeLogoPick"><?php echo $tc('logo_choose'); ?></button>
                        <button type="button" class="btn btn-secondary" id="coeLogoRemove" hidden><?php echo $tc('logo_remove'); ?></button>
                    </div>
                </div>
                <div class="checkbox-field">
                    <input type="checkbox" id="coeActive" checked>
                    <label class="cb-label" for="coeActive"><strong><?php echo $tc('cb_active'); ?></strong><span><?php echo $tc('cb_active_desc'); ?></span></label>
                </div>

                <?php /* Email domains, specific senders, how mail reaches the company and which
                         companies it supports: editing an existing company on a multi-company
                         install only - shared-intake routing means nothing with one company. */ ?>
                <div class="form-field" id="coeDomainsSection" hidden>
                    <label><?php echo $tc('domains_label'); ?></label>
                    <div class="hint"><?php echo $tc('domains_hint'); ?></div>
                    <div id="coeDomainsList"></div>
                    <div style="display: flex; gap: 8px; margin-top: 8px;">
                        <input type="text" id="coeDomainInput" placeholder="<?php echo $tc('domain_placeholder'); ?>" style="flex: 1;">
                        <button type="button" class="btn btn-secondary" id="coeAddDomain"><?php echo $tc('domain_add'); ?></button>
                    </div>
                </div>
                <div class="form-field" id="coeSendersSection" hidden>
                    <label><?php echo $tc('senders_label'); ?></label>
                    <div class="hint"><?php echo $tc('senders_hint'); ?></div>
                    <div id="coeSendersList"></div>
                    <div style="display: flex; gap: 8px; margin-top: 8px;">
                        <input type="text" id="coeSenderInput" placeholder="<?php echo $tc('sender_placeholder'); ?>" style="flex: 1;">
                        <button type="button" class="btn btn-secondary" id="coeAddSender"><?php echo $tc('sender_add'); ?></button>
                    </div>
                </div>
                <div class="form-field" id="coeRoutingSection" hidden>
                    <label><?php echo $tc('routing_label'); ?></label>
                    <div class="hint"><?php echo $tc('routing_hint'); ?></div>
                    <div class="routing-panel" id="coeRoutingPanel"></div>
                </div>
                <div class="form-field" id="coeSupportSection" hidden>
                    <label><?php echo $tc('support_label'); ?></label>
                    <div class="hint"><?php echo $tc('support_hint'); ?></div>
                    <div id="coeSupportList"></div>
                </div>
            </div>
            <div class="sys-modal-foot">
                <button class="btn btn-secondary" id="coeCancel" type="button"><?php echo $tc('cancel'); ?></button>
                <button class="btn btn-primary" id="coeSave" type="button"><?php echo $tc('save'); ?></button>
            </div>
        </div>
    </div>

    <?php ticketFieldsScriptOnce(); ?>
    <script>
    window.CompanyEditor = (function () {
        const API = <?php echo json_encode($base . 'api/'); ?>;
        const T = <?php echo json_encode($strings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
        const $ = id => document.getElementById(id);
        const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };
        /** A system.companies string with {placeholders} filled - the same as window.t, which a host may not have. */
        const tc = (k, p) => String(T[k] == null ? k : T[k]).replace(/\{(\w+)\}/g, (_, n) => (p && n in p) ? p[n] : '{' + n + '}');
        const toast = (msg, type) => { if (typeof window.showToast === 'function') window.showToast(msg, type); };
        async function getJson(url) { const r = await fetch(url, { credentials: 'same-origin' }); return r.json(); }
        async function postJson(url, body) {
            const r = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
            return r.json();
        }

        let companies = [];        // every company (admin list), reloaded each time the editor opens
        let freemail = null;       // public email domains, loaded once
        let opts = {};
        let editing = null;        // the company being edited; null when adding
        let route = null;          // #419: { kind: 'domain'|'sender', domain, address } offered for a new company

        // ── loading ──────────────────────────────────────────────────────────
        async function loadCompanies() {
            try { const d = await getJson(API + 'system/get_tenants.php'); companies = d.success ? d.companies : []; }
            catch (e) { companies = []; }
        }
        async function loadFreemail() {
            if (freemail) return;
            try {
                const d = await getJson(API + 'system/get_freemail_domains.php');
                freemail = new Set(d.success ? [].concat(d.builtin || [], (d.custom || []).map(x => x.domain)).map(x => String(x).toLowerCase()) : []);
            } catch (e) { freemail = new Set(); }
        }

        // ── #419: a name from an email domain ────────────────────────────────
        // acme-engineering.co.uk -> "Acme Engineering". Only a suggestion, put in an
        // empty box: the registered name is often different, and it is easy to change.
        const SECOND_LEVEL = new Set(['co', 'com', 'org', 'net', 'ac', 'gov', 'edu', 'ltd', 'plc', 'me', 'nhs', 'sch', 'gob', 'or', 'ne']);
        function nameFromDomain(domain) {
            const parts = domain.split('.').filter(Boolean);
            if (parts.length < 2) return '';
            parts.pop();                                            // the top-level domain
            if (parts.length > 1 && SECOND_LEVEL.has(parts[parts.length - 1])) parts.pop();   // co.uk, com.gr ...
            const label = parts[parts.length - 1] || '';
            return label.split(/[-_]+/).filter(Boolean).map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');
        }

        // ── #419: is it already here? ────────────────────────────────────────
        // Legal-form words are ignored, so "Acme Engineering Ltd" matches "ACME Engineering".
        const LEGAL = new Set(['ltd', 'limited', 'inc', 'incorporated', 'llc', 'llp', 'plc', 'gmbh', 'ag', 'sa', 'sas', 'sarl', 'srl', 'spa', 'bv', 'nv',
            'co', 'company', 'corp', 'corporation', 'group', 'holdings', 'ae', 'ike', 'epe', 'oe', 'ee', 'pty', 'ab', 'as', 'oy', 'kg', 'the']);
        function normName(s) {
            return String(s || '').toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '')
                .replace(/[^\p{L}\p{N}]+/gu, ' ').trim().split(/\s+/).filter(w => w && !LEGAL.has(w)).join(' ');
        }
        function similarName(a, b) {
            const x = normName(a), y = normName(b);
            if (!x || !y) return false;
            if (x === y) return true;
            // One is the other plus more words ("Acme" / "Acme Engineering") - only when the
            // shorter is a real name, not a two-letter scrap.
            const [s, l] = x.length <= y.length ? [x, y] : [y, x];
            return s.length >= 4 && l.startsWith(s + ' ');
        }
        // Tax IDs are written with spaces, dots and sometimes a country prefix (EL123456789).
        const normTax = s => String(s || '').toUpperCase().replace(/[^A-Z0-9]/g, '').replace(/^[A-Z]{2}(?=\d)/, '');

        function findDuplicates() {
            const name = $('coeName').value.trim(), tax = normTax($('coeTaxId').value), ref = $('coeExternalRef').value.trim().toLowerCase();
            const out = [];
            companies.forEach(c => {
                if (editing && String(c.id) === String(editing.id)) return;
                const why = [];
                if (tax && normTax(c.tax_id) === tax) why.push(tc('dup_tax'));
                if (ref && String(c.external_ref || '').toLowerCase() === ref) why.push(tc('dup_ref'));
                if (route && route.kind === 'domain' && (c.domains || []).map(d => String(d).toLowerCase()).includes(route.domain)) why.push(tc('dup_domain', { domain: route.domain }));
                if (name && similarName(name, c.name)) why.push(tc('dup_name'));
                if (why.length) out.push({ c, why });
            });
            // The strongest reasons first: an identifier beats a look-alike name.
            return out.sort((a, b) => (b.why.length - a.why.length)).slice(0, 4);
        }
        function paintDuplicates() {
            const box = $('coeDup'), list = findDuplicates();
            if (!list.length) { box.hidden = true; box.innerHTML = ''; return; }
            const verb = opts.onUseExisting ? tc('dup_use') : tc('dup_open');
            box.innerHTML = '<div class="coe-dup-h">' + esc(tc('dup_heading')) + '</div>' + list.map(m =>
                '<div class="coe-dup-row"><span><strong>' + esc(m.c.name) + '</strong>'
                + (m.c.is_active ? '' : ' (' + esc(tc('inactive')) + ')')
                + '<small>' + esc(m.why.join(' · ')) + '</small></span>'
                + '<button type="button" class="btn btn-secondary" data-coe-pick="' + m.c.id + '">' + esc(verb) + '</button></div>').join('');
            box.hidden = false;
        }
        $('coeDup').addEventListener('click', e => {
            const id = e.target.getAttribute('data-coe-pick');
            if (!id) return;
            const c = companies.find(x => String(x.id) === id);
            if (!c) return;
            if (opts.onUseExisting) { const cb = opts.onUseExisting; close(); cb(c); }
            else open(c, Object.assign({}, opts, { prefill: null }));
        });
        let dupTimer = null;
        ['coeName', 'coeTaxId', 'coeExternalRef'].forEach(id => $(id).addEventListener('input', () => {
            clearTimeout(dupTimer); dupTimer = setTimeout(paintDuplicates, 200);
            if (id === 'coeName' || id === 'coeCode') codeHint();
        }));

        // ── ticket code ──────────────────────────────────────────────────────
        const codeRelevant = () => companies.length > 1 || !editing;
        /** The code used if the box is left empty: the server's for an existing company
            (its rule also weighs the slug), derived from the name for a new one. */
        function codeHint() {
            const hint = $('coeCodeHint');
            if ($('coeCode').value.trim() !== '') { hint.textContent = ''; return; }
            if (editing) {
                hint.textContent = editing.effective_ticket_code ? tc('field_code_derived', { code: editing.effective_ticket_code }) : tc('field_code_underivable');
                return;
            }
            const derived = $('coeName').value.trim().replace(/[^A-Za-z]/g, '').toUpperCase().slice(0, 3);
            hint.textContent = derived ? tc('field_code_derived', { code: derived }) : tc('field_code_underivable');
        }
        $('coeCode').addEventListener('input', codeHint);

        // ── logo (existing companies) ────────────────────────────────────────
        function renderLogo(url) {
            const box = $('coeLogoPreview');
            box.innerHTML = '';
            if (url) { const img = document.createElement('img'); img.src = url; img.alt = ''; img.style.cssText = 'max-width:100%;max-height:100%;'; box.appendChild(img); }
            else box.textContent = tc('logo_none');
            $('coeLogoRemove').hidden = !url;
        }
        async function sendLogo(fd) {
            fd.append('tenant_id', $('coeId').value);
            const r = await fetch(API + 'system/tenant_logo.php', { method: 'POST', credentials: 'same-origin', body: fd });
            const j = await r.json();
            if (!j.success) { toast(j.error, 'error'); return; }
            renderLogo(j.logo_url);
            if (editing) editing.logo_url = j.logo_url;
            changed();
        }
        $('coeLogoPick').addEventListener('click', () => $('coeLogoFile').click());
        $('coeLogoFile').addEventListener('change', function () {
            if (!this.files[0]) return;
            const fd = new FormData(); fd.append('logo', this.files[0]); this.value = '';
            sendLogo(fd);
        });
        $('coeLogoRemove').addEventListener('click', () => { const fd = new FormData(); fd.append('remove', '1'); sendLogo(fd); });

        function changed() { if (typeof opts.onChanged === 'function') opts.onChanged(); }

        // ── custom fields (GH #279) ──────────────────────────────────────────
        async function loadCustomFields(id) {
            const box = $('coeCustomFields');
            box.innerHTML = '';
            if (!window.TicketFields) return;
            try {
                const d = await getJson(API + 'system/custom_fields.php?entity=company&record=' + encodeURIComponent(id || 0));
                if (d.success && d.fields && d.fields.length) box.innerHTML = TicketFields.render(d.fields, { groupClass: 'form-field' });
            } catch (e) { /* the dialog still saves everything else */ }
        }

        // ── email domains ────────────────────────────────────────────────────
        async function loadDomains(tenantId) {
            let list = [];
            try { const d = await getJson(API + 'system/get_tenant_domains.php?tenant_id=' + tenantId); list = d.success ? d.domains : []; } catch (e) { list = []; }
            $('coeDomainsList').innerHTML = !list.length ? '<div class="list-none">' + esc(tc('domains_none')) + '</div>'
                : list.map(d => '<div class="domain-row"><span>' + esc(d.domain) + '</span><button type="button" class="table-action-btn" data-coe-rm-domain="' + d.id + '">' + esc(tc('domain_remove')) + '</button></div>').join('');
        }
        async function addDomain() {
            const tenantId = $('coeId').value, domain = $('coeDomainInput').value.trim();
            if (!tenantId || !domain) return;
            $('coeAddDomain').disabled = true;
            try {
                const d = await postJson(API + 'system/add_tenant_domain.php', { tenant_id: tenantId, domain });
                if (d.success) { $('coeDomainInput').value = ''; toast(tc('domain_added'), 'success'); loadDomains(tenantId); loadRouting(tenantId); changed(); }
                else toast(d.error || tc('domain_add_failed'), 'error');
            } catch (e) { toast(tc('domain_add_failed'), 'error'); }
            $('coeAddDomain').disabled = false;
        }
        async function removeDomain(id) {
            try {
                const d = await postJson(API + 'system/delete_tenant_domain.php', { id });
                if (d.success) { toast(tc('domain_removed'), 'success'); loadDomains($('coeId').value); loadRouting($('coeId').value); changed(); }
                else toast(d.error || tc('domain_remove_failed'), 'error');
            } catch (e) { toast(tc('domain_remove_failed'), 'error'); }
        }
        $('coeAddDomain').addEventListener('click', addDomain);
        $('coeDomainInput').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addDomain(); } });
        $('coeDomainsList').addEventListener('click', e => { const id = e.target.getAttribute('data-coe-rm-domain'); if (id) removeDomain(parseInt(id, 10)); });

        // ── specific senders (address-level routing) ─────────────────────────
        async function loadSenders(tenantId) {
            let list = [];
            try { const d = await getJson(API + 'system/get_tenant_senders.php?tenant_id=' + tenantId); list = d.success ? d.senders : []; } catch (e) { list = []; }
            $('coeSendersList').innerHTML = !list.length ? '<div class="list-none">' + esc(tc('senders_none')) + '</div>'
                : list.map(s => '<div class="domain-row"><span>' + esc(s.email) + '</span><button type="button" class="table-action-btn" data-coe-rm-sender="' + s.id + '">' + esc(tc('sender_remove')) + '</button></div>').join('');
        }
        async function addSender() {
            const tenantId = $('coeId').value, email = $('coeSenderInput').value.trim();
            if (!tenantId || !email) return;
            $('coeAddSender').disabled = true;
            try {
                const d = await postJson(API + 'system/add_tenant_sender.php', { tenant_id: tenantId, email });
                if (d.success) { $('coeSenderInput').value = ''; toast(tc('sender_added'), 'success'); loadSenders(tenantId); loadRouting(tenantId); }
                else toast(d.error || tc('sender_add_failed'), 'error');
            } catch (e) { toast(tc('sender_add_failed'), 'error'); }
            $('coeAddSender').disabled = false;
        }
        async function removeSender(id) {
            try {
                const d = await postJson(API + 'system/delete_tenant_sender.php', { id });
                if (d.success) { toast(tc('sender_removed'), 'success'); loadSenders($('coeId').value); loadRouting($('coeId').value); }
                else toast(d.error || tc('sender_remove_failed'), 'error');
            } catch (e) { toast(tc('sender_remove_failed'), 'error'); }
        }
        $('coeAddSender').addEventListener('click', addSender);
        $('coeSenderInput').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addSender(); } });
        $('coeSendersList').addEventListener('click', e => { const id = e.target.getAttribute('data-coe-rm-sender'); if (id) removeSender(parseInt(id, 10)); });

        // ── how email reaches this company (derived, read-only) ──────────────
        function routingFlags(p) {
            const flags = [];
            if (!p.is_active) flags.push(tc('routing_inactive'));
            if (!p.authenticated) flags.push(tc('routing_unauth'));
            return flags.length ? ' <span class="rp-flag">(' + flags.map(esc).join(', ') + ')</span>' : '';
        }
        async function loadRouting(tenantId) {
            const panel = $('coeRoutingPanel');
            panel.innerHTML = '<div class="routing-empty">' + esc(tc('routing_loading')) + '</div>';
            let data = null;
            try { data = await getJson(API + 'system/get_tenant_email_routing.php?tenant_id=' + tenantId); } catch (e) { data = null; }
            if (!data || !data.success) { panel.innerHTML = '<div class="routing-empty">' + esc(tc('routing_failed')) + '</div>'; return; }
            let html = '';
            (data.paths || []).forEach(p => {
                const addr = '<strong>' + esc(p.address || p.name) + '</strong>';
                if (p.type === 'pinned') {
                    html += '<div class="routing-path"><span class="rp-icon">📌</span><div class="rp-body"><div class="rp-kind">' + esc(tc('routing_pinned')) + routingFlags(p)
                        + '</div><div class="rp-desc">' + tc('routing_pinned_desc', { address: addr }) + '</div></div></div>';
                } else {
                    const dl = p.matched_domains || [], sl = p.matched_senders || [];
                    const domains = dl.map(d => '<strong>' + esc(d) + '</strong>').join(', ');
                    const senders = sl.map(s => '<strong>' + esc(s) + '</strong>').join(', ');
                    const desc = dl.length && sl.length ? tc('routing_shared_desc_both', { address: addr, domains, senders })
                        : sl.length ? tc('routing_shared_desc_senders', { address: addr, senders }) : tc('routing_shared_desc', { address: addr, domains });
                    html += '<div class="routing-path"><span class="rp-icon">📥</span><div class="rp-body"><div class="rp-kind">' + esc(tc('routing_shared')) + routingFlags(p)
                        + '</div><div class="rp-desc">' + desc + '</div></div></div>';
                }
            });
            if (!html) html = '<div class="routing-empty">' + esc(tc('routing_warn_no_route')) + '</div>';
            (data.warnings || []).forEach(w => {
                const key = { no_route: 'routing_warn_no_route', domains_no_shared: 'routing_warn_domains_no_shared', unauthenticated: 'routing_warn_unauth' }[w];
                if (key && !(w === 'no_route' && !(data.paths && data.paths.length))) html += '<div class="routing-warn"><span>⚠️</span><span>' + esc(tc(key)) + '</span></div>';
            });
            if (data.catches_unrouted) html += '<div class="routing-note">' + esc(tc('routing_default_note')) + '</div>';
            panel.innerHTML = html;
        }

        // ── supports other companies (GH #228) ───────────────────────────────
        async function loadSupport(tenantId) {
            const list = $('coeSupportList');
            list.innerHTML = '';
            let data = null;
            try { data = await getJson(API + 'system/get_tenant_support.php?tenant_id=' + tenantId); } catch (e) { data = null; }
            if (!data || !data.success) { list.innerHTML = '<div class="list-none">' + esc(tc('support_failed')) + '</div>'; return; }
            if (!data.ready) { list.innerHTML = '<div class="list-none">' + esc(tc('support_not_ready')) + '</div>'; return; }
            if (!data.companies.length) { list.innerHTML = '<div class="list-none">' + esc(tc('support_none')) + '</div>'; return; }
            list.innerHTML = '<table class="support-table"><thead><tr><th>' + esc(tc('support_col_company')) + '</th>'
                + '<th class="tick"><abbr title="' + esc(tc('support_col_quiet_hint')) + '">' + esc(tc('support_col_quiet')) + '</abbr></th>'
                + '<th class="tick"><abbr title="' + esc(tc('support_col_portal_hint')) + '">' + esc(tc('support_col_portal')) + '</abbr></th></tr></thead><tbody>'
                + data.companies.map(c => '<tr data-supported="' + c.id + '"><td>' + esc(c.name) + (c.is_active ? '' : '<span class="support-inactive">(' + esc(tc('support_inactive')) + ')</span>') + '</td>'
                    + '<td class="tick"><input type="checkbox" data-tick="quiet_warning" aria-label="' + esc(tc('support_col_quiet') + ': ' + c.name) + '"' + (c.quiet_warning ? ' checked' : '') + '></td>'
                    + '<td class="tick"><input type="checkbox" data-tick="portal_raise" aria-label="' + esc(tc('support_col_portal') + ': ' + c.name) + '"' + (c.portal_raise ? ' checked' : '') + '></td></tr>').join('')
                + '</tbody></table>';
            if (window.FreeitsmSelectSearch) FreeitsmSelectSearch.filterList(list, 'tbody tr');   // GH #358: many companies
        }
        $('coeSupportList').addEventListener('change', async function (e) {
            const row = e.target.closest('tr[data-supported]');
            if (!row) return;
            const boxes = row.querySelectorAll('input[data-tick]');
            boxes.forEach(b => b.disabled = true);
            try {
                const d = await postJson(API + 'system/save_tenant_support.php', {
                    tenant_id: parseInt($('coeId').value, 10), supported_id: parseInt(row.getAttribute('data-supported'), 10),
                    quiet_warning: row.querySelector('[data-tick=quiet_warning]').checked ? 1 : 0,
                    portal_raise: row.querySelector('[data-tick=portal_raise]').checked ? 1 : 0 });
                if (d.success) toast(tc('support_saved'), 'success');
                else { e.target.checked = !e.target.checked; toast(d.error || tc('support_failed'), 'error'); }
            } catch (err) { e.target.checked = !e.target.checked; toast(tc('support_failed'), 'error'); }
            boxes.forEach(b => b.disabled = false);
        });

        // ── open / close ─────────────────────────────────────────────────────
        async function open(company, o) {
            opts = o || {};
            await Promise.all([loadCompanies(), loadFreemail()]);
            editing = (company && company.id) ? (companies.find(c => String(c.id) === String(company.id)) || company) : null;
            const c = editing;
            $('coeTitle').textContent = tc(c ? 'modal_edit_title' : 'modal_add_title');
            $('coeId').value = c ? c.id : '';
            $('coeName').value = c ? c.name : '';
            $('coeExternalRef').value = (c && c.external_ref) || '';
            $('coeTaxId').value = (c && c.tax_id) || '';
            $('coeActive').checked = c ? !!c.is_active : true;
            $('coeActive').disabled = !!(c && c.is_default);   // the default company is always active
            $('coeCode').value = (c && c.ticket_code) ? c.ticket_code : '';
            $('coeCodeSection').hidden = !codeRelevant();
            $('coeLogoSection').hidden = !c;
            renderLogo(c ? c.logo_url : null);

            // #419: a new company for a person - suggest a name and offer to route their mail here.
            route = null;
            const email = (!c && opts.prefill && opts.prefill.email) ? String(opts.prefill.email).trim().toLowerCase() : '';
            const at = email.lastIndexOf('@');
            if (at > 0) {
                const domain = email.slice(at + 1);
                const owner = companies.find(x => (x.domains || []).map(d => String(d).toLowerCase()).includes(domain));
                if (domain && !freemail.has(domain)) {
                    if (!$('coeName').value) $('coeName').value = nameFromDomain(domain);
                    // A domain another company already receives cannot be routed twice - the
                    // duplicate warning names that company instead.
                    route = owner ? { kind: 'domain', domain, taken: true } : { kind: 'domain', domain };
                } else if (domain) {
                    route = { kind: 'sender', domain, address: email };
                }
            }
            const routeOn = !!(route && !route.taken);
            $('coeRouteField').hidden = !routeOn;
            $('coeRoute').checked = true;
            if (routeOn) {
                $('coeRouteLabel').textContent = route.kind === 'domain' ? tc('route_domain_label', { domain: route.domain }) : tc('route_sender_label', { address: route.address });
                $('coeRouteHelp').textContent = route.kind === 'domain' ? tc('route_domain_help', { domain: route.domain }) : tc('route_sender_help', { domain: route.domain });
            }

            const multiEdit = !!(c && companies.length > 1);
            ['coeDomainsSection', 'coeSendersSection', 'coeRoutingSection', 'coeSupportSection'].forEach(id => { $(id).hidden = !multiEdit; });
            $('coeDomainInput').value = ''; $('coeSenderInput').value = '';
            ['coeDomainsList', 'coeSendersList', 'coeRoutingPanel', 'coeSupportList'].forEach(id => { $(id).innerHTML = ''; });
            if (multiEdit) { loadDomains(c.id); loadSenders(c.id); loadRouting(c.id); loadSupport(c.id); }

            codeHint();
            paintDuplicates();
            loadCustomFields(c ? c.id : 0);
            $('coeOverlay').classList.add('open');
            $('coeName').focus();
        }
        async function openById(id, o) {
            await loadCompanies();
            const c = companies.find(x => String(x.id) === String(id));
            if (c) open(c, o);
        }
        function close() {
            $('coeOverlay').classList.remove('open');
            if (typeof opts.onClose === 'function') opts.onClose();
        }
        $('coeCancel').addEventListener('click', close);
        $('coeOverlay').addEventListener('click', e => { if (e.target === $('coeOverlay')) close(); });
        // Escape closes THIS dialog only - the person editor underneath stays open.
        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape' || !$('coeOverlay').classList.contains('open')) return;
            e.preventDefault(); e.stopImmediatePropagation(); close();
        }, true);

        // ── save ─────────────────────────────────────────────────────────────
        $('coeSave').addEventListener('click', async function () {
            const payload = {
                id: $('coeId').value || 0,
                name: $('coeName').value.trim(),
                is_active: $('coeActive').checked ? 1 : 0,
                // Sent even when hidden: empty means "derive one", the server's answer too.
                ticket_code: $('coeCode').value.trim(),
                external_ref: $('coeExternalRef').value.trim(),
                tax_id: $('coeTaxId').value.trim()
            };
            const cf = $('coeCustomFields');
            if (window.TicketFields && cf.querySelector('[data-tf-key]')) payload.custom_fields = TicketFields.collect(cf);
            if (!payload.name) { toast(tc('required_name'), 'error'); $('coeName').focus(); return; }
            this.disabled = true;
            let d = null;
            try { d = await postJson(API + 'system/save_tenant.php', payload); } catch (e) { d = null; }
            if (!d || !d.success) {
                toast(d && d.error ? tc('error', { error: d.error }) : tc('save_failed'), 'error');
                this.disabled = false;
                return;
            }
            const savedId = d.id || payload.id;
            // #419: route the person's domain or address to the NEW company. The company is
            // made either way; a refusal here (someone mapped the domain meanwhile) is said.
            if (!payload.id && route && !route.taken && !$('coeRouteField').hidden && $('coeRoute').checked) {
                try {
                    const r = route.kind === 'domain'
                        ? await postJson(API + 'system/add_tenant_domain.php', { tenant_id: savedId, domain: route.domain })
                        : await postJson(API + 'system/add_tenant_sender.php', { tenant_id: savedId, email: route.address });
                    if (!r.success) toast(tc('route_failed', { error: r.error || '' }), 'error');
                } catch (e) { toast(tc('route_failed', { error: '' }), 'error'); }
            }
            this.disabled = false;
            toast(tc('company_saved'), 'success');
            const cb = opts.onSaved;
            close();
            if (typeof cb === 'function') cb({ id: savedId, name: payload.name });
        });

        return { open, openById, close };
    })();
    </script>
    <?php
}

/**
 * The custom-field renderer's <script> tag, once per page however many editors ask
 * for it (the person editor and the company editor can share a page).
 */
function ticketFieldsScriptOnce(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $base = defined('BASE_URL') ? BASE_URL : '/';
    echo '<script src="' . htmlspecialchars($base) . 'assets/js/ticket-fields.js?v=2"></script>' . "\n";
}
