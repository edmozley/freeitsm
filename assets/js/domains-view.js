/**
 * Domains — one domain's page (domains/view.php).
 *
 * @wiki Domains-Developer-Guide
 */
(function () {
    'use strict';
    const { T, esc, api, fmtDate, grade, health, daysPill, status, purpose, renewal, tags, finding, money } = window.Dom;
    /** GH #203: is this domain internal - health instead of a grade, nothing sent outside? */
    const internal = () => D && D.scope === 'internal';
    /** A stored UTC date-time in the analyst's format. */
    const when = v => v ? (window.fmtDateTime ? fmtDateTime(String(v).replace(' ', 'T') + 'Z') : v) : '';
    const ID = window.DOMAIN_ID;
    let D = null, data = null, L = null;

    document.addEventListener('DOMContentLoaded', async () => {
        wire();
        await Promise.all([load(), loadLookups()]);
        const tab = new URLSearchParams(location.search).get('tab');
        if (tab) switchTab(tab);
        // ?edit=1 - from the register's right-click Edit: straight into the dialog.
        if (new URLSearchParams(location.search).get('edit') === '1' && D) openEdit();
    });

    /**
     * Fetches the domain and redraws every tab, then loads its connections; on failure shows the error in
     * the hero.
     */
    async function load() {
        try {
            data = await api('get.php?id=' + ID);
            D = data.domain;
            renderHero(); renderOverview(); renderSecurity(); renderCerts(); renderLookalikes(); renderHistory();
            loadConnections();
        } catch (e) {
            document.getElementById('hero').innerHTML = '<div class="dom-empty">' + esc(e.message) + '</div>';
        }
    }
    /** Fetches the drop-down lists the Edit dialog needs; leaves them null when that fails. */
    async function loadLookups() {
        try { L = (await api('lookups.php')).lookups; } catch (e) { L = null; }
    }

    // ---------------------------------------------------------------- hero
    function renderHero() {
        // A subdomain renews with its parent: the countdown is the parent's.
        const P = D.parent;
        const dl = P ? P.days_left : D.days_left;
        const expiry = P ? P.expiry_date : D.expiry_date, mode = P ? P.renewal_mode : D.renewal_mode;
        let cls = 'green', n = dl, l = T('page.days_left');
        if (dl === null) { n = '?'; cls = ''; l = T('page.expiry_unknown'); }
        else if (dl < 0) { cls = 'red'; n = -dl; l = T('page.days_since'); }
        else if (dl <= 30) cls = dl <= 7 ? 'red' : 'amber';
        document.getElementById('hero').innerHTML =
            (internal()
                ? '<div>' + health(D.health, true) + '</div>'
                : '<div>' + grade(D.security_grade, true) + (D.security_score !== null ? '<div class="dom-sub" style="text-align:center">' + esc(D.security_score) + '/100</div>' : '') + '</div>')
            + '<div style="min-width:0"><h1>' + esc(D.display_name || D.domain_name) + '</h1>'
            + (D.display_name ? '<div class="dom-sub">' + esc(D.domain_name) + '</div>' : '')
            + (P ? '<div class="dom-sub">' + withLink('page.subdomain_of', '<a href="view.php?id=' + P.id + '">' + esc(P.display_name || P.domain_name) + '</a>') + '</div>' : '')
            + '<div class="meta">' + status(D.status_name, D.status_colour)
            + '<span>' + esc(internal() ? T('scope.internal') : purpose(D.purpose)) + '</span>'
            + (data.multi_company && D.company_name ? '<span>· ' + esc(D.company_name) + '</span>' : '')
            + (D.owner_name ? '<span>· ' + esc(T('page.owned_by', { name: D.owner_name })) + '</span>' : '<span class="dom-pill amber">' + esc(T('page.no_owner')) + '</span>')
            + (D.monitoring_enabled ? '' : '<span class="dom-pill grey">' + esc(T('page.monitoring_off')) + '</span>')
            + '</div>' + (D.tags ? '<div style="margin-top:6px">' + tags(D.tags) + '</div>' : '') + '</div>'
            // An internal domain has no registration to count down to (GH #203).
            + (internal() ? '' : '<div class="count-down"><div class="n ' + cls + '">' + esc(n) + '</div><div class="l">' + esc(l) + '</div>'
            + (expiry ? '<div class="dom-sub">' + esc(fmtDate(expiry)) + ' · ' + esc(renewal(mode)) + '</div>' : '') + '</div>');
        // The Security and email tab is Health for an internal domain - same tab, same
        // id (Check now and ?tab=security links land on it) - and an internal domain
        // has no registry to refresh from and no look-alikes to watch.
        const secTab = document.querySelector('#domTabs [data-tab="security"]');
        secTab.firstChild.textContent = T(internal() ? 'tab.health' : 'tab.security') + ' ';
        document.querySelector('#domTabs [data-tab="lookalikes"]').hidden = internal();
        // TRAP: not .hidden - .dom-btn sets display, which beats the hidden attribute.
        document.getElementById('btnRefresh').style.display = internal() ? 'none' : '';
        document.title = (document.titleBase = document.titleBase || document.title) + ' - ' + (D.display_name || D.domain_name);   // GH #173: appended to the server's pageTitle()

        const f = D.findings || [];
        const bad = f.filter(x => x.level === 'fail').length, warn = f.filter(x => x.level === 'warn').length;
        document.getElementById('secBadge').innerHTML = bad ? '<span class="dom-pill red">' + bad + '</span>' : (warn ? '<span class="dom-pill amber">' + warn + '</span>' : '');
        const newCt = (data.ct || []).filter(c => c.acknowledged == 0).length;
        document.getElementById('certBadge').innerHTML = newCt ? '<span class="dom-pill red">' + newCt + '</span>' : '';
        const la = (data.lookalikes || []).filter(x => x.dismissed == 0).length;
        document.getElementById('laBadge').innerHTML = la ? '<span class="dom-pill amber">' + la + '</span>' : '';
    }

    /** A translated sentence with a link where its {name} goes - the words escaped, the link as given. */
    function withLink(key, linkHtml) {
        const parts = T(key, { name: '\u0001' }).split('\u0001');
        return esc(parts[0]) + linkHtml + esc(parts[1] || '');
    }

    // ---------------------------------------------------------------- overview
    function row(k, v, mono) {
        if (v === null || v === undefined || v === '') v = '<span class="dom-sub">—</span>';
        return '<div class="k">' + esc(T(k)) + '</div><div class="v' + (mono ? ' mono' : '') + '">' + v + '</div>';
    }
    /** Draws a lock or DNSSEC flag as a green on pill, a red off pill, or grey unknown. */
    function yn(v) { return v === true ? '<span class="dom-pill green">' + esc(T('lock.on')) + '</span>' : (v === false ? '<span class="dom-pill red">' + esc(T('lock.off')) + '</span>' : '<span class="dom-sub">' + esc(T('lock.unknown')) + '</span>'); }

    /**
     * Draws the Overview tab: the top three checks worth fixing, then the registration, DNS and web, and
     * people and money cards.
     */
    function renderOverview() {
        const P = D.parent;
        // A subdomain's registration is its parent's: shown read-only, with a way there.
        const parentReg = P ? '<div class="dom-card"><div class="dom-card-h"><h3>' + esc(T('page.parent_reg', { name: P.display_name || P.domain_name })) + '</h3>'
            + '<a href="view.php?id=' + P.id + '" class="dom-sub">' + esc(T('page.parent_open', { name: P.display_name || P.domain_name })) + '</a></div>'
            + '<div class="dom-card-b"><p class="dom-hint" style="margin:0 0 12px">' + esc(T('page.parent_reg_note', { name: P.display_name || P.domain_name })) + '</p><div class="dom-fields">'
            + row('field.registrar', esc(P.supplier_name || P.registrar_name || ''))
            + row('field.expiry_date', P.expiry_date ? esc(fmtDate(P.expiry_date)) + ' ' + daysPill(P.days_left) : '')
            + row('field.renewal_mode', esc(renewal(P.renewal_mode)))
            + row('field.transfer_lock', yn(P.transfer_lock))
            + row('field.dnssec', yn(P.dnssec))
            + '</div></div></div>' : '';
        const subs = data.subdomains || [];
        const subCard = subs.length ? '<div class="dom-card"><div class="dom-card-h"><h3>' + esc(T('page.subdomains')) + ' <span class="dom-pill grey">' + subs.length + '</span></h3></div>'
            + '<div class="dom-card-b"><p class="dom-hint" style="margin:0 0 12px">' + esc(T('page.subdomains_hint')) + '</p>'
            + '<table class="dom-table"><tbody>' + subs.map(s => '<tr style="cursor:default"><td><a href="view.php?id=' + s.id + '">' + esc(s.display_name || s.domain_name) + '</a></td>'
                + '<td>' + grade(s.security_grade) + '</td><td class="dom-sub">' + esc(purpose(s.purpose)) + '</td></tr>').join('') + '</tbody></table></div></div>' : '';
        const reg = internal() ? healthCard() : P ? parentReg : '<div class="dom-card"><div class="dom-card-h"><h3>' + esc(T('page.registration')) + '</h3>'
            + '<span class="dom-sub">' + esc(D.lookup_source ? T('source.' + D.lookup_source) : T('source.manual'))
            + (D.last_lookup_datetime ? ' · ' + esc(window.fmtDateTime ? fmtDateTime(D.last_lookup_datetime.replace(' ', 'T') + 'Z') : D.last_lookup_datetime) : '') + '</span></div>'
            + '<div class="dom-card-b">'
            + (D.last_lookup_error ? '<div class="dom-finding warn" style="margin-bottom:12px"><span class="ic">!</span><div><div class="t">' + esc(T('page.lookup_error')) + '</div><div class="a">' + esc(D.last_lookup_error) + '</div></div></div>' : '')
            + '<div class="dom-fields">'
            + row('field.registrar', esc(D.supplier_name || D.registrar_name || '') + (D.supplier_name && D.registrar_name && D.supplier_name !== D.registrar_name ? '<div class="dom-sub">' + esc(D.registrar_name) + '</div>' : ''))
            + row('field.registrar_account', D.account_name ? '<a href="accounts/?id=' + D.registrar_account_id + '">' + esc(D.account_name) + '</a>' : '')
            + row('field.registration_date', esc(fmtDate(D.registration_date)))
            + row('field.expiry_date', D.expiry_date ? esc(fmtDate(D.expiry_date)) + ' ' + daysPill(D.days_left) : '')
            + row('field.last_renewed_date', esc(fmtDate(D.last_renewed_date)))
            + row('field.renewal_mode', esc(renewal(D.renewal_mode)))
            + row('field.transfer_lock', yn(D.transfer_lock))
            + row('field.registry_lock', D.registry_lock ? yn(true) : '<span class="dom-sub">' + esc(T('page.none')) + '</span>')
            + row('field.dnssec', yn(D.dnssec))
            + row('field.registrant_name', esc(D.registrant_name || ''))
            + row('field.registry_statuses', esc(D.registry_statuses || ''))
            + row('field.auth_code', authCodeCell())
            + '</div></div></div>';

        const dns = '<div class="dom-card"><div class="dom-card-h"><h3>' + esc(T('page.dns_web')) + '</h3></div><div class="dom-card-b"><div class="dom-fields">'
            // An internal domain has no delegation, checked certificates or DKIM (GH #203).
            + (internal() ? '' : row('field.nameservers', esc(D.nameservers || ''), true))
            + row('field.dns_provider', esc(D.dns_provider || ''))
            + row('field.hosting_provider', esc(D.hosting_provider || ''))
            + (internal() ? '' : row('field.ssl_expiry', D.ssl_expiry_date ? esc(fmtDate(D.ssl_expiry_date)) + ' ' + daysPill(D.ssl_days_left, 21) + (D.ssl_issuer ? '<div class="dom-sub">' + esc(D.ssl_issuer) + '</div>' : '') : ''))
            + row('field.ssl_hosts', esc(D.ssl_hosts || ''))
            + (internal() ? '' : row('field.dkim_selectors', esc(D.dkim_selectors || '')))
            + row('field.last_check', D.last_check_datetime ? esc(window.fmtDateTime ? fmtDateTime(D.last_check_datetime.replace(' ', 'T') + 'Z') : D.last_check_datetime) : '')
            + '</div></div></div>';

        const people = '<div class="dom-card"><div class="dom-card-h"><h3>' + esc(T('page.people_money')) + '</h3></div><div class="dom-card-b"><div class="dom-fields">'
            + row('field.owner', esc(D.owner_name || ''))
            + row('field.tech_contact', techCell())
            + row('field.customer', customerCell())
            + (data.multi_company ? row('field.company', esc(D.company_name || '')) : '')
            + row('field.cost', D.cost !== null ? esc(money(D.cost, D.currency)) + (D.billing_years > 1 ? ' <span class="dom-sub">/ ' + esc(T('page.years', { n: D.billing_years })) + '</span>' : '') : '')
            // GH #188: the cost centre from System -> Cost centres; text typed before it was a list shows as previously recorded.
            + row('field.cost_centre', D.cost_centre_label ? esc(D.cost_centre_label) : (D.cost_centre ? esc(D.cost_centre) + ' <span class="dom-sub">' + esc(T('field.cost_centre_old')) + '</span>' : ''))
            + row('field.contract', D.contract_id ? '<a href="' + esc(window.DOM_API.replace('api/domains/', '') + 'contracts/view.php?id=' + D.contract_id) + '">' + esc(D.contract_label || ('#' + D.contract_id)) + '</a>' : '')
            + row('field.tags', tags(D.tags))
            + '</div>' + (D.notes ? '<div style="margin-top:14px;white-space:pre-wrap;font-size:13px">' + esc(D.notes) + '</div>' : '') + '</div></div>';

        // The three things most worth fixing, on the first tab, so nobody has to go looking.
        const top = (D.findings || []).filter(f => f.level === 'fail' || f.level === 'warn').sort((a, b) => b.weight - a.weight).slice(0, 3);
        const fix = top.length ? '<div class="dom-card" style="grid-column:1/-1"><div class="dom-card-h"><h3>' + esc(T('page.fix_first')) + '</h3>'
            + '<a href="#" data-goto="security" class="dom-sub">' + esc(T('page.see_all_checks')) + '</a></div><div class="dom-card-b"><div class="dom-findings">'
            + top.map(finding).join('') + '</div></div></div>' : '';

        document.getElementById('overview').innerHTML = fix + reg + dns + people + subCard;
    }

    /**
     * An internal domain's health card (GH #203): the state, the two dates the review
     * asked for - the last completed check and the last healthy result - and when the
     * next check is due, with a plain note when the state is not current.
     */
    function healthCard() {
        const h = D.health || { state: 'not_checked' };
        const due = D.last_check_datetime && h.interval_minutes
            ? new Date(new Date(D.last_check_datetime.replace(' ', 'T') + 'Z').getTime() + h.interval_minutes * 60000) : null;
        let note = '';
        if (h.state === 'stale') note = T('health.stale_note', { minutes: h.interval_minutes * 2 });
        else if (h.state === 'not_checked' && !h.known) note = T('health.not_checked_note');
        if (h.known && h.known !== h.state) note = T('health.known_after', { state: T('health.' + h.known), date: when(h.last_completed) }) + (note ? ' ' + note : '');
        return '<div class="dom-card"><div class="dom-card-h"><h3>' + esc(T('health.label')) + '</h3></div><div class="dom-card-b">'
            + '<div class="dom-internal-note">' + esc(T('view.internal_note')) + '</div>'
            + (note ? '<p class="dom-hint" style="margin:0 0 12px">' + esc(note) + '</p>' : '')
            + '<div class="dom-fields">'
            + row('health.label', health(h))
            + row('health.last_completed', h.last_completed ? esc(when(h.last_completed)) : esc(T('health.never')))
            + row('health.last_healthy', h.last_healthy ? esc(when(h.last_healthy)) : esc(T('health.never')))
            + row('health.next_due', due ? esc(window.fmtDateTime ? fmtDateTime(due.toISOString()) : due.toISOString()) : '')
            + '</div></div></div>';
    }

    /**
     * Draws the auth code field: hidden with Reveal and Change links for analysts allowed auth codes,
     * otherwise just whether one is set.
     */
    function authCodeCell() {
        if (!data.can_auth_codes) return D.auth_code_set ? '<span class="dom-sub">' + esc(T('code.set_hidden')) + '</span>' : '';
        return (D.auth_code_set ? '<span id="codeVal" style="font-family:monospace">••••••••</span> <a href="#" id="codeReveal">' + esc(T('code.reveal')) + '</a> · ' : '')
            + '<a href="#" id="codeChange">' + esc(D.auth_code_set ? T('code.change') : T('code.add')) + '</a>';
    }

    // ---------------------------------------------------------------- security
    const AREAS = ['health', 'registration', 'dns', 'email', 'web'];   // 'health': an internal domain's checks (GH #203)
    /** Draws the Security tab - the grade and score, then the findings grouped by area, worst first. */
    function renderSecurity() {
        const f = D.findings || [];
        if (!f.length) {
            document.getElementById('security').innerHTML = '<div class="dom-empty"><h3>' + esc(T('page.not_checked')) + '</h3><p>' + esc(T('page.not_checked_body')) + '</p></div>';
            return;
        }
        const order = { fail: 0, warn: 1, info: 2, pass: 3 };
        const intro = internal()
            ? '<div style="display:flex;gap:16px;align-items:center;margin-bottom:18px">' + health(D.health, true)
              + '<div class="dom-sub">' + esc(T('view.internal_note')) + (D.checked_at ? ' ' + esc(T('page.checked_at', { when: fmtDate(D.checked_at) })) : '') + '</div></div>'
            : '<div style="display:flex;gap:16px;align-items:center;margin-bottom:18px">' + grade(D.security_grade, true)
            + '<div><div style="font-weight:600">' + esc(T('page.grade_line', { score: D.security_score })) + '</div>'
            + '<div class="dom-sub">' + esc(T('page.grade_explain')) + (D.checked_at ? ' ' + esc(T('page.checked_at', { when: fmtDate(D.checked_at) })) : '') + '</div></div></div>';
        document.getElementById('security').innerHTML = intro + AREAS.map(a => {
            const items = f.filter(x => x.area === a).sort((x, y) => order[x.level] - order[y.level]);
            if (!items.length) return '';
            return '<div class="dom-area-h">' + esc(T('area.' + a)) + '</div><div class="dom-findings">' + items.map(finding).join('') + '</div>';
        }).join('');
    }

    // ---------------------------------------------------------------- certificates
    function renderCerts() {
        const live = D.certificates || [];
        const liveHtml = live.length ? '<table class="dom-table"><thead><tr><th class="nosort">' + esc(T('cert.host')) + '</th><th class="nosort">' + esc(T('cert.issuer')) + '</th><th class="nosort">' + esc(T('cert.expires')) + '</th><th class="nosort">' + esc(T('cert.names')) + '</th><th class="nosort">' + esc(T('cert.trusted')) + '</th></tr></thead><tbody>'
            + live.map(c => '<tr style="cursor:default"><td>' + esc(c.host) + '</td><td>' + esc(c.ok ? (c.issuer || '') : '—') + '</td><td>'
                + (c.ok ? esc(fmtDate(c.valid_to)) + ' ' + daysPill(c.days_left, 21) : '<span class="dom-sub">' + esc(c.error || '') + '</span>') + '</td><td class="dom-sub">'
                + esc((c.sans || []).slice(0, 6).join(', ')) + ((c.sans || []).length > 6 ? '…' : '') + '</td><td>'
                + (c.ok ? (c.hostname_match === false ? '<span class="dom-pill red">' + esc(T('cert.wrong_name')) + '</span>' : (c.chain_valid === false ? '<span class="dom-pill red">' + esc(T('cert.untrusted')) + '</span>' : '<span class="dom-pill green">' + esc(T('cert.ok')) + '</span>')) : '')
                + '</td></tr>').join('') + '</tbody></table>'
            : '<div class="dom-sub">' + esc(T('cert.none_live')) + '</div>';

        const ct = data.ct || [];
        const newOnes = ct.filter(c => c.acknowledged == 0).length;
        const last = (data.last_scans || {}).ct_scanned;
        const ctHtml = '<div style="display:flex;align-items:center;gap:8px;margin:22px 0 10px"><h3 style="margin:0;font-size:15px">' + esc(T('cert.ct_title')) + '</h3>'
            + '<span class="dom-sub">' + esc(last ? T('cert.last_scan', { date: fmtDate(last) }) : T('cert.never_scanned')) + '</span><span style="flex:1"></span>'
            + (newOnes ? '<button type="button" class="dom-btn" id="ctAck">' + esc(T('cert.ack_all')) + '</button>' : '')
            + '<button type="button" class="dom-btn" id="ctScan">' + esc(T('cert.scan_now')) + '</button></div>'
            + '<p class="dom-hint" style="font-size:12.5px">' + esc(T('cert.ct_explain')) + '</p>'
            + (ct.length ? '<table class="dom-table"><thead><tr><th class="nosort"></th><th class="nosort">' + esc(T('cert.names')) + '</th><th class="nosort">' + esc(T('cert.issuer')) + '</th><th class="nosort">' + esc(T('cert.issued')) + '</th><th class="nosort">' + esc(T('cert.expires')) + '</th></tr></thead><tbody>'
                + ct.map(c => '<tr style="cursor:default"><td>' + (c.acknowledged == 0 ? '<span class="dom-pill red">' + esc(T('cert.new')) + '</span>' : '') + '</td><td>'
                    + esc(String(c.name_value || c.common_name || '').split('\n').slice(0, 4).join(', ')) + '</td><td>' + esc(c.issuer) + '</td><td>' + esc(fmtDate(c.not_before)) + '</td><td>' + esc(fmtDate(c.not_after)) + '</td></tr>').join('')
                + '</tbody></table>' : '');
        document.getElementById('certs').innerHTML = manualCertsHtml() + '<h3 style="margin:22px 0 10px;font-size:15px">' + esc(T('cert.live_title')) + '</h3>' + liveHtml + ctHtml;
    }

    /** The certificates recorded by hand: what each is for, its type, provider and expiry, with Edit and Delete. */
    function manualCertsHtml() {
        if (!data.manual_certs_ready) return '<p class="dom-hint">' + esc(T('cert.m_not_ready')) + '</p>';
        const m = data.manual_certificates || [];
        return '<div style="display:flex;align-items:center;gap:8px;margin:0 0 6px"><h3 style="margin:0;font-size:15px">' + esc(T('cert.manual_title')) + '</h3>'
            + '<span style="flex:1"></span><button type="button" class="dom-btn" id="mcAdd">' + esc(T('cert.manual_add')) + '</button></div>'
            + '<p class="dom-hint" style="font-size:12.5px">' + esc(T('cert.manual_intro')) + '</p>'
            + (m.length ? '<table class="dom-table"><thead><tr><th class="nosort">' + esc(T('cert.m_purpose')) + '</th><th class="nosort">' + esc(T('cert.m_type')) + '</th><th class="nosort">'
                + esc(T('cert.m_provider')) + '</th><th class="nosort">' + esc(T('cert.expires')) + '</th><th class="nosort"></th></tr></thead><tbody>'
                + m.map(c => '<tr style="cursor:default"><td><strong>' + esc(c.purpose) + '</strong>'
                    + (c.url ? '<div class="dom-sub"><a href="' + esc(c.url) + '" target="_blank" rel="noopener noreferrer">' + esc(c.url) + '</a></div>' : '')
                    + (c.notes ? '<div class="dom-sub" style="white-space:pre-wrap">' + esc(c.notes) + '</div>' : '') + '</td>'
                    + '<td>' + esc(c.cert_type || '') + '</td><td>' + esc(c.provider || '') + '</td>'
                    + '<td>' + esc(fmtDate(c.expiry_date)) + ' ' + daysPill(c.days_left, 21) + '</td>'
                    + '<td style="white-space:nowrap"><a href="#" data-mc-edit="' + c.id + '">' + esc(window.t('common.edit')) + '</a> · <a href="#" data-mc-del="' + c.id + '">' + esc(window.t('common.delete')) + '</a></td></tr>').join('')
                + '</tbody></table>'
              : '<div class="dom-sub">' + esc(T('cert.manual_none')) + '</div>');
    }

    let mcEditing = 0;
    /** Opens the certificate dialog, empty or filled from one recorded certificate. */
    function openCert(c) {
        mcEditing = c ? c.id : 0;
        document.getElementById('mcTitle').textContent = T(c ? 'cert.manual_edit' : 'cert.manual_new');
        // Active types, plus this certificate's own if it has since been switched off.
        const types = (data.cert_types || []).slice();
        if (c && c.cert_type_id && !types.some(t => t.id === c.cert_type_id)) types.push({ id: c.cert_type_id, name: c.cert_type });
        window.Dom.fillSelect('mcType', types, { blank: '—' });
        const sups = ((L && L.suppliers) || []).slice();
        if (c && c.supplier_id && !sups.some(s => +s.id === c.supplier_id)) sups.unshift({ id: c.supplier_id, name: c.supplier_name });
        window.Dom.fillSelect('mcSupplier', sups, { blank: T('cert.m_provider_other') });
        document.getElementById('mcPurpose').value = c ? c.purpose : '';
        document.getElementById('mcType').value = c && c.cert_type_id ? c.cert_type_id : '';
        document.getElementById('mcSupplier').value = c && c.supplier_id ? c.supplier_id : '';
        document.getElementById('mcProvider').value = c ? (c.provider_name || '') : '';
        document.getElementById('mcExpiry').value = c ? c.expiry_date : '';
        document.getElementById('mcUrl').value = c ? (c.url || '') : '';
        document.getElementById('mcNotes').value = c ? (c.notes || '') : '';
        mcProviderToggle();
        window.Dom.openModal('mCert');
        document.getElementById('mcPurpose').focus();
    }
    /** The typed provider is only for one that is not a supplier. */
    function mcProviderToggle() {
        document.getElementById('mcProviderGroup').hidden = document.getElementById('mcSupplier').value !== '';
    }
    /** Saves the certificate dialog and redraws the tab. */
    async function saveCert() {
        const sup = document.getElementById('mcSupplier').value;
        const btn = document.getElementById('mcSave');
        btn.disabled = true;
        try {
            await api('certificates.php', {
                action: 'save', id: mcEditing || undefined, domain_id: ID,
                purpose: document.getElementById('mcPurpose').value.trim(),
                cert_type_id: document.getElementById('mcType').value || null,
                supplier_id: sup || null,
                provider_name: sup ? '' : document.getElementById('mcProvider').value.trim(),
                expiry_date: document.getElementById('mcExpiry').value,
                url: document.getElementById('mcUrl').value.trim(),
                notes: document.getElementById('mcNotes').value,
            });
            window.Dom.closeModal('mCert');
            showToast(T('cert.m_saved'), 'success');
            await load();
            switchTab('certs');
        } catch (e) { showToast(e.message, 'error'); } finally { btn.disabled = false; }
    }

    // ---------------------------------------------------------------- look-alikes
    function renderLookalikes() {
        const la = data.lookalikes || [];
        const last = (data.last_scans || {}).lookalikes_scanned;
        let html = '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px"><span class="dom-sub">'
            + esc(last ? T('la.last_scan', { date: fmtDate(last) }) : T('la.never_scanned')) + '</span><span style="flex:1"></span>'
            + '<button type="button" class="dom-btn" id="laScan">' + esc(T('la.scan_now')) + '</button></div>'
            + '<p class="dom-hint" style="font-size:12.5px">' + esc(T('la.explain')) + '</p>';
        if (!la.length) {
            html += '<div class="dom-empty">' + esc(last ? T('la.none_found') : T('la.not_scanned')) + '</div>';
        } else {
            html += '<table class="dom-table"><thead><tr><th class="nosort">' + esc(T('la.name')) + '</th><th class="nosort">' + esc(T('la.technique')) + '</th><th class="nosort">' + esc(T('la.web')) + '</th><th class="nosort">' + esc(T('la.mail')) + '</th><th class="nosort">' + esc(T('la.first_seen')) + '</th><th class="nosort"></th></tr></thead><tbody>'
                + la.map(x => '<tr style="cursor:default;' + (x.dismissed == 1 ? 'opacity:.5' : '') + '"><td><strong>' + esc(x.lookalike) + '</strong></td><td>' + esc(T('la.tech.' + x.technique)) + '</td>'
                    + '<td>' + (x.has_a == 1 ? '<span class="dom-pill amber">' + esc(window.t('common.yes')) + '</span>' : '') + '</td>'
                    + '<td>' + (x.has_mx == 1 ? '<span class="dom-pill red">' + esc(T('la.receives_mail')) + '</span>' : '') + '</td>'
                    + '<td class="dom-sub">' + esc(fmtDate(x.first_seen_datetime)) + '</td>'
                    + '<td><a href="#" data-la="' + x.id + '" data-dismissed="' + (x.dismissed == 1 ? 0 : 1) + '">' + esc(x.dismissed == 1 ? T('la.restore') : T('la.dismiss')) + '</a></td></tr>').join('')
                + '</tbody></table>';
        }
        document.getElementById('lookalikes').innerHTML = html;
    }

    // ---------------------------------------------------------------- history
    function renderHistory() {
        const h = data.history || [];
        if (!h.length) { document.getElementById('history').innerHTML = '<div class="dom-empty">' + esc(T('hist.none')) + '</div>'; return; }
        document.getElementById('history').innerHTML = h.map(x => {
            const who = x.analyst_name ? esc(x.analyst_name) : esc(T('source.' + x.source));
            const label = T('hist.f.' + x.field_name);
            const name = label === 'domains.hist.f.' + x.field_name ? x.field_name : label;
            let chg = '';
            if (x.field_name === 'domain_created') chg = esc(T('hist.created'));
            else if (x.field_name === 'auth_code_viewed') chg = esc(T('hist.viewed_code'));
            else if (x.old_value !== null || x.new_value !== null) {
                chg = (x.old_value !== null && x.old_value !== '' ? '<del>' + esc(x.old_value) + '</del> → ' : '') + '<ins>' + esc(x.new_value === null || x.new_value === '' ? T('hist.blank') : x.new_value) + '</ins>';
            }
            return '<div class="dom-tl"><div class="when">' + esc(window.fmtDateTime ? fmtDateTime(x.created_datetime.replace(' ', 'T') + 'Z') : x.created_datetime) + '</div>'
                + '<div class="what"><strong>' + esc(name) + '</strong><span class="src">' + who + '</span><div class="chg">' + chg + '</div></div></div>';
        }).join('');
    }


    // ---------------------------------------------------------------- connections (3.0.0)
    // What this domain is part of elsewhere: CIs, Service Status services,
    // tickets, runbooks and its contract. One card per kind the analyst can open
    // (the server leaves out the rest); link by searching, unlink with the ✕.
    // Every rule is server-side in includes/domains/links.php.
    const KINDS = [
        { kind: 'cmdb',    icon: '<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>' },
        { kind: 'service', icon: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>' },
        { kind: 'ticket',  icon: '<path d="M4 4h16v16H4z"/><path d="M8 9h8M8 13h6"/>' },
        { kind: 'article', icon: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>' },
    ];
    let C = null;

    /** Fetches what the domain is linked to and redraws the Connections tab. */
    async function loadConnections() {
        try { C = await api('links.php?domain_id=' + ID); }
        catch (e) { C = { error: e.message }; }
        renderConnections();
    }

    /** Wraps icon paths in an SVG for a connections card heading. */
    function connIcon(paths) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
    }

    /**
     * Draws the banner warning that linked services are at risk from an expired domain or certificate,
     * with the open incident, the Service Status mode, or a Raise incident button.
     */
    function riskHtml() {
        const s = C.status || {};
        if (!s.ready || !(s.problems || []).length || !s.at_risk || s.mode === undefined) return '';
        const why = s.problems.map(p => esc(p.kind === 'expired' ? T('links.risk_expired', { date: fmtDate(p.date) })
            : T(p.date < new Date().toISOString().slice(0, 10) ? 'links.risk_cert_gone' : 'links.risk_cert', { date: fmtDate(p.date) }))).join(' ');
        let act = '';
        if (s.incident) {
            act = '<div class="dom-conn-risk-act">' + esc(T('links.incident_open', { title: s.incident.title }))
                + ' <a class="dom-btn" href="' + esc(window.DOM_BASE + 'service-status/') + '">' + esc(T('links.incident_view')) + '</a></div>';
        } else if (s.mode === 'off') {
            act = '<div class="dom-conn-risk-act dom-sub">' + esc(T('links.mode_off')) + '</div>';
        } else if (!s.can_raise) {
            act = '<div class="dom-conn-risk-act dom-sub">' + esc(T('links.cant_raise')) + '</div>';
        } else {
            act = '<div class="dom-conn-risk-act">' + (s.mode === 'auto' ? '<span class="dom-sub">' + esc(T('links.mode_auto')) + '</span> ' : '')
                + '<button type="button" class="dom-btn danger" id="connRaise">' + esc(T('links.raise')) + '</button></div>';
        }
        return '<div class="dom-conn-risk" role="alert"><strong>' + esc(T('links.risk_title')) + '</strong> ' + why
            + ' ' + esc(T('links.risk_body', { n: s.at_risk })) + act + '</div>';
    }

    /** Draws the Contract card - the linked contract or none, and a button that opens Edit to set it. */
    function contractHtml() {
        const has = D && D.contract_id;
        const label = (data && data.domain && data.domain.contract_label) || '';
        return '<div class="dom-card dom-conn-card"><div class="dom-card-h"><h3>' + connIcon('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>')
            + esc(T('links.contract_title')) + '</h3></div><div class="dom-card-b"><p class="dom-hint">' + esc(T('links.contract_hint')) + '</p>'
            + (has ? '<a class="dom-conn-row-link" href="' + esc(window.DOM_BASE + 'contracts/view.php?id=' + D.contract_id) + '">' + esc(label || ('#' + D.contract_id)) + '</a>'
                   : '<div class="dom-sub">' + esc(T('links.contract_none')) + '</div>')
            + ' <button type="button" class="dom-btn" data-goto-edit="1">' + esc(T('links.contract_set')) + '</button></div></div>';
    }

    /** Draws one linked record with its link, a status or inactive pill, and an unlink button. */
    function rowHtml(kind, r) {
        const extra = kind === 'ticket' && r.status
            ? ' <span class="dom-pill" style="background:' + esc(r.status_colour || '#888') + '22;color:' + esc(r.status_colour || '#666') + '">' + esc(r.status) + '</span>'
            : (r.inactive ? ' <span class="dom-pill grey">' + esc(T('links.inactive')) + '</span>' : '');
        return '<li class="dom-conn-row"><div class="dom-conn-row-main"><a class="dom-conn-row-link" href="' + esc(window.DOM_BASE + r.url) + '">' + esc(r.label) + '</a>' + extra
            + (r.sub ? '<div class="dom-sub">' + esc(r.sub) + '</div>' : '') + '</div>'
            + '<button type="button" class="dom-conn-x" data-unlink="' + kind + '" data-id="' + r.id + '" title="' + esc(T('links.remove')) + '" aria-label="' + esc(T('links.remove')) + ': ' + esc(r.label) + '">&times;</button></li>';
    }

    /**
     * Draws the Connections tab: the risk banner, the contract card and one card per kind the analyst may
     * open, and sets the tab badge (red when services are at risk with no incident).
     */
    function renderConnections() {
        const host = document.getElementById('connections');
        if (!host) return;
        if (C.error) { host.innerHTML = '<div class="dom-empty">' + esc(C.error) + '</div>'; return; }
        if (C.ready === false) { host.innerHTML = '<div class="dom-card"><div class="dom-card-b dom-sub">' + esc(T('links.not_ready')) + '</div></div>'; return; }
        const kinds = KINDS.filter(k => C.links && C.links[k.kind] !== undefined);
        let total = 0;
        const cards = kinds.map(k => {
            const rows = C.links[k.kind];
            total += rows.length;
            return '<div class="dom-card dom-conn-card" data-kind="' + k.kind + '"><div class="dom-card-h"><h3>' + connIcon(k.icon) + esc(T('links.' + k.kind + '_title'))
                + ' <span class="dom-pill grey">' + rows.length + '</span></h3></div><div class="dom-card-b">'
                + '<p class="dom-hint">' + esc(T('links.' + k.kind + '_hint')) + '</p>'
                + (rows.length ? '<ul class="dom-conn-list">' + rows.map(r => rowHtml(k.kind, r)).join('') + '</ul>' : '<div class="dom-sub dom-conn-none">' + esc(T('links.none')) + '</div>')
                + '<div class="dom-person dom-conn-search"><input type="text" data-search="' + k.kind + '" autocomplete="off" placeholder="' + esc(T('links.search_ph')) + '" aria-label="' + esc(T('links.' + k.kind + '_title')) + ': ' + esc(T('links.search_ph')) + '">'
                + '<ul class="dom-person-results" data-results="' + k.kind + '" role="listbox" hidden></ul></div>'
                + '</div></div>';
        }).join('');
        host.innerHTML = riskHtml() + '<p class="dom-hint dom-conn-intro">' + esc(T('links.intro')) + '</p>'
            + '<div class="dom-conn-grid">' + contractHtml() + (cards || '') + '</div>'
            + (kinds.length ? '' : '<div class="dom-sub">' + esc(T('links.nothing_allowed')) + '</div>');
        const badge = document.getElementById('connBadge');
        if (badge) badge.innerHTML = total ? '<span class="dom-pill grey">' + total + '</span>' : '';
        const risk = C.status && C.status.at_risk && (C.status.problems || []).length && !C.status.incident;
        if (badge && risk) badge.innerHTML = '<span class="dom-pill red">' + total + '</span>';
    }

    let connTimer = null;
    /** Searches records of one kind that can be linked to this domain and shows the results under the box. */
    async function connSearch(input) {
        const kind = input.dataset.search;
        const ul = document.querySelector('[data-results="' + kind + '"]');
        try {
            const d = await api('links.php?domain_id=' + ID + '&search=' + encodeURIComponent(kind) + '&q=' + encodeURIComponent(input.value.trim()));
            const res = d.results || [];
            ul.innerHTML = res.length
                ? res.map(r => '<li role="option" tabindex="-1" data-add="' + kind + '" data-id="' + r.id + '">' + esc(r.label) + (r.sub ? '<small>' + esc(r.sub) + '</small>' : '') + '</li>').join('')
                : '<li class="dom-sub" aria-disabled="true">' + esc(T('links.no_match')) + '</li>';
            ul.hidden = false;
        } catch (e) { showToast(e.message, 'error'); }
    }

    /**
     * Wires the Connections tab once: search as you type, link on pick, unlink, Edit for the contract,
     * Raise incident, and keyboard movement through the results.
     */
    function wireConnections() {
        const host = document.getElementById('connections');
        if (!host) return;
        host.addEventListener('input', e => {
            const inp = e.target.closest('[data-search]');
            if (!inp) return;
            clearTimeout(connTimer);
            connTimer = setTimeout(() => connSearch(inp), 220);
        });
        host.addEventListener('focusin', e => { const inp = e.target.closest('[data-search]'); if (inp) connSearch(inp); });
        host.addEventListener('focusout', e => {
            const box = e.target.closest('.dom-conn-search');
            if (box) setTimeout(() => { if (!box.contains(document.activeElement)) box.querySelector('.dom-person-results').hidden = true; }, 150);
        });
        // mousedown, not click: the input's blur would hide the list first.
        host.addEventListener('mousedown', async e => {
            const li = e.target.closest('[data-add]');
            if (!li) return;
            e.preventDefault();
            try {
                await api('links.php', { action: 'add', domain_id: ID, kind: li.dataset.add, target_id: Number(li.dataset.id) });
                showToast(T('links.linked'), 'success');
                await loadConnections();
            } catch (err) { showToast(err.message, 'error'); }
        });
        host.addEventListener('click', async e => {
            const x = e.target.closest('[data-unlink]');
            if (x) {
                try {
                    await api('links.php', { action: 'remove', domain_id: ID, kind: x.dataset.unlink, target_id: Number(x.dataset.id) });
                    showToast(T('links.unlinked'), 'success');
                    await loadConnections();
                } catch (err) { showToast(err.message, 'error'); }
                return;
            }
            if (e.target.closest('[data-goto-edit]')) { openEdit(); return; }
            if (e.target.id === 'connRaise') {
                busy(e.target, async () => {
                    await api('links.php', { action: 'raise_incident', domain_id: ID });
                    showToast(T('links.raised'), 'success');
                    await loadConnections();
                });
            }
        });
        host.addEventListener('keydown', e => {
            const li = e.target.closest('[data-add]');
            if (li && e.key === 'Enter') { e.preventDefault(); li.dispatchEvent(new MouseEvent('mousedown', { bubbles: true })); }
            if (e.key === 'Escape') { const ul = e.target.closest('.dom-conn-search')?.querySelector('.dom-person-results'); if (ul) ul.hidden = true; }
            if (e.key === 'ArrowDown' && e.target.matches('[data-search]')) {
                const first = e.target.closest('.dom-conn-search').querySelector('[data-add]');
                if (first) { e.preventDefault(); first.focus(); }
            }
        });
    }

    // ---------------------------------------------------------------- actions
    function switchTab(tab) {
        document.querySelectorAll('#domTabs .tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.toggle('active', c.id === 'tab-' + tab));
    }

    /**
     * Runs an action with its button disabled and showing "Working", then puts the button back.
     * Errors become a toast.
     */
    async function busy(btn, fn) {
        const was = btn.innerHTML; btn.disabled = true; btn.innerHTML = esc(T('page.working'));
        try { await fn(); } catch (e) { showToast(e.message, 'error'); } finally { btn.disabled = false; btn.innerHTML = was; }
    }

    /**
     * Wires the page once: tabs, Refresh, Check, Edit, Delete, the auth code links and dialog,
     * certificate and look-alike scans, and dismissing look-alikes.
     */
    function wire() {
        wireConnections();
        document.getElementById('domTabs').addEventListener('click', e => { const b = e.target.closest('.tab'); if (b) switchTab(b.dataset.tab); });
        document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => window.Dom.closeModal(b.dataset.close)));
        document.getElementById('btnRefresh').addEventListener('click', e => busy(e.currentTarget, async () => {
            const d = await api('process.php', { ids: [ID], lookup: true, check: false });
            const r = (d.results || [])[0] || {};
            if (r.lookup_ok === false) showToast(r.lookup_error, 'warning'); else showToast(T('page.refreshed'), 'success');
            await load();
        }));
        document.getElementById('btnCheck').addEventListener('click', e => busy(e.currentTarget, async () => {
            await api('process.php', { ids: [ID], lookup: false, check: true });
            showToast(T('page.checked'), 'success');
            await load();
            switchTab('security');
        }));
        document.getElementById('btnEdit').addEventListener('click', openEdit);
        document.getElementById('eSave').addEventListener('click', saveEdit);
        document.getElementById('ePurpose').addEventListener('change', () => { document.getElementById('ePurposeHint').textContent = T('purpose_hint.' + document.getElementById('ePurpose').value); });
        document.getElementById('btnDelete').addEventListener('click', async () => {
            const ok = await showConfirm({ title: T('page.delete_title'), message: T('page.delete_body', { name: D.domain_name }), okLabel: T('bulk.delete_ok'), okClass: 'danger' });
            if (!ok) return;
            try { await api('delete.php', { id: ID }); location.href = './'; } catch (e) { showToast(e.message, 'error'); }
        });
        document.addEventListener('click', async e => {
            const go = e.target.closest('[data-goto]');
            if (go) { e.preventDefault(); switchTab(go.dataset.goto); return; }
            if (e.target.id === 'codeReveal') {
                e.preventDefault();
                try { const d = await api('auth_code.php', { id: ID, action: 'reveal' }); document.getElementById('codeVal').textContent = d.code || ''; e.target.remove(); }
                catch (err) { showToast(err.message, 'error'); }
                return;
            }
            if (e.target.id === 'codeChange') { e.preventDefault(); document.getElementById('cCode').value = ''; window.Dom.openModal('mCode'); return; }
            if (e.target.id === 'ctScan') { busy(e.target, async () => { const d = await api('watch.php', { id: ID, action: 'scan_ct' }); showToast(T('cert.scanned', { n: d.result.total, fresh: d.result.new }), 'success'); await load(); }); return; }
            if (e.target.id === 'ctAck') { try { await api('watch.php', { id: ID, action: 'ack_ct' }); await load(); } catch (err) { showToast(err.message, 'error'); } return; }
            if (e.target.id === 'mcAdd') { openCert(null); return; }
            const mcE = e.target.closest('[data-mc-edit]');
            if (mcE) { e.preventDefault(); openCert((data.manual_certificates || []).find(c => c.id === +mcE.dataset.mcEdit)); return; }
            const mcD = e.target.closest('[data-mc-del]');
            if (mcD) {
                e.preventDefault();
                const c = (data.manual_certificates || []).find(x => x.id === +mcD.dataset.mcDel);
                if (!c || !(await showConfirm({ title: T('cert.m_delete'), message: T('cert.m_delete_body', { name: c.purpose }), okLabel: window.t('common.delete'), okClass: 'danger' }))) return;
                try { await api('certificates.php', { action: 'delete', id: c.id }); await load(); switchTab('certs'); } catch (err) { showToast(err.message, 'error'); }
                return;
            }
            if (e.target.id === 'laScan') { busy(e.target, async () => { const d = await api('watch.php', { id: ID, action: 'scan_lookalikes' }); showToast(T('la.scanned', { checked: d.result.checked, found: d.result.found }), 'success'); await load(); }); return; }
            const la = e.target.closest('[data-la]');
            if (la) { e.preventDefault(); try { await api('watch.php', { id: ID, action: 'dismiss', lookalike_id: la.dataset.la, dismissed: la.dataset.dismissed === '1' }); await load(); } catch (err) { showToast(err.message, 'error'); } }
        });
        document.getElementById('mcSave').addEventListener('click', saveCert);
        document.getElementById('mcSupplier').addEventListener('change', mcProviderToggle);
        document.getElementById('eParent').addEventListener('change', regOnlyToggle);
        document.getElementById('eScope').addEventListener('change', regOnlyToggle);
        document.getElementById('cSave').addEventListener('click', async () => {
            try { await api('auth_code.php', { id: ID, action: 'set', code: document.getElementById('cCode').value }); window.Dom.closeModal('mCode'); showToast(T('code.saved'), 'success'); await load(); }
            catch (e) { showToast(e.message, 'error'); }
        });
    }

    // ------------------------------------- who: tech contact + customer (#162)
    // The technical contact is a supplier contact OR one of your analysts; the
    // customer is a person OR a supplier, optionally with one of its contacts.
    function peopleLink(page, id, name) {
        const label = esc(name || ('#' + id));
        const ok = page === 'person.php' ? window.DOM_PEOPLE : (window.DOM_PEOPLE && window.DOM_PEOPLE_SUPPLIERS);
        return ok ? '<a href="' + esc(window.DOM_PEOPLE + page + '?id=' + id) + '">' + label + '</a>' : label;
    }
    /** Draws the technical contact - an analyst, or a supplier contact linked to People. */
    function techCell() {
        if (D.tech_analyst_id) return esc(D.tech_analyst_name || ('#' + D.tech_analyst_id)) + '<div class="dom-sub">' + esc(T('field.tech_is_analyst')) + '</div>';
        if (D.tech_contact_id) return peopleLink('contact.php', D.tech_contact_id, D.tech_contact_name);
        return '';
    }
    /**
     * Draws the customer - a person, a supplier with or without one of its contacts, or a contact -
     * linked to People where the analyst can open it.
     */
    function customerCell() {
        if (D.customer_user_id) {
            return peopleLink('person.php', D.customer_user_id, D.customer_user_name)
                + (D.customer_user_email && D.customer_user_email !== D.customer_user_name ? '<div class="dom-sub">' + esc(D.customer_user_email) + '</div>' : '');
        }
        if (D.customer_supplier_id && D.customer_contact_id) {
            return peopleLink('supplier.php', D.customer_supplier_id, D.customer_supplier_name)
                + '<div class="dom-sub">' + peopleLink('contact.php', D.customer_contact_id, D.customer_contact_name) + '</div>';
        }
        if (D.customer_supplier_id) return peopleLink('supplier.php', D.customer_supplier_id, D.customer_supplier_name);
        if (D.customer_contact_id) return peopleLink('contact.php', D.customer_contact_id, D.customer_contact_name);
        return '';
    }
    /** Returns the customer as plain text for the Edit dialog's search box. */
    function customerLabel() {
        if (D.customer_user_id) return D.customer_user_name || '';
        if (D.customer_contact_id) return (D.customer_contact_name || '') + (D.customer_supplier_name ? ' (' + D.customer_supplier_name + ')' : '');
        if (D.customer_supplier_id) return D.customer_supplier_name || '';
        return '';
    }

    // ---------------------------------------------------------------- edit
    let techAtOpen = '', custAtOpen = '';
    /**
     * Fills the technical contact drop-down with analysts and supplier contacts, keeping the current one
     * even when it is no longer in either list, and remembers the value it opened with.
     */
    function fillTech() {
        const sel = document.getElementById('eTech');
        /** Creates one option element. */
        const opt = (v, label) => { const o = document.createElement('option'); o.value = v; o.textContent = label; return o; };
        /** Creates an option group holding the given options. */
        const group = (label, items) => { const g = document.createElement('optgroup'); g.label = label; g.append(...items); return g; };
        sel.replaceChildren(opt('', '—'));
        if (L.parties_ready) {
            const analysts = (L.analysts || []).slice();
            // Somebody set earlier who has since left stays on the list, so the
            // dialog shows the truth; saving sends this field only if it changed.
            if (D.tech_analyst_id && !analysts.some(a => +a.id === D.tech_analyst_id)) analysts.unshift({ id: D.tech_analyst_id, name: D.tech_analyst_name || ('#' + D.tech_analyst_id) });
            sel.append(group(T('field.tech_group_analysts'), analysts.map(a => opt('a:' + a.id, a.name))));
        }
        // Contacts are listed only for analysts who can open Contracts; the one
        // already set is always kept, so opening and saving never clears it.
        const contacts = (L.contacts || []).slice();
        if (D.tech_contact_id && !contacts.some(c => +c.id === D.tech_contact_id)) contacts.unshift({ id: D.tech_contact_id, name: D.tech_contact_name || ('#' + D.tech_contact_id) });
        if (contacts.length) sel.append(group(T('field.tech_group_contacts'), contacts.map(c => opt('c:' + c.id, c.name))));
        sel.value = D.tech_analyst_id ? 'a:' + D.tech_analyst_id : (D.tech_contact_id ? 'c:' + D.tech_contact_id : '');
        techAtOpen = sel.value;
    }

    /**
     * Fills the Edit dialog from the domain - drop-downs, every data-f field, the technical contact and
     * the customer - and opens it.
     *
     * A linked contract the analyst cannot see is kept as an option, so saving does not unlink it.
     */
    function openEdit() {
        if (!L) { showToast(T('err.generic'), 'error'); return; }
        const F = window.Dom.fillSelect;
        F('ePurpose', L.purposes.map(p => ({ id: p, name: purpose(p) })));
        F('eScope', ['public', 'internal'].map(s => ({ id: s, name: T('scope.' + s) })));
        F('eStatus', L.statuses, { blank: T('status.none') });
        F('eOwner', L.analysts, { blank: T('field.no_owner') });
        F('eRegistrar', L.suppliers, { blank: '—' });
        const acc = (L.accounts || []).filter(a => a.tenant_id === D.tenant_id || (!data.multi_company));
        F('eAccount', acc, { blank: '—' });
        F('eRenewal', L.renewal_modes.map(m => ({ id: m, name: renewal(m) })));
        F('eTLock', [{ id: '1', name: T('lock.on') }, { id: '0', name: T('lock.off') }], { blank: T('lock.unknown') });
        // Subdomain of: the domains in its company it could belong to, plus the one
        // it has. Offered only where there is a choice to make.
        const parents = (data.parent_candidates || []).map(p => ({ id: p.id, name: p.name }));
        if (D.parent && !parents.some(p => p.id === D.parent.id)) parents.unshift({ id: D.parent.id, name: D.parent.domain_name });
        F('eParent', parents, { blank: T('field.parent_none') });
        document.getElementById('eParentGroup').hidden = !data.subdomains_ready || (!parents.length) || (data.subdomains || []).length > 0;
        // A linked customer contract this analyst cannot see (#153) is not in the
        // list; keep it as an option so saving the form does not unlink it.
        const contracts = (L.contracts || []).slice();
        if (D.contract_id && !contracts.some(c => +c.id === D.contract_id)) contracts.unshift({ id: D.contract_id, name: T('field.contract_hidden') });
        F('eContract', contracts, { blank: '—' });
        // GH #188: the cost centres of this domain's company (active, plus its current one).
        F('eCc', (D.cost_centre_options || []).map(c => ({ id: c.id, name: c.code + ' ' + c.name + (c.is_active ? '' : ' (' + T('field.cost_centre_inactive') + ')') })), { blank: '—' });
        const ccOld = document.getElementById('eCcOld');
        if (ccOld) { ccOld.hidden = !(D.cost_centre && !D.cost_centre_id); ccOld.textContent = D.cost_centre ? T('field.cost_centre_was', { text: D.cost_centre }) : ''; }
        fillTech();
        // Every currency, favourites first (GH #189). Filled before the data-f loop sets its value,
        // and with the domain's own code so a retired or unrecognised one is kept, not changed.
        Money.fill(document.getElementById('eCur'), D.currency || '', { blank: '—' });
        document.querySelectorAll('#editForm [data-f]').forEach(el => {
            const k = el.dataset.f;
            let v = D[k];
            if (el.dataset.bool) { el.checked = !!v; return; }
            if (el.dataset.tri) { el.value = v === true ? '1' : (v === false ? '0' : ''); return; }
            el.value = v === null || v === undefined ? '' : v;
        });
        document.getElementById('ePurposeHint').textContent = T('purpose_hint.' + D.purpose);
        regOnlyToggle();
        const ck = D.customer_user_id ? 'user:' + D.customer_user_id
                 : D.customer_contact_id ? 'contact:' + D.customer_contact_id
                 : D.customer_supplier_id ? 'supplier:' + D.customer_supplier_id : '';
        setCustomer(ck, customerLabel());
        custAtOpen = ck;
        window.Dom.openModal('mEdit');
    }

    /** A subdomain's registration is its parent's: its own registration fields are hidden while one is chosen. */
    function regOnlyToggle() {
        const sub = document.getElementById('eParent').value !== '';
        // GH #203: an internal domain has no registration and no purpose either.
        const inside = document.getElementById('eScope').value === 'internal';
        document.querySelectorAll('#editForm .reg-only').forEach(g => { g.hidden = sub || inside; });
        document.getElementById('ePurposeGroup').hidden = inside;
        document.getElementById('eScopeHint').hidden = !inside;
    }

    // --------------------------------- customer: person (#153) or supplier (#162)
    // eCustomerId holds "user:5", "supplier:3" or "contact:9" - one kind only.
    function setCustomer(key, label) {
        document.getElementById('eCustomerId').value = key || '';
        document.getElementById('eCustomer').value = label || '';
        document.getElementById('eCustomerClear').hidden = !key;
        hideCustomers();
    }
    /** Hides and empties the customer search results. */
    function hideCustomers() { const ul = document.getElementById('eCustomerResults'); ul.hidden = true; ul.replaceChildren(); }
    let custTimer = null, custSeq = 0;
    /**
     * Searches people and suppliers for the customer box after a short pause and lists them, grouped
     * when both kinds match. Typing clears the chosen customer; a reply to an older search is ignored.
     */
    function searchCustomers() {
        document.getElementById('eCustomerId').value = '';   // typing again means choosing again
        document.getElementById('eCustomerClear').hidden = true;
        clearTimeout(custTimer);
        const q = document.getElementById('eCustomer').value.trim();
        if (q.length < 2) { hideCustomers(); return; }
        custTimer = setTimeout(async () => {
            const seq = ++custSeq;
            let people = [], suppliers = [];
            try {
                const res = await api('people.php?domain_id=' + ID + '&q=' + encodeURIComponent(q));
                people = res.people || []; suppliers = res.suppliers || [];
            } catch (e) { people = []; suppliers = []; }
            if (seq !== custSeq) return;
            const ul = document.getElementById('eCustomerResults');
            if (!people.length && !suppliers.length) {
                const li = document.createElement('li'); li.textContent = T('field.customer_none'); li.setAttribute('aria-disabled', 'true');
                ul.replaceChildren(li); ul.hidden = false; return;
            }
            /** Builds one customer result that sets the customer when picked. */
            const item = (key, label, sub) => {
                const li = document.createElement('li');
                li.setAttribute('role', 'option'); li.dataset.id = key; li.dataset.label = label;
                li.append(document.createTextNode(label));
                if (sub) { const s = document.createElement('small'); s.textContent = sub; li.append(s); }
                li.addEventListener('mousedown', e => { e.preventDefault(); setCustomer(key, label); });
                return li;
            };
            /** Builds a non-selectable group heading for the customer results. */
            const heading = text => { const li = document.createElement('li'); li.className = 'dom-person-group'; li.setAttribute('role', 'presentation'); li.textContent = text; return li; };
            const out = [];
            // Headings only when both kinds are there - one list needs no label.
            if (people.length && suppliers.length) out.push(heading(T('field.customer_group_people')));
            people.forEach(p => out.push(item('user:' + p.id, p.name, p.email && p.email !== p.name ? p.email : '')));
            if (suppliers.length && people.length) out.push(heading(T('field.customer_group_suppliers')));
            suppliers.forEach(s => s.kind === 'supplier'
                ? out.push(item('supplier:' + s.id, s.name, T('field.customer_is_supplier')))
                : out.push(item('contact:' + s.id, s.name + (s.supplier ? ' (' + s.supplier + ')' : ''), s.email || '')));
            ul.replaceChildren(...out);
            ul.hidden = false;
        }, 220);
    }
    /** Arrow keys move through the customer results, Enter picks one and Escape closes the list. */
    function customerKeys(e) {
        const ul = document.getElementById('eCustomerResults');
        const items = Array.from(ul.querySelectorAll('li[role=option]'));
        if (ul.hidden || !items.length) return;
        let i = items.findIndex(li => li.getAttribute('aria-selected') === 'true');
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            i = e.key === 'ArrowDown' ? Math.min(items.length - 1, i + 1) : Math.max(0, i - 1);
            items.forEach((li, n) => li.setAttribute('aria-selected', n === i ? 'true' : 'false'));
        } else if (e.key === 'Enter' && i >= 0) {
            e.preventDefault(); setCustomer(items[i].dataset.id, items[i].dataset.label);
        } else if (e.key === 'Escape') { hideCustomers(); }
    }
    document.addEventListener('DOMContentLoaded', () => {
        const input = document.getElementById('eCustomer');
        if (!input) return;   // no edit modal for a domain that does not exist
        input.addEventListener('input', searchCustomers);
        input.addEventListener('keydown', customerKeys);
        document.getElementById('eCustomerClear').addEventListener('click', () => { setCustomer('', ''); input.focus(); });
        document.addEventListener('click', e => { if (!e.target.closest('.dom-person')) hideCustomers(); });
    });

    /**
     * Saves the Edit dialog through save.php and reloads the page.
     *
     * The technical contact and customer are sent only when changed, so one set by somebody else that this
     * analyst's lists cannot show is left alone.
     */
    async function saveEdit() {
        const body = { id: ID };
        document.querySelectorAll('#editForm [data-f]').forEach(el => {
            const k = el.dataset.f;
            body[k] = el.dataset.bool ? (el.checked ? 1 : 0) : el.value;
        });
        // Who (#162): sent only when changed, so a contact or analyst set by
        // somebody else - one this analyst's lists cannot show - is left alone.
        const tech = document.getElementById('eTech').value;
        if (tech !== techAtOpen) {
            body.tech_contact_id = tech.startsWith('c:') ? tech.slice(2) : null;
            if (L.parties_ready) body.tech_analyst_id = tech.startsWith('a:') ? tech.slice(2) : null;
        }
        const cust = document.getElementById('eCustomerId').value;
        if (cust !== custAtOpen) {
            const [kind, id] = cust ? cust.split(':') : ['', ''];
            body.customer_user_id = kind === 'user' ? id : null;
            if (L.parties_ready) {
                body.customer_supplier_id = kind === 'supplier' ? id : null;
                body.customer_contact_id = kind === 'contact' ? id : null;
            }
        }
        const btn = document.getElementById('eSave');
        btn.disabled = true;
        try {
            await api('save.php', body);
            window.Dom.closeModal('mEdit');
            showToast(T('page.saved'), 'success');
            await load();
        } catch (e) { showToast(e.message, 'error'); } finally { btn.disabled = false; }
    }
})();
