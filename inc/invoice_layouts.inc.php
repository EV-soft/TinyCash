<?php # /inc/invoice_layouts.inc.php v:1.3.1 d:2026-09-15 i:evs
# Flere navngivne fakturalayouts (bruger-anmodet): placeringen af fakturaens
# design-blokke (logo, afsender, modtager, linjer, totaler, fod ...) gemmes pr.
# layout i invoice_layout_blocks - i stedet for ét fælles sæt i layout_settings.
#
# Hvilket layout en faktura bruger (il_for_invoice):
#   1. fakturaens eget valg (invoices.inv_layout_id) - sat på invoice_edit.php,
#      og stemplet ved bogføring (invoice_post_action.php), så en bogført
#      faktura beholder sit udseende, selv om kundens eller standardens layout
#      senere skiftes,
#   2. ellers kundens layout (customers.cust_layout_id, customer_edit.php),
#   3. ellers standardlayoutet (invoice_layouts.is_default = 1).
#
# PDF'en følger automatisk med: den laves i browseren (html2pdf.js) ud fra
# selve fakturasiden (invoice_view.php), som tegner det valgte layouts blokke.
#
# Ikke migreret endnu (il_ready() = false): alt virker som før, med det ene
# fælles layout i layout_settings. Skemaet laves af il_ensure_schema() - kaldt
# af db-setup/migrate_invoice_layouts.php (eksisterende installationer) og af
# create_all_tables_for() (nye installationer), så de to veje ikke kan glide fra
# hinanden.

// De design-blokke der kan placeres (samme id'er som invoice_view.php's blokke)
const IL_BLOCK_IDS = [
    'block-stamp', 'block-logo', 'block-sender', 'block-recipient',
    'block-cust-ref', 'block-delivery', 'block-inv-no', 'block-inv-date', 'block-inv-due', 'block-lines',
    'block-totals', 'block-notes', 'block-foot-pay', 'block-foot-contact', 'block-foot-online', 'block-foot-legal',
    'block-foot-message',
];
const IL_NAME_MAX = 60;
// Blokke der kan skjules pr. layout (Design Mode: øje-ikonet) - pligtoplysningerne på en faktura (afsender med
// CVR, modtager, fakturanummer, dato, linjer og totaler) kan IKKE skjules
const IL_OPTIONAL_BLOCKS = [
    'block-logo', 'block-stamp', 'block-cust-ref', 'block-delivery', 'block-inv-due', 'block-notes',
    'block-foot-pay', 'block-foot-contact', 'block-foot-online', 'block-foot-legal', 'block-foot-message',
];
// Er blokken skjult i layoutet? ($blocks: il_blocks()) - kun valgfrie blokke kan være det
function il_block_hidden(array $blocks, string $element_id): bool {
    return in_array($element_id, IL_OPTIONAL_BLOCKS, true) && isset($blocks[$element_id]) && (string)($blocks[$element_id]['is_visible'] ?? '1') === '0';
}

function il_table_exists($conn, string $table, ?bool $is_sqlite = null): bool {
    $is_sqlite = $is_sqlite ?? DB::is_sqlite();
    $t = DB::escape($conn, $table);
    if ($is_sqlite) {
        $r = DB::query($conn, "SELECT name FROM sqlite_master WHERE type='table' AND name='$t'");
        return (bool)($r && DB::fetch_assoc($r));
    }
    $r = DB::query($conn, "SHOW TABLES LIKE '$t'");
    return $r && DB::num_rows($r) > 0;
}

function il_column_exists($conn, string $table, string $column, ?bool $is_sqlite = null): bool {
    $is_sqlite = $is_sqlite ?? DB::is_sqlite();
    if ($is_sqlite) {
        $r = DB::query($conn, "PRAGMA table_info($table)");
        if ($r) while ($row = DB::fetch_assoc($r)) if (strtolower($row['name']) === strtolower($column)) return true;
        return false;
    }
    $r = DB::query($conn, "SHOW COLUMNS FROM $table LIKE '" . DB::escape($conn, $column) . "'");
    return $r && DB::num_rows($r) > 0;
}

// Er migrationen kørt (begge tabeller + begge kolonner)? Husket pr. forespørgsel.
function il_ready($conn): bool {
    static $ready = null;
    if ($ready === null) {
        $ready = il_table_exists($conn, 'invoice_layouts') && il_table_exists($conn, 'invoice_layout_blocks')
              && il_column_exists($conn, 'customers', 'cust_layout_id') && il_column_exists($conn, 'invoices', 'inv_layout_id');
    }
    return $ready;
}

// Opretter tabeller og kolonner (idempotent) og flytter det hidtidige ene
// layout (layout_settings) over som layoutet "Standard". Returnerer linjer til
// kalderens udskrift.
function il_ensure_schema($conn, bool $is_sqlite): array {
    $out = [];
    if (!il_table_exists($conn, 'invoice_layouts', $is_sqlite)) {
        $ok = DB::query($conn, $is_sqlite
            ? "CREATE TABLE invoice_layouts (
                   layout_id INTEGER PRIMARY KEY AUTOINCREMENT,
                   name TEXT NOT NULL,
                   is_default INTEGER DEFAULT 0,
                   created_at TEXT
               )"
            : "CREATE TABLE invoice_layouts (
                   layout_id INT AUTO_INCREMENT PRIMARY KEY,
                   name VARCHAR(100) NOT NULL,
                   is_default TINYINT DEFAULT 0,
                   created_at DATETIME
               ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $out[] = $ok ? "[OK] Tabellen 'invoice_layouts' oprettet." : "[FEJL] invoice_layouts: " . DB::error($conn);
    } else {
        $out[] = "[SPRUNGET OVER] Tabellen 'invoice_layouts' findes allerede.";
    }
    if (!il_table_exists($conn, 'invoice_layout_blocks', $is_sqlite)) {
        $ok = DB::query($conn, $is_sqlite
            ? "CREATE TABLE invoice_layout_blocks (
                   layout_id INTEGER NOT NULL,
                   element_id TEXT NOT NULL,
                   pos_x REAL,
                   pos_y REAL,
                   width_mm REAL,
                   is_visible INTEGER DEFAULT 1,
                   PRIMARY KEY (layout_id, element_id)
               )"
            : "CREATE TABLE invoice_layout_blocks (
                   layout_id INT NOT NULL,
                   element_id VARCHAR(100) NOT NULL,
                   pos_x FLOAT,
                   pos_y FLOAT,
                   width_mm FLOAT,
                   is_visible TINYINT DEFAULT 1,
                   PRIMARY KEY (layout_id, element_id)
               ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $out[] = $ok ? "[OK] Tabellen 'invoice_layout_blocks' oprettet." : "[FEJL] invoice_layout_blocks: " . DB::error($conn);
    } else {
        $out[] = "[SPRUNGET OVER] Tabellen 'invoice_layout_blocks' findes allerede.";
    }
    foreach ([['customers', 'cust_layout_id'], ['invoices', 'inv_layout_id']] as [$table, $col]) {
        if (!il_table_exists($conn, $table, $is_sqlite)) continue;
        if (il_column_exists($conn, $table, $col, $is_sqlite)) {
            $out[] = "[SPRUNGET OVER] Kolonnen '$col' findes allerede på $table.";
        } else {
            $ok = DB::query($conn, "ALTER TABLE $table ADD COLUMN $col " . ($is_sqlite ? 'INTEGER' : 'INT NULL'));
            $out[] = $ok ? "[OK] Kolonnen '$col' tilføjet til $table." : "[FEJL] $table.$col: " . DB::error($conn);
        }
    }
    // Det hidtidige ene layout bliver "Standard" (kun når der endnu ingen layouts er)
    $cnt = DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM invoice_layouts"));
    if ($cnt && (int)$cnt[0] === 0) {
        $id = il_create_default($conn);
        $moved = 0;
        if ($id > 0 && il_table_exists($conn, 'layout_settings', $is_sqlite)) {
            $res = DB::query($conn, "SELECT element_id, pos_x, pos_y, width_mm, is_visible FROM layout_settings");
            if ($res) while ($r = DB::fetch_assoc($res)) {
                if (!in_array($r['element_id'], IL_BLOCK_IDS, true)) continue;
                if (DB::insert($conn, 'invoice_layout_blocks', ['layout_id' => $id, 'element_id' => $r['element_id'],
                        'pos_x' => (float)$r['pos_x'], 'pos_y' => (float)$r['pos_y'], 'width_mm' => (float)$r['width_mm'],
                        'is_visible' => (int)($r['is_visible'] ?? 1)])) $moved++;
            }
        }
        $out[] = $id > 0 ? "[OK] Layoutet 'Standard' oprettet som standard - $moved blokplacering(er) flyttet over fra layout_settings."
                         : "[FEJL] Kunne ikke oprette layoutet 'Standard': " . DB::error($conn);
        // RETTET: fakturaer der allerede er bogført, fik intet layout stemplet - de fulgte derfor kundens
        // layout / standarden og skiftede udseende, når et af dem blev skiftet (og deres layout kunne slettes).
        // De får nu "Standard" - det layout de blev sendt med.
        if ($id > 0 && il_table_exists($conn, 'invoices', $is_sqlite)) {
            DB::query($conn, "UPDATE invoices SET inv_layout_id = $id WHERE inv_layout_id IS NULL AND LOWER(COALESCE(inv_status, 'draft')) <> 'draft'");
            $n = DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM invoices WHERE inv_layout_id = $id"));
            $out[] = "[OK] " . (int)($n[0] ?? 0) . " bogført(e) faktura(er) beholder layoutet 'Standard'.";
        }
    } else {
        $out[] = "[SPRUNGET OVER] Der findes allerede layouts - intet flyttet.";
    }
    return $out;
}

function il_create_default($conn): int {
    if (!DB::insert($conn, 'invoice_layouts', ['name' => 'Standard', 'is_default' => 1, 'created_at' => date('Y-m-d H:i:s')])) return 0;
    return (int)DB::insert_id($conn);
}

// Alle layouts: [layout_id => ['layout_id', 'name', 'is_default']] - standarden først, så efter navn
function il_list($conn): array {
    $list = [];
    $res = DB::query($conn, "SELECT layout_id, name, is_default FROM invoice_layouts ORDER BY is_default DESC, name ASC");
    if ($res) while ($r = DB::fetch_assoc($res)) $list[(int)$r['layout_id']] = $r;
    return $list;
}

// Standardlayoutets id - mangler der et (fx et nyt regnskab hvor tabellerne er tømt), laves "Standard"
function il_default_id($conn): int {
    $r = DB::fetch_assoc(DB::query($conn, "SELECT layout_id FROM invoice_layouts WHERE is_default = 1 ORDER BY layout_id LIMIT 1"));
    if ($r) return (int)$r['layout_id'];
    $r = DB::fetch_assoc(DB::query($conn, "SELECT layout_id FROM invoice_layouts ORDER BY layout_id LIMIT 1"));
    if ($r) { DB::query($conn, "UPDATE invoice_layouts SET is_default = 1 WHERE layout_id = " . (int)$r['layout_id']);  return (int)$r['layout_id']; }
    return il_create_default($conn);
}

// $id hvis layoutet findes - ellers 0
function il_valid_id($conn, $id): int {
    $id = (int)$id;
    if ($id <= 0) return 0;
    $r = DB::fetch_assoc(DB::query($conn, "SELECT layout_id FROM invoice_layouts WHERE layout_id = $id"));
    return $r ? $id : 0;
}

// Layoutet en faktura bruger: dens eget valg, ellers kundens, ellers standarden ($inv: en invoices-række)
function il_for_invoice($conn, array $inv): int {
    if ($id = il_valid_id($conn, $inv['inv_layout_id'] ?? 0)) return $id;
    $cust_id = (int)($inv['cust_id'] ?? 0);
    if ($cust_id > 0) {
        $c = DB::fetch_assoc(DB::query($conn, "SELECT cust_layout_id FROM customers WHERE cust_id = $cust_id"));
        if ($c && ($id = il_valid_id($conn, $c['cust_layout_id'] ?? 0))) return $id;
    }
    return il_default_id($conn);
}

// Blokplaceringerne i et layout: [element_id => ['pos_x', 'pos_y', 'width_mm', 'is_visible']] - en blok
// uden række står på sin standardplads (invoice_view.php's getStyle())
function il_blocks($conn, int $layout_id): array {
    $blocks = [];
    $res = DB::query($conn, "SELECT element_id, pos_x, pos_y, width_mm, is_visible FROM invoice_layout_blocks WHERE layout_id = $layout_id");
    if ($res) while ($r = DB::fetch_assoc($res)) $blocks[$r['element_id']] = $r;
    return $blocks;
}

// Et navn til et layout: trimmet, højst IL_NAME_MAX tegn - '' hvis tomt
function il_clean_name($name): string {
    $name = trim(preg_replace('/\s+/u', ' ', (string)$name));
    return mb_substr($name, 0, IL_NAME_MAX);
}

// Er navnet taget af et andet layout (store/små bogstaver ens)?
function il_name_taken($conn, string $name, int $except_id = 0): bool {
    foreach (il_list($conn) as $id => $l) {
        if ($id !== $except_id && mb_strtolower($l['name']) === mb_strtolower($name)) return true;
    }
    return false;
}
