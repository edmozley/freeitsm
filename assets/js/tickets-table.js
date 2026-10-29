/**
 * The ticket table (PR #213, by @Dr4inder): the inbox's ticket list shown as a
 * paginated table, with the column chooser, filters and saved views every other
 * module's table has.
 *
 * An analyst picks Messages or Table from the action row, or in System ->
 * Preferences (tickets_list_layout). Table mode uses the available queue width
 * and opens the existing reading pane beside it, or on its own in a narrower
 * window. The native ticket/workspace renderer and all actions are reused.
 *
 * HOW IT FITS
 * -----------
 * - The table itself is the shared assets/js/data-table.js, in its SERVER mode:
 *   it asks api/tickets/table.php for one page at a time, with sorting,
 *   filtering and search done by the server over the WHOLE list.
 * - The rows come from the message list's own query (includes/ticket_list.php),
 *   so the table can never show a ticket the list would not.
 * - 🔑 THE TABLE'S ROWS ARE DRESSED AS MESSAGE-LIST ROWS. onRowsRendered gives
 *   each <tr> the .email-item class and the data-email-id / data-ticket-id /
 *   data-ticket-number attributes inbox.js reads, so selection, Shift/Ctrl
 *   clicking, the right-click menu, drag to a folder, the SLA dots and bulk
 *   actions all work unchanged. ticketRowSelector() in inbox.js points them at
 *   whichever list is showing.
 * - The folder chosen on the left becomes the table's starting filter
 *   (folderFilters), and the view filter's My tickets / Hide closed travel as
 *   its context.
 *
 * @wiki Ticket-Table-Developer-Guide
 */
window.TicketTable = (() => {
    'use strict';

    let table = null;          // the createDataTable() instance, built on first use
    let folderKey = '';        // the folder the table was last filtered for
    let contextKey = '';       // the view filter it last used
    let switching = false;     // a Messages/Table change is being saved
    let detailOpen = false;    // a ticket (or the selection summary) is beside the table
    let drag = null;           // the divider drag in progress: pointer id, start x, start width
    const minList = 440, minDetail = 600, divider = 8;

    // The divider's position, as a share of the width: remembered in this browser,
    // because a screen's width belongs to the device rather than the person.
    // layout() clamps it, so a share saved on a big monitor cannot squeeze a laptop.
    // TRAP: localStorage can throw (a private window, blocked site data) - it is
    // a convenience, so a failure just means the default.
    const SPLIT_KEY = 'tickets_table_split';
    let splitRatio = .44;
    try {
        const saved = parseFloat(localStorage.getItem(SPLIT_KEY));
        if (saved > 0 && saved < 1) splitRatio = saved;
    } catch (e) { /* the default */ }

    /** Ends a divider drag and releases the pointer. */
    function stopResize() {
        if (!drag) return;
        const splitter = document.getElementById('ticketTableSplitter');
        if (splitter.hasPointerCapture(drag.id)) splitter.releasePointerCapture(drag.id);
        drag = null;
        document.body.classList.remove('ticket-table-resizing');
    }

    /** The width the table and the ticket share: the page less the folder pane. */
    function availableWidth() {
        // Computed width survives display:none in the narrow detail view.
        // Measuring its hidden rectangle here would oscillate between layouts.
        const folder = document.querySelector('.folder-container');
        return document.querySelector('.main-container').clientWidth - parseFloat(getComputedStyle(folder).width);
    }

    /** Sizes the table and the ticket for the screen: side by side, the ticket alone (narrow), or the table alone. */
    function layout() {
        const desktop = matchMedia('(min-width: 769px)').matches;
        const open = isActive() && detailOpen;
        const available = availableWidth();
        const narrow = desktop && open && available < minList + minDetail + divider;
        document.body.classList.toggle('ticket-table-detail', open);
        document.body.classList.toggle('ticket-table-narrow', narrow);
        document.getElementById('ticketTableBack').hidden = !open;
        const splitter = document.getElementById('ticketTableSplitter');
        splitter.hidden = !desktop || !open || narrow;
        if (splitter.hidden) { stopResize(); return; }
        const max = available - minDetail - divider;
        const width = Math.round(Math.max(minList, Math.min(max, available * splitRatio)));
        document.body.style.setProperty('--ticket-table-width', width + 'px');
        splitter.setAttribute('aria-valuemin', minList);
        splitter.setAttribute('aria-valuemax', Math.floor(max));
        splitter.setAttribute('aria-valuenow', width);
    }

    // Called by the native selection/loading paths, including failed loads and
    // the bulk summary. Visibility must not depend on a successful API response.
    function showDetail(open = true) {
        detailOpen = open;
        layout();
    }

    /**
     * Closes the ticket beside the table and gives the table the width back.
     * Escape does the same (inbox.js), after full screen has had its turn.
     */
    async function backToList() {
        if (!isActive() || !detailOpen) return;
        if (window.TicketWorkspace && !await TicketWorkspace.canLeave()) return;
        // TRAP: leave full screen through its own exit, never by removing the
        // class - toggleTicketPopout() also clears the saved preference and puts
        // the action row back. Taking the class alone left "full screen" saved,
        // so the next ticket opened full screen again.
        if (document.body.classList.contains('ticket-popout')) toggleTicketPopout();
        showDetail(false);
        // Keep both DOMs: returning to the queue must not reset its page,
        // filters or scroll, nor replace a live task editor or ticket thread.
        (document.querySelector('#dtBody .selected') || document.getElementById('dtSearch')).focus({preventScroll: true});
    }

    /** Wires the divider: drag, arrow keys, Home and End. The share is saved as it changes. */
    function bindLayout() {
        const splitter = document.getElementById('ticketTableSplitter');
        const setWidth = width => {
            const available = availableWidth();
            splitRatio = Math.max(minList, Math.min(available - minDetail - divider, width)) / available;
            try { localStorage.setItem(SPLIT_KEY, splitRatio.toFixed(4)); } catch (e) { /* kept for this page only */ }
            layout();
        };
        splitter.addEventListener('keydown', event => {
            const current = Number(splitter.getAttribute('aria-valuenow'));
            const widths = {ArrowLeft: current - 24, ArrowRight: current + 24,
                Home: minList, End: availableWidth() - minDetail - divider};
            if (!(event.key in widths)) return;
            event.preventDefault();
            setWidth(widths[event.key]);
        });
        splitter.addEventListener('pointerdown', event => {
            if (event.button !== 0) return;
            drag = {id: event.pointerId, x: event.clientX, width: Number(splitter.getAttribute('aria-valuenow'))};
            splitter.setPointerCapture(event.pointerId);
            document.body.classList.add('ticket-table-resizing');
            event.preventDefault();
        });
        splitter.addEventListener('pointermove', event => {
            if (drag && event.pointerId === drag.id) setWidth(drag.width + event.clientX - drag.x);
        });
        ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(type => splitter.addEventListener(type, stopResize));
        new ResizeObserver(layout).observe(document.querySelector('.main-container'));
        new ResizeObserver(layout).observe(document.querySelector('.folder-container'));
    }

    const text = key => t('tickets.queue.' + key);
    const isActive = () => window.TICKETS_LIST_LAYOUT === 'table';

    /** What the folder pane says, which the server applies on top of the filters. */
    function context() {
        return {
            view: ['trash', 'snoozed'].includes(currentFilter.type) ? currentFilter.type : 'active',
            mine: viewFilterMineActive(),
            includeClosed: !viewFilter.hide_closed,
            searchContent: !!document.getElementById('ticketTableContent')?.checked,
        };
    }

    /**
     * The folder selected on the left, as table filters - so opening the table
     * on "Service Desk / Open" shows those tickets, with the filters ticked to
     * match. An Unassigned folder means "empty" in whichever column the folders
     * are grouped by.
     */
    function folderFilters() {
        const f = currentFilter;
        const result = {};
        if (f.type.startsWith('unassigned')) {
            const column = folderGrouping === 'analyst' ? 'assignee_id' : folderGrouping === 'team' ? 'team_id' : 'department_id';
            result[column] = ['unassigned'];
        }
        if (f.type === 'department' || f.type === 'dept_status') result.department_id = [String(f.id ?? f.dept_id)];
        if (f.type === 'team' || f.type === 'team_status') result.team_id = [String(f.id ?? f.team_id)];
        if (f.type === 'analyst' || f.type === 'analyst_status') result.assignee_id = [String(f.id ?? f.analyst_id)];
        if (f.status) {
            const status = ticketStatuses.find(s => s.name === f.status);
            if (status) result.status_id = [String(status.id)];
        }
        return result;
    }

    /** The table's query as api/tickets/table.php's parameters. The server works out company access itself. */
    function parameters(q) {
        return new URLSearchParams({
            page: q.page,
            page_size: q.pageSize,
            sort: q.sort.key,
            dir: q.sort.dir,
            q: q.search,
            filters: JSON.stringify(q.filters),
            view: q.context.view,
            mine: q.context.mine ? '1' : '0',
            include_closed: q.context.includeClosed ? '1' : '0',
            search_content: q.context.searchContent ? '1' : '0',
        });
    }

    /**
     * Clear the selection whenever the rows change. A bulk action must never hit
     * tickets on another page that are no longer on screen.
     */
    function clearSelection() {
        if (!isActive()) return;
        selectedEmailIds.clear();
        selectionAnchorId = null;
        selectionFocusId = null;
        hideSelectionBar();
        hideKeepModeWarning();
        renderSelectionUi();
    }

    /** Format a cell. Created and snoozed-until are UTC; the work window is the wall-clock time as typed. */
    function displayValue(key, value) {
        if (!value) return '';
        if (['work_start_datetime', 'work_end_datetime'].includes(key)) return formatNaiveFullDateTime(value);
        if (['created_datetime', 'snoozed_until'].includes(key)) return fmtDateTime(parseUTCDate(value));
        return value;
    }

    /**
     * Build the table, the first time it is needed.
     *
     * Columns: [key, the row field it shows (when not the key), visible by default, sortable].
     * The six id columns are filterable; the server sends their labels and counts.
     */
    function setup() {
        const columns = [
            ['ticket_number',       null,               true,  true],
            ['subject',             null,               true,  false],
            ['status_id',           'status',           true,  true],
            ['priority_id',         'priority',         true,  true],
            ['assignee_id',         'assignee_name',    true,  true],
            ['created_datetime',    null,               true,  true],
            ['department_id',       'department_name',  false, false],
            ['team_id',             'team_name',        false, false],
            ['ticket_type_id',      'ticket_type_name', false, false],
            ['work_start_datetime', null,               false, true],
            ['work_end_datetime',   null,               false, true],
            ['snoozed_until',       null,               false, true],
            ['company_name',        null,               false, false],
        ].map(([key, field, visible, sortable], index) => ({
            key,
            label: text('columns.' + key),
            defaultVisible: visible,
            defaultOrder: index,
            sortable,
            filterable: ['status_id', 'priority_id', 'assignee_id', 'department_id', 'team_id', 'ticket_type_id'].includes(key),
            display: row => displayValue(key, row[field || key]),
        }));

        table = createDataTable({
            manual: true,                  // started by reload(), not on page load
            columns,
            initialFilters: folderFilters(),
            prefKey: 'tickets_table',      // column layout, per analyst
            viewsKey: 'tickets',           // saved views (includes/table_views.php)
            prefApi: sharedApiBase() + 'system/',
            defaultSort: {key: 'created_datetime', dir: 'desc'},
            rowId: r => r.inbox_id,
            noun: text('ticket'),
            getContext: context,

            // A saved view carries its folder context too. Applying one puts the
            // folder pane and view filter back to match, so the screen agrees with
            // the rows.
            applyContext: ctx => {
                currentFilter = {type: ['trash', 'snoozed'].includes(ctx.view) ? ctx.view : 'all'};
                viewFilter = {mine: !!ctx.mine, hide_closed: ctx.includeClosed === false};
                document.getElementById('ticketTableContent').checked = !!ctx.searchContent;
                folderKey = JSON.stringify([currentFilter, folderGrouping]);
                contextKey = JSON.stringify(context());
                document.getElementById('emailListTitle').textContent =
                    t('tickets.list.' + (currentFilter.type === 'all' ? 'all_tickets' : currentFilter.type));
                updateActiveFolderClasses();
                updateViewFilterUI();
                loadFolderCounts();
            },

            onLoading: clearSelection,
            onLoadError: clearSelection,
            // A click goes through the message list's own handler, modifiers and all.
            onRowClick: (row, event) => handleEmailRowClick(event, Number(row.inbox_id)),
            onRowContextMenu: (row, event) => openTicketContextMenu(event, Number(row.ticket_id), row.ticket_number),

            // Dress each row as a message-list row (see the 🔑 at the top).
            onRowsRendered: rows => {
                if (!isActive()) return;
                emails = rows;   // inbox.js looks rows up in `emails` by id
                document.querySelectorAll('#dtBody .dt-row').forEach(node => {
                    const row = rows.find(r => String(r.inbox_id) === node.dataset.id);
                    node.classList.add('email-item', 'ticket-queue-row');
                    node.dataset.emailId = row.inbox_id;
                    node.dataset.ticketId = row.ticket_id;
                    node.dataset.ticketNumber = row.ticket_number;
                    node.draggable = true;
                    node.tabIndex = 0;
                    // Double-click opens this row full screen, as on the message list.
                    // Enter steps in one level: the ticket beside the table, then full
                    // screen (inbox.js enterRow; Escape steps back out).
                    node.addEventListener('dblclick', () => openRowFullScreen(Number(row.inbox_id)));
                    node.addEventListener('keydown', event => {
                        if (event.key !== 'Enter' || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
                        event.preventDefault();
                        enterRow(Number(row.inbox_id));
                    });
                    // Where loadInboxSlaIndicators() draws the SLA dot.
                    const slot = document.createElement('span');
                    slot.dataset.slaSlot = row.ticket_id;
                    node.firstElementChild?.append(slot);
                });
                attachEmailDragHandlers();
                renderSelectionUi();
                loadInboxSlaIndicators();
            },

            server: {
                load: async q => {
                    // A column filter changed away from the folder's own filter: the
                    // rows are no longer "that folder", so say All tickets rather
                    // than keep a folder highlighted that does not match.
                    const narrowed = folderFilters();
                    if (Object.entries(narrowed).some(([key, value]) => JSON.stringify(q.filters[key]) !== JSON.stringify(value))) {
                        currentFilter = {type: 'all'};
                        folderKey = JSON.stringify([currentFilter, folderGrouping]);
                        document.getElementById('emailListTitle').textContent = t('tickets.list.all_tickets');
                        updateActiveFolderClasses();
                    }
                    const response = await fetch(API_BASE + 'table.php?' + parameters(q));
                    const data = await response.json();
                    if (!response.ok || !data.success) throw Error('Ticket list unavailable');
                    return data;
                },
                // The whole matching list, not the page. The server checks Who can
                // export the ticket list on every request; the button is only
                // drawn when the answer is yes.
                exportCsv: async (q, signal) => {
                    const params = parameters(q);
                    params.set('columns', q.columns.join(','));
                    const response = await fetch(API_BASE + 'table_export.php?' + params, {signal});
                    if (!response.ok || !response.headers.get('Content-Type')?.includes('text/csv')) throw Error('Export unavailable');
                    const blob = await response.blob();
                    const url = URL.createObjectURL(blob);
                    const anchor = document.createElement('a');
                    anchor.href = url;
                    anchor.download = 'tickets.csv';
                    anchor.click();
                    setTimeout(() => URL.revokeObjectURL(url), 1000);
                },
            },
        });

        document.getElementById('ticketTableContent').addEventListener('change', () => table.setQuery({page: 1}));
    }

    /**
     * loadEmails() in inbox.js calls this instead of fetching the message list -
     * on a folder click, the view filter, and the 60-second refresh. A new folder
     * replaces the filters; a new view filter goes back to page 1; anything else
     * re-reads the page the analyst is on, keeping their filters.
     */
    async function reload() {
        if (!isActive()) return;
        const key = JSON.stringify([currentFilter, folderGrouping]);
        const nextContext = JSON.stringify(context());
        if (!table) {
            setup();
            folderKey = key;
            contextKey = nextContext;
            await table.start();
            return;
        }
        if (folderKey !== key) {
            folderKey = key;
            contextKey = nextContext;
            await table.setQuery({filters: folderFilters()});
        } else if (contextKey !== nextContext) {
            contextKey = nextContext;
            await table.setQuery({page: 1});
        } else {
            await table.reload();
        }
    }

    /**
     * The action row's Messages / Table dropdown. Asks the workspace first (a
     * half-written reply is protected here too), saves the preference, then swaps
     * the list. A failed save puts the dropdown back.
     */
    async function requestMode(mode) {
        const previous = window.TICKETS_LIST_LAYOUT || 'messages';
        const select = document.getElementById('ticketListSelect');
        if (switching || !['messages', 'table'].includes(mode)) return;
        switching = true;
        select.disabled = true;
        try {
            if (window.TicketWorkspace && !await TicketWorkspace.canLeave()) return;
            const response = await fetch(sharedApiBase() + 'system/set_user_preference.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({key: 'tickets_list_layout', value: mode}),
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw Error('Preference failed');
            loadEmailsToken++;   // a message-list load already in flight must not land after the switch
            clearSelection();
            window.TICKETS_LIST_LAYOUT = mode;
            paint();
            await loadEmails();
        } catch (error) {
            showToast(text('save_failed'), 'error');
        } finally {
            switching = false;
            select.disabled = false;
            select.value = window.TICKETS_LIST_LAYOUT || previous;
        }
    }

    /**
     * Show one list and hide the other. Exactly one is ever in the page's flow, so
     * the selection and drag code can never pick up rows from the hidden one.
     */
    function paint() {
        document.body.classList.toggle('ticket-table-mode', isActive());
        document.getElementById('emailList').hidden = isActive();
        document.getElementById('ticketTableContainer').hidden = !isActive();
        detailOpen = !!currentEmail || !!document.querySelector('#readingPane .sel-summary');
        layout();
    }
    document.addEventListener('DOMContentLoaded', () => { bindLayout(); paint(); });

    return {reload, requestMode, isActive, showDetail, backToList, getSelectedTicketIds: () => selectedTicketIds()};
})();
