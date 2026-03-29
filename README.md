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

## Installation
1. Upload all files to your PHP server.
2. Create a MySQL database and import the `database_blueprint.sql` schema.
3. Create a `db_connect.inc.php` file based on the following template:
   ```php
   <?php
   $conn = mysqli_connect("localhost", "your_user", "your_password", "your_database");
