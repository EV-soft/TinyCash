![TinyCash Dashboard](images/dashboard.png)


# TinyCash 🛠️

**TinyCash is an open-source, web-based accounting system written in PHP, with support for MySQL and SQLite.**

**The system supports over 180 languages, 13 of which are currently pre-configured with a minimal translation covering the main menu and essential system elements.**

**The system is developed to comply with Danish bookkeeping requirements. It can be hosted on anything from a standard web server to a private NAS.**

**It provides a fast, efficient, and self-reliant solution for invoicing, inventory management, VAT handling, and more.**

### Core Features & Strengths

- 🌍 **Smart Language Engine:** TinyCash supports automated translation via OpenAI integration. The system automatically scans the source code for English strings and provides a central management page for translations – now with 13 languages prepared.

- 📦 **Automated Inventory Management:** Inventory levels are updated automatically when items are sold and invoices are created, ensuring full synchronization between sales and stock.

- 💱 **Multi-Currency Support:** Choose your own bookkeeping currency, invoice customers in a different one, and let the system post automatic exchange-rate gains/losses.

- 🔐 **Privacy & Ownership (Self-Hosted):** Your data is 100% yours. No dependency on third-party cloud solutions, no monthly subscriptions. Run everything securely on your own infrastructure.

- ⚡ **High Performance:** Built with clean PHP/MySQL logic, ensuring fast execution even on simple shared hosting environments.

- 🛠️ **Developer-Centric Architecture:** The system is now centralized around a robust library (`php2htm.lib.php`) that handles everything from theme management (Light/Custom/Dark) to reusable UI components, making the code modular and easy to maintain.

- *The current code is written by EV-soft in close collaboration with AI assistants.*

### Key Features

- **Customer & Supplier Management:** Efficient database management of your business relations.

- **Invoicing:** Professional, print-ready invoice layouts with full flexibility.

- **Inventory Management:** Real-time tracking with automatic stock deduction upon sales.

- **Document Management:** AI-assisted scanning, interpretation, bookkeeping, and electronic archiving of documents.

- **Bank Import & Integration:** CSV import for reconciliation, plus direct PSD2 bank integration (Enable Banking).

- **Backup:** Centralized control of regular, encrypted backups for data, documents, mail, and other files.

- **Integrated Tools:** A built-in toolkit for code review, backup, database migration, and advanced language management.

- **Theme Selection:** Customizable visual themes (Light, Dark, Custom).

- **User Levels:** The menu allows you to restrict features based on user roles: Simple – Advanced – System Administrator. Levels can be toggled via a button in the Log-out area.

### Installation & Configuration

1. **Upload:** Upload all files from the source archive to a PHP-compatible server (Requirement: PHP 8.0+).

2. **Database Setup**

   - **MySQL:** Create a MySQL database via your control panel. Create a database admin with full privileges, then run the setup scripts in `db-setup/` (see `db-setup/README.md`) to build the schema.

   - **Edit** the configuration file at `inc/data/env.ini` with your database credentials (host, user, password, database), as well as mail and API connection settings. Note: If mail and API settings are omitted, mail and translation features will be disabled.

   - **SQLite:** No setup required. A pre-configured database is included in the file package.

3. **API & Language Configuration**

   - **OpenAI:** To enable smart translation suggestions and document scanning, insert your `OPENAI_API_KEY` into your `inc/data/env.ini` file.

   - **Language:** The system currently includes 13 pre-defined languages. You can add or edit texts on the management page located under *System/Control Panel/Language Editing*, or directly in `/json-data/languages.json`.

<br>
<br>
<br>

# **TinyCash 🛠️**

**TinyCash er et open source webbaseret regnskabssystem, skrevet i PHP, og understøttet af MySQL og SQLite.**

Systemet understøtter over 180 sprog, hvoraf 13 sprog i øjeblikket er forberedt med en minimal oversættelse, der dækker hovedmenuen og systemets vigtigste elementer.

Systemet er udviklet med henblik på at opfylde de danske lovkrav der stilles til bogføringsprogrammer. Programmet kan afvikles på alt fra standard webhotel, til egen NAS-server.

Det løser dine behov for hurtig, effektiv og selvhjulpen håndtering af fakturering, lagerstyring, momshåndtering m.v.

### **Kerneegenskaber & Styrker**

- 🌍 **Smart Sprog-motor:** TinyCash understøtter automatiseret oversættelse via OpenAI-integration. Systemet scanner automatisk kildekoden for sætninger (på engelsk) og giver dig en central redigeringsside til at administrere oversættelser – nu med 13 forberedte sprog.

- 📦 **Automatiseret Lagerstyring:** Lagerbeholdningen opdateres automatisk, når varer sælges og fakturaer oprettes, hvilket sikrer fuld synkronisering mellem salg og beholdning.

- 💱 **Understøttelse af Flere Valutaer:** Vælg din egen bogføringsvaluta, fakturér kunder i en anden, og lad systemet bogføre automatiske kursgevinster/-tab.

- 🔐 **Privatliv & Ejerskab (Self-Hosted):** Dine data er 100% dine. Ingen afhængighed af tredjeparts sky-løsninger, ingen månedlige abonnementer. Kør alt sikkert på din egen infrastruktur.

- ⚡ **Høj Ydeevne:** Bygget med ren PHP/MySQL-logik, hvilket sikrer hurtig eksekvering, selv på simple delte hosting-miljøer.

- 🛠️ **Udvikler-centreret Arkitektur:** Systemet er nu centraliseret omkring et robust bibliotek (`php2htm.lib.php`), der håndterer alt fra tema-styring (Light/Custom/Dark) til genanvendelige UI-komponenter, hvilket gør koden modulær og let at vedligeholde.

- *Den nuværende kode er skrevet af EV-soft i tæt samarbejde med AI-assistenter.*

### **Vigtigste Funktioner**

- **Kunde- & Leverandørstyring:** Effektiv databasehåndtering af dine forretningsrelationer.

- **Fakturering:** Professionelle, printklare fakturalayouts med fuld fleksibilitet.

- **Lagerstyring:** Realtidssporing med automatisk nedskrivning af beholdning ved salg.

- **Bilagsstyring:** AI-skanning og tolkning af bilag, samt journalisering og elektronisk arkivering af disse.

- **Bankimport & -integration:** Integreret CSV-import fra bank til afstemning af kontobevægelser, samt direkte PSD2-bankintegration (Enable Banking).

- **Backup:** Kontrol af regelmæssig, krypteret backup af data, bilag, mail og andre dokumenter.

- **Integrerede Værktøjer:** Et indbygget værktøjssæt til kodegennemgang, backup, migrering af databaser og avanceret sprogstyring.

- **Tema-valg:** Programmets farvevalg, d.v.s. visuelle temaer (Light, Dark, Custom).

- **Brugerniveau:** I programmets menu kan der ”slukkes” for forskellige punkter svarende til brugerfunktion: Simpel – Avanceret – System ansvarlig. Der er ingen automatik, men en knap i Log ud-feltet, der skifter niveau.

### **Installation & Konfiguration**

1. **Upload:** Upload al programkode fra kilde-arkivet til en PHP-kompatibel server. (Krav: PHP 8.0+)

2. **Database Opsætning**

   - **MySQL:** Opret en MySQL-database via dit kontrolpanel. Opret en database-admin med de fulde rettigheder til databasen, og kør derefter opsætningsscripterne i `db-setup/` (se `db-setup/README.md`) for at oprette skemaet.

   - **Rediger** konfigurationsfilen på `inc/data/env.ini` med dine databaseoplysninger (`host, bruger, kodeord, database`), samt mail- og API-forbindelsesoplysninger. Udelades mail og API, mistes blot mail- og oversættelsesmuligheder.

   - **SQLite:** Kræver ingen opsætning. Blandt filerne er en præopsat database.

3. **API & Sprog-konfiguration**

   - **OpenAI:** For at aktivere smarte oversættelsesforslag og indskanning af bilag, indsæt din `OPENAI_API_KEY` i `inc/data/env.ini`.

   - **Sprog:** Systemet indeholder nu 13 prædefinerede sprog. Du kan tilføje eller rette tekster på redigeringssiden, som du finder under System/Kontrolpanel/Sprog redigering, eller direkte i `/json-data/languages.json`.

<br>
<br>

---

## 🚀 Quick Demo Installation

Want to try TinyCash without setting up a database by hand? Three files are hosted at **[ev-soft.dk/tc-demo-boot](https://ev-soft.dk/tc1/)**:

- **[tinycash.boot.zip](https://ev-soft.dk/tc-demo-boot/tinycash.boot.zip)** – the full application with a working demo database already built in (theme: H.C. Andersen's fairy tales) – no setup wizard needed.
- **[tinycash.boot.php](https://ev-soft.dk/tc-demo-boot/tinycash.boot.php)** – a small installer script that unpacks the zip file for you.
- **[tinycash.boot.txt](https://ev-soft.dk/tc-demo-boot/tinycash.boot.txt)** – the full bilingual (EN/DA) instructions, including how to later switch to MySQL, enable mail/AI features, and more.

**How to use it:**

1. Download both `tinycash.boot.zip` and `tinycash.boot.php` from the links above.
2. Create a **new, empty** folder on your own PHP web server (PHP 8.1+ recommended, with the `zip` extension enabled) – never an existing TinyCash installation.
3. Upload both files into that folder (binary/FTP mode for the `.zip`).
4. Open `tinycash.boot.php` in your browser, e.g. `https://your-domain.example/tinycash.boot.php`.
5. The script unpacks everything automatically and shows a confirmation page.
6. Log in with **admin / eventyr123**, then change the password right away.

⚠️ Once you're done exploring, delete `tinycash.boot.php` and `tinycash.boot.zip` from the server – they should never be left reachable on a live site. See `tinycash.boot.txt` for the full guide, including how to register additional ledgers or convert this demo into a real, production-ready installation.

---

## 🚀 Hurtig Demo-installation

Vil du prøve TinyCash uden selv at skulle sætte en database op? Tre filer ligger på **[ev-soft.dk/tc-demo-boot](https://ev-soft.dk/tc1/)**:

- **[tinycash.boot.zip](https://ev-soft.dk/tc-demo-boot/tinycash.boot.zip)** – hele programmet med en færdig demo-database allerede bygget ind (tema: H.C. Andersens eventyr) – ingen opsætningsguide nødvendig.
- **[tinycash.boot.php](https://ev-soft.dk/tc-demo-boot/tinycash.boot.php)** – et lille installations-script, der udpakker zip-filen for dig.
- **[tinycash.boot.txt](https://ev-soft.dk/tc-demo-boot/tinycash.boot.txt)** – den fulde tosprogede (EN/DA) vejledning, inkl. hvordan du senere skifter til MySQL, aktiverer mail-/AI-funktioner m.m.

**Sådan bruger du det:**

1. Download både `tinycash.boot.zip` og `tinycash.boot.php` fra linkene ovenfor.
2. Opret en **ny, tom** mappe på dit eget PHP-webhotel (PHP 8.1+ anbefales, med "zip"-udvidelsen aktiveret) – aldrig en eksisterende TinyCash-installation.
3. Upload begge filer til den mappe (binær/FTP-tilstand til `.zip`-filen).
4. Åbn `tinycash.boot.php` i din browser, fx `https://dit-domæne.eksempel/tinycash.boot.php`.
5. Scriptet udpakker automatisk alt og viser en bekræftelsesside.
6. Log ind med **admin / eventyr123**, og skift kodeordet med det samme.

⚠️ Når du er færdig med at udforske, så slet `tinycash.boot.php` og `tinycash.boot.zip` fra serveren – de må aldrig ligge tilgængelige på en rigtig, kørende installation. Se `tinycash.boot.txt` for den fulde vejledning, inkl. hvordan du registrerer flere regnskaber eller konverterer denne demo til en rigtig, produktionsklar installation.
