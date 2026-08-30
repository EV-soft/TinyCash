<?php # /db-setup/init_demo_data.core.php v:1.3.0 d:2026-08-30 i:evs
# Definerer seed_demo_data_for($conn, string $db_type): void - opretter den
# minimale demo-database (tema: H.C. Andersens eventyr) på den medsendte
# forbindelse: skema (et mindre tabelsæt end create_all_tables.core.php's
# fulde skema - kaldes altid FØR create_all_tables_for() i begge
# eksisterende kaldere, som derefter supplerer med resten), kontoplan,
# momskoder, kunder, produkter, én eksempelfaktura, grundindstillinger og
# admin-brugeren. $is_sqlite afgøres af den medsendte $db_type-parameter,
# ikke DB::is_sqlite() (som stadig læser globale $db_type internt) - så
# funktionen kan kaldes korrekt mod et ANDET regnskab end det der ambient
# er forbundet, fx fra db-setup/provision_account.php's isolerede
# forbindelse (flere-regnskaber-funktionen).
#
# Ingen "allerede har brugere"-værn her - det er bevidst kalderens ansvar
# (se init_demo_data.php og provision_account.php), så funktionen altid kan
# kaldes mod en helt frisk, tom forbindelse uden at et internt tjek kan stå
# i vejen.
/* ==========================================================================
   MINIMAL DEMO-DATABASE - Tema: H.C. Andersens eventyr
   ========================================================================== */

function seed_demo_data_for($conn, string $db_type): void {

echo "TinyCash demo-data (tema: H.C. Andersens eventyr)\n";
echo "Motor: $db_type\n";
echo str_repeat('-', 50) . "\n";

$is_sqlite = ($db_type === 'sqlite');

$tables_sqlite = [
'users' => "CREATE TABLE IF NOT EXISTS users (
    user_id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    user_role TEXT NOT NULL DEFAULT 'user',
    user_level INTEGER NOT NULL DEFAULT 1
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
    credit_ref INTEGER
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
    voucher_no INTEGER,
    created_at TIMESTAMP,
    user_id INTEGER
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
];

$tables_mysql = [
'users' => "CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    user_role VARCHAR(20) NOT NULL DEFAULT 'user',
    user_level INT NOT NULL DEFAULT 1
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
    credit_ref INT
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
    created_at TIMESTAMP NULL DEFAULT NULL,
    user_id INT
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
];

$table_defs = $is_sqlite ? $tables_sqlite : $tables_mysql;

foreach ($table_defs as $name => $sql) {
    if (DB::query($conn, $sql)) {
        echo "[OK] Tabel '$name' klar.\n";
    } else {
        echo "[FEJL] Kunne ikke oprette '$name': " . DB::error($conn) . "\n";
    }
}

echo str_repeat('-', 50) . "\n";

// -------------------------------------------------------------------------
// 2. KONTOPLAN (dansk standard-inspireret, eventyr-tema)
// -------------------------------------------------------------------------
$accounts = [
    [1000, 'Salg af eventyrvarer',                 'income',    'S25', 25.00],
    [1010, 'Salg, EU',                             'income',    null,  0.00],
    [1020, 'Salg af tryllestave',                  'income',    'S25', 25.00],
    [1030, 'Salg af flyvende tæpper',               'income',    'S25', 25.00],
    [1040, 'Salg af drageblod (flasker)',          'income',    'S25', 25.00],
    [1050, 'Konsulentbistand i forbandelser',      'income',    'S25', 25.00],
    [2100, 'Vareforbrug - eventyrlager',           'expense',   'K25', 25.00],
    [2200, 'Lokaleomkostninger',                   'expense',   'K25', 25.00],
    [2210, 'El og vand i slottet',                 'expense',   'K25', 25.00],
    [2220, 'Vedligeholdelse af vindebro',          'expense',   'K25', 25.00],
    [2300, 'Kontorhold og gebyrer',                'expense',   'K25', 25.00],
    [2320, 'Bankgebyrer',                          'expense',   null,  0.00],
    [2400, 'Rejseomkostninger (fejer/dragejagt)',  'expense',   null,  0.00],
    [2500, 'Markedsføring i Kongeriget',           'expense',   'K25', 25.00],
    [3000, 'Egenkapital / Overført resultat',      'equity',    null,  0.00],
    [4000, 'Leverandørgæld og anden gæld',         'liability', null,  0.00],
    [5800, 'Bank - Den Gyldne Skatkiste',          'bank',      null,  0.00],
    [5900, 'Kassebeholdning - Slottets skatkammer','bank',      null,  0.00],
    [6900, 'Moms, salg',                           'vat',       null,  0.00],
    [6910, 'Moms, køb',                            'vat',       null,  0.00],
    [8100, 'Tilgodehavender fra salg (Debitorer)', 'asset',     null,  0.00],
];

foreach ($accounts as [$id, $name, $type, $vat, $rate]) {
    DB::insert($conn, 'accounts', [
        'acc_id' => $id, 'acc_name' => $name, 'acc_type' => $type,
        'vat_code' => $vat, 'vat_rate' => $rate
    ]);
}
echo "[OK] " . count($accounts) . " konti oprettet (inkl. egenkapital- og gældskonto til year_end_close.php).\n";

// -------------------------------------------------------------------------
// 3. MOMSKODER
// -------------------------------------------------------------------------
DB::insert($conn, 'vat_codes', ['vat_id' => 'S25', 'vat_name' => 'Salgsmoms 25%', 'vat_rate' => 25, 'vat_account' => 6900]);
DB::insert($conn, 'vat_codes', ['vat_id' => 'K25', 'vat_name' => 'Købsmoms 25%',  'vat_rate' => 25, 'vat_account' => 6910]);
echo "[OK] 2 momskoder oprettet.\n";

// -------------------------------------------------------------------------
// 4. KUNDER (Eventyr-tema)
// -------------------------------------------------------------------------
$customers = [
    ['Askepots Skomageri',        'Askepot',        'Slotsvejen 1, 1000 Eventyrby',  'askepot@skomageri.dk',    '11223344', '10101010'],
    ['Den Grimme Ælling A/S',     'Ællingen',        'Andedammen 7, 2000 Fjerkræsted','info@grimme-aelling.dk',  '22334455', '20202020'],
    ['Rødhættes Kurveflet',       'Rødhætte',        'Skovstien 13, 3000 Ulveskov',   'roedhaette@kurveflet.dk', '33445566', '30303030'],
    ['H.C. Andersens Eventyrhus', 'H.C. Andersen',   'Eventyrpladsen 5, 5000 Odense', 'kontakt@eventyrhus.dk',   '44556677', '40404040'],
    ['Fyrtøjet Håndværk',         'Soldaten',        'Landevejen 22, 6000 Kongerslev','soldat@fyrtoejet.dk',     '55667788', '50505050'],
];

$cust_ids = [];
foreach ($customers as [$name, $contact, $addr, $email, $phone, $cvr]) {
    DB::insert($conn, 'customers', [
        'cust_name' => $name, 'cust_contact_person' => $contact, 'cust_address' => $addr,
        'cust_email' => $email, 'cust_phone' => $phone, 'cust_cvr' => $cvr,
        'cust_notes' => '', 'cust_payment_days' => 8
    ]);
    $cust_ids[] = DB::insert_id($conn);
}
echo "[OK] " . count($customers) . " kunder oprettet.\n";

// -------------------------------------------------------------------------
// 5. PRODUKTER (Eventyr-tema)
// -------------------------------------------------------------------------
$products = [
    ['GLAS-01', 'Glassko (par)',           25, 5,  899.00],
    ['AERT-01', 'Ært, økologisk (kg)',     50, 10, 149.00],
    ['FYR-01',  'Fyrtøj, antik model',     10, 2,  1299.00],
    ['SPIN-01', 'Spindesnok, guldtråd',    30, 5,  349.00],
    ['FJER-01', 'Ælling-fjer (pose á 20)', 100,20, 79.00],
];

$prod_ids = [];
foreach ($products as [$sku, $name, $stock, $min, $price]) {
    DB::insert($conn, 'products', [
        'prod_sku' => $sku, 'prod_name' => $name, 'prod_stock' => $stock,
        'prod_min_stock' => $min, 'prod_price' => $price, 'acc_id' => 1000
    ]);
    $prod_ids[] = DB::insert_id($conn);
}
echo "[OK] " . count($products) . " produkter oprettet.\n";

// -------------------------------------------------------------------------
// 6. EN ENKELT EKSEMPEL-FAKTURA (draft) MED 2 LINJER
// -------------------------------------------------------------------------
DB::insert($conn, 'invoices', [
    'invoice_no' => null,
    'cust_id' => $cust_ids[0],
    'inv_date' => date('Y-m-d'),
    'inv_due_date' => date('Y-m-d', strtotime('+8 days')),
    'delivery_address' => 'Slotsvejen 1, 1000 Eventyrby',
    'currency' => 'DKK',
    'inv_status' => 'draft',
    'inv_note' => 'Eksempelfaktura oprettet af init_demo_data.php'
]);
$demo_inv_id = DB::insert_id($conn);

DB::insert($conn, 'invoice_lines', [
    'inv_id' => $demo_inv_id, 'line_text' => 'Glassko (par)', 'quantity' => 1,
    'price_each' => 899.00, 'acc_id' => 1000, 'line_vat_rate' => 25, 'prod_id' => $prod_ids[0], 'currency' => 'DKK'
]);
DB::insert($conn, 'invoice_lines', [
    'inv_id' => $demo_inv_id, 'line_text' => 'Spindesnok, guldtråd', 'quantity' => 2,
    'price_each' => 349.00, 'acc_id' => 1000, 'line_vat_rate' => 25, 'prod_id' => $prod_ids[3], 'currency' => 'DKK'
]);
echo "[OK] 1 eksempelfaktura (draft) med 2 linjer oprettet til Askepot.\n";

// -------------------------------------------------------------------------
// 7. GRUNDINDSTILLINGER
// -------------------------------------------------------------------------
$default_settings = [
    'company_name'  => 'TinyCash Eventyr-Demo ApS',
    'currency'      => 'DKK',
    'date_format'   => 'd.m.Y',
];
foreach ($default_settings as $key => $val) {
    DB::insert($conn, 'settings', ['setting_key' => $key, 'setting_value' => $val]);
}
echo "[OK] Grundindstillinger oprettet.\n";

// -------------------------------------------------------------------------
// 8. ADMIN-BRUGER
// -------------------------------------------------------------------------
$demo_username = 'admin';
$demo_password = 'eventyr123'; // Skift dette efter første login!
$password_hash = password_hash($demo_password, PASSWORD_DEFAULT);

DB::insert($conn, 'users', [
    'username' => $demo_username,
    'password_hash' => $password_hash,
    'user_role' => 'admin',
    'user_level' => 3
]);
echo "[OK] Admin-bruger oprettet.\n";

echo str_repeat('-', 50) . "\n";
echo "FÆRDIG! Login med:\n";
echo "  Brugernavn: $demo_username\n";
echo "  Kodeord:    $demo_password\n";

} // slut seed_demo_data_for()
