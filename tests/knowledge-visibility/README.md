# Knowledge visibility harnesses

Integration checks for who can read a knowledge article: its owning **company**
(`knowledge_articles.tenant_id`, NULL = shared with all) and its **audience**
(`internal` / `customer` / `public`).

```bash
php tests/knowledge-visibility/run.php
```

> ⚠️ **Dev installs only.** These drive the real endpoints against a running
> install and create/delete real rows (all prefixed `ZZ-`). They also forge a
> PHP session file to act as an analyst. Never point them at production.

Configure with env vars if your install isn't at the default:

```bash
FREEITSM_TEST_URL=http://localhost/freeitsm-app   # where the app is served
FREEITSM_SESS_DIR=C:/wamp64/tmp                   # where PHP writes sessions
```

Best run on a **multi-company** install with at least "Ed Mozley Ltd" and "Dream
Holidays" (they're looked up by name). On a single-company install most company
checks are no-ops by design — which is itself the point, but it means they prove
less.

## What each one covers

| File | Guards |
|---|---|
| `01_webchat_scope.php` | The original bug: an anonymous web chat visitor on one client's site being answered from another client's articles — or from an `internal` one. Also that a caller who forgets to pass scope gets the *most restrictive* result, not everything. |
| `02_write_path.php` | `KnowledgeService::saveArticle` — defaults (shared + internal), validation, that a bogus audience is rejected rather than normalised, that you can't file against a company you can't reach, and that a **partial save doesn't silently reset visibility**. |
| `03_readers.php` | Every analyst-facing surface, driven over HTTP as a genuinely **restricted** analyst: list, by-id, review list, embedding backfill, Watchtower. |
| `04_rest_api.php` | REST v1 with a company-scoped key: own + **shared** visible, other company never, 404 (not 403) on a guessed id, `?company=` / `?audience=` validated. |
| `05_lms.php` | The LMS may only build lessons from **shared** articles — including posting a hidden article's id directly, since the picker isn't a control. |

## Why these are integration tests, not unit tests

Every bug they guard against only exists **end to end**: a missing `WHERE`
clause, a filter with the wrong NULL semantics, an id that bypasses a picker. A
mocked version would have passed happily while the app leaked. So they use a
real database, real HTTP, and a real restricted analyst.

## The one thing they cannot prove

`01` asserts that `kbRetrieveArticles` defaults to the most restrictive rung —
but no harness here can prove the **empty-password / unauthenticated-bind**
class of bug in the LDAP work, and similarly none of these can prove a filter
you *forgot to add somewhere new*. They cover the readers that existed when they
were written. Add a case when you add a reader.
