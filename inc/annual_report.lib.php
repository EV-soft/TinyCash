<?php # /inc/annual_report.lib.php v:1.3.0 d:2026-08-30 i:evs
/* ==========================================================================
   ÅRSRAPPORT-BEREGNINGER (Årsregnskabsloven, regnskabsklasse B)

   Fælles beregningslag for balance_sheet.php og annual_report.php - undgår
   at have samme fortegns-/grupperings-logik to steder. arl_balance_sheet()
   beregner en kumulativ statusopgørelse pr. en given dato; arl_income_statement()
   en periodeafgrænset resultatopgørelse. Ingen af dem filtrerer på is_cancelled
   (en annulleret postering og dens modpostering skal begge tælles med, så de
   balancerer hinanden ud til 0 - kun originalen får is_cancelled=1).
   arl_income_statement() udelukker desuden selve årsafslutningens egen
   lukkepostering (trans_type='year_end_close') fra periodens beregning, da
   den ellers altid falder inden for sin egen periode og nulstiller resultatet
   til 0.

   Fortegnskonvention (DEBET = positivt, KREDIT = negativt):
    - asset/bank: normal DEBET-saldo -> vis SUM(amount) direkte.
    - equity/liability: normal KREDIT-saldo -> vis -SUM(amount).
    - vat: nettoes til én linje - negativ = skyldig moms (passiv), positiv =
      tilgodehavende moms (aktiv).
    - income/expense: hører til resultatopgørelsen, men et ikke-afsluttet
      regnskabsårs saldo skal også indgå i balancens egenkapital som "Årets
      resultat", ellers balancerer Aktiver/Passiver ikke uden for lige efter
      en årsafslutning (year_end_close.php).
   ========================================================================== */

function arl_group_balances($conn, array $types, $where_date_sql) {
    $type_list = "'" . implode("','", $types) . "'";
    // BEVIDST intet "j.is_cancelled = 0"-filter - se forklaring i toppen af
    // filen (rettet 2026-08-19). En annulleret postering og dens modpostering
    // (se ledger_view.php's Annullér) skal BEGGE tælles med, så de balancerer
    // hinanden ud til 0 - kun originalen får is_cancelled=1, modposteringen
    // gør ikke, så et filter her ville ekskludere originalen men medtage
    // modposteringen, og dermed lade et rent annulleret spøgelsesbeløb slippe
    // igennem til balance/resultatopgørelsen. Samme mønster som
    // report_income.php allerede brugte korrekt.
    $sql = "SELECT a.acc_id, a.acc_name, a.acc_type, SUM(l.amount) AS balance
            FROM ledger l
            JOIN journal j ON l.jou_id = j.jou_id
            JOIN accounts a ON l.acc_id = a.acc_id
            WHERE a.acc_type IN ($type_list)
              AND $where_date_sql
            GROUP BY a.acc_id, a.acc_name, a.acc_type
            HAVING SUM(l.amount) != 0
            ORDER BY a.acc_id ASC";
    $rows = [];
    $res = DB::query($conn, $sql);
    if ($res) { while ($r = DB::fetch_assoc($res)) { $rows[] = $r; } }
    return $rows;
}

/**
 * Statusopgørelse (balance) pr. en given dato - kumulativ siden systemets start.
 */
function arl_balance_sheet($conn, $as_of_date) {
    $s = get_settings($conn);
    $acc_vat_out = (int)($s['conf_acc_vat'] ?? 6900);
    $acc_vat_in  = (int)($s['conf_acc_purchase_vat'] ?? 6910);
    $as_of_esc = DB::escape($conn, $as_of_date);
    $date_sql = "j.jou_date <= '$as_of_esc'";

    $asset_rows = arl_group_balances($conn, ['asset', 'bank'], $date_sql);
    $assets_total = 0;
    foreach ($asset_rows as $r) $assets_total += (float)$r['balance'];

    $vat_row = DB::fetch_assoc(DB::query($conn, "
        SELECT SUM(l.amount) AS net FROM ledger l
        JOIN journal j ON l.jou_id = j.jou_id
        WHERE l.acc_id IN ($acc_vat_out, $acc_vat_in) AND $date_sql"));
    $vat_net = (float)($vat_row['net'] ?? 0);
    $vat_is_asset = ($vat_net > 0);

    $equity_rows = arl_group_balances($conn, ['equity'], $date_sql);
    $equity_total = 0;
    foreach ($equity_rows as $r) $equity_total += -(float)$r['balance'];

    $liability_rows = arl_group_balances($conn, ['liability'], $date_sql);
    $liability_total = 0;
    foreach ($liability_rows as $r) $liability_total += -(float)$r['balance'];

    $pl_row = DB::fetch_assoc(DB::query($conn, "
        SELECT SUM(l.amount) AS net FROM ledger l
        JOIN journal j ON l.jou_id = j.jou_id
        JOIN accounts a ON l.acc_id = a.acc_id
        WHERE a.acc_type IN ('income','expense') AND $date_sql"));
    $unclosed_result = -(float)($pl_row['net'] ?? 0);

    if ($vat_is_asset) { $assets_total += $vat_net; }
    else               { $liability_total += -$vat_net; }

    $equity_total += $unclosed_result;
    $passives_total = $equity_total + $liability_total;

    return [
        'as_of'            => $as_of_date,
        'asset_rows'       => $asset_rows,
        'assets_total'     => $assets_total,
        'vat_net'          => $vat_net,
        'vat_is_asset'     => $vat_is_asset,
        'equity_rows'      => $equity_rows,
        'equity_total'     => $equity_total,
        'liability_rows'   => $liability_rows,
        'liability_total'  => $liability_total,
        'unclosed_result'  => $unclosed_result,
        'passives_total'   => $passives_total,
        'diff'             => round($assets_total - $passives_total, 2),
    ];
}

/**
 * Resultatopgørelse for en periode, formateret efter ÅRL skema 2's
 * forenkling for regnskabsklasse B: omsætning, vareforbrug og eksterne
 * omkostninger må aggregeres til ét "Bruttoresultat" - kontoplanen er for
 * flad til en mere detaljeret opdeling (personaleomkostninger, af- og
 * nedskrivninger er ikke sporet særskilt i dag).
 */
function arl_income_statement($conn, $period_start, $period_end) {
    $s = get_settings($conn);
    $acc_vat_out = (int)($s['conf_acc_vat'] ?? 6900);
    $acc_vat_in  = (int)($s['conf_acc_purchase_vat'] ?? 6910);
    $ps = DB::escape($conn, $period_start);
    $pe = DB::escape($conn, $period_end);
    // RETTET (ALVORLIGT FUND, se [[year-end-close-bugs-review]]): manglede en
    // udelukkelse af selve årsafslutningens egen lukkepostering
    // (trans_type='year_end_close'). Den postering er dateret på periodens
    // egen slutdato (jf. year_end_close.php) og nulstiller netop de samme
    // indtægts-/udgiftskonti den selv lige har summeret op - falder derfor
    // ALTID inden for sin egen periodes BETWEEN-forespørgsel og kancellerer
    // hele resultatet til 0. Bekræftet direkte: en afsluttet regnskabsårs
    // årsrapport viste 0,00 kr i omsætning/omkostninger/resultat for et år
    // hvor der reelt var bogført en rigtig udgift - selve pointen med at
    // generere en årsrapport EFTER en afslutning blev derved altid ødelagt.
    // RETTET (§bugs-batch-22-review, samme fund som year_end_close.php): NULL
    // trans_type (reelt forekommende - ingen NOT NULL/standardværdi i skemaet)
    // blev stiltiende UDELUKKET af "!= 'year_end_close'" pga. SQL's tre-
    // værdis-logik (NULL != x evaluerer til NULL, ikke SAND). Resultat-
    // opgørelsen ville derfor mangle enhver postering hvis journalpost har
    // trans_type=NULL. Kræver nu eksplicit at NULL også tælles med.
    $date_sql = "j.jou_date BETWEEN '$ps' AND '$pe' AND (j.trans_type IS NULL OR j.trans_type != 'year_end_close')";

    $rev_row = DB::fetch_assoc(DB::query($conn, "
        SELECT SUM(-l.amount) AS total FROM ledger l
        JOIN journal j ON l.jou_id = j.jou_id
        JOIN accounts a ON l.acc_id = a.acc_id
        WHERE a.acc_type = 'income' AND $date_sql"));
    $revenue = (float)($rev_row['total'] ?? 0);

    $cost_rows = [];
    $total_costs = 0;
    $res = DB::query($conn, "
        SELECT a.acc_id, a.acc_name, SUM(l.amount) AS total FROM ledger l
        JOIN journal j ON l.jou_id = j.jou_id
        JOIN accounts a ON l.acc_id = a.acc_id
        WHERE a.acc_type = 'expense' AND $date_sql
        GROUP BY a.acc_id, a.acc_name HAVING SUM(l.amount) != 0 ORDER BY a.acc_id ASC");
    if ($res) {
        while ($r = DB::fetch_assoc($res)) { $cost_rows[] = $r; $total_costs += (float)$r['total']; }
    }

    $sv_row = DB::fetch_assoc(DB::query($conn, "SELECT SUM(-l.amount) AS total FROM ledger l JOIN journal j ON l.jou_id=j.jou_id WHERE l.acc_id = $acc_vat_out AND $date_sql"));
    $sales_vat = (float)($sv_row['total'] ?? 0);
    $pv_row = DB::fetch_assoc(DB::query($conn, "SELECT SUM(l.amount) AS total FROM ledger l JOIN journal j ON l.jou_id=j.jou_id WHERE l.acc_id = $acc_vat_in AND $date_sql"));
    $purchase_vat = (float)($pv_row['total'] ?? 0);

    return [
        'period_start'  => $period_start,
        'period_end'    => $period_end,
        'revenue'       => $revenue,
        'cost_rows'     => $cost_rows,
        'total_costs'   => $total_costs,
        'gross_result'  => $revenue - $total_costs, // "Bruttoresultat" = "Årets resultat" for klasse B
        'sales_vat'     => $sales_vat,
        'purchase_vat'  => $purchase_vat,
        'vat_to_pay'    => $sales_vat - $purchase_vat,
    ];
}
?>
