# Directory Sync — Setup Guide

FreeITSM can import people from three kinds of source: an **LDAP / Active Directory** directory, a **CardDAV** address book, and **Microsoft Entra ID** (through Microsoft Graph, including Entra groups and their members). Each one has an **Import now** button under *System → Authentication*, and an Entra source also has **Import automatically** — how often it is imported without anybody pressing the button.

A scheduled task is what makes that reliable, and this document describes how to set one up on Windows and Linux. Without one, FreeITSM can still start an import itself — see *Without a scheduled task* below — but only while somebody has it open.

Setting up each source — the connection, which people and groups to bring in, and the safety settings — is covered in the in-app help: *System → Help → Authentication*, sections *LDAP / Active Directory*, *CardDAV address books* and *Importing from Entra ID*.

---

## What runs

A single PHP script: `cron/directory_sync.php`

```
php cron/directory_sync.php --all
```

> **Scheduled `scripts/directory_sync.php` before?** That path still works and always will — it is what release 1.8.0 documented, so it stays as a pointer to `cron/directory_sync.php`, with the same options, output and exit codes. There is no need to change an existing task, though new ones should use `cron/`.

`--all` picks up every source that is **enabled** and has **importing switched on** — LDAP directories, CardDAV address books, and OpenID Connect providers whose issuer is Microsoft Entra ID — and sends each one through the right engine. A source added later is included automatically; the scheduled task never needs changing.

Each source keeps its own pace through **Import automatically**:

| Setting | What `--all` does with that source |
|---|---|
| *Only when the scheduled task runs* (the default, and what every source did before the setting existed) | Imports it on **every** run of the task. |
| *Every hour*, *Every 4 hours*, *Every 12 hours*, *Once a day* | Imports it only when that long has passed since its last import, and prints `Not due: … imports every N minute(s).` otherwise. |

So the task can run every 15 minutes and a source set to *Once a day* is still imported once a day. Pressing **Import now** also restarts that source's clock.

**One import of a source at a time.** Whatever starts it — the scheduled task, FreeITSM itself, or Import now — a source already being imported is skipped (`Skipped: an import of … is already running.`), and Import now says so instead of starting a second one.

For each source the output reads:

```
======================================================================
SYNC — Contoso Entra ID [oidc]
======================================================================
  status       : ok
  found        : 412
  created      : 3
  updated      : 17
  adopted      : 0
  deactivated  : 1
  conflicts    : 0
  errors       : 0

  People read: 412. Created: 3. Updated: 17. Adopted: 0. Marked as left:
  1. Groups: 24 (new: 1, no longer imported: 0). Memberships added: 9,
  removed: 2.
```

Other options:

| Option | What it does |
|---|---|
| `--provider=<id>` | Import one source only. The id is the number in the address bar when the source is open (`entra.php?id=3`). |
| `--preview` | Run the real import with writing switched off and report what *would* change. Safe at any time. |
| `--quiet` | Print nothing. Use it in a scheduled task that does not keep a log. |

The script is **command line only**. Unlike the SLA or webhook jobs there is no token web address, because an import can mark people as having left and is not something to trigger from a URL.

### Exit codes

| Code | Meaning | What to do |
|---|---|---|
| `0` | Every source imported. | Nothing. |
| `1` | At least one source **failed** — could not connect, wrong credentials, an expired Entra client secret, missing Graph permissions. | Read the message on that source's *History* tab, fix it, run again. |
| `2` | At least one source was **stopped** by a safety check and **nothing was changed** for it. | Not a fault to retry blindly: it is waiting for a person. See *When a run stops* below. |

One source failing does not stop the others; the exit code reports the worst result.

---

## How often

**Once a day is the right starting point**, early in the morning before the service desk opens. That is also what *Reporting → System logs → Background jobs* expects by default: the job is called overdue if no scheduled run has finished for 36 hours (a day, plus 12 hours' grace). You can change the expected interval there if you schedule it more often.

More often is fine — every few hours if new starters must appear the same day — but be aware of one interaction:

> ⚠️ **"Missing for N imports" counts runs, not days.** Each source has a setting *Mark somebody as having left after they have been missing from the directory this many imports running* (3 by default). Run hourly and somebody absent for three hours is marked as left; run daily and it takes three days. If you schedule more often, raise that number to match.

With every source on *Only when the scheduled task runs*, how often the task runs is how often they import. Once sources have their own interval, run the task every 15 minutes and let each source keep its own pace — two runs of the same source can never overlap, so a short task interval is safe.

---

## Without a scheduled task

If no scheduled task runs the import, FreeITSM starts it itself. Every open FreeITSM tab checks for new notifications once a minute, and that check also looks for a source whose *Import automatically* interval has passed. When it finds one, it starts the import **as a separate background process** — the same way the Intune sync runs — so nobody's page waits for it.

It is a fallback, not a schedule, and it has three limits:

- **It needs somebody to have FreeITSM open.** If nobody opens it over a weekend, nothing imports that weekend; the first person in on Monday starts the overdue import.
- **It needs PHP to be allowed to start a process** (`popen`). Most servers allow it — the Intune sync needs the same — but some shared hosts block it. When it is blocked, the attempt is logged to the PHP error log and tried again after one interval, not every minute.
- **It uses the same PHP as the Intune worker.** If FreeITSM cannot find a command-line PHP on its own, set one under *Assets → Settings → Intune*.

Sources set to *Only when the scheduled task runs* are never started this way.

Runs started like this are recorded with the trigger **page**. *Reporting → System logs → Background jobs* does not count them as the schedule working, so an install that relies on them is still told it has no scheduled task — which is true, and worth fixing.

---

## Windows (Task Scheduler)

Run as the account the web server runs as, or as `SYSTEM` on a WAMP / Laragon box:

```
schtasks /create /tn "FreeITSM Directory Sync" /tr "\"C:\wamp64\bin\php\php8.4.0\php.exe\" \"C:\wamp64\www\freeitsm-app\scripts\directory_sync.php\" --all --quiet" /sc daily /st 06:00 /ru SYSTEM
```

Adjust the two paths to your PHP and FreeITSM folders. Use the **same PHP** the web server uses — on WAMP there is usually more than one under `bin\php\`, and the command-line one may be missing the `ldap` or `curl` extension.

## Linux (cron)

In the web server user's crontab (`sudo crontab -u www-data -e`):

```
0 6 * * * /usr/bin/php /var/www/freeitsm/cron/directory_sync.php --all >> /var/log/freeitsm-directory-sync.log 2>&1
```

Run it **as the web server user** (`www-data`, `apache` or `nginx`), not as root. The script needs to read `config.php`, the `db_config.php` it points to, and the encryption key that unlocks the stored passwords and client secrets. Those are owned by the web server user, and a run as anybody else either cannot read them or leaves behind files the web server then cannot read.

Every 15 minutes, once your sources have their own *Import automatically* interval — each is still imported only at its own pace:

```
*/15 * * * * /usr/bin/php /var/www/freeitsm/cron/directory_sync.php --all --quiet
```

## Docker

Run it in the container from the host's cron:

```
0 6 * * * docker compose -f /path/to/docker-compose.yml exec -T app php cron/directory_sync.php --all --quiet
```

---

## Before you schedule it

1. Set up each source and run **Preview** from its page at least once. Check the numbers look right — especially *marked as left*.
2. Run it once by hand from the command line, **as the same user the schedule will use**:
   ```
   sudo -u www-data php /var/www/freeitsm/cron/directory_sync.php --all --preview
   ```
   This catches the two problems that only appear outside the browser: a PHP without the `ldap` / `curl` extensions, and a user that cannot read the configuration or the encryption key.
3. For Microsoft Entra ID: note when the **client secret expires** (Entra shows it next to the secret, at most two years ahead). On that day every scheduled import of that source starts failing with exit code `1` and `AADSTS7000222`.

---

## Checking it is working

1. **The source's History tab.** Every run is listed, scheduled or by hand, with what it did. Click one to see each person and what happened to them. A scheduled run shows no analyst in the *By* column.
2. **Reporting → System logs → Background jobs.** *Directory and address book sync* shows when it last ran, per source, and turns overdue or failing when the schedule stops or a source breaks. It is also exposed through the REST health address for an external monitor.
3. **Feature Bingo** lights *Scheduled directory import* once a live run has finished without an analyst starting it.

`No matching provider with sync enabled.` means the script ran, but no source is both enabled and has importing switched on. That is not an error for the scheduler; check the *Import people* tick box on each source.

---

## When a run stops

A run reported as **stopped** (exit code `2`) changed **nothing** for that source. It is the safety check working:

- the import found far fewer people than last time (the percentage set on the source, 20% by default) — usually a wrong scope, a renamed OU or group, or, with Entra, the app having lost its Graph permissions; or
- the scope matched nobody — an emptied group, or a group that no longer exists.

Both look exactly like everybody leaving at once, which is why the run refuses rather than marking hundreds of people as left. Open the source, read the message on its History tab, **Preview**, and once the numbers are right, **Import now**. The next scheduled run then compares against the new count.

See also: `docs/sla-cron-setup.md`, `docs/webhook-cron-setup.md` and `docs/integration-poll-cron-setup.md` for the other scheduled jobs.
