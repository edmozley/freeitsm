<?php
/** Personal presentation only. A URL preview never writes a preference. */
function ticketWorkspaceResolve(?string $saved, ?string $requested): string
{
    $layouts = ['inbox', 'workspace'];
    if (in_array($requested, $layouts, true)) return $requested;
    return in_array($saved, $layouts, true) ? $saved : 'inbox';
}

function ticketWorkspaceLayout(PDO $conn, int $analystId, ?string $requested): string
{
    $stmt = $conn->prepare('SELECT preference_value FROM user_preferences WHERE analyst_id = ? AND preference_key = ?');
    $stmt->execute([$analystId, 'tickets_detail_layout']);
    $saved = $stmt->fetchColumn();
    return ticketWorkspaceResolve($saved === false ? null : (string)$saved, $requested);
}

function ticketWorkspaceSelector(string $layout): string
{
    $h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES);
    $html = '<span class="tw-choice"><label class="tw-layout-label" for="ticketLayoutSelect">' . $h(t('tickets.workspace.layout')) . '</label>';
    $html .= '<select id="ticketLayoutSelect" class="action-row-search" onchange="TicketWorkspace.requestMode(this.value)">';
    foreach (['inbox', 'workspace'] as $value) {
        $html .= '<option value="' . $value . '"' . ($layout === $value ? ' selected' : '') . '>' . $h(t('tickets.workspace.' . $value)) . '</option>';
    }
    return $html . '</select></span>';
}

/** Separate personal choice of list; the default remains the message list. */
function ticketListLayout(PDO $conn, int $analystId): string
{
    $stmt=$conn->prepare('SELECT preference_value FROM user_preferences WHERE analyst_id=? AND preference_key=?');
    $stmt->execute([$analystId,'tickets_list_layout']);
    return $stmt->fetchColumn()==='table'?'table':'messages';
}

function ticketListSelector(string $layout): string
{
    $html='<span class="tw-choice"><label class="tw-layout-label" for="ticketListSelect">'.htmlspecialchars(t('tickets.queue.layout')).'</label>';
    $html.='<select id="ticketListSelect" class="action-row-search" onchange="TicketTable.requestMode(this.value)">';
    foreach(['messages','table'] as $value) $html.='<option value="'.$value.'"'.($value===$layout?' selected':'').'>'.htmlspecialchars(t('tickets.queue.'.$value)).'</option>';
    return $html.'</select></span>';
}
