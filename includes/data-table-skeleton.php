<?php
/**
 * Shared data-table skeleton (toolbar + table) for the full-screen table views.
 *
 * Renders the standard markup that assets/js/data-table.js drives (fixed dt-*
 * element IDs). Pair with assets/css/data-table.css. A standalone page wraps
 * this in <div class="dt-page">; the tasks module nests it inside its own
 * flex layout instead.
 *
 * Options (set before include):
 *   $dtShowPdf           bool   — show the PDF export button (default false)
 *   $dtSearchPlaceholder string — search box placeholder
 */
$dtShowPdf = $dtShowPdf ?? false;
$dtSearchPlaceholder = $dtSearchPlaceholder ?? 'Search across visible columns...';
?>
<div class="dt-layout">
    <div class="dt-toolbar">
        <input type="text" id="dtSearch" class="dt-search" placeholder="<?php echo htmlspecialchars($dtSearchPlaceholder); ?>" autocomplete="off">
        <button type="button" class="dt-btn" id="dtColumnsBtn" title="Choose visible columns and drag to reorder">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="8" y1="6" x2="21" y2="6"></line>
                <line x1="8" y1="12" x2="21" y2="12"></line>
                <line x1="8" y1="18" x2="21" y2="18"></line>
                <line x1="3" y1="6" x2="3.01" y2="6"></line>
                <line x1="3" y1="12" x2="3.01" y2="12"></line>
                <line x1="3" y1="18" x2="3.01" y2="18"></line>
            </svg>
            Columns
        </button>
        <button type="button" class="dt-btn" id="dtResetBtn" title="Clear all filters, sort and search">Reset</button>
        <button type="button" class="dt-btn" id="dtCsvBtn" title="Download visible rows as CSV">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="7 10 12 15 17 10"></polyline>
                <line x1="12" y1="15" x2="12" y2="3"></line>
            </svg>
            CSV
        </button>
        <?php if ($dtShowPdf): ?>
        <button type="button" class="dt-btn" id="dtPdfBtn" title="Download visible rows as PDF (selectable text)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
            </svg>
            PDF
        </button>
        <?php endif; ?>
        <span class="dt-count" id="dtCount"></span>
    </div>

    <div class="dt-wrap">
        <table class="dt-table" id="dtTable">
            <thead id="dtHead"></thead>
            <tbody id="dtBody">
                <tr><td colspan="20" class="dt-empty">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</div>
