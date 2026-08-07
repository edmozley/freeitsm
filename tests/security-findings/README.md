# Security findings — verification

The fixes for the August 2026 security findings are almost all under the floorboards.
There is no screen that shows "the session cookie is HttpOnly now", and no way to
eyeball whether a refresh token is ciphertext in the database. This directory exists
so the answer to "is it actually fixed?" is not "trust the diff".

## Run it

```
php tests/security-findings/run.php
php tests/security-findings/run.php http://localhost/freeitsm-app/
```

Give it a base URL and it also runs the live HTTP checks — including the original F2
exploit chain, which is the one worth watching fail. Without a URL those are reported
as SKIP, never as a silent pass.

It is **read-only**: nothing is written to the database or the web root. The upload
checks work in the system temp folder and tidy up after themselves.

Exit code is 0 when everything passes, 1 otherwise, so it can go in a pipeline.

## What it proves

92 checks across the eight security findings. Two things about how it is built are
worth knowing, because both were learned the hard way:

**Every refusal is paired with a positive control.** It is not enough to show that
`shell.php` is rejected — a guard broken by a typo in a constant rejects everything
and looks like a clean pass. So each "it refused" is followed by "and it still
accepts a real PNG / still previews a real image / still allows a proper JSON POST".
That is the pairing that catches a fix which has quietly become a brick wall.

**It strips comments before reading source.** Every one of these fixes carries a
comment quoting the old vulnerable line, because that is how the next person
understands why the code looks as it does. The first version of this suite grepped
raw source and reported four fixes as missing — it had found the old code inside the
comment explaining that it had been removed.

To satisfy yourself the suite can fail at all, break something and re-run:

```
mv tickets/attachments/web.config tickets/attachments/web.config.bak
php tests/security-findings/run.php      # 1 failed
mv tickets/attachments/web.config.bak tickets/attachments/web.config
```

## The bits a script cannot check for you

Six things you can only really confirm by looking. Ten minutes, in a browser.

**1. The setup page keeps its mouth shut.** Open `/setup/` in a private window, not
signed in. You should see a blue banner saying setup is complete, the usual list of
checks with their ticks and crosses — and no filesystem paths, no database error
text, no default password, and no Database Verify button. Now sign in as an
administrator and reload: full detail, and the Verify button is back.

**2. A refused attachment tells the customer what happened.** In **System →
Security** there is now an *Attachments* card. Leave it on *Keep it, download only*,
then raise a portal ticket (or email the service desk) with something not on the
allow-list — an `.html` file is easiest. Open the ticket: the attachment is there,
and underneath the message is an amber note naming the file and saying it can only be
downloaded. Now switch the setting to *Do not keep it* and do it again: no
attachment, and the note says it was not saved and why. That note is the whole point
of the setting — a file never disappears in silence.

**3. Images still preview.** Open any ticket with a photo attached. It should still
appear in the reading pane exactly as before. (An SVG will now download instead of
opening — that is the fix, not a bug.)

**4. The editor still works.** Open a knowledge article, a change, or the ticket
reply box. The toolbar should be intact and typing should behave. This is the check
for the TinyMCE upgrade; the version jumped four minor releases, so it is worth a
minute of clicking rather than trusting that the file parsed.

**5. The session cookie is locked down.** Sign in, then in your browser's developer
tools open **Application → Cookies**. `PHPSESSID` should show **HttpOnly** ticked and
**SameSite = Lax**. While you are there: note the value, sign out, sign in again, and
confirm it changed. It never used to.

**6. The Security page tells the truth.** **System → Security** should show the
values actually stored, not a hopeful 5 and 2. If a setting is genuinely unset the
box shows 0 — which is what the sign-in code will really do. A screen that overstates
your protection is worse than one that admits it is switched off.

### And the one that needs a bit of setup

**Company isolation (F9).** To see it rather than take the script's word for it you
need a second company and an analyst restricted to it: **System → Companies**, then
**System → Analysts** to create one with access to just that company. Sign in as them
and try to open a ticket, a recording or a customer belonging to the other company by
putting its id in the URL. You should get a not-found every time — and, importantly,
the same not-found you would get for an id that does not exist, so nothing leaks
about whether the record is real.
