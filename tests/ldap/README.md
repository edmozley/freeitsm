# LDAP tests

```bash
php tests/ldap/01_empty_password_guard.php
```

## Why there is a test here at all

The LDAP developer guide claimed for months that the RFC 4513 empty-password
guard was *"covered by an explicit unit-style assertion"*. It wasn't. Nothing
referenced `ldapAuthenticate()` outside the two login endpoints. That is the
worst kind of documentation error — it tells the next developer a dangerous
thing is protected when nothing is protecting it.

## What `01_empty_password_guard.php` proves

An LDAP bind with a DN and a **zero-length password** is an *unauthenticated
bind* (RFC 4513 §5.1.2), and many directories answer it with **success** — so
without a guard, a blank password box logs you in as whoever was found.

> ⚠️ **Neither test rig reproduces the dangerous behaviour.** OpenLDAP and Samba
> AD both reject the empty bind themselves, so an end-to-end test against a rig
> would pass just as happily with the guard deleted. It would prove nothing.

So this test doesn't use a directory at all. It points the provider at
**192.0.2.1** (TEST-NET-1, RFC 5737 — guaranteed unroutable) and asserts on
**timing**: if the guard runs, the call returns in under a millisecond having
never opened a socket. If it doesn't, the call spends the connect timeout.
Speed *is* the assertion.

It needs no database and no containers.

### The three cases

| Password | Expected | Why |
|---|---|---|
| `''` | **Guarded**, no network | The textbook RFC 4513 case |
| `"\0"`, `"\0hunter2"` | **Guarded**, no network | libldap takes a NUL-terminated C string, so a NUL truncates the password to `""` *at the library boundary* — sailing past a `=== ''` check in PHP and becoming the very bind we're preventing |
| `"   "` | **NOT guarded** — goes to the directory | Whitespace has non-zero length. The directory checks it properly and rejects it like any wrong password. Trimming here would refuse a password some directory might legitimately hold |

That last row is asserted deliberately, so nobody later adds a "helpful"
`trim()` and quietly narrows what a password is allowed to be.

### Proven able to fail

A test never seen fail proves nothing. This one was verified by replacing the
guard with `if (false)` and re-running: **6 assertions fail**, including all
three "without any network call" checks. Restored immediately afterwards.

The final positive control catches the opposite mistake — a guard so eager it
swallows real passwords. A genuine password must *not* be short-circuited, and
must be seen to reach the network.
