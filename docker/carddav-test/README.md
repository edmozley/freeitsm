# CardDAV test server

A throwaway CardDAV server for developing and testing FreeITSM's contact sync.
**Development only — never run this anywhere real. The passwords below are
public.**

Raised in [issue #133](https://github.com/edmozley/freeitsm/issues/133) by an
operator running his own address book server. Background and the phase order:
[CalDAV and CardDAV](https://github.com/edmozley/freeitsm/wiki/CalDAV-and-CardDAV).

| Service | Port | What it is |
|---|---|---|
| `baikal` | [8092](http://localhost:8092) | **Baikal 0.12.1** — CardDAV + CalDAV on `sabre/dav`, with an admin UI |

## 🔴🔴 READ THIS FIRST: Baikal speaks DIGEST, not Basic

A stock Baikal ships `dav_auth_type: Digest`. Measured against this fixture:

| What the client sends | Result |
|---|---|
| **Basic** (`curl --basic`) | 🔴 **401** |
| **Digest** (`curl --digest`) | ✅ 207 |
| **Negotiated** (`curl --anyauth`) | ✅ 207 |

⚠️ **So an implementation that reaches for Basic — the obvious first choice, and
what almost every REST integration uses — gets a flat 401 against a default
install.** The operator then sees "authentication failed" and checks their
password, which is fine, and their username, which is fine, and concludes
FreeITSM is broken. It is the same shape as the API keys that were dead for
three weeks because a bodiless request sent `text/plain`, and the IMAP check
that reported "not authenticated" when the real fault was elsewhere.

🔑 **Use `CURLAUTH_ANY` and let it negotiate**, and have *Test connection* report
*which* scheme succeeded — because the answer is the one thing an operator
cannot find out for themselves.

Baikal can be switched to Basic in its admin UI, and mbsouth's server may well
be either, which is the other reason to negotiate rather than pick.

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

The image builds from the official release zip with its checksum verified, so
the first run takes a minute. Then open <http://localhost:8092> and complete the
**install wizard by hand** — it cannot be scripted, and it runs once:

1. Set the admin password to `admin` (this is a scratch server).
2. Leave the database as **SQLite**, and finish.

Everything after that is scripted:

```bash
bash docker/carddav-test/seed.sh
```

That creates the `itsm` / `itsm` user, two address books and five contacts.
Idempotent — re-running replaces the cards (`201` the first time, `204`
afterwards) rather than duplicating them, so it doubles as a reset.

| | |
|---|---|
| **Admin UI** | <http://localhost:8092/admin/> — `admin` / `admin` |
| **Address book URL** | `http://localhost:8092/dav.php/addressbooks/itsm/itsm/` |
| **Credentials** | `itsm` / `itsm` |

What gets seeded, and why each one is there:

| Card | In book | Why it exists |
|---|---|---|
| Alice Fairweather | `itsm` | everything filled in — the happy path |
| Bruno Kowalczyk | `itsm` | 🔑 **no email address.** A real contact can have no mailbox, and an importer that matches on email alone silently drops him |
| Chen Wei | `itsm` | no organisation or job title — sparse but perfectly valid |
| `ITSM` | `itsm` | a **`KIND:group`** card whose `MEMBER` properties point at the others |
| Dora Nkemelu | `default` | 🔑 **outside** the `itsm` book. If she ever turns up in FreeITSM, the scoping is not working |

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
