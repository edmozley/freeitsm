/**
 * Projects - a proposal and its approval, on the Overview (3.3.0).
 * Rules: includes/projects/intake.php. Who may decide comes from the server
 * (proposal.can_decide); the server checks again.
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = (k, p) => P.T('intake.' + k, p);
    const esc = P.esc;
    const $ = id => document.getElementById(id);
    let ctx = null;
    let wired = false;
    let deciding = null;

    const call = body => P.api('tools.php', Object.assign({ project_id: ctx.projectId }, body));
    function money(v) {
        if (v === null || v === undefined) return '-';
        const cur = (ctx.data.budget && ctx.data.budget.currency) || 'GBP';
        try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: cur }).format(v); }
        catch (e) { return cur + ' ' + Number(v).toFixed(2); }
    }

    function render() {
        const box = $('pvProposal');
        const pr = ctx.data.proposal;
        if (!box) return;
        box.hidden = !pr;
        if (!pr) return;
        const p = ctx.data.project;
        const canChange = !!(ctx.data.permissions && ctx.data.permissions.can_change);
        const st = pr.status || 'none';
        let html = '<div class="prj-panel prj-proposal st-' + esc(st) + '"><div class="prj-panel-head"><h3>' + esc(T('title')) + '</h3>'
            + (pr.status ? '<span class="prj-ctl-pill cp-' + esc(st === 'pending' ? 'proposed' : st) + '">' + esc(T('status_' + st)) + '</span>' : '') + '</div>';
        const who = [];
        if (pr.proposed_by) who.push(T('proposed_by', { name: pr.proposed_by }));
        if (pr.form_title) who.push(T('on_form', { form: pr.form_title }));
        who.push(P.fmtDate(String(p.created_datetime || '').slice(0, 10)));
        html += '<p class="prj-muted sm" style="margin-top:0">' + esc(who.join(' - ')) + '</p>';
        html += '<dl class="prj-proposal-facts">'
            + '<dt>' + esc(T('cost')) + '</dt><dd>' + esc(pr.estimated_cost !== null ? money(pr.estimated_cost) : T('not_given')) + '</dd>'
            + '<dt>' + esc(T('benefit')) + '</dt><dd>' + (pr.estimated_benefit ? esc(pr.estimated_benefit).replace(/\n/g, '<br>') : '<span class="prj-muted">' + esc(T('not_given')) + '</span>') + '</dd>'
            + '<dt>' + esc(T('business_case')) + '</dt><dd>' + (p.business_case ? esc(p.business_case).replace(/\n/g, '<br>') : '<span class="prj-muted">' + esc(T('not_given')) + '</span>') + '</dd>'
            + '</dl>';
        if (pr.status === 'approved' || pr.status === 'rejected') {
            html += '<p class="prj-proposal-decision">' + esc(T('decided_' + pr.status, { name: pr.decided_by_name || T('someone'), date: P.fmtDate(String(pr.decided_datetime || '').slice(0, 10)) }))
                + (pr.notes ? ': ' + esc(pr.notes) : '') + '</p>';
        } else if (pr.status === 'pending') {
            html += '<p class="prj-muted sm">' + esc(pr.can_decide ? T('you_decide') : T('waiting_for')) + '</p>';
        }
        const acts = [];
        if (pr.can_decide) {
            acts.push('<button type="button" class="btn btn-primary prj-btn sm" data-pp-decide="approved">' + esc(T('approve')) + '</button>');
            acts.push('<button type="button" class="btn btn-secondary sm" data-pp-decide="rejected">' + esc(T('reject')) + '</button>');
        }
        if (canChange && pr.status !== 'rejected') acts.push('<button type="button" class="btn btn-secondary sm" data-pp-edit>' + esc(P.TC('edit')) + '</button>');
        if (acts.length) html += '<div class="prj-ctl-acts">' + acts.join('') + '</div>';
        box.innerHTML = html + '</div>';
    }

    function openDecide(decision) {
        deciding = decision;
        $('ppTitle').textContent = T(decision === 'approved' ? 'approve_title' : 'reject_title', { name: ctx.data.project.name });
        $('ppIntro').textContent = decision === 'approved' ? T('approve_effect_' + (ctx.data.proposal.on_approve || 'proposed')) : T('reject_effect');
        $('ppNotesLabel').textContent = T(decision === 'approved' ? 'notes' : 'notes_required');
        $('ppNotes').value = '';
        $('ppSave').textContent = T(decision === 'approved' ? 'approve' : 'reject');
        $('ppError').hidden = true;
        P.openModal('prjProposalModal');
    }

    async function decide() {
        try {
            await call({ action: 'proposal_decide', decision: deciding, notes: $('ppNotes').value });
            P.closeModal('prjProposalModal'); P.toast(T(deciding)); await ctx.refresh();
        } catch (e) { $('ppError').textContent = e.message; $('ppError').hidden = false; }
    }

    function openEdit() {
        const pr = ctx.data.proposal, p = ctx.data.project;
        $('peCase').value = p.business_case || '';
        $('peCost').value = pr.estimated_cost !== null ? pr.estimated_cost : '';
        $('peBenefit').value = pr.estimated_benefit || '';
        $('peCostLabel').textContent = T('cost_field', { currency: (ctx.data.budget && ctx.data.budget.currency) || 'GBP' });
        $('peError').hidden = true;
        P.openModal('prjProposalEditModal');
    }

    async function saveEdit() {
        try {
            await P.api('save.php', { id: ctx.projectId, business_case: $('peCase').value, estimated_cost: $('peCost').value.trim(), estimated_benefit: $('peBenefit').value });
            P.closeModal('prjProposalEditModal'); P.toast(T('saved')); await ctx.refresh();
        } catch (e) { $('peError').textContent = e.message; $('peError').hidden = false; }
    }

    function wire() {
        if (wired) return;
        wired = true;
        // The Overview is redrawn on every refresh, so listen on the page, not the box.
        document.addEventListener('click', e => {
            const d = e.target.closest('[data-pp-decide]');
            if (d) { openDecide(d.dataset.ppDecide); return; }
            if (e.target.closest('[data-pp-edit]')) openEdit();
        });
        $('ppSave').addEventListener('click', decide);
        $('peSave').addEventListener('click', saveEdit);
    }

    window.PrjIntake = {
        render(c) { ctx = c; wire(); render(); },
    };
})();
