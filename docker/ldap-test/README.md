# LDAP test directories

Throwaway directory servers for developing and testing FreeITSM's LDAP sign-in
(System → Authentication → Type: *LDAP / Active Directory*). **Development only
— never run these anywhere real.** The passwords below are public.

Two servers, deliberately:

| Service | Port | Base DN | What it's for |
|---|---|---|---|
| `samba-ad` | 3891 | `DC=ad,DC=freeitsm,DC=test` | **Real Active Directory semantics** — `sAMAccountName`, `memberOf`, nested groups, referrals, binary `objectGUID`, disabled accounts. This is what most users actually have. |
| `openldap` | 3890 | `dc=freeitsm,dc=test` | A genuinely different flavour — `uid`, `entryUUID`, `groupOfNames`, no nested groups. |
| `phpldapadmin` | [8091](http://localhost:8091) | — | Browse the OpenLDAP tree in a GUI. |

Testing against **both** is the point. Build against one and the code silently
grows that one's assumptions — nested groups and `sAMAccountName` simply don't
exist on OpenLDAP, and `memberOf` doesn't exist there at all without the
`memberof` overlay.

## Start

```bash
docker compose -f docker/ldap-test/docker-compose.yml up -d
```

Then seed:

```bash
# OpenLDAP: entries, then the ACL that lets the service account read them
docker cp docker/ldap-test/seed.ldif freeitsm-ldap:/tmp/seed.ldif
docker exec freeitsm-ldap ldapadd -x -H ldap://localhost \
  -D "cn=admin,dc=freeitsm,dc=test" -w adminpass -f /tmp/seed.ldif
docker cp docker/ldap-test/acl.ldif freeitsm-ldap:/tmp/acl.ldif
docker exec freeitsm-ldap ldapmodify -Y EXTERNAL -H ldapi:/// -f /tmp/acl.ldif

# Samba AD: simple users, then a realistic company
bash docker/ldap-test/seed-ad.sh
bash docker/ldap-test/seed-ad-company.sh
```

The containers have **no restart policy** and **no volumes** — they survive
`docker stop`/`start` but a `docker rm` loses everything. Re-seed with the above.

## Credentials

**OpenLDAP** (`127.0.0.1:3890`, base `dc=freeitsm,dc=test`)
- admin: `cn=admin,dc=freeitsm,dc=test` / `adminpass`
- service account: `cn=svc-freeitsm,dc=freeitsm,dc=test` / `svcpass`
- users: `alice`/`alicepass`, `bob`/`bobpass`, `carol`/`carolpass`

**Samba AD** (`127.0.0.1:3891`, realm `AD.FREEITSM.TEST`)
- admin: `Administrator@AD.FREEITSM.TEST` / `Passw0rd!2026`
- simple users (base `DC=ad,DC=freeitsm,DC=test`): `alice`/`Passw0rd!alice`, `bob`, `carol`;
  service account `svc-freeitsm@AD.FREEITSM.TEST` / `Passw0rd!svc`
- **Northwind company** (base `OU=Northwind,DC=ad,DC=freeitsm,DC=test`):
  service account `svc-ldap@AD.FREEITSM.TEST` / `Nw!Svc2026`

## The Northwind company — why it exists

`seed-ad-company.sh` builds a small business rather than flat test users,
because the cases that break LDAP code only exist in a realistic tree:

| Who | Password | Why they're there |
|---|---|---|
| `r.patel` | `Nw!Patel2026` | Ordinary analyst, in `NW-IT-Support`, nested under `OU=IT,OU=Staff` |
| `a.chen` | `Nw!Chen2026` | In `NW-IT-Admins` **only** — an admins group is *not* automatically your analyst group |
| `s.oconnor` | `Nw!Conn2026` | `Siobhan O'Connor` — an **apostrophe** in the DN |
| `j.muller` | `Nw!Mull2026` | `Jürgen Müller` — **UTF-8** round-tripping |
| `l.garcia` | `Nw!Garc2026` | `Lucía García` — same |
| `t.brooks` | `Nw!Broo2026` | Sales — gate him to the self-service user group, not analyst |
| `p.ndlovu` | `Nw!Ndlo2026` | Finance — in **neither** ITSM group, so must be **denied despite a correct password** |
| `w.noemail` | `Nw!NoMa2026` | Has **no email attribute** — JIT must refuse cleanly |
| `x.leaver` | `Nw!Leav2026` | **Disabled** — must never sign in, even with the right password |

Groups live in `OU=Groups,OU=Northwind`: `NW-IT-Support`, `NW-IT-Admins`,
`NW-Sales`, `NW-Finance`, and `NW-All-Staff` — which contains the **other
groups**, not people. Nobody has `NW-All-Staff` in `memberOf`; only AD's
chain-matching rule finds it. Gate on it to test nested groups.

## Gotchas these rigs exist to catch

- **OpenLDAP's default ACL denies reads**, and an unreadable subtree is reported
  as `No such object` — which looks exactly like a missing user. `acl.ldif`
  grants the service account read (deliberately *not* on `userPassword`).
- **Samba needs `privileged: true`** and `INSECURELDAP=true`, the latter relaxing
  `ldap server require strong auth` so simple binds work over plain 389 in
  testing. Real AD usually wants LDAPS.
- **Neither rig reproduces the empty-password "unauthenticated bind"** — both
  correctly reject it. The guard in `includes/ldap.php` therefore cannot be
  proven by these containers and must never be removed on the strength of a
  green test run.

See the [LDAP Developer Guide](https://github.com/edmozley/freeitsm/wiki/LDAP-Developer-Guide)
on the wiki for the implementation and the full list of traps.
