<?php
/**
 * Workflow starter templates — the "recipe library".
 *
 * A template is a workflow definition with the install-specific bits left
 * abstract, so the same recipe can be cloned into any FreeITSM install.
 *
 * Two abstraction markers may appear anywhere an action arg or a condition
 * value would normally hold a literal:
 *
 *   ['$lookup' => 'ticket_priority', 'names' => ['P1', 'Critical']]
 *       Resolved at clone time against this install's own lookup table, by
 *       label, case-insensitively, trying each candidate name in order.
 *       Installs rename their statuses and priorities freely, so a recipe
 *       can never hardcode `status_id: 5` — it says "whatever this install
 *       calls Closed" and we look it up.
 *
 *   ['$configure' => 'Your Slack incoming-webhook URL']
 *       A value only the user can supply (a webhook URL, an email address).
 *
 * Either marker that can't be turned into a real value is cloned as an EMPTY
 * arg and reported back in `unresolved`, which the editor turns into a
 * "these fields still need you" banner. A recipe therefore always clones —
 * it just tells you honestly what it couldn't fill in.
 *
 * Cloned workflows are always INACTIVE. Nothing a recipe creates can fire
 * until the user has read it and switched it on.
 *
 * Node positions (x/y) are deliberately omitted: the canvas editor
 * auto-lays-out any workflow whose nodes lack coordinates, so recipes stay
 * readable here and still land tidy on the canvas.
 */

require_once __DIR__ . '/engine.php';

class WorkflowTemplates
{
    /**
     * The catalogue. Every `trigger_event` here is a real, wired trigger
     * (checked against WorkflowEngine::availableTriggers()) and every action
     * type is a real handler — a recipe that can't actually run would be
     * worse than no recipe at all.
     */
    public static function all(): array
    {
        return [

            // ---------------------------------------------------------
            //  Tickets
            // ---------------------------------------------------------
            'ticket_auto_acknowledge' => [
                'name'        => 'Auto-acknowledge a new ticket',
                'category'    => 'Tickets',
                'description' => 'Emails the requester the moment their ticket lands, so nobody is left wondering whether it arrived.',
                'trigger_event' => 'ticket.created',
                'conditions'  => [],
                'actions'     => [
                    [
                        'type' => 'send_email',
                        'args' => [
                            'ticket_id' => '{{ticket.id}}',
                            'subject'   => 'We have received your request [{{ticket.number}}]',
                            'body'      => "Hello,\n\nThanks for getting in touch — we have logged your request and the service desk will pick it up shortly.\n\nReference: {{ticket.number}}\nSubject: {{ticket.subject}}\n\nYou can simply reply to this email to add more detail.\n\nThe Service Desk",
                        ],
                    ],
                ],
            ],

            'ticket_closed_thank_you' => [
                'name'        => 'Thank the requester when a ticket closes',
                'category'    => 'Tickets',
                'description' => 'When a ticket moves into a closed status, send the requester a short wrap-up email inviting them to reply if it is not actually fixed.',
                'trigger_event' => 'ticket.status_changed',
                'conditions'  => [
                    [
                        'field' => 'ticket.status_id',
                        'op'    => 'equals',
                        'value' => ['$lookup' => 'ticket_status', 'names' => ['Closed', 'Resolved', 'Complete', 'Completed', 'Done']],
                    ],
                ],
                'actions'     => [
                    [
                        'type' => 'send_email',
                        'args' => [
                            'ticket_id' => '{{ticket.id}}',
                            'subject'   => 'Your request is now closed [{{ticket.number}}]',
                            'body'      => "Hello,\n\nWe have closed your request:\n\n{{ticket.subject}}\n\nIf this is not resolved after all, just reply to this email and it will be reopened.\n\nThe Service Desk",
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------
            //  Tasks (GH #274)
            // ---------------------------------------------------------
            'task_done_note_on_ticket' => [
                'name'        => 'When a task is done, note it on its ticket',
                'category'    => 'Tasks',
                'description' => 'When a task linked to a ticket is completed, writes an internal note on that ticket naming the task and who owned it - so whoever has the ticket sees the work is finished without opening the task.',
                'trigger_event' => 'task.completed',
                'conditions'  => [
                    ['field' => 'task.ticket_id', 'op' => 'is_not_empty', 'value' => ''],
                ],
                'actions'     => [
                    [
                        'type' => 'add_ticket_note',
                        'args' => [
                            'ticket_id' => '{{task.ticket_id}}',
                            'note'      => "Task completed: {{task.title}} (owner: {{task.assignee_name}}).",
                            'write_to'  => 'note',
                            'shared'    => false,
                        ],
                    ],
                ],
            ],
            // ---------------------------------------------------------
            //  Issue trackers
            // ---------------------------------------------------------
            'tracker_escalate_bugs' => [
                'name'        => 'Raise a development issue for bugs',
                'category'    => 'Issue trackers',
                'description' => 'When a ticket is triaged as a bug, raise it in your issue tracker automatically, so nobody has to retype it into a second system.',
                'trigger_event' => 'ticket.status_changed',
                'conditions'  => [
                    [
                        'field' => 'ticket.type_id',
                        'op'    => 'equals',
                        'value' => ['$lookup' => 'ticket_type', 'names' => ['Bug', 'Fault', 'Defect', 'Incident']],
                    ],
                ],
                'actions'     => [
                    [
                        'type' => 'escalate_to_tracker',
                        'args' => [
                            'ticket_id'     => '{{ticket.id}}',
                            'connection_id' => ['$configure' => 'Which tracker connection to raise it in (System → Integrations)'],
                            // Deliberately blank: since #957 the connection's
                            // mapping decides the project and issue type, so a
                            // recipe does not have to guess at somebody's
                            // project keys. Type them here only to override it.
                            'project'       => '',
                            'issue_type'    => '',
                            'summary'       => '{{ticket.subject}}',
                            // Left on: a status-change trigger fires repeatedly
                            // on the same ticket, and without it every firing
                            // hands the developers another duplicate.
                            'skip_if_linked' => true,
                        ],
                    ],
                ],
            ],

            'tracker_tell_requester_when_done' => [
                'name'        => 'Tell the requester when the developers finish',
                'category'    => 'Issue trackers',
                'description' => 'When the linked issue reaches a done state in the tracker, note it on the ticket and email the person who reported it — so the answer gets back to them without anyone watching the tracker.',
                'trigger_event' => 'tracker.issue_status_changed',
                'conditions'  => [
                    [
                        // ⚠️ The CATEGORY, never a status name. Status names are
                        // per-project and renamed at will; the four categories
                        // are the only stable thing across every tracker.
                        'field' => 'tracker.status_category',
                        'op'    => 'equals',
                        'value' => 'done',
                    ],
                ],
                'actions'     => [
                    [
                        'type' => 'add_ticket_note',
                        'args' => [
                            'ticket_id' => '{{ticket.id}}',
                            'write_to'  => 'note',   // GH #268: a real note, by System
                            'note'      => "The development team have finished {{tracker.key}} ({{tracker.status_name}}).\n\n{{tracker.url}}",
                        ],
                    ],
                    [
                        'type' => 'send_email',
                        'args' => [
                            'ticket_id' => '{{ticket.id}}',
                            'subject'   => 'An update on your request [{{ticket.number}}]',
                            'body'      => "Hello,\n\nThe development team have finished work on the issue behind your request:\n\n{{ticket.subject}}\n\nWe will confirm once it has reached you. If anything still looks wrong, just reply to this email.\n\nThe Service Desk",
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------
            //  Incident response
            // ---------------------------------------------------------
            'p1_incident_response' => [
                'name'        => 'P1 incident response',
                'category'    => 'Incident response',
                'description' => 'A top-priority ticket arrives: alert the team in chat immediately and stamp the ticket so the response clock is visible in the audit trail.',
                'trigger_event' => 'ticket.created',
                'conditions'  => [
                    [
                        'field' => 'ticket.priority_id',
                        'op'    => 'equals',
                        'value' => ['$lookup' => 'ticket_priority', 'names' => ['P1', 'P1 - Critical', 'Critical', 'Urgent', 'Highest', 'High']],
                    ],
                ],
                'actions'     => [
                    [
                        'type' => 'send_webhook',
                        'args' => [
                            'preset'  => 'slack',
                            'url'     => ['$configure' => 'Your Slack incoming-webhook URL'],
                            'message' => ':rotating_light: *{{ticket.priority_name}} raised* — {{ticket.subject}} ({{ticket.number}}). Requester: {{ticket.requester_email}}',
                        ],
                    ],
                    [
                        'type' => 'add_ticket_note',
                        'args' => [
                            'ticket_id' => '{{ticket.id}}',
                            'write_to'  => 'note',   // GH #268: a real note, by System
                            'note'      => 'P1 workflow fired: the team has been alerted in chat.',
                        ],
                    ],
                ],
            ],

            'ticket_escalated_to_p1' => [
                'name'        => 'Alert when a ticket is escalated to P1',
                'category'    => 'Incident response',
                'description' => 'Catches the ticket that starts life as routine and gets escalated later — the case a create-time rule misses entirely.',
                'trigger_event' => 'ticket.priority_changed',
                'conditions'  => [
                    [
                        'field' => 'ticket.priority_id',
                        'op'    => 'equals',
                        'value' => ['$lookup' => 'ticket_priority', 'names' => ['P1', 'P1 - Critical', 'Critical', 'Urgent', 'Highest', 'High']],
                    ],
                ],
                'actions'     => [
                    [
                        'type' => 'send_webhook',
                        'args' => [
                            'preset'  => 'slack',
                            'url'     => ['$configure' => 'Your Slack incoming-webhook URL'],
                            'message' => ':arrow_up: *Escalated to {{ticket.priority_name}}* — {{ticket.subject}} ({{ticket.number}})',
                        ],
                    ],
                    [
                        'type' => 'add_ticket_note',
                        'args' => [
                            'ticket_id' => '{{ticket.id}}',
                            'write_to'  => 'note',   // GH #268: a real note, by System
                            'note'      => 'Escalated to P1 — the team has been alerted in chat.',
                        ],
                    ],
                ],
            ],

            'status_page_incident_broadcast' => [
                'name'        => 'Broadcast a status-page incident to the team',
                'category'    => 'Incident response',
                'description' => 'When you open an incident on the status page, push it straight into your team chat so internal staff hear it at the same time as customers.',
                'trigger_event' => 'service_status.incident_created',
                'conditions'  => [],
                'actions'     => [
                    [
                        'type' => 'send_webhook',
                        'args' => [
                            'preset'  => 'teams',
                            'url'     => ['$configure' => 'Your Microsoft Teams incoming-webhook URL'],
                            'message' => 'Status page incident opened: {{incident.title}}',
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------
            //  Onboarding — the cross-module fan-out
            // ---------------------------------------------------------
            'new_starter_onboarding' => [
                'name'        => 'New starter onboarding fan-out',
                'category'    => 'Onboarding',
                'description' => 'One form submission becomes the whole onboarding chain: an IT ticket for the account and kit, an HR ticket for the paperwork, a Facilities ticket for the desk and pass, and a task to chase the lot. This is the workflow engine earning its keep — point it at your own new-starter form and edit the wording.',
                'trigger_event' => 'form.submitted',
                'conditions'  => [
                    [
                        'field' => 'form.name',
                        'op'    => 'contains',
                        'value' => 'New starter',
                    ],
                ],
                'actions'     => [
                    [
                        'type' => 'create_ticket',
                        'args' => [
                            'subject'    => 'New starter — IT setup (from form {{form.name}})',
                            'body'       => "A new starter form has been submitted.\n\nPlease create the user account, mailbox and licences, and prepare the hardware.\n\nSubmission reference: {{submission.id}}",
                            'from_email' => '{{submission.email}}',
                        ],
                    ],
                    [
                        'type' => 'create_ticket',
                        'args' => [
                            'subject'    => 'New starter — HR paperwork (from form {{form.name}})',
                            'body'       => "A new starter form has been submitted.\n\nPlease action the contract, right-to-work checks and payroll setup.\n\nSubmission reference: {{submission.id}}",
                            'from_email' => '{{submission.email}}',
                        ],
                    ],
                    [
                        'type' => 'create_ticket',
                        'args' => [
                            'subject'    => 'New starter — desk, pass and access (from form {{form.name}})',
                            'body'       => "A new starter form has been submitted.\n\nPlease allocate a desk, issue a building pass and set up door access.\n\nSubmission reference: {{submission.id}}",
                            'from_email' => '{{submission.email}}',
                        ],
                    ],
                    [
                        'type' => 'create_task',
                        'args' => [
                            'title'       => 'Chase new-starter onboarding to completion',
                            'description' => "Raised automatically from new-starter form submission {{submission.id}}.\n\nCheck the IT, HR and Facilities tickets are all progressing and close this off on the starter's first day.",
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------
            //  Time-based (need the SLA / scheduled-trigger cron running)
            // ---------------------------------------------------------
            'sla_escalation' => [
                'name'        => 'Escalate before the SLA breaches',
                'category'    => 'SLA',
                'description' => 'The one every service desk wants: shout BEFORE you miss the target, not after. Fires when a ticket\'s SLA clock crosses the warning threshold — alerting the team in chat and stamping the ticket, while there is still time to save it. Needs the SLA breach-check cron scheduled.',
                'trigger_event' => 'sla.warning',
                'conditions'  => [
                    // Resolution only — a response-SLA warning on every new ticket
                    // would be noise, and it's the resolution clock people miss.
                    ['field' => 'sla.target', 'op' => 'equals', 'value' => 'resolution'],
                ],
                'actions'     => [
                    [
                        'type' => 'send_webhook',
                        'args' => [
                            'preset'  => 'slack',
                            'url'     => ['$configure' => 'Your Slack incoming-webhook URL'],
                            'message' => ':hourglass_flowing_sand: *SLA warning* — {{ticket.number}} ({{ticket.priority_name}}) is at {{sla.percent}}% of its resolution target, {{sla.remaining_minutes}} minutes left. {{ticket.subject}}',
                        ],
                    ],
                    [
                        'type' => 'add_ticket_note',
                        'args' => [
                            'ticket_id' => '{{ticket.id}}',
                            'write_to'  => 'note',   // GH #268: a real note, by System
                            'note'      => 'SLA warning: {{sla.percent}}% of the resolution target used, {{sla.remaining_minutes}} minutes remaining. The team has been alerted.',
                        ],
                    ],
                ],
            ],

            'sla_breach_alert' => [
                'name'        => 'Alert when an SLA is breached',
                'category'    => 'SLA',
                'description' => 'The backstop. When a target is actually missed, say so loudly and record it on the ticket, so a breach is never something you discover in a report a month later. Needs the SLA breach-check cron scheduled.',
                'trigger_event' => 'sla.breached',
                'conditions'  => [],
                'actions'     => [
                    [
                        'type' => 'send_webhook',
                        'args' => [
                            'preset'  => 'slack',
                            'message' => ':rotating_light: *SLA BREACHED* — {{ticket.number}} missed its {{sla.target}} target by {{sla.overdue_minutes}} minutes. {{ticket.subject}}',
                            'url'     => ['$configure' => 'Your Slack incoming-webhook URL'],
                        ],
                    ],
                    [
                        'type' => 'add_ticket_note',
                        'args' => [
                            'ticket_id' => '{{ticket.id}}',
                            'write_to'  => 'note',   // GH #268: a real note, by System
                            'note'      => 'SLA BREACHED: the {{sla.target}} target was missed by {{sla.overdue_minutes}} minutes.',
                        ],
                    ],
                ],
            ],

            'contract_renewal_reminder' => [
                'name'        => 'Contract renewal reminder',
                'category'    => 'Contracts',
                'description' => 'Raises a ticket 30 days before a contract ends, so a renewal is a decision you make rather than a deadline you discover. Fires at 90, 30, 7 and 1 days out — this recipe filters to the 30-day reminder; change the condition, or drop it, to get all four. Needs the scheduled-trigger cron.',
                'trigger_event' => 'contract.expiring',
                'conditions'  => [
                    ['field' => 'window_days', 'op' => 'equals', 'value' => '30'],
                ],
                'actions'     => [
                    [
                        'type' => 'create_ticket',
                        'args' => [
                            'subject' => 'Contract renewal due: {{contract.title}} ({{contract.number}})',
                            'body'    => "This contract ends on {{contract.end_date}} — {{contract.days_remaining}} days away.\n\nWith: {{contract.party_name}}\n\nDecide whether to renew, renegotiate or let it lapse, and action it before the end date.",
                        ],
                    ],
                ],
            ],

            'warranty_expiry_reminder' => [
                'name'        => 'Asset warranty expiry reminder',
                'category'    => 'Assets',
                'description' => 'Raises a ticket 30 days before an asset\'s warranty runs out, so you can extend it or plan the replacement while the machine is still covered. Needs the scheduled-trigger cron.',
                'trigger_event' => 'asset.warranty_expiring',
                'conditions'  => [
                    ['field' => 'window_days', 'op' => 'equals', 'value' => '30'],
                ],
                'actions'     => [
                    [
                        'type' => 'create_ticket',
                        'args' => [
                            'subject' => 'Warranty expiring: {{asset.hostname}}',
                            'body'    => "The warranty on {{asset.hostname}} expires on {{asset.warranty_end}} — {{asset.days_remaining}} days away.\n\nExtend the cover, or plan the replacement while it is still under warranty.",
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------
            //  Change management
            // ---------------------------------------------------------
            'change_high_risk_cab_review' => [
                'name'        => 'Flag a high-risk change for CAB review',
                'category'    => 'Change management',
                'description' => 'A change scored High risk or above should never reach the board as a surprise. Raises a review task and pings the channel the moment one is created — while there is still time to challenge it.',
                'trigger_event' => 'change.created',
                'conditions'  => [
                    // change.risk is derived from the risk score and is one of
                    // Low / Medium / High / Very High / Critical — the engine's
                    // own scale, not an install-renamable lookup, so this
                    // condition is safe to hardcode.
                    ['field' => 'change.risk', 'op' => 'in', 'value' => 'High, Very High, Critical'],
                ],
                'actions'     => [
                    [
                        'type' => 'create_task',
                        'args' => [
                            'title'       => 'CAB review: {{change.title}} ({{change.risk}} risk)',
                            'description' => "This change was raised at {{change.risk}} risk and needs a CAB decision.\n\nCheck the implementation plan, the back-out plan and the test evidence before it is approved.",
                            'ticket_id'   => '',
                        ],
                    ],
                    [
                        'type' => 'send_webhook',
                        'args' => [
                            'preset'  => 'slack',
                            'url'     => ['$configure' => 'Your Slack incoming-webhook URL'],
                            'message' => ':warning: *{{change.risk}}-risk change raised:* {{change.title}} — needs CAB review.',
                        ],
                    ],
                ],
            ],

            'change_approved_implementation_task' => [
                'name'        => 'Raise an implementation task when a change is approved',
                'category'    => 'Change management',
                'description' => 'Approval is not delivery. The moment CAB approves a change, this puts a task on the board so it does not sit approved-but-forgotten.',
                'trigger_event' => 'change.approved',
                'conditions'  => [],
                'actions'     => [
                    [
                        'type' => 'create_task',
                        'args' => [
                            'title'       => 'Implement approved change: {{change.title}}',
                            'description' => "Change {{change.id}} has been approved and is ready to schedule and implement.\n\nRisk: {{change.risk}}",
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------
            //  Knowledge
            // ---------------------------------------------------------
            'knowledge_published_announce' => [
                'name'        => 'Announce a newly published knowledge article',
                'category'    => 'Knowledge',
                'description' => 'Push new knowledge into the team chat as it is published, so the article gets read instead of quietly existing.',
                'trigger_event' => 'knowledge.published',
                'conditions'  => [],
                'actions'     => [
                    [
                        'type' => 'send_webhook',
                        'args' => [
                            'preset'  => 'slack',
                            'url'     => ['$configure' => 'Your Slack incoming-webhook URL'],
                            'message' => ':books: New knowledge article published: *{{article.title}}*',
                        ],
                    ],
                ],
            ],

            'knowledge_archived_orphan_check' => [
                'name'        => 'Check for orphaned links when an article is archived',
                'category'    => 'Knowledge',
                'description' => 'Archiving an article does not un-link it. Anything that pointed at it — a ticket reply, another article, a form — now points at nothing. This raises a task to go and fix the references while you still remember what the article said.',
                'trigger_event' => 'knowledge.archived',
                'conditions'  => [],
                'actions'     => [
                    [
                        'type' => 'create_task',
                        'args' => [
                            'title'       => 'Re-point anything that linked to the archived article: {{article.title}}',
                            'description' => "Article {{article.id}} ({{article.title}}) has been archived.\n\nFind what still links to it — tickets, other articles, canned replies — and either update the link or retire it.",
                            'ticket_id'   => '',
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------
            //  Problem management
            // ---------------------------------------------------------
            'problem_created_rca_task' => [
                'name'        => 'Start the root-cause investigation',
                'category'    => 'Problem management',
                'description' => 'A problem record without an owner and a deadline is a wish. Raises the RCA task the moment the problem is opened, so the investigation is scheduled rather than intended.',
                'trigger_event' => 'problem.created',
                'conditions'  => [],
                'actions'     => [
                    [
                        'type' => 'create_task',
                        'args' => [
                            'title'       => 'Root-cause analysis: {{problem.problem_number}} — {{problem.title}}',
                            'description' => "Investigate and record the root cause of {{problem.problem_number}}.\n\nWhen you know it, write it up as a known error so the service desk can recognise it next time.",
                            'ticket_id'   => '',
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------
            //  Assets
            // ---------------------------------------------------------
            'asset_assigned_acceptable_use' => [
                'name'        => 'Chase the paperwork when kit is issued',
                'category'    => 'Assets',
                'description' => 'The laptop is handed over and the acceptable-use form is never signed. Raises a task to collect it, naming the machine and the person, so the gap is visible instead of assumed.',
                'trigger_event' => 'asset.assigned',
                'conditions'  => [],
                'actions'     => [
                    [
                        'type' => 'create_task',
                        'args' => [
                            'title'       => 'Collect signed acceptable-use form: {{asset.hostname}} ({{user.name}})',
                            'description' => "{{asset.hostname}} has been issued to {{user.name}}.\n\nCollect the signed acceptable-use agreement and file it against the asset record.",
                            'ticket_id'   => '',
                        ],
                    ],
                ],
            ],

            'asset_unassigned_return_checklist' => [
                'name'        => 'Wipe-and-restock checklist when kit comes back',
                'category'    => 'Assets',
                'description' => 'A returned machine that is never wiped is a data-protection incident waiting to be discovered. Raises the return checklist the moment the asset is unassigned.',
                'trigger_event' => 'asset.unassigned',
                'conditions'  => [],
                'actions'     => [
                    [
                        'type' => 'create_task',
                        'args' => [
                            'title'       => 'Wipe and restock returned asset: {{asset.hostname}}',
                            'description' => "{{asset.hostname}} has been returned by {{user.name}}.\n\nWipe it, check it for damage, reset its status to spare/in-stock, and confirm nothing of theirs is left on it.",
                            'ticket_id'   => '',
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------
            //  Contracts and suppliers
            // ---------------------------------------------------------
            'contract_created_setup_task' => [
                'name'        => 'Set a new contract up properly',
                'category'    => 'Contracts',
                'description' => 'A contract added in a hurry with no end date and no payment schedule is the reason renewal reminders never fire. Raises a task to fill in the parts the reminders depend on.',
                'trigger_event' => 'contract.created',
                'conditions'  => [],
                'actions'     => [
                    [
                        'type' => 'create_task',
                        'args' => [
                            'title'       => 'Complete contract setup: {{contract.title}}',
                            'description' => "A new contract ({{contract.id}}) has been added.\n\nConfirm the start and end dates, the payment schedule and the notice period — the renewal reminders are driven off the end date, so a blank one means no reminder.",
                            'ticket_id'   => '',
                        ],
                    ],
                ],
            ],

            'supplier_created_due_diligence' => [
                'name'        => 'Due diligence on a new supplier',
                'category'    => 'Contracts',
                'description' => 'A new supplier is a new third party with access to something of yours. Raises the due-diligence task — security review, insurance, DPA — before anyone signs anything.',
                'trigger_event' => 'supplier.created',
                'conditions'  => [],
                'actions'     => [
                    [
                        'type' => 'create_task',
                        'args' => [
                            'title'       => 'Supplier due diligence: {{supplier.name}}',
                            'description' => "{{supplier.name}} has been added as a supplier.\n\nBefore they get access to anything: security review, insurance certificate, data-processing agreement, and a named contact for incidents.",
                            'ticket_id'   => '',
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------
            //  Morning checks
            // ---------------------------------------------------------
            'morning_check_failed_ticket' => [
                'name'        => 'Raise a ticket when a morning check fails',
                'category'    => 'Morning checks',
                'description' => 'A failed morning check that nobody actions is worse than no check at all — it looks like the system is watched. Raises a ticket on any result that is not a pass. Every install names its own statuses, so check the condition matches yours.',
                'trigger_event' => 'morning_check.recorded',
                'conditions'  => [
                    // Morning-check statuses are user-defined, and there is no
                    // template lookup for them — so this filters by NAME and is
                    // deliberately generous. If your desk calls a pass something
                    // else, say so here.
                    ['field' => 'result.status_name', 'op' => 'not_in', 'value' => 'OK, Pass, Passed, Green, Good, Healthy'],
                ],
                'actions'     => [
                    [
                        'type' => 'create_ticket',
                        'args' => [
                            'subject' => 'Morning check failed: {{check.name}} ({{result.date}})',
                            'body'    => "The morning check *{{check.name}}* was recorded as *{{result.status_name}}* on {{result.date}}.\n\nInvestigate and record what you found — a check that fails quietly is a check nobody is doing.",
                            'priority_id' => ['$lookup' => 'ticket_priority', 'names' => ['P2', 'High', 'P2 - High', 'Medium', 'Normal']],
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------
            //  Software
            // ---------------------------------------------------------
            'software_discovered_shadow_it' => [
                'name'        => 'Shadow-IT alert on newly discovered software',
                'category'    => 'Software',
                'description' => 'Discovery finds an application nobody approved. Raises a ticket naming the app and its publisher so someone decides whether it is fine, licensed, or needs removing — rather than it simply spreading.',
                'trigger_event' => 'software.application_discovered',
                'conditions'  => [],
                'actions'     => [
                    [
                        'type' => 'create_ticket',
                        'args' => [
                            'subject' => 'New software discovered: {{application.name}}',
                            'body'    => "Discovery has found an application that was not previously in the inventory.\n\nApplication: {{application.name}}\nPublisher: {{application.publisher}}\n\nDecide whether it is approved, needs a licence, or needs removing.",
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------
            //  CMDB
            // ---------------------------------------------------------
            'cmdb_object_deleted_alert' => [
                'name'        => 'Tell someone when a CI is deleted',
                'category'    => 'CMDB',
                'description' => 'CIs are deleted rarely and consequentially — relationships, dependencies and impact analysis all silently lose a node. Announces it, so a mistaken deletion is caught in minutes rather than the next time someone asks what depends on what.',
                'trigger_event' => 'cmdb.object.deleted',
                'conditions'  => [],
                'actions'     => [
                    [
                        'type' => 'send_webhook',
                        'args' => [
                            'preset'  => 'slack',
                            'url'     => ['$configure' => 'Your Slack incoming-webhook URL'],
                            'message' => ':wastebasket: *CI deleted from the CMDB:* {{object.name}} — check nothing depended on it.',
                        ],
                    ],
                    [
                        'type' => 'log_message',
                        'args' => [
                            'message' => 'CMDB object deleted: {{object.name}} (id {{object.id}})',
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------
            //  Incident response (all-clear)
            // ---------------------------------------------------------
            'status_page_incident_all_clear' => [
                'name'        => 'Sound the all-clear when an incident is resolved',
                'category'    => 'Incident response',
                'description' => 'Teams remember the outage and never hear it ended, so they keep escalating a service that is already back. Pairs with the incident broadcast: same channel, closing the loop.',
                'trigger_event' => 'service_status.incident_resolved',
                'conditions'  => [],
                'actions'     => [
                    [
                        'type' => 'send_webhook',
                        'args' => [
                            'preset'  => 'slack',
                            'url'     => ['$configure' => 'Your Slack incoming-webhook URL'],
                            'message' => ':white_check_mark: *Resolved:* {{incident.title}} — the service is back to normal.',
                        ],
                    ],
                ],
            ],

            // ---------------------------------------------------------
            //  Audit
            // ---------------------------------------------------------
            'ticket_deleted_audit_trail' => [
                'name'        => 'Log every ticket that is binned',
                'category'    => 'Audit',
                'description' => 'Tickets go to the trash for good reasons and occasionally for bad ones. Writes an execution-log entry for every deletion, naming the ticket — a quiet, permanent record that costs nothing and answers "what happened to it?" months later.',
                'trigger_event' => 'ticket.deleted',
                'conditions'  => [],
                'actions'     => [
                    [
                        'type' => 'log_message',
                        'args' => [
                            'message' => 'Ticket binned: #{{ticket.number}} — {{ticket.subject}} (requester {{ticket.requester_email}})',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * One template by key, or null.
     *
     * @param string $key  the template's key in all(), e.g. 'ticket_deleted_audit_trail'
     */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * The catalogue as the gallery UI wants it: no conditions/actions bodies,
     * just what a human needs to choose — plus a plain-English step list so
     * the card can show what the recipe will actually do.
     */
    public static function catalogue(): array
    {
        $triggers = WorkflowEngine::availableTriggers();
        $actions  = WorkflowEngine::availableActions();
        $out = [];
        foreach (self::all() as $key => $t) {
            $steps = [];
            foreach ($t['actions'] as $a) {
                $steps[] = $actions[$a['type']]['label'] ?? $a['type'];
            }
            $out[] = [
                'key'            => $key,
                'name'           => $t['name'],
                'category'       => $t['category'],
                'description'    => $t['description'],
                'trigger_event'  => $t['trigger_event'],
                'trigger_label'  => $triggers[$t['trigger_event']] ?? $t['trigger_event'],
                'condition_count'=> count($t['conditions']),
                'steps'          => $steps,
            ];
        }
        return $out;
    }

    /**
     * Turn a template into a concrete workflow for THIS install: resolve every
     * `$lookup` marker against the local lookup tables and blank out every
     * `$configure` marker.
     *
     * Returns:
     *   [
     *     'name', 'description', 'trigger_event', 'conditions', 'actions',
     *     'unresolved' => [ ['where' => 'Action 1 · Assign to', 'reason' => '...'], ... ],
     *   ]
     *
     * Never throws on an unresolvable value — an install that has renamed
     * every priority should still get the workflow, with an honest list of
     * what it needs to fill in.
     *
     * @param array $tpl  one template from all() (name, description, trigger_event, conditions, actions)
     */
    public static function resolve(array $tpl): array
    {
        $unresolved = [];
        $actionSpecs = WorkflowEngine::availableActions();

        $conditions = [];
        foreach ($tpl['conditions'] as $i => $c) {
            $where = 'Condition ' . ($i + 1) . ' · ' . ($c['field'] ?? '');
            $c['value'] = self::resolveValue($c['value'] ?? null, $where, $unresolved);
            $conditions[] = $c;
        }

        $actions = [];
        foreach ($tpl['actions'] as $i => $a) {
            $actionLabel = $actionSpecs[$a['type']]['label'] ?? $a['type'];
            $args = [];
            foreach (($a['args'] ?? []) as $argKey => $val) {
                $argLabel = $actionSpecs[$a['type']]['args'][$argKey]['label'] ?? $argKey;
                $where = 'Action ' . ($i + 1) . ' (' . $actionLabel . ') · ' . $argLabel;
                $args[$argKey] = self::resolveValue($val, $where, $unresolved);
            }
            $a['args'] = $args;
            $actions[] = $a;
        }

        return [
            'name'          => $tpl['name'],
            'description'   => $tpl['description'],
            'trigger_event' => $tpl['trigger_event'],
            'conditions'    => $conditions,
            'actions'       => $actions,
            'unresolved'    => $unresolved,
        ];
    }

    /**
     * Resolve one template value. Literals pass straight through; markers are
     * expanded (or recorded as unresolved and blanked).
     *
     * @param mixed $val  a condition value or action arg: a literal, or a ['$lookup' => ..., 'names' => [...]]
     *     or ['$configure' => reason] marker
     * @param string $where  where the value sits, in words, for the unresolved list, e.g. 'Action 1 (...) · Assign to'
     * @param array $unresolved  by reference; a [where, reason] entry is added for each value left blank
     */
    private static function resolveValue($val, string $where, array &$unresolved)
    {
        if (!is_array($val)) {
            return $val;
        }

        if (isset($val['$configure'])) {
            $unresolved[] = ['where' => $where, 'reason' => $val['$configure']];
            return '';
        }

        if (isset($val['$lookup'])) {
            $names = $val['names'] ?? [];
            $rows  = WorkflowEngine::availableActionLookup($val['$lookup']);
            if (!$rows) {
                $unresolved[] = [
                    'where'  => $where,
                    'reason' => 'Could not read this install\'s ' . str_replace('_', ' ', $val['$lookup']) . ' list — pick a value yourself.',
                ];
                return '';
            }
            foreach ($names as $wanted) {
                foreach ($rows as $row) {
                    if (strcasecmp(trim($row['label']), trim($wanted)) === 0) {
                        return $row['id'];
                    }
                }
            }
            $unresolved[] = [
                'where'  => $where,
                'reason' => 'This install has no ' . str_replace('_', ' ', $val['$lookup'])
                          . ' named ' . self::humanList($names) . ' — pick the closest one yourself.',
            ];
            return '';
        }

        // Some other array — pass through untouched.
        return $val;
    }

    /**
     * ['A', 'B', 'C'] → "A, B or C" — for the unresolved-reason sentences.
     *
     * @param array $names  the names to list, quoted in the result
     */
    private static function humanList(array $names): string
    {
        $names = array_map(fn($n) => '"' . $n . '"', $names);
        if (count($names) <= 1) return $names[0] ?? '';
        $last = array_pop($names);
        return implode(', ', $names) . ' or ' . $last;
    }
}
