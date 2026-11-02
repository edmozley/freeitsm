<?php
/**
 * People who have made a substantial contribution to FreeITSM.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * 🔑 THIS IS A CODE FILE ON PURPOSE, NOT A DATABASE TABLE.
 *
 * A table would need a migration, a schema entry, an admin screen to edit it,
 * and permissions deciding who may — all so that a list which grows a handful
 * of times a year can be changed without a deploy. It is also not an
 * operator's data: it is part of what FreeITSM *is*, and it should read the
 * same on every install rather than being something each site curates.
 *
 * So: add a person here, commit it, and it ships with the next release.
 * ──────────────────────────────────────────────────────────────────────────
 *
 * Each entry:
 *   name     how they asked to be credited — use exactly what they gave you
 *   github   username without the @, or null if they would rather not be linked
 *   date     the contribution, YYYY-MM-DD. Displayed as a month and year.
 *   what     two or three sentences. Say what they actually did and why it
 *            mattered; "helped with the project" credits nobody.
 *
 * ⚠️ Ask before adding someone. A person's name and GitHub handle on a public
 * product page is their decision, not ours — Sandy was asked in #138 and chose
 * how he wanted to be credited.
 *
 * Newest first.
 */

/**
 * Returns the people credited for substantial contributions, newest first.
 *
 * @return array<int,array{name:string,github:?string,date:string,what:string}>
 */
function getContributors(): array
{
    return [
        [
            // Public GitHub handle only; ask in the #235 reply how they would like
            // to be credited - change this if they say otherwise.
            'name'   => 'vsc55',
            'github' => 'vsc55',
            'date'   => '2026-11-02',
            'what'   => 'Brought Microsoft Entra ID to FreeITSM as a people source (PR #235): people, groups, '
                      . 'nested membership and reporting lines read through Microsoft Graph, so a new starter '
                      . 'exists before they first sign in, with a careful design for matching two different '
                      . 'names for one person. Also the design for a built-in scheduler, one line for every '
                      . 'background job (#233).',
        ],
        [
            // Name and company as Ed was given them (October 2026). The package
            // he sent also held six modules, not taken yet - add them here if
            // they are.
            'name'   => 'Carl Steynvaard (Open Resilience)',
            'github' => null,
            'date'   => '2026-10-24',
            'what'   => 'The Zabbix, 3CX and Microsoft Teams alert integrations: monitoring problems open incidents '
                      . 'by themselves, linked to the server in the CMDB; the phone system shows who is calling and '
                      . 'keeps every call on the caller\'s ticket; and service desk alerts reach Teams channels as '
                      . 'cards - all built and run in production, and offered to the project along with six '
                      . 'modules of his own.',
        ],
        [
            // Public GitHub handle only; asked in the #194 reply how they would like
            // to be credited - change this if they say otherwise.
            'name'   => 'Dr4inder',
            'github' => 'Dr4inder',
            'date'   => '2026-10-19',
            'what'   => 'Three pull requests for the REST API, built against an agreed design and each with '
                      . 'its own tests: custom asset fields on create and update (PR #195), evidence '
                      . 'attachments on a change with a key that can do nothing else (PR #196), and inventory '
                      . 'reports from other tools that never touch another source\'s rows (PR #197, 3.12.0), '
                      . 'and a fix for long forms cut off inside a task\'s Work view, with a browser test '
                      . 'that fails on the old code (PR #198, 3.13.0). '
                      . 'Before that, careful reviews of approvals and evidence, repeating tasks, task '
                      . 'evidence and background job health (#180-#182, #190) that each found a real '
                      . 'problem - and wrote a reproduction for it.',
        ],
        [
            // Public GitHub name; asked in the PR #159 reply how they would like
            // to be credited - change this if they say otherwise.
            'name'   => 'Andrew Turbay',
            'github' => 'turbay-a',
            'date'   => '2026-09-30',
            'what'   => 'Brought Telegram to FreeITSM as a full messaging channel (PR #159): the bot '
                      . 'connection, matching a chat to the right person by the phone number they share, '
                      . 'bot replies in each customer\'s own language, and sending pictures and documents '
                      . 'over Telegram and WhatsApp alike - with every new string translated into thirteen '
                      . 'languages. Then Microsoft Teams and Mattermost channels, and asking chat customers '
                      . 'for their rating with buttons in the chat itself (PR #166, 3.1.0). Then the '
                      . 'Servers list from Proxmox VE and VMware Cloud Director, several servers of each, '
                      . 'read-only and careful never to delete what a failed read could not see (PR #167, 3.1.0).',
        ],
        [
            'name'   => 'Santhosh Srinivasan (Sandy)',
            'github' => 'srinivasansanthosh',
            'date'   => '2026-09-14',
            'what'   => 'Designed and built the entire Checklists & SOPs module — reusable procedure '
                      . 'templates, mandatory-step gating, analyst input capture and workflow '
                      . 'integration — and offered it to the project unprompted. It is the first '
                      . 'whole module FreeITSM has received from the community, and it started with '
                      . 'a careful argument about why neither Knowledge nor Tasks solved the problem. '
                      . 'Since then: separate auto-create and profile-sync settings for single '
                      . 'sign-on (2.10.0), and asset reconciliation by serial number, automatic '
                      . 'asset tags and configurable physical labels (3.1.0).',
        ],
        [
            'name'   => 'Abdul Aziz',
            'github' => 'abdulaziz-git',
            'date'   => '2026-06-20',
            'what'   => 'FreeITSM\'s first external contribution. Fixed Google mailbox OAuth setup '
                      . 'more thoroughly than the original patch did — the encrypted token columns '
                      . 'were too small and were silently truncating — and added per-provider '
                      . 'callback validation with a redirect-URI the setup screen fills in for you.',
        ],
        [
            'name'   => 'chris18890',
            'github' => 'chris18890',
            'date'   => '2026-07-22',
            'what'   => 'Raised two issues that turned into real work: defaulting TLS certificate '
                      . 'verification to on, and a demo dataset with realistic roles and permissions '
                      . 'rather than everyone being an administrator. Both shipped in full.',
        ],
    ];
}
