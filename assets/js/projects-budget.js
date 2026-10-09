/**
 * Projects - the Budget tab (3.2.0). Rules: includes/projects/budget.php.
 *
 * Planned against actual in the PROJECT's currency, with labour worked out from
 * the time logged on its tasks. Amounts in another currency (a contract) are
 * shown and never added; changing the project's currency is a relabel, and the
 * page says so before anyone confirms. Rates have dates, so a new rate never
 * re-prices the past - the panel shows the project's own rate history.
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = (k, p) => P.T('budget.' + k, p);
    const esc = P.esc;
    let ctx = null;
    let wired = false;

    function money(v, cur) {
        if (v === null || v === undefined || v === '') return '-';
        try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: cur }).format(v); }
        catch (e) { return cur + ' ' + Number(v).toFixed(2); }
    }
    function hours(min) {
        const h = (min || 0) / 60;
        return (Math.round(h * 10) / 10).toLocaleString() + ' h';
    }
    const canChange = () => !!(ctx.data.permissions && ctx.data.permissions.can_change);
    const call = body => P.api('tools.php', Object.assign({ project_id: ctx.projectId }, body));

    function render() {
        const box = document.getElementById('pvBudget');
        const b = ctx.data.budget;
        if (!box) return;
        if (!b) { box.innerHTML = '<div class="prj-plan-empty">' + esc(T('not_ready')) + '</div>'; return; }
        const cur = b.currency;
        const used = b.planned > 0 ? Math.round(b.actual * 100 / b.planned) : null;
        const cls = used === null ? '' : used > 100 ? 'bad' : used >= 90 ? 'warn' : '';
        const tiles = [
            { n: money(b.planned, cur), l: T('planned') },
            { n: money(b.actual, cur), l: T('actual') },
            // 3.3.0: what it is now expected to cost in the end.
            { n: money(b.forecast, cur), l: T('forecast'), cls: b.planned > 0 && b.forecast > b.planned ? 'bad' : '',
              sub: b.planned > 0 && b.forecast !== b.planned ? T(b.forecast > b.planned ? 'forecast_over' : 'forecast_under', { amount: money(Math.abs(b.forecast - b.planned), cur) }) : '' },
            { n: money(b.remaining, cur), l: T('remaining'), cls: b.remaining < 0 ? 'bad' : '' },
            { n: used === null ? '-' : used + '%', l: T('used'), cls: cls },
        ];
        let html = '<p class="prj-muted" style="margin-top:0">' + esc(T('intro')) + '</p>';
        html += '<div class="prj-ov-tiles prj-budget-tiles">' + tiles.map(t => '<div class="prj-tile ' + (t.cls || '') + '"><span class="prj-tile-num">' + esc(t.n) + '</span><span class="prj-tile-label">' + esc(t.l) + '</span>' + (t.sub ? '<span class="prj-tile-sub">' + esc(t.sub) + '</span>' : '') + '</div>').join('') + '</div>';
        if (b.cost_basis === 'forecast') html += '<p class="prj-muted sm" style="margin:-6px 0 10px">' + esc(T('basis_forecast')) + '</p>';
        if (used !== null) html += '<div class="prj-budget-bar ' + cls + '"><span style="width:' + Math.min(100, used) + '%"></span></div>';
        // Where the money goes (3.3.0): planned against actual, by category - drawn below once on the page.
        // Earned value (3.3.0) - drawn by drawEarned() once the tab shows.
        html += '<div class="prj-panel prj-budget-chart" id="pbEarned" hidden><h3>' + esc(T('ev_title')) + '</h3><div class="prj-ev-tiles"></div><p class="prj-muted sm prj-ev-note"></p><div class="prj-budget-chart-body"></div></div>';
        html += '<div class="prj-panel prj-budget-chart" id="pbSpend" hidden><h3>' + esc(T('spend_title')) + '</h3><p class="prj-muted sm prj-spend-note"></p><div class="prj-budget-chart-body"></div></div>';
        html += '<div class="prj-panel prj-budget-chart" id="pbChart" hidden><h3>' + esc(T('chart_title')) + '</h3><div class="prj-budget-chart-body"></div></div>';

        // Currency
        html += '<div class="prj-budget-cur">' + esc(T('currency_line', { currency: cur }))
            + (b.per_project_currency && canChange() ? ' <button type="button" class="btn btn-secondary sm" data-budget-currency>' + esc(T('change_currency')) + '</button>' : '') + '</div>';

        // Lines
        html += '<div class="prj-panel"><div class="prj-panel-head"><h3>' + esc(T('lines')) + '</h3>'
            + (canChange() ? '<button type="button" class="btn btn-primary prj-btn sm" data-budget-add>' + esc(T('add')) + '</button>' : '') + '</div>';
        if (!b.lines.length) html += '<p class="prj-muted">' + esc(T('no_lines')) + '</p>';
        else {
            html += '<div class="prj-table-wrap"><table class="prj-budget-table"><thead><tr><th>' + esc(T('col_line')) + '</th><th>' + esc(T('col_category')) + '</th>'
                + '<th class="num">' + esc(T('planned')) + '</th><th class="num">' + esc(T('actual')) + '</th><th class="num">' + esc(T('forecast')) + '</th><th class="num" title="' + esc(T('variance_hint')) + '">' + esc(T('col_variance')) + '</th></tr></thead><tbody>';
            html += b.lines.map(l => {
                const sub = [];
                if (l.contract) sub.push(T('from_contract', { name: (l.contract.number ? l.contract.number + ' ' : '') + l.contract.title }));
                if (l.cost_centre) sub.push(l.cost_centre.code + ' ' + l.cost_centre.name);
                if (l.notes) sub.push(l.notes);
                // 3.3.0: when, and what it is now expected to cost (planned less forecast: negative = dearer than planned).
                if (l.planned_date) sub.push(T('planned_for', { date: P.fmtDate(l.planned_date) }));
                if (l.spent_date) sub.push(T('spent_on', { date: P.fmtDate(l.spent_date) }));
                const variance = (l.planned !== null && l.forecast !== null) ? l.planned - l.forecast : null;
                const act = l.currency_mismatch
                    ? '<span class="prj-budget-warn" title="' + esc(T('mismatch_hint', { currency: l.contract.currency })) + '">' + esc(money(l.contract.value, l.contract.currency)) + ' *</span>'
                    : esc(money(l.actual, cur)) + (l.actual_source === 'contract' ? ' <small class="prj-muted">' + esc(T('contract_value')) + '</small>' : '');
                return '<tr' + (canChange() ? ' class="click" data-budget-line="' + l.id + '"' : '') + '><td><strong>' + esc(l.title) + '</strong>' + (sub.length ? '<div class="prj-muted sm">' + esc(sub.join(' - ')) + '</div>' : '') + '</td>'
                    + '<td>' + esc(T('cat_' + l.category)) + '</td><td class="num">' + esc(money(l.planned, cur)) + '</td><td class="num">' + act + '</td>'
                    + '<td class="num">' + esc(money(l.forecast, cur)) + (l.forecast_typed !== null ? '' : ' <small class="prj-muted" title="' + esc(T(l.covers_labour ? 'forecast_labour_hint' : 'forecast_auto_hint')) + '">*</small>') + '</td>'
                    + '<td class="num' + (variance !== null && variance < 0 ? ' bad' : '') + '">' + esc(variance === null ? '-' : money(variance, cur)) + '</td></tr>';
            }).join('');
            html += '</tbody></table></div>';
            if (b.lines.some(l => l.currency_mismatch)) html += '<p class="prj-muted sm">* ' + esc(T('mismatch_note')) + '</p>';
            if (b.lines.some(l => l.forecast_typed === null && l.forecast !== null)) html += '<p class="prj-muted sm">* ' + esc(T('forecast_note')) + '</p>';
        }
        html += '</div>';

        // Labour
        const lab = b.labour;
        html += '<div class="prj-panel" style="margin-top:16px"><h3>' + esc(T('labour')) + '</h3>';
        html += '<p>' + esc(T('labour_logged', { hours: hours(lab.minutes) })) + (lab.cost !== null ? ' - <strong>' + esc(money(lab.cost, cur)) + '</strong>' : '') + '</p>';
        html += '<p class="prj-muted">' + esc(T('labour_mode_' + b.labour_mode)) + '</p>';
        if (lab.unpriced_minutes > 0 && b.labour_mode !== 'hours') html += '<p class="prj-budget-warn">' + esc(T('unpriced', { hours: hours(lab.unpriced_minutes), currency: cur })) + '</p>';
        // 3.3.0: what the open tasks' estimates say is still to come.
        const tc = b.labour_to_come;
        if (tc && (tc.hours > 0 || tc.open_unestimated > 0)) {
            html += '<p>' + esc(T('to_come', { hours: hours(tc.hours * 60) })) + (tc.cost !== null && tc.cost > 0 ? ' - <strong>' + esc(money(tc.cost, cur)) + '</strong>' : '')
                + (tc.counted ? '' : ' <span class="prj-muted">(' + esc(T('to_come_not_counted')) + ')</span>') + '</p>';
            if (tc.open_unestimated > 0) html += '<p class="prj-muted sm">' + esc(T('to_come_unestimated', { count: tc.open_unestimated })) + '</p>';
            if (tc.unpriced_hours > 0 && b.labour_mode !== 'hours') html += '<p class="prj-budget-warn">' + esc(T('to_come_unpriced', { hours: hours(tc.unpriced_hours * 60) })) + '</p>';
        }
        if (b.labour_mode === 'rate') {
            html += '<h4>' + esc(T('project_rate')) + '</h4><p class="prj-muted sm">' + esc(T('project_rate_hint', { currency: cur })) + '</p>';
            if (b.project_rates.length) html += '<ul class="prj-budget-rates">' + b.project_rates.map(r => '<li>' + esc(T('rate_from', { rate: money(r.rate, cur), date: P.fmtDate(r.from) }))
                + (canChange() ? ' <button type="button" class="prj-task-remove" data-rate-delete="' + esc(r.from) + '" aria-label="' + esc(P.TC('delete')) + '">&times;</button>' : '') + '</li>').join('') + '</ul>';
            else html += '<p class="prj-muted sm">' + esc(b.default_rate_now !== null && cur === b.install_currency ? T('uses_default', { rate: money(b.default_rate_now, cur) }) : T('no_rate')) + '</p>';
            if (canChange()) html += '<div class="prj-budget-rate-add"><input type="number" min="0" step="0.01" id="pbRate" placeholder="' + esc(T('rate_ph')) + '">'
                + '<input type="date" id="pbRateFrom" value="' + esc(P.todayStr()) + '"><button type="button" class="btn btn-secondary sm" data-rate-add>' + esc(T('add_rate')) + '</button></div>';
        }
        html += '</div>';
        box.innerHTML = html;
        drawChart(b, cur);
        drawSpend(b, cur);
        drawEarned(b, cur);
    }

    /**
     * Earned value (3.3.0): planned value (orange - the plan, as on Spend over time),
     * actual cost (blue - what was spent, likewise) and earned value (aqua - the
     * work done, valued at its budget), with today's indices in words as well as numbers.
     */
    function drawEarned(b, cur) {
        const wrap = document.getElementById('pbEarned');
        const tabPanel = document.getElementById('pvBudget');
        const e = b.earned;
        if (!wrap || !window.PrjCharts || !tabPanel || tabPanel.hidden) return;
        wrap.hidden = !e;
        if (!e) return;
        const word = (v, good, bad) => v === null ? T('ev_unknown') : (v >= 1 ? good : bad);
        const tiles = [
            { n: e.cpi === null ? '-' : e.cpi.toFixed(2), l: T('ev_cpi'), sub: word(e.cpi, T('ev_cpi_good'), T('ev_cpi_bad')), cls: e.cpi !== null && e.cpi < 1 ? 'bad' : '' },
            { n: e.spi === null ? '-' : e.spi.toFixed(2), l: T('ev_spi'), sub: word(e.spi, T('ev_spi_good'), T('ev_spi_bad')), cls: e.spi !== null && e.spi < 1 ? 'warn' : '' },
            { n: money(e.ev, cur), l: T('ev_ev'), sub: T('ev_of', { bac: money(e.bac, cur) }) },
            { n: e.eac === null ? '-' : money(e.eac, cur), l: T('ev_eac'), sub: e.vac === null ? '' : T(e.vac < 0 ? 'ev_vac_over' : 'ev_vac_under', { amount: money(Math.abs(e.vac), cur) }), cls: e.vac !== null && e.vac < 0 ? 'bad' : '' },
        ];
        wrap.querySelector('.prj-ev-tiles').innerHTML = '<div class="prj-ov-tiles">' + tiles.map(t => '<div class="prj-tile ' + (t.cls || '') + '"><span class="prj-tile-num">' + esc(t.n) + '</span><span class="prj-tile-label">' + esc(t.l) + '</span>'
            + (t.sub ? '<span class="prj-tile-sub">' + esc(t.sub) + '</span>' : '') + '</div>').join('') + '</div>';
        wrap.querySelector('.prj-ev-note').textContent = T(e.measure === 'hours' ? 'ev_note_hours' : 'ev_note_tasks');
        let tick;
        try { const nf = new Intl.NumberFormat(undefined, { style: 'currency', currency: cur, notation: 'compact', maximumFractionDigits: 1 }); tick = v => nf.format(v); }
        catch (er) { tick = v => Math.round(v).toLocaleString(); }
        window.PrjCharts.lines(wrap.querySelector('.prj-budget-chart-body'), {
            points: e.points.map(p => ({ d: p.d, values: [p.pv, p.ev, p.d <= b.timeline.today ? p.ac : null] })),
            series: [{ name: T('ev_pv'), slot: 2 }, { name: T('ev_ev'), slot: 3 }, { name: T('ev_ac'), slot: 1 }],
            refs: [{ v: e.bac, label: T('ev_bac') }], today: b.timeline.today, step: true,
            fmt: v => money(v, cur), fmtTick: tick, fmtDate: P.fmtDate,
            labels: { table: T('chart_table'), chart: T('chart_chart'), date: T('col_date'), today: T('today'),
                aria: T('ev_aria', { pv: money(e.pv, cur), ev: money(e.ev, cur), ac: money(e.ac, cur) }) },
        });
    }

    /** Spend over time (3.3.0): cumulative planned and actual, the forecast, and what to measure them against. */
    function drawSpend(b, cur) {
        const wrap = document.getElementById('pbSpend');
        const tabPanel = document.getElementById('pvBudget');
        if (!wrap || !window.PrjCharts || !tabPanel || tabPanel.hidden || !b.timeline) return;
        const tl = b.timeline;
        const any = tl.points.some(p => p.planned > 0 || (p.actual || 0) > 0);
        wrap.hidden = !any;
        if (!any) return;
        const refs = [];
        if (b.planned > 0) refs.push({ v: b.planned, label: T('ref_budget') });
        // The latest baseline's budget, when change control is on and it differs from the budget now.
        const bl = ctx.data.control && ctx.data.control.baselines && ctx.data.control.baselines[0];
        if (bl && bl.plan.budget_planned !== null && Math.abs(bl.plan.budget_planned - b.planned) >= 0.01) refs.push({ v: bl.plan.budget_planned, label: T('ref_baseline', { n: bl.number }) });
        const tol = ctx.data.tolerances && ctx.data.tolerances.cost;
        if (tol !== null && tol !== undefined && b.planned > 0) refs.push({ v: Math.round(b.planned * (1 + tol / 100) * 100) / 100, label: T('ref_tolerance', { pct: tol }) });
        let tick;
        try { const nf = new Intl.NumberFormat(undefined, { style: 'currency', currency: cur, notation: 'compact', maximumFractionDigits: 1 }); tick = v => nf.format(v); }
        catch (e) { tick = v => Math.round(v).toLocaleString(); }
        const note = wrap.querySelector('.prj-spend-note');
        note.textContent = T('spend_intro') + (tl.undated ? ' ' + T('spend_undated', { count: tl.undated }) : '');
        window.PrjCharts.spend(wrap.querySelector('.prj-budget-chart-body'), {
            points: tl.points, forecast: tl.forecast, refs: refs, today: tl.today, target: tl.target,
            fmt: v => money(v, cur), fmtTick: tick, fmtDate: P.fmtDate,
            series: { actual: T('actual'), planned: T('planned'), forecast: T('forecast') },
            labels: { table: T('chart_table'), chart: T('chart_chart'), date: T('col_date'), today: T('today'), target: T('target'), at_finish: T('at_finish'),
                aria: T('spend_aria', { planned: money(b.planned, cur), actual: money(b.actual, cur), forecast: money(b.forecast, cur) }) },
        });
    }

    /** Planned against actual per category; labour's actual is the costed time (3.3.0). */
    function drawChart(b, cur) {
        const wrap = document.getElementById('pbChart');
        // It measures its width, so it draws once the Budget TAB is showing (shown()). Not wrap.offsetParent:
        // the chart box itself starts hidden until there is something to draw.
        const tabPanel = document.getElementById("pvBudget");
        if (!wrap || !window.PrjCharts || !tabPanel || tabPanel.hidden) return;
        const by = {};
        b.categories.forEach(c => { by[c] = { a: 0, b: 0, any: false }; });
        b.lines.forEach(l => {
            const r = by[l.category]; if (!r) return;
            if (l.planned !== null) { r.a += l.planned; r.any = true; }
            if (l.actual !== null && !l.currency_mismatch) { r.b += l.actual; r.any = true; }
        });
        if (b.labour && b.labour.cost !== null && b.labour.cost > 0 && by.labour) { by.labour.b += b.labour.cost; by.labour.any = true; }
        const rows = b.categories.filter(c => by[c].any).map(c => ({ label: T('cat_' + c), a: by[c].a || null, b: by[c].b || null }));
        wrap.hidden = rows.length === 0;
        if (!rows.length) return;
        window.PrjCharts.bars(wrap.querySelector('.prj-budget-chart-body'), {
            rows: rows, series: [T('planned'), T('actual')], fmt: v => money(v, cur),
            labels: { table: T('chart_table'), chart: T('chart_chart'), category: T('col_category'), aria: T('chart_aria', { planned: money(b.planned, cur), actual: money(b.actual, cur) }) },
        });
    }

    function openLine(line) {
        const b = ctx.data.budget;
        document.getElementById('pbTitle').textContent = line ? T('edit_line') : T('new_line');
        document.getElementById('pbId').value = line ? line.id : '';
        document.getElementById('pbName').value = line ? line.title : '';
        document.getElementById('pbCategory').innerHTML = b.categories.map(c => '<option value="' + c + '"' + (line && line.category === c ? ' selected' : '') + '>' + esc(T('cat_' + c)) + '</option>').join('');
        document.getElementById('pbPlanned').value = line && line.planned !== null ? line.planned : '';
        document.getElementById('pbActual').value = line && line.actual_typed !== null ? line.actual_typed : '';
        document.getElementById('pbPlannedDate').value = line && line.planned_date ? line.planned_date : '';
        document.getElementById('pbSpentDate').value = line && line.spent_date ? line.spent_date : '';
        document.getElementById('pbForecast').value = line && line.forecast_typed !== null ? line.forecast_typed : '';
        const cwrap = document.getElementById('pbContractWrap');
        cwrap.hidden = b.contracts === null;
        if (b.contracts !== null) document.getElementById('pbContract').innerHTML = '<option value="">' + esc(T('none')) + '</option>'
            + b.contracts.map(c => '<option value="' + c.id + '"' + (line && line.contract && line.contract.id === c.id ? ' selected' : '') + '>' + esc((c.number ? c.number + ' ' : '') + c.title + (c.value !== null ? ' (' + money(c.value, c.currency || b.currency) + ')' : '')) + '</option>').join('');
        const ccs = b.cost_centres.slice();
        if (line && line.cost_centre && !ccs.some(c => c.id === line.cost_centre.id)) ccs.push(line.cost_centre);   // keep a switched-off one
        document.getElementById('pbCostCentre').innerHTML = '<option value="">' + esc(T('none')) + '</option>'
            + ccs.map(c => '<option value="' + c.id + '"' + (line && line.cost_centre && line.cost_centre.id === c.id ? ' selected' : '') + '>' + esc(c.code + ' ' + c.name) + '</option>').join('');
        document.getElementById('pbNotes').value = line ? (line.notes || '') : '';
        document.getElementById('pbDelete').hidden = !line;
        document.getElementById('pbError').hidden = true;
        P.openModal('prjBudgetModal');
    }

    async function saveLine() {
        const err = document.getElementById('pbError');
        const body = { action: 'budget_line_save', id: document.getElementById('pbId').value || undefined,
            title: document.getElementById('pbName').value.trim(), category: document.getElementById('pbCategory').value,
            planned: document.getElementById('pbPlanned').value, actual: document.getElementById('pbActual').value,
            planned_date: document.getElementById('pbPlannedDate').value, spent_date: document.getElementById('pbSpentDate').value, forecast: document.getElementById('pbForecast').value,
            cost_centre_id: document.getElementById('pbCostCentre').value || null, notes: document.getElementById('pbNotes').value.trim() };
        if (!document.getElementById('pbContractWrap').hidden) body.contract_id = document.getElementById('pbContract').value || null;
        try { await call(body); P.closeModal('prjBudgetModal'); P.toast(T('saved')); await ctx.refresh(); }
        catch (e) { err.textContent = e.message; err.hidden = false; }
    }

    async function deleteLine() {
        const ok = await window.showConfirm({ title: T('delete_title'), message: T('delete_body'), okLabel: P.TC('delete'), okClass: 'danger' });
        if (!ok) return;
        try { await call({ action: 'budget_line_delete', id: document.getElementById('pbId').value }); P.closeModal('prjBudgetModal'); await ctx.refresh(); }
        catch (e) { P.toast(e.message, 'error'); }
    }

    async function changeCurrency() {
        const b = ctx.data.budget;
        const code = (window.prompt(T('currency_prompt', { currency: b.currency }), b.currency) || '').trim().toUpperCase();
        if (!code || code === b.currency) return;
        const ok = await window.showConfirm({ title: T('relabel_title', { currency: code }), message: T('relabel_body', { from: b.currency, to: code }), okLabel: T('relabel_ok'), okClass: 'danger' });
        if (!ok) return;
        try { await call({ action: 'budget_currency', currency: code }); await ctx.refresh(); } catch (e) { P.toast(e.message, 'error'); }
    }

    function wire() {
        if (wired) return;
        wired = true;
        document.getElementById('pvBudget').addEventListener('click', async e => {
            if (e.target.closest('[data-budget-add]')) { openLine(null); return; }
            if (e.target.closest('[data-budget-currency]')) { changeCurrency(); return; }
            const row = e.target.closest('[data-budget-line]');
            if (row) { openLine(ctx.data.budget.lines.find(l => String(l.id) === row.dataset.budgetLine)); return; }
            if (e.target.closest('[data-rate-add]')) {
                try { await call({ action: 'budget_rate_add', rate: document.getElementById('pbRate').value, from: document.getElementById('pbRateFrom').value }); P.toast(T('saved')); await ctx.refresh(); }
                catch (err) { P.toast(err.message, 'error'); }
                return;
            }
            const rd = e.target.closest('[data-rate-delete]');
            if (rd) { try { await call({ action: 'budget_rate_delete', from: rd.dataset.rateDelete }); await ctx.refresh(); } catch (err) { P.toast(err.message, 'error'); } }
        });
        document.getElementById('pbSave').addEventListener('click', saveLine);
        document.getElementById('pbDelete').addEventListener('click', deleteLine);
    }

    window.PrjBudget = {
        render(c) {
            ctx = c;
            if (!(c.data.project.tools || []).includes('budget')) return;
            wire();
            render();
        },
        /** The tab was shown: the chart measures its width, so it draws now (3.3.0). */
        shown() {
            if (ctx && ctx.data.budget) { drawChart(ctx.data.budget, ctx.data.budget.currency); drawSpend(ctx.data.budget, ctx.data.budget.currency); drawEarned(ctx.data.budget, ctx.data.budget.currency); }
        },
    };
})();
