# FreeITSM — tidying & security backlog

Private working list, same spirit as `CHANGELOG.local.md`: this is the technical
record of what is **owed**, not release notes. Items move out of here into a
changelog row when they are done.

Everything below is intended for **1.8.0** unless marked otherwise.

---

## 🔴 Security

| # | Item | Why it matters |
|---|---|---|
| S-1 | **CSRF on ~369 endpoints** (finding S4, Erlend's August audit) | Still open since August. Weighs more now there are government users with compliance officers reading the repo. Big enough that it may deserve its own release rather than riding along — decide before committing to it. |
| S-2 | `api/assets/get_people.php` still returns a raw cross-tenant `manager_id` | The *name* is now withheld (#1611), but the id travels. Weak — it only confirms an id exists — and the UI needs it. Worth a look, not urgent. |

## 🧹 Tidying — the people work (this release's theme)

| # | Item | Why it matters |
|---|---|---|
| T-1 | **`asset-management/users.php` hardcodes the seven person fields TWICE in its own JS** | `includes/users.php` says in as many words that a list duplicated across writers "is a list that will disagree with itself within a month". `tickets/users.php` now renders it from the PHP constant; this one does not. Two copies remain. |
| T-2 | **Assets → Users does not show the preferred name either** | `personName()` treats `preferred_name` as a *fallback* for `display_name`, so where both exist the preferred name is never shown. Exactly the gap #1610 closed on the Tickets screen — the other half is still open. |
| T-3 | ⬜ **ED'S DECISION: should the two people editors be one screen?** | Tickets → Users and Assets → Users now show the same seven fields on two separate pages. Consolidating would delete T-1 and T-2 outright. Not a job to start without deciding. |
| T-4 | **`tickets/users.php` has never been through the mobile rollout** | `scrollWidth` 1406 at a 400px viewport, measured identical before and after this release's work, so it is pre-existing. Belongs with the §37-onwards mobile sweep rather than here. |

## 🧹 Tidying — elsewhere

| # | Item | Why it matters |
|---|---|---|
| T-5 | **The rest of the portal may have the same hardcoded-light problem** | `user-menu.php` was fixed in #1608, but it was written that way by habit, not by accident. `assets/css/self-service.css` and the portal pages want the same audit — grep for hex literals against `theme.css` tokens. |
| T-6 | **New English i18n keys are untranslated in 23 locales** | Safe: `I18n::t()` falls back per key and `exportForJs()` deep-merges English, both verified with a real `Accept-Language: pl-PL` request. So this is a quality item, not a bug. Feeds the i18n fan-out. |
| T-7 | **Polish is 37.6% translated** | Under a Polish government agency. The worst locale, under the user least able to shrug it off. Not a 1.8.0 job — it is its own piece of work. |

## 📌 Not tidying, but owed

| # | Item |
|---|---|
| O-1 | **Reply to mbsouth on #133** — his 8 September message is still unanswered. Draft written and approved in principle; send with the 1.8.0 links. |
| O-2 | **The escalation / tier half of GH #125** — the other half of team assignment, outstanding since 1.6.0. |
| O-3 | **dschipfel's status / team / tags on form submission** — promised across three releases now. |

---

## Done in 1.8.0 so far

#1594-#1597 rota copy/paste · #1598-#1601 person fields on Tickets → Users ·
#1602 the unscrollable modal · #1603 the managed note's colour ·
#1604-#1607 portal self-service contact details · #1608 portal dark mode ·
#1609 the invisible managed note · #1610 preferred name shown to analysts ·
#1611 🔴 the cross-tenant manager name

**18 rows: 4 Feature / 6 Improvement / 8 Fix (one security).** MINOR → 1.8.0.
