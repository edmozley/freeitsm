/**
 * Projects - the Toolbox on the Overview (3.3.0).
 *
 * A project starts with the tools its way of running switches on (Simple: People
 * only; Agile: People, Scope, RAID; Staged: all eight - includes/projects/
 * methodologies.php). Somebody new to running projects should not meet a RACI
 * matrix and earned value on day one, so the Overview lists the tools NOT yet in
 * use - what each is for and when you would want it - with Add, and moves up the
 * ones the project itself suggests ("4 people on it - RACI says who does what").
 *
 * Adding a tool writes the project's tailoring, exactly as the Edit form's Tools
 * boxes do (save.php, field `tailoring`), so it can be taken away there again.
 * RACI needs People and Scope (its columns and rows), so adding it adds those.
 *
 * Off for the whole install with Projects -> Settings -> General -> Toolbox
 * (lookups `toolbox`); "Hide" hides it for this project in this browser.
 *
 *   PrjToolbox.render({ data, L, projectId, refresh })   from projects-view.js
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = (k, p) => P.T('toolbox.' + k, p);
    const esc = P.esc;
    const ORDER = ['people', 'scope', 'raid', 'gates', 'budget', 'raci', 'control', 'benefits'];
    const ICONS = { people: 'users', scope: 'box', raci: 'network', raid: 'shield', gates: 'flag', budget: 'database', control: 'wrench', benefits: 'heart' };
    const NEEDS = { raci: ['people', 'scope'] };
    let ctx = null;

    const hiddenKey = id => 'freeitsm.projects.toolbox.hide.' + id;
    function isHidden(id) { try { return localStorage.getItem(hiddenKey(id)) === '1'; } catch (e) { return false; } }

    /** Why the project itself suggests a tool, or null. Reads only what get.php already sent. */
    function reason(tool, d) {
        const tasks = d.tasks || [];
        const p = d.project;
        const people = new Set(tasks.map(t => t.assigned_analyst_id).filter(Boolean)).size;
        const members = (d.members || []).length;
        const overdue = tasks.filter(t => !Number(t.is_closed) && t.due_date && t.due_date < P.todayStr()).length;
        const days = p.start_date && p.target_end_date ? Math.round((Date.parse(p.target_end_date) - Date.parse(p.start_date)) / 86400000) : 0;
        switch (tool) {
            case 'people':   return people >= 2 && members === 0 ? T('why_people', { count: people }) : null;
            case 'scope':    return tasks.length >= 12 ? T('why_scope', { count: tasks.length }) : null;
            case 'raci':     return members >= 4 ? T('why_raci', { count: members }) : null;
            case 'raid':     return overdue > 0 ? T('why_raid', { count: overdue }) : null;
            case 'gates':    return (d.stages || []).length >= 3 ? T('why_gates', { count: d.stages.length }) : null;
            case 'budget':   return days >= 90 ? T('why_budget', { weeks: Math.round(days / 7) }) : null;
            case 'control':  return (d.history || []).some(h => h.field_name === 'target_end_date') ? T('why_control') : null;
            case 'benefits': return p.business_case ? T('why_benefits') : null;
        }
        return null;
    }

    function render(c) {
        ctx = c;
        const box = document.getElementById('pvToolbox');
        if (!box) return;
        const d = c.data, p = d.project;
        const can = !d.permissions || d.permissions.can_change;
        const finished = p.status === 'closed' || p.status === 'cancelled';
        const on = p.tools || [];
        const off = ORDER.filter(k => !on.includes(k));
        if (!c.L.toolbox || !can || finished || !off.length || isHidden(p.id)) { box.hidden = true; box.innerHTML = ''; return; }
        const rows = off.map(k => ({ k: k, why: reason(k, d) })).sort((a, b) => (b.why ? 1 : 0) - (a.why ? 1 : 0));
        box.hidden = false;
        box.innerHTML = '<div class="prj-panel prj-toolbox"><div class="prj-toolbox-head"><h3>' + P.icon('wrench', 16) + ' ' + esc(T('title')) + '</h3>'
            + '<button type="button" class="prj-link" data-tb-hide>' + esc(T('hide')) + '</button></div>'
            + '<p class="prj-muted">' + esc(T('intro', { count: on.length })) + ' <a href="' + esc(window.PRJ_BASE + 'projects/tutorial.php') + '">' + esc(P.T('tutorial.link')) + '</a></p>'
            + '<div class="prj-toolbox-grid">' + rows.map(r =>
                '<div class="prj-toolbox-card' + (r.why ? ' suggested' : '') + '">'
                + '<div class="prj-toolbox-name">' + P.icon(ICONS[r.k], 16) + '<strong>' + esc(P.T('tools.' + r.k)) + '</strong>'
                + (r.why ? '<span class="prj-toolbox-tag">' + esc(T('suggested')) + '</span>' : '') + '</div>'
                + '<p class="prj-toolbox-what">' + esc(P.T('tools.' + r.k + '_desc')) + '</p>'
                + '<p class="prj-toolbox-when">' + esc(r.why || T('when_' + r.k)) + '</p>'
                + '<button type="button" class="btn btn-secondary prj-btn sm" data-tb-add="' + r.k + '">' + esc(T('add')) + '</button></div>').join('')
            + '</div></div>';
    }

    async function add(tool) {
        const p = ctx.data.project;
        let tail = {};
        try { tail = JSON.parse(p.tailoring || '{}') || {}; } catch (e) { tail = {}; }
        [tool].concat(NEEDS[tool] || []).forEach(k => { tail[k] = true; });
        try {
            await P.api('save.php', { id: ctx.projectId, tailoring: tail });
            P.toast(T('added', { tool: P.T('tools.' + tool) }));
            await ctx.refresh();
        } catch (e) { P.toast(e.message, 'error'); }
    }

    document.addEventListener('click', e => {
        const a = e.target.closest('#pvToolbox [data-tb-add]');
        if (a) { a.disabled = true; add(a.dataset.tbAdd); return; }
        if (e.target.closest('#pvToolbox [data-tb-hide]') && ctx) {
            try { localStorage.setItem(hiddenKey(ctx.data.project.id), '1'); } catch (err) { /* private mode: hide for now */ }
            const box = document.getElementById('pvToolbox'); box.hidden = true; box.innerHTML = '';
            P.toast(T('hidden'));
        }
    });

    window.PrjToolbox = { render: render };
})();
