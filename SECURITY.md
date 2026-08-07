# Security policy

FreeITSM is a service desk. Installations hold ticket histories, customer contact
details, screen recordings, mailbox credentials and, on multi-company installs,
several clients' data side by side. Security reports are genuinely welcome and will
be treated as a priority.

## Reporting a vulnerability

**Please do not open a public issue or a Discussion for a security problem.** Those
are visible to everyone, including before there is a fix, and every FreeITSM
installation is self-hosted — operators need a chance to upgrade.

Use one of these instead:

1. **GitHub private vulnerability reporting** — the *Report a vulnerability* button
   on the [Security tab](https://github.com/edmozley/freeitsm/security). This is the
   preferred route: it is private, it threads properly, and it is where an advisory
   would be published from.
2. **Email** — <edmozley@gmail.com>. Say "FreeITSM security" in the subject line.

You do not need to write an exploit, and you are not expected to. A description, a
file and line reference, and enough detail to reproduce it is more than enough.

## What to expect

- **Acknowledgement within a few days.** FreeITSM is maintained by one person in his
  own time, so please allow for that; if you have heard nothing in a week, chase.
- A decision on severity, and an honest answer if a report is not something that will
  be fixed, with the reasoning.
- Credit in the changelog and in any advisory, unless you would rather not be named.
- No legal action for good-faith research against your own installation.

## Scope

**In scope:** the application in this repository — authentication and session
handling, the permission and multi-company isolation layers, file upload and
serving, injection of any kind, secrets at rest, the REST API, and the bundled
third-party libraries listed in [VENDOR.md](VENDOR.md).

**Out of scope:** anything about a specific person's deployment rather than the
code — an operator's TLS configuration, an out-of-date PHP, a database exposed to
the internet, or a `/setup` folder left in place on a live install. Findings that
depend on an attacker already having administrator access are usually
post-compromise impact rather than a vulnerability, but say so and send it anyway if
you think it matters; more than one report in that shape has been worth fixing.

## Testing safely

Test against your own installation. Do not test against somebody else's service
desk, and please do not use automated scanners against a host you do not own.

If you want an install to attack, `docker compose up` gives you a throwaway one in a
couple of minutes — see the [README](README.md).

## Things worth knowing before you dig

Written down so nobody spends an afternoon rediscovering them:

- `includes/uploads.php` is the single home for upload rules. Anything writing files
  outside it is a bug worth reporting on its own.
- `includes/tenancy.php` holds the multi-company isolation checks; endpoints that
  bypass it and query tables directly are the likeliest source of cross-company
  problems.
- `api/system/db_verify.php` builds and migrates the schema. It is administrator-only
  except on an install with no analyst accounts yet, where it is the bootstrap.
- There is no `composer.json` or `package.json`, so no dependency scanner sees the
  bundled libraries. [VENDOR.md](VENDOR.md) lists them with versions.

## Credits

- **Erlend Volden** — a private audit in August 2026 covering
  attachment handling, credential storage at rest, session management, default
  credentials and multi-company isolation. Nine security findings, every one of them
  real, plus three correctness issues found while tracing them.
