<?php
/**
 * Forms Module — every submission in a collection, across all its forms.
 *
 * 🔑 WHY THIS IS NOT submissions.php. That page shows ONE form, and its columns
 * ARE that form's questions. A collection holds several forms with different
 * questions, so the table can only show what they have in common — which form,
 * who, when, and the approval — and the answers live in the detail panel and
 * the PDF, where each submission is rendered against its own form.
 *
 * The rows come from `form_submissions.collection_id`, the STAMP, not from
 * joining through the form. A submission made while the form was a member
 * belongs here whatever the form says today.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/branding.php';   // brandingLogoUrl() for the PDF export
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('forms');

$current_page = 'forms';
$path_prefix = '../';
$translationNamespaces = ['common', 'forms'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('forms.collections.page_title')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../assets/js/i18n.js?v=3"></script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <!-- For FormLogic.formatDateValue() — date answers are naive local values and must
         NOT go through Tz, which would shift them into the reader's timezone. -->
    <script src="../assets/js/form-logic.js?v=9"></script>
    <script src="../assets/js/vendor/jspdf.umd.min.js"></script>
    <!-- autotable: a table QUESTION is drawn as a real table in the PDF rather
         than flattened into a paragraph. Same versions as morning-checks. -->
    <script src="../assets/js/vendor/jspdf.plugin.autotable.min.js"></script>
    <!-- The shared document builder. Same code as the single-form page, so a
         record exported from here is identical to one exported from there. -->
    <script src="../assets/js/form-pdf.js?v=2"></script>
    <link rel="stylesheet" href="../assets/css/theme.css?v=24">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=76">
    <!-- The detail panel draws a table QUESTION as a real table, and .form-table
         lives in the shared sheet. Without this the markup is right and the
         table renders unstyled - borderless rows running into each other. -->
    <link rel="stylesheet" href="../assets/css/form-shared.css?v=6">
    <style>
        body { --accent: var(--forms-accent, #00897b); --accent-hover: var(--forms-accent-hover, #00695c); }

        .coll-container { flex: 1; overflow-y: auto; background-color: var(--app-bg, #f5f7fa); }

        /* Full width, edge to edge. ⚠️ Dropping the cap alone does NOTHING:
           `.main-container` is `display: flex`, so this is a flex item and an
           auto cross-axis margin absorbs the free space — it would keep its
           gutters and look exactly as if a cap were still in place. The
           `margin: 0` is the half that does the work. */
        .coll-content {
            flex: 1 1 auto;
            min-width: 0;
            max-width: none;
            width: 100%;
            margin: 0;
            box-sizing: border-box;
            padding: 24px 32px 40px;
        }

        .coll-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
        .coll-toolbar-left { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .coll-toolbar-left h2 { margin: 0; font-size: 20px; color: var(--text, #333); }
        .coll-toolbar-right { display: flex; align-items: center; gap: 10px; }

        .coll-count { font-size: 13px; color: var(--text-dim, #888); background: var(--surface-hover, #f0f0f0); padding: 3px 10px; border-radius: 12px; }
        .coll-closed-pill { font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; background: var(--surface-hover); color: var(--text-muted); }
        .coll-blurb { color: var(--text-muted, #666); font-size: 13px; margin: -4px 0 16px; }

        .filter-group { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-muted, #666); }
        .filter-group input[type="date"] {
            padding: 6px 10px; border: 1px solid var(--border, #ddd); border-radius: 4px;
            font-size: 13px; font-family: inherit; background: var(--surface, #fff); color: var(--text, #333);
        }

        .btn { padding: 8px 16px; border: none; border-radius: 5px; cursor: pointer; font-size: 13px;
               font-weight: 500; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; transition: background-color .15s; }
        .btn-secondary { background: var(--surface-2, #f5f7fa); color: var(--text, #333); border: 1px solid var(--border, #ddd); }
        .btn-secondary:hover { background: var(--surface-hover, #eef0f2); }

        /* The selection bar. ⚠️ NOT `display:flex` with `[hidden]` — an element
           with a display rule of its own ignores the hidden attribute. */
        .coll-selbar {
            display: none; align-items: center; gap: 10px; margin: 0 0 12px;
            padding: 10px 14px; border-radius: 6px;
            background: var(--surface-2); border: 1px solid var(--border);
        }
        .coll-selbar.open { display: flex; }
        .coll-selbar .sel-count { font-weight: 600; font-size: 14px; color: var(--text); white-space: nowrap; }
        .coll-selbar .sel-spacer { flex: 1; }

        .coll-card { background: var(--surface, #fff); border-radius: 8px; box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,.08)); overflow: hidden; }
        .coll-table-wrap { overflow-x: auto; }
        .coll-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .coll-table th {
            background: var(--surface-2, #f8f9fa); padding: 10px 14px; text-align: left; font-weight: 600;
            color: var(--text-muted, #555); border-bottom: 2px solid var(--border, #e8e8e8); white-space: nowrap;
            position: sticky; top: 0;
        }
        .coll-table td { padding: 10px 14px; border-bottom: 1px solid var(--border-soft, #f0f0f0); color: var(--text, #333); }
        .coll-table tbody tr { cursor: pointer; }
        .coll-table tbody tr:hover td { background: #f8fbff; }
        [data-theme-mode="dark"] .coll-table tbody tr:hover td { background: var(--forms-accent-soft); }

        .coll-table th.sel-col, .coll-table td.sel-col { width: 34px; padding-left: 10px; padding-right: 0; text-align: center; }
        .coll-table .sel-box { cursor: pointer; margin: 0; vertical-align: middle; }
        .coll-table td.num-col { width: 52px; color: var(--text-dim); }
        .coll-table td.form-col { font-weight: 600; }
        .coll-table td.act-col { text-align: right; white-space: nowrap; width: 1%; }

        .row-pdf-btn {
            background: none; border: none; cursor: pointer; padding: 4px; margin-right: 2px;
            color: var(--text-muted, #94a3b8); border-radius: 4px; vertical-align: middle;
        }
        .row-pdf-btn:hover { color: var(--accent, #00897b); background: var(--surface-hover, #f1f5f9); }

        .da-pill { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 10px; }
        .da-approved { background: #dcfce7; color: #16a34a; }
        .da-rejected { background: #fee2e2; color: #dc2626; }
        .da-pending  { background: #fef3c7; color: #b45309; }
        .da-none     { background: var(--surface-hover); color: var(--text-muted); }

        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-dim, #888); }
        .empty-state h3 { margin: 12px 0 6px; color: var(--text-muted, #666); font-size: 16px; }

        /* Detail panel — the answers, rendered against the submission's OWN form. */
        .detail-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 2000;
            display: none; justify-content: center; align-items: center;
        }
        .detail-overlay.open { display: flex; }
        .detail-box { background: var(--surface, #fff); border-radius: 8px; width: 90%; max-width: 760px; max-height: 88vh; display: flex; flex-direction: column; }
        .detail-head { padding: 15px 24px; border-bottom: 1px solid var(--border); font-size: 18px; font-weight: 600; color: var(--text); }
        .detail-body { padding: 24px; overflow-y: auto; flex: 1; min-height: 0; }
        .detail-foot { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; gap: 12px; justify-content: flex-end; }
        .detail-meta { font-size: 12px; color: var(--text-dim); margin-bottom: 14px; }
        .detail-approval { border: 1px solid var(--border); background: var(--surface-2); border-radius: 6px; padding: 10px 12px; margin-bottom: 18px; font-size: 13px; }
        .detail-field { margin-bottom: 14px; }
        .detail-field-label { font-size: 12px; font-weight: 600; color: var(--text-muted); margin-bottom: 3px; }
        .detail-field-value { font-size: 14px; color: var(--text); white-space: pre-wrap; }
        .detail-field-value.empty { color: var(--text-faint); font-style: italic; }
        .col-retired { font-size: 10px; font-weight: 600; color: var(--text-faint); }

        @media print {
            .header, .coll-toolbar-right, .coll-selbar, .sel-col, .act-col,
            .detail-overlay { display: none !important; }
            .coll-content { padding: 0; }
            .coll-card { box-shadow: none; }
        }
    </style>
    <link rel="stylesheet" href="../assets/css/mobile.css?v=175">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="main-container coll-container">
        <div class="coll-content">
            <div class="coll-toolbar">
                <div class="coll-toolbar-left">
                    <a href="settings/?tab=collections" class="btn btn-secondary">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                        <?php echo htmlspecialchars(t('forms.collections.back')); ?>
                    </a>
                    <h2 id="collTitle"><?php echo htmlspecialchars(t('forms.collections.heading')); ?></h2>
                    <span class="coll-closed-pill" id="collClosedPill" style="display:none"><?php echo htmlspecialchars(t('forms.collections.closed_badge')); ?></span>
                    <span class="coll-count" id="collCount"></span>
                </div>
                <div class="coll-toolbar-right">
                    <div class="filter-group">
                        <label for="dateFrom"><?php echo htmlspecialchars(t('forms.subs.from')); ?></label>
                        <input type="date" id="dateFrom" onchange="applyFilter()">
                    </div>
                    <div class="filter-group">
                        <label for="dateTo"><?php echo htmlspecialchars(t('forms.subs.to')); ?></label>
                        <input type="date" id="dateTo" onchange="applyFilter()">
                    </div>
                    <button class="btn btn-secondary" onclick="clearFilter()"><?php echo htmlspecialchars(t('forms.subs.clear')); ?></button>
                </div>
            </div>

            <p class="coll-blurb" id="collBlurb"></p>

            <div class="coll-selbar" id="selBar">
                <span class="sel-count" id="selCount"></span>
                <span class="sel-spacer"></span>
                <button class="btn btn-secondary" onclick="exportSelected('bundle')" title="<?php echo htmlspecialchars(t('forms.subs.sel_bundle_hint')); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    <?php echo htmlspecialchars(t('forms.subs.sel_bundle')); ?>
                </button>
                <button class="btn btn-secondary" onclick="exportSelected('separate')" title="<?php echo htmlspecialchars(t('forms.subs.sel_separate_hint')); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    <?php echo htmlspecialchars(t('forms.subs.sel_separate')); ?>
                </button>
                <button class="btn btn-secondary" onclick="clearSelection()"><?php echo htmlspecialchars(t('forms.subs.sel_clear')); ?></button>
            </div>

            <div class="coll-card">
                <div class="coll-table-wrap">
                    <div id="collBody">
                        <div style="text-align:center;padding:40px;color:var(--text-dim, #888)"><?php echo htmlspecialchars(t('forms.subs.loading')); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="detail-overlay" id="detailOverlay" onclick="if(event.target===this)closeDetail()">
        <div class="detail-box">
            <div class="detail-head" id="detailHead"><?php echo htmlspecialchars(t('forms.subs.detail_heading')); ?></div>
            <div class="detail-body" id="detailBody"></div>
            <div class="detail-foot">
                <button class="btn btn-secondary" onclick="closeDetail()"><?php echo htmlspecialchars(t('common.close')); ?></button>
                <button class="btn btn-secondary" id="detailPdfBtn" onclick="exportOne()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    <?php echo htmlspecialchars(t('forms.subs.export_pdf')); ?>
                </button>
            </div>
        </div>
    </div>

    <script src="../assets/js/mobile.js?v=73"></script>
    <script>
        const API_BASE = '<?php echo defined('BASE_URL') ? BASE_URL : '../'; ?>api/forms/';
        const LOGO_URL = <?php echo json_encode(brandingLogoUrl()); ?>;
        const COLLECTION_ID = <?php echo (int)($_GET['id'] ?? 0); ?>;

        let collection = null;
        let allSubmissions = [];
        let filtered = [];
        let formsById = {};          // form_id -> { title, fields }
        let currentIdx = -1;
        const selectedIds = new Set();

        function esc(v) {
            return String(v == null ? '' : v).replace(/[&<>"']/g, c =>
                ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }
        const escAttr = esc;

        /* 🔑 fmtDate, not fmtNaiveDate: a submission date is a real instant
           stamped by the server, so it converts into the reader's zone. */
        function shownDate(v) {
            return (typeof window.fmtDate === 'function') ? window.fmtDate(v) : (v || '');
        }

        document.addEventListener('DOMContentLoaded', load);

        async function load() {
            if (!COLLECTION_ID) {
                document.getElementById('collBody').innerHTML =
                    '<p style="color:var(--danger-text,#c00);text-align:center;padding:20px">' + esc(window.t('forms.subs.no_id')) + '</p>';
                return;
            }
            try {
                const res = await fetch(API_BASE + 'get_collection_submissions.php?id=' + COLLECTION_ID);
                const data = await res.json();
                if (!data.success) {
                    document.getElementById('collBody').innerHTML =
                        '<p style="color:var(--danger-text,#c00);text-align:center;padding:20px">' + esc(data.error) + '</p>';
                    return;
                }
                collection = data.collection;
                allSubmissions = data.submissions || [];
                filtered = allSubmissions;
                (data.forms || []).forEach(f => { formsById[f.id] = f; });

                document.getElementById('collTitle').textContent = collection.name;
                document.getElementById('collClosedPill').style.display = collection.closed_datetime ? '' : 'none';
                document.getElementById('collBlurb').textContent = collection.description || '';
                render();
            } catch (e) {
                console.error(e);
                document.getElementById('collBody').innerHTML =
                    '<p style="color:var(--danger-text,#c00);text-align:center;padding:20px">' + esc(window.t('forms.subs.load_failed')) + '</p>';
            }
        }

        function render() {
            /* The ticks belong to the rows that were on screen, and this redraws
               them. At the TOP, above the empty-list return: putting it at the
               foot means the one redraw that most obviously invalidates a
               selection — filtering down to nothing — is the only one that never
               clears it. */
            clearSelection();

            const n = filtered.length;
            document.getElementById('collCount').textContent =
                window.t(n === 1 ? 'forms.subs.count' : 'forms.subs.count_plural', { n: n });

            if (!n) {
                document.getElementById('collBody').innerHTML = `<div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg>
                    <h3>${esc(window.t('forms.collections.no_subs_title'))}</h3>
                    <p>${esc(window.t('forms.collections.no_subs_body'))}</p>
                </div>`;
                return;
            }

            let html = '<table class="coll-table"><thead><tr>';
            html += `<th class="sel-col"><input type="checkbox" class="sel-box" id="selAll" onchange="toggleSelectAll(this.checked)" title="${escAttr(window.t('forms.subs.sel_all'))}" aria-label="${escAttr(window.t('forms.subs.sel_all'))}"></th>`;
            html += '<th>' + esc(window.t('forms.subs.col_num')) + '</th>';
            html += '<th>' + esc(window.t('forms.collections.col_form')) + '</th>';
            html += '<th>' + esc(window.t('forms.subs.col_submitted_by')) + '</th>';
            html += '<th>' + esc(window.t('forms.subs.col_date')) + '</th>';
            html += '<th>' + esc(window.t('forms.subs.detail_approval')) + '</th>';
            html += '<th></th></tr></thead><tbody>';

            filtered.forEach((sub, idx) => {
                const ap = FormPdf.approvalParts(sub);
                html += `<tr onclick="showDetail(${idx})">`;
                html += `<td class="sel-col"><input type="checkbox" class="sel-box" data-sub-id="${sub.id}" onclick="event.stopPropagation()" onchange="toggleSelect(${sub.id}, this.checked)" aria-label="${escAttr(window.t('forms.subs.sel_row'))}"></td>`;
                html += `<td class="num-col">${n - idx}</td>`;
                html += `<td class="form-col">${esc(sub.form_title)}</td>`;
                html += `<td>${esc(sub.submitted_by || window.t('forms.subs.unknown_user'))}</td>`;
                html += `<td>${esc(shownDate(sub.submitted_date))}</td>`;
                html += `<td><span class="da-pill ${ap.cls || 'da-none'}">${esc(ap.label)}</span></td>`;
                html += `<td class="act-col"><button class="row-pdf-btn" onclick="event.stopPropagation();exportOne(${idx})" title="${escAttr(window.t('forms.subs.export_pdf'))}" aria-label="${escAttr(window.t('forms.subs.export_pdf'))}">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                </button></td>`;
                html += '</tr>';
            });

            html += '</tbody></table>';
            document.getElementById('collBody').innerHTML = html;
            refreshSelBar();
        }

        // ---------------- detail ----------------
        function showDetail(idx) {
            const sub = filtered[idx];
            if (!sub) return;
            currentIdx = idx;
            const form = formsById[sub.form_id] || { title: sub.form_title, fields: [] };

            document.getElementById('detailHead').textContent = form.title;

            const ap = FormPdf.approvalParts(sub);
            let html = `<div class="detail-meta">#${filtered.length - idx} · ${esc(window.t('forms.subs.detail_submitted_by'))} ${esc(sub.submitted_by || window.t('forms.subs.unknown_user'))} · ${esc(window.t('forms.subs.detail_date'))} ${esc(shownDate(sub.submitted_date))}</div>`;

            html += `<div class="detail-approval">
                <strong>${esc(window.t('forms.subs.detail_approval'))}:</strong> <span class="da-pill ${ap.cls || 'da-none'}">${esc(ap.label)}</span>`;
            if (sub.approval_decided_by) {
                html += `<div style="margin-top:6px">${esc(window.t('forms.approval.approver'))}: ${esc(sub.approval_decided_by)}`
                     + (sub.approval_decided_datetime ? ` (${esc(shownDate(sub.approval_decided_datetime))})` : '') + '</div>';
            }
            if (sub.approval_comment) {
                html += `<div style="margin-top:6px">${esc(window.t('forms.subs.approval_comment'))}: ${esc(sub.approval_comment)}</div>`;
            }
            html += '</div>';

            (form.fields || []).forEach(f => {
                const p = FormPdf.fieldValueParts(f, sub.data ? sub.data[f.id] : '');
                const retired = f.is_deleted == 1
                    ? ` <span class="col-retired" title="${escAttr(window.t('forms.subs.retired_hint'))}">${esc(window.t('forms.subs.retired'))}</span>` : '';
                html += `<div class="detail-field"><div class="detail-field-label">${esc(f.label)}${retired}</div>`;
                if (p.kind === 'grid') {
                    /* Drawn as a real table, the same as the single-form
                       submissions panel. ⚠️ Without this branch a table fell
                       through to the plain-text case below and showed its
                       one-line summary ("3 rows") as if that were the answer —
                       technically a record, and useless. */
                    html += p.empty
                        ? `<div class="detail-field-value empty">${esc(window.t('forms.subs.no_response'))}</div>`
                        : `<div class="detail-field-value"><div class="form-table-wrap"><table class="form-table">
                               <thead><tr>${p.columns.map(c => `<th>${esc(c.label)}${c.deleted
                                   ? ` <span class="col-retired">${esc(window.t('forms.subs.retired'))}</span>` : ''}</th>`).join('')}</tr></thead>
                               <tbody>${p.rows.map(r => `<tr>${p.columns.map(c =>
                                   `<td>${esc(FormPdf.gridCellText(c, r[c.id])) || '—'}</td>`).join('')}</tr>`).join('')}</tbody>
                           </table></div></div>`;
                } else if (p.kind === 'list') {
                    html += p.empty
                        ? `<div class="detail-field-value empty">${esc(window.t('forms.subs.no_response'))}</div>`
                        : `<div class="detail-field-value"><ul style="margin:0;padding-left:18px">${p.list.map(v => `<li>${esc(v)}</li>`).join('')}</ul></div>`;
                } else {
                    html += `<div class="detail-field-value ${p.empty ? 'empty' : ''}">${p.empty ? esc(window.t('forms.subs.no_response')) : esc(p.text)}</div>`;
                }
                html += '</div>';
            });

            document.getElementById('detailBody').innerHTML = html;
            document.getElementById('detailOverlay').classList.add('open');
        }

        function closeDetail() { document.getElementById('detailOverlay').classList.remove('open'); }

        // ---------------- selection ----------------
        function toggleSelect(id, on) { on ? selectedIds.add(id) : selectedIds.delete(id); refreshSelBar(); }

        function toggleSelectAll(on) {
            selectedIds.clear();
            if (on) filtered.forEach(s => selectedIds.add(s.id));
            document.querySelectorAll('.coll-table .sel-box[data-sub-id]').forEach(b => { b.checked = on; });
            refreshSelBar();
        }

        function clearSelection() {
            selectedIds.clear();
            document.querySelectorAll('.coll-table .sel-box').forEach(b => { b.checked = false; });
            refreshSelBar();
        }

        function refreshSelBar() {
            const bar = document.getElementById('selBar');
            const n = selectedIds.size;
            bar.classList.toggle('open', n > 0);
            document.getElementById('selCount').textContent =
                window.t(n === 1 ? 'forms.subs.sel_count' : 'forms.subs.sel_count_plural', { n: n });
            /* `indeterminate` is a property, never an attribute, so it cannot be
               set in the markup. */
            const all = document.getElementById('selAll');
            if (all) {
                all.checked = filtered.length > 0 && n === filtered.length;
                all.indeterminate = n > 0 && n < filtered.length;
            }
        }

        // ---------------- export ----------------
        /* Every document is produced by the shared FormPdf, against the
           submission's OWN form — so a record exported from a collection is
           byte-for-byte what the single-form page would produce. */
        function itemFor(idx) {
            const sub = filtered[idx];
            return { form: formsById[sub.form_id] || { title: sub.form_title, fields: [] },
                     sub: sub, num: filtered.length - idx };
        }

        async function exportOne(idx) {
            if (typeof idx !== 'number') idx = currentIdx;
            if (idx < 0 || !filtered[idx]) return;
            const btn = document.getElementById('detailPdfBtn');
            const fromPanel = (idx === currentIdx) && document.getElementById('detailOverlay').classList.contains('open');
            if (btn && fromPanel) btn.disabled = true;
            try {
                const it = itemFor(idx);
                await FormPdf.exportOne(it.form, it.sub, it.num, LOGO_URL);
            } catch (e) {
                console.error(e);
                if (typeof showToast === 'function') showToast(window.t('forms.subs.pdf_error'), 'error');
            } finally {
                if (btn && fromPanel) btn.disabled = false;
            }
        }

        async function exportSelected(mode) {
            const items = filtered
                .map((sub, idx) => ({ sub, idx }))
                .filter(r => selectedIds.has(r.sub.id))
                .map(r => itemFor(r.idx));
            if (!items.length) return;

            if (mode === 'separate' && items.length > 10 &&
                !confirm(window.t('forms.subs.sel_many_confirm', { n: items.length }))) return;

            const bar = document.getElementById('selBar');
            bar.querySelectorAll('button').forEach(b => { b.disabled = true; });
            try {
                if (mode === 'bundle') await FormPdf.exportBundle(items, collection.name, LOGO_URL);
                else await FormPdf.exportSeparate(items, LOGO_URL);
            } catch (e) {
                console.error(e);
                if (typeof showToast === 'function') showToast(window.t('forms.subs.pdf_error'), 'error');
            } finally {
                bar.querySelectorAll('button').forEach(b => { b.disabled = false; });
            }
        }

        // ---------------- filter ----------------
        function applyFilter() {
            const from = document.getElementById('dateFrom').value;
            const to   = document.getElementById('dateTo').value;
            filtered = allSubmissions.filter(sub => {
                /* Bucket by the LOCAL (reader-zone) date so the filter matches
                   the date shown in the table — submitted_date is a UTC instant. */
                /* ymdInZone + parseUTCDate, exactly as submissions.php does.
                   ⚠️ NOT a slice of the raw string: that is the UTC calendar
                   day, so a submission made at 23:30 UTC filters into the
                   wrong day for anybody ahead of it. */
                const local = window.ymdInZone(window.parseUTCDate(sub.submitted_date));
                if (from && local < from) return false;
                if (to && local > to) return false;
                return true;
            });
            render();
        }

        function clearFilter() {
            document.getElementById('dateFrom').value = '';
            document.getElementById('dateTo').value = '';
            filtered = allSubmissions;
            render();
        }
    </script>
</body>
</html>
