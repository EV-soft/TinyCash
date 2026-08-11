# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

TinyCash is a self-hosted PHP/MySQL (or SQLite) accounting + inventory system for small
Danish businesses: invoicing, expenses, double-entry bookkeeping, VAT (moms) reporting,
bank import/reconciliation, and Danish e-invoice (OIOUBL) export. There is **no build step,
no package manager, and no test suite** — it is a flat set of PHP scripts served directly by
Apache/Nginx. The working copy is not a git repository.

The code and comments are written in **Danish**. User-facing strings are English keys
resolved at runtime through the translation layer (see below).

## Running & debugging

- **Config lives in `inc/env.ini`** (parsed with `parse_ini_file`). `ACTIVE_DB` picks the
  active section: `[mysql_config]` or `[sqlite_config]`. This file holds real secrets
  (DB password, mail IMAP passwords, `OPENAI_API_KEY`) — treat it as sensitive; it is the
  single source of connection settings. `login.php` can override the engine per-session via
  `$_SESSION['db_type']`, but credentials always come from `env.ini`.
- **Run locally:** configure `env.ini`, then `php -S localhost:8000` from the project root and
  open `index.php`. SQLite mode auto-creates `data/tinycash.sqlite`; fresh setup scripts live
  in `setup/` and `db-setup/`.
- **Debug output is off by default.** Append `?Debug=true` to any URL to force
  `display_errors` + `E_ALL` (handled in `inc/db_connect.inc.php`). PHP errors are logged to
  `inc/system_errors.log`.
- **First-time DB setup** (from `db-setup/README.md`, all idempotent, admin-only except the
  first): `init_demo_data.php` (minimal tables + demo data + `admin`/`eventyr123`), then
  `create_all_tables.php` for the remaining tables. Delete `setup/` and `db-setup/` after use.

## Page architecture

Every user-facing page is a standalone `.php` file in the project root that follows the same
include + render sequence:

```php
require_once 'inc/auth.inc.php';        // session, auth gate, language switch
require_once 'inc/db_connect.inc.php';  // env.ini -> $conn, DB class, get_settings()
require_once 'inc/menu.inc.php';        // showMenu()
require_once 'inc/php2htm.lib.php';     // htm_* HTML builders (pulls in core_utils, help, htm_page)

htm_Header(capt: '@Page Title', mwidth: 1000);
showMenu();
htm_Card_(capt: '@Section', wdth: 1000);
// ... page body, built with htm_* helpers ...
htm_Card_end();
htm_Footer();
```

Root files pair a view (`*_edit.php`, `*_list.php`, `*_view.php`) with a POST handler
(`*_actions.php`, `*_action.php`, `*_save.php`) that processes the form and redirects back.

## Key conventions (from `inc/Kodestandard.txt`)

- **All HTML is generated through the `htm_*` functions** in `inc/php2htm.lib.php` (and
  `inc/htm_page.lib.php` for header/footer) — do not hand-write markup in pages. Use `'`
  for PHP strings, `"` for HTML attributes.
- **Call `htm_*` functions with named arguments** (PHP 8 named params), e.g.
  `htm_Card_(capt: '@X', wdth: 1000)`.
- Parameter names are a fixed vocabulary of **4-character keys** (`capt`=caption, `labl`=label,
  `wdth`=width, `hint`=tooltip, `echo`=return-vs-print, `form`, `icon`, …). The full list is in
  `inc/Kodestandard.txt` — match it when adding parameters.
- Each source file starts with a version header comment:
  `# path v:X.Y.Z d:YYYY-MM-DD i:author`. Bump it when you make substantive changes.

## Translation / i18n

- `lang('@Some English text')` (defined in `inc/core_utils.lib.php`) resolves keys against
  `json-data/languages.json`, keyed by the current `$_SESSION['lang']` (default `da`). Keys are
  the English source string prefixed with `@`; if a translation is missing it falls back to the
  key with the `@` stripped. Wrap every user-visible string in `lang()`.
- `tools/extract_translations.php` / `translation_manager.php` scan the codebase and keep
  `languages.json` in sync across the supported languages.

## Database access layer

**Never call `mysqli_*` or PDO directly** — go through the static `DB` class in
`inc/db_connect.inc.php`, which abstracts over MySQL and SQLite so the same code runs on both:

- `DB::query($conn, $sql)`, `DB::fetch_assoc($res)`, `DB::fetch_row($res)`, `DB::num_rows($res)`,
  `DB::free_result($res)`, `DB::escape($conn, $s)`.
- `DB::insert($conn, $table, $data)`, `DB::update($conn, $table, $data, $whereField, $whereValue)`,
  `DB::prepare($conn, $sql)`, `DB::prepare_and_execute($conn, $sql, $params)`.
- Transactions: `DB::begin_transaction`/`DB::commit`/`DB::rollback` — use these for
  multi-statement operations that must be atomic (e.g. cancelling a posted expense while
  syncing the journal).
- Branch on engine with `DB::is_sqlite()`. Globals in play: `$conn`, `$db_type`, `$pdo` (SQLite).
- SQLite `SELECT`s return a `SQLiteBufferedResult` so `num_rows()` + repeated `fetch_assoc()`
  behave like MySQLi's buffered results. Prefer `DB::query` over raw PDO to keep this behavior.

`get_settings($conn)` returns the `settings` table as a key/value array; `CONF_DATE_FORMAT`
and `$global_settings` are defined during connect. `is_date_locked($conn, $date)` enforces the
accounting lock date.

Note: `inc/db.lib.php` (`getDb()`) is a separate, legacy PDO helper and is **not** the path the
app uses — the live abstraction is the `DB` class in `inc/db_connect.inc.php`.

## Auth & access control

`inc/auth.inc.php` runs on every page: it starts the session (`TCC_V100_SESSION`), enforces
inactivity timeout, redirects to `login.php` when not logged in, and applies user levels.
Set `$rLev` (required level) **before** including `auth.inc.php` to gate a page;
`deny_access_gracefully()` renders a styled "access denied" page. Levels are `1`–`3`
(`$_SESSION['user_level']`); admin/migration scripts require level `3`.

## Data model (core tables)

`accounts` (chart of accounts) + `vat_codes`; `customers`, `products` (with `prod_stock` auto
-adjusted on invoicing); `invoices` / `invoice_lines`; `expenses`; **`journal` + `ledger`**
(double-entry postings — the accounting core); `transactions`; `bank_statement_temp` (bank
import staging) → reconciliation; `settings`, `users`, `login_log`, `fiscal_years`, `projects`.
Full column reference is in `inc/Kodestandard.txt`. Attachments live in `uploads/` and
`storage/`; backups in `backups/`.

## Migrations

Schema changes are applied via one-off, idempotent (`IF NOT EXISTS` / column-existence checks)
scripts, not a migration framework: `inc/db_migrate.php` (registry of `ALTER TABLE`s recorded
in `system_migrations`), plus individual `inc/migration_*.php` and `db-setup/migrate_*.php`
files. When adding a column, follow the existing pattern — check for the column on both engines
before altering, and record the migration key.

## `tools/` and one-time scripts

`tools/` holds developer utilities (code collectors producing `collected_*.txt`, a file
dependency analyzer, unused-file finder, translation extractor) — not part of the runtime.
`setup/` and `db-setup/` are install/migration scripts meant to be deleted after setup.

## Regulatory context

Danish bookkeeping-law (bogføringsloven) compliance is a known concern for this project.
Documented gaps (see the analysis at the end of `inc/Kodestandard.txt`) include: ledger/journal
postings are mutable and deletable (no immutability/period-lock), journal entries lack a unique
voucher number (`bilagsnummer`), and there is no automatic off-site weekly backup. Keep these in
mind when touching posting, deletion, or backup logic.
