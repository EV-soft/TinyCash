![TinyCash Dashboard](images/dashboard.png
)

# TinyCash Control 🛠️

TinyCash is a lightweight, web-based accounting and inventory management system built with PHP and MySQL. It is designed for small businesses that need a simple and efficient solution for invoicing and stock tracking.

**Key Features & Qualities**

🌍 Built-in Language Switching: Seamlessly switch between languages. The system is designed for easy localization, allowing you to adapt the interface to your preferred region.

📦 Automated Inventory Control: Stop manual tracking. Stock levels are automatically updated whenever an invoice is created, ensuring your product counts are always accurate.

🔐 Privacy-First (Self-Hosted): You own your data. No third-party cloud dependencies or monthly subscriptions. Run everything on your own infrastructure.

⚡ Lightweight & Fast: Built with clean PHP/MySQL logic, ensuring high performance even on basic hosting environments.

🛠️ Developer Friendly: A modular codebase that is easy to audit, extend, and customize for specific business needs.

## Features
- **Customer Management:** Create and manage your client database.
- **Inventory Tracking:** Real-time stock management with automatic updates during invoicing.
- **Invoicing:** Create professional invoices with PDF/Print-ready views.
- **Collector Tool:** A built-in utility in `/tools/` for easy code backup and project snapshots.
- **Try and feel:** [Live demo](https://ev-soft.dk/tcash/about.page.php)


## Installation & Configuration

1. **Source files:**
   - Upload all files to your PHP server.
   
2. **Database Setup:**
   - Create a MySQL database and import `database_blueprint.sql`.
   - Rename `db_connect.doc.php` to `db_connect.inc.php` and update it with your credentials.
   - Look at db_connect.doc.php for more...

3. **Company Profile (Static Data):**
   - TinyCash uses a JSON file for static company information (name, address, VAT number) to reduce database overhead for non-changing data.
   - Locate `/json-data/stamdata.json`.
   - Update this file with your own business details.

4. **Language:**
   - The system defaults to English but can be switched via the built-in language selector.
