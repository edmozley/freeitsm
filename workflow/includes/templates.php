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
                            'body'    => "This contract ends on {{contract.end_date}} — {{contract.days_remaining}} days away.\n\nSupplier: {{contract.supplier_name}}\n\nDecide whether to renew, renegotiate or let it lapse, and action it before the end date.",
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
        ];
    }

    /**
     * One template by key, or null.
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
     */
    private static function humanList(array $names): string
    {
        $names = array_map(fn($n) => '"' . $n . '"', $names);
        if (count($names) <= 1) return $names[0] ?? '';
        $last = array_pop($names);
        return implode(', ', $names) . ' or ' . $last;
    }
}
