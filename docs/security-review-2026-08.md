# Security review, August 2026 — what changed

A point-in-time record of how FreeITSM responded to a private security audit by
**Erlend Volden** (erlend@devinco.com), reported against commit `f7f1e9dd` on
7 August 2026.

Nine security findings and three correctness findings. **Every security finding was
verified against the code and every one of them was real.** All eight in scope are
fixed on the branch below.

---

## The branch

**`security/findings-2026-08`** — https://github.com/edmozley/freeitsm/tree/security/findings-2026-08

Eleven commits, one per finding, so each can be reviewed and accepted on its own.
130 files changed — but 62 of those are the TinyMCE upgrade blob, so the reviewable
surface is **68 files**.

| Commit | Finding | Severity |
| --- | --- | --- |
| `e9ebd1f` | **F2** `setup/` granted a privilege flag to anonymous visitors | Critical |
| `0693aac` | **F3** M365 / Gmail refresh tokens stored in cleartext | Critical |
| `98e397f` | **F5** SVG served inline with a sender-supplied Content-Type | High |
| `5267716` | **F1** attachments written to the web root under the sender's filename | Critical |
| `172868c` | **F9** tenant guards failed open; two confirmed cross-tenant paths | High |
| `a93a9b4` | **F6** bundled TinyMCE 8.3.2, four published XSS CVEs | High |
| `435d450` | **F7** no session regeneration, no CSRF tokens, no cookie flags | High |
| `fdc2727` | **F8** default credentials permanent; lockout disabled by default | High |
| `61b5ec9` | `SECURITY.md` — a private reporting channel | — |
| `fd1c8c5` | A verification suite for all of the above | — |

Out of scope by decision, and tracked separately: **F4** (SLA determinations not
reproducible) and **F11** (no subject-access or erasure capability). Both are
features rather than fixes and deserve their own design work. **F10** (db_verify runs
destructive DDL with no dry-run) is acknowledged and not yet addressed.

---

## Two corrections to the report

Offered in the same spirit the report was written in — neither changes a finding.

**F3.** The report lists `workflow/includes/engine.php:1722`,
`includes/sla_notifications.php:306` and `includes/self_service_email.php:43` as
readers that access `token_data` without going through `decryptMailboxRow()`. They do
read `$mailbox['token_data']` directly, but in every case the row was decrypted
upstream — by `templateGetMailboxForTicket()`, `sla_get_first_active_mailbox()` and
`ssGetSendingMailbox()` respectively. Tracing all eleven readers found none that
needed changing; registering the column fixed the read path entirely.

**F6.** The report notes TinyMCE is initialised at `self-service/new-ticket.php`,
"which is reachable without logging in". It is not — it 302s to the portal login. The
underlying point stands regardless, since `tinymce.min.js` is a static file anyone can
fetch and read the version out of, which is how it would be fingerprinted anyway.

## And one addition

**F1 is four ingest paths, not three.** `saveChannelMediaAttachment()` in
`includes/messaging/ingest.php` — the WhatsApp / web-chat / Slack media path — has
exactly the same shape: sender-supplied filename, no allow-list, written into
`tickets/attachments/`. The report lists that file under F5 for its `content_type`
handling but not under F1. It is fixed alongside the other three.

---

## The findings

### F1 — Attachments could name themselves on disk

`uploadStoreFile()` already did the right thing but takes a `$_FILES` entry, and none
of the four ingest paths have one — they hold decoded bytes. Added
`uploadStoreBytes()` as its sibling in `includes/uploads.php`: same extension
allow-list, same `finfo` cross-check against the content, same random stored name
drawn from our own list. The sender's filename is kept in the database for display
only, and the `content_type` recorded is the one we detected, not the one declared.

It deliberately does **not** throw on a disallowed file the way `uploadStoreFile()`
does. Inbound email is not somebody at a form who can choose a different file, and one
odd attachment must never cost a person their ticket.

**New setting, and the reasoning behind it.** The obvious fix — reject the file —
loses a customer's attachment. The alternative — keep it inert — means storing content
the operator has declared they do not want. Both are defensible, so it is a choice:

> **System → Security → Attachments → "When a file type is not accepted"**
> *Keep it, download only* (default) · *Do not keep it*

Either way the outcome is written onto the ticket, so nothing disappears silently. See
the UI section below.

**Directory protection.** The report is right that `.htaccess` does nothing on nginx
and IIS. `web.config` added to the four PHP-served upload directories, and
`tickets/attachments` and `change-management/attachments` changed from "deny script
extensions" to denying the whole directory — the old rule blocked `.php` but happily
served `.html` and `.svg` from our own origin. Both files are whitelisted in
`.gitignore` so they actually ship.

The honest framing, though: **gate 3 is the fix and the directory rules are the net.**
A file that cannot be *named* `.php` cannot be executed by any server, configured or
not. nginx and IIS installs are now protected by the primary defence rather than by
one that was never there for them.

An attacker-supplied `.htaccess` is now stored as `.bin` like anything else, which
closes the third bullet of the finding — the one marked *likely* rather than confirmed.

**Migration.** A live install still had a `.html` attachment on disk from before the
change. `db_verify` now renames existing attachments carrying an executable or web
extension to `.bin` and updates `file_path`; the displayed filename is a separate
column and is untouched, so it still downloads under its own name.

### F2 — `setup/` granted a privilege flag to anonymous visitors

The flag is gone rather than guarded. `installIsUnprovisioned()` in the new
`includes/setup_state.php` asks the database the question the flag was standing in
for, so there is no longer a token to forge — the unauthenticated bootstrap path
exists only while it is genuinely the only path.

It **fails closed**. A missing `analysts` table is the one error that really means
"fresh install"; a dropped connection, a lock timeout or a permissions error returns
false and an administrator is required. The new `includes/db_errors.php` is what tells
those apart, and F9 uses it too.

The disclosures are gated separately: every check now carries a path-free twin,
swapped in unless the viewer is a fresh install or a signed-in administrator.
Statuses are untouched — you still see *which* check failed, just not the paths,
hosts or accounts involved.

**One suggestion not taken.** `setup/` is not in `.dockerignore`. `README.md:58` tells
Docker users to open `/setup/` to build the schema and create their admin account, and
`docker/entrypoint.sh` only generates the encryption key — nothing else builds the
schema, so removing the folder breaks first-run. The reasoning in the finding looks
like it assumed the page was diagnostics-only; it is also the bootstrap. Since the
folder now grants nothing and shows nothing, shipping it seemed the lesser problem.
**Happy to revisit if you disagree.**

### F3 — Mailbox tokens in cleartext

`token_data` added to `ENCRYPTED_MAILBOX_COLUMNS`, which fixes every reader, plus the
eleven writers wrapped in `encryptValue()`. `db_verify` migrates existing rows in
place, across every column in the list so a future addition migrates itself.

**The structural note was worth more than the finding.** Taking "a wildcard SELECT
filtered by an allow-list of known secrets fails open" seriously and auditing a live
install turned up **five more secrets nobody had registered**, all in plaintext:

```
csat_token_secret       integration_cron_token   sla_cron_token
webhook_cron_token      workflow_cron_token
```

The four cron tokens authenticate endpoints deliberately reachable without a login, so
they are bearer credentials. `isEncryptedSettingKey()` is now a rule first and a list
second — `*_password` / `*_secret` / `*_token` / `*_api_key` is encrypted the moment it
exists, with `SETTING_KEYS_NEVER_ENCRYPT` (currently empty) as a documented escape
hatch. Forgetting now means "encrypted something harmless" rather than "leaked
something that mattered".

**Masking was deliberately not inverted.** Encryption is invisible to the user, so
defaulting it on costs nothing. Masking changes what an administrator can see and copy
— the cron tokens have to stay readable to build a cron URL — so it stays an explicit
per-key decision.

**A trap worth flagging.** There is no central `system_settings` accessor; the cron
scripts read `setting_value` straight from SQL. Auto-encrypting would have broken every
cron job. `decryptValue()` passes plaintext through untouched, which is what makes the
migration safe in both directions.

### F5 — Sender-chosen Content-Type

The served type now comes from the file extension against `ATTACHMENT_SERVE_TYPES` in
`includes/uploads.php` — our string, never theirs — and anything unrecognised is
`application/octet-stream` as a download. A file named `.png` whose bytes are HTML is
served as `image/png` with `nosniff`, which a browser will not execute. SVG is absent
from the map for the same reason it is absent from `UPLOAD_TYPES_IMAGE`. Filenames are
stripped of quotes and CR/LF so they cannot break out of the header.

It lives in `includes/uploads.php` because that file is already the one home for these
rules, and `api/change-management/get_attachment.php` was already doing it correctly —
this is the same idea kept in one place rather than a third copy.

PDF stays inline deliberately: it is what the reading pane previews, and browsers run
PDF script inside the viewer sandbox rather than as page script on our origin. Noted
rather than silently kept.

### F6 — TinyMCE

8.3.2 → **8.8.2**, downloaded from the npm registry and verified against
`dist.shasum` before extracting. Replaced file-for-file over the existing tree: 136
files, 62 of which actually differed. The 130 files 8.8.2 has that we do not are all
unminified twins — we carry only the production `.min.*` set.

**The more useful half of the finding** was that there is no `package.json` or
`composer.json`, so no scanner sees any of it. `VENDOR.md` now lists all eight bundled
libraries with version, path, source and licence, how to check each for updates, and
the procedure for replacing one. Versions not embedded in the file say so and give the
date vendored rather than guessing.

### F7 — Sessions and CSRF

`session_regenerate_id` appeared **zero** times in the tree, as reported.
`sessionPromoteToAuthenticated()` in the new `includes/session_security.php` rotates
the id and re-issues the cookie, called at all ten identity points — the eight sign-in
paths plus both password-change endpoints.

**Why the ini settings ship as configuration rather than code**, since this was the
interesting constraint: **809 of the 818 files that call `session_start()` do so
before they include `config.php`.** There is no PHP file early enough in the request to
set cookie parameters. So they ship three times:

| File | Covers |
| --- | --- |
| `.user.ini` | PHP-FPM / CGI / FastCGI — nginx and IIS, the case the report called out |
| `.htaccess` | Apache with mod_php, which does not read `.user.ini` |
| `docker/php.ini` | the image, which previously shipped no `php.ini` at all |

`session.cookie_secure` is in **none** of them, deliberately: it cannot be conditional
in a static file, and forcing it on an install served over plain HTTP would stop the
cookie being sent at all. It is applied per request where the scheme is known.
`X-Forwarded-Proto` is only believed when `TRUST_PROXY_HTTPS` is defined, since
otherwise a client could flag its own cookie Secure over HTTP and lock itself out.

Because a server might read none of those three files, the authenticated cookie is
also re-issued explicitly at sign-in — but only when the server's own configuration
did not already produce the right attributes, so a correctly configured install emits
one `Set-Cookie` rather than two.

**CSRF is minimum-viable and labelled as such.** `SameSite=Lax` stated explicitly is
the real fix — Chrome already defaults to it, and saying so extends the same
protection to Firefox and Safari. On top of that, `includes/request_guard.php` refuses
any state-changing request declaring `text/plain`, the one Content-Type with no
legitimate use here (our own front end sends `application/json` at all 149 fetch
sites). `urlencoded` and `multipart` are **not** refused, because ordinary forms and
uploads need them.

A token layer across all 369 endpoints that read `php://input` remains outstanding,
and is deliberate follow-up rather than something to bolt on inside a security fix.

### F8 — Default credentials and lockout

`analysts.must_change_password` added, set on the seeded `admin` account, cleared when
a password is changed.

**And enforced, which the redirect alone was not.** Both this and the pre-existing
password-expiry flow only redirected at the end of login — the session was fully
authenticated by then, so typing `/tickets/` walked straight past it. Confirmed before
fixing: a flagged account reached the inbox with a 200. `includes/password_gate.php`
now runs on every request; HTML gets a redirect, API callers get a 403 with
`password_change_required`. Without this the fix would have been cosmetic, which is
the one outcome worth avoiding when the finding is "the default credentials are
permanent".

The five brute-force settings are now seeded. The report was right to flag the UI
hardest: seeding the values the screen was *already claiming* is what makes it honest.
The phantom `|| '5'` and `|| '2'` fallbacks are gone, along with the matching static
HTML defaults; an unset value now shows `0`, which is what the login code will really
do.

`password_expiry_days` stays `0` on purpose. Forced rotation on a timer is not good
practice, and unlike the others, switching it on at upgrade would lock people out of
their own service desk.

Trade-off stated rather than hidden: an attacker who knows a username can now lock
that account for the configured window. That beats unlimited guessing, and the IP ban
covers a sweep across many usernames.

Both OTP verifiers now count failures in the session and abandon the challenge after
five, sending the user back to the password step — which *is* rate-limited. The
session is the right home for the counter because the challenge is session-bound:
discarding the session to reset the count means presenting the password again.

### F9 — Tenant guards

`tenancyDegradeAllowed()` forgives only the two errors that genuinely mean the schema
has not caught up. Anything else — lock wait, dropped connection, permissions,
deadlock, or a non-database exception — denies and is logged. Applied at all six sites
plus `analystCanAccessArticle()`, which also returned `true` when the row was not
found and so could not deny anything for an id that did not resolve.

Both confirmed leaks are closed. `get_recording.php` now gates analysts on the Tickets
module **and** `analystCanAccessTicket()`; a pending recording with no ticket belongs
to the portal user who made it and to nobody else. `save_user.php` gained
`analystCanAccessUser()` — guarding the destination company without guarding the
subject guarded nothing.

The two stale comments are corrected: `tenancy.php` no longer claims "nothing wires
these into queries yet" and `tenancy-switcher.php` no longer claims it is not wired
into the header.

---

## UI changes

Five things a person can see. Everything else is under the floorboards.

### 1. System → Security — a new *Attachments* card

The setting from F1. Two options, defaulting to keeping the file:

- **Keep it, download only** — stored under a name of ours with an inert `.bin`
  extension, and it can only ever come back out as an `octet-stream` download.
- **Do not keep it** — not written at all.

Under it: *"Either way the ticket says what happened and why, so nobody has to wonder
where their file went."*

### 2. The note on the ticket

When an attachment is not accepted, an amber block is appended to the message naming
the file and the reason — *"kept, but php files are not accepted, so it can only be
downloaded, never opened in the browser"*, or *"not saved, because php files are not
accepted"*. This is the part that makes the setting honest: a customer who thinks they
attached something is told what happened to it.

### 3. System → Security — values that are no longer aspirational

The IP-ban boxes previously pre-filled `5` and `2` from a hardcoded fallback while the
login code read the missing settings as *off*. They now show what is actually stored,
and `0` where nothing is.

### 4. `/setup/` seen by someone who is not signed in

A blue banner — *"Setup is complete on this install, so paths, connection errors and
credentials are hidden. Sign in as an administrator to see full detail."* — and the
same checks with the same ticks and crosses, minus every path, error, credential and
the Database Verify button. Sign in as an administrator and it all returns.

### 5. Forced password change on first sign-in

`admin` / `freeitsm` now lands on the change-password interstitial, and cannot leave
it by typing a different URL.

All new strings are in `en` and `pt-BR`.

---

## Verifying it

Almost none of this has a button, so there is a script:

```bash
php tests/security-findings/run.php                                # code + database checks
php tests/security-findings/run.php https://your-install.example/  # adds the live checks
```

**92 checks, currently all passing.** Read-only — nothing written to the database or
the web root, the upload checks work in the temp folder and clean up after
themselves. Exit code 1 on failure. Without a base URL the live checks report `SKIP`,
never a silent pass.

It tests behaviour wherever behaviour is reachable, not just code shape: it feeds
`shell.php` to the upload handler and inspects what lands on disk, asks the attachment
server what it would send for an SVG, hands `tenancyDegradeAllowed()` a simulated
lock-wait timeout, reads the real cookie attributes off a live response — and runs the
**original F2 exploit chain** against a running install to watch it return 401.

Two things it does on purpose:

**Every refusal is paired with a positive control.** Showing `shell.php` is rejected
proves nothing on its own: a guard broken by a typo in a constant rejects *everything*
and reads as a clean pass. So each one is followed by "and a real PNG is still
accepted", "a real image still previews inline", "the same POST as `application/json`
is still allowed".

**It strips comments before matching source.** The first run reported four fixes as
missing — it had found `$_SESSION['setup_access'] = true`,
`s.max_ip_attempts || '5'` and `session.cookie_secure` inside the comments explaining
that those things had been removed.

The suite is itself checked against a deliberate regression:

```
mv tickets/attachments/web.config{,.bak}   → 83 passed, 1 failed
restored                                    → 84 passed, 0 failed
```

`tests/security-findings/README.md` covers the six things a script cannot judge — the
setup page staying quiet, the note on a refused attachment, images still previewing,
the editor still working, the cookie flags in devtools, and the Security page showing
real values — plus how to set up the second company needed to see the isolation fix
first-hand.

---

## What was proven live, not just linted

Every fix was exercised against a running install with a real database, rather than
reasoned about:

| | |
| --- | --- |
| F1 | `shell.php` through the real portal → stored as `…​.bin`, note on the ticket; policy switched to *drop* → nothing written, note says so |
| F2 | `GET /setup/` then `POST db_verify` on one cookie → **401**; admin session → 200 |
| F3 | Microsoft Graph **and** Gmail both collected mail on encrypted tokens; cron auth: wrong token 403, right token ran |
| F5 | a planted SVG carrying `<script>alert(document.domain)</script>` → `application/octet-stream`, `attachment` |
| F6 | editor initialised in headless Chrome → `version=8.8.2 ui=rendered plugins=14`, with a negative control |
| F7 | portal and analyst logins both rotated the id; forged id refused and replaced; `text/plain` POST → 415, JSON → 200 |
| F8 | flagged account blocked from every module page and the API; released on compliance; five wrong codes abandoned the MFA challenge, the real code still worked |
| F9 | analyst scoped to one company → 404 on another company's recording, "User not found" on their customer; positive controls passed with access granted |

Testing caught two things code review had not: the CA-bundle path still leaking
through `setup.detail.ssl_verified` after F2 looked finished, and the F8 gate first
redirecting to `auth/force_password_change.php`, which `auth/.htaccess` deliberately
404s — stranding the user with no way to comply and no way back.

---

## Outstanding

**In the application**

- CSRF tokens across the 369 endpoints that read `php://input`. F7 shipped the
  minimum viable defence; this is the real one.
- **F10** — `db_verify` still has no dry-run, preview or backup prompt, and the header
  comment claiming it "never drops anything" is still wrong.
- **F4** and **F11** — SLA snapshotting and subject access / erasure. Both features.
- `lms/content` and `system/uploads/branding` did not get the deny-all treatment;
  both may legitimately be fetched by the browser and breaking SCORM playback or
  branding to close a lesser risk was not a trade worth making blind.

**Process, from the report's own suggestions**

- Private vulnerability reporting is being enabled — `SECURITY.md` already points at
  it, and it is the channel we would rather this had arrived on.
- Release tagging, so an advisory can say "fixed in 1.4.2" rather than quoting a SHA.

---

*Thank you. The structural observations — the allow-list that fails open, the untyped
catch, the missing manifest — were consistently more valuable than the individual bugs
they came attached to, and the fixes are shaped around them rather than around the
symptoms.*
