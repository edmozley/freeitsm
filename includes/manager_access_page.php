<?php
/**
 * Manager access - one manager's management lines, FULL SCREEN (discussion #62).
 *
 * WHY A PAGE AND NOT A MODAL
 * --------------------------
 * Ed: "some people will work with large organisations with lots of departments
 * and lots of users, so if we are making a picker to grant them access then this
 * should be full screen not in a modal." So: a page, searched and paged on the
 * server (api/tickets/manager_access.php), never "load everyone".
 *
 * ONE body, two doors - like the person editor. tickets/manager-access.php and
 * asset-management/manager-access.php each print their own module's header and
 * call managerAccessRender(); System -> Managers links to the Tickets one.
 *
 * Strings are resolved here, not exported: Assets pages do not export the
 * tickets namespace to JavaScript.
 */

/**
 * Print the page body (below the module header). Call once, inside <body>.
 *
 * @param int $managerId  the manager's users.id (the portal user whose access lines are shown)
 * @param string $backUrl  where the Back button goes; escaped when printed
 */
function managerAccessRender(int $managerId, string $backUrl): void
{
    $base = defined('BASE_URL') ? BASE_URL : '/';
    $keys = [
        'page_title', 'back', 'intro', 'off_banner', 'left_banner', 'needs_verify', 'readonly',
        'can_see_label', 'people', 'people_one', 'sources_heading', 'directory_direct', 'directory_all',
        'directory_note', 'directory_off', 'directory_label', 'lines_search', 'tab_empty', 'why_title', 'why_lead_one', 'why_lead', 'why_tree_label', 'why_kind_directory', 'why_kind_reports', 'why_kind_user', 'why_kind_group', 'why_kind_department', 'why_kind_everyone', 'why_direct', 'why_via', 'why_named', 'why_everyone_in', 'why_everyone_all', 'why_box_title', 'why_can_read', 'why_can_reply', 'why_cannot_reply', 'why_can_close', 'why_cannot_close', 'why_conf_none', 'why_conf_stub', 'why_conf_all', 'why_off', 'why_person_left', 'why_fix_title', 'why_fix_many', 'why_fix_line', 'why_fix_line_user', 'why_fix_line_group', 'why_fix_line_dept', 'why_fix_line_everyone', 'why_fix_line_reports', 'why_fix_dir', 'why_fix_dir_body', 'why_fix_dir_via', 'why_fix_excl', 'why_fix_excl_body', 'why_readonly', 'why_gone', 'open', 'lines_heading', 'lines_none', 'excl_heading', 'excl_desc',
        'excl_none', 'line_everyone', 'line_user', 'line_group', 'line_department', 'line_reports',
        'line_reports_all', 'line_gone_user', 'line_gone_group', 'line_meta', 'line_meta_nobody',
        'dept_empty', 'remove', 'add_heading', 'tab_people', 'tab_groups', 'tab_departments', 'tab_other',
        'search_people', 'search_groups', 'search_departments', 'add', 'exclude', 'is_added',
        'is_excluded', 'no_results', 'page_of', 'prev', 'next', 'everyone_title', 'everyone_desc',
        'everyone_desc_one', 'reports_title', 'reports_desc', 'reports_direct', 'reports_all',
        'team_heading', 'team_filter', 'team_more', 'team_none', 'flag_left', 'load_failed', 'save_failed',
    ];
    $T = [];
    foreach ($keys as $k) $T[$k] = t('tickets.manager_access.' . $k);
    ?>
    <style>
        /* THREE FULL-HEIGHT PANELS (Ed, after using it): find → what gives them
           access → who they can see, left to right. Each panel scrolls on its
           own, so adding a line never shoves the rest of the page down, and the
           page itself does not scroll at all on a desktop. Stacked on a phone. */
        body { display: flex; flex-direction: column; background: var(--app-bg, #f5f5f5); }
        .ma-scroll { flex: 1; min-height: 0; display: flex; flex-direction: column; }
        .ma-wrap { flex: 1; min-height: 0; display: flex; flex-direction: column; padding: 14px 24px 18px; box-sizing: border-box; }
        .ma-top { display: flex; align-items: center; justify-content: space-between; gap: 20px; margin-bottom: 12px; flex-wrap: wrap; }
        /* Back is a button (Ed) - still a link underneath, so it opens in a new tab and works without script. */
        .ma-back { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; margin-bottom: 8px; font-size: var(--font-size-md, 13px); text-decoration: none;
                   color: var(--text, #333); background: var(--surface, #fff); border: 1px solid var(--border, #ccc); border-radius: 4px; }
        .ma-back:hover { background: var(--surface-hover, #f3f3f3); border-color: var(--accent, #0078d4); }
        .ma-name { font-size: var(--font-size-4xl, 24px); font-weight: 600; margin: 0; color: var(--text, #333); }
        .ma-sub { color: var(--text-muted, #666); font-size: var(--font-size-md, 13px); margin-top: 2px; }
        .ma-stat { background: var(--surface, #fff); border: 1px solid var(--border, #e0e0e0); border-left: 4px solid var(--accent, #0078d4);
                   border-radius: 8px; padding: 8px 16px; min-width: 200px; }
        .ma-stat[hidden] { display: none; }
        .ma-stats { display: flex; gap: 12px; flex-wrap: wrap; align-items: stretch; }
        .ma-stat-note { font-size: var(--font-size-sm, 12px); color: var(--text-muted, #666); margin-top: 2px; max-width: 320px; }
        .ma-stat-label { font-size: var(--font-size-xs, 11px); color: var(--text-muted, #666); text-transform: uppercase; letter-spacing: .04em; }
        .ma-stat-num { font-size: var(--font-size-4xl, 24px); font-weight: 600; color: var(--text, #333); }
        .ma-banner { border-radius: 6px; padding: 8px 14px; margin-bottom: 10px; font-size: var(--font-size-md, 13px); border: 1px solid; }
        .ma-banner.info { background: var(--info-bg, #e8f4fd); border-color: var(--info-border, #b6d7f2); color: var(--info-text, #0b4f85); }
        .ma-banner.warn { background: var(--warning-bg, #fff4ce); border-color: var(--warning-border, #f0d78c); color: var(--warning-text, #6b5900); }
        .ma-banner a { color: inherit; font-weight: 600; }
        .ma-grid { flex: 1; min-height: 0; display: grid; gap: 16px;
                   grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr) minmax(260px, .7fr); }
        /* Read-only: no picker, so two panels. */
        .ma-grid.no-add { grid-template-columns: minmax(0, 1fr) minmax(260px, .6fr); }
        /* ⚠️ display:grid / display:flex BEAT the [hidden] attribute - say it again. */
        .ma-grid[hidden], .ma-card[hidden] { display: none; }
        .ma-card { background: var(--surface, #fff); border: 1px solid var(--border, #e0e0e0); border-radius: 8px;
                   display: flex; flex-direction: column; min-height: 0; overflow: hidden; }
        .ma-card-head { padding: 12px 16px; border-bottom: 1px solid var(--border-soft, #eee); font-weight: 600; font-size: var(--font-size-lg, 14px); color: var(--text, #333);
                        display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-shrink: 0; }
        .ma-step { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 50%;
                   background: var(--accent-soft, #e8f4fd); color: var(--accent, #0078d4); font-size: var(--font-size-xs, 11px); font-weight: 700; margin-right: 8px; }
        .ma-card-body { padding: 12px 16px; }
        /* The part of a panel that scrolls. Everything else in it stays put. */
        .ma-fill { flex: 1; min-height: 0; overflow: auto; }
        .ma-fixed { padding: 12px 16px 0; flex-shrink: 0; }
        .ma-fixed[hidden] { display: none; }
        .ma-foot { padding: 8px 16px; border-top: 1px solid var(--border-soft, #eee); flex-shrink: 0; }
        .ma-foot:empty { display: none; }
        .ma-sec { font-size: var(--font-size-sm, 12px); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--text-muted, #666); margin: 14px 0 6px; }
        .ma-sec:first-child { margin-top: 0; }
        .ma-sec small { text-transform: none; letter-spacing: 0; font-weight: 400; }
        .ma-line { display: flex; align-items: center; gap: 12px; padding: 9px 10px; border: 1px solid var(--border-soft, #eee); border-radius: 6px; margin-bottom: 6px; background: var(--surface-2, #fafafa); }
        .ma-line.excl { border-left: 3px solid var(--danger-accent, #c42b1c); }
        .ma-line.dir  { border-left: 3px solid var(--accent, #0078d4); }
        .ma-line-main { flex: 1; min-width: 0; }
        .ma-line-label { font-weight: 600; font-size: var(--font-size-md, 13px); color: var(--text, #333); overflow-wrap: anywhere; }
        .ma-line-meta { font-size: var(--font-size-sm, 12px); color: var(--text-muted, #666); margin-top: 2px; }
        .ma-line-warn { font-size: var(--font-size-sm, 12px); color: var(--warning-text, #6b5900); margin-top: 2px; }
        .ma-chip { font-size: var(--font-size-sm, 12px); padding: 2px 8px; border-radius: 10px; background: var(--accent-soft, #e8f4fd); color: var(--text, #333); white-space: nowrap; }
        .ma-empty { color: var(--text-muted, #666); font-size: var(--font-size-md, 13px); padding: 6px 0; }
        .ma-btn { padding: 5px 12px; font-size: var(--font-size-sm, 12px); border-radius: 4px; border: 1px solid var(--border, #ccc); background: var(--surface, #fff); color: var(--text, #333); cursor: pointer; white-space: nowrap; }
        .ma-btn:hover { background: var(--surface-hover, #f3f3f3); }
        .ma-btn.primary { background: var(--accent, #0078d4); border-color: var(--accent, #0078d4); color: var(--on-accent, #fff); }
        .ma-btn.primary:hover { background: var(--accent-hover, #106ebe); }
        .ma-btn:disabled { opacity: .5; cursor: default; }
        .ma-tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border-soft, #eee); padding: 0 12px; flex-shrink: 0; overflow-x: auto; }
        .ma-tab { background: none; border: 0; border-bottom: 2px solid transparent; padding: 10px 10px; font-size: var(--font-size-md, 13px); color: var(--text-muted, #666); cursor: pointer; white-space: nowrap; }
        span.ma-tab { cursor: default; display: inline-block; }
        .ma-tab { line-height: 18px; font-family: inherit; }   /* a <span> tab and a <button> tab the same height, so the three filters line up */
        .ma-tab.active { color: var(--accent, #0078d4); border-bottom-color: var(--accent, #0078d4); font-weight: 600; }
        .ma-search { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid var(--border, #ccc); border-radius: 4px; font-size: var(--font-size-md, 13px);
                     background: var(--surface, #fff); color: var(--text, #333); }
        .ma-table { width: 100%; border-collapse: collapse; font-size: var(--font-size-md, 13px); }
        .ma-table td { padding: 8px 6px; border-bottom: 1px solid var(--border-soft, #eee); vertical-align: middle; color: var(--text, #333); }
        .ma-table td.acts { text-align: right; white-space: nowrap; }
        .ma-table td.acts .ma-btn + .ma-btn { margin-left: 6px; }
        .ma-muted { color: var(--text-muted, #666); font-size: var(--font-size-sm, 12px); }
        .ma-state { font-size: var(--font-size-sm, 12px); font-weight: 600; }
        .ma-state.added { color: var(--success-text, #107c10); }
        .ma-state.excluded { color: var(--danger-text, #b3261e); }
        .ma-flag { font-size: var(--font-size-xs, 11px); padding: 1px 6px; border-radius: 8px; background: var(--warning-bg, #fff4ce); color: var(--warning-text, #6b5900); margin-left: 6px; }
        .ma-pager { display: flex; align-items: center; justify-content: space-between; font-size: var(--font-size-sm, 12px); color: var(--text-muted, #666); }
        .ma-pager .ma-btn + .ma-btn { margin-left: 6px; }
        .ma-option { border: 1px solid var(--border-soft, #eee); border-radius: 6px; padding: 12px; margin-bottom: 10px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .ma-option select { padding: 6px 8px; border: 1px solid var(--border, #ccc); border-radius: 4px; background: var(--surface, #fff); color: var(--text, #333); font-size: var(--font-size-md, 13px); }
        .ma-team { list-style: none; margin: 0; padding: 0; }
        .ma-team li { padding: 7px 8px; margin: 0 -8px; border-bottom: 1px solid var(--border-soft, #eee); font-size: var(--font-size-md, 13px); color: var(--text, #333);
                      cursor: pointer; border-radius: 4px; }
        .ma-team li:hover, .ma-team li:focus-visible { background: var(--surface-hover, #f3f3f3); outline: none; }
        .ma-team li.ma-empty { cursor: default; }
        .ma-team li.ma-empty:hover { background: none; }
        /* Always two lines, so every row is the same height (Ed). */
        .ma-team li .ma-muted { display: block; min-height: 1.3em; }

        /* ── "Why can they see this person?" - the same visual language as the
           Sign-in conflict explanation (system/analysts/index.php): a head with
           a round icon, a picture, an info box, then numbered ways out. ─── */
        #maWhy .modal-content { max-width: 820px; padding: 18px 22px 14px; box-sizing: border-box; max-height: 90vh; overflow-y: auto; }
        /* 13px = a step row's padding + border, so Close lines up under the buttons above it. */
        #maWhy .modal-actions { display: flex; justify-content: flex-end; padding: 0 13px 0 0; border: 0; }
        .mw-head { display: flex; gap: 14px; align-items: flex-start; margin-bottom: 12px; }
        .mw-head-icon { flex: 0 0 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            background: var(--info-bg, #e3f2fd); color: var(--info-text, #0d47a1); border: 1px solid var(--info-border, #90caf9); }
        .mw-head-icon svg { width: 22px; height: 22px; }
        .mw-head h3 { margin: 0 0 4px; font-size: var(--font-size-3xl, 20px); color: var(--text, #333); }
        .mw-head p { margin: 0; font-size: var(--font-size-md, 13px); line-height: 1.5; color: var(--text-muted, #666); }
        /* The picture, LEFT TO RIGHT (Ed: "use less vertical space"): the manager,
           a bar down to one card per reason stacked in the middle, a bar joining
           them again, the person. A grid, so each connector cell sits on the same
           row as its card and the lines meet whatever the cards' heights. */
        .mw-tree { background: var(--surface-2, #f7f9fa); border: 1px solid var(--border-soft, #eee); border-radius: 10px; padding: 10px 14px 12px; margin-bottom: 12px; }
        .mw-tree-label { font-size: var(--font-size-xs, 11px); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--text-faint, #999); text-align: center; margin-bottom: 8px; }
        .mw-grid { display: grid; grid-template-columns: 110px 14px 14px minmax(0, 1fr) 14px 14px 110px; align-items: stretch; }
        .mw-person { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 5px; grid-row: 1 / -1; text-align: center; }
        .mw-person.them { grid-column: 7; }
        .mw-avatar { width: 42px; height: 42px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: var(--font-size-xl, 16px); background: var(--accent, #546e7a); color: var(--on-accent, #fff); }
        .mw-avatar.them { background: var(--surface, #fff); color: var(--accent, #546e7a); border: 2px solid var(--accent, #546e7a); }
        .mw-person-name { font-weight: 600; font-size: var(--font-size-md, 13px); color: var(--text, #333); overflow-wrap: anywhere; }
        .mw-person-sub { font-size: var(--font-size-sm, 12px); color: var(--text-muted, #666); margin-top: -3px; }
        /* The stems: from a person to the bar, spanning every row, line at mid-height. */
        .mw-stem { position: relative; grid-row: 1 / -1; }
        .mw-stem::after { content: ''; position: absolute; left: 0; right: 0; top: calc(50% - 1px); height: 2px; background: var(--border, #ccc); }
        /* The connectors, one per row: a piece of the vertical bar plus a tick to the card. */
        .mw-conn { position: relative; }
        .mw-conn::after { content: ''; position: absolute; left: 0; right: 0; top: calc(50% - 1px); height: 2px; background: var(--border, #ccc); }
        .mw-conn::before { content: ''; position: absolute; top: 0; bottom: 0; width: 2px; background: var(--border, #ccc); }
        .mw-conn.l::before { left: 0; }
        .mw-conn.r::before { right: 0; }
        .mw-conn.first::before { top: 50%; }
        .mw-conn.last::before  { bottom: 50%; }
        .mw-conn.only::before  { display: none; }
        .mw-cell { padding: 3px 0; }
        .mw-reason { background: var(--surface, #fff); border: 1px solid var(--border, #ddd); border-left: 3px solid var(--mw-tone, var(--accent, #0078d4));
            border-radius: 8px; padding: 7px 10px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; text-align: center; box-sizing: border-box; height: 100%; }
        .mw-kind { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 12px; font-size: var(--font-size-sm, 12px); font-weight: 600;
            background: var(--mw-tone-bg, var(--accent-soft, #e8f4fd)); color: var(--mw-tone, var(--accent, #0078d4)); }
        .mw-kind svg { width: 13px; height: 13px; flex: 0 0 13px; }
        .mw-reason-name { font-weight: 600; font-size: var(--font-size-md, 13px); color: var(--text, #333); overflow-wrap: anywhere; }
        .mw-reason-sub { font-size: var(--font-size-sm, 12px); color: var(--text-muted, #666); overflow-wrap: anywhere; }
        .mw-reason.directory, .mw-reason.reports { --mw-tone: var(--accent, #0078d4);        --mw-tone-bg: var(--accent-soft, #e8f4fd); }
        .mw-reason.department { --mw-tone: var(--success-text, #2e7d32); --mw-tone-bg: var(--success-bg, #e8f5e9); }
        .mw-reason.group      { --mw-tone: var(--info-text, #0d47a1);    --mw-tone-bg: var(--info-bg, #e3f2fd); }
        .mw-reason.user       { --mw-tone: var(--text, #333);            --mw-tone-bg: var(--surface-2, #f0f0f0); }
        .mw-reason.everyone   { --mw-tone: var(--warning-text, #e65100); --mw-tone-bg: var(--warning-bg, #fff3e0); }
        /* The info boxes share a row. */
        .mw-boxes { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .mw-box { border-radius: 8px; padding: 10px 12px; margin: 0; display: flex; gap: 10px; align-items: flex-start; font-size: var(--font-size-md, 13px); line-height: 1.5; }
        .mw-box svg { width: 18px; height: 18px; flex: 0 0 18px; margin-top: 1px; }
        .mw-box strong { display: block; margin-bottom: 2px; }
        .mw-box.info { background: var(--info-bg, #e3f2fd); color: var(--info-text, #0d47a1); border: 1px solid var(--info-border, #90caf9); }
        .mw-box.warn { background: var(--warning-bg, #fff3e0); color: var(--warning-text, #e65100); border: 1px solid var(--warning-border, #ffcc80); }
        .mw-fix-title { font-size: var(--font-size-md, 13px); font-weight: 600; color: var(--text, #333); margin: 0 0 4px; }
        .mw-fix-note { font-size: var(--font-size-sm, 12px); color: var(--text-muted, #666); margin: 0 0 6px; }
        .mw-fix { display: flex; gap: 12px; align-items: center; padding: 7px 12px; border: 1px solid var(--border-soft, #eee); border-radius: 8px; margin-bottom: 6px; background: var(--surface, #fff); }
        .mw-fix-num { flex: 0 0 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: var(--font-size-sm, 12px); font-weight: 700;
            background: var(--success-bg, #e8f5e9); color: var(--success-text, #2e7d32); border: 1px solid var(--success-border, #a5d6a7); }
        .mw-fix-text { flex: 1; font-size: var(--font-size-md, 13px); line-height: 1.4; color: var(--text-muted, #666); }
        .mw-fix-text strong { display: block; color: var(--text, #333); }
        /* Open, Remove, Exclude and Close: one size, whatever the word or element (Ed). */
        .mw-fix .btn, #mwClose { flex: 0 0 auto; box-sizing: border-box; width: 96px; height: 34px; padding: 0 10px; font-size: var(--font-size-md, 13px); line-height: 1;
            display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
        .mw-gone { text-align: center; padding: 20px 10px; font-size: var(--font-size-lg, 14px); color: var(--success-text, #2e7d32); }
        @media (max-width: 560px) {
            /* A phone has no room across: the grid collapses to one column. */
            .mw-grid { grid-template-columns: 1fr; }
            .mw-person, .mw-person.them { grid-row: auto; grid-column: auto; }
            .mw-stem, .mw-conn { display: none; }
            .mw-fix { flex-wrap: wrap; }
            .mw-fix .btn { margin-left: 38px; }
        }
        /* Tablet and phone: one column, the page scrolls, panels take their height. */
        @media (max-width: 1100px) {
            .ma-scroll { display: block; overflow: auto; }
            .ma-wrap { display: block; padding: 16px; }
            .ma-grid, .ma-grid.no-add { display: block; }
            .ma-card { margin-bottom: 16px; overflow: visible; }
            .ma-fill { overflow: visible; }
        }
    </style>

    <div class="ma-scroll">
    <div class="ma-wrap">
        <div class="ma-top">
            <div>
                <a class="ma-back" href="<?php echo htmlspecialchars($backUrl); ?>">&larr; <?php echo htmlspecialchars($T['back']); ?></a>
                <h1 class="ma-name" id="maName"><?php echo htmlspecialchars($T['page_title']); ?></h1>
                <div class="ma-sub" id="maSub"></div>
            </div>
            <div class="ma-stats">
                <?php /* What the Manager field alone gives them - it is not a line, and
                         cannot be changed here, so it sits with the totals (Ed). */ ?>
                <div class="ma-stat" id="maDir" hidden></div>
                <div class="ma-stat" id="maStat" hidden>
                    <div class="ma-stat-label"><?php echo htmlspecialchars($T['can_see_label']); ?></div>
                    <div class="ma-stat-num" id="maStatNum"></div>
                </div>
            </div>
        </div>
        <div id="maBanners"></div>

        <div class="ma-grid" id="maGrid" hidden>
            <?php /* 1. Find people to add. Tabs, search and pager stay put; only the results scroll. */ ?>
            <div class="ma-card" id="maAddCard" hidden>
                <div class="ma-card-head"><span><span class="ma-step">1</span><?php echo htmlspecialchars($T['add_heading']); ?></span></div>
                <div class="ma-tabs" role="tablist">
                    <button type="button" class="ma-tab active" data-kind="user"><?php echo htmlspecialchars($T['tab_people']); ?></button>
                    <button type="button" class="ma-tab" data-kind="group"><?php echo htmlspecialchars($T['tab_groups']); ?></button>
                    <button type="button" class="ma-tab" data-kind="department"><?php echo htmlspecialchars($T['tab_departments']); ?></button>
                    <button type="button" class="ma-tab" data-kind="other"><?php echo htmlspecialchars($T['tab_other']); ?></button>
                </div>
                <div class="ma-fixed" id="maSearchBar"><input type="search" class="ma-search" id="maQ" autocomplete="off"></div>
                <div class="ma-card-body ma-fill" id="maResultsScroll">
                    <div id="maResults"></div>
                    <div id="maOtherPane" hidden></div>
                </div>
                <div class="ma-foot" id="maPager"></div>
            </div>

            <?php /* 2. What gives them access. */ ?>
            <div class="ma-card">
                <div class="ma-card-head"><span><span class="ma-step" id="maStep2">2</span><?php echo htmlspecialchars($T['sources_heading']); ?></span></div>
                <?php /* Tabs and a search box in the same place as panel 1's, so the
                         two line up and read as one pair. Filled by paintSources(). */ ?>
                <div class="ma-tabs" role="tablist" id="maLineTabs"></div>
                <div class="ma-fixed"><input type="search" class="ma-search" id="maLineQ" placeholder="<?php echo htmlspecialchars($T['lines_search']); ?>" autocomplete="off"></div>
                <div class="ma-card-body ma-fill" id="maSources"></div>
            </div>

            <?php /* 3. The result: who they can see. */ ?>
            <div class="ma-card">
                <div class="ma-card-head"><span><span class="ma-step" id="maStep3">3</span><?php echo htmlspecialchars($T['team_heading']); ?></span></div>
                <?php /* One tab, carrying the count - there is nothing to switch to, but
                         it keeps this panel's filter level with the other two. */ ?>
                <div class="ma-tabs"><span class="ma-tab active" id="maTeamCount"></span></div>
                <div class="ma-fixed"><input type="search" class="ma-search" id="maTeamFilter" placeholder="<?php echo htmlspecialchars($T['team_filter']); ?>" autocomplete="off"></div>
                <div class="ma-card-body ma-fill"><ul class="ma-team" id="maTeam"></ul></div>
                <div class="ma-foot ma-muted" id="maTeamMore"></div>
            </div>
        </div>
    </div>
    </div>

    <?php /* Why can this manager see this person? Filled by openWhy(). */ ?>
    <div class="modal" id="maWhy" role="dialog" aria-modal="true" aria-labelledby="mwTitle">
        <div class="modal-content">
            <div id="mwBody"></div>
            <div class="modal-actions" style="margin-top: 16px;">
                <button type="button" class="btn btn-secondary" id="mwClose"><?php echo htmlspecialchars(t('common.close')); ?></button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const API = <?php echo json_encode($base . 'api/tickets/manager_access.php'); ?>;
        const SYSTEM_URL = <?php echo json_encode($base . 'system/managers/'); ?>;
        const MANAGER = <?php echo (int)$managerId; ?>;
        const T = <?php echo json_encode($T, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
        const $ = id => document.getElementById(id);
        const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };
        const fmt = (s, p) => String(s).replace(/\{(\w+)\}/g, (_, k) => (p && k in p) ? p[k] : '{' + k + '}');
        const people = n => Number(n) === 1 ? T.people_one : fmt(T.people, { count: n });
        const toast = (m, k) => { if (typeof window.showToast === 'function') window.showToast(m, k); };
        // Stored in UTC (#126); shown as a date in the analyst's own zone.
        const day = s => {
            if (!s) return '';
            const d = new Date(String(s).replace(' ', 'T') + 'Z');
            return isNaN(d) ? s : d.toLocaleDateString();
        };

        let S = null;                  // the manager's state from the server
        let kind = 'user', page = 1, timer = null, seq = 0;
        let lastSearch = null;         // the results on screen, kept so a write can re-mark them in place
        let lineKind = 'user';         // panel 2's tab: user | group | department | other
        // Which tab a line belongs in. Everyone and the reporting line share one,
        // as they do in panel 1.
        const tabOf = t => (t === 'everyone' || t === 'reports') ? 'other' : t;

        async function call(params, body) {
            const r = body
                ? await fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                                     body: JSON.stringify(Object.assign({ manager: MANAGER }, body)) })
                : await fetch(API + '?' + new URLSearchParams(Object.assign({ manager: MANAGER }, params)), { credentials: 'same-origin' });
            return r.json();
        }

        function lineLabel(l) {
            switch (l.grant_type) {
                case 'everyone':   return T.line_everyone;
                case 'user':       return l.label == null ? T.line_gone_user : fmt(T.line_user, { name: l.label });
                case 'group':      return l.label == null ? T.line_gone_group : fmt(T.line_group, { name: l.label });
                case 'department': return fmt(T.line_department, { name: l.target_value });
                case 'reports':    return l.target_value === 'all' ? T.line_reports_all : T.line_reports;
            }
            return l.grant_type;
        }

        function lineHtml(l) {
            const meta = l.added_by ? fmt(T.line_meta, { name: l.added_by, date: day(l.added) }) : fmt(T.line_meta_nobody, { date: day(l.added) });
            const warn = (l.grant_type === 'department' && !l.members) ? '<div class="ma-line-warn">⚠ ' + esc(T.dept_empty) + '</div>' : '';
            return '<div class="ma-line' + (l.is_exclusion ? ' excl' : '') + '">'
                 + '<div class="ma-line-main"><div class="ma-line-label">' + esc(l._name ?? lineLabel(l)) + '</div>'
                 + '<div class="ma-line-meta">' + esc(meta) + '</div>' + warn + '</div>'
                 + '<span class="ma-chip">' + esc(people(l.members)) + '</span>'
                 + (S.can_edit ? '<button type="button" class="ma-btn" data-remove="' + l.id + '">' + esc(T.remove) + '</button>' : '')
                 + '</div>';
        }

        function paint() {
            const m = S.manager;
            document.title = (document.titleBase = document.titleBase || document.title) + ' - ' + m.name;   // GH #173: appended to the server's pageTitle()
            $('maName').textContent = m.name;
            $('maSub').textContent = [m.job_title, m.department, m.company, m.email].filter(Boolean).join(' · ');

            let b = '';
            if (S.needs_verify) b += '<div class="ma-banner warn">' + esc(T.needs_verify) + '</div>';
            else {
                if (!m.is_active) b += '<div class="ma-banner warn">' + esc(fmt(T.left_banner, { name: m.name })) + '</div>';
                if (!S.settings.enabled) {
                    const txt = esc(fmt(T.off_banner, { name: m.name }));
                    b += '<div class="ma-banner info">' + (S.is_admin ? txt.replace('System → Managers', '<a href="' + SYSTEM_URL + '">System → Managers</a>') : txt) + '</div>';
                }
                if (!S.can_edit) b += '<div class="ma-banner info">' + esc(T.readonly) + '</div>';
            }
            $('maBanners').innerHTML = b;
            if (S.needs_verify) { $('maGrid').hidden = true; $('maStat').hidden = true; $('maDir').hidden = true; return; }

            $('maStat').hidden = false;
            $('maStatNum').textContent = people(S.team_total);
            $('maDir').hidden = false;
            $('maDir').innerHTML = S.settings.directory
                ? '<div class="ma-stat-label">' + esc(S.settings.directory_depth === 'all' ? T.directory_all : T.directory_direct) + '</div>'
                  + '<div class="ma-stat-num">' + esc(people(S.reports)) + '</div>'
                  + '<div class="ma-stat-note">' + esc(T.directory_note) + '</div>'
                : '<div class="ma-stat-label">' + esc(T.directory_label) + '</div>'
                  + '<div class="ma-stat-note">' + esc(T.directory_off) + '</div>';
            $('maGrid').hidden = false;
            $('maAddCard').hidden = !S.can_edit;
            // Read-only: no picker, so two panels, numbered from 1.
            $('maGrid').classList.toggle('no-add', !S.can_edit);
            $('maStep2').textContent = S.can_edit ? '2' : '1';
            $('maStep3').textContent = S.can_edit ? '3' : '2';
            $('maTeamCount').textContent = T.tab_people + ' (' + S.team_total + ')';

            paintSources();

            paintTeam();
            // After a write, redraw the results we already have with their new
            // Added / Excluded marks - never fetch them again. A refetch replaced
            // the list under the pointer and lost its scroll position (Ed: "things
            // jump about the page as you add/remove things").
            if (S.can_edit) { if (kind === 'other') paintOther(); else if (lastSearch) renderResults(lastSearch); else search(); }
        }

        // Panel 2: one tab per kind of line, each with its count, sorted by name;
        // exclusions of that kind listed under their own heading in the same tab.
        function paintSources() {
            const tabs = [['user', T.tab_people], ['group', T.tab_groups], ['department', T.tab_departments], ['other', T.tab_other]];
            const count = k => S.lines.filter(l => tabOf(l.grant_type) === k).length;
            // Everyone / reporting line only when there is one, or it is open.
            $('maLineTabs').innerHTML = tabs
                .filter(([k]) => k !== 'other' || count('other') || lineKind === 'other')
                .map(([k, label]) => '<button type="button" class="ma-tab' + (k === lineKind ? ' active' : '') + '" data-lkind="' + k + '">'
                    + esc(label) + ' (' + count(k) + ')</button>').join('');

            // Inside a tab the kind is already said, so a department is just its name.
            const name = l => (l.grant_type === 'group' || l.grant_type === 'department')
                ? (l.grant_type === 'group' ? (l.label ?? T.line_gone_group) : l.target_value)
                : lineLabel(l);
            const q = $('maLineQ').value.trim().toLowerCase();
            const mine = S.lines
                .filter(l => tabOf(l.grant_type) === lineKind)
                .filter(l => !q || name(l).toLowerCase().includes(q))
                .sort((x, y) => name(x).localeCompare(name(y), undefined, { sensitivity: 'base' }));
            const row = l => lineHtml(Object.assign({}, l, { _name: name(l) }));
            const grants = mine.filter(l => !l.is_exclusion), excl = mine.filter(l => l.is_exclusion);

            let h = grants.length ? grants.map(row).join('')
                  : '<div class="ma-empty">' + esc(q ? T.no_results : T.tab_empty) + '</div>';
            if (excl.length) {
                h += '<div class="ma-sec">' + esc(T.excl_heading) + ' <small>- ' + esc(T.excl_desc) + '</small></div>'
                   + excl.map(row).join('');
            }
            $('maSources').innerHTML = h;
        }

        function paintTeam() {
            const q = $('maTeamFilter').value.trim().toLowerCase();
            const list = S.team.filter(p => !q || (p.name || '').toLowerCase().includes(q) || (p.department || '').toLowerCase().includes(q));
            $('maTeam').innerHTML = list.length
                ? list.map(p => '<li tabindex="0" data-person="' + p.id + '">' + esc(p.name) + (p.is_active ? '' : '<span class="ma-flag">' + esc(T.flag_left) + '</span>')
                    + '<span class="ma-muted">' + (p.department ? esc(p.department) : '&nbsp;') + '</span></li>').join('')
                : '<li class="ma-empty">' + esc(T.team_none) + '</li>';
            $('maTeamMore').textContent = S.team_total > S.team.length ? fmt(T.team_more, { shown: S.team.length, total: S.team_total }) : '';
        }

        // What this row already is, so it is never added twice.
        function existing(k, row) {
            return S.lines.find(l => l.grant_type === k && (k === 'department'
                ? String(l.target_value).trim().toLowerCase() === String(row.name).trim().toLowerCase()
                : l.target_id === row.id));
        }

        async function search() {
            const mine = ++seq;
            const ph = { user: T.search_people, group: T.search_groups, department: T.search_departments }[kind];
            $('maQ').placeholder = ph;
            const d = await call({ action: 'search', kind: kind, q: $('maQ').value.trim(), page: page });
            if (mine !== seq) return;                       // a newer search has started
            lastSearch = d.success ? d : null;
            $('maResultsScroll').scrollTop = 0;             // a NEW list starts at the top
            if (!d.success) { $('maResults').innerHTML = '<div class="ma-empty">' + esc(d.error || '') + '</div>'; $('maPager').innerHTML = ''; return; }
            renderResults(d);
        }

        function renderResults(d) {
            if (!d.rows.length) { $('maResults').innerHTML = '<div class="ma-empty">' + esc(T.no_results) + '</div>'; $('maPager').innerHTML = ''; return; }
            const rows = d.rows.map((r, i) => {
                let main;
                if (kind === 'user') {
                    main = '<strong>' + esc(r.name) + '</strong>' + (r.is_active ? '' : '<span class="ma-flag">' + esc(T.flag_left) + '</span>')
                         + '<div class="ma-muted">' + esc([r.job_title, r.department, r.email].filter(Boolean).join(' · ')) + '</div>';
                } else if (kind === 'group') {
                    main = '<strong>' + esc(r.name) + '</strong>' + (r.description ? '<div class="ma-muted">' + esc(r.description) + '</div>' : '');
                } else {
                    main = '<strong>' + esc(r.name) + '</strong>';
                }
                const count = kind === 'user' ? '' : '<span class="ma-chip">' + esc(people(r.members)) + '</span>';
                const ex = existing(kind, r);
                const acts = ex
                    ? '<span class="ma-state ' + (ex.is_exclusion ? 'excluded">' + esc(T.is_excluded) : 'added">' + esc(T.is_added)) + '</span>'
                    : '<button type="button" class="ma-btn primary" data-add="' + i + '">' + esc(T.add) + '</button>'
                    + '<button type="button" class="ma-btn" data-exclude="' + i + '">' + esc(T.exclude) + '</button>';
                return '<tr><td>' + main + '</td><td>' + count + '</td><td class="acts">' + acts + '</td></tr>';
            }).join('');
            const pages = Math.max(1, Math.ceil(d.total / d.per_page));
            $('maResults').innerHTML = '<table class="ma-table"><tbody>' + rows + '</tbody></table>';
            $('maPager').innerHTML = '<div class="ma-pager"><span>' + esc(fmt(T.page_of, { page: d.page, pages: pages, total: d.total })) + '</span><span>'
                + '<button type="button" class="ma-btn" data-page="' + (d.page - 1) + '"' + (d.page <= 1 ? ' disabled' : '') + '>' + esc(T.prev) + '</button>'
                + '<button type="button" class="ma-btn" data-page="' + (d.page + 1) + '"' + (d.page >= pages ? ' disabled' : '') + '>' + esc(T.next) + '</button>'
                + '</span></div>';
            $('maResults')._rows = d.rows;
        }

        function paintOther() {
            const has = t => S.lines.find(l => !l.is_exclusion && l.grant_type === t);
            const everyone = has('everyone'), reports = has('reports');
            const desc = S.manager.company ? fmt(T.everyone_desc, { company: S.manager.company }) : T.everyone_desc_one;
            $('maOtherPane').innerHTML =
                  '<div class="ma-option"><div class="ma-line-main"><div class="ma-line-label">' + esc(T.everyone_title) + '</div>'
                + '<div class="ma-line-meta">' + esc(desc) + '</div></div>'
                + (everyone ? '<span class="ma-state added">' + esc(T.is_added) + '</span>'
                            : '<button type="button" class="ma-btn primary" data-other="everyone">' + esc(T.add) + '</button>') + '</div>'
                + '<div class="ma-option"><div class="ma-line-main"><div class="ma-line-label">' + esc(T.reports_title) + '</div>'
                + '<div class="ma-line-meta">' + esc(T.reports_desc) + '</div></div>'
                + '<select id="maDepth"><option value="">' + esc(T.reports_direct) + '</option><option value="all">' + esc(T.reports_all) + '</option></select>'
                + '<button type="button" class="ma-btn primary" data-other="reports">' + esc(T.add) + '</button></div>';
            if (reports) $('maDepth').value = reports.target_value === 'all' ? 'all' : '';
        }

        async function write(body) {
            if (body.action === 'add') { lineKind = tabOf(body.grant_type); $('maLineQ').value = ''; }
            const d = await call(null, body);
            if (!d.success) { toast(fmt(T.save_failed, { error: d.error || '' }), 'error'); return; }
            S = d;
            paint();
        }

        document.addEventListener('click', e => {
            const t = e.target.closest('button');
            if (!t) return;
            if (t.dataset.lkind) { lineKind = t.dataset.lkind; paintSources(); return; }
            if (t.classList.contains('ma-tab')) {
                document.querySelectorAll('.ma-tab[data-kind]').forEach(x => x.classList.toggle('active', x === t));
                kind = t.dataset.kind; page = 1;
                // Looking for departments to add? Show the departments they have.
                lineKind = kind; $('maLineQ').value = ''; if (S) paintSources();
                $('maSearchBar').hidden = kind === 'other';
                $('maResults').hidden = kind === 'other';
                $('maOtherPane').hidden = kind !== 'other';
                $('maPager').innerHTML = '';
                lastSearch = null;
                if (kind === 'other') paintOther(); else { $('maQ').value = ''; $('maResults').innerHTML = ''; search(); }
                return;
            }
            if (t.dataset.remove) { write({ action: 'remove', line_id: Number(t.dataset.remove) }); return; }
            if (t.dataset.page)   { page = Number(t.dataset.page); search(); return; }
            if (t.dataset.add !== undefined || t.dataset.exclude !== undefined) {
                const row = $('maResults')._rows[Number(t.dataset.add ?? t.dataset.exclude)];
                t.disabled = true;
                write({ action: 'add', grant_type: kind, target_id: row.id || 0, target_value: kind === 'department' ? row.name : '',
                        is_exclusion: t.dataset.exclude !== undefined });
                return;
            }
            if (t.dataset.other === 'everyone') { write({ action: 'add', grant_type: 'everyone' }); return; }
            if (t.dataset.other === 'reports')  { write({ action: 'add', grant_type: 'reports', target_value: $('maDepth').value }); return; }
        });
        $('maQ').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => { page = 1; search(); }, 250); });
        $('maTeamFilter').addEventListener('input', paintTeam);
        $('maLineQ').addEventListener('input', paintSources);

        // ── Why can they see this person? ───────────────────────────────────
        // Click a name in "Who they can see". The server lists every reason
        // (manager_access.php?action=why); this draws them the way the Sign-in
        // conflict explanation draws a clash: the manager on top, a branch per
        // reason, joining again at the person - then what the manager can do,
        // and one numbered way out per reason, plus Exclude, which beats them all.
        const ICON = {
            directory:  '<path d="M12 3v6M6 15v-3h12v3"/><circle cx="12" cy="3" r="1"/><rect x="3" y="15" width="6" height="5" rx="1"/><rect x="15" y="15" width="6" height="5" rx="1"/>',
            reports:    '<path d="M12 3v6M6 15v-3h12v3"/><circle cx="12" cy="3" r="1"/><rect x="3" y="15" width="6" height="5" rx="1"/><rect x="15" y="15" width="6" height="5" rx="1"/>',
            user:       '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
            group:      '<circle cx="9" cy="8" r="3.5"/><path d="M2 20v-1a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v1"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M22 20v-1a5 5 0 0 0-4-4.9"/>',
            department: '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M9 15h2M13 15h2M10 21v-3h4v3"/>',
            everyone:   '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        };
        const svg = p => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + p + '</svg>';
        const initials = n => String(n || '?').trim().split(/\s+/).slice(0, 2).map(w => w[0]).join('').toUpperCase();
        let whyPerson = null;

        function reasonCard(r, W) {
            let name = '', sub = '';
            if (r.kind === 'directory' || r.kind === 'reports') {
                name = r.via.length ? fmt(T.why_via, { names: r.via.join(' → ') }) : T.why_direct;
                sub  = r.kind === 'directory' ? '' : (r.all ? T.line_reports_all : T.line_reports);
            } else if (r.kind === 'user')       { name = T.why_named; }
            else if (r.kind === 'everyone')     { name = S.manager.company ? fmt(T.why_everyone_in, { company: S.manager.company }) : T.why_everyone_all; }
            else                                { name = r.name || (r.kind === 'group' ? T.line_gone_group : ''); }
            return '<div class="mw-reason ' + r.kind + '">'
                 + '<span class="mw-kind">' + svg(ICON[r.kind] || '') + esc(T['why_kind_' + r.kind] || r.kind) + '</span>'
                 + '<div class="mw-reason-name">' + esc(name) + '</div>'
                 + (sub ? '<div class="mw-reason-sub">' + esc(sub) + '</div>' : '')
                 + '</div>';
        }

        function fixRow(n, title, body, button) {
            return '<div class="mw-fix"><div class="mw-fix-num">' + n + '</div>'
                 + '<div class="mw-fix-text"><strong>' + esc(title) + '</strong>' + esc(body) + '</div>' + (button || '') + '</div>';
        }

        function paintWhy(W) {
            const mName = S.manager.name, pName = W.person.name;
            const P = { manager: mName, person: pName };
            let h = '<div class="mw-head"><div class="mw-head-icon">'
                  + svg('<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>') + '</div>'
                  + '<div><h3 id="mwTitle">' + esc(fmt(T.why_title, P)) + '</h3>';
            if (!W.on_team || !W.reasons.length) {
                $('mwBody').innerHTML = h + '</div></div><div class="mw-gone">' + esc(fmt(T.why_gone, P)) + '</div>';
                return;
            }
            const n = W.reasons.length;
            h += '<p>' + esc(n === 1 ? fmt(T.why_lead_one, P) : fmt(T.why_lead, Object.assign({ count: n }, P))) + '</p></div></div>';

            // The picture, left to right: manager | stem | bar | reasons | bar | stem | person.
            const pos = i => n === 1 ? ' only' : (i === 0 ? ' first' : (i === n - 1 ? ' last' : ''));
            let cells = '<div class="mw-person" style="grid-column:1"><div class="mw-avatar">' + esc(initials(mName)) + '</div><div class="mw-person-name">' + esc(mName) + '</div></div>'
                      + '<div class="mw-stem" style="grid-column:2"></div>'
                      + '<div class="mw-stem" style="grid-column:6"></div>'
                      + '<div class="mw-person them"><div class="mw-avatar them">' + esc(initials(pName)) + '</div><div class="mw-person-name">' + esc(pName) + '</div>'
                      + (W.person.department ? '<div class="mw-person-sub">' + esc(W.person.department) + '</div>' : '') + '</div>';
            W.reasons.forEach((r, i) => {
                const row = 'grid-row:' + (i + 1);
                cells += '<div class="mw-conn l' + pos(i) + '" style="grid-column:3;' + row + '"></div>'
                       + '<div class="mw-cell" style="grid-column:4;' + row + '">' + reasonCard(r, W) + '</div>'
                       + '<div class="mw-conn r' + pos(i) + '" style="grid-column:5;' + row + '"></div>';
            });
            h += '<div class="mw-tree"><div class="mw-tree-label">' + esc(T.why_tree_label) + '</div>'
               + '<div class="mw-grid" style="grid-template-rows:repeat(' + n + ', auto)">' + cells + '</div></div>';

            // What that means.
            const st = W.settings;
            const does = [fmt(T.why_can_read, P), st.can_reply ? T.why_can_reply : T.why_cannot_reply,
                          st.can_close ? T.why_can_close : T.why_cannot_close, T['why_conf_' + st.confidential]].join(' ');
            h += '<div class="mw-boxes"><div class="mw-box info">' + svg('<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>')
               + '<div><strong>' + esc(fmt(T.why_box_title, P)) + '</strong>' + esc(does) + '</div></div>';
            if (!st.enabled) {
                h += '<div class="mw-box warn">' + svg('<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>')
                   + '<div>' + esc(T.why_off) + '</div></div>';
            }
            if (!W.person.is_active) {
                h += '<div class="mw-box warn">' + svg('<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>')
                   + '<div>' + esc(fmt(T.why_person_left, P)) + '</div></div>';
            }

            h += '</div>';   // .mw-boxes

            // The ways out: one per reason, then Exclude.
            h += '<p class="mw-fix-title">' + esc(fmt(T.why_fix_title, P)) + '</p>';
            h += '<p class="mw-fix-note">' + esc(S.can_edit ? (n > 1 ? T.why_fix_many : '') : T.why_readonly) + '</p>';
            let i = 0;
            W.reasons.forEach(r => {
                i++;
                if (r.kind === 'directory') {
                    // Changed on the person, not here - so a link, whoever may edit lines.
                    h += fixRow(i, fmt(T.why_fix_dir, P), r.via.length ? T.why_fix_dir_via : T.why_fix_dir_body,
                        '<a class="btn btn-secondary" href="users.php?user_id=' + encodeURIComponent(W.person.id) + '">' + esc(T.open) + '</a>');
                    return;
                }
                const line = S.lines.find(l => l.id === r.line_id);
                const label = line ? (line._name ?? lineLabel(line)) : '';
                const body = { user: fmt(T.why_fix_line_user, P), group: T.why_fix_line_group, everyone: T.why_fix_line_everyone,
                               reports: T.why_fix_line_reports, department: fmt(T.why_fix_line_dept, { name: r.name }) }[r.kind] || '';
                h += fixRow(i, fmt(T.why_fix_line, { label: label || lineLabel({ grant_type: r.kind, target_value: r.name, label: r.name }) }), body,
                    S.can_edit ? '<button type="button" class="btn btn-secondary" data-why-remove="' + r.line_id + '">' + esc(T.remove) + '</button>' : '');
            });
            h += fixRow(i + 1, fmt(T.why_fix_excl, P), T.why_fix_excl_body,
                S.can_edit ? '<button type="button" class="btn btn-primary" data-why-exclude="' + W.person.id + '">' + esc(T.exclude) + '</button>' : '');
            $('mwBody').innerHTML = h;
        }

        async function openWhy(personId) {
            whyPerson = personId;
            const d = await call({ action: 'why', person: personId });
            if (!d.success) { toast(d.error || T.load_failed, 'error'); return; }
            paintWhy(d);
            $('maWhy').classList.add('active');
        }
        function closeWhy() { $('maWhy').classList.remove('active'); whyPerson = null; }

        $('maTeam').addEventListener('click', e => {
            const li = e.target.closest('li[data-person]');
            if (li) openWhy(Number(li.dataset.person));
        });
        $('maTeam').addEventListener('keydown', e => {
            const li = e.target.closest('li[data-person]');
            if (li && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openWhy(Number(li.dataset.person)); }
        });
        // Acting from the modal: make the change, then show the answer again -
        // one reason fewer, or "no longer on the team".
        $('maWhy').addEventListener('click', async e => {
            if (e.target.id === 'maWhy') { closeWhy(); return; }
            const b = e.target.closest('button');
            if (!b) return;
            if (b.id === 'mwClose') { closeWhy(); return; }
            const pid = whyPerson;
            if (b.dataset.whyRemove)  { b.disabled = true; await write({ action: 'remove', line_id: Number(b.dataset.whyRemove) }); }
            else if (b.dataset.whyExclude) { b.disabled = true; await write({ action: 'add', grant_type: 'user', target_id: pid, is_exclusion: true }); }
            else return;
            if (pid) { const d = await call({ action: 'why', person: pid }); if (d.success) paintWhy(d); }
        });
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && $('maWhy').classList.contains('active')) closeWhy(); });

        (async function () {
            try {
                const d = await call({ action: 'get' });
                if (!d.success) { $('maBanners').innerHTML = '<div class="ma-banner warn">' + esc(d.error || T.load_failed) + '</div>'; return; }
                S = d;
                paint();
            } catch (e) {
                $('maBanners').innerHTML = '<div class="ma-banner warn">' + esc(T.load_failed) + '</div>';
            }
        })();
    })();
    </script>
    <?php
}
