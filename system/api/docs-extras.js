/**
 * FreeITSM REST API docs — per-endpoint examples and endpoint-specific errors.
 * Loaded by system/api/docs.php; keyed "METHOD /path" matching the SPEC array.
 *
 * Shape per endpoint:
 *   examples: [{ title,                    // chip label, a few words
 *                note,                     // one sentence: what this shows a beginner
 *                path:  {param: value},    // fills path inputs
 *                query: {param: value},    // fills query inputs
 *                body:  {...} }]           // replaces the body editor (POST/PATCH only)
 *   errors:   [{ code, slug, when }]       // endpoint-SPECIFIC errors only —
 *                                          // 401/403/404/422/429 are derived automatically.
 */
window.API_EXTRAS = {

'GET /ping': {
    examples: [
        {title: 'Check your key', note: 'The simplest possible call — no parameters. Returns what the key may do: its permissions, company scope and expiry.'},
    ],
},
'GET /': {
    examples: [
        {title: 'List every endpoint', note: 'Returns the API version and the full route index — handy for discovering what exists.'},
    ],
},

// ===== extras_core =====
'GET /analysts': {
    examples: [
        {title: 'All analysts', note: 'No parameters — the active analysts eligible for ticket and task assignment.'},
    ],
},
'GET /companies': {
    examples: [
        {title: 'All companies', note: 'No parameters — the companies (tenants) this key is scoped to see; single-company installs get one entry.'},
    ],
},
'GET /statuses': {
    examples: [
        {title: 'All statuses', note: 'No parameters — every ticket status with its closed/default/SLA-pausing flags and colour.'},
    ],
},
'GET /priorities': {
    examples: [
        {title: 'All priorities', note: 'No parameters — every ticket priority with its SLA response and resolution targets in minutes.'},
    ],
},
'GET /ticket-types': {
    examples: [
        {title: 'All ticket types', note: 'No parameters — the install-wide list.'},
        {title: "One company's view", note: "company_id applies that company's add/hide overrides — what its analysts actually see.", query: {company_id: '2'}},
    ],
},
'GET /origins': {
    examples: [
        {title: 'All origins', note: 'No parameters — the install-wide list of how tickets can arrive (email, phone, portal, …).'},
        {title: "One company's view", note: "company_id applies that company's add/hide overrides — what its analysts actually see.", query: {company_id: '2'}},
    ],
},
'GET /departments': {
    examples: [
        {title: 'All departments', note: 'No parameters — the full department list used to classify tickets.'},
    ],
},
'GET /asset-types': {
    examples: [
        {title: 'All asset types', note: 'No parameters — the categories assets are classified under (laptop, monitor, …).'},
    ],
},
'GET /asset-statuses': {
    examples: [
        {title: 'All asset statuses', note: 'No parameters — the lifecycle statuses an asset can hold (In Use, In Stock, Retired, …).'},
    ],
},
'GET /asset-locations': {
    examples: [
        {title: 'All locations', note: 'No parameters — every location flattened, each with its full path (e.g. "UK › London › Office 1").'},
    ],
},
'GET /suppliers': {
    examples: [
        {title: 'All suppliers', note: 'No parameters — every supplier with its display name and whether it supplies assets.'},
    ],
},
'GET /problem-statuses': {
    examples: [
        {title: 'All problem statuses', note: 'No parameters — every problem status with its closed/default flag and colour.'},
    ],
},
'GET /problem-priorities': {
    examples: [
        {title: 'All problem priorities', note: 'No parameters — the priority list used to classify problems.'},
    ],
},
'GET /change-statuses': {
    examples: [
        {title: 'All change statuses', note: 'No parameters — the full change lifecycle (Draft → … → Completed) with colours.'},
    ],
},
'GET /change-types': {
    examples: [
        {title: 'All change types', note: 'No parameters — Standard, Normal, Emergency, plus any custom types.'},
    ],
},
'GET /change-priorities': {
    examples: [
        {title: 'All change priorities', note: 'No parameters — the priority list used to classify changes.'},
    ],
},
'GET /change-impacts': {
    examples: [
        {title: 'All change impacts', note: 'No parameters — the impact levels used to classify changes.'},
    ],
},
'GET /change-categories': {
    examples: [
        {title: 'All change categories', note: 'No parameters — empty on a fresh install until categories are configured in Changes > Settings.'},
    ],
},
'GET /knowledge/tags': {
    examples: [
        {title: 'All tags', note: 'No parameters — every knowledge-base tag with its published-article count.'},
    ],
},
'GET /task-statuses': {
    examples: [
        {title: 'All task statuses', note: 'No parameters — the board columns (To Do → … → Done) with colour and display order.'},
    ],
},
'GET /task-priorities': {
    examples: [
        {title: 'All task priorities', note: 'No parameters — the priority list used to classify tasks.'},
    ],
},
'GET /task-tags': {
    examples: [
        {title: 'All task tags', note: 'No parameters — the curated tag list with colours and usage counts.'},
    ],
},
'GET /cmdb-relationship-types': {
    examples: [
        {title: 'All relationship types', note: 'No parameters — the verb / inverse-verb pairs used to link CMDB items (e.g. "depends on" / "is depended on by").'},
    ],
},
'GET /contract-statuses': {
    examples: [
        {title: 'All contract statuses', note: 'No parameters — populated via Contracts > Settings; empty on a fresh install.'},
    ],
},
'GET /payment-schedules': {
    examples: [
        {title: 'All payment schedules', note: 'No parameters — the billing frequencies available to contracts (Monthly, Annually, …).'},
    ],
},
'GET /supplier-types': {
    examples: [
        {title: 'All supplier types', note: 'No parameters — the categories a supplier can be classified under.'},
    ],
},
'GET /supplier-statuses': {
    examples: [
        {title: 'All supplier statuses', note: 'No parameters — the status options a supplier record can hold.'},
    ],
},
'GET /contract-term-tabs': {
    examples: [
        {title: 'All term tabs', note: 'No parameters — the configured contract term tabs (Termination, Liability, …).'},
    ],
},
'GET /calendar-categories': {
    examples: [
        {title: 'All categories', note: 'No parameters — every calendar category with its colour and event count.'},
    ],
},
'GET /service-incident-statuses': {
    examples: [
        {title: 'All incident statuses', note: 'No parameters — the Service Status lifecycle (Investigating → … → Resolved) with is_resolved flags and colours.'},
    ],
},
'GET /service-impact-levels': {
    examples: [
        {title: 'All impact levels', note: 'No parameters — the impact levels ordered worst-first (severity_order 1 = Major Outage).'},
    ],
},
'GET /morning-check-statuses': {
    examples: [
        {title: 'All statuses', note: 'No parameters — the status options (Green / Amber / Red by default); inactive ones are included so historic checks always resolve.'},
    ],
},
'GET /workflow-triggers': {
    examples: [
        {title: 'All triggers', note: 'No parameters — every trigger event a workflow can fire on, with its condition fields and valid operators.'},
    ],
},
'GET /workflow-actions': {
    examples: [
        {title: 'All actions', note: 'No parameters — every action type a workflow step can run, with its args spec and template-variable support.'},
    ],
},
'GET /cmdb-icons': {
    examples: [
        {title: 'All icons', note: 'No parameters — every icon key usable as a CMDB class icon or a diagram node\'s icon_override.'},
    ],
},

// ===== extras_tickets =====
'GET /tickets': {
    examples: [
        {title: 'Open tickets', note: 'The simplest useful list — open tickets, newest first.', query: {state: 'open'}},
        {title: 'Search + filter combined', note: 'q searches subjects/ticket numbers; filters stack with pagination.', query: {q: 'printer', state: 'open', per_page: '10'}},
        {title: 'Sync changed tickets', note: 'An integration polling for changes since its last run.', query: {updated_since: '2026-07-01T00:00:00Z', per_page: '100'}},
    ],
    errors: [
        {code: 400, slug: 'invalid_parameter', when: 'created_since / created_before / updated_since / closed_since is not a parseable date.'},
        {code: 400, slug: 'invalid_parameter', when: "sort names a field that isn't sortable."},
    ],
},

'POST /tickets': {
    examples: [
        {title: 'Simplest ticket', note: 'Only subject and requester_email are required — everything else defaults (status Open, priority Normal).', body: {subject: 'Printer offline', requester_email: 'sam@example.com'}},
        {title: 'With description & priority', note: 'A new requester is created automatically since sam@example.com doesn\'t exist yet.', body: {subject: 'Laptop won\'t boot', requester_email: 'sam@example.com', requester_name: 'Sam Jones', description: 'Blue screen on startup, happens every time.', priority: 'High'}},
        {title: 'Filed under a company, from a mailbox', note: 'A realistic integration scenario: a monitoring tool raises a ticket for a specific company and pins a reply-from mailbox.', body: {subject: 'VPN keeps dropping', requester_email: 'jane@example.com', requester_name: 'Jane Smith', description: 'Connection drops every 10 minutes since this morning.', priority: 'High', company_id: 2, mailbox_id: 1}},
    ],
    errors: [
        {code: 422, slug: 'missing_field', when: "'subject' is empty or missing."},
        {code: 422, slug: 'missing_field', when: "'requester_email' is missing or not a valid email address."},
        {code: 422, slug: 'invalid_field', when: "status / priority / ticket_type_id / origin_id / department_id / assigned_analyst_id / mailbox_id / company_id don't resolve to a known (or active) row."},
        {code: 403, slug: 'forbidden', when: "company_id is set but the key isn't scoped to that company."},
    ],
},

'GET /tickets/{id}': {
    examples: [
        {title: 'Get a ticket', note: 'Full ticket detail, including the original request body.', path: {id: '42'}},
    ],
},

'PATCH /tickets/{id}': {
    examples: [
        {title: 'Reassign', note: 'The simplest update — hand the ticket to another analyst.', path: {id: '42'}, body: {assigned_analyst_id: 1}},
        {title: 'Progress + priority together', note: 'Multiple fields can change in one call; only the ones you send are touched.', path: {id: '42'}, body: {status: 'In Progress', priority: 'High', assigned_analyst_id: 1}},
        {title: 'Close and mark first-time fix', note: 'A realistic close-out: moving to a closed status stamps closed_at and can auto-send the CSAT survey.', path: {id: '42'}, body: {status: 'Resolved', first_time_fix: true}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'The ticket is in the trash — restore it before updating.'},
        {code: 422, slug: 'missing_field', when: 'The body has no fields to update.'},
        {code: 422, slug: 'invalid_field', when: "subject is sent as an empty string."},
        {code: 422, slug: 'invalid_field', when: "status / priority / ticket_type_id / origin_id / department_id / assigned_analyst_id / company_id don't resolve to a known (or active) row."},
        {code: 403, slug: 'forbidden', when: "company_id is set to a company the key isn't scoped to."},
    ],
},

'DELETE /tickets/{id}': {
    examples: [
        {title: 'Trash a ticket', note: 'Soft-delete — the ticket moves to the trash and can be restored later.', path: {id: '42'}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'The ticket is already in the trash.'},
    ],
},

'POST /tickets/{id}/restore': {
    examples: [
        {title: 'Restore from trash', note: 'Brings a trashed ticket back.', path: {id: '42'}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'The ticket is not in the trash.'},
    ],
},

'GET /tickets/{id}/notes': {
    examples: [
        {title: 'List notes', note: 'All notes on the ticket, oldest first.', path: {id: '42'}},
    ],
},

'POST /tickets/{id}/notes': {
    examples: [
        {title: 'Add an internal note', note: 'Notes default to internal (private to analysts).', path: {id: '42'}, body: {text: 'Chased the supplier — replacement part due Friday.'}},
        {title: 'Add a public note', note: 'is_internal: false makes the note visible to the requester.', path: {id: '42'}, body: {text: 'We\'re aware of the issue and working on a fix.', is_internal: false}},
        {title: 'Automation note', note: 'A realistic integration scenario: a monitoring workflow logs an escalation note.', path: {id: '42'}, body: {text: 'Auto-escalated: no vendor response in 48 hours.', is_internal: true}},
    ],
    errors: [
        {code: 422, slug: 'missing_field', when: "'text' is empty or missing."},
    ],
},

'GET /tickets/{id}/thread': {
    examples: [
        {title: 'Get the conversation', note: 'The full message thread — inbound and outbound — oldest first, with HTML bodies.', path: {id: '42'}},
    ],
},

'GET /tickets/{id}/audit': {
    examples: [
        {title: 'Get the change history', note: 'Every audited change with old/new values and who made it.', path: {id: '42'}},
    ],
},

'GET /tickets/{id}/sla': {
    examples: [
        {title: 'Get live SLA state', note: 'Response/resolution targets, elapsed and remaining time, and breach flags — computed live.', path: {id: '42'}},
    ],
},

'GET /tickets/{id}/time-entries': {
    examples: [
        {title: 'List time entries', note: 'Active time logged against the ticket.', path: {id: '42'}},
    ],
},

'POST /tickets/{id}/time-entries': {
    examples: [
        {title: 'Log time', note: 'The simplest entry — just the minutes spent.', path: {id: '42'}, body: {minutes: 30}},
        {title: 'Log time with notes', note: 'Add a note describing what the time was spent on.', path: {id: '42'}, body: {minutes: 30, notes: 'Remote session with the user'}},
        {title: 'Backdated entry', note: 'A realistic integration scenario: logging yesterday\'s on-site visit after the fact.', path: {id: '42'}, body: {minutes: 45, notes: 'On-site hardware swap', entry_at: '2026-07-03T14:30:00Z'}},
    ],
    errors: [
        {code: 422, slug: 'missing_field', when: "'minutes' is missing, zero or not a positive integer."},
    ],
},

'DELETE /tickets/{id}/time-entries/{entry_id}': {
    examples: [
        {title: 'Remove a time entry', note: 'Soft-deletes the entry, same as the UI.', path: {id: '42', entry_id: '7'}},
    ],
},

'GET /users': {
    examples: [
        {title: 'Search requesters', note: 'q searches email, display name and preferred name.', query: {q: 'jane'}},
        {title: 'Exact email lookup', note: 'Find one requester by their exact email address.', query: {email: 'jane@example.com'}},
        {title: 'Paginated directory sync', note: 'A realistic integration scenario: pulling requesters for a domain in pages.', query: {q: 'example.com', per_page: '50'}},
    ],
},

'GET /users/{id}': {
    examples: [
        {title: 'Get a requester', note: 'Includes their ticket counts, scoped to the key\'s companies.', path: {id: '12'}},
    ],
},

'POST /users': {
    examples: [
        {title: 'Simplest requester', note: 'Only email is required — display_name defaults from the address.', body: {email: 'sam@example.com'}},
        {title: 'With a display name', note: 'Set the name shown in the UI instead of the auto-generated one.', body: {email: 'sam@example.com', display_name: 'Sam Jones'}},
        {title: 'Directory sync', note: 'A realistic integration scenario: creating a requester from an HR/directory feed.', body: {email: 'jane.smith@example.com', display_name: 'Jane Smith', preferred_name: 'Jane'}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'A requester with this email already exists.'},
    ],
},

'PATCH /users/{id}': {
    examples: [
        {title: 'Rename', note: 'Update the display name only.', path: {id: '12'}, body: {display_name: 'Sam Jones-Smith'}},
        {title: 'Change email', note: 'Update the email address on file.', path: {id: '12'}, body: {email: 'sam.jones@example.com'}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: "email is not a valid email address."},
        {code: 409, slug: 'conflict', when: 'Another requester already uses this email.'},
        {code: 422, slug: 'missing_field', when: 'The body has no fields to update.'},
    ],
},

// ===== extras_assets =====
'GET /assets': {
    examples: [
        {title: 'List all assets', note: 'The simplest possible call — no filters, sorted alphabetically by hostname.'},
        {title: 'Laptops in a location, newest first', note: 'Combine a classification filter with location and sort to narrow the list to something specific.',
         query: {asset_type_id: '2', location_id: '5', sort: '-last_seen'}},
        {title: 'Warranty report for renewals', note: 'The shape the dashboard uses to find hardware whose warranty is running out, so you can plan renewals or replacements.',
         query: {warranty_within_days: '30', sort: 'warranty_expiry'}},
    ],
    errors: [
        {code: 400, slug: 'invalid_parameter', when: "'sort' isn't one of the sortable fields (hostname, id, first_seen, last_seen, warranty_expiry, purchase_date, model)."},
    ],
},
'POST /assets': {
    examples: [
        {title: 'Register a bare asset', note: 'The only required field is hostname — every other field can be filled in later.',
         body: {hostname: 'LT-1050'}},
        {title: 'Register with classification', note: 'Set the type and location at creation time, alongside basic hardware identity.',
         body: {hostname: 'LT-1051', asset_type_id: 1, location_id: 2, manufacturer: 'Dell', model: 'Latitude 5440', service_tag: 'SVCTAG991'}},
        {title: 'Agent enrolment', note: 'The full sweep an inventory agent sends the first time it sees a machine — hardware, OS and warranty together.',
         body: {hostname: 'LT-1052', asset_type_id: 1, manufacturer: 'Dell', model: 'Latitude 5440', service_tag: 'SVCTAG992', memory: 16, cpu_name: 'Intel Core i7-1355U', operating_system: 'Windows 11 Pro', feature_release: '23H2', build_number: '22631', domain: 'corp.example.com', warranty_expiry: '2028-06-30'}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: "'hostname' matches an existing asset — every ingest path (agent, Intune sync) upserts on hostname, so duplicates are refused; PATCH the existing asset instead."},
        {code: 422, slug: 'missing_field', when: "'hostname' is blank or missing."},
        {code: 422, slug: 'invalid_field', when: 'a lookup field (asset_type_id, asset_status_id, location_id, supplier_id) is an id that does not exist.'},
    ],
},
'GET /assets/{id}': {
    examples: [
        {title: 'Get full asset detail', note: 'Hardware, OS, network, lifecycle and location path in one call, plus who currently has it.',
         path: {id: '42'}},
    ],
},
'PATCH /assets/{id}': {
    examples: [
        {title: 'Mark as retired', note: 'The smallest useful update — change one field and everything else is left alone.',
         path: {id: '42'}, body: {asset_status_id: 4}},
        {title: 'Update lifecycle fields together', note: 'Move an asset to a new location and record its updated warranty and purchase order in one call.',
         path: {id: '42'}, body: {location_id: 3, supplier_id: 2, warranty_expiry: '2028-06-30', order_number: 'PO-10453'}},
        {title: 'Re-sync hardware from an integration', note: 'The kind of update a sync job (e.g. Intune) sends when it detects hardware or OS drift on an existing asset.',
         path: {id: '42'}, body: {manufacturer: 'Dell', model: 'Latitude 5440', operating_system: 'Windows 11 Pro', build_number: '22631', memory: 16, cpu_name: 'Intel Core i7-1355U'}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: "'hostname' is changed to a value another asset already uses."},
        {code: 422, slug: 'invalid_field', when: "'hostname' is set to blank (it cannot be cleared), or a lookup field is an id that does not exist."},
    ],
},
'GET /assets/{id}/assignments': {
    examples: [
        {title: 'Who has this asset', note: 'The requesters currently assigned, with dates, due-back and who assigned it.',
         path: {id: '42'}},
    ],
},
'POST /assets/{id}/assignments': {
    examples: [
        {title: 'Assign by requester id', note: 'The simplest checkout — the requester must already exist.',
         path: {id: '42'}, body: {user_id: '17'}},
        {title: 'Assign by email with due-back date', note: 'Look the requester up by email instead of id, and record when it should be returned.',
         path: {id: '42'}, body: {user_email: 'jane@example.com', notes: 'Loan laptop', expected_return_date: '2026-08-01'}},
        {title: 'Onboarding checkout', note: 'The call an HR onboarding automation makes to hand a new starter their laptop as part of the joiner workflow.',
         path: {id: '42'}, body: {user_email: 'new.starter@example.com', notes: 'New starter kit — issued on day one', expected_return_date: '2026-08-15'}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'this requester is already assigned to this asset.'},
        {code: 422, slug: 'missing_field', when: "neither 'user_id' nor 'user_email' is provided."},
        {code: 422, slug: 'invalid_field', when: "the requester id or email doesn't match an existing user — create them first with POST /users."},
    ],
},
'DELETE /assets/{id}/assignments/{user_id}': {
    examples: [
        {title: 'Check in a returned asset', note: 'Removes the assignment and logs the check-in — the requester and asset must currently be linked.',
         path: {id: '42', user_id: '17'}},
    ],
},
'GET /assets/{id}/history': {
    examples: [
        {title: 'View the change history', note: 'Every audited change — type, status, location, warranty, assignments — newest first.',
         path: {id: '42'}},
    ],
},
'GET /assets/{id}/custody': {
    examples: [
        {title: 'View the custody trail', note: 'Every check-out and check-in for this asset, with who processed it and when.',
         path: {id: '42'}},
    ],
},
'GET /assets/{id}/disks': {
    examples: [
        {title: 'View disk usage', note: 'Agent-collected drives with size, free space and used percent — useful for spotting machines running low on disk.',
         path: {id: '42'}},
    ],
},
'GET /assets/{id}/network-adapters': {
    examples: [
        {title: 'View network adapters', note: 'Agent-collected adapters with MAC, IP, subnet, gateway and DHCP status.',
         path: {id: '42'}},
    ],
},
'GET /assets/{id}/devices': {
    examples: [
        {title: 'View device-manager entries', note: 'Agent-collected devices with class, status and driver info — handy for spotting driver problems.',
         path: {id: '42'}},
    ],
},
'GET /assets/{id}/software': {
    examples: [
        {title: 'List installed software', note: 'System components are excluded by default, leaving the software a user actually installed.',
         path: {id: '42'}},
        {title: 'Software audit including system components', note: 'A full license/compliance sweep that also includes OS-bundled components.',
         path: {id: '42'}, query: {include_components: 'true'}},
    ],
},

// ===== extras_problems =====
/**
 * Draft API_EXTRAS entries for the Problems + "Problem notes, history & links"
 * sections. Merge these keys into window.API_EXTRAS in system/api/docs-extras.js.
 */

'GET /problems': {
    examples: [
        {title: 'Open problems', note: 'The simplest useful call — just the open/closed filter, so you see everything still being worked.',
         query: {state: 'open'}},
        {title: 'High-priority problems for one analyst', note: 'Combine filters: only High priority problems assigned to a specific analyst.',
         query: {state: 'open', priority: 'High', assigned_analyst_id: '4'}},
        {title: 'Find recurring printer incidents', note: 'A realistic sweep before writing a problem record: search title/number text, exclude anything already flagged as a known error, newest first.',
         query: {q: 'printer', is_known_error: 'false', sort: '-created_at', per_page: '10'}},
    ],
    errors: [
        {code: 400, slug: 'invalid_parameter', when: "sort is not one of the sortable fields (created_at, updated_at, closed_at, id, title, problem_number, priority, status)"},
    ],
},

'POST /problems': {
    examples: [
        {title: 'Minimal problem', note: 'The only required field is title — status defaults to the module\'s default status.',
         body: {title: 'Printer offline across 3rd floor'}},
        {title: 'With priority and description', note: 'Add a description and set the priority by name in the same call.',
         body: {title: 'Recurring VPN drops on London office link', description: 'Multiple users report VPN disconnects since 28 June.', priority: 'High'}},
        {title: 'Recurring printer incidents become a problem', note: 'A realistic ITIL flow: after spotting a pattern across several incidents, raise the problem record itself, already flagged as a known error with the workaround analysts should give callers in the meantime.',
         body: {title: 'Recurring paper jams on 3rd-floor printer (PRN-14)', description: 'Six separate incidents this month reporting the same jam on tray 2.', priority: 'Medium', is_known_error: true, workaround: 'Ask users to print to PRN-12 until the roller is replaced.'}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: 'status, status_id, priority or priority_id does not match a known lookup name/id'},
        {code: 422, slug: 'invalid_field', when: 'company_id is not a recognised company id'},
        {code: 403, slug: 'forbidden', when: "company_id is valid but outside the API key's company scope"},
    ],
},

'GET /problems/{id}': {
    examples: [
        {title: 'Get a problem', note: 'Fetches full detail for one problem, including its linked incidents and linked changes inline.',
         path: {id: '3'}},
    ],
},

'PATCH /problems/{id}': {
    examples: [
        {title: 'Change status', note: 'The simplest update — send only the field that changed.',
         path: {id: '3'}, body: {status: 'Investigating'}},
        {title: 'Reassign and reprioritise', note: 'Combine several fields in one PATCH; only what you send is changed and audited.',
         path: {id: '3'}, body: {assigned_analyst_id: 4, priority: 'High'}},
        {title: 'Record the root cause and close it out', note: 'A realistic wrap-up: log the confirmed root cause and workaround, then move to a closed status — closed_at is stamped automatically.',
         path: {id: '3'}, body: {root_cause: 'Faulty SFP on the primary switch uplink.', workaround: 'Failover to secondary link.', status: 'Closed'}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: "title is sent but empty, or status/status_id/priority/priority_id doesn't match a known lookup"},
        {code: 422, slug: 'invalid_field', when: 'assigned_analyst_id refers to an unknown or inactive analyst'},
    ],
},

'DELETE /problems/{id}': {
    examples: [
        {title: 'Delete a problem', note: 'Permanently removes the problem and everything attached to it (notes, audit trail, links) — there is no undo.',
         path: {id: '3'}},
    ],
},

'GET /problems/{id}/notes': {
    examples: [
        {title: 'Read the journal', note: 'Returns every note logged against the problem, newest first.',
         path: {id: '3'}},
    ],
},

'POST /problems/{id}/notes': {
    examples: [
        {title: 'Add a note', note: 'Appends a note attributed to the analyst the key acts as — notes can\'t be edited or deleted afterwards.',
         path: {id: '3'}, body: {note: 'Checked switch logs — no further drops since failover.'}},
        {title: 'Log a vendor update', note: 'A realistic journal entry recording an external update relevant to the investigation.',
         path: {id: '3'}, body: {note: 'Vendor confirmed firmware bug; fix scheduled in change #42.'}},
    ],
},

'GET /problems/{id}/audit': {
    examples: [
        {title: 'View change history', note: 'Every audited change to the problem — field, old/new value and who made it — newest first.',
         path: {id: '3'}},
    ],
},

'POST /problems/{id}/tickets': {
    examples: [
        {title: 'Link one incident', note: 'Links a single ticket to the problem as a related incident.',
         path: {id: '3'}, body: {ticket_id: 12}},
        {title: 'Link another recurring printer incident', note: 'A realistic follow-up once a fresh incident matches the pattern: attach it to the existing problem instead of raising a new one.',
         path: {id: '3'}, body: {ticket_id: 47}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: 'the ticket belongs to a different company than the problem (multi-company installs)'},
        {code: 409, slug: 'conflict', when: 'the incident is already linked to this problem'},
    ],
},

'DELETE /problems/{id}/tickets/{ticket_id}': {
    examples: [
        {title: 'Unlink an incident', note: 'Removes the link between the ticket and the problem; the ticket itself is untouched.',
         path: {id: '3', ticket_id: '12'}},
    ],
},

'POST /problems/{id}/changes': {
    examples: [
        {title: 'Link the fixing change', note: 'Links a change record to the problem via the shared change-relations mechanism.',
         path: {id: '3'}, body: {change_id: 1}},
        {title: 'Attach the printer roller replacement change', note: 'A realistic close-of-loop: once a change is raised to fix the root cause, link it so the problem shows what will resolve it.',
         path: {id: '3'}, body: {change_id: 42}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: 'change_id does not match a known change, or Change Management is not installed'},
        {code: 409, slug: 'conflict', when: 'the change is already linked to this problem'},
    ],
},

'DELETE /problems/{id}/changes/{change_id}': {
    examples: [
        {title: 'Unlink a change', note: 'Removes the link between the change and the problem.',
         path: {id: '3', change_id: '1'}},
    ],
},

// ===== extras_changes =====
// Entries for docs-extras.js — 'Changes' + 'Change comments, history & CAB' sections.
// Keyed 'METHOD /path' exactly as the SPEC array in system/api/docs.php.

'GET /changes': {
    examples: [
        {title: 'List all changes', note: 'The simplest call — every change, newest first, page 1.'},
        {title: 'Filter open high-priority changes', note: 'Combine a state filter with a priority filter and choose a sort order.',
         query: {state: 'open', priority: 'High', sort: '-created_at'}},
        {title: 'Upcoming emergency changes needing CAB', note: 'A realistic dashboard query: emergency changes requiring CAB approval, scheduled to start this week.',
         query: {change_type: 'Emergency', cab_required: 'true', work_start_from: '2026-07-04T00:00:00Z', work_start_to: '2026-07-11T00:00:00Z', sort: 'work_start_at'}},
    ],
    errors: [
        {code: 400, slug: 'invalid_parameter', when: "'sort' names a field that isn't sortable."},
    ],
},

'POST /changes': {
    examples: [
        {title: 'Minimal change', note: 'Only the title is required — everything else defaults (Normal / Draft / Medium / Medium).',
         body: {title: 'Replace failing UPS battery'}},
        {title: 'Change with type, priority and risk', note: 'Set the type and priority explicitly and provide a risk score — the server computes the level.',
         body: {title: 'Upgrade firewall firmware', change_type: 'Normal', priority: 'Medium', risk_likelihood: 2, risk_impact_score: 3}},
        {title: 'Emergency change raised for CAB approval', note: 'A realistic emergency scenario: high risk, a reason for change, a schedule, and CAB required with a majority threshold.',
         body: {title: 'Emergency patch for critical vulnerability CVE-2026-1234', change_type: 'Emergency', priority: 'Critical',
                reason_for_change: 'Zero-day vulnerability actively exploited; emergency patch required.',
                risk_likelihood: 4, risk_impact_score: 5, cab_required: true, cab_approval_type: 'majority',
                work_start_at: '2026-07-05T20:00:00Z', work_end_at: '2026-07-05T22:00:00Z'}},
    ],
    errors: [
        {code: 422, slug: 'missing_field', when: "'title' is missing or blank."},
        {code: 422, slug: 'invalid_field', when: 'A lookup (change_type, status, priority, impact or category_id) names a value that does not exist.'},
        {code: 422, slug: 'invalid_field', when: "'risk_likelihood' or 'risk_impact_score' is outside 1-5."},
    ],
},

'GET /changes/{id}': {
    examples: [
        {title: 'Get change detail', note: 'The full record: plan bodies, risk, PIR, attachments and any linked problems.',
         path: {id: '7'}},
    ],
},

'PATCH /changes/{id}': {
    examples: [
        {title: 'Move to Pending Approval', note: 'The simplest update — a single status change, audited as such.',
         path: {id: '7'}, body: {status: 'Pending Approval'}},
        {title: 'Reassign and reschedule', note: 'Update several fields at once — only what you send changes.',
         path: {id: '7'}, body: {assigned_to_id: 3, work_start_at: '2026-07-06T06:00:00Z', work_end_at: '2026-07-06T08:00:00Z'}},
        {title: 'CAB-approved change scheduled for work', note: 'A realistic follow-on after CAB sign-off: set the approver, move to Scheduled, and confirm the risk score.',
         path: {id: '7'}, body: {status: 'Scheduled', approver_id: 2, work_start_at: '2026-07-12T22:00:00Z', work_end_at: '2026-07-13T02:00:00Z', risk_likelihood: 2, risk_impact_score: 3}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: "'title' is sent but blank."},
        {code: 422, slug: 'invalid_field', when: 'A lookup names a value that does not exist.'},
        {code: 422, slug: 'invalid_field', when: "'risk_likelihood' or 'risk_impact_score' is outside 1-5."},
    ],
},

'DELETE /changes/{id}': {
    examples: [
        {title: 'Delete a change', note: 'Permanently removes the change, its attachments and all children — no restore.',
         path: {id: '7'}},
    ],
},

'GET /changes/{id}/comments': {
    examples: [
        {title: 'List comments', note: 'All comments on this change, newest first.', path: {id: '7'}},
    ],
},

'POST /changes/{id}/comments': {
    examples: [
        {title: 'Add a quick update', note: 'The simplest comment — just the text.',
         path: {id: '7'}, body: {text: 'Vendor confirmed parts shipped.'}},
        {title: 'Add a detailed progress note', note: 'A longer comment recording the plan for the work window.',
         path: {id: '7'}, body: {text: 'Parts arrived; work confirmed for Saturday 06:00. Network team on standby for rollback if needed.'}},
    ],
    errors: [
        {code: 422, slug: 'missing_field', when: "'text' is missing or blank."},
    ],
},

'DELETE /changes/{id}/comments/{comment_id}': {
    examples: [
        {title: 'Delete a comment', note: 'Hard-deletes a single comment, same as the UI.',
         path: {id: '7', comment_id: '3'}},
    ],
},

'GET /changes/{id}/audit': {
    examples: [
        {title: 'Change history', note: 'Every audited event on this change — field changes, status changes, CAB votes and comments — newest first.',
         path: {id: '7'}},
    ],
},

'GET /changes/{id}/cab': {
    examples: [
        {title: 'CAB roster & progress', note: 'The CAB members with their votes, plus approval progress against the required threshold.',
         path: {id: '7'}},
    ],
},

'POST /changes/{id}/cab': {
    examples: [
        {title: 'Add one required member', note: 'The simplest roster — a single required voter.',
         path: {id: '7'}, body: {members: [{analyst_id: 1, is_required: true}]}},
        {title: 'Mixed required and optional roster', note: 'A roster with both required voters and an optional (informed) member.',
         path: {id: '7'}, body: {members: [{analyst_id: 1, is_required: true}, {analyst_id: 2, is_required: true}, {analyst_id: 3, is_required: false}]}},
        {title: 'Full CAB for a high-risk emergency change', note: 'A realistic emergency-change board: several required approvers plus one optional observer.',
         path: {id: '7'}, body: {members: [{analyst_id: 1, is_required: true}, {analyst_id: 2, is_required: true}, {analyst_id: 4, is_required: true}, {analyst_id: 5, is_required: false}]}},
    ],
    errors: [
        {code: 422, slug: 'missing_field', when: "'members' is missing or not an array."},
        {code: 422, slug: 'invalid_field', when: 'A member entry is missing its analyst_id, or the analyst_id does not exist.'},
    ],
},

'POST /changes/{id}/cab/vote': {
    examples: [
        {title: 'Approve', note: 'The simplest vote — cast as the analyst this key acts as.',
         path: {id: '7'}, body: {vote: 'Approve'}},
        {title: 'Reject with a reason', note: 'A required member rejecting sends the change back to Draft, with the reason recorded.',
         path: {id: '7'}, body: {vote: 'Reject', comment: 'Rollback plan is insufficient for this outage window.'}},
        {title: 'Final approving vote triggers auto-approval', note: 'A realistic scenario: this is the last required vote, so the change auto-moves to Approved and the response reports status_changed.',
         path: {id: '7'}, body: {vote: 'Approve', comment: 'Change plan reviewed and confirmed safe to proceed.'}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: "'vote' is not Approve, Reject or Abstain."},
        {code: 403, slug: 'forbidden', when: 'The analyst this key acts as is not a CAB member for this change.'},
        {code: 409, slug: 'conflict', when: 'This CAB member has already voted on this change.'},
    ],
},

// ===== extras_knowledge_tasks =====
/**
 * Draft additions to window.API_EXTRAS — Knowledge base + Tasks sections.
 * Merge these entries into system/api/docs-extras.js.
 */

// ---------------------------------------------------------------------------
// Knowledge base
// ---------------------------------------------------------------------------

'GET /knowledge/articles': {
    examples: [
        {title: 'Browse published articles', note: 'The simplest call — no parameters. Returns the newest-modified published articles first.'},
        {title: 'Search by keyword and tag', note: 'Combine a text search with a tag filter to narrow results, like typing into the module\'s search box then clicking a tag.',
         query: {q: 'vpn', tag: 'how-to', sort: 'title'}},
        {title: 'Nightly KB sync via modified_since', note: 'A realistic integration: a sync job pulls only articles changed since its last run, sorted oldest-changed-first, in workable pages.',
         query: {modified_since: '2026-07-03T00:00:00Z', sort: 'modified_at', per_page: 50}},
    ],
    errors: [
        {code: 400, slug: 'invalid_parameter', when: "'review' is set to anything other than overdue, upcoming or none."},
        {code: 400, slug: 'invalid_parameter', when: "'sort' names a field that isn't sortable (modified_at, created_at, title, view_count, next_review_date, id)."},
        {code: 400, slug: 'invalid_parameter', when: "'modified_since' isn't a valid ISO 8601 date/time."},
    ],
},

'POST /knowledge/articles': {
    examples: [
        {title: 'Minimal article', note: 'Only title is required — everything else defaults empty, and the article is published immediately (there is no draft workflow).',
         body: {title: 'How to reset your VPN token'}},
        {title: 'Article with content and tags', note: 'Add the HTML body and a couple of tags — tags are created automatically if they don\'t already exist.',
         body: {title: 'How to reset your VPN token', body_html: '<h2>Steps</h2><ol><li>Open the portal</li><li>Click Reset</li></ol>', tags: ['vpn', 'how-to']}},
        {title: 'Onboarding article with a review cycle', note: 'A realistic scenario: publishing a doc with an owner and a next-review date, so it surfaces on that analyst\'s review screen when due.',
         body: {title: 'New starter IT checklist', body_html: '<p>Day-one setup steps for new joiners.</p>', tags: ['onboarding', 'how-to'], owner_id: 4, next_review_date: '2026-10-01'}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: "'title' is longer than 255 characters."},
    ],
},

'GET /knowledge/articles/{id}': {
    examples: [
        {title: 'Fetch an article', note: 'Returns the full HTML body and tags. Reading via the API does not bump the view counter by default.',
         path: {id: 101}},
        {title: 'Fetch and count as a view', note: 'Pass count_view=true when a real reader (not a sync job) opened the article, to keep the module\'s view stats accurate.',
         path: {id: 101}, query: {count_view: 'true'}},
    ],
},

'PATCH /knowledge/articles/{id}': {
    examples: [
        {title: 'Rename an article', note: 'The simplest update — change just the title.',
         path: {id: 101}, body: {title: 'How to reset your VPN token (updated)'}},
        {title: 'Update content and tags together', note: 'Replace the body and the tag set in one call — tags sent here fully replace the existing set.',
         path: {id: 101}, body: {body_html: '<h2>Steps (updated)</h2><ol><li>Open the portal</li><li>Click Reset</li><li>Check your email</li></ol>', tags: ['vpn', 'how-to', 'security']}},
        {title: 'Save as a new version before editing', note: 'A realistic scenario: snapshot the current content into version history first (like the UI\'s "Save as new version"), then apply the edit.',
         path: {id: 101}, body: {body_html: '<h2>Steps (updated)</h2>…', save_as_version: true, owner_id: 4, next_review_date: '2027-01-01'}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'The article is in the recycle bin — restore it first.'},
        {code: 422, slug: 'missing_field', when: 'The request body has no fields to update.'},
        {code: 422, slug: 'invalid_field', when: "'title' is sent empty, or longer than 255 characters."},
    ],
},

'DELETE /knowledge/articles/{id}': {
    examples: [
        {title: 'Move an article to the recycle bin', note: 'Soft-deletes the article — it stays recoverable until the recycle bin\'s retention window purges it.',
         path: {id: 101}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'The article is already in the recycle bin.'},
    ],
},

'POST /knowledge/articles/{id}/restore': {
    examples: [
        {title: 'Restore from the recycle bin', note: 'Brings an archived article back to published, exactly as clicking Restore in the bin would.',
         path: {id: 101}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: "The article isn't in the recycle bin."},
    ],
},

'DELETE /knowledge/articles/{id}/permanent': {
    examples: [
        {title: 'Permanently delete', note: 'Hard-deletes an article that is already in the recycle bin — this cannot be undone.',
         path: {id: 101}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'The article is not archived yet — DELETE it (move to the bin) before permanently deleting.'},
    ],
},

'GET /knowledge/articles/{id}/versions': {
    examples: [
        {title: 'List an article\'s version history', note: 'Every saved snapshot, newest first, with who saved it and when — useful for showing an audit trail.',
         path: {id: 101}},
    ],
},

'GET /knowledge/articles/{id}/versions/{version}': {
    examples: [
        {title: 'Fetch a specific version', note: 'Returns that snapshot\'s full HTML body — handy for comparing against the current content or rolling back manually.',
         path: {id: 101, version: 2}},
    ],
    errors: [
        {code: 404, slug: 'not_found', when: 'The article exists but has no snapshot with that version number.'},
    ],
},

// ---------------------------------------------------------------------------
// Tasks
// ---------------------------------------------------------------------------

'GET /tasks': {
    examples: [
        {title: 'List the board', note: 'The simplest call — top-level tasks in board order, exactly what the kanban view shows by default.'},
        {title: 'My open High-priority tasks', note: 'Combine an assignee filter, priority and open-only state to build a personal worklist.',
         query: {assigned_analyst_id: 7, priority: 'High', state: 'open'}},
        {title: 'Kanban card moved by automation feed', note: 'A realistic integration: a dashboard polls overdue open tasks past their due date, sorted soonest-first, to alert a team lead.',
         query: {overdue: 'true', state: 'open', sort: 'due_date', per_page: 50}},
    ],
    errors: [
        {code: 400, slug: 'invalid_parameter', when: "'sort' names a field that isn't sortable (board_position, created_at, updated_at, due_date, completed_at, title, id)."},
        {code: 400, slug: 'invalid_parameter', when: "'due_before' or 'due_after' isn't a valid YYYY-MM-DD date."},
    ],
},

'POST /tasks': {
    examples: [
        {title: 'Minimal task', note: 'Only title is required — it lands in To Do at Medium priority, appended to the end of that column.',
         body: {title: 'Renew SSL certificate for portal'}},
        {title: 'Task with priority, due date and tags', note: 'Set the column, priority, due date and existing tags in one call — tags must already exist in Tasks > Settings.',
         body: {title: 'Renew SSL certificate for portal', status: 'In Progress', priority: 'High', due_date: '2026-07-20', tags: ['Security']}},
        {title: 'Subtask linked to a ticket', note: 'A realistic scenario: a follow-up task created from a ticket, filed as a subtask of a parent checklist task and assigned to a team.',
         body: {title: 'Order replacement cert from CA', parent_task_id: 340, ticket_id: 1583, assigned_team_id: 2, priority: 'High', due_date: '2026-07-10'}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: 'status / status_id or priority / priority_id names a value that doesn\'t exist.'},
        {code: 422, slug: 'invalid_field', when: 'assigned_team_id, ticket_id, change_id, contract_id or parent_task_id references a record that doesn\'t exist — for ticket_id, this also covers a ticket outside the key\'s company scope.'},
        {code: 422, slug: 'invalid_field', when: 'tags includes a name or id that isn\'t in the curated tag list (Tasks > Settings manages it — the API won\'t create new ones).'},
    ],
},

'GET /tasks/{id}': {
    examples: [
        {title: 'Fetch a task', note: 'Full detail in one call: parent summary, ordered subtasks, comments, and any linked ticket/change.',
         path: {id: 340}},
    ],
},

'PATCH /tasks/{id}': {
    examples: [
        {title: 'Mark a task done', note: 'The simplest update — move the status to a closed column; this stamps completed_at and fires the task.completed workflow event.',
         path: {id: 340}, body: {status: 'Done'}},
        {title: 'Reassign and reprioritise together', note: 'Change the assignee, priority and due date in one PATCH — only the fields you send are touched.',
         path: {id: 340}, body: {assigned_analyst_id: 9, priority: 'Urgent', due_date: '2026-07-08'}},
        {title: 'Kanban card moved by automation', note: 'A realistic scenario: a workflow rule reopens a task by moving it back to In Progress, which clears completed_at, and adds a note tag.',
         path: {id: 340}, body: {status: 'In Progress', tags: ['Security', 'Reopened']}},
    ],
    errors: [
        {code: 422, slug: 'missing_field', when: 'The request body has no fields to update.'},
        {code: 422, slug: 'invalid_field', when: 'status / status_id or priority / priority_id names a value that doesn\'t exist.'},
        {code: 422, slug: 'invalid_field', when: 'parent_task_id is set to the task\'s own id — a task cannot be its own parent.'},
        {code: 422, slug: 'invalid_field', when: 'assigned_team_id, ticket_id, change_id, contract_id or parent_task_id references a record that doesn\'t exist (or, for ticket_id, is outside the key\'s company scope).'},
        {code: 422, slug: 'invalid_field', when: 'tags includes a name or id that isn\'t in the curated tag list.'},
    ],
},

'POST /tasks/{id}/move': {
    examples: [
        {title: 'Reorder within the current column', note: 'Omit status to just reposition the card among its siblings — position is 0-based, so 0 means the top.',
         path: {id: 340}, body: {position: 0}},
        {title: 'Move to another column at a position', note: 'Change the column and place the card at a specific spot in it — the rest of that column is automatically re-packed.',
         path: {id: 340}, body: {status: 'In Progress', position: 2}},
        {title: 'Kanban card moved by automation', note: 'A realistic scenario: a workflow rule drags a card into Done at the end of the column — note this mirrors the UI drag and, unlike PATCH, does NOT fire the task.completed event.',
         path: {id: 340}, body: {status: 'Done'}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: 'status / status_id names a value that doesn\'t exist.'},
    ],
},

'DELETE /tasks/{id}': {
    examples: [
        {title: 'Delete a task', note: 'Permanently removes the task along with its subtasks, comments and tag links — there is no trash to restore from.',
         path: {id: 340}},
    ],
},

'GET /tasks/{id}/comments': {
    examples: [
        {title: 'List comments', note: 'Returns the task\'s comment thread oldest first, exactly as the UI displays it.',
         path: {id: 340}},
    ],
},

'POST /tasks/{id}/comments': {
    examples: [
        {title: 'Add a comment', note: 'Appends a comment attributed to the analyst the API key acts as — comments can\'t be edited or deleted afterwards, same as the UI.',
         path: {id: 340}, body: {text: 'Cert ordered; waiting on validation email.'}},
    ],
    errors: [
        {code: 422, slug: 'missing_field', when: "'text' is empty."},
    ],
},


// ===== extras_cmdb_nm =====
/**
 * Partial API_EXTRAS entries for the 'CMDB' and 'Network Mapper' SPEC sections.
 * Merge these key/value pairs into window.API_EXTRAS in system/api/docs-extras.js.
 */

// ===========================================================================
// CMDB
// ===========================================================================

'GET /cmdb/classes': {
    examples: [
        {title: 'List all classes', note: 'The simplest call — every CI class with its icon and how many objects use it.'},
    ],
},

'GET /cmdb/classes/{id}': {
    examples: [
        {title: 'Get the Server class', note: 'Fetch one class\'s full property schema — every field, its type, and dropdown options — so you know exactly what a POST/PATCH to /cmdb/objects can send.', path: {id: 1}},
    ],
},

'GET /cmdb/objects': {
    examples: [
        {title: 'List every object', note: 'The simplest call — no filters, returns page one of every CI in the CMDB.'},
        {title: 'Search within a class', note: 'Combine a class filter with a text search — the object picker\'s exact use case.', query: {class_key: 'server', q: 'SQL'}},
        {title: 'Live top-level servers, newest first', note: 'A dashboard-style query: root-level, not-yet-planned servers, most recently created first, paginated.', query: {class_key: 'server', top_level: 'true', is_planned: 'false', sort: '-created_at', page: 1, per_page: 25}},
    ],
},

'POST /cmdb/objects': {
    examples: [
        {title: 'Create a bare object', note: 'The minimum needed: a name and a class — no properties yet.', body: {name: 'SQLSVR01', class_key: 'server'}},
        {title: 'Create with properties and a parent', note: 'Set typed properties and place it under a parent CI in the same call.', body: {name: 'SQLSVR01', class_key: 'server', parent_id: 4, properties: {ip_address: '10.0.0.15', environment: 'Production'}}},
        {title: 'Stage a planned CI', note: 'Discovery agents can pre-register a not-yet-live CI (is_planned) ready to promote once it\'s actually racked.', body: {name: 'CORE-SW02', class_key: 'switch', is_planned: true, properties: {ip_address: '10.0.0.2', environment: 'Production'}}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: 'A dropdown property value isn\'t one of that property\'s configured options.'},
    ],
},

'GET /cmdb/objects/{id}': {
    examples: [
        {title: 'Get one object, fully hydrated', note: 'Every typed property value, parent, children, relationships in both directions and the cached AI summary — everything in one call.', path: {id: 1}},
    ],
},

'PATCH /cmdb/objects/{id}': {
    examples: [
        {title: 'Rename an object', note: 'Send only what changes — nothing else is touched. Class can\'t be changed here; it\'s fixed at creation.', path: {id: 1}, body: {name: 'SQLSVR01-DR'}},
        {title: 'Move it and update one property', note: 'Re-parent the CI and change a single property in the same call — like the UI\'s inline edit, only sent properties are checked/touched.', path: {id: 1}, body: {parent_id: 4, properties: {environment: 'DR'}}},
        {title: 'Promote a planned CI to live', note: 'Flip is_planned off once it\'s racked, updating its properties in the same request.', path: {id: 3}, body: {is_planned: false, properties: {environment: 'Production', ip_address: '10.0.0.2'}}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: 'parent_id is the object itself, one of its own descendants (would create a cycle), or doesn\'t exist.'},
        {code: 422, slug: 'invalid_field', when: 'A dropdown property value isn\'t one of that property\'s configured options.'},
    ],
},

'DELETE /cmdb/objects/{id}': {
    examples: [
        {title: 'Delete an object', note: 'Deletes the object AND its whole descendant subtree — check GET .../impact first if you\'re not sure what that includes.', path: {id: 3}},
    ],
},

'GET /cmdb/objects/{id}/impact': {
    examples: [
        {title: 'Check the blast radius before deleting', note: 'Descendants, objects referencing this one via a property, and things that depend on it — run this before DELETE.', path: {id: 2}},
    ],
},

'POST /cmdb/objects/{id}/relationships': {
    examples: [
        {title: 'Link by verb', note: 'SQLSVR01 depends on CORE-SW01 — the relationship type is looked up by its verb text.', path: {id: 1}, body: {to_object_id: 2, verb: 'depends on'}},
        {title: 'Link by relationship type id', note: 'The same kind of link addressed by relationship_type_id instead — handy once you\'ve cached GET /cmdb-relationship-types.', path: {id: 1}, body: {to_object_id: 2, relationship_type_id: 3}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'The same from/to/relationship-type triple already exists.'},
    ],
},

'DELETE /cmdb/objects/{id}/relationships/{rel_id}': {
    examples: [
        {title: 'Unlink two objects', note: 'The relationship must involve this object, in either direction.', path: {id: 1, rel_id: 10}},
    ],
},

'GET /cmdb/objects/{id}/tickets': {
    examples: [
        {title: 'List tickets linked to a CI', note: 'Every ticket linked to this object, scoped to the key\'s companies.', path: {id: 1}},
    ],
},

'POST /cmdb/objects/{id}/tickets': {
    examples: [
        {title: 'Link a ticket to a CI', note: 'The ticket must be within the key\'s company scope.', path: {id: 1}, body: {ticket_id: 1}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'This ticket is already linked to this CI.'},
    ],
},

'DELETE /cmdb/objects/{id}/tickets/{ticket_id}': {
    examples: [
        {title: 'Unlink a ticket from a CI', note: 'Removes the link only — neither the ticket nor the CI is touched.', path: {id: 1, ticket_id: 1}},
    ],
},

// ===========================================================================
// Network Mapper
// ===========================================================================

'GET /network-diagrams': {
    examples: [
        {title: 'List current diagrams', note: 'The simplest call — current versions only, most recently updated first.'},
        {title: 'Find diagrams showing a CI', note: 'Search titles and find every diagram a particular CMDB object appears on.', query: {q: 'Head office', contains_object_id: 1}},
        {title: 'Audit everything touched recently', note: 'Include frozen historical versions and filter to what\'s changed since a date — an audit-style sweep.', query: {all_versions: 'true', updated_since: '2026-06-01T00:00:00Z'}},
    ],
},

'POST /network-diagrams': {
    examples: [
        {title: 'Create an empty diagram', note: 'Just a title — add nodes and connectors afterwards with the endpoints below.', body: {title: 'Head office network'}},
        {title: 'Create with paper + description', note: 'Set up the print layout at creation time too.', body: {title: 'Head office network', description: 'Core switching and firewalls', paper_size: 'A3', paper_orientation: 'landscape'}},
        {title: 'Seed a diagram from discovery', note: 'A discovery agent creates the diagram and its first two nodes in one call, connecting them by the existing CMDB relationship rather than guessing one.', body: {title: 'Head office network', nodes: [{cmdb_object_id: 1, ref: 'fw'}, {cmdb_object_id: 2, ref: 'sw1'}], connectors: [{from_ref: 'fw', to_ref: 'sw1', cmdb_relationship_id: 'auto'}]}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: 'A node\'s cmdb_object_id, size or icon_override, or a connector\'s line_style, isn\'t recognised.'},
    ],
},

'GET /network-diagrams/{id}': {
    examples: [
        {title: 'Get a diagram, fully hydrated', note: 'Nodes with their CI name/class/icon, connectors with both endpoints and the CMDB verb, branding and a layout bounding box — everything an agent needs, in one call.', path: {id: 5}},
        {title: 'Include each CI\'s full properties', note: 'Adds every node object\'s typed CMDB property values — useful when an agent needs more than name and class.', path: {id: 5}, query: {include_properties: 'true'}},
    ],
},

'PATCH /network-diagrams/{id}': {
    examples: [
        {title: 'Rename a diagram', note: 'Metadata-only update — nodes and connectors are untouched.', path: {id: 5}, body: {title: 'Head office network (2026)'}},
        {title: 'Update paper and branding together', note: 'Change the print layout and one branding slot in the same call (null = inherit the org default).', path: {id: 5}, body: {paper_size: 'A3', paper_orientation: 'landscape', branding: {header: {left: 'Acme IT', center: null, right: null}}}},
        {title: 'Full redraw after a discovery pass', note: 'Sending nodes/connectors replaces BOTH sets wholesale — node ids regenerate, so this suits a bulk redraw; prefer the node/connector endpoints below for incremental edits.', path: {id: 5}, body: {nodes: [{cmdb_object_id: 1, ref: 'fw'}, {cmdb_object_id: 2, ref: 'sw1'}, {cmdb_object_id: 7, ref: 'sw2'}], connectors: [{from_ref: 'fw', to_ref: 'sw1', cmdb_relationship_id: 'auto'}, {from_ref: 'sw1', to_ref: 'sw2', cmdb_relationship_id: 'auto'}]}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'The diagram is a frozen historical version — only the current (leaf) version is editable.'},
    ],
},

'DELETE /network-diagrams/{id}': {
    examples: [
        {title: 'Delete the current version', note: 'Its parent (if any) resurfaces as the current version.', path: {id: 5}},
        {title: 'Delete the whole chain', note: 'Removes every version and all their contents, transactionally.', path: {id: 5}, query: {chain: 'true'}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'Deleting a version that has newer versions after it, without chain=true — that would corrupt the chain.'},
    ],
},

'GET /network-diagrams/{id}/versions': {
    examples: [
        {title: 'See the version chain', note: 'Every version in this diagram\'s chain, oldest first, with is_current flagged.', path: {id: 5}},
    ],
},

'POST /network-diagrams/{id}/versions': {
    examples: [
        {title: 'Snapshot before automated changes', note: 'Clones the current version forward unchanged — a free undo point before an agent starts editing.', path: {id: 5}},
        {title: 'Snapshot with a new label', note: 'Same clone, with a custom version_label instead of the default.', path: {id: 5}, body: {version_label: 'v2'}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'Called on a version that isn\'t current — only the leaf can be versioned forward.'},
    ],
},

'GET /network-diagrams/{id}/suggestions': {
    examples: [
        {title: 'What\'s missing from this diagram', note: 'Every CMDB neighbour of what\'s already drawn that isn\'t on the diagram yet — the discovery-agent starting point.', path: {id: 5}},
        {title: 'Scope to one object, capped', note: 'Just the neighbours of one on-diagram CI, limited to a handful of results.', path: {id: 5}, query: {object_id: 2, limit: 10}},
        {title: 'Continue the discovery loop', note: 'Having just drawn CORE-SW01, ask what\'s attached to it before deciding what to add next — each suggestion carries the connecting verb, ready to POST as a node then a connector below.', path: {id: 5}, query: {object_id: 2}},
    ],
    errors: [
        {code: 422, slug: 'invalid_parameter', when: 'object_id isn\'t one of the objects already on this diagram.'},
    ],
},

'POST /network-diagrams/{id}/nodes': {
    examples: [
        {title: 'Add a single node', note: 'The minimum: which CI to draw — position is auto-placed for you in a fresh column.', path: {id: 5}, body: {cmdb_object_id: 3}},
        {title: 'Add a positioned, resized, re-iconed node', note: 'Combine an explicit position with a bigger size and a custom icon.', path: {id: 5}, body: {cmdb_object_id: 3, x: 240, y: 120, size: 'large', icon_override: 'firewall'}},
        {title: 'Add every neighbour a scan just found', note: 'A discovery agent batch-adds newly-found CMDB neighbours straight from GET .../suggestions — each is auto-placed with a stable node id, ready to connect next.', path: {id: 5}, body: {nodes: [{cmdb_object_id: 7, size: 'medium'}, {cmdb_object_id: 8, size: 'medium'}]}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'The object is already on this diagram and allow_duplicate wasn\'t sent.'},
        {code: 422, slug: 'invalid_field', when: 'cmdb_object_id, size or icon_override isn\'t recognised.'},
    ],
},

'PATCH /network-diagrams/{id}/nodes/{node_id}': {
    examples: [
        {title: 'Move a node', note: 'The editor\'s drag-and-drop, done programmatically.', path: {id: 5, node_id: 12}, body: {x: 420, y: 180}},
        {title: 'Resize and reset its icon', note: 'Bump the size up and clear a custom icon back to the class default (null reverts it).', path: {id: 5, node_id: 12}, body: {size: 'large', icon_override: null}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: 'size or icon_override isn\'t recognised.'},
    ],
},

'DELETE /network-diagrams/{id}/nodes/{node_id}': {
    examples: [
        {title: 'Remove a node', note: 'Removes the node and every connector touching it — the response reports how many connectors went with it.', path: {id: 5, node_id: 12}},
    ],
},

'POST /network-diagrams/{id}/connectors': {
    examples: [
        {title: 'Connect two objects', note: 'Endpoints given as CMDB object ids — resolved to nodes on this diagram for you.', path: {id: 5}, body: {from_object_id: 1, to_object_id: 3}},
        {title: 'Connect by node id, styled', note: 'Address the endpoints by node id directly and set a label and dashed line.', path: {id: 5}, body: {from_node_id: 12, to_node_id: 15, label: '10GbE uplink', line_style: 'dashed'}},
        {title: 'Connect a freshly-added neighbour', note: 'Just added CORE-SW01 (object 7) from suggestions — connect it and let "auto" bind to whatever CMDB relationship already exists between the two, rather than guessing.', path: {id: 5}, body: {from_object_id: 1, to_object_id: 7, cmdb_relationship_id: 'auto'}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'These two nodes are already connected (either direction) and allow_duplicate wasn\'t sent.'},
        {code: 422, slug: 'invalid_field', when: 'from/to_object_id isn\'t on this diagram, or is on it more than once (ambiguous — use the node id instead).'},
    ],
},

'PATCH /network-diagrams/{id}/connectors/{connector_id}': {
    examples: [
        {title: 'Relabel a connector', note: 'Send only what changes.', path: {id: 5, connector_id: 20}, body: {label: '10GbE uplink'}},
        {title: 'Re-resolve its CMDB relationship', note: 'Re-binds to whatever CMDB relationship exists now between the two endpoints — handy after editing relationships in the CMDB.', path: {id: 5, connector_id: 20}, body: {cmdb_relationship_id: 'auto'}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: 'cmdb_relationship_id is set to an id that doesn\'t exist.'},
    ],
},

'DELETE /network-diagrams/{id}/connectors/{connector_id}': {
    examples: [
        {title: 'Remove a connector', note: 'Removes the connector only — neither node is touched.', path: {id: 5, connector_id: 20}},
    ],
},


// ===== extras_contracts =====
/**
 * FreeITSM REST API docs — per-endpoint examples and endpoint-specific errors.
 * Covers SPEC sections 'Contracts' and 'Suppliers & contacts'.
 * Shape matches system/api/docs-extras.js (window.API_EXTRAS).
 */

'GET /contracts': {
    examples: [
        {title: 'List all contracts', note: 'The simplest possible call — no filters, sorted by contract_end, first page of 25.'},
        {title: 'Active contracts for a supplier', note: 'Combine an id filter with is_active to see only what is currently live with one supplier.',
         query: {supplier_id: 1, is_active: 'true'}},
        {title: 'Renewals due in 90 days for the finance report', note: 'The Watchtower renewal window as a query parameter, sorted soonest-first — this is the shape a scheduled report would call.',
         query: {expiring_within_days: 90, sort: 'contract_end'}},
    ],
    errors: [
        {code: 400, slug: 'invalid_parameter', when: "'sort' names a field that is not sortable — the error lists the valid ones."},
    ],
},
'POST /contracts': {
    examples: [
        {title: 'Minimal contract', note: 'Only the two required fields — contract_number and title.',
         body: {contract_number: 'CN-2026-020', title: 'Office cleaning contract'}},
        {title: 'With supplier, dates and value', note: 'A typical contract record: linked supplier, term dates and contract value together.',
         body: {contract_number: 'CN-2026-021', title: 'Managed print services', supplier_id: 1, contract_start: '2026-08-01', contract_end: '2028-07-31', contract_value: 24000, currency: 'GBP'}},
        {title: 'Full GDPR governance record for a new supplier deal', note: 'A realistic onboarding call: owner, notice period, cost centre and the data-protection fields together.',
         body: {contract_number: 'CN-2026-022', title: 'Cloud backup services', supplier_id: 2, contract_owner_id: 3, contract_status_id: 1, contract_start: '2026-09-01', contract_end: '2029-08-31', notice_period_days: 90, notice_date: '2029-06-03', contract_value: 18500, currency: 'GBP', cost_centre: 'IT-002', dms_link: 'https://docs.example.com/contracts/CN-2026-022', personal_data_transferred: true, dpia_required: true}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'contract_number matches an existing contract — the API refuses duplicates even though the underlying table has no unique constraint and the UI allows them.'},
        {code: 422, slug: 'invalid_field', when: 'supplier_id, contract_owner_id, contract_status_id or payment_schedule_id does not match a real row.'},
        {code: 422, slug: 'invalid_field', when: "currency is not a 3-letter code (it is upper-cased automatically, e.g. 'gbp' becomes 'GBP', but 'pounds' is rejected)."},
    ],
},
'GET /contracts/{id}': {
    examples: [
        {title: 'Get a contract', note: 'Full detail for one contract, including its governance and payment-schedule blocks.',
         path: {id: 14}},
    ],
},
'PATCH /contracts/{id}': {
    examples: [
        {title: 'Retire a contract', note: 'Set is_active false to retire it without deleting any history.',
         path: {id: 14}, body: {is_active: false}},
        {title: 'Extend the end and notice dates', note: 'Send only the fields that changed — everything else on the contract is left untouched.',
         path: {id: 14}, body: {contract_end: '2029-07-31', notice_date: '2029-05-02'}},
        {title: 'Reassign the owner and record a renegotiated value', note: 'A realistic post-renewal update: new owner and value together in one call.',
         path: {id: 14}, body: {contract_owner_id: 3, contract_value: 27500, currency: 'GBP'}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'contract_number is changed to a value another contract already uses.'},
        {code: 422, slug: 'invalid_field', when: 'a lookup field (e.g. supplier_id) is changed to an id that does not exist.'},
        {code: 422, slug: 'missing_field', when: 'the request body is empty — there is nothing to update.'},
    ],
},
'DELETE /contracts/{id}': {
    examples: [
        {title: 'Delete a contract', note: 'Permanently removes the contract and its term values; consider is_active=false instead if you just want to retire it.',
         path: {id: 14}},
    ],
},
'GET /contracts/{id}/terms': {
    examples: [
        {title: 'Read all term tabs for a contract', note: 'Every active term tab, with this contract’s saved content (null where nothing has been recorded yet).',
         path: {id: 14}},
    ],
},
'POST /contracts/{id}/terms': {
    examples: [
        {title: 'Write one term tab', note: 'The simplest upsert — only the tab you send is touched, everything else is left as-is.',
         path: {id: 14}, body: {terms: [{term_tab_id: 1, content: 'Termination requires 90 days written notice.'}]}},
        {title: 'Write two term tabs together', note: 'Send several tabs in one call when updating more than one section at once.',
         path: {id: 14}, body: {terms: [{term_tab_id: 1, content: 'Termination requires 90 days written notice.'}, {term_tab_id: 2, content: 'Liability capped at 12 months of fees.'}]}},
        {title: 'Populate terms after a renewal negotiation', note: 'A realistic scenario: recording the outcome of a renewal across termination, liability and data-protection clauses in one request.',
         path: {id: 14}, body: {terms: [{term_tab_id: 1, content: 'Termination requires 90 days written notice.'}, {term_tab_id: 2, content: 'Liability capped at 12 months of fees.'}, {term_tab_id: 3, content: 'Data processed in the UK only; DPA signed 2026-06-01.'}]}},
    ],
    errors: [
        {code: 422, slug: 'missing_field', when: "'terms' is missing or is not an array."},
        {code: 422, slug: 'invalid_field', when: 'a term is missing term_tab_id, or names a term_tab_id that does not exist.'},
    ],
},

'GET /suppliers/{id}': {
    examples: [
        {title: 'Get a supplier', note: 'The full record plus its contacts inline, so you rarely need a second call.',
         path: {id: 5}},
    ],
},
'POST /suppliers': {
    examples: [
        {title: 'Minimal supplier', note: 'Only legal_name is required.',
         body: {legal_name: 'Acme Print Ltd'}},
        {title: 'With trading name, type and status', note: 'Add the identity and classification fields you would normally fill in on the supplier form.',
         body: {legal_name: 'Acme Print Ltd', trading_name: 'Acme Print', supplier_type_id: 1, supplier_status_id: 1}},
        {title: 'Onboard a hardware supplier that also supplies assets', note: 'A realistic onboarding call: full address plus supplies_assets, the flag the Assets module uses to filter this supplier list (the UI never sets it — only an integration or the asset-import migration does).',
         body: {legal_name: 'Acme Hardware Ltd', trading_name: 'Acme Hardware', supplier_type_id: 1, supplier_status_id: 1, address_line_1: '1 Industrial Estate', city: 'Leeds', postcode: 'LS1 1AA', country: 'United Kingdom', supplies_assets: true}},
    ],
    errors: [
        {code: 422, slug: 'missing_field', when: "'legal_name' is missing or empty."},
        {code: 422, slug: 'invalid_field', when: 'supplier_type_id or supplier_status_id does not match a real row.'},
    ],
},
'PATCH /suppliers/{id}': {
    examples: [
        {title: 'Change status', note: 'Send only the field that changed.',
         path: {id: 5}, body: {supplier_status_id: 2}},
        {title: 'Update trading name and VAT number', note: 'Combine a couple of identity fields in one call.',
         path: {id: 5}, body: {trading_name: 'Acme Print & Design', vat_number: 'GB123456789'}},
        {title: 'Retire a supplier after a contract review', note: 'A realistic wind-down scenario: mark it inactive rather than deleting it.',
         path: {id: 5}, body: {is_active: false}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: 'supplier_type_id or supplier_status_id is changed to an id that does not exist.'},
        {code: 422, slug: 'missing_field', when: 'the request body is empty — there is nothing to update.'},
    ],
},
'DELETE /suppliers/{id}': {
    examples: [
        {title: 'Delete a supplier', note: 'Its contracts, contacts and assets keep their rows but are unlinked, same as the UI.',
         path: {id: 5}},
    ],
},
'GET /suppliers/{id}/contacts': {
    examples: [
        {title: 'List a supplier’s contacts', note: 'All contacts recorded against this supplier.',
         path: {id: 5}},
    ],
},
'POST /suppliers/{id}/contacts': {
    examples: [
        {title: 'Add a contact with just a name', note: 'Only first_name and surname are required.',
         path: {id: 5}, body: {first_name: 'Jo', surname: 'Bates'}},
        {title: 'Add a contact with email and job title', note: 'Fill in the fields you would normally capture from a business card.',
         path: {id: 5}, body: {first_name: 'Jo', surname: 'Bates', email: 'jo@acmeprint.example', job_title: 'Account manager'}},
        {title: 'Add the account manager for renewal correspondence', note: 'A realistic full contact record, so renewal emails and calls have somewhere to go.',
         path: {id: 5}, body: {first_name: 'Jo', surname: 'Bates', email: 'jo@acmeprint.example', mobile: '07700 900123', job_title: 'Account manager', direct_dial: '0113 000 0111', switchboard: '0113 000 0000'}},
    ],
    errors: [
        {code: 422, slug: 'missing_field', when: "'first_name' or 'surname' is missing."},
    ],
},
'PATCH /suppliers/{id}/contacts/{contact_id}': {
    examples: [
        {title: 'Update a phone number', note: 'Send only the field that changed.',
         path: {id: 5, contact_id: 12}, body: {mobile: '07700 900123'}},
        {title: 'Mark a contact inactive after they leave', note: 'A realistic scenario: keep the history but stop showing them as current.',
         path: {id: 5, contact_id: 12}, body: {is_active: false}},
    ],
    errors: [
        {code: 404, slug: 'not_found', when: 'contact_id exists but belongs to a different supplier than the one in the path.'},
        {code: 422, slug: 'invalid_field', when: 'first_name or surname is sent as empty.'},
    ],
},
'DELETE /suppliers/{id}/contacts/{contact_id}': {
    examples: [
        {title: 'Remove a contact', note: 'Deletes the contact record.',
         path: {id: 5, contact_id: 12}},
    ],
    errors: [
        {code: 404, slug: 'not_found', when: 'contact_id exists but belongs to a different supplier than the one in the path.'},
    ],
},


// ===== extras_cal_soft_status =====
/* Entries for window.API_EXTRAS — Calendar, Software, Service status sections. */

'GET /calendar/events': {
    examples: [
        {title: 'One day', note: 'Lists events overlapping a single day — from is inclusive, to is exclusive.',
         query: {from: '2026-07-05 00:00:00', to: '2026-07-06 00:00:00'}},
        {title: 'Filter by category + search', note: 'Narrows a month-long window to one category and a text search across title and location.',
         query: {from: '2026-07-01 00:00:00', to: '2026-07-31 00:00:00', category_id: 2, q: 'maintenance'}},
        {title: 'Sync tool: manual events only', note: 'An external calendar sync pulls every manual (non-generated) event, paging through since no window is given.',
         query: {all: 'true', source: 'manual', per_page: 100}},
    ],
    errors: [
        {code: 400, slug: 'invalid_parameter', when: "Neither a `from`+`to` window, `contract_id`, nor `all=true` is given."},
        {code: 422, slug: 'invalid_field', when: "`from` or `to` carries a Z/offset — this calendar stores naive local times, not UTC."},
    ],
},
'POST /calendar/events': {
    examples: [
        {title: 'Just a title and start', note: 'The bare minimum — end_at defaults to start_at when omitted.',
         body: {title: 'Team meeting', start_at: '2026-07-10 09:00:00'}},
        {title: 'Full event', note: 'A timed maintenance window with a category and location attached.',
         body: {title: 'Maintenance window — core switch', start_at: '2026-07-10 06:00:00', end_at: '2026-07-10 08:00:00', all_day: false, description: 'Firmware upgrade', location: 'Server room', category_id: 1}},
        {title: 'Contract reminder integration', note: 'A contract-management tool drops an all-day reminder onto the calendar, linked back to the contract record.',
         body: {title: 'Contract renewal — ISP circuit', start_at: '2026-08-01 00:00:00', end_at: '2026-08-02 00:00:00', all_day: true, contract_id: 4}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: "`start_at` or `end_at` carries a Z/offset — send naive 'YYYY-MM-DD HH:MM:SS' local time instead."},
        {code: 422, slug: 'invalid_field', when: "`end_at` is earlier than `start_at`."},
        {code: 422, slug: 'invalid_field', when: "`category_id` or `contract_id` doesn't match an existing row."},
    ],
},
'GET /calendar/events/{id}': {
    examples: [
        {title: 'Get one event', note: 'One event with its category, creator and source (null = manual, a name = generated).',
         path: {id: 1}},
    ],
},
'PATCH /calendar/events/{id}': {
    examples: [
        {title: 'Rename it', note: 'Send only the field that changed — everything else is left as-is.',
         path: {id: 1}, body: {title: 'Updated meeting title'}},
        {title: 'Reschedule + relocate', note: 'Moves the event to a new time and room in one call.',
         path: {id: 2}, body: {start_at: '2026-07-06 06:00:00', end_at: '2026-07-06 08:00:00', location: 'Server room B'}},
        {title: 'Vendor delay pushes the window back', note: 'A realistic edit: the maintenance window slips a day, so start, end and the description all move together.',
         path: {id: 2}, body: {start_at: '2026-07-07 06:00:00', end_at: '2026-07-07 09:00:00', description: 'Vendor pushed the upgrade back a day.'}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: "The event is generated (source is set, e.g. 'asset_warranty') — it would be recreated by its sync anyway, so it's read-only."},
        {code: 422, slug: 'invalid_field', when: "`start_at` or `end_at` carries a Z/offset instead of a naive local datetime."},
        {code: 422, slug: 'missing_field', when: 'The request body has no fields at all.'},
    ],
},
'DELETE /calendar/events/{id}': {
    examples: [
        {title: 'Delete a manual event', note: 'Removes the event immediately — there is no undo.',
         path: {id: 5}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: "The event is generated (source is set) — it would just come back on the next sync, so deletes are refused."},
    ],
},

'GET /software/apps': {
    examples: [
        {title: 'List everything', note: 'Every detected application and system component, first page, sorted by name.',
         query: {}},
        {title: 'Real apps, busiest first', note: 'Excludes system components and matches a publisher search, sorted by how many machines have it installed.',
         query: {filter: 'apps', sort: '-install_count', q: 'Adobe'}},
        {title: 'Licensing-audit sweep', note: 'A compliance script pages through user-facing apps busiest-first to prioritise which need a licence check.',
         query: {filter: 'apps', sort: '-install_count', page: 1, per_page: 50}},
    ],
    errors: [
        {code: 400, slug: 'invalid_parameter', when: "`filter` is something other than apps, components or all."},
        {code: 400, slug: 'invalid_parameter', when: "`sort` names a field that isn't sortable (name, publisher, install_count, id)."},
    ],
},
'GET /software/apps/{id}': {
    examples: [
        {title: 'App + compliance numbers', note: "One application with its licences and computed compliance: installs vs licensed seats, with seats_available worked out for you.",
         path: {id: 1}},
    ],
},
'GET /software/apps/{id}/machines': {
    examples: [
        {title: 'Where it\'s installed', note: 'Every machine with the app, including asset_id so you can join straight into /assets.',
         path: {id: 1}},
    ],
},
'GET /software/licences': {
    examples: [
        {title: 'List everything', note: 'Every licence across all apps, soonest renewal first — the default sort.',
         query: {}},
        {title: 'Active licences for one app', note: 'Narrows to one application\'s Active licences, most expensive first.',
         query: {app_id: 1, status: 'Active', sort: '-cost'}},
        {title: 'Renewals dashboard', note: "A renewals tracker pulls everything inside each licence's own notice period so procurement can act before the deadline.",
         query: {due_soon: 'true'}},
    ],
    errors: [
        {code: 400, slug: 'invalid_parameter', when: "`sort` names a field that isn't sortable (renewal_date, app, cost, created_at, id)."},
    ],
},
'POST /software/licences': {
    examples: [
        {title: 'Minimum fields', note: 'app_id and licence_type are the only requirements — currency defaults GBP, status defaults Active.',
         body: {app_id: 1, licence_type: 'Per-seat subscription'}},
        {title: 'Full seat-counted licence', note: 'A typical per-seat licence with a renewal date, cost and a custom notice period.',
         body: {app_id: 1, licence_type: 'Per-seat subscription', quantity: 50, renewal_date: '2027-03-31', cost: 2400, currency: 'GBP', notice_period_days: 60}},
        {title: 'Procurement records a site licence', note: 'No `quantity` means unmetered — the API flags this so compliance reports show seats_available as null instead of a misleading number.',
         body: {app_id: 2, licence_type: 'Site licence', renewal_date: '2027-01-15', portal_url: 'https://vendor.example.com/portal', vendor_contact: 'accounts@vendor.example.com', notes: 'Site licence — no seat cap'}},
    ],
    errors: [
        {code: 422, slug: 'missing_field', when: "`app_id` is missing."},
        {code: 422, slug: 'invalid_field', when: "`app_id` doesn't match an existing application."},
        {code: 422, slug: 'missing_field', when: "`licence_type` is missing."},
    ],
},
'GET /software/licences/{id}': {
    examples: [
        {title: 'Get one licence', note: 'Full licence detail, including app_installs and the computed renewal_status.',
         path: {id: 1}},
    ],
},
'PATCH /software/licences/{id}': {
    examples: [
        {title: 'Bump the seat count', note: 'Send only the field that changed.',
         path: {id: 1}, body: {quantity: 75}},
        {title: 'Renew + reprice', note: 'Extends the renewal date alongside a new cost in one call.',
         path: {id: 1}, body: {quantity: 75, renewal_date: '2028-03-31', cost: 2600}},
        {title: 'Vendor renewal negotiation', note: 'A realistic renewal: new date and price agreed, with a note recording why.',
         path: {id: 1}, body: {renewal_date: '2028-03-31', cost: 2600, status: 'Active', notes: 'Renewed for another 12 months at a reduced rate.'}},
    ],
    errors: [
        {code: 422, slug: 'missing_field', when: 'The request body has no fields at all.'},
    ],
},
'DELETE /software/licences/{id}': {
    examples: [
        {title: 'Delete a licence', note: 'Permanently removes the licence record — nothing else references it.',
         path: {id: 9}},
    ],
},

'GET /service-status/services': {
    examples: [
        {title: 'The active board', note: 'The default view — active services only, each carrying its derived live status.',
         query: {}},
        {title: 'Search, explicit filter', note: 'Matches a service name; is_active is passed explicitly even though true is already the default.',
         query: {q: 'network', is_active: 'true'}},
        {title: 'Audit retired services', note: 'Lists services marked inactive instead of the live board — useful for cleaning up decommissioned entries.',
         query: {is_active: 'false'}},
    ],
},
'POST /service-status/services': {
    examples: [
        {title: 'Just a name', note: 'name is the only required field.',
         body: {name: 'Email'}},
        {title: 'With description + order', note: 'Adds a description and controls where it sits in the board.',
         body: {name: 'Email', description: 'Exchange Online', display_order: 2}},
        {title: 'Provisioning script pre-launch', note: 'A deployment tool registers a new service ahead of go-live, flagged inactive until launch day.',
         body: {name: 'VPN Gateway', description: 'Remote-access VPN concentrator', display_order: 5, is_active: false}},
    ],
    errors: [
        {code: 422, slug: 'missing_field', when: "`name` is missing."},
    ],
},
'GET /service-status/services/{id}': {
    examples: [
        {title: 'Get one service', note: 'The service with its derived status and its open incidents inline — enough for a detail page in one call.',
         path: {id: 1}},
    ],
},
'PATCH /service-status/services/{id}': {
    examples: [
        {title: 'Reorder it', note: 'Send only the field that changed.',
         path: {id: 1}, body: {display_order: 5}},
        {title: 'Update description + order', note: 'Changes two fields together.',
         path: {id: 1}, body: {description: 'Exchange Online (EU tenant)', display_order: 2}},
        {title: 'Retire a service', note: 'Takes a decommissioned service off the live board without deleting its incident history.',
         path: {id: 1}, body: {is_active: false}},
    ],
    errors: [
        {code: 422, slug: 'missing_field', when: 'The request body has no fields at all.'},
        {code: 422, slug: 'invalid_field', when: "`name` is cleared to empty."},
    ],
},
'DELETE /service-status/services/{id}': {
    examples: [
        {title: 'Delete a service', note: 'Removes the service and its incident links, but the incidents themselves are kept — just unlinked.',
         path: {id: 9}},
    ],
},
'GET /service-status/incidents': {
    examples: [
        {title: 'List everything', note: 'Every incident, open ones first, then most recently updated.',
         query: {}},
        {title: 'Open incidents for one service', note: "Narrows the board to what's currently affecting a single service.",
         query: {state: 'open', service_id: 1}},
        {title: 'Monthly uptime report', note: 'A reporting job pulls every incident resolved since the start of the period.',
         query: {state: 'resolved', resolved_since: '2026-06-01T00:00:00Z', per_page: 50}},
    ],
    errors: [
        {code: 400, slug: 'invalid_parameter', when: "`created_since` or `resolved_since` isn't a valid ISO 8601 date/time."},
    ],
},
'POST /service-status/incidents': {
    examples: [
        {title: 'Just a title', note: 'status defaults to Investigating and no services are linked yet.',
         body: {title: 'Email delivery delays'}},
        {title: 'With narrative + affected service', note: 'Adds the current-state comment and links one affected service with its impact level.',
         body: {title: 'Email delivery delays', comment: 'Probes report SMTP queue growth; investigating.', services: [{service_id: 1, impact_level: 'Degraded'}]}},
        {title: 'Monitoring probe opens an incident', note: "A health-check probe fires this the moment it sees consecutive failures — the verb this endpoint is built for.",
         body: {title: 'VPN gateway unreachable', status: 'Investigating', comment: 'Automated probe detected 3 consecutive failed health checks.', services: [{service_id: 3, impact_level: 'Major Outage'}]}},
    ],
    errors: [
        {code: 422, slug: 'missing_field', when: "`title` is missing."},
        {code: 422, slug: 'invalid_field', when: "`status`/`status_id` doesn't match a known, active incident status."},
        {code: 422, slug: 'invalid_field', when: "A `services` entry names an unknown service_id or an unknown/inactive impact_level — the API rejects these instead of silently skipping them like the UI does."},
    ],
},
'GET /service-status/incidents/{id}': {
    examples: [
        {title: 'Get one incident', note: 'Full incident detail: status, narrative comment, and every affected service with its impact level.',
         path: {id: 12}},
    ],
},
'PATCH /service-status/incidents/{id}': {
    examples: [
        {title: 'Add a status update', note: 'Overwrites the single narrative comment field — the module has no timeline, just the current state.',
         path: {id: 12}, body: {comment: 'Update: still investigating root cause.'}},
        {title: 'Move to Monitoring', note: 'Changes status and comment together after a fix is deployed.',
         path: {id: 12}, body: {status: 'Monitoring', comment: 'Fix deployed; watching for recurrence.'}},
        {title: 'Probe closes the loop', note: 'The same monitoring probe resolves the incident once its health check recovers — resolved_at is stamped automatically.',
         path: {id: 12}, body: {status: 'Resolved', comment: 'Health checks recovered; service confirmed reachable.'}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: "`status`/`status_id` doesn't match a known, active incident status."},
        {code: 422, slug: 'invalid_field', when: "A replacement `services` entry names an unknown service_id or impact_level."},
        {code: 422, slug: 'missing_field', when: 'The request body has no fields at all.'},
    ],
},
'DELETE /service-status/incidents/{id}': {
    examples: [
        {title: 'Delete an incident', note: 'Removes the incident and its service links together, transactionally.',
         path: {id: 12}},
    ],
},

// ===== extras_checks_forms_wf =====
/**
 * Draft entries for window.API_EXTRAS — 'Morning checks', 'Forms', 'Workflows'
 * sections (docs.php SPEC). Merge these keys into system/api/docs-extras.js.
 */

// ---------------------------------------------------------------------------
// Morning checks
// ---------------------------------------------------------------------------

'GET /morning-checks/board': {
    examples: [
        {title: "Today's board", note: 'The simplest call — no date means today, server-local, exactly like the dashboard.'},
        {title: 'A specific day', note: 'Pass a date to see what the board looked like on a past morning.',
         query: {date: '2026-07-04'}},
        {title: 'Ops wallboard refresh', note: 'A wallboard app polls this every morning to show every active check and whether it has been done yet.',
         query: {date: '2026-07-04'}},
    ],
},
'POST /morning-checks/results': {
    examples: [
        {title: 'Mark a check green', note: 'The simplest recording — a status label and the check it belongs to.',
         body: {check_id: 1, status: 'Green'}},
        {title: 'Amber with notes and a backdated day', note: 'Combine an explicit date with notes explaining why the status is not green.',
         body: {check_id: 1, status: 'Amber', notes: 'Slow but functional — investigating', date: '2026-07-04'}},
        {title: 'Backup probe fills the morning-checks board', note: 'A nightly backup script posts its own result automatically each morning, no analyst needed — recording again the same day overwrites rather than duplicating.',
         body: {check_id: 2, status: 'Red', notes: 'Veeam overnight job failed — see INC-4521', date: '2026-07-04'}},
    ],
    errors: [
        {code: 422, slug: 'missing_field', when: "A status flagged 'requires notes' (e.g. Red) is recorded with an empty notes field."},
        {code: 422, slug: 'invalid_field', when: 'check_id does not match a check, or status/status_id does not match an active status.'},
    ],
},
'GET /morning-checks/results': {
    examples: [
        {title: 'Full history', note: 'No filters — every recorded result, newest date first.'},
        {title: 'One check over a date range', note: 'Combine check_id with from/to to see how a single check has trended over a month.',
         query: {check_id: 1, from: '2026-06-01', to: '2026-07-04'}},
        {title: 'Find orphaned results', note: "orphans=true surfaces results whose status was later deleted — the same set the dashboard's warning banner shows, ready to remap in Settings.",
         query: {orphans: 'true'}},
    ],
},
'GET /morning-checks/results/{id}': {
    examples: [
        {title: 'Get one result', note: 'Fetch a single recorded result by its id.', path: {id: 1}},
    ],
},
'GET /morning-checks/checks': {
    examples: [
        {title: 'All checks', note: 'No filters — every check definition, active and inactive, in board order.'},
        {title: 'Search active checks', note: 'Combine is_active with a name search.',
         query: {is_active: 'true', q: 'backup'}},
        {title: 'Onboard a new monitoring tool', note: 'A new probe needs check ids before it can start posting results, so it first lists the active checks it can report against.',
         query: {is_active: 'true'}},
    ],
},
'POST /morning-checks/checks': {
    examples: [
        {title: 'Add a check', note: 'Only a name is required.', body: {name: 'Backups completed'}},
        {title: 'Add a check with detail', note: 'Combine a description with sort order and active state.',
         body: {name: 'Firewall config sync', description: 'Checks overnight firewall config replication', sort_order: 5, is_active: true}},
        {title: 'Stand up a new automated probe', note: 'A new monitoring integration adds its own check before it starts posting daily results against it.',
         body: {name: 'Antivirus definitions updated', description: 'Confirms AV signatures pulled overnight', sort_order: 6}},
    ],
},
'GET /morning-checks/checks/{id}': {
    examples: [
        {title: 'Get one check', note: 'Fetch a single check definition by its id.', path: {id: 1}},
    ],
},
'PATCH /morning-checks/checks/{id}': {
    examples: [
        {title: 'Rename a check', note: 'Send only the field that changes.', path: {id: 1}, body: {name: 'Backups verified'}},
        {title: 'Update several fields at once', note: 'Combine a description and sort order change in one call.',
         path: {id: 1}, body: {description: 'Confirms all overnight backup jobs completed successfully', sort_order: 2}},
        {title: 'Retire a decommissioned check', note: 'Deactivating removes a check from the board — for a server being decommissioned — while its historical results stay intact.',
         path: {id: 3}, body: {is_active: false}},
    ],
},
'DELETE /morning-checks/checks/{id}': {
    examples: [
        {title: 'Delete a check', note: 'Removes the check and all its historical results — prefer deactivating instead if the history is worth keeping.', path: {id: 5}},
    ],
},

// ---------------------------------------------------------------------------
// Forms
// ---------------------------------------------------------------------------

'GET /forms': {
    examples: [
        {title: 'List all forms', note: 'No filters — one row per version chain, the current editable version of each.'},
        {title: 'Search active forms', note: 'Combine is_active with a title search.', query: {is_active: 'true', q: 'starter'}},
        {title: 'Self-service portal form picker', note: 'An internal portal lists only active forms so employees always pick from the current, in-use set.',
         query: {is_active: 'true'}},
    ],
},
'POST /forms': {
    examples: [
        {title: 'Create a bare form', note: 'Only a title is required — fields can be added later with a PATCH.', body: {title: 'Contact details update'}},
        {title: 'Add a couple of fields', note: 'Ship an ordered array of typed fields in the same call.',
         body: {title: 'Equipment request', description: 'Request new hardware', fields: [{field_type: 'text', label: 'Item needed', is_required: true}, {field_type: 'number', label: 'Quantity', is_required: true}]}},
        {title: 'Build the new-starter onboarding form', note: 'HR builds a multi-field form once, ready for the self-service portal and its own workflow automation.',
         body: {title: 'New starter', fields: [{field_type: 'text', label: 'Full name', is_required: true}, {field_type: 'email', label: 'Manager email', is_required: true}, {field_type: 'dropdown', label: 'Department', options: ['IT', 'HR', 'Finance']}]}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: 'A field has an empty label, or its field_type is not one of the eight supported types.'},
    ],
},
'GET /forms/{id}': {
    examples: [
        {title: 'Get one form', note: 'Returns the form with its fields and version info (version.is_current shows whether this is the editable leaf).', path: {id: 1}},
    ],
},
'PATCH /forms/{id}': {
    examples: [
        {title: 'Rename a form', note: 'Send only the field that changes.', path: {id: 1}, body: {title: 'New starter (onboarding)'}},
        {title: 'Retire a form', note: 'Combine is_active with an explanatory description — the only way to retire a form without deleting it.',
         path: {id: 1}, body: {is_active: false, description: 'Retired — replaced by the v2 onboarding form'}},
        {title: 'Add a field to a live form', note: "Sending fields replaces the set using the same positional sync the UI uses, so existing field ids — and their submission history — survive; this fails with 409 on a frozen historical version, so fork a new version first if you need to keep the old one intact.",
         path: {id: 1}, body: {fields: [{field_type: 'text', label: 'Full name', is_required: true}, {field_type: 'email', label: 'Manager email', is_required: true}, {field_type: 'text', label: 'Start date', is_required: true}]}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'The form id is a frozen historical version rather than the current (leaf) version.'},
        {code: 422, slug: 'invalid_field', when: 'title is sent empty, or a fields entry has an empty label or unknown field_type.'},
    ],
},
'DELETE /forms/{id}': {
    examples: [
        {title: 'Delete the current version', note: 'Removes just this version and its submissions — only works on the leaf version.', path: {id: 4}},
        {title: 'Delete an entire version chain', note: 'chain=true wipes every version of a retired form and all of their submissions in one transaction.',
         path: {id: 1}, query: {chain: 'true'}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'The id is a mid-chain version with newer versions built on it and chain=true was not passed.'},
    ],
},
'GET /forms/{id}/versions': {
    examples: [
        {title: 'View the version chain', note: 'Every version of this form, oldest first, so you can see how it has evolved over time.', path: {id: 1}},
    ],
},
'POST /forms/{id}/versions': {
    examples: [
        {title: 'Fork a new version', note: 'Clones the current version into a new editable one and freezes the source.', path: {id: 1}},
        {title: 'Edit a live form safely', note: 'Fork the onboarding form before adding a new mandatory field, so every existing submission stays attached to the frozen v1 rather than being reshaped.',
         path: {id: 1}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'The id being forked is not the current (leaf) version.'},
    ],
},
'GET /forms/{id}/submissions': {
    examples: [
        {title: 'List submissions', note: 'No filters — every submission for this version, newest first.', path: {id: 1}},
        {title: 'Page through a date range', note: 'Combine date bounds with pagination.',
         path: {id: 1}, query: {submitted_since: '2026-06-01T00:00:00Z', page: 1, per_page: 20}},
        {title: "HR's weekly onboarding review", note: 'HR pulls every new-starter submission from the last week to check nothing has been missed.',
         path: {id: 1}, query: {submitted_since: '2026-06-27T00:00:00Z'}},
    ],
},
'POST /forms/{id}/submissions': {
    examples: [
        {title: 'Submit one answer', note: 'data maps field id to value — the simplest possible submission.',
         path: {id: 1}, body: {data: {'1': 'Sam Jones'}}},
        {title: 'Submit several answers', note: 'Answer every field on the form in one call.',
         path: {id: 1}, body: {data: {'1': 'Sam Jones', '2': 'manager@example.com', '3': 'IT'}}},
        {title: 'New-starter form submission fires the workflow', note: 'A completed new-starter form dispatches the form.submitted event with the answers, which a workflow can use to create the onboarding ticket automatically.',
         path: {id: 1}, body: {data: {'1': 'Sam Jones', '2': 'manager@example.com', '3': 'IT'}}},
    ],
    errors: [
        {code: 409, slug: 'conflict', when: 'The form is inactive.'},
        {code: 422, slug: 'missing_field', when: 'A required field is left blank (or, for checkboxes, nothing is ticked).'},
        {code: 422, slug: 'invalid_field', when: 'An email/number field fails its format check, or the data map references a field id that does not belong to this form.'},
    ],
},
'GET /forms/{id}/submissions/{submission_id}': {
    examples: [
        {title: 'Get one submission', note: 'Fetch a single submission with its answers joined to field labels.', path: {id: 1, submission_id: 10}},
    ],
},
'DELETE /forms/{id}/submissions/{submission_id}': {
    examples: [
        {title: 'Delete a submission', note: 'Removes a test or duplicate submission and its answers.', path: {id: 1, submission_id: 10}},
    ],
},

// ---------------------------------------------------------------------------
// Workflows
// ---------------------------------------------------------------------------

'GET /workflows': {
    examples: [
        {title: 'List all workflows', note: 'No filters — every automation rule, most recently updated first.'},
        {title: 'Active ticket-creation rules', note: 'Combine trigger_event with is_active to see only what actually runs on a given event.',
         query: {trigger_event: 'ticket.created', is_active: 'true'}},
        {title: 'Pre-change-freeze audit', note: 'Before a change freeze, list every active automation so nothing unexpected fires while systems are locked down.',
         query: {is_active: 'true'}},
    ],
},
'POST /workflows': {
    examples: [
        {title: 'Log every new ticket', note: 'The simplest rule — no conditions, one action.',
         body: {name: 'Log every new ticket', trigger_event: 'ticket.created', actions: [{type: 'log_message', args: {message: 'New ticket created: {{ticket.id}}'}}]}},
        {title: 'Flag urgent-sounding tickets', note: 'Combine a condition (contains) with an action that reads the matched ticket back via a template variable.',
         body: {name: 'Flag urgent tickets', trigger_event: 'ticket.created', conditions: [{field: 'ticket.subject', op: 'contains', value: 'urgent'}], actions: [{type: 'add_ticket_note', args: {ticket_id: '{{ticket.id}}', note: 'Subject contains "urgent" — review priority.'}}]}},
        {title: 'Escalation rule for P1 priority changes', note: 'A realistic rule: whenever a ticket is raised to the top priority, drop an audit note on it automatically instead of relying on an analyst to remember.',
         body: {name: 'Escalate P1s', trigger_event: 'ticket.priority_changed', conditions: [{field: 'new_priority_id', op: 'in', value: ['1']}], actions: [{type: 'add_ticket_note', args: {ticket_id: '{{ticket.id}}', note: 'Auto-escalated: priority raised to P1.'}}]}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: 'trigger_event is not one of the engine\'s triggers (see GET /workflow-triggers), or a condition operator / action type is not recognised (see GET /workflow-actions).'},
    ],
},
'GET /workflows/{id}': {
    examples: [
        {title: 'Get one workflow', note: 'The full rule — decoded conditions and actions, creator, run stats.', path: {id: 1}},
    ],
},
'PATCH /workflows/{id}': {
    examples: [
        {title: 'Rename a workflow', note: 'Send only the field that changes.', path: {id: 1}, body: {name: 'Escalate P1 tickets (v2)'}},
        {title: 'Widen a condition', note: 'Combine a condition change with an updated description.',
         path: {id: 1}, body: {conditions: [{field: 'new_priority_id', op: 'in', value: ['1', '2']}], description: 'Now escalates P1 and P2 priority changes'}},
        {title: 'Pause during a maintenance window', note: 'Toggle is_active off to pause a rule without losing its configuration, then flip it back on afterwards.',
         path: {id: 1}, body: {is_active: false}},
    ],
    errors: [
        {code: 422, slug: 'invalid_field', when: 'A sent trigger_event, condition operator or action type fails the same catalogue validation as create.'},
    ],
},
'DELETE /workflows/{id}': {
    examples: [
        {title: 'Delete a workflow', note: 'Hard delete — its past executions survive as an audit trail, detached but still attributable by name.', path: {id: 3}},
    ],
},
'POST /workflows/{id}/fire': {
    examples: [
        {title: 'Fire with a minimal payload', note: 'The simplest test-fire — just enough payload for the actions to run.',
         path: {id: 1}, body: {payload: {ticket: {id: 123}}}},
        {title: 'Fire with a fuller synthetic ticket', note: 'Combine several payload fields so both conditions and template variables have something to read.',
         path: {id: 1}, body: {payload: {ticket: {id: 123, subject: 'Test ticket', priority_id: 1}}}},
        {title: 'Escalation rule test-fired with a synthetic payload', note: "Before relying on the P1 escalation rule in production, test-fire it with a synthetic priority-changed payload and check the returned step log — no real P1 ticket needed, and run_count isn't touched.",
         path: {id: 1}, body: {payload: {ticket: {id: 456, subject: 'Server down', priority_id: 1}, new_priority_id: 1}}},
    ],
},
'GET /workflows/{id}/executions': {
    examples: [
        {title: "List a workflow's runs", note: 'No filters — every run of this rule, newest first.', path: {id: 1}},
        {title: 'Recent failures', note: 'Combine a status filter with a lower time bound.',
         path: {id: 1}, query: {status: 'failed', started_since: '2026-07-01T00:00:00Z'}},
        {title: 'Debug a rule that stopped firing', note: 'When an escalation rule seems to have gone quiet, filter its runs to failed to see exactly which step broke and why.',
         path: {id: 1}, query: {status: 'failed'}},
    ],
},
'GET /workflow-executions': {
    examples: [
        {title: 'List all runs', note: 'No filters — install-wide execution history, newest first.'},
        {title: 'Failures on one trigger', note: 'Combine a status filter with a trigger_event filter across every workflow.',
         query: {status: 'failed', trigger_event: 'ticket.created'}},
        {title: 'Find orphaned runs after cleanup', note: 'After deleting some old workflows, list runs whose parent rule no longer exists to confirm nothing important was lost.',
         query: {orphaned: 'true'}},
    ],
},
'GET /workflow-executions/{id}': {
    examples: [
        {title: 'Get one run', note: 'Full detail — the trigger payload snapshot and the per-step log, for debugging exactly what a specific run did.', path: {id: 42}},
    ],
},


};
