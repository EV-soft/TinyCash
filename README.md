# TinyCash Control 🛠️

TinyCash is a lightweight, web-based accounting and inventory management system built with PHP and MySQL. It is designed for small businesses that need a simple and efficient solution for invoicing and stock tracking.

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
