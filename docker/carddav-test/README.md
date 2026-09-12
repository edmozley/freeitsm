# CardDAV test server

A throwaway CardDAV server for developing and testing FreeITSM's contact sync.
**Development only — never run this anywhere real. The passwords below are
public.**

Raised in [issue #133](https://github.com/edmozley/freeitsm/issues/133) by an
operator running his own address book server. Background and the phase order:
[CalDAV and CardDAV](https://github.com/edmozley/freeitsm/wiki/CalDAV-and-CardDAV).

| Service | Port | What it is |
|---|---|---|
| `baikal` | [8092](http://localhost:8092) | Baikal — CardDAV + CalDAV on `sabre/dav`, with an admin UI |

## Why Baikal, and not "SabreDAV"

🔑 **`sabre/dav` is a PHP library, not a server.** There is nothing called
"SabreDAV" to install, so "he runs SabreDAV" resolves to *something built on
sabre/dav*. Baikal is the thin standard one, so it is both the closest match to
the reporter's setup and the most useful target: pass against Baikal and you
have passed against `sabre/dav` itself — which Nextcloud and ownCloud also use
underneath.

⚠️ There is no official Baikal image. `ckulka/baikal` is the community one.

## Start

```bash
docker compose -f docker/carddav-test/docker-compose.yml up -d
```

Then open <http://localhost:8092> and complete the **install wizard by hand**
— it cannot be scripted, and it runs once:

1. Set the admin password to `admin` (this is a scratch server).
2. Leave the database as **SQLite**.
3. Finish, then sign in at <http://localhost:8092/admin/> as `admin`.
4. **Users → Add user**: `itsm` / `itsm` / email `itsm@carddav.test`.
5. That user gets a default address book automatically. Add a second one called
   **`itsm`** if you are testing the "scope it to one group" case — see the note
   on what "group" means below.

The address book URL you give FreeITSM then looks like:

```
http://localhost:8092/dav.php/addressbooks/itsm/default/
```

## Seeding contacts

```bash
docker/carddav-test/seed-contacts.sh
```

Writes a handful of vCards straight over CardDAV with `curl`, so the fixture is
repeatable and does not depend on clicking through the UI. Re-running replaces
them rather than duplicating — each card is `PUT` to a fixed URL.

## 🔴 "Scope it to one group" is not one question

The reporter asked for the sync to be limited to an `itsm` group. **CardDAV has
three different things that get called a group**, and they are not
interchangeable:

| What | How it looks | Scoping by it |
|---|---|---|
| **A separate address book** | its own collection URL | trivial — the URL *is* the scope |
| **`KIND:group` + `MEMBER`** | one vCard listing the others (RFC 6350) | needs a second pass to resolve members |
| **`CATEGORIES:itsm`** | a property on each card | a filter, and not every client writes it |

Apple Contacts writes the second. Many Android and Thunderbird setups write the
third. ⚠️ **Do not guess which one he means** — the answer changes what phase 4
has to read, and building for the wrong one looks identical until it silently
imports nothing. Ask.

## Stop and reset

```bash
docker compose -f docker/carddav-test/docker-compose.yml down
```

To get back to a clean server, remove the two volumes as well:

```bash
docker compose -f docker/carddav-test/docker-compose.yml down -v
```

⚠️ `down -v` destroys every contact in it. That is the point of a fixture, but
it is also why nothing real should ever live here.
