# FreeITSM backlog, in plain English

Everything outstanding, by module, as at **13 September 2026** (just after 1.8.0).
Private working document, like `TODO.local.md` and `WRITEUP.local.md`.

**Key:** 🔴 somebody is waiting, or it is broken now · 📌 needs Ed's decision · ⬜ nobody is waiting

> ⚠️ **GitHub's open/closed state is not the backlog.** 31 discussions and 7 issues are
> open, and most of the discussions describe things that **shipped months ago** and were
> never closed. Checked against `CHANGELOG.local.md`: 24 of 33 open threads have changelog
> rows behind them. So "open on GitHub" tells you almost nothing — this list is what is
> actually undone.

---

## 🔴 Broken right now

- **After upgrading, Database Verification complains the index list is out of date.** It
  names `asset_physical_disks.idx_asset_physical_disks_model` as present in the app's list
  but missing from the reference schema. **This shipped in 1.8.0 last night**, so anybody
  upgrading today sees it. Reported by tjedelhauser as #121 back on 30 August, against an
  earlier fix that did not fully land. One command fixes it
  (`php scripts/gen_db_verify_indexes.php`, commit both files), which makes it a patch
  candidate rather than a job.

---

## Tickets

- 🔴 **Second-line escalation.** Kraleemil asked for structural 1st line / 2nd line
  escalation (#125). Assigning a ticket to a *team* was built; the **escalation and tier
  half was not**, and that is the half he actually asked about.
- 📌 **Ticket roles — owner, assignees, watchers** (dschipfel, #93). Designed, nothing
  built, and blocked on a single vocabulary decision: what to call the person responsible.
  ⚠️ The ticket already stores the assignee **twice** and 93 of 110 rows disagree, so this
  cannot be migrated blindly.
- ⬜ **Group "All tickets" by status** for department and analyst views (dschipfel, #73).
- ⬜ **Moving notes when a ticket is split** — the split works, moving the notes with it
  was never finished.
- ⬜ **The rota copy/paste is missing from the ticket help page** (§9). The wiki has it;
  the in-app guide does not. Ed asked me to note this.

## People and contacts

- 📌 **There are two people editors** — Tickets → Users and Assets → Users — showing the
  same record. They now show the same seven fields, but they are still two screens.
  **Ed's call whether they become one.**
- ⬜ **Write contact changes back to the address book.** The CardDAV import reads only.
  This is the half mbsouth actually argued for, and the remaining cost is the conflict
  rules, not the protocol.
- ⬜ **Preferred name on the Assets people screen.** It shows on Tickets → Users only.
- ⬜ **Assets → Users hardcodes the list of person fields twice**, so the next field added
  has to be typed in three places.

## Assets and CMDB

- 🔴 **33 of 597 inventory rows read as stale** and Ed has to decide what to do about
  them — whether a machine that has not reported in is hidden, flagged or left alone.
- ⬜ **The asset list has no filter bar.** Every other list has one.
- ⬜ **Asset leasing** — start and end dates, and what happens at the end of a lease.
- ⬜ **No canvas zoom on the network map** — 5 of 6 diagrams cannot be seen whole.
- 📌 **How does an estate actually get into the CMDB?** Still unanswered, and it is the
  question a new user hits first.

## Self-service portal

- ⬜ **The portal on a phone** is part-done; it stops at the login page.
- ⬜ **The rest of the portal may have the same fixed-light-colour problem** the account
  dialogue had, which looked like a sheet of white in dark mode.

## Knowledge

- 🔴 **`knowledge.js` still builds HTML without the shared sanitiser.** Everything else
  goes through one helper; this one does not.
- ⬜ **Folders and per-folder permissions** — designed, not built.

## Tasks

- ⬜ **Recurring tasks** (dschipfel, #94) — partly there; reminders and due dates are not.
- ⬜ **Tasks can be assigned to a team in the database** (`tasks.assigned_team_id` exists
  and is unused) but there is no way to do it on screen.

## System and administration

- 🔴 **The System Wiki scan returns nothing** — no tables, nothing to browse
  (tjedelhauser, #130). Reported 3 September, untouched.
- 🔴 **Branding does not work on PHP-FPM / FastCGI** (mbsouth, #115). `.htaccess`
  `php_value` directives are ignored under FastCGI; PHP documents `.user.ini` as the
  mechanism instead. He runs production that way.
- ⬜ **Set the sign-in method at team level** (chris18890, #41) rather than per analyst, so
  onboarding a new starter is one less step.
- ⬜ **Advanced automation, scripted sequences and PowerShell execution** (Kraleemil,
  #128). Large, and nothing has been done.
- 📌 **Roles beyond settings (RBAC layer 2)** — who may do what *inside* a module, as
  opposed to who may configure it.
- ⬜ **No "remove demo data" action.** You can import it and not get rid of it.
- ⬜ **Database Verification cannot widen an existing column**, only add new ones. Harmless
  until a column that has already shipped needs to change type.

## Security

- 🔴 **Cross-site request forgery protection is missing on roughly 369 endpoints.** Open
  since Erlend's audit in August, and FreeITSM now has government users whose compliance
  people read this sort of thing. The single biggest item on this list.
- ⬜ **`api/assets/get_people.php` still hands out a raw manager id across companies.**
  The *name* is withheld now (fixed in 1.8.0), so this only confirms that some id exists,
  and the screen needs the id to work. Worth a look, not urgent.
- ⬜ **Foreign keys are still unguarded** in Database Verification.
- ⬜ **A strict Content Security Policy breaks parts of the app** (chris18890, #43),
  including Database Verification. Anybody hardening their install hits it.
- ⬜ **The REST API's risk is unscoped resources reading scoped tables** — worth an audit
  before anybody builds on it.

## Email and integrations

- 🔴 **IMAP is broken on Debian 13**, where the PHP extension it needs has been dropped.
- ⬜ **The IMAP mailbox has never been tested on a real device.**
- ⬜ **Calendar sync's inbound half needs a scheduled job** and there is no smoke test for
  it. A dead cron job never announces itself.

## Mobile

- ⬜ **The rollout is at section 36 of an audit that covers every module**; next is 37.
- ⬜ **Tickets → Users has never been through it.**
- ⬜ **A scroll audit is owed across the other 15 modules.**

## Translation

- 🔴 **Polish is 37.6% translated**, under a Polish government agency — the worst locale
  under the user least able to shrug it off.
- ⬜ **New English text from recent releases is untranslated in 23 locales.** Safe, because
  anything missing falls back to English, but it accumulates.

## Documentation and the website

- 🔴 **`releases.html` needs uploading by FTP** — regenerated last night, and the website
  is a release behind until you do. The website is not in git.
- ⬜ **Morning Checks has no wiki page at all.**
- ⬜ **A backup and restore guide.** A prospective user asked for it. Must cover the
  database, the application directory, and the encryption key kept *outside* it.
- ⬜ **689 old rows on the updates page render a literal `&mdash;`** instead of a dash.
- ⬜ **The changelog's Unpublished section holds 1,143 rows that were released in 1.0.0 to
  1.7.0** and never moved across. The release tags give clean id boundaries if you want it
  tidied.

## The Pi and Docker

- ⬜ **Overnight auto-update for the demo Pi** — agreed in full, never built. The demo is
  still pinned to 1.2.0, which is six releases old.
- ⬜ **`update`, `backup` and `rollback` on the Pi console are stubs.**
- ⬜ **No database backup for the demo Pi.**

## Ideas raised but not agreed

- 📌 **An AI help assistant** — a chatbot over the 55 help pages. Measured as feasible:
  407 sections, 90,000 words, and the AI plumbing already exists. Not started.
- 📌 **One global preference for the left panel** instead of per-module. Ed's own idea.
- 📌 **Should contracts be per-company?** Ed asked, then deferred.
- 📌 **Buy me a coffee** — blocked on Ed's Ko-fi handle, and nothing should be guessed.

## Only Ed can answer these

- **A name for the person who reports a portal outage.**
- **The Ko-fi handle.**
- **What the calendar sync scheduled task should be.**
- **How an estate gets into the CMDB.**
- **Whether the two people editors become one.**
- **What to do about the 33 stale inventory rows.**
