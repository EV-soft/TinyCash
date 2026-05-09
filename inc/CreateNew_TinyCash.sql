SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;


CREATE TABLE `accounts` (
  `acc_id` int(11) NOT NULL,
  `acc_name` varchar(100) NOT NULL,
  `acc_type` enum('asset','liability','revenue','expense') NOT NULL,
  `std_ref_id` int(11) DEFAULT NULL,
  `vat_code` varchar(10) DEFAULT NULL,
  `vat_rate` decimal(5,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `bank_statement_temp` (
  `tmp_id` int(11) NOT NULL,
  `import_source` varchar(50) DEFAULT NULL,
  `acc_id` int(11) DEFAULT NULL,
  `trans_date` date NOT NULL,
  `text_val` varchar(255) DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `fee_amount` decimal(15,2) DEFAULT 0.00,
  `is_processed` tinyint(1) DEFAULT 0,
  `raw_hash` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `customers` (
  `cust_id` int(11) NOT NULL,
  `cust_name` varchar(100) NOT NULL,
  `cust_contact_person` varchar(100) DEFAULT NULL,
  `cust_address` text DEFAULT NULL,
  `cust_email` varchar(100) DEFAULT NULL,
  `cust_phone` varchar(20) DEFAULT NULL,
  `cust_cvr` varchar(20) DEFAULT NULL,
  `cust_notes` text DEFAULT NULL,
  `cust_payment_days` int(11) DEFAULT 8
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `expenses` (
  `exp_id` int(11) NOT NULL,
  `exp_date` date NOT NULL,
  `supplier` varchar(255) NOT NULL,
  `account_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `vat_rate` decimal(5,2) DEFAULT 25.00,
  `description` text DEFAULT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `invoices` (
  `inv_id` int(11) NOT NULL,
  `invoice_no` int(11) DEFAULT NULL,
  `cust_id` int(11) NOT NULL,
  `inv_date` date NOT NULL,
  `inv_due_date` date NOT NULL,
  `delivery_address` text DEFAULT NULL,
  `currency` varchar(10) DEFAULT 'DKK',
  `inv_status` enum('draft','paid','sent','void') DEFAULT 'draft',
  `inv_note` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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

CREATE TABLE `invoice_lines` (
  `line_id` int(11) NOT NULL,
  `inv_id` int(11) NOT NULL,
  `line_text` varchar(255) NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `price_each` decimal(15,2) NOT NULL,
  `acc_id` int(11) DEFAULT 1000,
  `line_vat_rate` decimal(5,2) DEFAULT 25.00,
  `prod_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `journal` (
  `jou_id` int(11) NOT NULL,
  `jou_date` date NOT NULL,
  `jou_text` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `ledger` (
  `led_id` int(11) NOT NULL,
  `jou_id` int(11) NOT NULL,
  `acc_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `login_log` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `logged_username` varchar(100) DEFAULT NULL,
  `login_time` timestamp NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `status` enum('Success','Failed') DEFAULT 'Success',
  `user_agent` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `products` (
  `prod_id` int(11) NOT NULL,
  `prod_sku` varchar(50) DEFAULT NULL,
  `prod_name` varchar(100) DEFAULT NULL,
  `prod_stock` int(11) DEFAULT 0,
  `prod_min_stock` int(11) DEFAULT 5,
  `prod_price` decimal(10,2) DEFAULT NULL,
  `acc_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `std_accounts` (
  `std_id` int(11) NOT NULL,
  `std_name` varchar(255) NOT NULL,
  `std_type` enum('asset','liabilities','revenue','costs') NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `transactions` (
  `trans_id` int(11) NOT NULL,
  `trans_date` date NOT NULL,
  `trans_text` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `vat_amount` decimal(10,2) DEFAULT 0.00,
  `acc_id` int(11) NOT NULL,
  `offset_acc_id` int(11) DEFAULT 5000,
  `attachment_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `user_role` enum('admin','user') NOT NULL DEFAULT 'user'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE TABLE `vat_codes` (
  `vat_id` varchar(10) NOT NULL,
  `vat_name` varchar(50) DEFAULT NULL,
  `vat_rate` decimal(5,2) DEFAULT NULL,
  `vat_account` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;


ALTER TABLE `accounts`
  ADD PRIMARY KEY (`acc_id`);

ALTER TABLE `bank_statement_temp`
  ADD PRIMARY KEY (`tmp_id`),
  ADD UNIQUE KEY `raw_hash` (`raw_hash`);

ALTER TABLE `customers`
  ADD PRIMARY KEY (`cust_id`);

ALTER TABLE `expenses`
  ADD PRIMARY KEY (`exp_id`);

ALTER TABLE `invoices`
  ADD PRIMARY KEY (`inv_id`),
  ADD UNIQUE KEY `invoice_no` (`invoice_no`),
  ADD KEY `cust_id` (`cust_id`);

ALTER TABLE `invoice_lines`
  ADD PRIMARY KEY (`line_id`),
  ADD KEY `inv_id` (`inv_id`);

ALTER TABLE `journal`
  ADD PRIMARY KEY (`jou_id`);

ALTER TABLE `ledger`
  ADD PRIMARY KEY (`led_id`),
  ADD KEY `jou_id` (`jou_id`),
  ADD KEY `acc_id` (`acc_id`);

ALTER TABLE `login_log`
  ADD PRIMARY KEY (`log_id`);

ALTER TABLE `products`
  ADD PRIMARY KEY (`prod_id`),
  ADD UNIQUE KEY `prod_sku` (`prod_sku`);

ALTER TABLE `settings`
  ADD PRIMARY KEY (`setting_key`);

ALTER TABLE `std_accounts`
  ADD PRIMARY KEY (`std_id`);

ALTER TABLE `transactions`
  ADD PRIMARY KEY (`trans_id`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

ALTER TABLE `vat_codes`
  ADD PRIMARY KEY (`vat_id`);


ALTER TABLE `bank_statement_temp`
  MODIFY `tmp_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `customers`
  MODIFY `cust_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `expenses`
  MODIFY `exp_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `invoices`
  MODIFY `inv_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `invoice_lines`
  MODIFY `line_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `journal`
  MODIFY `jou_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `ledger`
  MODIFY `led_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `login_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `products`
  MODIFY `prod_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `transactions`
  MODIFY `trans_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT;


ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`cust_id`) REFERENCES `customers` (`cust_id`);

ALTER TABLE `invoice_lines`
  ADD CONSTRAINT `invoice_lines_ibfk_1` FOREIGN KEY (`inv_id`) REFERENCES `invoices` (`inv_id`) ON DELETE CASCADE;

ALTER TABLE `ledger`
  ADD CONSTRAINT `ledger_ibfk_1` FOREIGN KEY (`jou_id`) REFERENCES `journal` (`jou_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ledger_ibfk_2` FOREIGN KEY (`acc_id`) REFERENCES `accounts` (`acc_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
