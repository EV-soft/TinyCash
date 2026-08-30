# setup/ - Engangs-scripts til opsætning og migrering

Alle scripts i denne mappe kræver login som **admin** (undtagen `init_demo_data.php`,
se nedenfor), og bruges kun én gang pr. formål. **Slet hele denne mappe, når du er
færdig** - ingen af scripterne er beregnet til at ligge tilgængelige permanent.

## Rækkefølge (ved en helt frisk installation)

1. **`init_demo_data.php`**
   Opretter et minimalt tabelsæt + eventyr-tema demo-data (kunder, produkter,
   fuld kontoplan inkl. egenkapital- og gældskonto, én eksempelfaktura,
   admin-bruger). **Kræver IKKE login** (der findes jo ingen bruger endnu på
   en frisk base) - dens eneste beskyttelse er, at den nægter at køre, hvis
   `users`-tabellen allerede indeholder rækker. Login efter kørsel:
   `admin` / `eventyr123` (skift kodeordet med det samme).

2. **`create_all_tables.php`**
   Opretter de resterende tabeller fra det fulde skema (`expenses`,
   `projects`, `fiscal_years`, `voucher_counter`, `transactions`,
   `bank_statement_temp`, `audit_log`, `tbl_maillog` m.fl.), som IKKE er en
   del af `init_demo_data.php`s minimale sæt. Kør denne, hvis du støder på
   "no such table"-fejl, mens du klikker rundt i systemet.

Disse to trin alene giver nu en fuldt køreklar installation - alle kolonner
tilføjet af nedenstående migrationer over tid (projekt-modul, valuta,
kreditnota, bilagsopfølgning, hulfrit bilagsnummer) er allerede en del af
basis-skemaet i begge scripts. **Verificeret 2026-08-17**: kørt mod en helt
tom database, efterfulgt af en funktionel test af udgifts-, fakturaformular
og kontoplan-siderne uden fejl.

## Ét-klik-opsætning (kun i ZIP-fresh-install-pakken)

- **`create_all_tables.core.php`** - selve tabel-opsætningen fra
  `create_all_tables.php`, udtrukket til en delt fil uden auth-afhængighed.
  `create_all_tables.php` kalder den efter sit eget admin-login-tjek; se
  nedenfor for den anden kalder.
- **`bootstrap_index.php`** - en midlertidig "førstegangsopsætnings"-side. I
  ZIP-fresh-install-pakken lægges denne ind SOM `index.php` (den rigtige
  `index.php` følger med i pakken omdøbt til `index.real.php`). Den løser
  hønen-og-ægget-problemet ved at `create_all_tables.php` normalt kræver
  admin-login, som jo ikke findes endnu på en frisk installation: den kører
  `init_demo_data.php` (som i forvejen ikke kræver login) og derefter
  `create_all_tables.core.php` direkte, uden om auth-tjekket. Nægter at køre
  hvis `users`-tabellen allerede har rækker (samme værn som
  `init_demo_data.php` selv bruger) - kan ikke bruges til at nulstille eller
  overskrive en eksisterende installation.
- **`finalize_bootstrap.php`** - sidste trin, kaldt fra `bootstrap_index.php`s
  "Færdig"-side: omdøber `index.real.php` til `index.php`. Ligger bevidst i en
  helt selvstændig fil, fordi `rename()` under test ikke kunne overskrive den
  fil der lige nu aktivt udføres af PHP (set på Windows - filen var låst,
  mens scriptet kørte).
- Dette gælder **kun ZIP-fresh-install-pakken** - i det almindelige
  udviklingstræ (denne mappe, som den ligger nu) er `index.php` altid den
  rigtige forside, og der findes ingen `index.real.php`.

## Kun relevante for ældre installationer der opgraderer

Alt herunder er allerede en del af basis-skemaet ovenfor for en frisk
installation - kør dem KUN hvis en *eksisterende* database mangler en
konkret kolonne (fx efter en opdatering af koden uden at have kørt en
tidligere version af `create_all_tables.php`).

- **`migrate_fiscal_years.php`** - opretter `fiscal_years`-tabellen (hvis den
  mangler) og viser en formular til at angive jeres **faktiske**
  regnskabsårs start-/slutdato - understøtter forskudt regnskabsår, ikke kun
  kalenderår. Kan bruges flere gange til flere regnskabsår.
- **`migrate_cust_reference.php`** - `invoices.cust_reference`.
- **`migrate_invoice_currency.php`** - `invoices.orig_currency`/`exch_rate`.
- **`migration_credit.php`** - `invoices.credit_ref`,
  `expenses.no_attachment_reason`, `expenses.cancelled_by`.
- **`migrate_cancelled_by.php`** - ældre, delvist overlappende med
  `migration_credit.php` (samme kolonne) - overflødig hvis du kører den.
- **`migration_projects.php`** - historisk kun den SIDSTE opgradering
  (`exp_type` + `projects.note_*`); selve `projects`-tabellen og
  `proj_id`-kolonnerne oprettes nu direkte af `create_all_tables.php`.
- **`migrate_equity_account.php`** / **`migrate_liability_account.php`** -
  opretter egenkapital-/gældskontoen (3000/4000) hvis de mangler - allerede
  en del af `init_demo_data.php`s kontoplan.
- **`migrate_ledger_audit.php`** - `ledger.created_at`/`user_id`.
- **`migrate_voucher_counter.php`** - opretter+seeder `voucher_counter`.
- **`migration_auto_backup.php`** - seeder valgfrie standardværdier for
  auto-backup-indstillinger i `settings` (rent bekvemmelighed).
- **`fix_login_log.php`** - selvstændigt nødreparations-script, hvis kun
  `login_log` mangler (indgår allerede i både `init_demo_data.php` og
  `create_all_tables.php`, så denne er normalt overflødig).
- **`db_migrate.php`** - ældre, generel ALTER-registrering; de fleste af dens
  `currency`-kolonner er allerede i basis-skemaet. Indeholder en reference
  til en tabel `bank_transactions`, som ikke findes i systemet (det rigtige
  navn er `bank_statement_temp`) - den enkelte linje fejler harmløst.

## Sikkerhed

- Alle scripts her bruger `CREATE TABLE IF NOT EXISTS` og lignende idempotente
  mønstre - det er altid sikkert at køre dem flere gange.
- `init_demo_data.php` og `create_all_tables.php` indsætter kun eventyr-/
  eksempeldata, hvis de relevante tabeller er tomme - de overskriver aldrig
  eksisterende rigtige data.
- **Slet denne mappe helt**, når opsætningen er færdig. Adgangskontrollen
  (admin-login) er et sikkerhedsnet, ikke en erstatning for at rydde op.
