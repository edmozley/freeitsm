<?php
/**
 * System Help — Preferences.
 */
require __DIR__ . '/_init.php';
$helpSlug = 'preferences';
require __DIR__ . '/_top.php';
?>
<!-- 1. Overview -->
<div class="help-section" id="overview">
    <div class="help-section-header"><?php echo helpSectionNum('overview'); ?>
        <div>
            <h3>What Preferences are</h3>
            <p>Preferences are <strong>personal to you</strong>, not system-wide. Changing one here only affects your own view — it never changes anything for other analysts.</p>
        </div>
    </div>
    <p>There are no Save or Apply buttons. Every control saves the moment you click it, and a brief confirmation or preview tells you it stuck.</p>
    <div class="help-note"><strong>Your settings travel with you.</strong> Choices are stored against your account, so they apply on any computer or browser you sign in from — not just the one you set them on.</div>
</div>

<!-- 2. Language -->
<div class="help-section" id="language">
    <div class="help-section-header"><?php echo helpSectionNum('language'); ?>
        <div>
            <h3>Interface language</h3>
            <p>Pick the language the interface is shown in. The dropdown lists every language FreeITSM ships translations for, with its language code alongside.</p>
        </div>
    </div>
    <p>When you choose a new language the page <strong>reloads</strong> so everything re-renders in your selection. Ticket content and other data you've entered are not translated — only the interface labels, menus and messages.</p>
</div>

<!-- Module order (GH #187) -->
<div class="help-section" id="module-order">
    <div class="help-section-header"><?php echo helpSectionNum('module-order'); ?>
        <div>
            <h3>Module order</h3>
            <p>Put the modules you use most first - on the <strong>Display</strong> tab.</p>
        </div>
    </div>
    <p>The order you choose is used in three places: the <strong>waffle menu</strong>, the <strong>home page</strong>, and the <strong>Ctrl+K</strong> "Go to" list - so Ctrl+K then Enter takes you to whichever module you put first. Move a module by dragging its handle (on a phone, press and hold the handle), with its arrow buttons, or by selecting it and pressing <kbd>Alt</kbd> with the up or down arrow.</p>
    <ul>
        <li>It is yours alone, and follows you to any browser or device you sign in from.</li>
        <li>It changes the order only. Which modules you can open is decided by your administrator, exactly as before.</li>
        <li>A module you cannot open today keeps its place, so if you are given it later it appears where it always would have.</li>
        <li>A module added in a later update joins the list on its own, in its usual place.</li>
        <li><strong>Reset</strong> puts the standard order back.</li>
    </ul>
</div>

<!-- Money (GH #189) -->
<div class="help-section" id="money">
    <div class="help-section-header"><?php echo helpSectionNum('money'); ?>
        <div>
            <h3>Money</h3>
            <p>How amounts of money are written for you - <code>£1,234.56</code>, <code>1.234,56 €</code> or <code>Rp 1.500.000</code>.</p>
        </div>
    </div>
    <p>Left on <strong>Use the system default</strong>, you see whatever your administrator chose in <strong>System - Currencies</strong>. Choose <strong>Choose my own</strong> to pick the separators, where the symbol goes, and the decimal places for every currency. Under <strong>Particular currencies</strong> you can give one currency a format of its own - euros the German way, say, while everything else stays as it is.</p>
    <p>It changes only how amounts <em>look</em> on your screen. The currency of a contract, licence or project, and the amount itself, stay exactly as they are, and nobody else sees your choice. It is saved as soon as you change it.</p>
</div>

<!-- 3. Toasts -->
<div class="help-section" id="toasts">
    <div class="help-section-header"><?php echo helpSectionNum('toasts'); ?>
        <div>
            <h3>Notifications (toasts)</h3>
            <p>Toasts are the small pop-up messages that confirm an action or report an error. Two settings control where they appear and how they move.</p>
        </div>
    </div>
    <div class="help-cards">
        <div class="help-card">
            <h4>Position</h4>
            <p>A 3&times;3 grid representing your screen. Click any of the nine cells — top/middle/bottom by left/centre/right — to choose the corner or edge toasts appear in. A sample toast previews your choice.</p>
        </div>
        <div class="help-card">
            <h4>Animation</h4>
            <p>Choose <strong>Slide</strong> (toasts glide in from the edge) or <strong>Fade</strong> (they fade in on the spot). A sample previews each.</p>
        </div>
    </div>
    <div class="help-note">If you set these before they became account settings, your old per-browser choices are migrated to your account automatically the first time you open this page.</div>
</div>

<!-- The notification bell (Notifications tab) -->
<div class="help-section" id="bell">
    <div class="help-section-header"><?php echo helpSectionNum('bell'); ?>
        <div>
            <h3>The notification bell</h3>
            <p>The <strong>Notifications</strong> tab decides what reaches the bell in the header, and how you hear about it. Toasts, above, are a different thing: they confirm what you just did.</p>
        </div>
    </div>
    <div class="help-cards">
        <div class="help-card">
            <h4>What reaches the bell</h4>
            <p>One switch per kind of event - a ticket assigned to you, a reply, a task you were put on, a comment, and so on. Turning one off only changes your own bell. You are never told about something you did yourself.</p>
        </div>
        <div class="help-card">
            <h4>Chime</h4>
            <p>A short sound when something new arrives. <strong>No sound</strong> until you pick one; <strong>Play</strong> previews it.</p>
        </div>
        <div class="help-card">
            <h4>Desktop notifications</h4>
            <p>Each new item in the bell also appears as a notification on your computer - in Edge on Windows, a normal Windows notification - so you see it while working in another window. Clicking it opens the ticket or task.</p>
            <p>Your browser asks whether to allow notifications from this site when you turn it on. If you say no, the switch goes back off and the page says how to allow them later. <strong>Test</strong> shows a sample.</p>
        </div>
        <div class="help-card">
            <h4>Tasks assigned to you</h4>
            <p><strong>Email me when a task is assigned to me</strong> sends an email whenever a task is created for you or handed to you from someone else, written in your own interface language. Nothing is sent for a task you assign to yourself. Off unless you turn it on.</p>
        </div>
    </div>
    <div class="help-note"><strong>Desktop notifications need FreeITSM open in a tab.</strong> Minimised or behind other windows is fine; with the browser closed nothing arrives. Browsers also only allow them on a site opened over <strong>https</strong>. The task email needs a mailbox able to send - the same one password resets use - and each one appears in the send log as <em>Task assigned</em>.</div>
</div>

<!-- 4. Left panels -->
<div class="help-section" id="panels">
    <div class="help-section-header"><?php echo helpSectionNum('panels'); ?>
        <div>
            <h3>Left-panel visibility</h3>
            <p>Several modules have a left-hand panel for navigation. This list gives you one toggle per module so you can decide, per module, whether that panel is always on screen or tucked away until you need it.</p>
        </div>
    </div>
    <p>Each toggle offers two modes:</p>
    <ul>
        <li><strong>Always</strong> — the panel stays pinned open whenever you're in that module.</li>
        <li><strong>Hover</strong> — the panel collapses to reclaim space and slides out when you move the pointer to the edge.</li>
    </ul>
    <p>The modules with their own panel toggle here are Knowledge, Process Mapper, Contracts, Calendar, Tasks, CMDB, Change Management, Asset Management and System Wiki.</p>
    <div class="help-note">Where a module also has its own settings page, the same toggle lives there too — both edit the one setting, so changing it in either place has the same effect.</div>
</div>

<!-- 5. Display options -->
<div class="help-section" id="display">
    <div class="help-section-header"><?php echo helpSectionNum('display'); ?>
        <div>
            <h3>Display options</h3>
            <p>Small choices about how the screen behaves for you.</p>
        </div>
    </div>
    <div class="help-cards">
        <div class="help-card">
            <h4>Mission Control chart fill</h4>
            <p>Sets how charts on the Mission Control dashboard are filled: <strong>Plain</strong> (solid colour) or <strong>Gradient</strong> (a graded fill). Purely cosmetic — it changes the look, not the data.</p>
        </div>
        <div class="help-card">
            <h4>Closing the search panel</h4>
            <p>The search panel in Tickets, Change Management, Problem Management and Contracts can be dragged out of the way and left open, so by default only its <strong>✕</strong> or the <strong>Escape</strong> key closes it. That suits searching once and then clicking down the results looking for the right ticket.</p>
            <p>Turn on <strong>Close it when I click away</strong> if you would rather it got out of the way on its own. It then closes as soon as you click the folder list, the ticket list or the toolbar. Clicking the ticket you just found — or its properties — leaves it open, so changing something on the ticket the search gave you does not throw the search away.</p>
            <p><strong>Escape always closes it</strong>, whichever way this is set.</p>
        </div>
        <div class="help-card">
            <h4>Live updates</h4>
            <p>How often an open page checks for changes other people make - the ticket list and folder counts, the ticket you have open, the tasks board, an open task and the bell - and catches up on its own: every <strong>15 seconds</strong> (the default), <strong>30 seconds</strong>, <strong>every minute</strong>, or <strong>Off</strong>. It pauses while the tab is in the background and catches up as soon as you come back, and a reply or note you are writing is never thrown away. A new choice applies from the next page you open.</p>
            <p>With it <strong>Off</strong> you refresh by hand, although the ticket list still checks for new email and chat messages once a minute.</p>
        </div>
        <div class="help-card">
            <h4>My company for new tickets</h4>
            <p>Shown only when you can reach more than one company. In the all-companies view, <strong>New ticket</strong> asks which company a ticket is for; choose one here to have it chosen for you. You can still change it on the form, and if you can no longer reach that company, nothing is chosen.</p>
        </div>
    </div>
</div>

<!-- Tasks -->
<div class="help-section" id="tasks">
    <div class="help-section-header"><?php echo helpSectionNum('tasks'); ?>
        <div>
            <h3>Tasks</h3>
            <p>Four choices about how the Tasks module works for you.</p>
        </div>
    </div>
    <div class="help-cards">
        <div class="help-card">
            <h4>Opening a task</h4>
            <p>On the board: in the <strong>side panel</strong>, which keeps the board visible, or in a <strong>large window</strong> with room for the description and comments. The expand button on a task changes the same setting.</p>
            <p>On the Table, Calendar and Timeline: a <strong>quick look</strong> (status, dates, assignee and the description, with an Edit button), or <strong>the full task</strong> - the board's editor, with subtasks, checklists and comments - in the same panel.</p>
        </div>
        <div class="help-card">
            <h4>Subtasks on the task calendar</h4>
            <p>Parent tasks only, tasks and subtasks together, or subtasks alone. The calendar's own <strong>Show</strong> control changes the same setting.</p>
        </div>
        <div class="help-card">
            <h4>Subtasks on the task board</h4>
            <p>A task's subtasks are listed under its card on the board. Choose <strong>All subtasks</strong>, <strong>Only the ones still to do</strong>, or <strong>None - just the count</strong>; the card always shows its count, and clicking the count shows or hides that one card's list. The same choice is in the Tasks sidebar.</p>
        </div>
        <div class="help-card">
            <h4>Empty work sections on a task</h4>
            <p>A task's Work view has Checklists, Forms, Procedures and Follow-ups. <strong>Show them in full</strong> keeps an empty one open with its attach and create controls; <strong>Fold empty ones to a heading</strong> shows it as, say, <em>Checklists (0)</em>, which opens with a click.</p>
        </div>
    </div>
</div>

<!-- My work calendar -->
<div class="help-section" id="workcal">
    <div class="help-section-header"><?php echo helpSectionNum('workcal'); ?>
        <div>
            <h3>My work calendar</h3>
            <p>Your scheduled tickets and tasks, in the calendar you actually use.</p>
        </div>
    </div>
    <div class="help-cards">
        <div class="help-card">
            <h4>Off, Add to my calendar, or Subscribe link</h4>
            <p><strong>Add to my calendar</strong> writes real appointments into your calendar and needs a connection set up by an administrator under <a href="calendar-sync.php">Calendar sync</a>. <strong>Subscribe link</strong> gives you a private link any calendar app can read, with nothing to set up.</p>
        </div>
        <div class="help-card">
            <h4>Which calendar</h4>
            <p>If your system has more than one calendar connection - Microsoft 365 and a CalDAV server, say - pick yours here. With only one there is nothing to choose. Moving to another takes your appointments out of the old calendar first.</p>
        </div>
        <div class="help-card">
            <h4>A CalDAV server</h4>
            <p>You sign in with your own calendar account, press <strong>Find</strong> and choose a calendar. An app password is the better choice where your server offers them. <strong>Forget</strong> takes the appointments back out and forgets the sign-in.</p>
        </div>
    </div>
    <p>The full guide, with each route compared, is <a href="../../tickets/help-calendar-sync.php">Tickets → Calendar sync</a>.</p>
</div>

<?php require __DIR__ . '/_bottom.php'; ?>
