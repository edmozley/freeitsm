# SLA Module — Design & Roadmap

Service Level Agreements for the Tickets module. Tracks response and resolution times against per-priority targets, respects business hours, and surfaces breach risk visibly.

This document captures the design decisions made during planning so future work can continue without re-litigating them. Anything not listed here is undecided.

---

## Why business-hours timing, not wall-clock

A P2 ticket arrives at 17:30, office closes at 18:00, opens 08:00 next day, analyst replies at 08:00. **The clock has ticked 30 minutes, not 14.5 hours.**

Two industry models exist:

| Model | Behaviour | Suits |
|---|---|---|
| **Wall-clock / 24×7** | Clock runs continuously | True 24×7 support desks; trivial to implement |
| **Business-hours** | Clock only ticks during configured working hours | Every typical Mon-Fri desk; fair to analysts; industry standard for ITIL-aligned SLAs |

**We chose business-hours** as the default. Wall-clock punishes you for office closures you can't avoid and makes every overnight ticket a guaranteed breach. ServiceNow, Jira Service Management, Freshservice, Zendesk all default to business-hours.

P1 (or any priority) can still opt into a 24×7 calendar — the "calendar" abstraction below makes this a configuration choice, not a code path.

---

## Why compute on read, not store running counters

**Don't store `sla_elapsed_seconds` on the ticket.** Compute it on demand.

The ticket audit log already records every status change (`from_status`, `to_status`, `changed_at`). The SLA engine reads those rows, splits the ticket lifetime into intervals where status was "running" vs. "paused", intersects each running interval with the relevant business calendar, sums the result, compares against the priority's target.

**Advantages of compute-on-read**:
- No drift — audit log is the single source of truth
- No background jobs to keep counters in sync
- No cache to invalidate
- Changing pause rules later means "next page-load uses the new rules" — zero migration
- Editing a business calendar (e.g. extending hours from 9-17 to 8-18) retroactively re-evaluates all open tickets correctly
- Trivial to debug — the computation is deterministic from the log

**Performance**: a ticket with say 10 status changes over 30 days computes in single-digit milliseconds. List views of 100+ tickets stay sub-100ms. If this ever becomes a bottleneck we can cache per-ticket but the bar is high.

---

## Business Calendars, not Offices

A first instinct is "Office (London / Sydney) with location + timezone, analysts linked to offices". This is the wrong abstraction.

**An SLA contract is between the service desk and the customer.** It doesn't matter which analyst happens to pick up the ticket — what matters is the calendar promised in the contract. If the SLA says "P2 = 1h response in London business hours", a Sydney analyst replying at their 14:00 (London 04:00) still misses the SLA if it's outside London hours.

**The clean model: Business Calendars.** Same as ServiceNow / Jira SM / Zendesk:

- A **Calendar** = name + timezone + weekly working hours pattern + holiday list
- An **SLA policy** (per priority) picks which calendar it uses
- Analysts are not linked to anything for SLA purposes

This covers:
- Single-office desk (one default calendar)
- Mixed 24×7 + business-hours (P1 → 24×7 calendar; P2-P4 → London calendar)
- Future follow-the-sun expansion (add "Sydney business hours" calendar, point new policies at it — no schema migration)

**If we later need to track where analysts sit** (for rota display, time-zone-aware reply scheduling, etc.) that's a separate concept, not part of SLA.

---

## Per-company SLAs (GH #320)

On a multi-company install, priorities and calendars can belong to one company, so each client can have its own SLA targets and business hours.
On a single-company install nothing here applies: every priority and calendar is shared, and the screens look as they always have.

**Shared and company rows.** `ticket_priorities.tenant_id` and `sla_calendars.tenant_id` follow the same rule as `ticket_types`: NULL is shared by every company, a set value is that company's own.
The Default company has no rows of its own; its list is the shared list.

**What a company sees.** Its own priorities plus the shared ones it has not hidden (`tenant_config_hidden`, `entity_type = 'ticket_priority'`).
Its calendars are its own plus every shared one; calendars are not hidden per company.
A ticket with no company yet (triage) sees the shared list.
All of this goes through `includes/ticket_priorities.php` (`ticketPrioritiesFor()`, `slaCalendarsFor()` and friends), never a raw query.

**Defaults.** `is_default` is per scope: one shared default, and at most one per company.
- A new ticket's priority: the company's own default, else the shared default, else its first priority (`ticketPriorityDefaultId()`).
- A ticket's calendar when its priority names none: the company's own default calendar, else the shared default, else no SLA (`slaDefaultCalendarId()`, used by `sla_get_state()`).

**Which calendar a priority may use.** A shared priority applies to every company, so it can only use shared calendars.
A company's own priority can use the shared calendars and that company's own.

**Choosing a priority.** Every picker offers the ticket's company's list: the ticket view, right-click, bulk edit (across several companies, only the shared priorities none of them hides), New Ticket, the portal and the REST API.
`TicketsService` resolves a priority id or name among that company's priorities and refuses anything else with the usual 422.
The portal and the channels that choose a priority for themselves (a form, a workflow step, a Zabbix severity map) fall back to the company's default instead (`ticketPriorityForNewTicket()`).

**Moving a ticket to another company.** If the new company cannot use its priority, the ticket gets that company's default, recorded on its history and raised as `ticket.priority_changed` (`ticketPriorityFollowCompanyMove()`, called from `ticketsFollowCompanyMove()`).

**Who can change what.** A shared priority or calendar affects every company, so changing one needs access to every company (`analystHasAllTenantAccess()`).
A company's own needs access to that company.

---

## v1 settings UI scope

A new **SLA** tab on the Tickets settings page. Six sections:

### 1. Business Calendars
List of calendars showing name / timezone / weekly hours / holiday count. Add / edit / delete via modal. One marked as default (one shared, and one per company on a multi-company install).

The calendar editor exposes:
- **Name** + **Timezone** (IANA zone name, e.g. `Europe/London`)
- **Weekly hours** — per weekday, a start time and end time (or a "closed" toggle). Most calendars are Mon-Fri 08:00-18:00, Sat/Sun closed.
- **Holidays** — list of dates (with optional name) that override the weekly pattern. Per-calendar; not a global list, since different regions observe different holidays.

### 2. SLA Targets
A table with one row per priority showing:
- **Response time target** (minutes)
- **Resolution time target** (minutes — optional in v1; can defer)
- **Calendar** (dropdown — picks which Business Calendar to use)

E.g. P1: 15 min / 4 h / 24×7 calendar; P2: 1 h / 8 h / London calendar; etc.

### 3. Pause behaviour
Each row in the existing **Statuses** settings tab gets a new **"Pauses SLA"** checkbox column. Tick any number of statuses — all ticked statuses pause the clock. No new screen / multi-select widget needed; the column naturally supports 1-or-more pausing statuses.

Sensible defaults seeded on migration: *Pending Customer*, *On Hold*.

### 4. Mid-ticket priority change behaviour
Radio button, one of:
- **Apply new SLA from change-point forward** (default — preserves accrued time and starts the new target's clock fresh at the change point)
- **Recompute retroactively against the new target** (can cause instant breach — useful for incident-management workflows where a reclassification means "this was always a P2")
- **Reset the SLA clock entirely** (start fresh, accrued time discarded — useful when the analyst is treating the reclassified ticket as conceptually new)

### 5. Reopened-ticket behaviour
When a closed ticket is reopened, does the SLA continue from where it paused, or start fresh? Toggle. Default: *start fresh* (most desks).

### 6. Breach warning
- **Warning threshold** — at what % of the SLA elapsed should the ticket flag visually in the inbox (default 80%)
- **Notify assignee at warning** — toggle, sends email
- **Notify team lead at breach** — toggle, sends email

### 7. Enforce SLAs from date/time
A single nullable datetime field. Semantics:
- **NULL** → SLA enforcement disabled entirely. No ticket is evaluated. No breach warnings, no UI badges.
- **Set to a datetime** → the SLA engine skips any ticket whose `created_at < sla_enforce_from`. Tickets created at or after the cutoff are evaluated normally.

Why a datetime, not a launch-time flag baked into deployment: it's admin-controlled and predictable. To grandfather in all open tickets when first enabling SLAs, the admin sets the cutoff to *now*. To retroactively apply SLAs from an earlier point (e.g. the start of a contract period), they set an earlier date. To disable SLA enforcement temporarily, they NULL it out.

Equivalent to ServiceNow's "Effective from" pattern.

### 8. First-response definition
Radio button, one of:
- **Outbound email only** (Reply or Forward to the requester counts; nothing else does)
- **Status change away from default** (e.g. ticket moves from *New*/*Open* to *In Progress* counts; outbound email alone doesn't)
- **Either, whichever first** (default — analyst-acknowledgement of any kind stops the response clock)

---

## Out of scope for v1

These all have legitimate demand but expand the data model substantially. Deferring lets us ship and learn:

- **Per-contract SLA overrides** — some customers have premium contracts with tighter SLAs. Needs a Contracts↔SLA link table and a resolution rule for "which SLA applies to this ticket". Per-company priorities and calendars (above) cover the common case of different SLAs per customer.
- **Per-department / per-team SLA overrides** — same shape as above.
- **Service hours vs. support hours distinction** — some tools split "the system is available" from "you can call the help desk".
- **Multi-stage SLA** — separate clocks for response / next-update / resolution, each with their own target and pause rules.
- **OLAs (Operational Level Agreements)** — internal SLAs between teams that don't directly affect the customer-facing SLA.

---

## Known gotchas (worth flagging, no action required yet)

1. **Timezones** — store all timestamps as UTC in the DB. Business-hours intersection happens in the calendar's timezone. Display in the analyst's preferred timezone (already a user preference). PHP's `DateTimeImmutable` + `DateTimeZone` handles this natively; never use naive timestamps.

2. **DST transitions** — twice a year one local day is 23h, another is 25h. Falls out correctly if you do all arithmetic with timezone-aware DateTimes. The 23h day will have less business time available, the 25h day more — which is what users expect.

3. **Mid-ticket priority changes** — see Section 4 above. The radio button is the answer; the implementation reads the audit log to find the change point and applies the chosen rule.

4. **Editing business calendars** — compute-on-read means changing "Mon-Fri 9-17" to "Mon-Fri 8-18" retroactively re-evaluates every open ticket. This is the right default behaviour (you've changed your hours, so the new hours apply). Closed tickets keep their as-stored breach flag (the final state was computed against the old calendar at close time — we should snapshot the breach result on close).

5. **Holidays added retroactively** — same story. If you add Christmas Day to the calendar today, open tickets that crossed that date get the day refunded from their SLA clock. Correct behaviour.

6. **Status that pauses being removed from the pause list** — if "On Hold" stops being a pausing status, tickets currently in "On Hold" start their clock immediately. Possibly surprising. We may want a "freeze" date on the setting that says "applies to status changes after this date".

---

## Data model sketch (proposed, not yet built)

Three new tables:

| Table | Purpose |
|---|---|
| `sla_calendars` | name, timezone (IANA), is_default, tenant_id (NULL = shared), audit timestamps |
| `sla_calendar_hours` | calendar_id, weekday (0-6), start_time, end_time, is_closed |
| `sla_calendar_holidays` | calendar_id, holiday_date, name |

Plus columns on existing tables:

| Table | New columns |
|---|---|
| `ticket_priorities` | `sla_response_minutes`, `sla_resolution_minutes` (nullable), `sla_calendar_id` (FK), `tenant_id` (NULL = shared) |
| `ticket_statuses` | `pauses_sla` TINYINT(1) DEFAULT 0 |

Plus a `system_settings` group for the global toggles:
- `sla_enforce_from` (DATETIME NULL) — see section 7 above; NULL disables the whole SLA engine
- `sla_priority_change_behaviour` (VARCHAR enum: `forward` / `recompute` / `reset`) — section 4
- `sla_reopen_behaviour` (VARCHAR enum: `continue` / `reset`) — section 5
- `sla_warning_threshold_percent` (TINYINT, default 80) — section 6
- `sla_notify_assignee_at_warning` (TINYINT(1)) — section 6
- `sla_notify_lead_at_breach` (TINYINT(1)) — section 6
- `sla_first_response_definition` (VARCHAR enum: `outbound_email` / `status_change` / `either`) — section 8

**No table for "current SLA state per ticket"** — that's the deliberate compute-on-read decision. If we want a denormalised flag (e.g. `is_breached`) for fast list filtering, we add it later as a snapshot updated on status change.

---

## Implementation plan (when we get there)

Order matters — each step is independently shippable:

1. **Schema** — three new `sla_*` tables + columns on `ticket_priorities` / `ticket_statuses` + `db_verify.php` updates.
2. **Settings UI** — the SLA tab with the six sections above. Read-only until step 3.
3. **SLA engine (`includes/sla.php`)** — the business-hours intersection function + pause-aware elapsed-time computation. Pure functions, easy to unit-test.
4. **Surface SLA state in the inbox** — column in the ticket list showing time-to-breach, colour-coded; badge in the reading pane.
5. **Breach warning** — when computing SLA state, evaluate against the warning threshold and the breach point; trigger emails via the existing template system.
6. **Reporting integration** — SLA performance widgets on the existing tickets dashboard (breach rate per priority, mean response time vs. target, etc.).

Each step is a separate PR and a separate changelog entry.

---

## Decisions log

For posterity / future-Ed-or-me wondering why we did things this way:

- **Pauses-SLA flag** lives on the `ticket_statuses` row (boolean column). Multiple statuses can be flagged; all pause the clock. Decided over an SLA-tab multi-select widget because the data belongs naturally to the status and the existing Statuses settings tab can grow a column without a new screen.
- **Pre-existing tickets** are handled by the `sla_enforce_from` datetime (section 7). Admin-controlled cutoff: NULL disables everything; setting it makes the engine skip any ticket created before that point. Picked over a launch-flag baked into deployment because it's predictable and re-configurable.
- **First-response definition** is an admin choice (section 8), with the recommended default being "either outbound email or status change — whichever happens first". Picked over a hardcoded definition because different desks have different conventions.
- **Per-company priorities and calendars** (GH #320) reuse the shared-plus-own model ticket types already had, rather than a new SLA-policy table. Per-contract overrides stay out of scope: they would need a contract on the ticket before a priority could be chosen.
- **Calendars are not hidden per company**, unlike priorities. Calendars are only seen in settings, a company's own default already beats the shared one, and hiding the shared default would only leave a company with no calendar and silently no SLA.
- **The Default company has no priorities or calendars of its own.** In its context Settings manages the shared rows, as for ticket types.
