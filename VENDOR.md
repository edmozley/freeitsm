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
| TinyMCE | **8.10.0** | `assets/js/tinymce/` | https://www.tiny.cloud · npm `tinymce` | GPL (see `assets/js/tinymce/license.md`) |
| Chart.js | **4.4.7** | `assets/js/chart.min.js` | https://www.chartjs.org · npm `chart.js` | MIT |
| PDFKit | **0.20.2** | `assets/js/vendor/pdfkit/pdfkit.bundle.js` - ONE self-contained ES module: PDFKit plus everything it is built from (fontkit - the text-shaping engine - linebreak, png-js, fflate, @noble/hashes and ciphers, restructure, and fontkit's helpers). Downloaded from `https://esm.sh/pdfkit@0.20.2/es2020/pdfkit.bundle.mjs` and renamed `.js` (IIS and older nginx do not serve `.mjs` as JavaScript). Loaded only by `assets/js/pdf-writer.js`, with `import()`. Replaced jsPDF 4.2.1 and AutoTable 5.0.9 in GH #254 | https://github.com/foliojs/pdfkit · npm `pdfkit` | MIT; `@swc/helpers` inside is Apache-2.0 (`assets/js/vendor/pdfkit/LICENSE.txt`, every package listed) |
| Noto fonts (Sans, Serif, Sans Mono, and Sans for 11 scripts) | *not embedded* - downloaded 2026-11-07 (Noto Sans: 2026-11-02, for asset labels) | `assets/fonts/noto-sans/` (also used by asset labels), `assets/fonts/noto-serif/`, `assets/fonts/noto-sans-mono/`, `assets/fonts/noto-sans-scripts/` (Devanagari, Bengali, Gujarati, Gurmukhi, Kannada, Malayalam, Tamil, Telugu, Arabic, Hebrew, Thai - Regular and Bold). The PDF writer fetches a script's font only when a PDF contains it | https://github.com/notofonts/notofonts.github.io (`fonts/<Family>/hinted/ttf`) | SIL Open Font License 1.1 (`OFL.txt` in each folder) |
| html2canvas | **1.4.1** (the latest) | `assets/js/vendor/html2canvas.min.js` | https://html2canvas.hertzen.com | MIT |
| pdf.js (Mozilla) | **6.3.289** | `assets/js/vendor/pdfjs/` - `build/pdf.min.mjs` and `pdf.worker.min.mjs` renamed to `.js` (IIS and older nginx do not serve `.mjs` as JavaScript), plus `cmaps/`, `standard_fonts/`, `wasm/`, `iccs/`; the QuickJS scripting sandbox (`wasm/quickjs-eval.*`) deliberately removed - the Files viewer never runs PDF JavaScript | https://mozilla.github.io/pdf.js · npm `pdfjs-dist` | Apache-2.0 (`assets/js/vendor/pdfjs/LICENSE`) |
| mammoth.js | **1.13.0** | `assets/js/vendor/mammoth.browser.min.js` | https://github.com/mwilliamson/mammoth.js · npm `mammoth` | BSD-2-Clause (`assets/js/vendor/mammoth.LICENSE`) |
| SheetJS Community Edition | **0.20.3** | `assets/js/vendor/xlsx.full.min.js` | https://cdn.sheetjs.com (NOT npm - the npm `xlsx` package stopped at 0.18.5) | Apache-2.0 (`assets/js/vendor/xlsx.LICENSE`) |
| Prism | **1.30.0** | `assets/js/vendor/prism.min.js` = npm `components/prism-core` + `markup` + `css` + `clike` + `javascript` (`.min.js`, in that order); the 7 language components, toolbar and copy-to-clipboard plugins and 2 stylesheets from the same package | https://prismjs.com · npm `prismjs` | MIT (`assets/js/vendor/prism.LICENSE`) |
| jsQR | *not embedded* — vendored 2026-07-29 | `assets/js/vendor/jsQR.js` | https://github.com/cozmo/jsQR | Apache-2.0 (`assets/js/vendor/jsQR.LICENSE`) |
| qrcode-generator | *not embedded* — vendored 2026-02-08 | `assets/js/qrcode.min.js` | https://github.com/kazuhikoarase/qrcode-generator | MIT |
| firebase/php-jwt | 6.x — *not embedded* — vendored 2026-05-30 | `includes/vendor/firebase-jwt/` | https://github.com/firebase/php-jwt | BSD-3-Clause (`includes/vendor/firebase-jwt/LICENSE`) |
| Mozilla CA bundle | **2026-07-16** | `includes/cacert.pem` | https://curl.se/ca/cacert.pem | MPL-2.0 |

## Checking for updates

There is no automation for this yet, so it is a manual job - **part of every pre-release docs audit.** Last checked **2026-10-25** (4.1.0: TinyMCE, jsPDF, AutoTable and Prism updated for published security fixes - jsPDF and AutoTable have since been replaced by PDFKit, GH #254; Chart.js, pdf.js, php-jwt, qrcode-generator and the CA bundle have newer releases not yet taken).

`tests/security-findings/run.php` (section F6) fails if TinyMCE or Prism falls back below the version with the fix, if PDFKit is not the version recorded here, or if jsPDF (or a second PDF library) appears again.

**PDFKit is updated differently from the rest**, because it is many npm packages, not one file: download the new bundle from `https://esm.sh/pdfkit@<version>/es2020/pdfkit.bundle.mjs`, check its first line names that version and that it has no `import` of another URL (it must be self-contained - an air-gapped install cannot fetch anything), rename it `pdfkit.bundle.js`, update `LICENSE.txt` from the new version's dependency list, then run `php tests/pdf-writer.php` and look at the PDF it saves. Why and how: wiki [JavaScript modules and vendoring explained](https://github.com/edmozley/freeitsm/wiki/JavaScript-Modules-And-Vendoring-Explained).

For anything on npm:

```
curl -s https://registry.npmjs.org/<name> | grep -o '"latest":"[^"]*"'
```

and for the CA bundle, compare the date in the header of `includes/cacert.pem`
against the one at https://curl.se/docs/caextract.html.

## When you update one

1. Replace the files, keeping the same subset. The TinyMCE tree, for example,
   deliberately carries only the `.min.*` files — the production set — so do not
   copy the whole npm package in.
2. Verify the checksum against the registry's own `dist.integrity` (sha512) before
   extracting anything you downloaded.
3. Update the row above, and the `?v=` on every page that loads it - the files keep
   their names, so without it a browser can keep running the old copy.
4. Actually load a page that uses it. For TinyMCE that means checking the editor
   renders and reports the new version, not just that the file parses.
5. Note it in `CHANGELOG.local.md` like any other change.
