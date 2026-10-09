<?php
/**
 * War room — chat that still works when Teams, Slack and the internet do not.
 *
 * ⚠️ ENGLISH ONLY at time of writing. The other 23 locales have no
 * lang/<locale>/war-room.php yet, which is SAFE but not finished:
 * I18n::loadNamespace() returns [] for a missing file and resolve() then falls
 * back to the English value, so a French analyst sees this module in English
 * rather than a fatal or a wall of raw keys. Translating it is outstanding work,
 * not an optional extra — see the Internationalisation developer guide.
 */
return [
    'title' => 'War room',

    'nav' => [
        'war_room' => 'War room',
        'settings' => 'Settings',
        'help'     => 'Help',
    ],

    'channel' => [
        // The all-hands channel. Always present, always first, cannot be removed:
        // in a real outage everyone needs one obvious place to gather rather than
        // six team rooms and an argument about which one to use.
        'all_hands'    => 'Everyone',
        'heading'      => 'Channels',
        'teams'        => 'Your teams',
        'channels'     => 'Channels',
        'direct'       => 'Direct messages',
        'new'          => 'New channel',
        'new_dm'       => 'New message',
        'archived'     => 'Archived',
        'show_archived'=> 'Show archived',
        'private'      => 'Private',
        'topic'        => 'Topic',
        'members'      => '{count} members',
    ],

    // Shown once at the top so somebody who has never opened this page knows
    // what it is for within one sentence.
    'intro' => 'A chat that runs on your own server, for when Teams, Slack or the internet are unavailable. Messages stay on this server and are not sent anywhere else.',

    'composer' => [
        'placeholder' => 'Type a message…',
        'send'        => 'Send',
        'attach'      => 'Attach a file',
        'archived'    => 'This channel is archived. You can read it, but not post.',
    ],

    'empty'          => 'No messages yet. Say something to get started.',
    'former_analyst' => 'Former analyst',

    'presence' => [
        'nobody'    => 'Nobody else is here right now',
        'here'      => 'Here now',
        // "In the war room but in another channel" is a genuinely useful thing to
        // know mid-incident: it is the difference between nobody is reading this
        // and everybody is in the other room.
        'elsewhere' => 'Elsewhere in the war room',
    ],

    'create' => [
        'heading'      => 'New channel',
        'name'         => 'Name',
        'name_hint'    => 'What is this channel for? For example, "Exchange outage".',
        'topic'        => 'Topic (optional)',
        'private'      => 'Private — only the people you choose can see it',
        'members'      => 'Who can see it',
        'create'       => 'Create',
        'cancel'       => 'Cancel',
        'name_required'=> 'Give the channel a name',
        'failed'       => 'Could not create that channel',
    ],

    'manage' => [
        'heading'    => 'Channel settings',
        'rename'     => 'Rename',
        'archive'    => 'Archive',
        'restore'    => 'Restore',
        'save'       => 'Save',
        // Said plainly, because "archive" means different things in different
        // tools and here it deliberately does NOT hide anything.
        'archive_hint' => 'Archiving stops new messages. The conversation stays readable.',
        'failed'     => 'Could not change that channel',
    ],

    'dm' => [
        'heading'  => 'New message',
        'search'   => 'Search people',
        'nobody'   => 'No other analysts to message',
        'here_now' => 'here now',
        'failed'   => 'Could not open that conversation',
    ],

    'search' => [
        'heading'      => 'Search',
        'placeholder'  => 'Search the war room…',
        'this_channel' => 'This channel only',
        'everywhere'   => 'Everywhere I can see',
        'no_results'   => 'Nothing found',
        'searching'    => 'Searching…',
        'results'      => '{count} results',
        'failed'       => 'Could not search',
        'jump'         => 'Open',
    ],

    'attach' => [
        'too_many'  => 'You can attach up to {count} files to one message',
        'rejected'  => 'Not attached: {names}',
        'download'  => 'Download',
    ],

    // The AI briefing. Named "situation report" rather than "summary" because
    // that is what the reader is being asked to produce, and it sets the
    // expectation that it is a draft to check rather than an answer.
    'sitrep' => [
        'heading'     => 'Situation report',
        'button'      => 'Situation report',
        'intro'       => 'Reads the chat and drafts the update you would send to the business.',
        'since'       => 'Covering the last',
        'hours'       => '{count} hours',
        'hour'        => '1 hour',
        'minutes'     => '{count} minutes',
        'scope_all'   => 'Everywhere I can see',
        'scope_this'  => 'This channel only',
        'generate'    => 'Write it',
        'working'     => 'Reading the conversation…',
        'copy'        => 'Copy',
        'copied'      => 'Copied',
        'empty'       => 'Nothing was said in that period, so there is nothing to report.',
        'footer'      => 'Drafted from {messages} messages by {model}. Check it before you send it.',
        // Two honest failure messages rather than one vague one. During an outage
        // "we could not reach the AI provider" is very likely the true and
        // expected answer, not a fault in FreeITSM, and saying so stops somebody
        // wasting minutes on it mid-incident.
        'not_configured' => 'No AI provider is set up for the war room yet. An administrator can add one in War room → Settings → Situation report.',
        'unreachable'    => 'Could not reach the AI provider. If the internet is what has failed, this part will not work until it is back — the chat itself is unaffected.',
        'failed'         => 'Could not write the report',
    ],

    // Mentions. Named after what they do to the reader, not after the @ symbol.
    'mention' => [
        'everyone'  => 'everyone',
        'heading'   => 'Mentions',
        'none'      => 'Nobody has mentioned you',
        'hint'      => 'Type @ and start typing a name. Pick it from the list, or keep typing — a first name on its own works too.',
        'desktop'   => 'Show a desktop notification when I am mentioned',

        // How the picker writes a name into the box. A personal typing preference,
        // so it is per-analyst rather than an administrator's decision.
        'style_label' => 'When I pick a name, insert',
        'style_short' => 'First name, unless two people share it',
        'style_full'  => 'Always the full name',
        'style_strip' => 'Full name; backspace removes the surname first',
        // Said once, next to the setting, because it is the bit that is not
        // obvious and the bit that was annoying before.
        'style_hint'  => 'Backspace at the end of a name removes the whole mention in one press.',
        'desktop_blocked' => 'Your browser has blocked notifications for this site. You will need to allow them in the browser\'s own settings.',
    ],

    // Warbot. Always labelled, never disguised as a colleague — somebody arriving
    // mid-incident must not mistake a machine's answer for a person's, especially
    // when that answer is about to be repeated to the business.
    'warbot' => [
        'tag'      => 'bot',
        'thinking' => 'Looking that up…',
        'intro'    => 'Ask @Warbot a question, or use a command like /p1 or /status.',
    ],

    'message' => [
        'edit'           => 'Edit',
        'delete'         => 'Delete',
        'edited'         => 'edited',
        'edit_heading'   => 'Edit message',
        'edit_hint'      => 'The message will be marked as edited, because this is the record of what was said during an incident.',
        'delete_heading' => 'Delete message',
        'delete_confirm' => 'Delete this message?',
        // Said out loud: this is not what most chat tools do, and somebody
        // deleting a pasted password deserves to know exactly what remains.
        'delete_hint'    => 'The text and any attached files are destroyed. A line saying you deleted a message stays in its place, so the conversation does not have an unexplained gap.',
        'deleted_by'     => 'Message deleted by {name}',
        'failed'         => 'Could not change that message',
    ],

    'error' => [
        'load' => 'Could not load messages',
        'send' => 'Could not send that message',
        // Shown in place of the usual presence line when polling stops
        // succeeding — the one thing you must not do in this module is let
        // somebody believe their message was delivered when it was not.
        'offline' => 'Lost contact with the server — messages may not be arriving',
    ],

    // The help guide. Written for somebody who has opened the war room for the
    // first time BECAUSE something is broken — so it leads with what works when
    // nothing else does, rather than with a tour of the buttons.
    'help' => [
        'page_title'  => 'War room help',
        'hero_title'  => 'War room',
        'hero_intro'  => 'A chat that runs on your own server, for when Teams, Slack or the internet are unavailable. Nothing on this page needs the internet except the two AI features, and both are optional.',

        'nav_overview' => 'What it is for',
        'nav_channels' => 'Channels',
        'nav_talking'  => 'Talking',
        'nav_finding'  => 'Finding things',
        'nav_warbot'   => 'Warbot',
        'nav_sitrep'   => 'Situation report',
        'nav_settings' => 'Settings',

        'overview_heading' => 'What it is for',
        'overview_intro'   => 'When your usual chat goes down, the service desk is often the last thing still running on the network — and it already knows every analyst and which team they are in. So it can be the place people gather.',
        'card_chat_title'   => 'An ordinary chat',
        'card_chat_desc'    => 'Channels, direct messages, search and attachments. It works the way you expect a chat to work, so there is nothing to learn on the day it matters.',
        'card_offline_title'=> 'It runs on your server',
        'card_offline_desc' => 'Messages are kept here and sent nowhere else, and the page loads nothing from the internet — which matters when the internet is the thing that has failed.',
        'card_who_title'    => 'Who is actually here',
        'card_who_desc'     => 'The panel on the left tells you who is reading this channel and who is elsewhere in the war room. When nothing else works, that is most of what you want to know.',
        'card_private_title'=> 'Private where it needs to be',
        'card_private_desc' => 'Team channels are visible to that team, private channels only to the people invited, and a direct message only to the two of you.',
        'overview_note_title' => 'It is not a Teams replacement, and does not try to be',
        'overview_note_body'  => 'Nobody is giving up their real chat tool for this. The point is that it works on the day the real one cannot — so it is worth opening once while everything is fine, just to know your way around.',

        'channels_heading' => 'Channels',
        'channels_intro'   => 'Four kinds, and they differ in where they come from.',
        'channels_everyone_title' => 'Everyone',
        'channels_everyone_desc'  => 'The all-hands room. It always exists and is always first in the list. In a real outage you want one obvious place for everybody rather than six team rooms and an argument about which to use.',
        'channels_team_title'     => 'One per team',
        'channels_team_desc'      => 'You get a channel for each team you belong to, taken straight from the teams already set up in System. There is nothing to create and nothing to manage: change the team and the channel follows.',
        'channels_own_title'      => 'Channels you create',
        'channels_own_desc'       => 'Any analyst can create one — during an incident, needing an administrator to make you a room is the wrong dependency. Give it a name and a topic, and tick Private to choose who can see it. Archive it when the incident is over.',
        'channels_dm_title'       => 'Direct messages',
        'channels_dm_desc'        => 'Use New message to start a one-to-one with any analyst. Only the two of you can see it.',
        'channels_note_title'     => 'Archiving does not hide anything',
        'channels_note_body'      => 'Archiving a channel stops new messages, but the conversation stays readable — it is the record of what was said during the incident. Only the person who created a channel, or somebody who can manage the war room, can rename or archive it.',

        'talking_heading' => 'Talking',
        'talking_intro'   => 'The parts that are not obvious.',
        'talking_send_title'   => 'Enter sends',
        'talking_send_desc'    => 'Enter sends your message and Shift+Enter starts a new line, the same as every other chat tool. If the page loses contact with the server it says so where the list of people usually is, rather than looking quiet.',
        'talking_mention_title'=> 'Mentioning somebody',
        // 🔴 TRANSLATORS: `@everyone` in the next string is a LITERAL TRIGGER, not
        // a word. includes/warroom.php matches /@everyone\b/ and the mention picker
        // in assets/js/war-room.js inserts the ASCII string `@everyone` whatever the
        // interface language is. Translating it tells the reader to type something
        // that does nothing at all.
        //
        // ⚠️ This has already happened twice: German shipped `@alle` and Polish
        // nearly shipped `@wszyscy`, both caught in September 2026. Translate the
        // sentence around it and leave `@everyone` exactly as it is.
        'talking_mention_desc' => 'Type @ and start typing a name, then pick it from the list or keep typing. Backspace at the end of a name removes the whole mention in one press. Use @everyone when you need the room to look up. Mentioning somebody puts a bell in the header of every page in FreeITSM, so it reaches them even if they are in Tickets.',
        'talking_files_title'  => 'Attaching files',
        'talking_files_desc'   => 'Up to five files per message, using the + button. Screenshots appear in the conversation rather than as a filename you have to open. Files are deleted along with their message.',
        'talking_edit_title'   => 'Editing and deleting',
        'talking_edit_desc'    => 'You can edit your own messages and delete your own; an administrator can delete anybody\'s. An edited message is marked as edited, and a deleted one leaves a line saying who removed it — the text and any files really are destroyed, but the conversation does not get an unexplained gap.',

        'finding_heading' => 'Finding things',
        'finding_intro'   => 'Two ways, depending on whether you are looking for something or something is looking for you.',
        'finding_search_title' => 'Search',
        'finding_search_desc'  => 'Searches every conversation you can see, or just the one you are in. Short things work — P1, DC2, an error code, part of an IP address — which is deliberate, because those are what people actually type into a war room. Click a result to jump to that channel.',
        'finding_bell_title'   => 'The bell',
        'finding_bell_desc'    => 'When somebody mentions you, a bell appears in the header of whatever page you are on. Open it to see who, in which channel, and what they said, and click through to reply. You can also turn on a desktop notification for yourself in the panel on the left.',

        'warbot_heading' => 'Warbot',
        'warbot_intro'   => 'An assistant that sits in the room. Mention it — for example "@Warbot how many P1s are open?" — or use one of the commands below.',
        'warbot_offline_title' => 'The commands work when the internet does not',
        'warbot_offline_body'  => 'Warbot\'s lookups are ordinary database queries running on this server, so they work during an outage. Only understanding a question in plain English needs an AI provider. If one is not set up, or cannot be reached, Warbot says so and the commands below still work.',
        'warbot_cmds_heading'  => 'Commands',
        'cmd_p1'       => 'Open critical tickets',
        'cmd_open'     => 'All open tickets',
        'cmd_spike'    => 'Are we seeing a surge? Compares the last hour against the usual rate for this time of day',
        'cmd_status'   => 'Which services are degraded, and what customers are being told',
        'cmd_changes'  => 'What changed recently — usually the first useful question in an incident',
        'cmd_checks'   => 'The morning checks, and which ones were not clear',
        'cmd_oncall'   => 'Who is on call today',
        'cmd_known'    => 'Known errors, root causes and workarounds from Problem Management',
        'cmd_kb'       => 'Find a runbook in the knowledge base',
        'cmd_find'     => 'Search the war room itself — what did we already say about this?',
        'cmd_asset'    => 'Look up a machine by hostname, asset tag or service tag',
        'cmd_impact'   => 'What depends on a configuration item',
        'cmd_linked'   => 'Tickets linked to a given one — duplicates and children',
        'cmd_supplier' => 'Who to ring: the supplier, their contract and their direct numbers',
        'cmd_projects' => 'Live projects and their health (needs access to Projects)',
        'cmd_project'  => 'How one project is going: health and why, stages, milestones, risks',
        'cmd_dates'    => 'Project dates coming up - go-lives, moves, stage ends - and missed milestones',
        'cmd_help'     => 'This list, in the chat',
        'warbot_limits_title' => 'What it will not do',
        'warbot_limits_body'  => 'Warbot can only read. It cannot raise, change or close anything, and it will not read a ticket out to a room — it points you at the ticket instead. Its answers are always labelled as coming from a bot, and everyone in the channel sees them.',

        'sitrep_heading' => 'Situation report',
        'sitrep_intro'   => 'For the person who has to tell the business what is going on and cannot read four hundred messages first.',
        'sitrep_open_title' => 'Choose a period',
        'sitrep_open_desc'  => 'Open Situation report from the top of the conversation, pick how far back to look, and whether to cover every channel you can see or just this one.',
        'sitrep_read_title' => 'What you get',
        'sitrep_read_desc'  => 'Where things stand, what has changed, who is doing what, what is still unknown, and a short paragraph you could send as-is. Copy takes the lot.',
        'sitrep_check_title'=> 'Read it before you send it',
        'sitrep_check_body' => 'It is told to keep speculation labelled as speculation and never to invent a cause or an ETA, but it is a draft written from a chat transcript, and it goes out under your name. This is also the one part of the war room that needs the internet, so during a real outage it may not be available.',

        'settings_heading' => 'Settings',
        'settings_intro'   => 'Two decisions for an administrator, and two for you.',
        'settings_retention_title' => 'How long messages are kept',
        'settings_retention_desc'  => 'From a week to forever. Old messages are removed as new ones arrive, so there is no scheduled job to set up, and files attached to a message go with it.',
        'settings_ai_title'        => 'The AI provider',
        'settings_ai_desc'         => 'One setting powers both Warbot\'s plain-English mode and the situation report. Leaving it unset is a perfectly good choice — the chat and Warbot\'s commands are unaffected.',
        'settings_personal_title'  => 'Your own preferences',
        'settings_personal_desc'   => 'In the panel on the left: whether to show a desktop notification when you are mentioned, and whether picking a name inserts the first name or the full name.',
        'settings_check_title'     => 'Check it before you need it',
        'settings_check_desc'      => 'System → Debug Tools → D008 confirms the whole module is working — channels, attachments, retention and Warbot\'s lookups. Worth running on a quiet day, because this is a tool you open when things are already going wrong.',
    ],

    'settings' => [
        'title'             => 'War room settings',
        'heading'           => 'Message retention',
        'intro'             => 'How long war room messages are kept. Old messages are removed automatically as new ones are posted, so there is no scheduled job to set up.',
        'retention_label'   => 'Keep messages for',
        'retention_forever' => 'Keep forever',
        'retention_days'    => '{count} days',
        'retention_hint'    => 'Set to "Keep forever" to disable automatic removal. Files attached to a message are deleted with it.',
        'save'              => 'Save',
        'saved'             => 'Saved',
        'save_failed'       => 'Could not save the setting',

        'ai_heading' => 'Situation report',
        'ai_intro'   => 'The situation report reads the war room chat and drafts the update a service delivery manager would send to the business — where things stand, what has changed, who is doing what and what is still unknown.',
        'ai_caveat'  => 'This is the one part of the war room that needs the internet, and it is optional. Without it the chat works exactly as it does now. The transcript of the channels the person can read is sent to the provider you choose here.',
    ],
];
