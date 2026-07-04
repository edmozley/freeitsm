# Outbound Webhook Delivery Cron — Setup Guide

FreeITSM sends outbound webhooks (the **Send a webhook** workflow action) *asynchronously*: when a workflow fires, the webhook is added to a queue and returned instantly, so a slow or dead endpoint never delays the ticket save or the workflow run. A scheduled task then delivers the queue with automatic retries.

This document describes how to set up that schedule on Windows and Linux.

---

## What runs

A single PHP script: `cron/webhook_deliveries.php`

It picks up due rows from `webhook_deliveries` (status `pending` or a `failed` row whose retry time has arrived), POSTs each to its URL, and records the outcome. On failure it retries with exponential backoff (1 min → 5 min → 15 min → 1 h → 6 h) and gives up after `max_attempts` (default 6), marking the row `dead`. Delivered and dead rows are pruned after `webhook_delivery_retention_days` (default 30).

Output is plain text — counts of attempted / delivered / failed / dead / pruned.

**Recommended cadence: every 1 minute.** Delivery latency is roughly your cron interval, so a minute keeps chat pings and pages feeling prompt. The `webhook_cron_min_interval_seconds` setting (default 20s) stops accidental double-scheduling from doing harm.

Review every delivery — status, retries, the response, and a **Replay** button — under **Workflows → Webhook deliveries** in the app.

---

## Two ways to invoke

### A. CLI (recommended)

```
php c:\wamp64\www\freeitsm-app\cron\webhook_deliveries.php
```

No auth needed — filesystem permissions gate who can run it.

### B. HTTP

```
curl "http://your-host/freeitsm-app/cron/webhook_deliveries.php?token=<TOKEN>"
```

The token is auto-generated on first install (or by Database Verification) and stored in `system_settings` under `webhook_cron_token`:

```sql
SELECT setting_value FROM system_settings WHERE setting_key = 'webhook_cron_token';
```

Rotate it anytime by `UPDATE`-ing that row. Use the HTTP form when you can't run PHP from the shell (some shared hosting, or remote cron services like cron-job.org / EasyCron).

**Security on the HTTP form:** a 128-bit shared-secret token compared with `hash_equals()`, plus the min-interval guard. CLI invocations skip the token (there's no untrusted caller).

---

## Windows — Task Scheduler

Create a task (`taskschd.msc`):

| Field | Value |
|-------|-------|
| Name | `FreeITSM — Webhook Deliveries` |
| Trigger | Daily, recur every **1 day**, repeat every **1 minute** for **1 day** |
| Action | Start a program |
| Program/script | `C:\wamp64\bin\php\php8.2.x\php.exe` |
| Add arguments | `C:\wamp64\www\freeitsm-app\cron\webhook_deliveries.php` |
| Start in | `C:\wamp64\www\freeitsm-app` |

(Adjust the PHP path to your WAMP version.) To capture output, point the action at a `.bat` that redirects to a log file, as in the SLA cron guide.

---

## Linux — cron

```cron
* * * * * /usr/bin/php /var/www/freeitsm-app/cron/webhook_deliveries.php >> /var/log/freeitsm-webhook-cron.log 2>&1
```

Adjust the `php` binary path (`which php`) and the install path. The cron user needs read access to `config.php` and the PHP `pdo_mysql`, `curl` and `mbstring` extensions.

---

## Verifying the signature (for webhook receivers)

If you set a signing **secret** on the action, each request carries `X-FreeITSM-Signature: sha256=<hex>`, where the hex is `HMAC-SHA256(raw_request_body, secret)`. Recompute it over the exact bytes received and compare:

```php
$expected = 'sha256=' . hash_hmac('sha256', file_get_contents('php://input'), $secret);
if (hash_equals($expected, $_SERVER['HTTP_X_FREEITSM_SIGNATURE'] ?? '')) {
    // authentic — process it
}
```

A match proves the call came from your FreeITSM install and the body wasn't altered.
