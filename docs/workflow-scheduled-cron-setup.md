# Time-Based Workflow Triggers — Cron Setup

Most workflow triggers hang off a **write path**: someone saves a ticket, so there's a moment to fire from. These ones don't exist until something goes looking.

> "The SLA is about to breach." "This contract expires in 30 days."
>
> Nothing *happened*. **Time passed.** There is no save to hang a `dispatch()` off, so a scheduled job has to go and find them.

**If you don't schedule these, the time-based triggers never fire at all.** The workflows will sit there, active, doing nothing, and nothing will tell you why.

---

## Which cron emits what

There are **two**, because SLA state is already computed by an existing job and duplicating that work would be wasteful and could drift.

| Trigger | Emitted by | Schedule |
|---|---|---|
| `sla.warning` | `cron/sla_breach_check.php` | every 5 minutes |
| `sla.breached` | `cron/sla_breach_check.php` | every 5 minutes |
| `contract.expiring` | `cron/workflow_scheduled.php` | hourly is plenty |
| `asset.warranty_expiring` | `cron/workflow_scheduled.php` | hourly is plenty |

The SLA cron already existed (it sends the SLA notification emails). Workflow events are now emitted from the same pass — free, and guaranteed not to disagree with what the emails think.

> **Note:** the workflow event fires **whether or not** an SLA notification rule matches. Notification rules decide who gets an *email*; they have nothing to say about automation.

---

## Windows

`contract.expiring` and `asset.warranty_expiring` fire at **90, 30, 7 and 1 days** before the date. Each is a separate emission carrying `window_days`, so *"only remind me at 30 days"* is a plain condition:

```
window_days  equals  30
```

...rather than something your workflow has to schedule for itself.

---

## Setting it up

### Windows (Task Scheduler)

```
Program:    C:\wamp64\bin\php\php8.4.0\php.exe
Arguments:  C:\wamp64\www\freeitsm-app\cron\workflow_scheduled.php
Trigger:    Daily, repeat every 1 hour, indefinitely
```

Do the same for `cron/sla_breach_check.php` at **every 5 minutes** if you want the SLA triggers.

### Linux (crontab)

```cron
*/5 * * * *  /usr/bin/php /var/www/freeitsm-app/cron/sla_breach_check.php    >/dev/null 2>&1
0   * * * *  /usr/bin/php /var/www/freeitsm-app/cron/workflow_scheduled.php  >/dev/null 2>&1
```

### HTTP (if you can't run CLI)

```
https://your-host/freeitsm-app/cron/workflow_scheduled.php?token=<workflow_cron_token>
```

The token is seeded by **Database Verification** into `system_settings.workflow_cron_token`. CLI invocation needs no token — there's no untrusted caller.

A minimum interval (`workflow_cron_min_interval_seconds`, default **300**) is enforced for both CLI and HTTP, so double-scheduling or a runaway loop can't hammer it.

---

## Fire-once: the bit that matters

A time-based condition **stays true**. A breached SLA is still breached five minutes later, and five minutes after that. Naively dispatching on detection would re-fire the same escalation *every single run, forever* — which would be worse than not having the feature.

So every emission is written to a ledger, `workflow_scheduled_emissions`, with a **UNIQUE key** over `(trigger_event, entity_key, fingerprint)`, using `INSERT IGNORE`. **Only the insert that actually created a row dispatches.** The database is the arbiter, not the application — so two overlapping cron runs cannot double-fire.

### The fingerprint — why "fire once" isn't quite enough

The **fingerprint** is the *state* the emission was recorded against: an SLA's target in minutes, a contract's end date.

If that changes, the fingerprint changes, and the new deadline is allowed to fire again.

- **Raise a ticket's priority** → its SLA target shrinks → new fingerprint → the *new, tighter* deadline can breach and escalate. Without this, the workflow would go quiet at exactly the moment the ticket got more urgent.
- **Renew a contract** → new end date → new fingerprint → next year's reminders still fire.

Without the fingerprint, "fire once" would silently mean *"fire once **ever**, even if the thing you were watching changed underneath you."*

### Nothing is burned on an audience of nobody

If **no active workflow** is listening for an event, **no ledger row is written**.

This matters more than it sounds. If we recorded an emission with nothing listening, the ledger would say *"already fired"* for every contract currently inside its window — and switching on a renewal workflow tomorrow would leave it **silent for all of them**, for a reason you could never see. Instead, the first run after you activate a workflow fires for everything currently in-window, which is what anyone would expect.

Old ledger rows are pruned after a year — far longer than any window we announce, so nothing can be resurrected by a prune.

---

## Checking it works

```
php cron/workflow_scheduled.php
```

```
OK — contract_expiring: 1, asset_warranty_expiring: 0, pruned 0 old emission(s).
```

Then look at **Workflows → Execution log** — the run will be there, with its step log and the trigger payload. Run it a second time and it should report **0**: nothing new has happened, so nothing should fire.

If it reports emissions but you see no runs, check the workflow is **Active**.

---

## See also

- `docs/sla-cron-setup.md` — the SLA breach-check cron (also emits `sla.warning` / `sla.breached`).
- `docs/webhook-cron-setup.md` — the **delivery** worker. Remember: a workflow firing and a webhook *arriving* are two different things. If your escalation posts to Slack, that cron has to be running too, or the message queues and never leaves.
