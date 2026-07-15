![TinyCash Dashboard](images/dashboard.png)


# TinyCash 🛠️

**TinyCash is an open-source, web-based accounting system written in PHP, with support for MySQL and SQLite.**

**The system supports over 180 languages, 13 of which are currently pre-configured with a minimal translation covering the main menu and essential system elements.**

**The system is developed to comply with Danish bookkeeping requirements. It can be hosted on anything from a standard web server to a private NAS.**

**It provides a fast, efficient, and self-reliant solution for invoicing, inventory management, VAT handling, and more.**

***Limitation: The system currently supports only one currency.***

### Core Features & Strengths

- 🌍 **Smart Language Engine:** TinyCash now supports automated translation via OpenAI integration. The system automatically scans the source code for English strings and provides a central management page for translations – now with 13 languages prepared.

- 📦 **Automated Inventory Management:** Inventory levels are updated automatically when items are sold and invoices are created, ensuring full synchronization between sales and stock.

- 🔐 **Privacy & Ownership (Self-Hosted):** Your data is 100% yours. No dependency on third-party cloud solutions, no monthly subscriptions. Run everything securely on your own infrastructure.

- ⚡ **High Performance:** Built with clean PHP/MySQL logic, ensuring fast execution even on simple shared hosting environments.

- 🛠️ **Developer-Centric Architecture:** The system is now centralized around a robust library (`php2htm.lib.php`) that handles everything from theme management (Light/Custom/Dark) to reusable UI components, making the code modular and easy to maintain.

- *The current code is written by EV-soft in close collaboration with AI assistants.*

### Key Features

- **Customer & Supplier Management:** Efficient database management of your business relations.

- **Invoicing:** Professional, print-ready invoice layouts with full flexibility.

- **Inventory Management:** Real-time tracking with automatic stock deduction upon sales.

- **Document Management:** AI-assisted scanning, interpretation, bookkeeping, and electronic archiving of documents.

- **Bank Import:** Integrated CSV import from banks for reconciling account statements.

- **Backup:** Centralized control of regular backups for data, documents, mail, and other files.

- **Integrated Tools:** A built-in toolkit for code review, backup, database migration, and advanced language management.

- **Theme Selection:** Customizable visual themes (Light, Dark, Custom).

- **User Levels:** The menu allows you to restrict features based on user roles: Simple – Advanced – System Administrator. Levels can be toggled via a button in the Log-out area.

### Installation & Configuration

1. **Upload:** Upload all files from the source archive to a PHP-compatible server (Requirement: PHP 8.0+).

2. **Database Setup**

   - **MySQL:** Create a MySQL database via your control panel. Create a database admin with full privileges.

   - **Import** `database\_blueprint.sql` to initialize the required tables.

   - **Edit** the configuration file in `inc/env.ini` with your database credentials (host, user, password, database), as well as mail and API connection settings. Note: If mail and API settings are omitted, mail and translation features will be disabled.

   - **SQLite:** No setup required. A pre-configured database is included in the file package.

3. **API & Language Configuration**

   - **OpenAI:** To enable smart translation suggestions and document scanning, insert your `OPENAI\_API\_KEY` into your `inc/env.ini` file.

   - **Language:** The system currently includes 13 pre-defined languages. You can add or edit texts on the management page located under *System/Control Panel/Language Editing*, or directly in `/json-data/languages.json`.



# **TinyCash 🛠️**

**TinyCash er et open source webbaseret regnskabssystem, skrevet i PHP, og  understøttet af MySQL og SQLite.**

Systemet understøtter over 180 sprog, hvoraf 13 sprog i øjeblikket er forberedt med en minimal oversættelse, der dækker hovedmenuen og systemets vigtigste elementer. 

**Systemet er udviklet med henblik på at opfylde de danske lovkrav der stilles til bogføringsprogrammer. Programmet kan afvikles på alt fra standard webhotel, til egen NAS-server.**

**Det løser dine behov for hurtig, effektiv og selvhjulpen håndtering af fakturering,  lagerstyring, momshåndtering m.v.**

Begrænsning: Systemet understøtter p.t. kun brug af én valuta.

### **Kerneegenskaber & Styrker**

- 🌍 **Smart Sprog-motor: TinyCash understøtter nu automatiseret oversættelse via OpenAI-integration.* Systemet scanner automatisk kildekoden for sætninger (på engelsk) og giver dig en central redigeringsside til at administrere oversættelser – nu med 13 forberedte sprog.*

- 📦 **Automatiseret Lagerstyring: Lagerbeholdningen opdateres automatisk, når varer sælges og fakturaer oprettes, hvilket sikrer fuld synkronisering mellem salg og beholdning.**

- 🔐 **Privatliv & Ejerskab (Self-Hosted): Dine data er 100% dine. Ingen afhængighed af tredjeparts sky-løsninger, ingen månedlige abonnementer. Kør alt sikkert på din egen infrastruktur.**

- ⚡ ***Høj Ydeevne: Bygget med ren PHP/MySQL-logik, hvilket sikrer hurtig eksekvering, selv på simple delte hosting-miljøer.**

- 🛠️ **Udvikler-centreret Arkitektur: Systemet er nu centraliseret omkring et robust bibliotek** (*`php2htm.lib.php`*), der håndterer alt fra tema-styring (Light/Custom/Dark) til genanvendelige UI-komponenter, hvilket gør koden modulær og let at vedligeholde.**

- **Den nuværende kode er skrevet af EV-soft i tæt samarbejde med AI-assistenter.**

### **Vigtigste Funktioner**

- **Kunde- & Leverandørstyring: Effektiv databasehåndtering af dine forretningsrelationer.**

- **Fakturering: Professionelle, printklare fakturalayouts med fuld fleksibilitet.**

- **Lagerstyring: Realtidssporing med automatisk nedskrivning af beholdning ved salg.**

- **Bilagsstyring: AI-skanning og tolkning  af bilag, samt journalisering og elektronisk arkivering af disse.**

- **Bankimport: Integreret CSV-import fra bank, til afstemning af kontobevægelser.**

- **Backup: Kontrol af regelmæssig backup af data, bilag, mail og andre dokumenter.**

- **Integrerede Værktøjer: Et indbygget værktøjssæt til kodegennemgang, backup, migrering af databaser og avanceret sprogstyring.**

- **Tema-valg: Programmes farvevalg d.v.s. visuelle temaer (Light, Dark, Custom).**

- **Brugerniveau: I programmets menu kan ”slukkes” for forskellige punkter svarende til brugerfunktion: Simpel – Avanceret – System ansvarlig. Der er ingen automatik, men en knap i Log ud feltet, der skifter niveau.**

### **Installation & Konfiguration**

1. Programkode Upload alle filer fra kilde-arkivet til en PHP-kompatibel server. 	(Krav: PHP 8.0+) 

**2. Database Opsætning**

- MySQL: Opret en MySQL-database via dit kontrolpanel. Opret en database-admin med de fulde rettigheder til databasen.

- **Importer** *`database\\\_blueprint.sql`* for at oprette nødvendige tabeller.


- **Rediger** konfigurationsfilen i `inc/env.ini` med dine databaseoplysninger (`host, bruger, kodeord, database`), samt mail og API-forbindelses oplysninger. Udelades mail og API mistes blot mail og oversættelses-muligheder.


- **SQLite:** Kræver ingen opsætning. Blandt filerne er en præopsat database.


- **API & Sprog:** Indsæt din `OPENAI\_API\_KEY` i `env.ini` for at aktivere AI-sprogoversættelser og bilagsskanning. Sprogfiler kan rettes via systemets indbyggede redigeringsside  eller direkte i `/json-data/languages.json`. 


**3. API & Sprog-konfiguration**

- **OpenAI: For at aktivere smarte oversættelsesforslag, og indskanning af bilag, indsæt din `OPENAI\\\_API\\\_KEY` i din `env.ini` i `inc/`-mappen.**


- **Sprog: Systemet indeholder nu 13 prædefinerede sprog. Du kan tilføje eller rette teks**ter på redigeringssiden, som du finder i System/Kontrolpanel/Sprog redigering.
