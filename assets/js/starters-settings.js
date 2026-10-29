/**
 * Starters & leavers -> Settings -> Templates (starters/settings/index.php).
 *
 * Lists the templates by kind and edits one in a dialog: its name, kind and
 * steps - each step's team or analyst, how many days from the case's date it is
 * due, and a checklist from Tasks. Saved whole through api/starters/templates.php.
 */
(function () {
    'use strict';
    const { T, esc, api, lookups, modal, stepsLabel } = window.Jml;
    const $ = id => document.getElementById(id);
    let templates = [], look = null, editing = null, m = null;

    const opts = (rows, chosen, none) => '<option value="">' + esc(none) + '</option>'
        + rows.map(r => '<option value="' + r.id + '"' + (String(r.id) === String(chosen) ? ' selected' : '') + '>' + esc(r.name || r.title) + '</option>').join('');

    function drawList() {
        const host = $('jmlTplList');
        if (!templates.length) { host.innerHTML = '<p class="jml-muted">' + esc(T('settings.none')) + '</p>'; return; }
        host.innerHTML = ['starter', 'mover', 'leaver'].map(k => {
            const mine = templates.filter(t => t.kind === k);
            if (!mine.length) return '';
            return '<div class="jml-tpl-group"><h3>' + esc(T('kinds.' + k)) + '</h3>' + mine.map(t =>
                '<div class="jml-tpl' + (t.is_active ? '' : ' off') + '" tabindex="0" data-id="' + t.id + '"><div class="grow"><b>' + esc(t.name) + '</b>'
                + (t.description ? '<div class="jml-dim">' + esc(t.description) + '</div>' : '') + '</div>'
                + '<span class="jml-dim">' + esc(stepsLabel(t.steps.length)) + '</span>'
                + (t.is_active ? '' : '<span class="jml-pill">' + esc(T('settings.inactive')) + '</span>') + '</div>').join('') + '</div>';
        }).join('');
        host.querySelectorAll('.jml-tpl').forEach(el => {
            const go = () => edit(templates.find(t => String(t.id) === el.dataset.id));
            el.onclick = go;
            el.onkeydown = e => { if (e.key === 'Enter') go(); };
        });
    }

    function stepRow(s) {
        const icon = d => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + d + '</svg>';
        return '<tr>'
            + '<td><input type="text" data-f="title" maxlength="255" autocomplete="off" data-lpignore="true" data-1p-ignore data-bwignore value="' + esc(s.title || '') + '" placeholder="' + esc(T('settings.step_title_ph')) + '"></td>'
            + '<td><select data-f="assigned_team_id">' + opts(look.teams, s.assigned_team_id, T('settings.step_none')) + '</select></td>'
            + '<td><select data-f="assigned_analyst_id">' + opts(look.analysts, s.assigned_analyst_id, T('settings.step_none')) + '</select></td>'
            + '<td><input type="number" data-f="due_offset_days" min="-365" max="365" value="' + (s.due_offset_days || 0) + '" title="' + esc(T('settings.step_days_tip')) + '"></td>'
            + '<td><select data-f="checklist_template_id">' + opts(look.checklists, s.checklist_template_id, T('settings.step_none')) + '</select></td>'
            + '<td class="ops">'
            + '<button type="button" class="jml-icon-btn" data-op="up" title="' + esc(T('settings.step_up')) + '">' + icon('<path d="M18 15l-6-6-6 6"/>') + '</button>'
            + '<button type="button" class="jml-icon-btn" data-op="down" title="' + esc(T('settings.step_down')) + '">' + icon('<path d="M6 9l6 6 6-6"/>') + '</button>'
            + '<button type="button" class="jml-icon-btn danger" data-op="remove" title="' + esc(T('settings.step_remove')) + '">' + icon('<path d="M18 6L6 18M6 6l12 12"/>') + '</button>'
            + '</td></tr>';
    }

    /** The steps as they stand in the dialog, empty rows included (the server drops them). */
    function readSteps() {
        return [...$('jmlTplSteps').querySelectorAll('tr')].map(tr => {
            const s = {};
            tr.querySelectorAll('[data-f]').forEach(el => { s[el.dataset.f] = el.value; });
            return s;
        });
    }
    function drawSteps(steps) { $('jmlTplSteps').innerHTML = steps.map(stepRow).join(''); }

    function edit(t) {
        editing = t || null;
        $('jmlTplName').value = t ? t.name : '';
        $('jmlTplKind').value = t ? t.kind : 'starter';
        $('jmlTplDesc').value = t ? (t.description || '') : '';
        $('jmlTplActive').checked = t ? t.is_active : true;
        $('jmlTplDelete').hidden = !t;
        $('jmlTplError').hidden = true;
        drawSteps(t && t.steps.length ? t.steps : [{}]);
        m.open();
        setTimeout(() => $('jmlTplName').focus(), 60);
    }

    async function save() {
        const btn = $('jmlTplSave');
        btn.disabled = true;
        try {
            await api('templates.php', { action: 'save', template: {
                id: editing ? editing.id : null, name: $('jmlTplName').value, kind: $('jmlTplKind').value,
                description: $('jmlTplDesc').value, is_active: $('jmlTplActive').checked, steps: readSteps(),
            } });
            m.close();
            window.showToast && showToast(T('settings.saved'), 'success');
            await load();
        } catch (e) {
            $('jmlTplError').textContent = e.message; $('jmlTplError').hidden = false;
        }
        btn.disabled = false;
    }

    async function remove() {
        if (!editing) return;
        const ok = window.showConfirm
            ? await showConfirm({ title: T('settings.delete_title', { name: editing.name }), message: T('settings.delete_body'), okLabel: T('settings.delete'), okClass: 'danger' })
            : confirm(T('settings.delete_title', { name: editing.name }));
        if (!ok) return;
        try {
            await api('templates.php', { action: 'delete', id: editing.id });
            m.close();
            window.showToast && showToast(T('settings.deleted'), 'success');
            await load();
        } catch (e) { $('jmlTplError').textContent = e.message; $('jmlTplError').hidden = false; }
    }

    async function load() {
        templates = (await api('templates.php')).templates;
        drawList();
    }

    document.addEventListener('DOMContentLoaded', async () => {
        if (!$('jmlTplList')) return;   // no permission: the page says so
        m = modal($('jmlTplModal'));
        try { look = await lookups(); } catch (e) { look = { teams: [], analysts: [], checklists: [] }; }
        $('jmlTplNew').onclick = () => edit(null);
        $('jmlTplSave').onclick = save;
        $('jmlTplDelete').onclick = remove;
        $('jmlTplAddStep').onclick = () => {
            const steps = readSteps(); steps.push({}); drawSteps(steps);
            const rows = $('jmlTplSteps').querySelectorAll('tr');
            rows[rows.length - 1].querySelector('input').focus();
        };
        $('jmlTplSteps').addEventListener('click', e => {
            const b = e.target.closest('[data-op]');
            if (!b) return;
            const rows = [...$('jmlTplSteps').querySelectorAll('tr')];
            const i = rows.indexOf(b.closest('tr'));
            const steps = readSteps();
            if (b.dataset.op === 'remove') steps.splice(i, 1);
            else if (b.dataset.op === 'up' && i > 0) [steps[i - 1], steps[i]] = [steps[i], steps[i - 1]];
            else if (b.dataset.op === 'down' && i < steps.length - 1) [steps[i + 1], steps[i]] = [steps[i], steps[i + 1]];
            drawSteps(steps.length ? steps : [{}]);
        });
        try { await load(); } catch (e) { $('jmlTplList').innerHTML = '<p class="jml-error">' + esc(e.message) + '</p>'; }
    });
})();
