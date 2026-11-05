/**
 * Change risk policies on the screen (GH #246) - shared by the change page, the change
 * table and Settings -> Risk policy.
 *
 * The page sets window.CM_RISK = { policies: {versionId: content}, current: versionId }
 * (ChangeRiskPolicy::jsBootstrap()). Version 0 is the built-in 5 x 5. A change is always
 * drawn on the version it was rated under (risk_policy_version_id, null = 0), never on the
 * one in use now - that is the whole point of versions.
 *
 * The built-in is shown in the reader's language and keeps its old CSS classes
 * (risk-low ... risk-critical), so an install that never sets a policy looks exactly as
 * before. A saved policy's words are the organisation's own and are shown as typed, in
 * its own colours.
 */
window.ChangeRisk = (function () {
    const data = window.CM_RISK || { policies: {}, current: 0 };
    const T = (k, p) => (window.t ? window.t(k, p) : k);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    /** A version's content; an unknown or missing version is the built-in. */
    function policy(versionId) {
        const p = data.policies[versionId || 0] || data.policies[0];
        return p || { builtin: true, likelihood: [], impact: [], results: [], matrix: [] };
    }
    /** The version new assessments use. */
    function currentId() { return data.current || 0; }
    function isBuiltin(p) { return !!(p && p.builtin); }

    /** A level's label: "3 - Medium" translated for the built-in, the policy's own word otherwise. */
    function levelLabel(p, axis, n) {
        const lv = (p[axis] || [])[n - 1];
        if (!lv) return String(n);
        return lv.key ? T('change-management.editor.' + lv.key) : lv.label;
    }
    /** What a level means: the built-in's example criteria, or the policy's own. */
    function criteria(p, axis, n) {
        const lv = (p[axis] || [])[n - 1];
        if (!lv) return '';
        return lv.criteria_key ? T('change-management.editor.' + lv.criteria_key) : (lv.criteria || '');
    }
    /** The result for a cell, from the matrix - never from multiplying. */
    function resultFor(p, l, i) {
        const row = (p.matrix || [])[l - 1];
        const idx = row ? row[i - 1] : undefined;
        return idx === undefined ? null : (p.results || [])[idx] || null;
    }
    /** A stored result word as a result: from its own version, else any version that has it. */
    function resultByLabel(p, label) {
        const find = q => (q.results || []).find(r => String(r.label).toLowerCase() === String(label || '').toLowerCase());
        let r = find(p);
        if (!r) Object.values(data.policies).some(q => (r = find(q)));
        return r || null;
    }
    /** A result's name in the reader's language when it is a built-in one. */
    function resultName(r, fallback) {
        if (!r) return String(fallback || '');
        return r.key ? T('change-management.risk.' + r.key) : r.label;
    }
    /** The class and style that colour a result: the old classes for the built-in, the policy's colour otherwise. */
    function paint(r, solid) {
        if (!r) return { cls: '', style: '' };
        if (r.key) return { cls: 'risk-' + r.key.replace(/_/g, '-'), style: '' };
        const c = /^#[0-9a-f]{6}$/i.test(r.colour || '') ? r.colour : '#94a3b8';
        return { cls: 'risk-custom', style: solid ? `background:${c};color:#fff;` : `background:${c}22;color:${c};border:1px solid ${c}66;` };
    }
    /** A badge for a change's stored result. */
    function badge(c, withScore) {
        if (!c || !c.risk_level) return '';
        const r = resultByLabel(policy(c.risk_policy_version_id), c.risk_level);
        const pt = paint(r);
        return `<span class="risk-badge ${pt.cls}"${pt.style ? ` style="${pt.style}"` : ''}>${esc(resultName(r, c.risk_level))}${withScore && c.risk_score ? ' (' + esc(c.risk_score) + ')' : ''}</span>`;
    }

    /** Fills the likelihood and impact selects with a version's levels, keeping a value that exists there. */
    function fillSelects(versionId, likelihoodSel, impactSel) {
        const p = policy(versionId);
        [[likelihoodSel, 'likelihood'], [impactSel, 'impact']].forEach(([sel, axis]) => {
            if (!sel) return;
            const keep = sel.value;
            sel.innerHTML = `<option value="">${esc(T('change-management.editor.not_assessed'))}</option>` +
                (p[axis] || []).map((_, n) => `<option value="${n + 1}">${esc(levelLabel(p, axis, n + 1))}</option>`).join('');
            sel.value = keep && Number(keep) <= (p[axis] || []).length ? keep : '';
        });
    }

    /** The rows of the rating guide's table for a version: each level of each axis with its criteria. */
    function guideRows(versionId) {
        const p = policy(versionId);
        const n = Math.max((p.likelihood || []).length, (p.impact || []).length);
        let html = '';
        for (let k = 1; k <= n; k++) {
            const cell = axis => (p[axis] || [])[k - 1]
                ? `<td>${isBuiltin(p) ? '' : `<strong>${esc(levelLabel(p, axis, k))}</strong>${criteria(p, axis, k) ? ' - ' : ''}`}${esc(criteria(p, axis, k))}</td>`
                : '<td></td>';
            html += `<tr><th scope="row">${k}</th>${cell('likelihood')}${cell('impact')}</tr>`;
        }
        return html;
    }

    /**
     * The likelihood-by-impact matrix for a change, on the version it was rated under:
     * rows likelihood (highest at the top), columns impact; each cell coloured by the
     * matrix's own result for it, the change's cell outlined.
     */
    function matrix(c) {
        const p = policy(c.risk_policy_version_id);
        const L = (p.likelihood || []).length, I = (p.impact || []).length;
        const l0 = parseInt(c.risk_likelihood), i0 = parseInt(c.risk_impact_score);
        let grid = '';
        for (let l = L; l >= 1; l--) {
            grid += `<div class="risk-matrix-label-y" title="${esc(levelLabel(p, 'likelihood', l))}">${l}</div>`;
            for (let i = 1; i <= I; i++) {
                const pt = paint(resultFor(p, l, i));
                const active = l === l0 && i === i0;
                grid += `<div class="risk-matrix-cell ${pt.cls.replace('risk-', '')}${active ? ' active' : ''}"${pt.style ? ` style="${pt.style}"` : ''} title="${esc(resultName(resultFor(p, l, i)))}">${l * i}</div>`;
            }
        }
        let xLabels = '<div class="risk-matrix-spacer"></div>';
        for (let i = 1; i <= I; i++) xLabels += `<div class="risk-matrix-label-x" title="${esc(levelLabel(p, 'impact', i))}">${i}</div>`;
        const r = resultByLabel(p, c.risk_level);
        const pt = paint(r);
        const name = isBuiltin(p) ? '' : `<div class="risk-matrix-policy">${esc(T('change-management.risk_policy.assessed_under', { name: p.name || '' }))}</div>`;
        return `
        <div class="risk-matrix-section">
            <h3>${esc(T('change-management.risk.assessment'))}</h3>
            <div class="risk-matrix-wrapper">
                <div class="risk-matrix-info">
                    <div class="risk-score-badge ${pt.cls.replace('risk-', '')}"${pt.style ? ` style="${pt.style}"` : ''}>
                        <span class="risk-score-value">${esc(c.risk_score)}</span>
                        <span class="risk-score-label">${esc(resultName(r, c.risk_level))}</span>
                    </div>
                    <div class="risk-matrix-details">
                        <div><strong>${esc(T('change-management.risk.likelihood'))}:</strong> ${esc(isBuiltin(p) ? l0 + ' / ' + L : levelLabel(p, 'likelihood', l0) + ' (' + l0 + ' / ' + L + ')')}</div>
                        <div><strong>${esc(T('change-management.risk.impact'))}:</strong> ${esc(isBuiltin(p) ? i0 + ' / ' + I : levelLabel(p, 'impact', i0) + ' (' + i0 + ' / ' + I + ')')}</div>
                        ${name}
                    </div>
                </div>
                <div class="risk-matrix-grid-container">
                    <div class="risk-matrix-y-label">${esc(T('change-management.risk.likelihood'))}</div>
                    <div class="risk-matrix-grid" style="grid-template-columns: 24px repeat(${I}, ${Math.max(L, I) > 6 ? 32 : 44}px); grid-template-rows: repeat(${L}, ${Math.max(L, I) > 6 ? 32 : 44}px) 20px;">
                        ${grid}
                        ${xLabels}
                    </div>
                    <div class="risk-matrix-x-label">${esc(T('change-management.risk.impact'))}</div>
                </div>
            </div>
        </div>`;
    }

    return { policy, currentId, isBuiltin, levelLabel, criteria, resultFor, resultByLabel, resultName, paint, badge,
             fillSelects, guideRows, matrix, data };
})();
