<?php # /db-setup/create_all_tables.core.php v:1.3.0 d:2026-08-30 i:evs
# Definerer create_all_tables_for($conn, string $db_type): void - opretter
# det fulde TinyCash-skema (manglende tabeller) på den medsendte forbindelse.
# $is_sqlite afgøres af den medsendte $db_type-parameter, ikke DB::is_sqlite()
# (som stadig læser globale $db_type internt) - så funktionen kan kaldes
# korrekt mod et ANDET regnskab end det der ambient er forbundet, fx fra
# db-setup/provision_account.php's isolerede forbindelse (flere-regnskaber-
# funktionen, se planens §"Central arkitektonisk begrænsning"). Kolonnen
# invoices.inv_status_new er bevidst udeladt - en helt død kolonne, ikke
# læst eller skrevet noget sted i kodebasen udenfor selve skemadefinitionerne,
# formentlig et efterladt spor fra et påbegyndt, aldrig afsluttet forsøg på
# at omdøbe/omtype inv_status. Ikke retroaktiv - allerede installerede
# databaser beholder kolonnen (harmløs at lade stå).
/* ==========================================================================
   KERNE-LOGIK udtrukket fra create_all_tables.php (v1.3.0), så den samme
   tabel-opsætning kan genbruges af FLERE forskellige "kaldere" uden at
   driften kan afvige mellem dem:
     1) create_all_tables.php     - det almindelige admin-gatede script.
     2) bootstrap_index.php       - den midlertidige førstegangs-opsætnings-
        side i ZIP-fresh-install-pakken, som (bevidst) IKKE kræver admin-
        login, fordi ingen admin-bruger findes endnu på det tidspunkt den
        kører.
     3) db-setup/provision_account.php - bygger skemaet på et NYT regnskabs
        isolerede forbindelse (flere-regnskaber-funktionen).

   FORUDSÆTNING: kalderen medsender en allerede åben $conn samt dens $db_type
   ('sqlite'/'mysql'). Ingen auth-tjek her - det er kalderens ansvar at sikre
   sig at det er en legitim kørsel.

   100% SIKKERT AT GENKØRE: bruger udelukkende CREATE TABLE IF NOT EXISTS.
   ========================================================================== */

function create_all_tables_for($conn, string $db_type): void {

echo "Opretter fulde TinyCash-skema (manglende tabeller)\n";
echo "Motor: $db_type\n";
echo str_repeat('-', 50) . "\n";

$is_sqlite = ($db_type === 'sqlite');

$tables_sqlite = [
'users' => "CREATE TABLE IF NOT EXISTS users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    user_role TEXT NOT NULL DEFAULT 'user',
    user_level INTEGER NOT NULL DEFAULT 1,
    totp_secret TEXT,
    totp_enabled INTEGER DEFAULT 0,
    totp_recovery_codes TEXT
)",
'std_accounts' => "CREATE TABLE IF NOT EXISTS std_accounts (
    std_id INTEGER PRIMARY KEY AUTOINCREMENT,
    std_name TEXT,
    std_type TEXT
)",
'accounts' => "CREATE TABLE IF NOT EXISTS accounts (
    acc_id INTEGER PRIMARY KEY,
    acc_name TEXT NOT NULL,
    acc_type TEXT,
    std_ref_id INTEGER,
    vat_code TEXT,
    vat_rate NUMERIC
)",
'vat_codes' => "CREATE TABLE IF NOT EXISTS vat_codes (
    vat_id TEXT PRIMARY KEY,
    vat_name TEXT,
    vat_rate NUMERIC,
    vat_account INTEGER
)",
'customers' => "CREATE TABLE IF NOT EXISTS customers (
    cust_id INTEGER PRIMARY KEY AUTOINCREMENT,
    cust_name TEXT NOT NULL,
    cust_contact_person TEXT,
    cust_address TEXT,
    cust_email TEXT,
    cust_phone TEXT,
    cust_cvr TEXT,
    cust_notes TEXT,
    cust_payment_days INTEGER DEFAULT 8
)",
'products' => "CREATE TABLE IF NOT EXISTS products (
    prod_id INTEGER PRIMARY KEY AUTOINCREMENT,
    prod_sku TEXT,
    prod_name TEXT NOT NULL,
    prod_stock INTEGER DEFAULT 0,
    prod_min_stock INTEGER DEFAULT 5,
    prod_price NUMERIC DEFAULT 0,
    acc_id INTEGER
)",
'invoices' => "CREATE TABLE IF NOT EXISTS invoices (
    inv_id INTEGER PRIMARY KEY AUTOINCREMENT,
    invoice_no INTEGER,
    cust_id INTEGER,
    inv_date DATE,
    inv_due_date DATE,
    delivery_address TEXT,
    currency TEXT DEFAULT 'DKK',
    inv_status TEXT DEFAULT 'draft',
    inv_note TEXT,
    jou_id INTEGER,
    cust_reference TEXT,
    proj_id INTEGER,
    orig_currency VARCHAR(3),
    exch_rate NUMERIC,
    credit_ref INTEGER,
    reminder_sent_at TIMESTAMP,
    reminder_count INTEGER DEFAULT 0
)",
'invoice_lines' => "CREATE TABLE IF NOT EXISTS invoice_lines (
    line_id INTEGER PRIMARY KEY AUTOINCREMENT,
    inv_id INTEGER,
    line_text TEXT,
    quantity NUMERIC,
    price_each NUMERIC,
    acc_id INTEGER,
    line_vat_rate NUMERIC,
    prod_id INTEGER,
    currency VARCHAR(3) DEFAULT 'DKK',
    proj_id INTEGER,
    orig_currency VARCHAR(3),
    orig_amount NUMERIC,
    exch_rate NUMERIC
)",
'expenses' => "CREATE TABLE IF NOT EXISTS expenses (
    exp_id INTEGER PRIMARY KEY AUTOINCREMENT,
    exp_date DATE,
    supplier TEXT,
    account_id INTEGER,
    amount NUMERIC,
    voucher_no INTEGER,
    vat_rate NUMERIC,
    description TEXT,
    attachment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INTEGER,
    is_cancelled INTEGER DEFAULT 0,
    currency VARCHAR(3) DEFAULT 'DKK',
    proj_id INTEGER,
    exp_type TEXT NOT NULL DEFAULT 'expense',
    orig_currency VARCHAR(3),
    orig_amount NUMERIC,
    exch_rate NUMERIC,
    no_attachment_reason TEXT,
    cancelled_by INTEGER
)",
'journal' => "CREATE TABLE IF NOT EXISTS journal (
    jou_id INTEGER PRIMARY KEY AUTOINCREMENT,
    jou_date DATE,
    voucher_no INTEGER,
    jou_text TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_cancelled INTEGER DEFAULT 0,
    trans_type VARCHAR(20),
    currency VARCHAR(3) DEFAULT 'DKK',
    proj_id INTEGER
)",
'ledger' => "CREATE TABLE IF NOT EXISTS ledger (
    led_id INTEGER PRIMARY KEY AUTOINCREMENT,
    jou_id INTEGER,
    acc_id INTEGER,
    amount NUMERIC,
    voucher_no INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_id INTEGER
)",
'voucher_counter' => "CREATE TABLE IF NOT EXISTS voucher_counter (
    id INTEGER PRIMARY KEY,
    next_no INTEGER NOT NULL
)",
'invoice_no_counter' => "CREATE TABLE IF NOT EXISTS invoice_no_counter (
    id INTEGER PRIMARY KEY,
    next_no INTEGER NOT NULL
)",
'invoice_payments' => "CREATE TABLE IF NOT EXISTS invoice_payments (
    payment_id INTEGER PRIMARY KEY AUTOINCREMENT,
    inv_id INTEGER,
    payment_date DATE,
    amount NUMERIC,
    note TEXT,
    created_by INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",
'recurring_invoices' => "CREATE TABLE IF NOT EXISTS recurring_invoices (
    recur_id INTEGER PRIMARY KEY AUTOINCREMENT,
    cust_id INTEGER,
    interval_type TEXT NOT NULL DEFAULT 'monthly',
    next_run_date DATE,
    last_run_date DATE,
    is_active INTEGER DEFAULT 1,
    inv_due_days INTEGER DEFAULT 8,
    cust_reference TEXT,
    inv_note TEXT,
    delivery_address TEXT,
    proj_id INTEGER,
    created_by INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",
'recurring_invoice_lines' => "CREATE TABLE IF NOT EXISTS recurring_invoice_lines (
    rline_id INTEGER PRIMARY KEY AUTOINCREMENT,
    recur_id INTEGER,
    line_text TEXT,
    quantity NUMERIC,
    price_each NUMERIC,
    line_vat_rate NUMERIC,
    prod_id INTEGER,
    proj_id INTEGER
)",
'bank_connections' => "CREATE TABLE IF NOT EXISTS bank_connections (
    conn_id INTEGER PRIMARY KEY AUTOINCREMENT,
    institution_id TEXT,
    institution_name TEXT,
    institution_country TEXT,
    requisition_id TEXT,
    gc_account_id TEXT,
    state_token TEXT,
    acc_id INTEGER,
    status TEXT DEFAULT 'CR',
    last_sync_at TIMESTAMP,
    created_by INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",
'menu_visibility' => "CREATE TABLE IF NOT EXISTS menu_visibility (
    item_key TEXT PRIMARY KEY,
    level_1 INTEGER NOT NULL DEFAULT 1,
    level_2 INTEGER NOT NULL DEFAULT 1,
    level_3 INTEGER NOT NULL DEFAULT 1
)",
'projects' => "CREATE TABLE IF NOT EXISTS projects (
    proj_id INTEGER PRIMARY KEY AUTOINCREMENT,
    proj_no TEXT,
    cust_id INTEGER,
    proj_start DATE,
    proj_stop DATE,
    proj_description TEXT,
    proj_concept TEXT,
    is_active INTEGER DEFAULT 1,
    note_expenses TEXT,
    note_income TEXT,
    note_general TEXT
)",
'fiscal_years' => "CREATE TABLE IF NOT EXISTS fiscal_years (
    year_id INTEGER PRIMARY KEY AUTOINCREMENT,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_closed INTEGER NOT NULL DEFAULT 0,
    closed_at TIMESTAMP,
    closed_by INTEGER,
    closing_jou_id INTEGER,
    equity_acc_id INTEGER,
    net_result NUMERIC
)",
'transactions' => "CREATE TABLE IF NOT EXISTS transactions (
    trans_id INTEGER PRIMARY KEY AUTOINCREMENT,
    trans_date DATE,
    trans_text TEXT,
    amount NUMERIC,
    vat_amount NUMERIC,
    acc_id INTEGER,
    offset_acc_id INTEGER,
    attachment_path TEXT,
    attachment_type TEXT,
    currency VARCHAR(3) DEFAULT 'DKK',
    proj_id INTEGER
)",
'bank_statement_temp' => "CREATE TABLE IF NOT EXISTS bank_statement_temp (
    tmp_id INTEGER PRIMARY KEY AUTOINCREMENT,
    import_source TEXT,
    acc_id INTEGER,
    trans_date DATE,
    text_val TEXT,
    amount NUMERIC,
    fee_amount NUMERIC,
    is_processed INTEGER DEFAULT 0,
    raw_hash TEXT
)",
'settings' => "CREATE TABLE IF NOT EXISTS settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT
)",
'login_log' => "CREATE TABLE IF NOT EXISTS login_log (
    log_id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    logged_username TEXT,
    login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address TEXT,
    status TEXT,
    user_agent TEXT
)",
'audit_log' => "CREATE TABLE IF NOT EXISTS audit_log (
    log_id INTEGER PRIMARY KEY AUTOINCREMENT,
    log_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_id INTEGER,
    action_type TEXT,
    table_name TEXT,
    row_id INTEGER,
    old_values TEXT,
    new_values TEXT
)",
'layout_settings' => "CREATE TABLE IF NOT EXISTS layout_settings (
    element_id TEXT PRIMARY KEY,
    pos_x REAL,
    pos_y REAL,
    is_visible INTEGER DEFAULT 1,
    width_mm REAL
)",
'tbl_maillog' => "CREATE TABLE IF NOT EXISTS tbl_maillog (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    faktura_id INTEGER,
    modtager_mail TEXT,
    afsendt_dato DATETIME,
    status TEXT,
    server_respons TEXT
)",
'system_migrations' => "CREATE TABLE IF NOT EXISTS system_migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    migration_key TEXT,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)",
];

$tables_mysql = [
'users' => "CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    user_role VARCHAR(20) NOT NULL DEFAULT 'user',
    user_level INT NOT NULL DEFAULT 1,
    totp_secret VARCHAR(64),
    totp_enabled TINYINT(1) DEFAULT 0,
    totp_recovery_codes TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'std_accounts' => "CREATE TABLE IF NOT EXISTS std_accounts (
    std_id INT AUTO_INCREMENT PRIMARY KEY,
    std_name VARCHAR(255),
    std_type VARCHAR(50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'accounts' => "CREATE TABLE IF NOT EXISTS accounts (
    acc_id INT PRIMARY KEY,
    acc_name VARCHAR(255) NOT NULL,
    acc_type VARCHAR(50),
    std_ref_id INT,
    vat_code VARCHAR(10),
    vat_rate DECIMAL(5,2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'vat_codes' => "CREATE TABLE IF NOT EXISTS vat_codes (
    vat_id VARCHAR(10) PRIMARY KEY,
    vat_name VARCHAR(100),
    vat_rate DECIMAL(5,2),
    vat_account INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'customers' => "CREATE TABLE IF NOT EXISTS customers (
    cust_id INT AUTO_INCREMENT PRIMARY KEY,
    cust_name VARCHAR(255) NOT NULL,
    cust_contact_person VARCHAR(255),
    cust_address TEXT,
    cust_email VARCHAR(255),
    cust_phone VARCHAR(50),
    cust_cvr VARCHAR(20),
    cust_notes TEXT,
    cust_payment_days INT DEFAULT 8
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'products' => "CREATE TABLE IF NOT EXISTS products (
    prod_id INT AUTO_INCREMENT PRIMARY KEY,
    prod_sku VARCHAR(50),
    prod_name VARCHAR(255) NOT NULL,
    prod_stock INT DEFAULT 0,
    prod_min_stock INT DEFAULT 5,
    prod_price DECIMAL(12,2) DEFAULT 0,
    acc_id INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'invoices' => "CREATE TABLE IF NOT EXISTS invoices (
    inv_id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_no INT,
    cust_id INT,
    inv_date DATE,
    inv_due_date DATE,
    delivery_address TEXT,
    currency VARCHAR(3) DEFAULT 'DKK',
    inv_status VARCHAR(20) DEFAULT 'draft',
    inv_note TEXT,
    jou_id INT,
    cust_reference VARCHAR(100),
    proj_id INT,
    orig_currency VARCHAR(3),
    exch_rate DECIMAL(12,6),
    credit_ref INT,
    reminder_sent_at DATETIME,
    reminder_count INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'invoice_lines' => "CREATE TABLE IF NOT EXISTS invoice_lines (
    line_id INT AUTO_INCREMENT PRIMARY KEY,
    inv_id INT,
    line_text VARCHAR(255),
    quantity DECIMAL(12,2),
    price_each DECIMAL(12,2),
    acc_id INT,
    line_vat_rate DECIMAL(5,2),
    prod_id INT,
    currency VARCHAR(3) DEFAULT 'DKK',
    proj_id INT,
    orig_currency VARCHAR(3),
    orig_amount DECIMAL(12,2),
    exch_rate DECIMAL(12,6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'expenses' => "CREATE TABLE IF NOT EXISTS expenses (
    exp_id INT AUTO_INCREMENT PRIMARY KEY,
    exp_date DATE,
    supplier VARCHAR(255),
    account_id INT,
    amount DECIMAL(12,2),
    voucher_no INT,
    vat_rate DECIMAL(5,2),
    description TEXT,
    attachment VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    is_cancelled TINYINT DEFAULT 0,
    currency VARCHAR(3) DEFAULT 'DKK',
    proj_id INT,
    exp_type VARCHAR(10) NOT NULL DEFAULT 'expense',
    orig_currency VARCHAR(3),
    orig_amount DECIMAL(12,2),
    exch_rate DECIMAL(12,6),
    no_attachment_reason TEXT,
    cancelled_by INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'journal' => "CREATE TABLE IF NOT EXISTS journal (
    jou_id INT AUTO_INCREMENT PRIMARY KEY,
    jou_date DATE,
    voucher_no INT,
    jou_text VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_cancelled TINYINT DEFAULT 0,
    trans_type VARCHAR(20),
    currency VARCHAR(3) DEFAULT 'DKK',
    proj_id INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'ledger' => "CREATE TABLE IF NOT EXISTS ledger (
    led_id INT AUTO_INCREMENT PRIMARY KEY,
    jou_id INT,
    acc_id INT,
    amount DECIMAL(12,2),
    voucher_no INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_id INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'voucher_counter' => "CREATE TABLE IF NOT EXISTS voucher_counter (
    id INT PRIMARY KEY,
    next_no INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'invoice_no_counter' => "CREATE TABLE IF NOT EXISTS invoice_no_counter (
    id INT PRIMARY KEY,
    next_no INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'invoice_payments' => "CREATE TABLE IF NOT EXISTS invoice_payments (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    inv_id INT,
    payment_date DATE,
    amount DECIMAL(12,2),
    note VARCHAR(255),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'recurring_invoices' => "CREATE TABLE IF NOT EXISTS recurring_invoices (
    recur_id INT AUTO_INCREMENT PRIMARY KEY,
    cust_id INT,
    interval_type VARCHAR(20) NOT NULL DEFAULT 'monthly',
    next_run_date DATE,
    last_run_date DATE,
    is_active TINYINT(1) DEFAULT 1,
    inv_due_days INT DEFAULT 8,
    cust_reference VARCHAR(100),
    inv_note TEXT,
    delivery_address TEXT,
    proj_id INT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'recurring_invoice_lines' => "CREATE TABLE IF NOT EXISTS recurring_invoice_lines (
    rline_id INT AUTO_INCREMENT PRIMARY KEY,
    recur_id INT,
    line_text VARCHAR(255),
    quantity DECIMAL(12,2),
    price_each DECIMAL(12,2),
    line_vat_rate DECIMAL(5,2),
    prod_id INT,
    proj_id INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'bank_connections' => "CREATE TABLE IF NOT EXISTS bank_connections (
    conn_id INT AUTO_INCREMENT PRIMARY KEY,
    institution_id VARCHAR(100),
    institution_name VARCHAR(150),
    institution_country VARCHAR(2),
    requisition_id VARCHAR(100),
    gc_account_id VARCHAR(100),
    state_token VARCHAR(64),
    acc_id INT,
    status VARCHAR(10) DEFAULT 'CR',
    last_sync_at TIMESTAMP NULL,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'menu_visibility' => "CREATE TABLE IF NOT EXISTS menu_visibility (
    item_key VARCHAR(100) PRIMARY KEY,
    level_1 TINYINT(1) NOT NULL DEFAULT 1,
    level_2 TINYINT(1) NOT NULL DEFAULT 1,
    level_3 TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'projects' => "CREATE TABLE IF NOT EXISTS projects (
    proj_id INT AUTO_INCREMENT PRIMARY KEY,
    proj_no VARCHAR(50),
    cust_id INT,
    proj_start DATE,
    proj_stop DATE,
    proj_description TEXT,
    proj_concept TEXT,
    is_active TINYINT DEFAULT 1,
    note_expenses TEXT,
    note_income TEXT,
    note_general TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'fiscal_years' => "CREATE TABLE IF NOT EXISTS fiscal_years (
    year_id INT AUTO_INCREMENT PRIMARY KEY,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_closed TINYINT NOT NULL DEFAULT 0,
    closed_at TIMESTAMP NULL DEFAULT NULL,
    closed_by INT,
    closing_jou_id INT,
    equity_acc_id INT,
    net_result DECIMAL(14,2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'transactions' => "CREATE TABLE IF NOT EXISTS transactions (
    trans_id INT AUTO_INCREMENT PRIMARY KEY,
    trans_date DATE,
    trans_text VARCHAR(255),
    amount DECIMAL(12,2),
    vat_amount DECIMAL(12,2),
    acc_id INT,
    offset_acc_id INT,
    attachment_path VARCHAR(255),
    attachment_type VARCHAR(50),
    currency VARCHAR(3) DEFAULT 'DKK',
    proj_id INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'bank_statement_temp' => "CREATE TABLE IF NOT EXISTS bank_statement_temp (
    tmp_id INT AUTO_INCREMENT PRIMARY KEY,
    import_source VARCHAR(50),
    acc_id INT,
    trans_date DATE,
    text_val VARCHAR(255),
    amount DECIMAL(12,2),
    fee_amount DECIMAL(12,2),
    is_processed TINYINT DEFAULT 0,
    raw_hash VARCHAR(64)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'settings' => "CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'login_log' => "CREATE TABLE IF NOT EXISTS login_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    logged_username VARCHAR(100),
    login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45),
    status VARCHAR(20),
    user_agent VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'audit_log' => "CREATE TABLE IF NOT EXISTS audit_log (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    log_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_id INT,
    action_type VARCHAR(50),
    table_name VARCHAR(100),
    row_id INT,
    old_values TEXT,
    new_values TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'layout_settings' => "CREATE TABLE IF NOT EXISTS layout_settings (
    element_id VARCHAR(100) PRIMARY KEY,
    pos_x FLOAT,
    pos_y FLOAT,
    is_visible TINYINT DEFAULT 1,
    width_mm FLOAT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'tbl_maillog' => "CREATE TABLE IF NOT EXISTS tbl_maillog (
    id INT AUTO_INCREMENT PRIMARY KEY,
    faktura_id INT,
    modtager_mail VARCHAR(255),
    afsendt_dato DATETIME,
    status VARCHAR(50),
    server_respons TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
'system_migrations' => "CREATE TABLE IF NOT EXISTS system_migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration_key VARCHAR(100),
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

$table_defs = $is_sqlite ? $tables_sqlite : $tables_mysql;
$created = 0; $existed = 0; $failed = 0;

foreach ($table_defs as $name => $sql) {
    $before = DB::query($conn, "SELECT COUNT(*) FROM $name");
    $already_existed = ($before !== false);

    if (DB::query($conn, $sql)) {
        if ($already_existed) {
            echo "[EKSISTEREDE] $name\n";
            $existed++;
        } else {
            echo "[OPRETTET]    $name\n";
            $created++;
        }
    } else {
        echo "[FEJL]        $name: " . DB::error($conn) . "\n";
        $failed++;
    }
}

echo str_repeat('-', 50) . "\n";
echo "Færdig: $created oprettet, $existed fandtes allerede, $failed fejlede.\n";

// -------------------------------------------------------------------------
// Indekser på de kolonner inc/auto_backup.inc.php's auto_backup_check()
// filtrerer på (kørt på HVER sidevisning, hver 7. dag) - manglede fra
// starten, fundet under en fejlsøgning af en bruger-rapporteret langsom
// sidevisning på en installation med en del bogføringshistorik. Samme
// indekser tilføjes for eksisterende installationer via
// db-setup/migrate_auto_backup_indexes.php.
// -------------------------------------------------------------------------
$auto_backup_indexes = [
    ['name' => 'idx_audit_log_date',   'table' => 'audit_log', 'column' => 'log_date'],
    ['name' => 'idx_expenses_created', 'table' => 'expenses',  'column' => 'created_at'],
    ['name' => 'idx_journal_created',  'table' => 'journal',   'column' => 'created_at'],
];
foreach ($auto_backup_indexes as $idx) {
    $has_index = false;
    if ($is_sqlite) {
        $res = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='index' AND name='{$idx['name']}'");
        $has_index = ($res && DB::fetch_assoc($res));
    } else {
        $res = DB::query($conn, "SELECT INDEX_NAME FROM information_schema.STATISTICS
                                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$idx['table']}' AND INDEX_NAME = '{$idx['name']}'");
        $has_index = ($res && DB::fetch_assoc($res));
    }
    if (!$has_index) {
        DB::query($conn, "CREATE INDEX {$idx['name']} ON {$idx['table']}({$idx['column']})");
    }
}

// -------------------------------------------------------------------------
// Seed den fælles bilagsnummer-tæller (voucher_counter), kun hvis den er tom.
// -------------------------------------------------------------------------
$vc_row = DB::fetch_assoc(DB::query($conn, "SELECT next_no FROM voucher_counter WHERE id = 1"));
if (!$vc_row) {
    if (DB::insert($conn, 'voucher_counter', ['id' => 1, 'next_no' => 1])) {
        echo "[OK] voucher_counter seedet (næste bilagsnummer: 1).\n";
    }
}

// -------------------------------------------------------------------------
// Seed fakturanummer-tælleren (invoice_no_counter), kun hvis den er tom.
// Starter fra 1001 hvis der ingen fakturaer er endnu, ellers ét over det
// højeste eksisterende invoice_no (så intet genbruges ved en opgradering).
// -------------------------------------------------------------------------
$ic_row = DB::fetch_assoc(DB::query($conn, "SELECT next_no FROM invoice_no_counter WHERE id = 1"));
if (!$ic_row) {
    $max_inv = DB::fetch_assoc(DB::query($conn, "SELECT MAX(invoice_no) AS m FROM invoices"));
    $start_no = max((int)($max_inv['m'] ?? 0) + 1, 1001);
    if (DB::insert($conn, 'invoice_no_counter', ['id' => 1, 'next_no' => $start_no])) {
        echo "[OK] invoice_no_counter seedet (næste fakturanummer: $start_no).\n";
    }
}

// -------------------------------------------------------------------------
// Tilføj et par eventyr-udgifter, MEN kun hvis expenses-tabellen er tom
// -------------------------------------------------------------------------
$exp_count_res = DB::query($conn, "SELECT COUNT(*) FROM expenses");
if ($exp_count_res) {
    $row = DB::fetch_row($exp_count_res);
    if ((int)$row[0] === 0) {
        $demo_expenses = [
            ['2026-06-15', 'Den Onde Stedmors Systue',   2100, 349.00,  25, 'Nye syle og tråd til glassko-reparation'],
            ['2026-06-20', 'Ulven & Co. Skovtransport',  2200, 899.00,  25, 'Transport af kurvevarer gennem skoven'],
            ['2026-06-28', 'Heksens Pebernøddebageri',    2100, 199.50,  25, 'Ingredienser til eventyrsalg'],
        ];
        foreach ($demo_expenses as [$date, $supplier, $acc, $amount, $vat, $desc]) {
            DB::insert($conn, 'expenses', [
                'exp_date' => $date, 'supplier' => $supplier, 'account_id' => $acc,
                'amount' => $amount, 'vat_rate' => $vat, 'description' => $desc,
                'is_cancelled' => 0, 'currency' => 'DKK'
            ]);
        }
        echo "[OK] " . count($demo_expenses) . " eksempel-udgifter tilføjet til expenses.\n";
    } else {
        echo "[SPRUNGET OVER] expenses indeholder allerede " . $row[0] . " række(r) - ingen demo-data tilføjet.\n";
    }
}

// -------------------------------------------------------------------------
// DB-niveau append-only på journal/ledger (bogføringsloven) - samme
// beskyttelse som db-setup/migrate_append_only_ledger.php giver eksisterende
// installationer, sat op fra dag ét på en frisk installation.
// -------------------------------------------------------------------------
$trg_result = create_append_only_triggers($conn);
if ($trg_result['created'] > 0) {
    echo "[OK] " . $trg_result['created'] . " trigger(e) for append-only-beskyttelse af journal/ledger oprettet.\n";
}
if (!empty($trg_result['failed'])) {
    echo "[FEJL] " . count($trg_result['failed']) . " trigger(e) KUNNE IKKE oprettes (tjek TRIGGER-rettigheden):\n";
    foreach ($trg_result['failed'] as $name => $msg) {
        echo "  - $name: $msg\n";
    }
}

echo "\nDu kan nu slette hele setup/-mappen, når du er færdig med opsætningen.\n";

} // slut create_all_tables_for()
