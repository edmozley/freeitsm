/**
 * Projects - the Benefits tab (3.3.0). Rules: includes/projects/benefits.php.
 *
 * Each benefit: where it started, where it is now (the latest measurement) and
 * where it should get to, with which way is better, its owner and its next
 * review. State and progress come from the server; this file only draws.
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = (k, p) => P.T('benefits.' + k, p);
    const esc = P.esc;
    const $ = id => document.getElementById(id);
    let ctx = null;
    let wired = false;
    let open = {};          // benefit id -> its measurement history is showing
    let measuring = null;   // the benefit a measurement is being recorded for

    const call = body => P.api('tools.php', Object.assign({ project_id: ctx.projectId }, body));
    const canChange = () => !!(ctx.data.permissions && ctx.data.permissions.can_change);
    const val = (v, unit) => v === null || v === undefined ? '-' : Number(v).toLocaleString() + (unit ? ' ' + unit : '');

    function card(b) {
        const due = b.review_due;
        let html = '<li class="prj-ben st-' + esc(b.state) + '">'
            + '<div class="prj-ben-head"><strong>' + esc(b.title) + '</strong>'
            + '<span class="prj-ctl-pill cp-' + esc({ achieved: 'approved', missed: 'rejected', closed: 'withdrawn', not_measured: 'withdrawn', in_progress: 'proposed' }[b.state] || 'proposed') + '">' + esc(T('state_' + b.state)) + '</span></div>';
        if (b.measure) html += '<p class="prj-muted sm" style="margin:2px 0 8px">' + esc(b.measure) + ' - ' + esc(T(b.direction === 'down' ? 'lower_better' : 'higher_better')) + '</p>';
        // Baseline -> now -> target, with progress between baseline and target.
        html += '<div class="prj-ben-figs">'
            + '<div><span class="prj-ben-n">' + esc(val(b.baseline_value, b.unit)) + '</span><span class="prj-ben-l">' + esc(T('baseline')) + '</span></div>'
            + '<div class="now"><span class="prj-ben-n">' + esc(val(b.current, b.unit)) + '</span><span class="prj-ben-l">' + esc(T('now')) + '</span></div>'
            + '<div><span class="prj-ben-n">' + esc(val(b.target_value, b.unit)) + '</span><span class="prj-ben-l">' + esc(b.target_date ? T('target_by', { date: P.fmtDate(b.target_date) }) : T('target')) + '</span></div>'
            + '</div>';
        if (b.progress !== null) html += '<div class="prj-ben-bar" role="img" aria-label="' + esc(T('progress', { pct: b.progress })) + '"><span style="width:' + Math.min(100, b.progress) + '%"></span></div>'
            + '<div class="prj-muted sm">' + esc(T('progress', { pct: b.progress })) + '</div>';
        const meta = [];
        meta.push(b.owner_name ? T('owner', { name: b.owner_name }) : T('no_owner'));
        if (b.status === 'open') meta.push(b.review_date ? T(due ? 'review_due' : 'review_on', { date: P.fmtDate(b.review_date) }) : T('no_review'));
        if (b.review_months) meta.push(T('every', { n: b.review_months }));
        html += '<p class="prj-ben-meta' + (due ? ' due' : '') + '">' + esc(meta.join(' - ')) + '</p>';
        if (b.notes) html += '<p class="prj-ctl-desc">' + esc(b.notes) + '</p>';
        const acts = [];
        if (canChange() && b.status === 'open') acts.push('<button type="button" class="btn btn-primary prj-btn sm" data-ben-measure="' + b.id + '">' + esc(T('record')) + '</button>');
        if (canChange()) acts.push('<button type="button" class="btn btn-secondary sm" data-ben-edit="' + b.id + '">' + esc(P.TC('edit')) + '</button>');
        if (b.measures.length) acts.push('<button type="button" class="prj-link" data-ben-history="' + b.id + '">' + esc(T(open[b.id] ? 'hide_history' : 'history', { count: b.measures.length })) + '</button>');
        if (acts.length) html += '<div class="prj-ctl-acts">' + acts.join('') + '</div>';
        if (open[b.id] && b.measures.length) {
            html += '<table class="prj-budget-table prj-ben-history"><thead><tr><th>' + esc(T('col_date')) + '</th><th class="num">' + esc(T('col_value')) + '</th><th>' + esc(T('col_note')) + '</th><th></th></tr></thead><tbody>'
                + b.measures.slice().reverse().map(m => '<tr><td>' + esc(P.fmtDate(m.measured_date)) + '</td><td class="num">' + esc(val(m.value, b.unit)) + '</td><td>' + esc(m.note || '')
                    + (m.recorded_by_name ? ' <span class="prj-muted sm">(' + esc(m.recorded_by_name) + ')</span>' : '') + '</td><td class="num">'
                    + (canChange() ? '<button type="button" class="prj-task-remove" data-ben-mdel="' + b.id + ':' + m.id + '" aria-label="' + esc(P.TC('delete')) + '">&times;</button>' : '') + '</td></tr>').join('')
                + '</tbody></table>';
        }
        return html + '</li>';
    }

    function render() {
        const box = $('pvBenefits');
        if (!box) return;
        const list = ctx.data.benefits || [];
        let html = '<p class="prj-muted" style="margin-top:0">' + esc(T('intro')) + '</p>';
        html += '<div class="prj-panel"><div class="prj-panel-head"><h3>' + esc(T('title')) + '</h3>'
            + (canChange() ? '<button type="button" class="btn btn-primary prj-btn sm" data-ben-add>' + esc(T('add')) + '</button>' : '') + '</div>';
        html += list.length ? '<ul class="prj-ctl-list">' + list.map(card).join('') + '</ul>' : '<p class="prj-muted">' + esc(T('none')) + '</p>';
        box.innerHTML = html + '</div>';
    }

    // ---- Boxes ---------------------------------------------------------------------------
    function openBenefit(b) {
        $('pbnTitle').textContent = b ? b.title : T('add_title');
        $('pbnId').value = b ? b.id : '';
        $('pbnName').value = b ? b.title : '';
        $('pbnMeasure').value = b ? (b.measure || '') : '';
        $('pbnUnit').value = b ? (b.unit || '') : '';
        $('pbnDirection').value = b ? b.direction : 'up';
        $('pbnBaseline').value = b && b.baseline_value !== null ? b.baseline_value : '';
        $('pbnTarget').value = b && b.target_value !== null ? b.target_value : '';
        $('pbnTargetDate').value = b && b.target_date ? b.target_date : '';
        $('pbnReview').value = b && b.review_date ? b.review_date : '';
        $('pbnEvery').value = b && b.review_months !== null ? b.review_months : '';
        $('pbnNotes').value = b ? (b.notes || '') : '';
        $('pbnClosed').checked = !!(b && b.status === 'closed');
        $('pbnClosedWrap').hidden = !b;
        const owners = (ctx.data.members || []).filter(m => m.analyst_id).map(m => ({ id: m.analyst_id, name: m.name }));
        const pm = ctx.data.project.owner_analyst_id;
        if (pm && !owners.some(o => Number(o.id) === Number(pm))) owners.unshift({ id: pm, name: ctx.data.project.owner_name });
        if (b && b.owner_analyst_id && !owners.some(o => Number(o.id) === b.owner_analyst_id)) owners.push({ id: b.owner_analyst_id, name: b.owner_name });
        $('pbnOwner').innerHTML = '<option value="">' + esc(T('no_owner')) + '</option>'
            + owners.map(o => '<option value="' + o.id + '"' + (b ? (Number(b.owner_analyst_id) === Number(o.id) ? ' selected' : '') : (Number(o.id) === Number(pm) ? ' selected' : '')) + '>' + esc(o.name) + '</option>').join('');
        $('pbnDelete').hidden = !b;
        $('pbnError').hidden = true;
        P.openModal('prjBenefitModal');
        setTimeout(() => $('pbnName').focus(), 60);
    }

    async function saveBenefit() {
        try {
            await call({ action: 'benefit_save', id: $('pbnId').value || undefined, title: $('pbnName').value.trim(), measure: $('pbnMeasure').value, unit: $('pbnUnit').value,
                direction: $('pbnDirection').value, baseline_value: $('pbnBaseline').value, target_value: $('pbnTarget').value, target_date: $('pbnTargetDate').value,
                review_date: $('pbnReview').value, review_months: $('pbnEvery').value, owner_analyst_id: $('pbnOwner').value || null, notes: $('pbnNotes').value,
                status: $('pbnClosed').checked ? 'closed' : 'open' });
            P.closeModal('prjBenefitModal'); P.toast(T('saved')); await ctx.refresh();
        } catch (e) { $('pbnError').textContent = e.message; $('pbnError').hidden = false; }
    }

    async function deleteBenefit() {
        const ok = await window.showConfirm({ title: T('delete_title'), message: T('delete_body'), okLabel: P.TC('delete'), okClass: 'danger' });
        if (!ok) return;
        try { await call({ action: 'benefit_delete', id: $('pbnId').value }); P.closeModal('prjBenefitModal'); await ctx.refresh(); }
        catch (e) { P.toast(e.message, 'error'); }
    }

    function openMeasure(b) {
        measuring = b;
        $('pbmTitle').textContent = T('record_title', { name: b.title });
        $('pbmValueLabel').textContent = b.unit ? T('value_unit', { unit: b.unit }) : T('value');
        $('pbmValue').value = '';
        $('pbmDate').value = P.todayStr();
        $('pbmNote').value = '';
        $('pbmHint').textContent = b.review_months ? T('record_hint', { n: b.review_months }) : T('record_hint_once');
        $('pbmError').hidden = true;
        P.openModal('prjBenefitMeasureModal');
        setTimeout(() => $('pbmValue').focus(), 60);
    }

    async function saveMeasure() {
        try {
            await call({ action: 'benefit_measure_add', id: measuring.id, value: $('pbmValue').value, measured_date: $('pbmDate').value, note: $('pbmNote').value });
            P.closeModal('prjBenefitMeasureModal'); P.toast(T('recorded')); await ctx.refresh();
        } catch (e) { $('pbmError').textContent = e.message; $('pbmError').hidden = false; }
    }

    function wire() {
        if (wired) return;
        wired = true;
        const find = id => (ctx.data.benefits || []).find(b => b.id === Number(id));
        $('pvBenefits').addEventListener('click', async e => {
            if (e.target.closest('[data-ben-add]')) { openBenefit(null); return; }
            const ed = e.target.closest('[data-ben-edit]'); if (ed) { openBenefit(find(ed.dataset.benEdit)); return; }
            const me = e.target.closest('[data-ben-measure]'); if (me) { openMeasure(find(me.dataset.benMeasure)); return; }
            const hi = e.target.closest('[data-ben-history]'); if (hi) { open[hi.dataset.benHistory] = !open[hi.dataset.benHistory]; render(); return; }
            const md = e.target.closest('[data-ben-mdel]');
            if (md) {
                const [bid, mid] = md.dataset.benMdel.split(':');
                try { await call({ action: 'benefit_measure_delete', id: Number(bid), measure_id: Number(mid) }); await ctx.refresh(); } catch (er) { P.toast(er.message, 'error'); }
            }
        });
        $('pbnSave').addEventListener('click', saveBenefit);
        $('pbnDelete').addEventListener('click', deleteBenefit);
        $('pbmSave').addEventListener('click', saveMeasure);
    }

    window.PrjBenefits = {
        render(c) { ctx = c; if (!(c.data.project.tools || []).includes('benefits')) return; wire(); render(); },
    };
})();
