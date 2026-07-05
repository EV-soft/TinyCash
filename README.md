![TinyCash Dashboard](images/dashboard.png)

# TinyCash 🛠️

TinyCash is a lightweight, web-based accounting and inventory management system built with PHP and MySQL. The system is designed for small businesses that need a fast, efficient, and self-reliant solution for invoicing and stock tracking without compromising data sovereignty.

### **Key Features & Strengths**

- 🌍 **Smart Localization Engine:** TinyCash now supports automated translation via OpenAI integration. The system automatically scans the source code for `lang()` calls and provides a centralized dashboard for managing translations—now with 13 pre-configured languages.

- 📦 **Automated Inventory Management:** Stock levels are updated automatically when invoices are created, ensuring full synchronization between sales and inventory.

- 🔐 **Privacy & Ownership (Self-Hosted):** Your data is 100% yours. No dependency on third-party cloud solutions, no monthly subscriptions. Run everything securely on your own infrastructure.

- ⚡ **High Performance:** Built with clean PHP/MySQL logic, ensuring fast execution even on simple shared hosting environments.

- 🛠️ **Developer-Centric Architecture:** The system is now centralized around a robust library (*`php2htm.lib.php`*) that handles everything from theme management (Light/Custom/Dark) to reusable UI components, making the code modular and easy to maintain.

### **Main Features**

- **Customer & Supplier Management:** Efficient database management of your business relationships.

- **Invoicing:** Professional, print-ready invoice layouts with full flexibility.

- **Inventory Tracking:** Real-time monitoring with automatic deductions upon sales.

- **Integrated Tools:** A built-in toolkit for code auditing, backups, database migration, and advanced language management.

- **Theme Selection:** A flexible interface that supports various visual themes (Light, Dark, Custom) throughout the entire system.

### **Installation & Configuration**

**1. Source Code** Upload all files from the archive to a PHP-compatible server.

**2. Database Setup**

- Create a MySQL database via your control panel or use the built-in SQLite server.

- Import *`database\_blueprint.sql`* to create the necessary tables.

- Update the configuration file in `/inc/env.ini` with your database credentials (`db\_host`, `db\_user`, `db\_pass`, `db\_name`), as well as mail and API connection details.

**3. API & Language Configuration**

- **OpenAI:** To activate smart translation suggestions and receipt scanning, insert your `OPENAI\_API\_KEY` into your `env.ini` file in the `/inc/` directory.

- **Languages:** The system now contains 13 predefined languages. You can add or edit texts in *`/json-data/languages.json`* directly via the system’s built-in *`translation\_manager.php`*.



---



# TinyCash 🛠️

TinyCash er et letvægts, webbaseret regnskabs- og lagerstyringssystem bygget med PHP og MySQL. Systemet er udviklet til små virksomheder, der har brug for en hurtig, effektiv og selvhjulpen løsning til fakturering og lagerstyring uden at gå på kompromis med datasuverænitet.

### Kerneegenskaber & Styrker

- 🌍 **Smart Lokaliserings-motor:** TinyCash understøtter nu automatiseret oversættelse via ***OpenAI-integration.*** Systemet scanner automatisk kildekoden for `lang()`-kald og giver dig et centralt dashboard til at administrere oversættelser – nu med 13 forberedte sprog.

- 📦 **Automatiseret Lagerstyring:** Lagerbeholdningen opdateres automatisk, når fakturaer oprettes, hvilket sikrer fuld synkronisering mellem salg og beholdning.

- 🔐 **Privatliv & Ejerskab (Self-Hosted):** Dine data er 100% dine. Ingen afhængighed af tredjeparts sky-løsninger, ingen månedlige abonnementer. Kør alt sikkert på din egen infrastruktur.

- ⚡ **Høj Ydeevne:** Bygget med ren PHP/MySQL-logik, hvilket sikrer hurtig eksekvering, selv på simple delte hosting-miljøer.

- 🛠️ **Udvikler-centreret Arkitektur:** Systemet er nu centraliseret omkring et robust bibliotek (*`php2htm.lib.php`*), der håndterer alt fra tema-styring (Light/Custom/Dark) til genanvendelige UI-komponenter, hvilket gør koden modulær og let at vedligeholde.

### Vigtigste Funktioner

- **Kunde- & Leverandørstyring:** Effektiv databasehåndtering af dine forretningsrelationer.

- **Fakturering:** Professionelle, printklare fakturalayouts med fuld fleksibilitet.

- **Lagerstyring:** Realtidssporing med automatisk fratræk ved salg.

- **Integrerede Værktøjer:** Et indbygget værktøjssæt til kodegennemgang, backup, migrering af databaser og avanceret sprogstyring.

- **Tema-valg:** Fleksibelt interface, der understøtter forskellige visuelle temaer (Light, Dark, Custom) på tværs af hele systemet.

### Installation & Konfiguration

**1. Kildekode** Upload alle filer fra arkivet til en PHP-kompatibel server.

**2. Database Opsætning**

- Opret en MySQL-database via dit kontrolpanel, eller benyt den indbyggede SQLite-server.

- Importer *`database\_blueprint.sql`* for at oprette nødvendige tabeller.

- Rediger konfigurationsfilen i  `inc/env.ini` med dine databaseoplysninger (`db\_host`, `db\_user`, `db\_pass`, `db\_name`), samt mail og API-forbindelses oplysninger.

**3. API & Sprog-konfiguration**

- **OpenAI:** For at aktivere smarte oversættelsesforslag, og indskanning af bilag, indsæt din `OPENAI\_API\_KEY` i din `env.ini`  i `inc/`-mappen.

- **Sprog:** Systemet indeholder nu 13 prædefinerede sprog. Du kan tilføje eller rette tekster i `/json-data/languages.json` direkte via systemets indbyggede `translation\_manager.php`.

  
