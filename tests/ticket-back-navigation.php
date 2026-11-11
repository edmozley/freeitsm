<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** Back must return to the queue without turning off the saved full-screen choice.
 * Uses the real ticket renderer, table navigation, event handlers and styles,
 * with synthetic records and no application/database connection. On the old
 * code the action-row Back leaves the table detail open and saves popout=0;
 * the table's own Back also asks to discard drafts that it could simply retain.
 */
require __DIR__ . '/support/ticket-workspace-browser.php';
require __DIR__ . '/support/ticket-workspace-page.php';

$root = dirname(__DIR__);
$source = file_get_contents($root . '/tickets/index.php');
if (!preg_match("/'onclick' => '([^']+)', 'id' => 'popoutBackBtn'/", $source, $match)) {
    throw new RuntimeException('Cannot find the action-row Back handler.');
}
$back = htmlspecialchars($match[1], ENT_QUOTES, 'UTF-8');
$rows = '';
for ($i = 0; $i < 40; $i++) {
    $rows .= '<tr class="dt-row email-item' . ($i === 0 ? ' selected' : '') . '" tabindex="0" data-email-id="' . (10 + $i) . '"><td style="height:32px">ZZ queue ' . $i . '</td></tr>';
}
$layout = '<h1 id="inboxActionTitle">Inbox</h1><button id="popoutBackBtn" hidden onclick="' . $back . '">Back</button>'
    . '<button id="ticketTableBack" hidden onclick="TicketTable.backToList()">Back</button>'
    . '<div class="main-container"><aside class="folder-container" style="width:200px;min-width:200px">ZZ queue</aside>'
    . '<section class="email-list-container"><div id="emailList" tabindex="-1"><div class="email-item" data-email-id="10">ZZ ticket</div></div>'
    . '<div id="ticketTableContainer"><input id="dtSearch" value="ZZ filter"><div class="dt-wrap" style="height:200px;overflow:auto"><table><tbody id="dtBody">' . $rows . '</tbody></table></div></div></section>'
    . '<div id="ticketTableSplitter" class="ticket-table-splitter" hidden></div><main id="readingPane" class="reading-pane"></main></div>';
$page = ticketWorkspacePage("window.navigationErrors = []; window.addEventListener('error', event => navigationErrors.push(event.message));\n"
    . file_get_contents($root . '/assets/js/schedule.js'));
$page = str_replace('<main id="readingPane" class="reading-pane"></main><div id="emailList"></div>', $layout, $page);
$page = str_replace('</head>', '<style>' . file_get_contents($root . '/assets/css/tickets-table.css') . '</style></head>', $page);
$page .= '<script>' . str_replace('</script', '<\/script', file_get_contents($root . '/assets/js/tickets-table.js')) . '</script>';
$page .= <<<'HTML'
<script>
document.addEventListener = originalAdd;
(async () => {
    const checks = [], errors = window.navigationErrors;
    const check = (label, pass) => checks.push({label, pass: !!pass});
    window.addEventListener('error', e => errors.push(e.message));
    window.addEventListener('unhandledrejection', e => errors.push(String(e.reason)));
    const pause = () => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
    for (const name of ['loadAiSummary', 'loadTicketChecklists', 'loadCorrespondenceThread', 'loadNotes',
        'loadTicketAttachments', 'loadCmdbObjects', 'loadTicketDomains', 'loadTicketProjects', 'loadTicketAssets',
        'loadTicketTasks', 'loadTimeEntries', 'loadSlaState', 'loadTicketCustomFields', 'startPresence']) window[name] = () => {};
    departments = [{id: 1, name: 'ZZ Service desk'}];
    analysts = [{id: 1, full_name: 'ZZ Analyst'}];
    ticketStatuses = [{id: 1, name: 'Open'}];
    ticketPriorities = [{id: 1, name: 'Normal'}];
    const ticket = {id: 10, ticket_id: 101, ticket_number: 'ZZ-101', subject: 'ZZ ticket', status: 'Open',
        department_id: 1, priority_id: 1, owner_id: 1, from_address: 'requester@example.invalid',
        from_name: 'ZZ Requester', to_recipients: 'desk@example.invalid', received_datetime: '2026-01-01 09:00:00',
        body_content: 'ZZ message', body_type: 'text', linked_tickets: {}, problems: [], changes: []};
    let requests = 0, prompts = 0;
    window.fetch = async input => {
        const url = new URL(input, location.href);
        if (!url.pathname.endsWith('/get_email_detail.php')) throw Error('Unexpected request: ' + url.pathname);
        requests++;
        const id = url.searchParams.has('id') ? Number(url.searchParams.get('id')) : Number(url.searchParams.get('ticket_id')) - 91;
        return {json: async () => ({success: true, email: {...ticket, id, ticket_id: id + 91, ticket_number: 'ZZ-' + (id + 91)}})};
    };
    window.showConfirm = async () => { prompts++; return true; };
    try {
        for (const workspace of [false, true]) {
            for (const mode of ['messages', 'table']) {
                for (const action of mode === 'table' ? ['Back', 'Escape', 'table Back'] : ['Back', 'Escape']) {
                    const label = (workspace ? 'workspace' : 'classic') + '/' + mode + '/' + action + ': ';
                    TicketWorkspace.dispose();
                    window.TICKET_WORKSPACE = {mode: workspace ? 'all' : 'off', personal: true, ready: true, can_write: true};
                    window.TICKETS_LIST_LAYOUT = mode;
                    document.body.classList.toggle('ticket-table-mode', mode === 'table');
                    document.getElementById('emailList').hidden = mode === 'table';
                    document.getElementById('ticketTableContainer').hidden = mode !== 'table';
                    currentEmail = null;
                    selectedEmailId = selectionFocusId = selectionAnchorId = 10;
                    selectedEmailIds = new Set([10]);
                    emails = [ticket, {...ticket, id: 11, ticket_id: 102}];
                    document.body.classList.remove('ticket-popout');
                    TicketTable.showDetail(false);
                    const scroller = document.querySelector('#ticketTableContainer .dt-wrap');
                    scroller.scrollTop = 450;
                    const scroll = scroller.scrollTop, query = document.getElementById('dtSearch');
                    query.value = 'ZZ retained filter';
                    toggleTicketPopout();
                    TicketTable.showDetail();
                    displayEmail(ticket, []);
                    const pane = document.getElementById('readingPane'), retained = pane.firstElementChild;
                    const draft = document.createElement('textarea');
                    draft.id = 'channelComposerText'; draft.value = 'ZZ unsaved reply'; pane.append(draft);
                    const property = document.createElement('input');
                    property.value = 'original'; pane.append(property);
                    TicketWorkspace.propertySaved(property); property.value = 'ZZ unsaved property';
                    const beforePrompts = prompts, beforeRequests = requests;
                    document.activeElement?.blur();
                    if (action === 'Back') document.getElementById('popoutBackBtn').click();
                    else if (action === 'table Back') await TicketTable.backToList();
                    else document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
                    await pause();
                    check(label + 'returns directly to the list', !document.body.classList.contains('ticket-popout')
                        && getComputedStyle(document.querySelector('.email-list-container')).display !== 'none'
                        && (mode !== 'table' || getComputedStyle(pane).display === 'none'));
                    check(label + 'keeps the full-screen preference', localStorage.getItem('tickets_popout') === '1');
                    check(label + 'retains drafts without a discard prompt', prompts === beforePrompts
                        && draft.value === 'ZZ unsaved reply' && property.value === 'ZZ unsaved property' && pane.firstElementChild === retained);
                    check(label + 'preserves the queue and its scroll', scroller.scrollTop === scroll
                        && document.getElementById('dtSearch') === query && query.value === 'ZZ retained filter' && requests === beforeRequests);
                    syncPopoutToTicketState(true);
                    check(label + 'a refresh cannot reopen the hidden full screen', !document.body.classList.contains('ticket-popout'));
                    if (action === 'Escape') enterRow(10);
                    else if (action === 'table Back') openRowFullScreen(10);
                    else await selectEmail(10);
                    await pause();
                    check(label + 'reopens the retained ticket full screen', document.body.classList.contains('ticket-popout')
                        && getComputedStyle(pane).display !== 'none' && pane.firstElementChild === retained && requests === beforeRequests);
                    check(label + 'the reopened ticket keeps its draft', draft.isConnected && draft.value === 'ZZ unsaved reply');
                    // A different ticket still loads normally and uses the saved preference.
                    draft.value = ''; property.value = 'original';
                    document.getElementById('popoutBackBtn').click(); await pause();
                    const beforeRefresh = requests;
                    await selectEmail(10, {refresh: true});
                    check(label + 'a mutation refresh loads data without leaving the queue', requests === beforeRefresh + 1
                        && !document.body.classList.contains('ticket-popout')
                        && (mode !== 'table' || !document.body.classList.contains('ticket-table-detail')));
                    await selectEmail(11);
                    check(label + 'the next ticket opens full screen', currentEmail.id === 11 && document.body.classList.contains('ticket-popout'));
                    document.querySelector('.ticket-popout-toggle').click();
                    check(label + 'the shrink icon still chooses the split view', !document.body.classList.contains('ticket-popout')
                        && localStorage.getItem('tickets_popout') === '0' && (mode !== 'table' || document.body.classList.contains('ticket-table-detail')));
                }
            }
        }
        toggleTicketPopout(); TicketTable.showDetail();
        const modal = document.createElement('div'); modal.className = 'modal active'; document.body.append(modal);
        document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
        check('Escape gives an open dialog priority', document.body.classList.contains('ticket-popout'));
        modal.remove();
        const input = document.querySelector('#readingPane select'); input.focus();
        document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true}));
        check('Escape leaves an active input alone', document.body.classList.contains('ticket-popout'));
        input.blur();
        ticketPendingWrites++;
        document.getElementById('popoutBackBtn').click(); await pause();
        check('Back waits for a pending save', document.body.classList.contains('ticket-popout') && document.body.classList.contains('ticket-table-detail'));
        ticketPendingWrites--;
        document.getElementById('popoutBackBtn').click(); await pause();
        window.frameElement.style.width = '1024px'; await pause();
        await selectEmail(11);
        check('a narrow desktop reopens the retained ticket full screen', document.body.classList.contains('ticket-popout')
            && document.body.classList.contains('ticket-table-narrow'));
        document.getElementById('popoutBackBtn').click(); await pause();
        check('narrow desktop Back returns directly to the table', !document.body.classList.contains('ticket-popout')
            && !document.body.classList.contains('ticket-table-detail') && localStorage.getItem('tickets_popout') === '1');
        // Ticket-id refreshes use the same view rule; only the fixture's static
        // queue repaint is isolated, while loading and the ticket renderer run.
        const renderList = window.renderEmailList;
        window.renderEmailList = () => {};
        try {
            await loadTicketById(102, {refresh: true});
            check('a ticket-id refresh keeps the queue open', !document.body.classList.contains('ticket-popout')
                && !document.body.classList.contains('ticket-table-detail'));
            await loadTicketById(101);
            check('an explicit ticket-id link restores full screen', currentEmail.ticket_id === 101 && document.body.classList.contains('ticket-popout'));
        } finally { window.renderEmailList = renderList; }
        document.getElementById('popoutBackBtn').click(); await pause();
        selectedEmailId = -101;
        displayEmail({...ticket, id: null}, []);
        const noEmailDraft = document.createElement('textarea');
        noEmailDraft.id = 'channelComposerText'; noEmailDraft.value = 'ZZ no-email draft';
        document.getElementById('readingPane').append(noEmailDraft);
        const beforeNoEmail = requests;
        await selectEmail(-101);
        check('a ticket without email reopens with its draft', document.body.classList.contains('ticket-popout')
            && requests === beforeNoEmail && noEmailDraft.isConnected && noEmailDraft.value === 'ZZ no-email draft');
        window.showConfirm = async () => false;
        await selectEmail(11);
        check('replacing a retained workspace still respects draft cancellation', currentEmail.ticket_id === 101
            && noEmailDraft.isConnected && noEmailDraft.value === 'ZZ no-email draft');
        check('no uncaught browser errors', errors.length === 0);
    } catch (error) {
        checks.push({label: 'navigation workflow completes', pass: false, detail: String(error) + '; startup errors: ' + JSON.stringify(errors)});
    }
    parent.postMessage(checks, location.origin);
})();
</script></body></html>
HTML;
$host = <<<'HTML'
<!doctype html><html><body style="margin:0"><pre id="result">RUNNING</pre>
<iframe src="fixture/tickets/index.html" style="width:100%;height:1000px;border:0"></iframe>
<script>addEventListener('message', event => {
    if (event.origin === location.origin && event.source === document.querySelector('iframe').contentWindow) {
        document.getElementById('result').textContent = JSON.stringify(event.data);
    }
});</script></body></html>
HTML;
exit(ticketWorkspaceBrowser(['fixture/tickets/index.html' => $page, 'index.html' => $host]));
