/**
 * Projects - save this project as a template (3.2.0).
 *
 * The Template button on the project banner, shown only to analysts with the
 * Templates permission (lookups.can_manage_templates - the server checks it
 * again). The dialog picks which parts to keep; people, the company, links and
 * progress are never kept (includes/projects/templates.php explains why).
 * "Replace one of your templates" overwrites a saved template's plan with
 * this project's - how a template is edited (start a project from it, change
 * it, save it back). Built-ins cannot be replaced, only copied.
 * Starting a project FROM a template is the picker in the project form
 * (assets/js/projects.js).
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = P.T;

    document.addEventListener('DOMContentLoaded', async () => {
        const page = document.querySelector('[data-project-id]');
        const btn = document.getElementById('pvTemplate');
        if (!page || !btn) return;
        const projectId = parseInt(page.dataset.projectId, 10) || 0;
        try {
            const L = await P.lookups();
            btn.hidden = !L.can_manage_templates;
        } catch (e) { return; }

        const $ = id => document.getElementById(id);
        const esc = P.esc;
        let saved = [];
        const mode = () => (document.querySelector('input[name="tpsMode"]:checked') || {}).value || 'new';
        const projectName = () => (document.getElementById('pvName') || {}).textContent || '';
        // New: the project's name. Replace: the chosen template's name and description.
        function fillFromMode() {
            const replace = mode() === 'replace';
            $('tpsReplace').hidden = !replace;
            const t = replace ? saved.find(x => String(x.id) === $('tpsReplace').value) : null;
            $('tpsName').value = t ? t.name : projectName();
            $('tpsDesc').value = t ? (t.description || '') : '';
        }
        btn.addEventListener('click', async () => {
            document.querySelector('input[name="tpsMode"][value="new"]').checked = true;
            document.querySelectorAll('#tpsParts [data-part]').forEach(c => { c.checked = true; });
            $('tpsError').hidden = true;
            // Only your own templates can be replaced; offer the choice when there are some.
            try { saved = ((await P.api('templates.php')).templates || []).filter(t => !t.builtin); } catch (e) { saved = []; }
            $('tpsReplace').innerHTML = saved.map(t => '<option value="' + t.id + '">' + esc(t.name) + '</option>').join('');
            $('tpsModeWrap').hidden = !saved.length;
            fillFromMode();
            P.openModal('prjTemplateModal');
            setTimeout(() => { $('tpsName').focus(); $('tpsName').select(); }, 60);
        });
        $('tpsModeWrap').addEventListener('change', fillFromMode);
        $('tpsSave').addEventListener('click', async () => {
            const parts = Array.from(document.querySelectorAll('#tpsParts [data-part]')).filter(c => c.checked).map(c => c.dataset.part);
            const name = $('tpsName').value.trim();
            if (!name) { $('tpsError').textContent = T('templates.name_required'); $('tpsError').hidden = false; return; }
            const body = { action: 'save_from_project', project_id: projectId, name: name, description: $('tpsDesc').value.trim(), parts: parts };
            if (mode() === 'replace') {
                const t = saved.find(x => String(x.id) === $('tpsReplace').value);
                if (!t) return;
                const ok = await window.showConfirm({ title: T('templates.replace_title', { name: t.name }), message: T('templates.replace_body'), okLabel: T('templates.replace_ok'), okClass: 'danger' });
                if (!ok) return;
                body.id = t.id;
            }
            $('tpsSave').disabled = true;
            try {
                await P.api('templates.php', body);
                P.closeModal('prjTemplateModal');
                P.toast(body.id ? T('templates.replaced') : T('templates.saved'));
            } catch (e) {
                $('tpsError').textContent = e.message; $('tpsError').hidden = false;
            } finally {
                $('tpsSave').disabled = false;
            }
        });
    });
})();
