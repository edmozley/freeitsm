/**
 * Projects - save this project as a template (3.2.0).
 *
 * The Template button on the project banner, shown only to analysts with the
 * Templates permission (lookups.can_manage_templates - the server checks it
 * again). The dialog picks which parts to keep; people, the company, links and
 * progress are never kept (includes/projects/templates.php explains why).
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
        btn.addEventListener('click', () => {
            const name = (document.getElementById('pvName') || {}).textContent || '';
            $('tpsName').value = name;
            $('tpsDesc').value = '';
            document.querySelectorAll('#tpsParts [data-part]').forEach(c => { c.checked = true; });
            $('tpsError').hidden = true;
            P.openModal('prjTemplateModal');
            setTimeout(() => { $('tpsName').focus(); $('tpsName').select(); }, 60);
        });
        $('tpsSave').addEventListener('click', async () => {
            const parts = Array.from(document.querySelectorAll('#tpsParts [data-part]')).filter(c => c.checked).map(c => c.dataset.part);
            const name = $('tpsName').value.trim();
            if (!name) { $('tpsError').textContent = T('templates.name_required'); $('tpsError').hidden = false; return; }
            $('tpsSave').disabled = true;
            try {
                await P.api('templates.php', { action: 'save_from_project', project_id: projectId, name: name, description: $('tpsDesc').value.trim(), parts: parts });
                P.closeModal('prjTemplateModal');
                P.toast(T('templates.saved'));
            } catch (e) {
                $('tpsError').textContent = e.message; $('tpsError').hidden = false;
            } finally {
                $('tpsSave').disabled = false;
            }
        });
    });
})();
