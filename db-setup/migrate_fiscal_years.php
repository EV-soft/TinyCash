<?php # /db-setup/migrate_fiscal_years.php v:1.3.0 d:2026-08-30 i:evs
# Understøtter nu forskudt regnskabsår
// Skift til projektroden så auth.inc.php's CWD-relative includes ('inc/...')
// virker når scriptet køres direkte fra db-setup/ (ellers 500).
chdir(dirname(__DIR__));
require_once __DIR__ . '/../inc/auth.inc.php';
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    deny_access_gracefully();
}
require_once __DIR__ . '/../inc/db_connect.inc.php';
require_once __DIR__ . '/../inc/php2htm.lib.php';

// -------------------------------------------------------------------------
// 1. OPRET SELVE TABELLEN (altid sikkert at genkøre)
// -------------------------------------------------------------------------
if (DB::is_sqlite()) {
    $sql = "CREATE TABLE IF NOT EXISTS fiscal_years (
        year_id INTEGER PRIMARY KEY AUTOINCREMENT,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        is_closed INTEGER NOT NULL DEFAULT 0,
        closed_at TIMESTAMP,
        closed_by INTEGER,
        closing_jou_id INTEGER,
        equity_acc_id INTEGER,
        net_result NUMERIC
    )";
} else {
    $sql = "CREATE TABLE IF NOT EXISTS fiscal_years (
        year_id INT AUTO_INCREMENT PRIMARY KEY,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        is_closed TINYINT NOT NULL DEFAULT 0,
        closed_at TIMESTAMP NULL,
        closed_by INT,
        closing_jou_id INT,
        equity_acc_id INT,
        net_result DECIMAL(14,2)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
}
DB::query($conn, $sql);

// -------------------------------------------------------------------------
// 2. HÅNDTER OPRETTELSE AF NYT REGNSKABSÅR (brugerdefineret periode -
//    understøtter forskudt regnskabsår, fx 1/7-30/6, ikke kun kalenderår)
// -------------------------------------------------------------------------
$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_year'])) {
    $start = $_POST['start_date'] ?? '';
    $end   = $_POST['end_date'] ?? '';

    if (empty($start) || empty($end)) {
        $err = 'Udfyld både start- og slutdato.';
    } elseif (strtotime($end) <= strtotime($start)) {
        $err = 'Slutdato skal ligge efter startdato.';
    } else {
        $start_esc = DB::escape($conn, $start);
        $end_esc   = DB::escape($conn, $end);

        $check = DB::query($conn, "SELECT COUNT(*) FROM fiscal_years WHERE start_date = '$start_esc' AND end_date = '$end_esc'");
        $exists = false;
        if ($check) { $row = DB::fetch_row($check); $exists = ((int)$row[0] > 0); }

        if ($exists) {
            $err = "Et regnskabsår med præcis denne periode ($start til $end) findes allerede.";
        } else {
            DB::insert($conn, 'fiscal_years', ['start_date' => $start, 'end_date' => $end, 'is_closed' => 0]);
            $msg = "Regnskabsår oprettet: $start til $end.";
        }
    }
}

// -------------------------------------------------------------------------
// 3. VIS FORM + EKSISTERENDE REGNSKABSÅR
// -------------------------------------------------------------------------
htm_Header('@Fiscal Years Setup');
echo "<div style='max-width:700px; margin:0 auto; padding:10px;'>";

if ($msg) htm_Alert($msg, 'success');
if ($err) htm_Alert($err, 'error');

htm_Card_('Opret regnskabsår', 700);
?>
<p style="font-size:0.9em; color:#7f8c8d;">
    Angiv jeres <strong>faktiske</strong> regnskabsårs start og slut - det behøver
    IKKE være kalenderår (1/1-31/12). Har I forskudt regnskabsår (fx 1/7-30/6),
    så angiv det her. Du kan oprette flere regnskabsår efter hinanden (fx
    både indeværende og næste), og altid tilføje flere senere.
</p>
<form method="post">
    <?php csrf_field(); ?>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px; margin-bottom:15px;">
        <div>
            <label style="font-size:0.85em; font-weight:bold; display:block; margin-bottom:5px;">Startdato</label>
            <input type="date" name="start_date" required value="<?php echo date('Y') . '-01-01'; ?>" style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:4px; box-sizing:border-box;">
        </div>
        <div>
            <label style="font-size:0.85em; font-weight:bold; display:block; margin-bottom:5px;">Slutdato</label>
            <input type="date" name="end_date" required value="<?php echo date('Y') . '-12-31'; ?>" style="width:100%; padding:8px; border:1px solid var(--border-color); border-radius:4px; box-sizing:border-box;">
        </div>
    </div>
    <?php
    htm_Button(icon: 'fa-plus', labl: 'Opret regnskabsår', type: 'success', styl: 'width:100%; padding:12px;', attr: 'name="add_year" type="submit"');
    ?>
</form>
<?php
htm_Card_end();

// Vis eksisterende regnskabsår
$existing = DB::query($conn, "SELECT year_id, start_date, end_date, is_closed FROM fiscal_years ORDER BY start_date ASC");
$rows = [];
if ($existing) {
    while ($r = DB::fetch_assoc($existing)) {
        $rows[] = [
            $r['start_date'] . ' - ' . $r['end_date'],
            $r['is_closed'] ? '<span style="color:#e74c3c; font-weight:bold;">Lukket</span>' : '<span style="color:#2ecc71; font-weight:bold;">Åben</span>'
        ];
    }
}

htm_Card_('Eksisterende regnskabsår', 700);
if (empty($rows)) {
    echo "<p style='color:#999;'>Ingen regnskabsår oprettet endnu.</p>";
} else {
    htm_Table(['Periode', 'Status'], $rows, 'fy_tbl', 50);
}
htm_Card_end();

echo "</div>";
htm_Footer();
?>
