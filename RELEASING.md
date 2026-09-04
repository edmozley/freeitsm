# Releasing FreeITSM

How FreeITSM is versioned and released. Written after
[discussion #92](https://github.com/edmozley/freeitsm/discussions/92), where Thomas
(tjedelhauser) asked for tagged releases so operators can say "I am on 1.4" instead of
"I am on whatever `main` looked like on some Tuesday", pin a version, and read what
changed between two upgrades.

Before this, `main` was the only release. That is why
[#129](https://github.com/edmozley/freeitsm/issues/129) — a fatal error on every page
after an upgrade — was so hard for the people hit by it to reason about: there was no
"before" to name and nothing to roll back to.

---

## 1. The numbering scheme

`MAJOR.MINOR.PATCH` — [semantic versioning](https://semver.org), read in FreeITSM's own
terms. The question each number answers is **"what does this upgrade ask of the
operator?"**, not "how much work was it?".

### PATCH — `1.4.0` to `1.4.1`

Nothing new. Fixes and refinements to what is already there.

- Every changelog row is a **Fix** or an **Improvement**.
- Database Verification may add nothing, or add columns and tables. It **must not drop** anything.
- No change to `config.php`, `db_config.php`, environment variables, the PHP floor, or the REST API.
- An operator can upgrade without reading anything.

### MINOR — `1.4.1` to `1.5.0`

New capability, backward compatible. This is the normal release.

- At least one changelog row is a **Feature** — or an **Improvement** substantial enough
  that you would lead the release notes with it (the mobile rollout of a whole module, say).
- Database Verification may create tables and add columns. That is expected and fine.
- Existing installs keep working with their existing configuration and existing data.

### MAJOR — `1.5.0` to `2.0.0`

**The upgrade asks something of the operator, or can break a working install if they do
nothing.** Reserve it for that, and nothing else. Concretely, for this codebase:

- The **PHP floor rises** above the current 7.4.
- `config.php`, `db_config.php`, the Docker environment variables, or the encryption key
  path change shape in a way an existing file does not satisfy.
- Database Verification **drops** a column or table that the previous release still reads.
- A **breaking change to the REST API v1** (`/api/v1/`) — a removed field, a renamed
  resource, a changed response shape. Additive changes are MINOR.
- An upload, attachment, or key directory **moves**, so a file that was reachable is not.
- A module is **removed**.

Being big is not enough. A whole new module is a MINOR — it asks nothing of anyone who
does not want it. Renaming one environment variable is a MAJOR.

### The mechanical rule

`CHANGELOG.local.md` already types every entry. Take the rows added **since the previous
tag** — `git log vX.Y.Z..HEAD -- CHANGELOG.local.md` — and read their types:

| What is in those rows | Release |
|---|---|
| Anything in the MAJOR list above | MAJOR |
| At least one **Feature** | MINOR |
| Only **Fix** and **Improvement** | PATCH |

Decide MAJOR first, because it is the only one that is a judgement call.

⚠️ **Use git for that range, not the file's own Unpublished/Published headings.** Those
headings are maintained by hand and have drifted badly before — in September 2026 the
Unpublished section held 1,010 rows while 1,149 of them were already live on the website.
Git has never been wrong about what shipped when.

### Starting number

**1.0.0** is the first tag. FreeITSM has been in production use at multiple sites for
months, across sixteen-plus modules — starting at `0.x` would say "not finished yet",
which is not true and is not the message. 1.0.0 is a statement about the stability of the
contract, not about being feature-complete.

---

## 2. When to cut a release

### Scheduled releases: tie them to the website update

Do not invent a second cadence. Changes accumulate as changelog rows, and when there are
enough of them they get published to the website.

**Make that one moment do all three jobs:** tag the release, publish the GitHub release
notes, and update the website — off the same `releases/X.Y.Z.md`, so they can never
disagree.

Roughly a fortnight, or when ten-ish rows have accumulated since the last tag, whichever
comes first. It is a rhythm, not a promise; nobody is waiting on a calendar.

**Small releases are the point, not a compromise.** The release page is written for someone
scanning it in a minute. A release carrying 300 changes cannot be read that way no matter
how well it is organised, so a long gap does not just delay the notes — it destroys them.

### Claude raises it, Ed decides

Ed should not have to remember this. **At the end of every working session, Claude checks
whether a release is due and says so** — naming the number and the reasoning in one line.
Any one of these is enough to raise it:

- a **critical fix** landed (section 3) — say so immediately, same day;
- **ten or more** changelog rows since the last tag;
- something **whole** shipped — a module, a requested feature, a mobile rollout — even if
  it is only three rows;
- **two weeks** have passed and there is anything at all.

### Out-of-cycle releases: do not make people wait

Ship a PATCH the same day, on its own, for:

- An install that is **broken by an upgrade** (the #129 class — a fatal on page load).
- **Data loss or corruption**, or anything destructive.
- A **security fix** (see `SECURITY.md` — operators need the fix before it is public).
- Anything where the honest answer to "should I upgrade now?" is "no, wait". That state
  should last hours, not a fortnight.

The regular cadence is for planned work. It is not a queue that a critical fix has to join.
A patch release is one tag and one page of notes; it costs almost nothing.

---

## 3. Old releases are not backported

**Only the latest release is supported.** If a serious bug is found, it is fixed in the
next release and nothing is backfilled to 1.1, 1.2 or 1.3.

Backporting means maintaining several branches at once, and is worth it when there are
support contracts to honour. There are not. "Please upgrade to the latest version" is a
complete and normal answer for a self-hosted open-source project.

What to do instead, and it matters: when a release fixes something that bites people on
older versions, **say so at the top of the release notes** so nobody has to work it out:

> **Fixes a critical issue present in 1.2.0 and 1.3.0** — every page returned HTTP 500
> after upgrading (#129). If you are on either version, upgrade.

That is the whole of the backport policy: name the affected versions, plainly, in the notes.

---

## 4. Rollback: what can honestly be promised

FreeITSM has **no down-migrations**. Schema changes are applied by
**System → Database Verification** (`api/system/db_verify.php`), which is idempotent and
**forward-only**. There is no path back.

So:

- Rolling back **code** is easy — check out the older tag, or point a Docker image tag at it.
- Rolling back the **database** is not possible without a backup.
- A rollback across a release whose Database Verification **added** columns is safe in
  practice: the old code ignores the extra columns.
- A rollback across a release whose Database Verification **dropped** a column is **not
  safe** — the older code will query a column that no longer exists. This is exactly the
  case that makes a release MAJOR.

**Every release note must therefore carry one of two lines:**

> Rollback: safe — this release only adds to the database.

> ⚠️ Rollback: **not** safe without a database backup — this release removes
> `<table>.<column>`, which earlier versions still read.

Never state a rollback promise without checking `includes/db_verify_schema.php` and
`api/system/db_verify.php` for `DROP` in that release's diff.

---

## 5. Cutting a release: the procedure

Claude does the lot; Ed approves the notes before anything is published, because a release
goes out under his name.

**1. Decide the number** using section 1. State the reasoning in one sentence.

**2. Bump the version constant** so the running application knows what it is
(`includes/version.php` — see section 7; until that exists, skip this step and say so).

**3. Write the release notes** to `releases/X.Y.Z.md`, in the shape set out in section 6.
The input is every change **since the previous tag** — `git log vX.Y.Z..HEAD` — matched to
its `CHANGELOG.local.md` row. Do not read the changelog's Unpublished/Published headings to
decide what is in the release; they have drifted before and git has not. **Show Ed the
notes and get a yes before publishing.**

**4. Commit** the version bump, then tag that exact commit:

```bash
git tag -a v1.4.0 -m "FreeITSM 1.4.0"
git push origin main
git push origin v1.4.0
```

Tags are `v`-prefixed (`v1.4.0`); the release title and the in-app version are not
(`1.4.0`). Annotated tags (`-a`), never lightweight — they carry a date and an author.

**5. Never move or delete a published tag.** People will have pinned it. If a tag is wrong,
cut the next patch.

**6. Publish the release.** `gh` is authenticated as `edmozley`, so this is one command
once Ed has approved the notes:

```bash
gh release create v1.4.0 --title "1.4.0" --notes-file releases/1.4.0.md
```

GitHub keeps that snapshot downloadable forever.

**7. Update the website** in the same sitting, so the tag, the GitHub notes and
freeitsm.co.uk cannot disagree — per section 2. The release page is built from the same
`releases/X.Y.Z.md`; `updates.php` keeps its own detailed feed separately.

**8. Move the published rows** in `CHANGELOG.local.md` from **Unpublished** to
**Published** under a heading naming the release.

---

## 6. Two documents, two audiences

`CHANGELOG.local.md` and the release notes are **separate documents that are never the
same text**. This is the part most easily got wrong, because the changelog is right there
and copying it feels like a shortcut.

| | `CHANGELOG.local.md` | `releases/X.Y.Z.md` |
|---|---|---|
| Audience | Ed and Claude | Somebody who runs FreeITSM for their team |
| Contains | **Every** change, one row each | Only what a user would notice |
| Voice | Technical, exhaustive, explains the reasoning | Plain, short, explains the benefit |
| Published | **Never** — it stays in the repo | GitHub Release + the website |
| Written | As each change ships | Once, at release time |

The changelog remains the complete technical record and the input to everything below.
It is not published and its prose is not reused.

### Writing the notes

**The essential move is merging, not copying.** Twenty changelog rows about making
Contracts work on a phone become **one** line: *"The whole Contracts module now works on a
phone."* A release of 100 rows should produce something like 15 bullets. If the notes are
as long as the changelog section, they have not been written yet.

Rules that follow from the audience:

- **Name things as they appear on screen.** "Preferences → General", not `user_prefs.php`.
- **State a fix as the symptom, not the cause.** "Folder counts disagreed with the ticket
  list" — the reader never saw the query.
- **Drop anything invisible.** Refactors, test coverage, tooling, internal renames. They
  belong in the changelog and nowhere else.
- **No commit hashes, file paths, CSS, or module internals.**
- **Say what someone can now do**, not what was changed.
- **Write as one person, never "we".** FreeITSM is maintained by one person, and
  "we are proud to release" invents a company that does not exist. Say "I". Where the
  first person sits awkwardly, name the product instead: "how FreeITSM got here".
- **Plain hyphens, never em dashes.** Same rule as replies to bug reporters.
- **Never claim something that is not built.** Check the module list, check the feature
  actually ships. A first draft will confidently assert a release-management module and a
  mobile rollout that covers everything; neither was true.

### What every set of notes contains

Written for an operator deciding whether to upgrade tonight, in this order — see
`releases/TEMPLATE.md` for the skeleton:

1. **Front matter** — `version`, `date`, `headline`, `security`. The website reads this.
2. **What this release is about** — three or four sentences. The one part written from
   scratch every time; everything else is distilled from the changelog.
3. **A critical-fix banner**, if any, naming the affected versions (section 3).
4. **Security**, when there is any — its own section, above the features, because it is
   the one category that asks the reader to act rather than just read. Delete the heading
   when empty rather than writing "none".
5. **New features, Improvements, Fixes** — in that order.
6. **Upgrade steps**, every time, no exceptions:
   1. Pull or rebuild.
   2. Sign in as an administrator and run **System → Database Verification**.
   3. Hard-refresh the browser (Ctrl-F5) — this release updates cached JavaScript.

   Step 2 is not optional and is not obvious. Step 3 matters because so many releases bump
   a cache-buster, and a stale `inbox.js` looks like a broken feature.
7. **The rollback line** (section 4).
8. **Anything an operator must do by hand** — a new environment variable, a permission to
   set, a scheduled task to create. If this section is not empty, question whether the
   release is really a MINOR.

### Where the notes live

One file per release: **`releases/X.Y.Z.md`**, committed before the tag. That single file
is the source for the GitHub Release body and for the website's release page, so the two
cannot drift apart. Never edit a published release's file to change history — if it is
wrong, fix it in the next release's notes.

---

## 7. Not built yet

Three things discussion #92 asks for that do not exist. Do not describe them as done.

- **A version number in the application.** Thomas asked for this first — he wants to open
  Settings and read which version he is on. It needs one source of truth
  (`includes/version.php`, a single `FREEITSM_VERSION` constant), shown on the System
  screen and included in Debug Tools output, and bumped as step 2 above. It must live in a
  file the application *ships*, never in `config.php` — that file belongs to the operator
  and Docker copies over it (#129).
- **Docker image tags.** This is the part Thomas cares about most. `docker-compose.yml`
  currently says `build: .`, so there is no way to pin a version and no way to roll back
  without digging through git history. A GitHub Actions workflow that builds and pushes
  `edmozley/freeitsm:1.4.0` and `:latest` on every `v*` tag would let operators write
  `image: edmozley/freeitsm:1.4.0` and roll back by editing one line. There are currently
  no workflows in `.github/` at all.
- **A `CHANGELOG.md` in the repository.** `CHANGELOG.local.md` is not published. Release
  notes on GitHub may be enough — decide before the first release rather than after.
