<?php
/** Shared permission-scoped ticket reads for the inbox, queue and CSV. */
require_once __DIR__.'/tenancy.php';
require_once __DIR__.'/ticket_snooze.php';
require_once __DIR__.'/inbox_view_filter.php';

/** Build the original inbox scope, optionally adding queue-only display columns. */
function ticketListBase(PDO $conn, int $analystId, array $request, bool $forTable = false): array
{
    // Get filter parameters
    $department_id = $request['department_id'] ?? null;
    $status = $request['status'] ?? null;
    $assignee_id = $request['assignee_id'] ?? null;
    $team_id     = $request['team_id'] ?? null;   // #1566

    // Pre-upgrade installs have no snooze columns; the busiest query in the
    // product must not name them until Database Verification has run.
    $snoozeReady = snoozeSchemaReady($conn);
    $snoozeCols  = $snoozeReady ? "t.snoozed_until, t.snooze_reason," : "NULL AS snoozed_until, NULL AS snooze_reason,";
    // Normal / Confidential (discussion #62) - same guard, for the same reason.
    require_once __DIR__ . '/ticket_sensitivity.php';
    $sensCol     = ticketSensitivityReady($conn) ? "t.sensitivity," : "'normal' AS sensitivity,";

    // The schedule travels with the row so the context menu can open Schedule on
    // ANY ticket, not just the one in the reading pane — right-clicking a row
    // that is not open is the normal case, and a round-trip per right-click to
    // fetch three columns we are already reading would be a poor trade.
    //
    // Guarded exactly as snooze is, and for the same reason: work_end_datetime /
    // work_all_day are new (#1161), and the busiest query in the product must not
    // name a column until Database Verification has run. work_start_datetime has
    // been there for years but is gated with them so there is ONE condition to
    // reason about rather than two overlapping ones.
    $schedReady = scheduleSchemaReady($conn);
    $schedCols  = $schedReady
        ? "t.work_start_datetime, t.work_end_datetime, t.work_all_day,"
        : "NULL AS work_start_datetime, NULL AS work_end_datetime, 0 AS work_all_day,";

    // Build query with filters - show only the most recent email per ticket
    $sql = "WITH LatestEmails AS (
                SELECT
                    e.id,
                    e.from_address,
                    e.from_name,
                    e.received_datetime,
                    e.body_preview,
                    e.is_read,
                    e.has_attachments,
                    e.importance,
                    e.ticket_id,
                    ROW_NUMBER() OVER (PARTITION BY e.ticket_id ORDER BY e.received_datetime DESC, e.id DESC) as rn
                FROM emails e
            )
            SELECT
                -- 🔑 A ROW STILL NEEDS A UNIQUE ID WHEN THERE IS NO EMAIL.
                -- The inbox keys selection, drag and the reading pane off this.
                -- Email ids are positive, so the negative ticket id can never
                -- collide with one, and its sign is what tells the front end to
                -- open the ticket directly instead of an email.
                COALESCE(le.id, -t.id) AS id,
                le.id AS email_id,
                -- With no email there is no sender, so the row falls back to the
                -- ticket's requester. A row showing a ticket number and then a
                -- blank sender would be worse than the bug this fixes.
                COALESCE(le.from_address, u.email) AS from_address,
                COALESCE(le.from_name, u.display_name, u.username) AS from_name,
                -- Who RAISED the ticket, as opposed to who spoke last (#143).
                -- Sent on every row regardless of the row-display setting, for
                -- the same reason the colours are: switching the choice is then
                -- instant rather than a reload. Costs nothing new — the users
                -- join was already here for the no-email fallback above.
                -- NULLIF(TRIM(...)) because a requester with a blank name must
                -- fall through to the next option rather than render an empty
                -- space where a person should be; 2 of 118 rows on the
                -- development install are in exactly that state.
                COALESCE(NULLIF(TRIM(u.display_name), ''), NULLIF(TRIM(u.username), '')) AS requester_name,
                u.email AS requester_address,
                COALESCE(le.received_datetime, t.created_datetime) AS received_datetime,
                le.body_preview,
                -- No email means nothing to have left unread.
                COALESCE(le.is_read, 1) AS is_read,
                COALESCE(le.has_attachments, 0) AS has_attachments,
                le.importance,
                t.id AS ticket_id,
                t.ticket_number,
                t.subject,
                ts.name AS status,
                t.department_id,
                t.assigned_analyst_id,
                -- #1566. So a drag onto a team folder can audit the team it came FROM.
                t.assigned_team_id,
                $snoozeCols
                $sensCol
                $schedCols
                tp.name AS priority,
                -- Row-display fields (discussion #61). The names and the analyst id
                -- were already selected here; only the colours and the analyst's
                -- display name are new, and both come off joins that already exist
                -- or cost one more. What the row actually SHOWS is decided per
                -- analyst — see includes/inbox_display.php — but the data is sent
                -- regardless so switching a chip on is instant rather than a reload.
                tp.colour AS priority_colour,
                ts.colour AS status_colour,
                aa.full_name AS assignee_name,
                -- The consolidated view (#1554). Always selected, rendered only
                -- when the analyst is looking at more than one company — one
                -- LEFT JOIN is cheaper than a second query shape to maintain.
                t.tenant_id,
                tn.name AS company_name,
                (SELECT COUNT(*) FROM emails WHERE ticket_id = t.id) as email_count
            -- 🔴 DRIVEN FROM `tickets`, NOT FROM `emails`.
            --
            -- This used to be `FROM LatestEmails le INNER JOIN tickets t`, which
            -- quietly made the inbox a list of EMAILS wearing a ticket's clothes:
            -- a ticket with no email row was counted in every folder badge and
            -- appeared in none of them, and could not be opened by any route.
            -- The folder said 99 and the list showed 96.
            --
            -- A ticket with no email is not exotic. A merge moves the emails to
            -- the surviving ticket; anything that removes the last email leaves
            -- one behind. The inbox lists TICKETS, so tickets is what it reads.
            FROM tickets t
            LEFT JOIN LatestEmails le ON le.ticket_id = t.id AND le.rn = 1
            LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
            LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
            LEFT JOIN analysts aa ON aa.id = t.assigned_analyst_id
            LEFT JOIN users u ON u.id = t.user_id
            LEFT JOIN tenants tn ON tn.id = t.tenant_id
            -- A merged-away ticket has been absorbed into another. It is kept, and
            -- kept findable by number, but it is not sitting in a queue. The old
            -- query dropped it only by accident (the merge took its emails); now
            -- it is excluded deliberately, and the counts exclude it to match.
            WHERE t.merged_into_id IS NULL";

    if ($forTable) {
        $sql = str_replace('t.id AS ticket_id,', 't.id AS ticket_id, t.created_datetime, t.status_id, t.ticket_type_id, t.priority_id, tp.display_order AS priority_order, qd.name AS department_name, qt.name AS team_name, qtt.name AS ticket_type_name,', $sql);
        $sql = str_replace('LEFT JOIN ticket_statuses ts', 'LEFT JOIN departments qd ON qd.id=t.department_id LEFT JOIN teams qt ON qt.id=t.assigned_team_id LEFT JOIN ticket_types qtt ON qtt.id=t.ticket_type_id LEFT JOIN ticket_statuses ts', $sql);
    }
    $params = [];

    if ($department_id === 'unassigned') {
        $sql .= " AND t.department_id IS NULL";
    } elseif ($department_id !== null && $department_id !== '') {
        $sql .= " AND t.department_id = ?";
        $params[] = $department_id;
    }

    if ($assignee_id === 'unassigned') {
        $sql .= " AND t.assigned_analyst_id IS NULL";
    } elseif ($assignee_id !== null && $assignee_id !== '') {
        $sql .= " AND t.assigned_analyst_id = ?";
        $params[] = $assignee_id;
    }

    // Which team owns the ticket (#1566).
    //
    // 🔴 A FILTER, NOT A PERMISSION. This narrows what is on screen; it does not
    // widen what the analyst may see. The tenancy predicate and the department
    // visibility rules above still apply exactly as they did — asking for one
    // team's queue can only ever return a subset of what you could already read.
    if ($team_id === 'unassigned') {
        $sql .= " AND t.assigned_team_id IS NULL";
    } elseif ($team_id !== null && $team_id !== '') {
        $sql .= " AND t.assigned_team_id = ?";
        $params[] = (int)$team_id;
    }

    if ($status !== null && $status !== '') {
        $sql .= " AND ts.name = ?";
        $params[] = $status;
    }

    // Multi-tenancy: scope the list to the analyst's active company (no-op at N=1).
    list($tenantSql, $tenantParams) = ticketTenantFilter($conn, $analystId);
    $sql .= $tenantSql;
    $params = array_merge($params, $tenantParams);

    // Trash: the Trash folder shows ONLY soft-deleted tickets; every other view
    // hides them.
    $sql .= !empty($request['trashed'])
        ? " AND t.deleted_datetime IS NOT NULL"
        : " AND t.deleted_datetime IS NULL";

    // Snoozed (#933): sleeping tickets leave every folder and gather in their own,
    // exactly as trashed ones do. The Trash view is excepted so a ticket that was
    // snoozed and then binned is still findable where it was put last.
    if (!empty($request['snoozed'])) {
        $sql .= snoozeOnlySql($conn, 't');
    } elseif (empty($request['trashed'])) {
        $sql .= snoozeHiddenSql($conn, 't');
    }

    // The view filter (#149) - "My tickets" / "Hide closed". The same helper the
    // folder counts use, so the list and its badge cannot disagree. Trash and
    // Snoozed are exempt on both sides: their counts are unfiltered too.
    if (empty($request['trashed']) && empty($request['snoozed'])) {
        $sql .= inboxViewFilterSql(['mine'=>!empty($request['mine']), 'hide_closed'=>!empty($request['hide_closed'])], $analystId, 't');
    }

    return [$sql, $params, $snoozeReady];
}

/** Keep the legacy timestamp, boolean and expired-snooze response semantics. */
function ticketListFormat(array $emails): array
{
    // Format dates for display
    foreach ($emails as &$email) {
        if ($email['received_datetime']) {
            $email['received_datetime'] = date('Y-m-d\TH:i:s', strtotime($email['received_datetime']));
        }
        // Convert bit fields to boolean
        $email['is_read'] = (bool)$email['is_read'];
        $email['has_attachments'] = (bool)$email['has_attachments'];
        // Only report a snooze that is still in the future — an expired one has
        // already woken, and a row badge saying "until last Tuesday" is noise.
        if (!empty($email['snoozed_until']) && strtotime($email['snoozed_until'] . ' UTC') <= time()) {
            $email['snoozed_until'] = null;
            $email['snooze_reason'] = null;
        }
    }

    return $emails;
}

/** Validate the internal queue contract before any SQL is built. */
function ticketListQuery(array $input): array
{
    $string = static function (string $key, string $default = '') use ($input): string {
        if (!isset($input[$key])) return $default;
        if (!is_scalar($input[$key])) throw new InvalidArgumentException('Invalid '.$key);
        return (string)$input[$key];
    };
    $page=$string('page','1'); $size=$string('page_size','50');
    if (!ctype_digit($page) || (int)$page<1 || (int)$page>10000000 || !in_array($size,['25','50','100'],true)) throw new InvalidArgumentException('Invalid page');
    $sort=$string('sort','created_datetime'); $dir=$string('dir','desc'); $view=$string('view','active');
    if (!isset(ticketListSorts()[$sort]) || !in_array($dir,['asc','desc'],true) || !in_array($view,['active','snoozed','trash'],true)) throw new InvalidArgumentException('Invalid list option');
    $filters=json_decode($string('filters','{}'),true,16,JSON_THROW_ON_ERROR);
    if (!is_array($filters) || count($filters)>6) throw new InvalidArgumentException('Invalid filters');
    foreach ($filters as $key=>&$values) {
        if (!isset(ticketListFilters()[$key]) || !is_array($values) || count($values)>200) throw new InvalidArgumentException('Invalid filter');
        foreach ($values as &$value) {
            if (!is_scalar($value) || ((string)$value!=='unassigned' && (!ctype_digit((string)$value) || (int)$value<1))) throw new InvalidArgumentException('Invalid filter value');
            $value=(string)$value;
        } unset($value);
        $values=array_values(array_unique($values));
    } unset($values);
    $bool=static function(string $key,string $default)use($string):bool{ $v=$string($key,$default); if(!in_array($v,['0','1'],true))throw new InvalidArgumentException('Invalid '.$key);return $v==='1'; };
    $q=trim($string('q'));if(mb_strlen($q)>500)throw new InvalidArgumentException('Search is too long');
    return ['page'=>(int)$page,'pageSize'=>(int)$size,'sort'=>['key'=>$sort,'dir'=>$dir],'search'=>$q,'filters'=>$filters,
        'context'=>['view'=>$view,'mine'=>$bool('mine','0'),'includeClosed'=>$bool('include_closed','1'),'searchContent'=>$bool('search_content','0')]];
}

/** Allow only sortable queue columns; never interpolate a caller-supplied column. */
function ticketListSorts(): array
{
    return ['ticket_number'=>'ticket_number','created_datetime'=>'created_datetime','priority'=>'priority_order',
        'status'=>'status','assigned_analyst_id'=>'assignee_name','work_start_datetime'=>'work_start_datetime',
        'work_end_datetime'=>'work_end_datetime','snoozed_until'=>'snoozed_until'];
}

/** Map the six supported facets to fields in the scoped row set. */
function ticketListFilters(): array
{
    return ['department_id'=>'department_id','team_id'=>'assigned_team_id','assignee_id'=>'assigned_analyst_id',
        'status_id'=>'status_id','ticket_type_id'=>'ticket_type_id','priority_id'=>'priority_id'];
}

/** The same scoped row set feeds pages, faceting and the complete export. */
function ticketListSql(PDO $conn, int $analystId, array $query, ?string $omitFilter=null): array
{
    $ctx=$query['context'];
    [$sql,$args]=ticketListBase($conn,$analystId,['trashed'=>$ctx['view']==='trash','snoozed'=>$ctx['view']==='snoozed',
        'mine'=>$ctx['mine'],'hide_closed'=>!$ctx['includeClosed']],true);
    if ($query['search']!=='') {
        if ($ctx['searchContent']) {
            require_once __DIR__.'/search/search.php';
            if(!searchCorpusReady($conn))throw new RuntimeException('Content search is unavailable');
            $parsed=searchParseQuery($query['search'],searchMinTokenSize($conn));
            if(!$parsed['terms']&&!$parsed['phrases'])throw new InvalidArgumentException('No searchable words; use longer words');
            [$scope,$scopeArgs]=searchScopeToSql(searchScopeForAnalyst($conn,$analystId,['require_ticket'=>true,'include_internal'=>true,'include_deleted'=>$ctx['view']==='trash']),'sd');
            $sql.=' AND EXISTS (SELECT 1 FROM search_documents sd WHERE sd.ticket_id=t.id AND MATCH(sd.title,sd.body) AGAINST (? IN BOOLEAN MODE)'.$scope.')';
            $args=array_merge($args,[$parsed['expr']],$scopeArgs);
        } else {
            $sql.=' AND (t.ticket_number LIKE ? OR t.subject LIKE ? OR u.email LIKE ? OR EXISTS (SELECT 1 FROM emails se WHERE se.ticket_id=t.id AND (se.subject LIKE ? OR se.from_address LIKE ? OR se.to_recipients LIKE ?)))';
            $args=array_merge($args,array_fill(0,6,'%'.$query['search'].'%'));
        }
    }
    foreach($query['filters'] as $key=>$values) {
        if($key===$omitFilter || !$values)continue;
        $column=['department_id'=>'t.department_id','team_id'=>'t.assigned_team_id','assignee_id'=>'t.assigned_analyst_id','status_id'=>'t.status_id','ticket_type_id'=>'t.ticket_type_id','priority_id'=>'t.priority_id'][$key];
        $nonnull=array_values(array_filter($values,fn($v)=>$v!=='unassigned'));
        $parts=[];
        if(count($nonnull)!==count($values))$parts[]="$column IS NULL";
        if($nonnull){$parts[]="$column IN (".implode(',',array_fill(0,count($nonnull),'?')).')';$args=array_merge($args,$nonnull);}
        $sql.=' AND ('.implode(' OR ',$parts).')';
    }
    return [$sql,$args];
}

/** Keep null dates last and use ticket ID as a stable pagination tie-breaker. */
function ticketListOrder(array $query): string
{
    $column=ticketListSorts()[$query['sort']['key']];
    return " ORDER BY ($column IS NULL) ASC, $column ".$query['sort']['dir'].', ticket_id DESC';
}

/** Read one page and global, self-excluding facet counts under the same scope. */
function ticketListRead(PDO $conn, int $analystId, array $query): array
{
    [$sql,$args]=ticketListSql($conn,$analystId,$query);
    $count=$conn->prepare("SELECT COUNT(*) FROM ($sql) queue");$count->execute($args);$total=(int)$count->fetchColumn();
    $page=min($query['page'],max(1,(int)ceil($total/$query['pageSize'])));
    $offset=($page-1)*$query['pageSize'];
    $st=$conn->prepare("SELECT * FROM ($sql) queue".ticketListOrder($query).' LIMIT '.$query['pageSize'].' OFFSET '.$offset);$st->execute($args);
    $rows=ticketListFormat($st->fetchAll(PDO::FETCH_ASSOC));
    foreach($rows as &$row)$row['inbox_id']=(int)$row['id'];unset($row);
    $facets=[];
    $lookups=['department_id'=>['departments','name'],'team_id'=>['teams','name'],'assignee_id'=>['analysts','full_name'],
        'status_id'=>['ticket_statuses','name'],'ticket_type_id'=>['ticket_types','name'],'priority_id'=>['ticket_priorities','name']];
    foreach(ticketListFilters() as $key=>$column) {
        [$facetSql,$facetArgs]=ticketListSql($conn,$analystId,$query,$key);
        [$table,$label]=$lookups[$key];
        $st=$conn->prepare("SELECT COALESCE(CAST(queue.$column AS CHAR),'unassigned') value, COALESCE(l.$label,'—') label, COUNT(*) count FROM ($facetSql) queue LEFT JOIN $table l ON l.id=queue.$column GROUP BY queue.$column,l.$label ORDER BY label");
        $st->execute($facetArgs);$facets[$key]=$st->fetchAll(PDO::FETCH_ASSOC);
    }
    return ['rows'=>$rows,'total'=>$total,'page'=>$page,'pageSize'=>$query['pageSize'],'facets'=>$facets];
}

/** Only fields offered in the queue can leave through its CSV endpoint. */
function ticketListExportColumns(): array
{
    return ['ticket_number'=>'ticket_number','subject'=>'subject','created_datetime'=>'created_datetime','priority_id'=>'priority',
        'status_id'=>'status','assignee_id'=>'assignee_name','department_id'=>'department_name','team_id'=>'team_name',
        'ticket_type_id'=>'ticket_type_name','work_start_datetime'=>'work_start_datetime','work_end_datetime'=>'work_end_datetime',
        'snoozed_until'=>'snoozed_until','company_name'=>'company_name'];
}

/** Prevent spreadsheet applications from interpreting exported text as a formula. */
function ticketListCsvCell($value): string
{
    $text=(string)($value??'');
    return preg_match('/^[\s\x00-\x1f]*[=+@\-]/u',$text) ? "'".$text : $text;
}
