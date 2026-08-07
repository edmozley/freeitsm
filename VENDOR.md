# Bundled third-party libraries

FreeITSM vendors its front-end and a couple of PHP libraries directly into the
repository rather than installing them with a package manager. That keeps the
install story simple — clone or unzip, point a web server at it, done — but it
has one real cost, which this file exists to pay off.

**There is no `package.json` and no `composer.json`, so no dependency scanner can
see any of this.** Dependabot, `npm audit`, `composer audit`, and every SCA tool
in a security review look for a manifest, find none, and report a clean bill of
health for a tree that contains a browser editor, a PDF generator and a JWT
implementation. A security review in August 2026 had to read minified JavaScript
to work out what versions were in here, and found that the bundled TinyMCE had
four published stored-XSS CVEs that had been fixed upstream three months earlier.

So: **when you add or update a bundled library, add or update its row here.** It
is the only place anyone — including us — can find out what is actually shipping.

Where a version is not embedded in the file itself, the row says so and gives the
date it was vendored instead. Do not guess at those: check the upstream project
before assuming what you have.

| Library | Version | Where | Source | Licence |
| --- | --- | --- | --- | --- |
| TinyMCE | **8.8.2** | `assets/js/tinymce/` | https://www.tiny.cloud · npm `tinymce` | GPL (see `assets/js/tinymce/license.md`) |
| Chart.js | **4.4.7** | `assets/js/chart.min.js` | https://www.chartjs.org · npm `chart.js` | MIT |
| jsPDF | **2.5.2** | `assets/js/vendor/jspdf.umd.min.js` | https://github.com/parallax/jsPDF | MIT |
| html2canvas | **1.4.1** | `assets/js/vendor/html2canvas.min.js` | https://html2canvas.hertzen.com | MIT |
| jsQR | *not embedded* — vendored 2026-07-29 | `assets/js/vendor/jsQR.js` | https://github.com/cozmo/jsQR | Apache-2.0 (`assets/js/vendor/jsQR.LICENSE`) |
| qrcode-generator | *not embedded* — vendored 2026-02-08 | `assets/js/qrcode.min.js` | https://github.com/kazuhikoarase/qrcode-generator | MIT |
| firebase/php-jwt | 6.x — *not embedded* — vendored 2026-05-30 | `includes/vendor/firebase-jwt/` | https://github.com/firebase/php-jwt | BSD-3-Clause (`includes/vendor/firebase-jwt/LICENSE`) |
| Mozilla CA bundle | **2026-07-16** | `includes/cacert.pem` | https://curl.se/ca/cacert.pem | MPL-2.0 |

## Checking for updates

There is no automation for this yet, so it is a manual job. For anything on npm:

```
curl -s https://registry.npmjs.org/<name> | grep -o '"latest":"[^"]*"'
```

and for the CA bundle, compare the date in the header of `includes/cacert.pem`
against the one at https://curl.se/docs/caextract.html.

## When you update one

1. Replace the files, keeping the same subset. The TinyMCE tree, for example,
   deliberately carries only the `.min.*` files — the production set — so do not
   copy the whole npm package in.
2. Verify the checksum against the registry's own `dist.shasum` before extracting
   anything you downloaded.
3. Update the row above.
4. Actually load a page that uses it. For TinyMCE that means checking the editor
   renders and reports the new version, not just that the file parses.
5. Note it in `CHANGELOG.local.md` like any other change.
