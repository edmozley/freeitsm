/**
 * Projects - the tools on a project's page (3.2.0 phase 2): People, Scope (the
 * MoSCoW board) and the RACI matrix.
 *
 * projects-view.js owns the page and its data; after every redraw it calls
 * PrjTools.render({data, L, projectId, refresh, page}) and this file draws the
 * three tool panels from that same data, so nothing here can disagree with the
 * banner or the plan. Every change goes to api/projects/tools.php and then asks
 * the view to refresh.
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = P.T, esc = P.esc;
    let ctx = null;          // the latest {data, L, projectId, refresh, page}
    let wired = false;
    const MOSCOW = ['must', 'should', 'could', 'wont'];

    function canChange() { return !(ctx.data.permissions && !ctx.data.permissions.can_change); }
    async function call(body) { return P.api('tools.php', Object.assign({ project_id: ctx.projectId }, body)); }

    // ---- People ---------------------------------------------------------------------
    function kindIcon(kind) { return P.icon(kind === 'team' ? 'users' : (kind === 'person' ? 'heart' : 'laptop'), 14); }

    function renderPeople() {
        const box = document.getElementById('pvPeople');
        const d = ctx.data, L = ctx.L;
        const roleOpts = (sel) => '<option value="">' + esc(T('people.no_role')) + '</option>'
            + (L.roles || []).map(r => '<option value="' + r.id + '"' + (String(r.id) === String(sel) ? ' selected' : '') + '>' + esc(r.name) + '</option>').join('');
        const pm = d.project.owner_analyst_id;
        let html = '<div class="prj-plan-head"><p class="prj-muted">' + esc(T('people.intro')) + '</p>'
            + (canChange() ? '<button type="button" class="btn btn-primary prj-btn" data-pm-add>+ ' + esc(T('people.add')) + '</button>' : '') + '</div>';
        if (!d.members.length) {
            html += '<div class="prj-plan-empty">' + esc(T('people.empty')) + '</div>';
        } else {
            html += '<div class="prj-people-grid">' + d.members.map(m =>
                '<div class="prj-person">'
                + '<span class="prj-avatar lg">' + esc(P.initials(m.name)) + '</span>'
                + '<div class="prj-person-main">'
                +   '<div class="prj-person-name">' + esc(m.name) + (m.kind === 'analyst' && String(m.analyst_id) === String(pm) ? ' <span class="prj-pm-badge">' + esc(T('people.pm_badge')) + '</span>' : '') + '</div>'
                +   '<div class="prj-person-kind">' + kindIcon(m.kind) + esc(T('people.kind_label_' + m.kind)) + (m.job_title ? ' &middot; ' + esc(m.job_title) : '') + '</div>'
                +   (canChange()
                        ? '<select class="prj-role-select" data-member-role="' + m.id + '" aria-label="' + esc(T('people.role')) + '">' + roleOpts(m.role_id) + '</select>'
                        : '<div class="prj-person-role">' + esc(m.role_name || T('people.no_role')) + '</div>')
                + '</div>'
                + (canChange() ? '<button type="button" class="prj-task-remove" data-member-remove="' + m.id + '" title="' + esc(T('people.remove')) + '" aria-label="' + esc(T('people.remove')) + '">&times;</button>' : '')
                + '</div>').join('') + '</div>';
        }
        box.innerHTML = html;
    }

    let memberKind = 'analyst';
    function openMember() {
        const L = ctx.L;
        memberKind = 'analyst';
        document.querySelectorAll('#pmKind [data-kind]').forEach(b => b.classList.toggle('active', b.dataset.kind === 'analyst'));
        document.getElementById('pmRole').innerHTML = '<option value="">' + esc(T('people.no_role')) + '</option>' + (L.roles || []).map(r => '<option value="' + r.id + '">' + esc(r.name) + '</option>').join('');
        document.getElementById('pmError').hidden = true;
        fillMemberPick();
        P.openModal('prjMemberModal');
    }
    function fillMemberPick() {
        const L = ctx.L, taken = ctx.data.members;
        const person = memberKind === 'person';
        document.getElementById('pmPickWrap').hidden = person;
        document.getElementById('pmPersonWrap').hidden = !person;
        document.getElementById('pmPersonId').value = '';
        document.getElementById('pmPerson').value = '';
        document.getElementById('pmPersonResults').hidden = true;
        if (person) return;
        const list = memberKind === 'team' ? (L.teams || []).map(t => ({ id: t.id, name: t.name })) : L.analysts.map(a => ({ id: a.id, name: a.full_name }));
        const col = memberKind === 'team' ? 'team_id' : 'analyst_id';
        document.getElementById('pmPickLabel').textContent = T('people.pick_' + memberKind);
        document.getElementById('pmPick').innerHTML = list.filter(x => !taken.some(m => String(m[col]) === String(x.id)))
            .map(x => '<option value="' + x.id + '">' + esc(x.name) + '</option>').join('');
    }
    let peopleTimer = null;
    function searchPeople() {
        clearTimeout(peopleTimer);
        const q = document.getElementById('pmPerson').value.trim();
        const list = document.getElementById('pmPersonResults');
        peopleTimer = setTimeout(async () => {
            try {
                const d = await P.api('tools.php?project_id=' + ctx.projectId + '&people=' + encodeURIComponent(q));
                const taken = ctx.data.members.map(m => String(m.user_id));
                const rows = (d.people || []).filter(p => !taken.includes(String(p.id)));
                list.innerHTML = rows.length ? rows.map(p => '<li><button type="button" data-person="' + p.id + '" data-person-name="' + esc(p.name) + '"><span class="prj-conn-label">' + esc(p.name) + '</span>'
                    + '<span class="prj-conn-sub">' + esc([p.job_title, p.email].filter(Boolean).join(' - ')) + '</span></button></li>').join('')
                    : '<li class="prj-conn-noresult">' + esc(T('links.no_results')) + '</li>';
                list.hidden = false;
            } catch (e) { P.toast(e.message, 'error'); }
        }, 180);
    }
    async function saveMember() {
        const body = { action: 'member_add', role_id: document.getElementById('pmRole').value || null };
        if (memberKind === 'person') body.user_id = document.getElementById('pmPersonId').value;
        else body[memberKind === 'team' ? 'team_id' : 'analyst_id'] = document.getElementById('pmPick').value;
        const pick = body.user_id || body.team_id || body.analyst_id;
        const err = document.getElementById('pmError');
        if (!pick) { err.textContent = T('people.choose'); err.hidden = false; return; }
        try {
            await call(body);
            P.closeModal('prjMemberModal');
            P.toast(T('people.added'));
            await ctx.refresh();
        } catch (e) { err.textContent = e.message; err.hidden = false; }
    }

    // ---- Scope / MoSCoW ----------------------------------------------------------------
    function itemCard(it) {
        const stage = it.stage_name ? '<span class="prj-item-stage">' + esc(it.stage_name) + '</span>' : '';
        return '<div class="prj-item' + (it.status === 'dropped' ? ' dropped' : '') + (it.status === 'accepted' ? ' accepted' : '') + '"' + (canChange() ? ' draggable="true"' : '') + ' data-item="' + it.id + '">'
            + '<div class="prj-item-title">' + esc(it.title) + '</div>'
            + '<div class="prj-item-meta"><span class="prj-item-status st-' + esc(it.status) + '">' + esc(T('scope.status_' + it.status)) + '</span>' + stage + '</div>'
            + '</div>';
    }

    function renderScope() {
        const box = document.getElementById('pvScope');
        const items = ctx.data.items;
        const cols = [...MOSCOW, ''];
        const counts = {}; MOSCOW.forEach(k => { counts[k] = items.filter(i => i.moscow === k).length; });
        let html = '<div class="prj-plan-head"><p class="prj-muted">' + esc(T('scope.intro')) + '</p>'
            + '<span class="prj-scope-summary">' + esc(T('scope.summary', { must: counts.must, should: counts.should, could: counts.could })) + '</span></div>';
        html += '<div class="prj-moscow">' + cols.map(k => {
            const rows = items.filter(i => (i.moscow || '') === k);
            return '<section class="prj-moscow-col mc-' + (k || 'none') + '" data-moscow="' + k + '">'
                + '<header><h4>' + esc(k ? T('scope.' + k) : T('scope.unsorted')) + ' <span class="prj-conn-count">' + rows.length + '</span></h4>'
                + (k ? '<small>' + esc(T('scope.' + k + '_hint')) + '</small>' : '') + '</header>'
                + '<div class="prj-moscow-cards" data-drop-moscow="' + k + '">' + rows.map(itemCard).join('') + '</div>'
                + (canChange() ? '<form class="prj-moscow-add" data-add-moscow="' + k + '"><input type="text" maxlength="255" placeholder="' + esc(T('scope.add_ph')) + '" aria-label="' + esc(T('scope.add')) + '"><button type="submit" class="prj-icon-btn" aria-label="' + esc(T('scope.add')) + '">+</button></form>' : '')
                + '</section>';
        }).join('') + '</div>';
        box.innerHTML = html;
    }

    function openItem(it) {
        const d = ctx.data;
        document.getElementById('piTitle').textContent = it ? T('scope.item_edit') : T('scope.item_new');
        document.getElementById('piId').value = it ? it.id : '';
        document.getElementById('piName').value = it ? it.title : '';
        document.getElementById('piMoscow').innerHTML = '<option value="">' + esc(T('scope.unsorted')) + '</option>' + MOSCOW.map(k => '<option value="' + k + '">' + esc(T('scope.' + k)) + '</option>').join('');
        document.getElementById('piMoscow').value = it ? (it.moscow || '') : '';
        document.getElementById('piStatus').innerHTML = ['proposed', 'agreed', 'in_progress', 'accepted', 'dropped'].map(s => '<option value="' + s + '">' + esc(T('scope.status_' + s)) + '</option>').join('');
        document.getElementById('piStatus').value = it ? it.status : 'proposed';
        const kind = (ctx.L.methodologies.find(m => m.key === d.project.methodology) || {}).timebox || 'phase';
        document.getElementById('piStageLabel').textContent = T('timebox.' + kind);
        document.getElementById('piStage').innerHTML = '<option value="">' + esc(T('scope.no_stage')) + '</option>' + d.stages.map(s => '<option value="' + s.id + '">' + esc(s.name) + '</option>').join('');
        document.getElementById('piStage').value = it ? (it.stage_id || '') : '';
        document.getElementById('piDesc').value = it ? (it.description || '') : '';
        document.getElementById('piAcc').value = it ? (it.acceptance_criteria || '') : '';
        document.getElementById('piDelete').hidden = !it || !canChange();
        document.getElementById('piSave').hidden = !canChange();
        document.getElementById('piError').hidden = true;
        P.openModal('prjItemModal');
    }
    async function saveItem() {
        const id = document.getElementById('piId').value;
        try {
            await call({ action: 'item_save', id: id || null, title: document.getElementById('piName').value,
                moscow: document.getElementById('piMoscow').value || null, status: document.getElementById('piStatus').value,
                stage_id: document.getElementById('piStage').value || null, description: document.getElementById('piDesc').value,
                acceptance_criteria: document.getElementById('piAcc').value });
            P.closeModal('prjItemModal');
            await ctx.refresh();
        } catch (e) { const er = document.getElementById('piError'); er.textContent = e.message; er.hidden = false; }
    }

    // ---- RACI ---------------------------------------------------------------------------
    function renderRaci() {
        const box = document.getElementById('pvRaci');
        const d = ctx.data, members = d.members, items = d.items.filter(i => i.status !== 'dropped');
        const legend = '<div class="prj-raci-legend">' + ['r', 'a', 'c', 'i'].map(k =>
            '<span><b class="prj-raci-letter l-' + k + '">' + k.toUpperCase() + '</b>' + esc(T('raci.' + k)) + ' <small>' + esc(T('raci.' + k + '_hint')) + '</small></span>').join('') + '</div>';
        if (!members.length || !items.length) {
            const key = !members.length && !items.length ? 'need_both' : (!members.length ? 'need_people' : 'need_items');
            box.innerHTML = '<p class="prj-muted">' + esc(T('raci.intro')) + '</p>' + legend + '<div class="prj-plan-empty">' + esc(T('raci.' + key)) + '</div>';
            return;
        }
        const raci = d.raci || {};
        let html = '<p class="prj-muted">' + esc(T('raci.intro')) + '</p>' + legend
            + '<div class="prj-raci-wrap"><table class="prj-raci"><thead><tr><th class="prj-raci-item">' + esc(T('raci.deliverable')) + '</th>'
            + members.map(m => '<th><span class="prj-avatar sm">' + esc(P.initials(m.name)) + '</span><span class="prj-raci-name">' + esc(m.name) + '</span>'
                + (m.role_name ? '<span class="prj-raci-role">' + esc(m.role_name) + '</span>' : '') + '</th>').join('')
            + '</tr></thead><tbody>';
        items.forEach(it => {
            const row = raci[it.id] || {};
            const letters = Object.values(row);
            const warn = !letters.includes('A') ? T('raci.no_a') : (!letters.includes('R') ? T('raci.no_r') : '');
            html += '<tr><th class="prj-raci-item"><span>' + esc(it.title) + '</span>' + (warn ? '<small class="prj-raci-warn">' + esc(warn) + '</small>' : '') + '</th>'
                + members.map(m => {
                    const l = row[m.id] || '';
                    return '<td><button type="button" class="prj-raci-cell' + (l ? ' l-' + l.toLowerCase() : '') + '" data-raci="' + it.id + ':' + m.id + '"'
                        + (canChange() ? '' : ' disabled') + ' aria-label="' + esc(it.title + ' - ' + m.name + ': ' + (l ? T('raci.' + l.toLowerCase()) : '-')) + '">' + esc(l) + '</button></td>';
                }).join('') + '</tr>';
        });
        html += '</tbody></table></div>';
        box.innerHTML = html;
    }

    async function cycleRaci(btn) {
        const [itemId, memberId] = btn.dataset.raci.split(':');
        const order = ['', 'R', 'A', 'C', 'I'];
        const now = (btn.textContent || '').trim();
        const next = order[(order.indexOf(now) + 1) % order.length];
        // Optimistic: show it at once, then take the server's row (a demoted A).
        btn.textContent = next; btn.className = 'prj-raci-cell' + (next ? ' l-' + next.toLowerCase() : '');
        try {
            const r = await call({ action: 'raci_set', item_id: itemId, member_id: memberId, letter: next });
            const before = Object.assign({}, (ctx.data.raci || {})[itemId] || {});
            ctx.data.raci = ctx.data.raci || {};
            ctx.data.raci[itemId] = r.row || {};
            if (next === 'A' && Object.entries(before).some(([mid, l]) => l === 'A' && String(mid) !== String(memberId))) P.toast(T('raci.demoted'), 'info');
            renderRaci();
        } catch (e) { P.toast(e.message, 'error'); await ctx.refresh(); }
    }

    // ---- RAID log ------------------------------------------------------------------------
    // dependency (3.3.0): something the project needs from outside it, by a date.
    const RAID_TYPES = ['risk', 'assumption', 'issue', 'dependency', 'decision', 'lesson'];
    const RAID_PLURAL = { dependency: 'dependencies' };
    const raidPlural = t => T('raid.' + (RAID_PLURAL[t] || t + 's'));
    /** A dependency or a decision still open after its date is LATE - it turns health amber. */
    const raidLate = r => r.status === 'open' && (r.type === 'dependency' || r.type === 'decision') && r.due_date && r.due_date < P.todayStr();
    let raidFilter = { type: '', closed: false, cell: null };   // cell = 'p:i' from the heat map

    function scoreClass(s) { return s >= 15 ? 'sc-high' : (s >= 8 ? 'sc-mid' : 'sc-low'); }

    function heatMap(risks) {
        const L = ctx.L;
        const open = risks.filter(r => r.status === 'open' && r.probability && r.impact);
        let html = '<div class="prj-heat-wrap"><div class="prj-heat-y">' + esc(T('raid.probability')) + '</div><div class="prj-heat">';
        for (let p = 5; p >= 1; p--) {
            html += '<div class="prj-heat-label y">' + esc((L.probability_labels || [])[p - 1] || p) + '</div>';
            for (let i = 1; i <= 5; i++) {
                const n = open.filter(r => Number(r.probability) === p && Number(r.impact) === i).length;
                const sel = raidFilter.cell === p + ':' + i;
                html += '<button type="button" class="prj-heat-cell ' + scoreClass(p * i) + (n ? ' has' : '') + (sel ? ' sel' : '') + '" data-heat="' + p + ':' + i + '"'
                    + ' title="' + esc(T('raid.score', { score: p * i })) + '">' + (n ? '<b>' + n + '</b>' : '') + '</button>';
            }
        }
        html += '<div></div>' + [1, 2, 3, 4, 5].map(i => '<div class="prj-heat-label x">' + esc((L.impact_labels || [])[i - 1] || i) + '</div>').join('');
        html += '</div></div><div class="prj-heat-x">' + esc(T('raid.impact')) + '</div>';
        return html;
    }

    function raidRow(r) {
        const score = r.score ? '<span class="prj-raid-score ' + scoreClass(Number(r.score)) + '" title="' + esc(T('raid.score', { score: r.score })) + '">' + esc(r.score) + '</span>' : '';
        const meta = [];
        if (r.owner_name) meta.push(esc(r.owner_name));
        // A decision made says who and when; one still to make, and a dependency, say by when.
        if (r.type === 'decision' && r.status === 'closed' && r.decided_date) meta.push(esc(r.decided_by ? T('raid.decided_meta', { name: r.decided_by, date: P.fmtDate(r.decided_date) }) : T('raid.decided_on_meta', { date: P.fmtDate(r.decided_date) })));
        else if (r.due_date) meta.push('<span class="' + (raidLate(r) ? 'prj-raid-late' : '') + '">' + esc(r.type === 'dependency' ? T('raid.needed_meta', { date: P.fmtDate(r.due_date) }) : (r.type === 'decision' ? T('raid.decide_meta', { date: P.fmtDate(r.due_date) }) : P.fmtDate(r.due_date))) + '</span>');
        if (r.actions && r.actions.length) meta.push(esc(T('raid.actions_meta', { done: r.actions.filter(a => a.is_closed).length, total: r.actions.length })));
        if (r.response) meta.push(esc(T('raid.resp_' + r.response)));
        if (r.ticket_number) meta.push('<a href="' + esc(window.PRJ_BASE + r.ticket_url) + '">' + esc(r.ticket_number) + '</a>');
        if (r.article_url) meta.push('<a href="' + esc(window.PRJ_BASE + r.article_url) + '">' + esc(T('raid.kb_row')) + (r.article_published === false ? ' (' + esc(T('raid.kb_draft')) + ')' : '') + '</a>');
        const escBadge = r.escalated_datetime && r.status === 'open'
            ? '<span class="prj-raid-esc" title="' + esc(r.escalation_note || '') + '">' + esc(T('raid.escalated')) + '</span>' : '';
        // In the decision log (the Decisions filter) the reason is shown, not hidden in the dialog.
        const why = raidFilter.type === 'decision' && r.rationale ? '<span class="prj-raid-why">' + esc(r.rationale) + '</span>' : '';
        return '<li class="prj-raid-row t-' + esc(r.type) + (r.status === 'closed' ? ' closed' : '') + (raidLate(r) ? ' late' : '') + '" data-raid="' + r.id + '">'
            + '<span class="prj-raid-type">' + esc(T('raid.' + r.type)) + '</span>'
            + '<div class="prj-raid-main"><span class="prj-raid-title">' + esc(r.title) + '</span>'
            + (meta.length ? '<span class="prj-raid-meta">' + meta.join(' &middot; ') + '</span>' : '') + why + '</div>'
            + escBadge + score + '</li>';
    }

    function renderRaid() {
        const box = document.getElementById('pvRaid');
        const all = ctx.data.raid || [];
        const risks = all.filter(r => r.type === 'risk');
        let rows = all.filter(r => (raidFilter.closed || r.status === 'open') && (!raidFilter.type || r.type === raidFilter.type));
        if (raidFilter.cell) {
            const [p, i] = raidFilter.cell.split(':');
            rows = rows.filter(r => r.type === 'risk' && String(r.probability) === p && String(r.impact) === i);
        }
        const counts = {}; RAID_TYPES.forEach(t => { counts[t] = all.filter(r => r.type === t && r.status === 'open').length; });
        let html = '<div class="prj-plan-head"><p class="prj-muted">' + esc(T('raid.intro')) + '</p>'
            + (canChange() ? '<button type="button" class="btn btn-primary prj-btn" data-raid-add>+ ' + esc(T('raid.add')) + '</button>' : '') + '</div>';
        html += '<div class="prj-raid-layout"><div class="prj-panel prj-heat-panel"><h3>' + esc(T('raid.heat_title')) + '</h3>'
            + '<p class="prj-muted" style="margin:-6px 0 12px">' + esc(T('raid.heat_hint')) + '</p>' + heatMap(risks)
            + (raidFilter.cell ? '<button type="button" class="prj-link" data-heat-clear style="margin-top:10px">' + esc(T('raid.heat_clear')) + '</button>' : '') + '</div>';
        html += '<div class="prj-raid-listwrap"><div class="prj-raid-filters"><div class="prj-seg">'
            + '<button type="button" data-rfilter=""' + (raidFilter.type === '' ? ' class="active"' : '') + '>' + esc(T('raid.all')) + '</button>'
            + RAID_TYPES.map(t => '<button type="button" data-rfilter="' + t + '"' + (raidFilter.type === t ? ' class="active"' : '') + '>' + esc(raidPlural(t))
                + (counts[t] ? ' <small>' + counts[t] + '</small>' : '') + '</button>').join('')
            + '</div><label class="prj-check"><input type="checkbox" data-rclosed' + (raidFilter.closed ? ' checked' : '') + '> ' + esc(T('raid.show_closed')) + '</label>'
            // 3.3.0: the whole log as a spreadsheet.
            + (all.length ? '<details class="prj-export" data-raid-export><summary title="' + esc(T('export.raid_hint')) + '">' + esc(T('export.button')) + '</summary><div class="prj-export-menu">'
                + '<a href="' + esc(window.PRJ_BASE + 'api/projects/export.php?what=raid&format=xlsx&project_id=' + ctx.data.project.id) + '">' + esc(T('export.xlsx')) + '</a>'
                + '<a href="' + esc(window.PRJ_BASE + 'api/projects/export.php?what=raid&format=csv&project_id=' + ctx.data.project.id) + '">' + esc(T('export.csv')) + '</a></div></details>' : '')
            + '</div>';
        if (raidFilter.type === 'decision') html += '<p class="prj-hint prj-raid-log-hint">' + esc(T('raid.log_hint')) + '</p>';
        html += rows.length ? '<ul class="prj-raid-list' + (raidFilter.type === 'decision' ? ' log' : '') + '">' + rows.map(raidRow).join('') + '</ul>'
            : '<div class="prj-plan-empty">' + esc(all.length ? T('raid.empty_filtered') : T('raid.empty')) + '</div>';
        html += '</div></div>';
        box.innerHTML = html;
    }

    let raidType = 'risk';
    function setRaidType(t) {
        raidType = t;
        document.querySelectorAll('#prType [data-rtype]').forEach(b => b.classList.toggle('active', b.dataset.rtype === t));
        document.querySelectorAll('#prjRaidModal [data-for]').forEach(el => { el.hidden = !el.dataset.for.split(' ').includes(t); });
        // What the date means depends on the type (3.3.0).
        document.getElementById('prDueLabel').textContent = T(t === 'dependency' ? 'raid.due_needed' : (t === 'decision' ? 'raid.due_decide' : 'raid.due'));
    }
    let raidOpen = null;   // the saved entry the dialog shows, for escalation and actions
    /** Escalation (3.3.0): the current one, or the box to escalate. A saved, open entry only. */
    function raidEscalationHtml(r) {
        if (!r || r.status !== 'open') return '';
        if (r.escalated_datetime) {
            return '<div class="prj-raid-esc-box on"><strong>' + esc(T('raid.escalated')) + '</strong> '
                + esc(T('raid.escalated_by', { name: r.escalated_by_name || T('history.someone'), date: P.fmtDate(String(r.escalated_datetime).slice(0, 10)) }))
                + '<p>' + esc(r.escalation_note || '') + '</p>'
                + (canChange() ? '<button type="button" class="btn btn-secondary sm" data-raid-deescalate>' + esc(T('raid.deescalate')) + '</button>' : '') + '</div>';
        }
        if (!canChange()) return '';
        return '<div class="prj-raid-esc-box"><label for="prEscNote">' + esc(T('raid.escalate_label')) + '</label>'
            + '<div class="prj-raid-inline"><input type="text" id="prEscNote" maxlength="500" placeholder="' + esc(T('raid.escalate_ph')) + '">'
            + '<button type="button" class="btn btn-secondary" data-raid-escalate>' + esc(T('raid.escalate')) + '</button></div>'
            + '<span class="prj-hint">' + esc(T('raid.escalate_hint')) + '</span></div>';
    }
    /** Follow-up actions (3.3.0): project tasks, each opening on the Tasks board. */
    function raidActionsHtml(r) {
        if (!r) return '';
        const rows = (r.actions || []).map(a => '<li class="' + (a.is_closed ? 'done' : '') + '"><span class="prj-task-status" style="background:' + esc(a.status_colour || '#94a3b8') + '" title="' + esc(a.status_name || '') + '"></span>'
            + '<a href="' + esc(window.PRJ_BASE + 'tasks/?task=' + a.id) + '">' + esc(a.title) + '</a>'
            + '<span class="prj-muted">' + esc([a.assignee_name, a.due_date ? P.fmtDate(a.due_date) : ''].filter(Boolean).join(' - ')) + '</span>'
            + (canChange() ? '<button type="button" class="prj-task-remove" data-raid-action-remove="' + a.id + '" title="' + esc(T('raid.action_remove')) + '" aria-label="' + esc(T('raid.action_remove')) + '">&times;</button>' : '') + '</li>').join('');
        const add = canChange() ? '<div class="prj-raid-inline"><input type="text" id="prActTitle" maxlength="255" placeholder="' + esc(T('raid.action_ph')) + '">'
            + '<select id="prActWho" aria-label="' + esc(T('plan.assignee')) + '"><option value="">' + esc(T('plan.assignee')) + '</option>' + ctx.L.analysts.map(a => '<option value="' + a.id + '">' + esc(a.full_name) + '</option>').join('') + '</select>'
            + '<input type="date" id="prActDue" aria-label="' + esc(T('plan.due')) + '">'
            + '<button type="button" class="btn btn-secondary" data-raid-action-add>' + esc(T('plan.add')) + '</button></div>' : '';
        if (!rows && !add) return '';
        return '<label>' + esc(T('raid.actions')) + '</label>' + (rows ? '<ul class="prj-raid-action-list">' + rows + '</ul>' : '<p class="prj-hint">' + esc(T('raid.actions_none')) + '</p>') + add;
    }
    function fillRaidExtras(r) {
        raidOpen = r;
        const e = document.getElementById('prEscalation'), a = document.getElementById('prActions');
        e.innerHTML = raidEscalationHtml(r); e.hidden = !e.innerHTML;
        a.innerHTML = raidActionsHtml(r); a.hidden = !a.innerHTML;
    }
    /** Re-read the entry after an escalation or an action, keeping the dialog open. */
    async function raidReopen(id) {
        await ctx.refresh();
        const r = (ctx.data.raid || []).find(x => String(x.id) === String(id));
        if (r) fillRaidExtras(r);
    }
    async function raidEscalate(btn) {
        const er = document.getElementById('prError'); er.hidden = true;
        btn.disabled = true;
        try {
            await call({ action: 'raid_escalate', id: raidOpen.id, note: document.getElementById('prEscNote').value });
            P.toast(T('raid.escalated_toast'));
            await raidReopen(raidOpen.id);
        } catch (e) { er.textContent = e.message; er.hidden = false; btn.disabled = false; }
    }
    async function raidAction(add, btn) {
        const er = document.getElementById('prError'); er.hidden = true;
        btn.disabled = true;
        try {
            if (add) await call({ action: 'raid_action_add', id: raidOpen.id, title: document.getElementById('prActTitle').value,
                assigned_analyst_id: document.getElementById('prActWho').value || null, due_date: document.getElementById('prActDue').value || null });
            else await call({ action: 'raid_action_remove', id: raidOpen.id, task_id: btn.dataset.raidActionRemove });
            await raidReopen(raidOpen.id);
            if (add) { const t = document.getElementById('prActTitle'); if (t) t.focus(); }
        } catch (e) { er.textContent = e.message; er.hidden = false; btn.disabled = false; }
    }
    function openRaid(r) {
        const L = ctx.L;
        const scale = (labels) => '<option value="">' + esc(T('raid.none')) + '</option>' + [1, 2, 3, 4, 5].map(n => '<option value="' + n + '">' + n + ' - ' + esc((labels || [])[n - 1] || '') + '</option>').join('');
        document.getElementById('prTitle').textContent = r ? T('raid.edit') : T('raid.new');
        document.getElementById('prId').value = r ? r.id : '';
        document.getElementById('prName').value = r ? r.title : '';
        document.getElementById('prDesc').value = r ? (r.description || '') : '';
        document.getElementById('prProb').innerHTML = scale(L.probability_labels);
        document.getElementById('prImpact').innerHTML = scale(L.impact_labels);
        document.getElementById('prProb').value = r && r.probability ? r.probability : '';
        document.getElementById('prImpact').value = r && r.impact ? r.impact : '';
        document.getElementById('prResp').innerHTML = '<option value="">' + esc(T('raid.none')) + '</option>' + ['avoid', 'reduce', 'transfer', 'accept', 'share'].map(k => '<option value="' + k + '">' + esc(T('raid.resp_' + k)) + '</option>').join('');
        document.getElementById('prResp').value = r ? (r.response || '') : '';
        document.getElementById('prOwner').innerHTML = '<option value="">' + esc(T('raid.nobody')) + '</option>' + L.analysts.map(a => '<option value="' + a.id + '">' + esc(a.full_name) + '</option>').join('');
        document.getElementById('prOwner').value = r ? (r.owner_analyst_id || '') : '';
        document.getElementById('prDue').value = r ? (r.due_date || '') : '';
        document.getElementById('prStatus').innerHTML = '<option value="open">' + esc(T('raid.open')) + '</option><option value="closed">' + esc(T('raid.closed')) + '</option>';
        document.getElementById('prStatus').value = r ? r.status : 'open';
        document.getElementById('prPlan').value = r ? (r.response_plan || '') : '';
        document.getElementById('prDecidedBy').value = r ? (r.decided_by || '') : '';
        document.getElementById('prDecidedDate').value = r ? (r.decided_date || '') : '';
        document.getElementById('prDecidedDate').max = P.todayStr();
        document.getElementById('prRationale').value = r ? (r.rationale || '') : '';
        // Who decided: suggest the project's people (any name can be typed).
        document.getElementById('prDecidedByList').innerHTML = (ctx.data.members || []).map(m => '<option value="' + esc(m.name) + '">').join('');
        fillRaidExtras(r);
        document.getElementById('prTicketId').value = r ? (r.ticket_id || '') : '';
        document.getElementById('prTicket').value = r && r.ticket_number ? r.ticket_number + ' - ' + (r.ticket_subject || '') : '';
        document.getElementById('prKb').innerHTML = raidKbHtml(r);
        document.getElementById('prRaise').innerHTML = raidRaiseHtml(r);
        document.getElementById('prDelete').hidden = !r || !canChange();
        document.getElementById('prSave').hidden = !canChange();
        document.getElementById('prError').hidden = true;
        setRaidType(r ? r.type : (raidFilter.type || 'risk'));
        P.openModal('prjRaidModal');
        setTimeout(() => document.getElementById('prName').focus(), 60);
    }
    function raidBody() {
        return { action: 'raid_save', id: document.getElementById('prId').value || null, type: raidType,
            title: document.getElementById('prName').value, description: document.getElementById('prDesc').value,
            probability: document.getElementById('prProb').value || null, impact: document.getElementById('prImpact').value || null,
            response: document.getElementById('prResp').value || null, response_plan: document.getElementById('prPlan').value,
            owner_analyst_id: document.getElementById('prOwner').value || null, due_date: document.getElementById('prDue').value || null,
            status: document.getElementById('prStatus').value, ticket_id: document.getElementById('prTicketId').value || null,
            decided_by: document.getElementById('prDecidedBy').value, decided_date: document.getElementById('prDecidedDate').value || null,
            rationale: document.getElementById('prRationale').value };
    }
    async function saveRaid() {
        try {
            await call(raidBody());
            P.closeModal('prjRaidModal');
            await ctx.refresh();
        } catch (e) { const er = document.getElementById('prError'); er.textContent = e.message; er.hidden = false; }
    }

    // A lesson -> a draft Knowledge article; an issue -> a new ticket (3.2.0).
    // Only on a saved entry, only with the other module, only when the project
    // can be changed - the server checks all three again.
    function raidKbHtml(r) {
        if (!r || r.type !== 'lesson') return '';
        if (r.article_url) {
            return '<a class="prj-link" href="' + esc(window.PRJ_BASE + r.article_url) + '">' + esc(T('raid.kb_open'))
                + (r.article_published === false ? ' (' + esc(T('raid.kb_draft')) + ')' : '') + '</a>';
        }
        if (!canChange() || !ctx.L.can_knowledge) return '';
        return '<button type="button" class="btn btn-secondary" data-raid-act="raid_to_knowledge">' + esc(T('raid.kb_button')) + '</button>'
            + '<span class="prj-hint">' + esc(T('raid.kb_hint')) + '</span>';
    }
    function raidRaiseHtml(r) {
        if (!r || r.type !== 'issue' || r.ticket_id || !canChange() || !ctx.L.can_tickets) return '';
        return '<button type="button" class="btn btn-secondary" data-raid-act="raid_to_ticket">' + esc(T('raid.ticket_raise')) + '</button>'
            + '<span class="prj-hint">' + esc(T('raid.ticket_raise_hint')) + '</span>';
    }
    async function raidAct(action, btn) {
        const er = document.getElementById('prError');
        er.hidden = true;
        btn.disabled = true;
        try {
            // Keep anything typed into the dialog: save it first, then act on it.
            await call(raidBody());
            const r = await call({ action: action, id: document.getElementById('prId').value });
            P.closeModal('prjRaidModal');
            await ctx.refresh();
            P.toast(action === 'raid_to_ticket' ? T('raid.ticket_done', { number: r.ticket.number }) : T('raid.kb_done'));
        } catch (e) {
            er.textContent = e.message; er.hidden = false;
        } finally {
            btn.disabled = false;
        }
    }
    let ticketTimer = null;
    function searchTicket() {
        clearTimeout(ticketTimer);
        const input = document.getElementById('prTicket'), list = document.getElementById('prTicketResults');
        document.getElementById('prTicketId').value = '';
        ticketTimer = setTimeout(async () => {
            try {
                const d = await P.api('links.php?project_id=' + ctx.projectId + '&search=ticket&q=' + encodeURIComponent(input.value.trim()));
                list.innerHTML = (d.results || []).length ? d.results.map(r => '<li><button type="button" data-ticket="' + r.id + '" data-ticket-label="' + esc(r.label + ' - ' + (r.sub || '')) + '"><span class="prj-conn-label">' + esc(r.label) + '</span><span class="prj-conn-sub">' + esc(r.sub || '') + '</span></button></li>').join('')
                    : '<li class="prj-conn-noresult">' + esc(T('links.no_results')) + '</li>';
                list.hidden = false;
            } catch (e) { /* the module may not be open to this analyst */ }
        }, 180);
    }

    // ---- Gates: business case, tolerances, stage gates ----------------------------------
    /** The red banner naming each tolerance breach - shared with the Overview. */
    function exceptionsBanner(exc) {
        if (!exc || !exc.length) return '';
        return '<div class="prj-exception"><div class="prj-exception-head">' + P.icon('flag', 18) + '<strong>' + esc(T('gates.exceptions')) + '</strong></div><ul>'
            + exc.map(x => '<li>' + esc(T('gates.exc_' + x.kind, x)) + '</li>').join('') + '</ul><p>' + esc(T('gates.exc_hint')) + '</p></div>';
    }

    function renderGates() {
        const box = document.getElementById('pvGates');
        const d = ctx.data, p = d.project, tol = d.tolerances || {};
        const kind = (ctx.L.methodologies.find(m => m.key === p.methodology) || {}).timebox || 'stage';
        let html = '<p class="prj-muted" style="margin-top:0">' + esc(T('gates.intro')) + '</p>' + exceptionsBanner(p.exceptions);
        html += '<div class="prj-gates-grid"><div class="prj-panel"><h3>' + esc(T('gates.business_case')) + '</h3><p class="prj-muted" style="margin:-6px 0 10px">' + esc(T('gates.business_case_hint')) + '</p>'
            + (canChange()
                ? '<textarea id="pgCase" class="prj-case" rows="7" placeholder="' + esc(T('gates.business_case_ph')) + '">' + esc(p.business_case || '') + '</textarea><div class="set-actions"><button type="button" class="btn btn-primary prj-btn sm" data-save-case>' + esc(P.TC('save')) + '</button></div>'
                : (p.business_case ? '<p class="prj-ov-summary">' + esc(p.business_case).replace(/\n/g, '<br>') + '</p>' : '<p class="prj-muted">' + esc(T('gates.no_case')) + '</p>'))
            + '</div>';
        html += '<div class="prj-panel"><h3>' + esc(T('gates.tolerances')) + '</h3><p class="prj-muted" style="margin:-6px 0 10px">' + esc(T('gates.tolerances_hint')) + '</p>'
            + '<div class="prj-tol"><label>' + esc(T('gates.tol_time')) + '<small>' + esc(T('gates.tol_time_hint')) + '</small></label>'
            + '<input type="number" min="0" max="365" id="pgTolTime" value="' + (tol.time ?? '') + '"' + (canChange() ? '' : ' disabled') + '></div>'
            + '<div class="prj-tol"><label>' + esc(T('gates.tol_risk')) + '<small>' + esc(T('gates.tol_risk_hint')) + '</small></label>'
            + '<input type="number" min="1" max="25" id="pgTolRisk" value="' + (tol.risk ?? '') + '"' + (canChange() ? '' : ' disabled') + '></div>'
            // Cost (3.2.0): only when the Budget tool is on - there is nothing to measure otherwise.
            + ((p.tools || []).includes('budget') ? '<div class="prj-tol"><label>' + esc(T('gates.tol_cost')) + '<small>' + esc(T('gates.tol_cost_hint')) + '</small></label>'
                + '<input type="number" min="0" max="500" id="pgTolCost" value="' + (tol.cost ?? '') + '"' + (canChange() ? '' : ' disabled') + '></div>' : '')
            + (canChange() ? '<div class="set-actions"><button type="button" class="btn btn-primary prj-btn sm" data-save-tol>' + esc(P.TC('save')) + '</button></div>' : '')
            + '</div></div>';
        html += '<div class="prj-panel" style="margin-top:16px"><h3>' + esc(T('gates.stage_gates')) + '</h3><p class="prj-muted" style="margin:-6px 0 12px">' + esc(T('gates.stage_gates_hint')) + '</p>';
        if (!d.stages.length) html += '<div class="prj-plan-empty">' + esc(T('gates.no_stages')) + '</div>';
        else html += '<ol class="prj-gate-list">' + d.stages.map(s => {
            const dec = s.gate_decision;
            const who = dec ? T('gates.decided_by', { name: (ctx.L.analysts.find(a => String(a.id) === String(s.gate_decided_by)) || {}).full_name || '-', date: window.fmtDateTime ? window.fmtDateTime(s.gate_decided_datetime) : s.gate_decided_datetime }) : '';
            return '<li class="prj-gate st-' + esc(s.status) + '">'
                + '<span class="prj-gate-dot ' + (dec ? 'g-' + esc(dec) : '') + '"></span>'
                + '<div class="prj-gate-main"><div class="prj-gate-name"><span class="prj-lane-kind">' + esc(T('timebox.' + (s.kind || kind))) + '</span> ' + esc(s.name)
                + ' <span class="prj-stage-pill sp-' + esc(s.status) + '">' + esc(T('stage_status.' + s.status)) + '</span></div>'
                + (dec ? '<div class="prj-gate-decision g-' + esc(dec) + '">' + esc(T('gates.' + dec)) + ' <small>' + esc(who) + '</small></div>' : '<div class="prj-muted">' + esc(T('gates.undecided')) + '</div>')
                + (s.status === 'active' && gateChanges().length ? '<div class="prj-gate-chip-warn">' + esc(T('gates.changes_chip', { count: gateChanges().length })) + '</div>' : '')
                + (s.gate_notes ? '<p class="prj-gate-notes">' + esc(s.gate_notes) + '</p>' : '')
                // The gate's checklist (3.3.0) - filled by projects-gatecheck.js.
                + '<div class="prj-gate-check" data-gc="' + s.id + '"></div></div>'
                + (canChange() && s.status !== 'planned' ? '<button type="button" class="btn btn-secondary sm" data-gate="' + s.id + '">' + esc(T('gates.decide')) + '</button>' : '')
                + '</li>';
        }).join('') + '</ol>';
        html += '</div>';
        box.innerHTML = html;
        if (window.PrjGateCheck) window.PrjGateCheck.fill(ctx);
    }

    /**
     * Linked changes not yet approved (3.2.0, going live safely). A WARNING, never
     * a block: the gate is the board's decision, and it may know the change is a
     * formality. ctx.data.gate_changes is null when this analyst cannot open
     * Changes - then the gate says nothing, rather than "none".
     */
    function gateChanges() {
        const list = ctx.data.gate_changes;
        return Array.isArray(list) ? list : [];
    }
    function gateChangesBox() {
        const list = gateChanges();
        if (!list.length) return '';
        return '<div class="prj-gate-warn"><div class="prj-gate-warn-head">' + P.icon('flag', 16) + '<strong>' + esc(T('gates.changes_title', { count: list.length })) + '</strong></div><ul>'
            + list.map(c => '<li><a href="' + esc(window.PRJ_BASE + c.url) + '" target="_blank" rel="noopener">' + esc(c.label) + '</a> ' + esc(c.title)
                + ' <span class="prj-gate-warn-st">' + esc(c.draft ? T('gates.change_draft') : (c.status || '')) + '</span>'
                + (c.work_start ? ' <small>' + esc(T('gates.change_starts', { date: window.fmtDateTime ? window.fmtDateTime(c.work_start) : c.work_start })) + '</small>' : '')
                + '</li>').join('')
            + '</ul><p>' + esc(T('gates.changes_hint')) + '</p></div>';
    }

    let gateDecision = null;
    function openGate(stage) {
        gateDecision = stage.gate_decision || null;
        document.getElementById('pgTitle').textContent = T('gates.title', { stage: stage.name });
        document.getElementById('pgIntro').textContent = T('gates.gate_intro');
        document.getElementById('pgStage').value = stage.id;
        document.getElementById('pgNotes').value = stage.gate_notes || '';
        document.querySelectorAll('#pgChoices [data-decision]').forEach(b => b.classList.toggle('selected', b.dataset.decision === gateDecision));
        // Only a gate still to close needs the warning; a closed stage's is history.
        const warn = document.getElementById('pgChanges');
        if (warn) { warn.innerHTML = stage.status !== 'closed' ? gateChangesBox() + (window.PrjGateCheck ? window.PrjGateCheck.openBox(stage.id) : '') : ''; warn.hidden = warn.innerHTML === ''; }
        document.getElementById('pgError').hidden = true;
        P.openModal('prjGateModal');
    }
    async function saveGate() {
        const err = document.getElementById('pgError');
        if (!gateDecision) { err.textContent = T('gates.choose'); err.hidden = false; return; }
        try {
            const r = await call({ action: 'gate_decide', stage_id: document.getElementById('pgStage').value, decision: gateDecision, notes: document.getElementById('pgNotes').value });
            P.closeModal('prjGateModal');
            await ctx.refresh();
            if (gateDecision !== 'stop' && r.closed) {
                P.celebrate(document.querySelector('#pvGates .prj-gate-list') || null);
                P.toast(r.next ? T('gates.next_started', { stage: r.next }) : T('gates.saved'));
            } else P.toast(T('gates.saved'));
        } catch (e) { err.textContent = e.message; err.hidden = false; }
    }

    // ---- Wiring (once) --------------------------------------------------------------------
    function wire() {
        if (wired) return;
        wired = true;
        const page = ctx.page;
        page.addEventListener('click', async e => {
            if (e.target.closest('[data-pm-add]')) { openMember(); return; }
            const rm = e.target.closest('[data-member-remove]');
            if (rm) {
                const ok = await window.showConfirm({ title: T('people.remove_title'), message: T('people.remove_body'), okLabel: T('people.remove'), okClass: 'danger' });
                if (!ok) return;
                try { await call({ action: 'member_remove', member_id: rm.dataset.memberRemove }); P.toast(T('people.removed')); await ctx.refresh(); } catch (err) { P.toast(err.message, 'error'); }
                return;
            }
            const card = e.target.closest('.prj-item');
            if (card) { openItem(ctx.data.items.find(i => String(i.id) === card.dataset.item)); return; }
            const cell = e.target.closest('[data-raci]');
            if (cell && !cell.disabled) { cycleRaci(cell); return; }
            if (e.target.closest('[data-raid-add]')) { openRaid(null); return; }
            const gb = e.target.closest('[data-gate]');
            if (gb) { openGate(ctx.data.stages.find(s => String(s.id) === gb.dataset.gate)); return; }
            if (e.target.closest('[data-save-case]')) {
                try { await P.api('save.php', { id: ctx.projectId, business_case: document.getElementById('pgCase').value }); P.toast(T('gates.saved')); await ctx.refresh(); } catch (err) { P.toast(err.message, 'error'); }
                return;
            }
            if (e.target.closest('[data-save-tol]')) {
                try { await call(Object.assign({ action: 'tolerances_save', time: document.getElementById('pgTolTime').value, risk: document.getElementById('pgTolRisk').value },
                    document.getElementById('pgTolCost') ? { cost: document.getElementById('pgTolCost').value } : {})); P.toast(T('gates.saved')); await ctx.refresh(); } catch (err) { P.toast(err.message, 'error'); }
                return;
            }
            const rr = e.target.closest('[data-raid]');
            if (rr && !e.target.closest('a')) { openRaid((ctx.data.raid || []).find(x => String(x.id) === rr.dataset.raid)); return; }
            const hc = e.target.closest('[data-heat]');
            if (hc) { raidFilter.cell = raidFilter.cell === hc.dataset.heat ? null : hc.dataset.heat; if (raidFilter.cell) raidFilter.type = ''; renderRaid(); return; }
            if (e.target.closest('[data-heat-clear]')) { raidFilter.cell = null; renderRaid(); return; }
            const rf = e.target.closest('[data-rfilter]');
            if (rf) { raidFilter.type = rf.dataset.rfilter; raidFilter.cell = null; renderRaid(); return; }
        });
        page.addEventListener('change', async e => {
            if (e.target.closest('[data-rclosed]')) { raidFilter.closed = e.target.checked; renderRaid(); return; }
            const sel = e.target.closest('[data-member-role]');
            if (!sel) return;
            try { await call({ action: 'member_update', member_id: sel.dataset.memberRole, role_id: sel.value || null }); await ctx.refresh(); } catch (err) { P.toast(err.message, 'error'); }
        });
        page.addEventListener('submit', async e => {
            const f = e.target.closest('[data-add-moscow]');
            if (!f) return;
            e.preventDefault();
            const input = f.querySelector('input');
            const title = input.value.trim();
            if (!title) return;
            const col = f.dataset.addMoscow;
            try {
                await call({ action: 'item_save', title: title, moscow: col || null });
                await ctx.refresh();
                const again = document.querySelector('[data-add-moscow="' + col + '"] input');
                if (again) again.focus();
            } catch (err) { P.toast(err.message, 'error'); }
        });
        // Drag a card between MoSCoW columns (desktop).
        let dragId = null;
        page.addEventListener('dragstart', e => { const c = e.target.closest('.prj-item'); if (c) { dragId = c.dataset.item; c.classList.add('dragging'); } });
        page.addEventListener('dragend', e => { const c = e.target.closest('.prj-item'); if (c) c.classList.remove('dragging'); document.querySelectorAll('.prj-moscow-col.drop-over').forEach(x => x.classList.remove('drop-over')); });
        page.addEventListener('dragover', e => {
            const col = e.target.closest('.prj-moscow-col'); if (!col || !dragId) return;
            e.preventDefault();
            document.querySelectorAll('.prj-moscow-col.drop-over').forEach(x => { if (x !== col) x.classList.remove('drop-over'); });
            col.classList.add('drop-over');
            const zone = col.querySelector('.prj-moscow-cards');
            const dragged = document.querySelector('.prj-item[data-item="' + dragId + '"]');
            const over = e.target.closest('.prj-item');
            if (dragged && zone) {
                if (over && over !== dragged) { const r = over.getBoundingClientRect(); zone.insertBefore(dragged, (e.clientY - r.top) > r.height / 2 ? over.nextSibling : over); }
                else if (!over && dragged.parentNode !== zone) zone.appendChild(dragged);
            }
        });
        page.addEventListener('drop', async e => {
            const col = e.target.closest('.prj-moscow-col'); if (!col || !dragId) return;
            e.preventDefault();
            col.classList.remove('drop-over');
            const id = dragId; dragId = null;
            const order = Array.from(col.querySelectorAll('.prj-item')).map(c => Number(c.dataset.item));
            try { await call({ action: 'item_move', id: id, moscow: col.dataset.moscow || null, order: order }); await ctx.refresh(); } catch (err) { P.toast(err.message, 'error'); await ctx.refresh(); }
        });
        // Dialogs
        document.getElementById('piSave').addEventListener('click', saveItem);
        document.getElementById('piName').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); saveItem(); } });
        document.getElementById('piDelete').addEventListener('click', async () => {
            const ok = await window.showConfirm({ title: T('scope.delete_title'), message: T('scope.delete_body'), okLabel: P.TC('delete'), okClass: 'danger' });
            if (!ok) return;
            try { await call({ action: 'item_delete', id: document.getElementById('piId').value }); P.closeModal('prjItemModal'); await ctx.refresh(); } catch (err) { P.toast(err.message, 'error'); }
        });
        document.getElementById('pmKind').addEventListener('click', e => {
            const b = e.target.closest('[data-kind]'); if (!b) return;
            memberKind = b.dataset.kind;
            document.querySelectorAll('#pmKind [data-kind]').forEach(x => x.classList.toggle('active', x === b));
            fillMemberPick();
        });
        document.getElementById('pmPerson').addEventListener('input', searchPeople);
        document.getElementById('pmPerson').addEventListener('focus', searchPeople);
        document.getElementById('pmPersonResults').addEventListener('click', e => {
            const b = e.target.closest('[data-person]'); if (!b) return;
            document.getElementById('pmPersonId').value = b.dataset.person;
            document.getElementById('pmPerson').value = b.dataset.personName;
            document.getElementById('pmPersonResults').hidden = true;
        });
        document.getElementById('pmSave').addEventListener('click', saveMember);
        document.getElementById('prType').addEventListener('click', e => { const b = e.target.closest('[data-rtype]'); if (b) setRaidType(b.dataset.rtype); });
        document.getElementById('prSave').addEventListener('click', saveRaid);
        document.getElementById('prjRaidModal').addEventListener('click', e => {
            const b = e.target.closest('[data-raid-act]'); if (b) { raidAct(b.dataset.raidAct, b); return; }
            const es = e.target.closest('[data-raid-escalate]'); if (es) { raidEscalate(es); return; }
            const de = e.target.closest('[data-raid-deescalate]');
            if (de) { de.disabled = true; call({ action: 'raid_deescalate', id: raidOpen.id }).then(() => raidReopen(raidOpen.id)).catch(err => { P.toast(err.message, 'error'); de.disabled = false; }); return; }
            const aa = e.target.closest('[data-raid-action-add]'); if (aa) { raidAction(true, aa); return; }
            const ar = e.target.closest('[data-raid-action-remove]'); if (ar) { raidAction(false, ar); }
        });
        document.getElementById('prjRaidModal').addEventListener('keydown', e => {
            if (e.key !== 'Enter') return;
            if (e.target.id === 'prActTitle') { e.preventDefault(); const b = document.querySelector('[data-raid-action-add]'); if (b) raidAction(true, b); }
            if (e.target.id === 'prEscNote') { e.preventDefault(); const b = document.querySelector('[data-raid-escalate]'); if (b) raidEscalate(b); }
        });
        document.getElementById('pgChoices').addEventListener('click', e => {
            const b = e.target.closest('[data-decision]'); if (!b) return;
            gateDecision = b.dataset.decision;
            document.querySelectorAll('#pgChoices [data-decision]').forEach(x => x.classList.toggle('selected', x === b));
        });
        document.getElementById('pgSave').addEventListener('click', saveGate);
        document.getElementById('prDelete').addEventListener('click', async () => {
            const ok = await window.showConfirm({ title: T('raid.delete_title'), message: T('raid.delete_body'), okLabel: P.TC('delete'), okClass: 'danger' });
            if (!ok) return;
            try { await call({ action: 'raid_delete', id: document.getElementById('prId').value }); P.closeModal('prjRaidModal'); await ctx.refresh(); } catch (err) { P.toast(err.message, 'error'); }
        });
        document.getElementById('prTicket').addEventListener('input', searchTicket);
        document.getElementById('prTicketResults').addEventListener('click', e => {
            const b = e.target.closest('[data-ticket]'); if (!b) return;
            document.getElementById('prTicketId').value = b.dataset.ticket;
            document.getElementById('prTicket').value = b.dataset.ticketLabel;
            document.getElementById('prTicketResults').hidden = true;
        });
    }

    window.PrjTools = {
        exceptionsBanner: exceptionsBanner,
        render(c) {
            ctx = c;
            wire();
            const tools = c.data.project.tools || [];
            if (tools.includes('people')) renderPeople();
            if (tools.includes('scope')) renderScope();
            if (tools.includes('raci')) renderRaci();
            if (tools.includes('raid')) renderRaid();
            if (tools.includes('gates')) renderGates();
        },
    };
})();
