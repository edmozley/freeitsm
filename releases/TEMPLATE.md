---
version: 0.0.0
date: YYYY-MM-DD
headline: One sentence, plain language, no version number in it.
security: false
---

## What this release is about

Three or four sentences for somebody who runs FreeITSM for their team and is
deciding whether to upgrade tonight. What is the point of this release? Lead with
the thing most people will notice. No file names, no commit hashes, no CSS, no
module internals.

## Security

Only when there is something here — delete the heading otherwise, and set
`security: false` in the front matter. Say what was exposed, who was at risk, and
whether an operator must do anything beyond upgrading. Name the affected versions.

## New features

- **The thing, named as it appears on screen.** One or two sentences on what you
  can now do that you could not before.

## Improvements

- **What is better.** Why it matters, in the reader's terms.

## Fixes

- **What was broken, stated as the symptom.** "The folder counts disagreed with
  the ticket list", not "corrected an off-by-one in the folder count query".

## Upgrading

1. Pull the new code, or rebuild the Docker image.
2. Sign in as an administrator and run **System → Database Verification**.
3. Hard-refresh your browser (Ctrl-F5).

Add anything else this release needs by hand — a new environment variable, a
permission to set, a scheduled task to create. If that list is not empty, check
the release is really a MINOR.

## Rollback

One line, honestly. Either "Safe — nothing was removed from the database, so you
can go back to X.Y.Z" or "Not safe without a backup: this release removes N from
the database and the previous version still reads it."
