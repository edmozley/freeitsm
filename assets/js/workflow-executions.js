/**
 * Workflows — execution log.
 *
 * Filters are held in the URL, so a filtered view is shareable, bookmarkable and
 * deep-linkable — which is what lets the Watchtower card and the status tallies
 * link straight to "the failures from today" rather than dropping you at an
 * unfiltered list and leaving you to find them.
 */
(() => {
    const API = '../api/workflow/';

    /** Escapes text for safe use in HTML. */
    const esc = (s) => {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };

    const FIELDS = {
        fWorkflow: 'workflow_id',
        fStatus:   'status',
        fTrigger:  'trigger',
        fDry:      'dry_run',
        fFrom:     'from',
        fTo:       'to',
        fQ:        'q',
    };

    let page = 1;
    let facetsLoaded = false;

    /** started_datetime is a server-stamped UTC instant → show in the analyst's zone. */
    function fmtWhen(s) {
        if (!s) return '';
        try { return window.fmtDateTime(s); }
        catch (e) { return s; }
    }

    /** How long the run took. Both timestamps are UTC, so the difference is safe. */
    function fmtTook(a, b) {
        if (!a || !b) return '';
        try {
            const ms = window.parseUTCDate(b) - window.parseUTCDate(a);
            if (isNaN(ms) || ms < 0) return '';
            return ms < 1000 ? ms + ' ms' : (ms / 1000).toFixed(1) + ' s';
        } catch (e) { return ''; }
    }

    /** Returns the coloured status badge for a run, plus the dry-run pill when it was one. */
    function statusPill(status, dry) {
        const map = {
            success: '<span class="status-badge status-active">' + esc(window.t('workflow.status.success')) + '</span>',
            failed:  '<span class="status-badge status-inactive">' + esc(window.t('workflow.status.failed')) + '</span>',
            skipped: '<span class="status-badge" style="background:#fef3c7;color:#92400e;">' + esc(window.t('workflow.status.skipped')) + '</span>',
            aborted: '<span class="status-badge" style="background:#fee2e2;color:#991b1b;">' + esc(window.t('workflow.status.aborted')) + '</span>',
            running: '<span class="status-badge" style="background:#e0e7ff;color:#3730a3;">' + esc(window.t('workflow.status.running')) + '</span>',
        };
        return (map[status] || esc(status))
            + (dry ? '<span class="wf-dry-pill">' + esc(window.t('workflow.dry_run.pill')) + '</span>' : '');
    }

    // ---- URL <-> filter controls ------------------------------------------

    /** Copies the filter values and page number from the URL query string into the filter controls. */
    function readUrlIntoControls() {
        const p = new URLSearchParams(location.search);
        Object.entries(FIELDS).forEach(([elId, param]) => {
            const el = document.getElementById(elId);
            if (el && p.has(param)) el.value = p.get(param);
        });
        page = Math.max(1, parseInt(p.get('page') || '1', 10));
    }

    /** Builds the query string from the filled filter controls and the current page. */
    function currentParams() {
        const p = new URLSearchParams();
        Object.entries(FIELDS).forEach(([elId, param]) => {
            const v = (document.getElementById(elId)?.value || '').trim();
            if (v !== '') p.set(param, v);
        });
        if (page > 1) p.set('page', String(page));
        return p;
    }

    /** Replaces the page URL with the current filters, so the view can be bookmarked or shared. */
    function pushUrl() {
        const qs = currentParams().toString();
        history.replaceState({}, '', qs ? ('?' + qs) : location.pathname);
    }

    // ---- Load --------------------------------------------------------------

    /**
     * Fetches the filtered page of runs from executions.php and draws the rows, the status tallies and the pager (filling the drop-downs on the first load).
     */
    async function load() {
        const tb = document.getElementById('wfxRows');
        pushUrl();
        try {
            const r = await fetch(API + 'executions.php?' + currentParams().toString(), { credentials: 'same-origin' });
            const d = await r.json();
            if (!d.success) {
                tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--danger-text,#c33);">' + esc(d.error) + '</td></tr>';
                return;
            }

            if (!facetsLoaded) { fillFacets(d.facets); facetsLoaded = true; }
            renderTally(d.tally);

            if (!d.executions.length) {
                tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--text-faint,#999);">'
                             + esc(window.t('workflow.executions.none')) + '</td></tr>';
                document.getElementById('wfxPager').innerHTML = '';
                return;
            }

            tb.innerHTML = d.executions.map(e => `
                <tr>
                    <td>${statusPill(e.status, e.is_dry_run)}</td>
                    <td>
                        ${e.workflow_id && !e.deleted
                            ? '<a href="editor.php?id=' + e.workflow_id + '" style="color:var(--text,#333);font-weight:600;">' + esc(e.workflow) + '</a>'
                            : '<span style="color:var(--text-dim,#888);">' + esc(e.workflow) + '</span>'}
                        ${e.error ? '<div style="font-size:12px;color:var(--danger-text,#c33);margin-top:3px;">' + esc(e.error) + '</div>' : ''}
                    </td>
                    <td><code style="font-size:12px;">${esc(e.trigger)}</code></td>
                    <td style="white-space:nowrap;color:var(--text-muted,#666);">${esc(fmtWhen(e.started))}</td>
                    <td style="white-space:nowrap;color:var(--text-dim,#888);">${esc(fmtTook(e.started, e.finished))}</td>
                    <td><button class="table-action-btn" onclick="WFX.open(${e.id})">${esc(window.t('workflow.executions.view'))}</button></td>
                </tr>
            `).join('');

            renderPager(d);
        } catch (e) {
            tb.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--danger-text,#c33);">Load failed</td></tr>';
        }
    }

    /**
     * Fills the workflow and trigger drop-downs from the log's facets, then re-applies the URL's filters now the options exist.
     */
    function fillFacets(f) {
        const wf = document.getElementById('fWorkflow');
        f.workflows.forEach(w => {
            const o = document.createElement('option');
            o.value = w.id; o.textContent = w.name;
            wf.appendChild(o);
        });
        const tg = document.getElementById('fTrigger');
        f.triggers.forEach(t => {
            const o = document.createElement('option');
            o.value = t.event; o.textContent = t.event + ' (' + t.count + ')';
            tg.appendChild(o);
        });
        // Re-apply whatever the URL asked for, now that the options exist.
        readUrlIntoControls();
    }

    /** Status counts across the whole log — and one-click filters into it. */
    function renderTally(tally) {
        const host = document.getElementById('wfxTally');
        const order = ['failed', 'aborted', 'skipped', 'success', 'running'];
        const cur = document.getElementById('fStatus').value;
        host.innerHTML = order.filter(s => tally[s]).map(s => `
            <button class="wfx-tally-item ${s} ${cur === s ? 'active' : ''}" onclick="WFX.filterStatus('${s}')">
                <span class="wfx-tally-n">${tally[s]}</span>
                <span class="wfx-tally-l">${esc(window.t('workflow.status.' + s).split(' (')[0])}</span>
            </button>
        `).join('');
    }

    /** Draws the pager - just a count on a single page, otherwise previous/next with the range shown. */
    function renderPager(d) {
        const host = document.getElementById('wfxPager');
        if (d.pages <= 1) {
            host.innerHTML = '<span class="wfx-pager-info">' + esc(window.t('workflow.executions.showing')
                .replace('%n', d.total).replace('%t', d.total)) + '</span>';
            return;
        }
        const from = (d.page - 1) * d.per_page + 1;
        const to   = Math.min(d.total, d.page * d.per_page);
        host.innerHTML =
            '<button class="btn btn-secondary" ' + (d.page <= 1 ? 'disabled' : '') + ' onclick="WFX.go(' + (d.page - 1) + ')">‹</button>'
          + '<span class="wfx-pager-info">' + esc(window.t('workflow.executions.range')
                .replace('%a', from).replace('%b', to).replace('%t', d.total)) + '</span>'
          + '<button class="btn btn-secondary" ' + (d.page >= d.pages ? 'disabled' : '') + ' onclick="WFX.go(' + (d.page + 1) + ')">›</button>';
    }

    // ---- Run detail --------------------------------------------------------

    /** Opens the run detail modal and loads one run from execution_detail.php. */
    async function open(id) {
        const body = document.getElementById('wfxModalBody');
        document.getElementById('wfxModalTitle').textContent = window.t('workflow.executions.run') + ' #' + id;
        body.innerHTML = '<em>' + esc(window.t('common.loading')) + '</em>';
        document.getElementById('wfxModal').classList.add('active');
        try {
            const r = await fetch(API + 'execution_detail.php?id=' + id, { credentials: 'same-origin' });
            const d = await r.json();
            if (!d.success) { body.innerHTML = '<div class="wf-diagnosis">' + esc(d.error) + '</div>'; return; }
            body.innerHTML = renderDetail(d);
        } catch (e) {
            body.innerHTML = '<div class="wf-diagnosis">Load failed</div>';
        }
    }

    /**
     * Returns the run detail HTML - header, error, each step, the webhook deliveries it queued, and the payload snapshot.
     */
    function renderDetail(d) {
        const e = d.execution;
        let h = '';

        h += '<div class="wfx-detail-head">'
           + statusPill(e.status, e.is_dry_run)
           + '<span>' + esc(e.workflow) + '</span>'
           + '<code>' + esc(e.trigger) + '</code>'
           + '<span style="color:var(--text-dim,#888);">' + esc(fmtWhen(e.started)) + '</span>'
           + '</div>';

        if (e.error) h += '<div class="wf-diagnosis"><strong>' + esc(window.t('workflow.executions.error')) + '</strong><div class="wf-diagnosis-body">' + esc(e.error) + '</div></div>';

        // Steps — conditions first, then actions, in the order the engine ran them.
        h += '<h4 class="wfx-h">' + esc(window.t('workflow.executions.steps')) + '</h4>';
        if (!e.step_log.length) {
            h += '<p style="color:var(--text-faint,#999);font-size:13px;">' + esc(window.t('workflow.executions.no_steps')) + '</p>';
        } else {
            h += '<div class="wfx-steps">' + e.step_log.map(s => renderStep(s)).join('') + '</div>';
        }

        // Webhook deliveries this run queued — bridges the two logs.
        if (d.deliveries.length) {
            h += '<h4 class="wfx-h">' + esc(window.t('workflow.executions.deliveries')) + '</h4>';
            h += '<div class="wfx-steps">' + d.deliveries.map(x => `
                <div class="wfx-step">
                    <div class="wfx-step-head"><strong>#${x.id}</strong> ${esc(x.preset || 'custom')}
                        <span class="wfx-step-status ${x.status === 'delivered' ? 'ok' : (x.status === 'dead' ? 'bad' : '')}">${esc(x.status)}</span>
                        ${x.last_status ? '<span style="color:var(--text-dim,#888);">HTTP ' + x.last_status + '</span>' : ''}
                    </div>
                    ${x.last_error ? '<div class="wfx-step-err">' + esc(x.last_error) + '</div>' : ''}
                </div>`).join('') + '</div>';
            h += '<p style="font-size:12px;margin-top:6px;"><a href="../system/webhooks/">'
               + esc(window.t('workflow.executions.open_webhooks')) + ' &rarr;</a></p>';
        }

        // The payload snapshot: what the conditions were tested against and what
        // the {{variables}} resolved from. The single most useful thing here.
        h += '<h4 class="wfx-h">' + esc(window.t('workflow.executions.payload')) + '</h4>';
        h += '<p style="font-size:12px;color:var(--text-muted,#666);margin:0 0 6px;">'
           + esc(window.t('workflow.executions.payload_hint')) + '</p>';
        h += '<pre class="wfx-pre">' + esc(JSON.stringify(e.payload, null, 2)) + '</pre>';

        return h;
    }

    /**
     * Returns the HTML for one logged step - a condition with what it compared and the actual value, an action with its status and result, or an engine error.
     */
    function renderStep(s) {
        const kind = s.kind || '';
        if (kind === 'condition') {
            const ok = s.passed;
            return `<div class="wfx-step">
                <div class="wfx-step-head">
                    <span class="wfx-step-status ${ok ? 'ok' : 'bad'}">${ok ? esc(window.t('workflow.executions.passed')) : esc(window.t('workflow.executions.did_not_match'))}</span>
                    <code>${esc(s.field || '')} ${esc(s.op || '')} ${esc(JSON.stringify(s.value ?? ''))}</code>
                </div>
                <div class="wfx-step-sub">${esc(window.t('workflow.executions.actual'))} <code>${esc(JSON.stringify(s.actual ?? null))}</code></div>
            </div>`;
        }
        if (kind === 'action') {
            const st = s.status || '';
            const cls = st === 'success' ? 'ok' : (st === 'failed' ? 'bad' : '');
            let inner = '';
            if (st === 'dry_run') {
                inner = '<div class="wfx-step-sub"><em>' + esc(window.t('workflow.dry_run.would_have')) + '</em>'
                      + Object.entries(s.would_args || {})
                          .filter(([, v]) => v !== '' && v !== null)
                          .map(([k, v]) => '<div><code>' + esc(k) + ':</code> ' + esc(v) + '</div>').join('')
                      + '</div>';
            } else if (s.error) {
                inner = '<div class="wfx-step-err">' + esc(s.error) + '</div>';
            } else if (s.result) {
                inner = '<div class="wfx-step-sub"><code>' + esc(JSON.stringify(s.result)) + '</code></div>';
            }
            // A note the action left for a person (e.g. a tasks-only checklist it could not attach).
            if (s.attention) inner = '<div class="wfx-step-note">' + esc(s.attention) + '</div>' + inner;
            return `<div class="wfx-step">
                <div class="wfx-step-head">
                    <span class="wfx-step-status ${cls}">${esc(st)}</span>
                    <strong>${esc(s.would_run || s.type || '')}</strong>
                </div>${inner}
            </div>`;
        }
        // engine_error / loop_protection
        return `<div class="wfx-step">
            <div class="wfx-step-head"><span class="wfx-step-status bad">${esc(kind)}</span></div>
            ${s.error ? '<div class="wfx-step-err">' + esc(s.error) + '</div>' : ''}
        </div>`;
    }

    // ---- Wiring ------------------------------------------------------------

    const WFX = {
        go: (p) => { page = p; load(); window.scrollTo({ top: 0, behavior: 'smooth' }); },
        open,
        close: () => document.getElementById('wfxModal').classList.remove('active'),
        filterStatus: (s) => {
            const el = document.getElementById('fStatus');
            el.value = (el.value === s) ? '' : s;   // click the active one to clear it
            page = 1;
            load();
        },
        reset: () => {
            Object.keys(FIELDS).forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
            page = 1;
            load();
        },
    };
    window.WFX = WFX;

    document.addEventListener('DOMContentLoaded', () => {
        readUrlIntoControls();
        Object.keys(FIELDS).forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            const evt = el.tagName === 'SELECT' || el.type === 'date' ? 'change' : 'input';
            let timer = null;
            el.addEventListener(evt, () => {
                clearTimeout(timer);
                timer = setTimeout(() => { page = 1; load(); }, evt === 'input' ? 300 : 0);
            });
        });
        load();
    });
})();
