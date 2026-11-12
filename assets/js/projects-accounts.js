/**
 * Projects - costs and income by account (GH #282, slice 5): the one table, drawn the
 * same way on a project's Budget tab and on the portfolio's Charts view.
 *
 * The figures come from projectAccountBreakdown() (includes/projects/accounts.php):
 * sections by kind (expense, fixed asset, payroll, income) with subtotals, "not assigned"
 * costs, the costs' totals (equal to the Budget tab's tiles), income, and income less
 * costs. combine() adds several projects' breakdowns IN ONE CURRENCY for the portfolio -
 * accounts are matched on code, the way the install's default accounts are.
 *
 * In hours mode labour has no money: an Hours column appears whenever any row has time,
 * and money is never invented for it.
 *
 * @wiki Chart-of-Accounts-Developer-Guide
 */
(function () {
    'use strict';
    const KINDS = ['expense', 'fixed_asset', 'payroll', 'income'];

    /** An empty breakdown, for combine(). */
    function empty(cur) {
        const sections = {};
        KINDS.forEach(k => { sections[k] = { rows: [], planned: 0, actual: 0, forecast: 0, minutes: 0 }; });
        return { currency: cur, sections, unassigned: null, costs: { planned: 0, actual: 0, forecast: 0 }, income: { planned: 0, actual: 0, forecast: 0 }, net: { planned: 0, actual: 0, forecast: 0 }, projects: 0 };
    }
    const r2 = v => Math.round(v * 100) / 100;

    /**
     * Adds breakdowns of ONE currency together: rows matched by kind and account code.
     *
     * @param {Array} list  breakdowns from projectAccountBreakdown(), all in the same currency
     */
    function combine(list) {
        const out = empty(list.length ? list[0].currency : '');
        const idx = {};
        list.forEach(b => {
            out.projects++;
            KINDS.forEach(k => {
                const s = b.sections[k];
                if (!s) return;
                s.rows.forEach(r => {
                    const key = k + '|' + (r.account ? r.account.code.toLowerCase() : '-');
                    if (!idx[key]) { idx[key] = { account: r.account, planned: 0, actual: 0, forecast: 0, minutes: 0 }; out.sections[k].rows.push(idx[key]); }
                    ['planned', 'actual', 'forecast', 'minutes'].forEach(c => { idx[key][c] += r[c] || 0; });
                });
                ['planned', 'actual', 'forecast', 'minutes'].forEach(c => { out.sections[k][c] += s[c] || 0; });
            });
            if (b.unassigned) {
                out.unassigned = out.unassigned || { account: null, planned: 0, actual: 0, forecast: 0, minutes: 0 };
                ['planned', 'actual', 'forecast', 'minutes'].forEach(c => { out.unassigned[c] += b.unassigned[c] || 0; });
            }
            ['costs', 'income', 'net'].forEach(t => ['planned', 'actual', 'forecast'].forEach(c => { out[t][c] += b[t][c] || 0; }));
        });
        KINDS.forEach(k => { out.sections[k].rows.sort((a, b) => (a.account ? a.account.code : '~').localeCompare(b.account ? b.account.code : '~', undefined, { numeric: true })); });
        ['costs', 'income', 'net'].forEach(t => ['planned', 'actual', 'forecast'].forEach(c => { out[t][c] = r2(out[t][c]); }));
        return out;
    }

    /**
     * The table's HTML.
     *
     * @param {object} b  a breakdown (or a combine() of several)
     * @param {object} o  {esc, T (a key under projects.budget.*), money (value, currency), hours (minutes)}
     */
    function table(b, o) {
        const { esc, T, money, hours } = o;
        const cur = b.currency;
        const anyHours = KINDS.some(k => b.sections[k].minutes > 0) || (b.unassigned && b.unassigned.minutes > 0);
        const num = v => '<td class="num">' + esc(money(r2(v), cur)) + '</td>';
        const hrs = m => anyHours ? '<td class="num">' + (m > 0 ? esc(hours(m)) : '') + '</td>' : '';
        const row = (label, r, cls) => '<tr' + (cls ? ' class="' + cls + '"' : '') + '><td>' + label + '</td>' + num(r.planned) + num(r.actual) + num(r.forecast) + hrs(r.minutes || 0) + '</tr>';
        const acc = a => a ? esc(a.code + ' ' + a.name) : '<em>' + esc(T('not_assigned')) + '</em>';
        let h = '<div class="prj-table-wrap"><table class="prj-budget-table prj-acc-table"><thead><tr><th>' + esc(T('ba_account')) + '</th><th class="num">' + esc(T('planned')) + '</th><th class="num">'
            + esc(T('actual')) + '</th><th class="num">' + esc(T('forecast')) + '</th>' + (anyHours ? '<th class="num">' + esc(T('ba_hours')) + '</th>' : '') + '</tr></thead><tbody>';
        ['expense', 'fixed_asset', 'payroll'].forEach(k => {
            const s = b.sections[k];
            if (!s.rows.length) return;
            h += '<tr class="prj-acc-kind"><td colspan="' + (anyHours ? 5 : 4) + '">' + esc(T('kind_' + k)) + '</td></tr>';
            s.rows.forEach(r => { h += row(acc(r.account), r); });
            h += row(esc(T('ba_subtotal', { kind: T('kind_' + k) })), s, 'prj-total sub');
        });
        if (b.unassigned) h += '<tr class="prj-acc-kind"><td colspan="' + (anyHours ? 5 : 4) + '">' + esc(T('not_assigned')) + '</td></tr>' + row(acc(null), b.unassigned);
        h += row('<strong>' + esc(T('ba_costs')) + '</strong>', Object.assign({ minutes: KINDS.slice(0, 3).reduce((t, k) => t + b.sections[k].minutes, 0) + (b.unassigned ? b.unassigned.minutes : 0) }, b.costs), 'prj-total');
        const inc = b.sections.income;
        if (inc.rows.length) {
            h += '<tr class="prj-acc-kind"><td colspan="' + (anyHours ? 5 : 4) + '">' + esc(T('kind_income')) + '</td></tr>';
            inc.rows.forEach(r => { h += row(acc(r.account), r); });
            h += row('<strong>' + esc(T('ba_income')) + '</strong>', b.income, 'prj-total');
            // Income less costs - red below zero.
            h += '<tr class="prj-total"><td>' + esc(T('income_less_costs')) + '</td>' + ['planned', 'actual', 'forecast'].map(c => '<td class="num' + (b.net[c] < 0 ? ' bad' : '') + '">' + esc(money(b.net[c], cur)) + '</td>').join('') + (anyHours ? '<td></td>' : '') + '</tr>';
        }
        return h + '</tbody></table></div>';
    }

    /** True when a breakdown has anything to show. */
    const any = b => !!b && (KINDS.some(k => b.sections[k] && b.sections[k].rows.length) || !!b.unassigned);

    window.PrjAccounts = { table, combine, any };
})();
