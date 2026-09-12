# Write-up checklist

What "done" means for a change, beyond the code compiling. Private working
document, like `CHANGELOG.local.md` and `TODO.local.md`.

Ed asked for this after a session where several of these were nearly missed and
two were missed entirely — the scheduled task that was never documented, and a
settings screen nobody could find by searching for it.

> **Not every row applies to every change.** A one-line fix needs the changelog
> and nothing else. The point of the list is that the decision to skip a row is
> made deliberately rather than by forgetting it exists.

---

## 1. The code is not finished until it has a front door

🔴 **The most-repeated mistake in this codebase**, three times in one day:

| What happened | What was missing |
|---|---|
| `knowledge_user_groups` fully built | no screen at all |
| the seven person fields on `users` | on Assets → Users only, never Tickets → Users |
| the CardDAV import engine | no Run button |

Before writing anything up, ask: **can somebody reach this without a PHP
console?** And: is it reachable from *every* place it belongs, not just the one
it was built for?

## 2. Findability

- [ ] **Search keywords** — `lang/en/system.php` → `*_keywords` for the System
      landing card, or the module's equivalent. ⚠️ Missed for CardDAV: nobody
      searching System for *"address book"* or *"carddav"* found the screen,
      because the keywords still only listed LDAP and OIDC synonyms. Include the
      words users will type, including product names (*baikal*, *sabredav*,
      *thunderbird*) and the file extension (*vcf*).
- [ ] **The card description** — it is part of the search haystack, so a feature
      absent from the description is half-hidden even with keywords.
- [ ] **Wiki `_Sidebar.md`** — a page nobody links to is a page nobody reads.

## 3. In-app help

There are **two** help systems, and a System-module change needs the second:

- [ ] **A module** — its own `<module>/help.php` (21 of them: `tickets`, `lms`,
      `self-service`, …). One page per module, sections inline.
- [ ] **System** — `system/help/<slug>.php`, **plus an entry in
      `system/help/_registry.php`**. 🔑 The registry owns the hero, the
      standfirst, the section list *and* a `terms` synonym string. The sections
      drive the sidebar nav **and** the deep-linkable search index on
      `system/help/index.php`, so a section added to the page but not the
      registry is unreachable and unsearchable.
- [ ] ⚠️ **`terms` in the registry is a SECOND search haystack**, separate from
      the landing card's `*_keywords` in §2. Both need the new words.
- [ ] The slug must match the area's `url` in `system/includes/areas.php` —
      `helpCards()` joins the two, and an area with no registry entry silently
      gets no card at all.
- [ ] Field-level hints on the screen itself — the `hint` under each label
- [ ] ⚠️ **Check every claim in a hint against the code.** Three wrong ones
      shipped in one day: *"approvals route along the manager chain"* (they go to
      a designated analyst), *"office feeds the ticket asset picker"* (it reads
      `asset_locations`), and *"shown on the login button"* for a source with no
      login button.

## 4. Wiki

- [ ] **User page** — for somebody running FreeITSM for their team. What it does,
      why they would want it, what it will not do.
- [ ] **Developer guide** — 🔑 **detail, with code snippets.** The files table
      with the colour key, the traps, the exact function names, the reasoning
      behind each decision. Somebody reopening this in a year needs to know why,
      not just what.
- [ ] **Split the dev page** when there is a lot to cover — see
      `Directory-Sync-Developer-Guide` / `Extending-Directory-Sync` /
      `Typed-Fields-Engine-Developer-Guide`. One overlong page gets skimmed.
- [ ] **Update pages the change makes WRONG**, not only the new ones. A blue-sky
      page that says "parked" after you built it is worse than no page.
- [ ] **`Scheduled-Tasks.md`** if the change adds a job somebody must schedule.
      ⚠️ Missed for LDAP sync since it shipped — operators had no way to learn
      from the documentation that it could be automated at all.
- [ ] **Verify every `[link](Target)` resolves** — `ls Target.md`. A wiki link to
      a page that does not exist renders as an invitation to create it.

## 5. A fixed bug report is three artefacts, not one

- [ ] the fix
- [ ] a row in `Bugs-Resolved.md`
- [ ] an `Issue-NN-…` wiki page
- [ ] a drafted reply for Ed to post — **plain hyphens, no em dashes**

## 6. Schema

- [ ] `database/freeitsm.sql`
- [ ] `includes/db_verify_schema.php` — ⚠️ **not** `api/system/db_verify.php`,
      which now `require`s it. Foreign keys and repair passes still live there.
- [ ] `includes/db_verify_indexes.php` is **generated** — `scripts/gen_db_verify_indexes.php`, never by hand
- [ ] Run **System → Database Verification** and confirm it reports the change
- [ ] ⚠️ **db_verify adds columns but does not WIDEN an existing one.** Changing
      a type needs a probe-then-`MODIFY` pass (see `users.email`). Harmless only
      while the column has never shipped.

## 7. Cache-busters

- [ ] `inbox.css?v=` · `inbox.js?v=` · `mobile.css?v=` · `self-service.css?v=` ·
      `theme.css?v=` — whichever you touched
- [ ] 🔴 **GREP for the current number. Never trust one written down anywhere**,
      including in these notes — they rot, and a stale bump ships a fix nobody
      receives.

## 8. i18n

- [ ] English strings in `lang/en/<namespace>.php`
- [ ] No hardcoded user-visible text left in the PHP or JS
- [ ] ⚠️ **`t()` has no pluralisation.** `{n} contacts` renders *"1 contacts"*.
      Put the count after a label — *"Contacts read: 1"* — or in brackets.
- [ ] Safe to ship untranslated: `t()` falls back per key and `exportForJs()`
      deep-merges English. **Verify with a real `Accept-Language` request**, not
      by reading the fallback code.

## 9. Release

- [ ] `CHANGELOG.local.md` — every change, next sequential id, one row per thing
- [ ] **`releases/X.Y.Z.md`** — 🔑 **merge, do not copy.** Twenty changelog rows
      about one module become one line. Written for somebody deciding whether to
      upgrade tonight, not for a developer.
- [ ] ⚠️ **Re-read the release notes after the release grows.** 1.8.0's notes
      described contact details and the rota for a long while after CardDAV had
      been built, and mentioned it zero times.
- [ ] ⚠️ **Check every factual claim in the notes.** Drafts invent things. Three
      were wrong in one session: a date, a cross-reference id, and the affected
      version range.
- [ ] `README.md` if it is a feature
- [ ] `includes/version.php` bumped **in the tagged commit**
- [ ] Tag `vX.Y.Z` annotated, on that commit, never moved afterwards
- [ ] GitHub release — strip the front matter first
- [ ] `php scripts/gen_release_notes_page.php`, then **Ed FTPs** —
      ⚠️ the website is **not** git
- [ ] Reply to whoever asked for it, on the issue or discussion

## 10. Before saying it is done

- [ ] Every touched PHP file passes `php -l`, and still starts with `<?php`
- [ ] Every touched page loads with **no fatal and no warning** — ⚠️ a PHP fatal
      is served as **HTTP 200**, and a *warning* printed before JSON breaks every
      caller's `JSON.parse` while the body reads perfectly. **Assert the response
      parses**, not that it contains the right words. That one shipped twice in
      one day.
- [ ] Drive the real page, do not read the code. Click the actual control — a
      click lands on the `<svg>`, not the button.
- [ ] **Assert the computed value**, not the inline style. `style.display !== 'none'`
      is true the moment you clear it, whatever the stylesheet then does.
- [ ] A negative control: break it deliberately and confirm the test fails. A
      test never shown to fail proves nothing.
- [ ] 🔴 **The dev database is Ed's real data.** `SELECT` the rows first, scope
      every `UPDATE`/`DELETE` to the ids you captured, and restore afterwards.
- [ ] Delete every harness file from the webroot
- [ ] Commit **and push** — app *and* wiki
