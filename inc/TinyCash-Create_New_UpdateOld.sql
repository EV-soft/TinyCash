-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Vært: localhost:3306
-- Genereringstid: 15. 06 2026 kl. 14:58:08
-- Serverversion: 10.11.17-MariaDB-cll-lve
-- PHP-version: 8.4.21

--
-- Only Tables and structure. No data
--
SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `evsoftdk_TinyCashControl`
--

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `accounts`
--

CREATE TABLE IF NOT EXISTS `accounts` (
  `acc_id` int(11) NOT NULL,
  `acc_name` varchar(100) NOT NULL,
  `acc_type` enum('asset','liability','revenue','expense') NOT NULL,
  `std_ref_id` int(11) DEFAULT NULL,
  `vat_code` varchar(10) DEFAULT NULL,
  `vat_rate` decimal(5,2) DEFAULT 0.00,
  PRIMARY KEY (`acc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `audit_log`
--

CREATE TABLE IF NOT EXISTS `audit_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `log_date` timestamp NULL DEFAULT current_timestamp(),
  `user_id` int(11) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `table_name` varchar(50) NOT NULL,
  `row_id` int(11) NOT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  PRIMARY KEY (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `bank_statement_temp`
--

CREATE TABLE IF NOT EXISTS `bank_statement_temp` (
  `tmp_id` int(11) NOT NULL AUTO_INCREMENT,
  `import_source` varchar(50) DEFAULT NULL,
  `acc_id` int(11) DEFAULT NULL,
  `trans_date` date NOT NULL,
  `text_val` varchar(255) DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `fee_amount` decimal(15,2) DEFAULT 0.00,
  `is_processed` tinyint(1) DEFAULT 0,
  `raw_hash` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`tmp_id`),
  UNIQUE KEY `raw_hash` (`raw_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `customers`
--

CREATE TABLE IF NOT EXISTS `customers` (
  `cust_id` int(11) NOT NULL AUTO_INCREMENT,
  `cust_name` varchar(100) NOT NULL,
  `cust_contact_person` varchar(100) DEFAULT NULL,
  `cust_address` text DEFAULT NULL,
  `cust_email` varchar(100) DEFAULT NULL,
  `cust_phone` varchar(20) DEFAULT NULL,
  `cust_cvr` varchar(20) DEFAULT NULL,
  `cust_notes` text DEFAULT NULL,
  `cust_payment_days` int(11) DEFAULT 8,
  PRIMARY KEY (`cust_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `expenses`
--

CREATE TABLE IF NOT EXISTS `expenses` (
  `exp_id` int(11) NOT NULL AUTO_INCREMENT,
  `exp_date` date NOT NULL,
  `supplier` varchar(255) NOT NULL,
  `account_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `voucher_no` int(11) NOT NULL,
  `vat_rate` decimal(5,2) DEFAULT 25.00,
  `description` text DEFAULT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `created_by` int(11) NOT NULL,
  `is_cancelled` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`exp_id`),
  UNIQUE KEY `uq_expense_voucher` (`voucher_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers/udløsere `expenses`
--
DELIMITER $$
CREATE TRIGGER `tg_prevent_expense_delete` BEFORE DELETE ON `expenses` FOR EACH ROW BEGIN
    IF OLD.voucher_no IS NOT NULL AND OLD.voucher_no != '' THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Forbidden: Expenses with an assigned voucher number cannot be permanently deleted! Use soft-delete.';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `invoices`
--

CREATE TABLE IF NOT EXISTS `invoices` (
  `inv_id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_no` int(11) DEFAULT NULL,
  `cust_id` int(11) NOT NULL,
  `inv_date` date NOT NULL,
  `inv_due_date` date NOT NULL,
  `delivery_address` text DEFAULT NULL,
  `currency` varchar(10) DEFAULT 'DKK',
  `inv_status` enum('draft','paid','sent','void') DEFAULT 'draft',
  `inv_note` text DEFAULT NULL,
  PRIMARY KEY (`inv_id`),
  UNIQUE KEY `invoice_no` (`invoice_no`),
  KEY `cust_id` (`cust_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers/udløsere `invoices`
--
DELIMITER $$
CREATE TRIGGER `after_invoice_sent` AFTER UPDATE ON `invoices` FOR EACH ROW BEGIN
    -- Træk kun fra lageret, hvis status skifter til 'sent' (eller 'paid')
    IF NEW.inv_status IN ('sent', 'paid') AND OLD.inv_status = 'draft' THEN
        UPDATE products p
        JOIN invoice_lines il ON p.prod_id = il.prod_id
        SET p.prod_stock = p.prod_stock - il.quantity
        WHERE il.inv_id = NEW.inv_id;
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `tg_prevent_invoice_delete` BEFORE DELETE ON `invoices` FOR EACH ROW BEGIN
    IF OLD.invoice_no IS NOT NULL AND OLD.invoice_no != '' AND OLD.invoice_no != '---' THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Forbidden: Posted invoices containing an invoice number cannot be permanently deleted!';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `invoice_lines`
--

CREATE TABLE IF NOT EXISTS `invoice_lines` (
  `line_id` int(11) NOT NULL AUTO_INCREMENT,
  `inv_id` int(11) NOT NULL,
  `line_text` varchar(255) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `price_each` decimal(15,2) NOT NULL,
  `acc_id` int(11) DEFAULT 1000,
  `line_vat_rate` decimal(5,2) DEFAULT 25.00,
  `prod_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`line_id`),
  KEY `inv_id` (`inv_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `journal`
--

CREATE TABLE IF NOT EXISTS `journal` (
  `jou_id` int(11) NOT NULL AUTO_INCREMENT,
  `jou_date` date NOT NULL,
  `voucher_no` int(11) NOT NULL,
  `jou_text` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `is_cancelled` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`jou_id`),
  KEY `idx_journal_voucher` (`voucher_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers/udløsere `journal`
--
DELIMITER $$
CREATE TRIGGER `trg_journal_lock_insert` BEFORE INSERT ON `journal` FOR EACH ROW BEGIN
    DECLARE lock_date DATE;
    
    SELECT CAST(setting_value AS DATE) INTO lock_date 
    FROM settings 
    WHERE setting_key = 'accounting_lock_date' 
    LIMIT 1;
    
    -- Tjekker mod created_at (Skift navnet hvis din datokolonne hedder noget andet)
    IF lock_date IS NOT NULL AND NEW.created_at <= lock_date THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Accounting year is closed. Cannot insert transactions in a locked period.';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_journal_lock_update` BEFORE UPDATE ON `journal` FOR EACH ROW BEGIN
    DECLARE lock_date DATE;
    
    SELECT CAST(setting_value AS DATE) INTO lock_date 
    FROM settings 
    WHERE setting_key = 'accounting_lock_date' 
    LIMIT 1;
    
    -- Tjekker mod created_at på den gamle postering, der forsøges ændret
    IF lock_date IS NOT NULL AND OLD.created_at <= lock_date THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Accounting year is closed. Cannot update transactions in a locked period.';
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_protect_historical_data` BEFORE DELETE ON `journal` FOR EACH ROW BEGIN
    IF OLD.jou_date > DATE_SUB(NOW(), INTERVAL 5 YEAR) THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Forbidden: Accounting data within the last 5 years cannot be deleted according to the Bookkeeping Act!';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `layout_settings`
--

CREATE TABLE IF NOT EXISTS `layout_settings` (
  `element_id` varchar(50) NOT NULL,
  `pos_x` float DEFAULT 0,
  `pos_y` float DEFAULT 0,
  `is_visible` tinyint(1) DEFAULT 1,
  `width_mm` float DEFAULT 180,
  PRIMARY KEY (`element_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `ledger`
--

CREATE TABLE IF NOT EXISTS `ledger` (
  `led_id` int(11) NOT NULL AUTO_INCREMENT,
  `jou_id` int(11) NOT NULL,
  `acc_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  PRIMARY KEY (`led_id`),
  KEY `jou_id` (`jou_id`),
  KEY `acc_id` (`acc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `login_log`
--

CREATE TABLE IF NOT EXISTS `login_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `logged_username` varchar(100) DEFAULT NULL,
  `login_time` timestamp NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `status` enum('Success','Failed') DEFAULT 'Success',
  `user_agent` text DEFAULT NULL,
  PRIMARY KEY (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `products`
--

CREATE TABLE IF NOT EXISTS `products` (
  `prod_id` int(11) NOT NULL AUTO_INCREMENT,
  `prod_sku` varchar(50) DEFAULT NULL,
  `prod_name` varchar(100) DEFAULT NULL,
  `prod_stock` int(11) DEFAULT 0,
  `prod_min_stock` int(11) DEFAULT 5,
  `prod_price` decimal(10,2) DEFAULT NULL,
  `acc_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`prod_id`),
  UNIQUE KEY `prod_sku` (`prod_sku`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `settings`
--

CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `std_accounts`
--

CREATE TABLE IF NOT EXISTS `std_accounts` (
  `std_id` int(11) NOT NULL,
  `std_name` varchar(255) NOT NULL,
  `std_type` enum('asset','liabilities','revenue','costs') NOT NULL,
  PRIMARY KEY (`std_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `transactions`
--

CREATE TABLE IF NOT EXISTS `transactions` (
  `trans_id` int(11) NOT NULL AUTO_INCREMENT,
  `trans_date` date NOT NULL,
  `trans_text` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `vat_amount` decimal(10,2) DEFAULT 0.00,
  `acc_id` int(11) NOT NULL,
  `offset_acc_id` int(11) DEFAULT 5000,
  `attachment_path` varchar(255) DEFAULT NULL,
  `attachment_type` enum('expense','revenue','bank','other') DEFAULT 'other',
  PRIMARY KEY (`trans_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `users`
--

CREATE TABLE IF NOT EXISTS `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `user_role` enum('admin','user') NOT NULL DEFAULT 'user',
  `user_level` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Struktur-dump for tabellen `vat_codes`
--

CREATE TABLE IF NOT EXISTS `vat_codes` (
  `vat_id` varchar(10) NOT NULL,
  `vat_name` varchar(50) DEFAULT NULL,
  `vat_rate` decimal(5,2) DEFAULT NULL,
  `vat_account` int(11) DEFAULT NULL,
  PRIMARY KEY (`vat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Begrænsninger for dumpede tabeller
--

--
-- Begrænsninger for tabel `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`cust_id`) REFERENCES `customers` (`cust_id`);

--
-- Begrænsninger for tabel `invoice_lines`
--
ALTER TABLE `invoice_lines`
  ADD CONSTRAINT `invoice_lines_ibfk_1` FOREIGN KEY (`inv_id`) REFERENCES `invoices` (`inv_id`) ON DELETE CASCADE;

--
-- Begrænsninger for tabel `ledger`
--
ALTER TABLE `ledger`
  ADD CONSTRAINT `ledger_ibfk_1` FOREIGN KEY (`jou_id`) REFERENCES `journal` (`jou_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ledger_ibfk_2` FOREIGN KEY (`acc_id`) REFERENCES `accounts` (`acc_id`);
SET FOREIGN_KEY_CHECKS=1;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
