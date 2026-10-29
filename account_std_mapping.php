<?php # /account_std_mapping.php v:1.3.1 d:2026-09-15 i:evs
# Masse-tildeling af "standard-reference" (accounts.std_ref_id) til alle konti i
# kontoplanen fra én side, i stedet for at åbne account_edit.php pr. konto (19+
# klik i praksis). std_ref_id peger på std_accounts - den danske normal-kontoplan
# som SAF-T-eksporten (export_saft.php) skriver ud som <StandardAccountID> for
# hver konto der har en mapping; uden den mangler eksportens revisor-/SKAT-
# rettede mapning helt (se posting-accounts/regnskabslov-status i hukommelsen).
# Tomme felter forudfyldes KUN som forslag når der er præcis ét std_accounts-
# match på kontoens acc_type (typisk income/bank/vat) - ved flertydige typer
# (fx 'expense', hvor flere standardkonti er mulige) forbliver feltet tomt, så
# brugeren selv vælger. Forslag er tydeligt mærket og ikke gemt, før "Gem
# tildelinger" trykkes.
# Samme niveau-3-krav som chart_of_accounts.php/account_edit.php (se deres
# headers for hvorfor - direkte URL-adgang skal spærres server-side, ikke kun
# skjules i menuen).
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';

// Kontotype-labels - samme liste som account_edit.php, til visning i tabellen.
$type_labels = [
    'income'    => '@Income (Revenue)',
    'expense'   => '@Expense (Costs)',
    'bank'      => '@Bank / Cash',
    'vat'       => '@VAT (Settlement)',
    'asset'     => '@Asset (Balance)',
    'liability' => '@Liability (Debt)',
    'equity'    => '@Equity'
];

// 0. IMPORT AF DEN OFFICIELLE STANDARDKONTOPLAN (BEK 811/2026-gennemgang,
// Bilag 3 "standardkontoplan", se [[digital-standard-bookkeeping-
// registration-goal]]): std_accounts indeholdt hidtil kun 7 selvopfundne,
// brede kategorier - ikke Erhvervsstyrelsens faktiske, navngivne
// "standardkontoplan" (findes som en konkret, versioneret CSV-fil til
// Regnskab Basis-upload, KONTONUMMER/KONTONAVN/VAERDI-kolonner, 4-cifrede
// kontonumre i faste intervaller - bekræftet via Erhvervsstyrelsens egen
// vejledning og Uniconta/e-conomic's dokumentation af standarden). Admin kan
// nu uploade den rigtige CSV-fil (hentes fra Erhvervsstyrelsens hjemmeside,
// søg efter "standardkontoplan") for at erstatte stub-listen med de ægte
// kontonumre/navne - IKKE forsøgt gættet/hårdkodet her, da den fulde liste
// har 190+ konti og kun findes officielt som downloadbar fil.
// VIGTIGT: ikke testet mod en ægte hentet fil (regeringssidens download var
// ikke tilgængelig under udviklingen af denne funktion - automatiseret
// hentning blev blokeret) - kontotype pr. interval er udledt af de
// bekræftede nummerintervaller, IKKE af VAERDI-kolonnens eksakte betydning
// (uafklaret). Juster regex/kolonnetolkningen nedenfor hvis en rigtig fil
// viser sig at afvige fra antagelsen.
if (isset($_POST['import_std_csv'])) {
    // NYT: eksplicit lokalt CSRF-tjek, samme "defense in depth"-mønster som
    // fx customer_contact_action.php/bank_rules.php - inc/auth.inc.phps
    // globale POST-gate (se [[csrf-protection-added]]) beskytter allerede
    // ALLE POST-kald i hele appen, så dette er reelt aldrig falsk i praksis,
    // men gør det eksplicit og selvstændigt dokumenteret netop her, fordi
    // handleren er usædvanligt destruktiv (sletter HELE std_accounts-
    // tabellen og nulstiller alle eksisterende kontomapninger).
    if (!csrf_verify()) {
        $import_message = htm_Alert(lang('@Security check failed (CSRF). Please reload the page and try again.'), 'error', 0, true);
    } elseif (empty($_FILES['std_csv']['name']) || $_FILES['std_csv']['error'] !== UPLOAD_ERR_OK) {
        $import_message = htm_Alert(lang('@Error: Could not read the uploaded file.'), 'error', 0, true);
    } else {
        $raw = file_get_contents($_FILES['std_csv']['tmp_name']);
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        $delim = (strpos($lines[0] ?? '', ';') !== false) ? ';' : ',';
        $header_row = str_getcsv(array_shift($lines), $delim);

        $col_num = $col_name = null;
        foreach ($header_row as $i => $h) {
            $h = trim($h);
            if ($col_num === null && stripos($h, 'KONTONUMMER') === 0) $col_num = $i;
            if ($col_name === null && stripos($h, 'KONTONAVN') === 0) $col_name = $i;
        }

        if ($col_num === null) {
            $import_message = htm_Alert(lang('@Error: Could not recognize this file as a standard chart of accounts CSV (expected a KONTONUMMER column).'), 'error', 0, true);
        } else {
            $parsed = [];
            foreach ($lines as $line) {
                if (trim($line) === '') continue;
                $cols = str_getcsv($line, $delim);
                $num = (int)trim($cols[$col_num] ?? '');
                if ($num <= 0) continue;
                $name = ($col_name !== null) ? trim($cols[$col_name] ?? '') : '';
                if ($name === '') $name = lang('@Account') . ' ' . $num;

                if ($num < 2000) $type = 'asset';
                elseif ($num < 3000) $type = 'expense';
                elseif ($num < 5000) $type = 'finance';
                elseif ($num < 6000) $type = 'asset';
                elseif ($num < 7000) $type = 'asset';
                elseif ($num < 8000) $type = 'liability';
                else $type = 'liability';

                $parsed[$num] = ['name' => $name, 'type' => $type];
            }

            if (empty($parsed)) {
                $import_message = htm_Alert(lang('@Error: No valid rows found in the uploaded file.'), 'error', 0, true);
            } else {
                DB::begin_transaction($conn);
                $new_ids = array_keys($parsed);
                $new_ids_sql = implode(',', array_map('intval', $new_ids));

                // Nulstil mappinger der peger på en std_id, der ikke findes i
                // den nye liste - ellers ville accounts.std_ref_id pege på
                // tomme referencer efter udskiftningen.
                $reset_count = 0;
                $dangling_res = DB::query($conn, "SELECT COUNT(*) AS n FROM accounts WHERE std_ref_id IS NOT NULL AND std_ref_id NOT IN ($new_ids_sql)");
                if ($dangling_res) { $reset_count = (int)(DB::fetch_assoc($dangling_res)['n'] ?? 0); }
                DB::query($conn, "UPDATE accounts SET std_ref_id = NULL WHERE std_ref_id IS NOT NULL AND std_ref_id NOT IN ($new_ids_sql)");

                DB::query($conn, "DELETE FROM std_accounts");
                $finance_count = 0;
                foreach ($parsed as $num => $row) {
                    if ($row['type'] === 'finance') $finance_count++;
                    DB::query($conn, "INSERT INTO std_accounts (std_id, std_name, std_type) VALUES ("
                        . (int)$num . ", '" . DB::escape($conn, $row['name']) . "', '" . DB::escape($conn, $row['type']) . "')");
                }
                DB::commit($conn);

                log_action($conn, 'IMPORT_STD_ACCOUNTS_CSV', 'std_accounts', 0, null, ['count' => count($parsed), 'reset_mappings' => $reset_count]);
                header("Location: account_std_mapping.php?msg=csv_imported&n=" . count($parsed) . "&r=" . $reset_count . "&f=" . $finance_count);
                exit;
            }
        }
    }
}

// 1. Hent standard-kontoplanen (std_accounts) - til dropdown-valg og til
//    entydigheds-tjek for auto-forslag (kun forslå når acc_type har PRÆCIS
//    ét std_accounts-match).
$std_options = ['' => lang('@No mapping / Not reportable')];
$std_by_type = [];
$std_res = DB::query($conn, "SELECT std_id, std_name, std_type FROM std_accounts ORDER BY std_id ASC");
if ($std_res) {
    while ($s = DB::fetch_assoc($std_res)) {
        $std_options[$s['std_id']] = $s['std_id'] . ' - ' . $s['std_name'];
        $std_by_type[$s['std_type']][] = $s['std_id'];
    }
}

// 2. Gem (POST) - kun konti der reelt indgik i formularen røres, og kun dem
//    hvor værdien faktisk ændrede sig får en UPDATE + revisionsspor-post.
if (isset($_POST['save_mappings'])) {
    // NYT: samme "defense in depth"-tilføjelse som import_std_csv-handleren
    // ovenfor - se dens kommentar for hvorfor (inc/auth.inc.phps globale
    // POST-gate beskytter allerede reelt denne handler).
    if (!csrf_verify()) {
        $message = htm_Alert(lang('@Security check failed (CSRF). Please reload the page and try again.'), 'error', 0, true);
    } else {
        $posted = $_POST['std_ref'] ?? [];
        $changed = 0;

        $cur_res = DB::query($conn, "SELECT acc_id, std_ref_id FROM accounts");
        $current = [];
        if ($cur_res) {
            while ($r = DB::fetch_assoc($cur_res)) {
                $current[(int)$r['acc_id']] = $r['std_ref_id'];
            }
        }

        DB::begin_transaction($conn);
        $ok = true;
        foreach ($current as $acc_id => $old_val) {
            if (!array_key_exists((string)$acc_id, $posted)) continue;

            $new_raw  = trim((string)$posted[$acc_id]);
            $new_val  = ($new_raw === '') ? null : (int)$new_raw;
            $old_norm = ($old_val === null || $old_val === '') ? null : (int)$old_val;
            if ($new_val === $old_norm) continue;

            $new_sql = ($new_val === null) ? "NULL" : (int)$new_val;
            if (!DB::query($conn, "UPDATE accounts SET std_ref_id = $new_sql WHERE acc_id = " . (int)$acc_id)) {
                $ok = false;
                break;
            }
            log_action($conn, 'UPDATE_ACCOUNT', 'accounts', $acc_id,
                ['std_ref_id' => $old_norm], ['std_ref_id' => $new_val]);
            $changed++;
        }

        if ($ok) {
            DB::commit($conn);
            header("Location: account_std_mapping.php?msg=updated&n=" . $changed);
            exit;
        } else {
            DB::rollback($conn);
            $message = htm_Alert(lang('@Error saving account') . ": " . DB::error($conn), 'error', 0, true);
        }
    }
}

// 3. Hent konti til visning
$accounts = [];
// Kun konti i brug (aktive og dæmpede): de slukkede fra standardkontoplanen er allerede tilknyttet deres eget
// nummer - med dem alle blev siden 20+ MB (485 valgmuligheder i hver række). Gem rører kun de viste rækker.
require_once __DIR__ . '/inc/accounts.inc.php';
$res = DB::query($conn, "SELECT acc_id, acc_name, acc_type, std_ref_id FROM accounts WHERE " . acc_visible_sql($conn) . " ORDER BY acc_id ASC");
$n_hidden_off = acc_status_ready($conn) ? (int)(DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM accounts WHERE acc_status = 'off'"))[0] ?? 0) : 0;
if ($res) {
    while ($row = DB::fetch_assoc($res)) $accounts[] = $row;
}

htm_Header('@Bulk Map to Standard Accounts');
showMenu();

echo "<div style='max-width:900px; margin:0 auto; padding:10px;'>";
if (isset($_GET['msg']) && $_GET['msg'] === 'updated') {
    $n = (int)($_GET['n'] ?? 0);
    htm_Alert(lang('@Standard account mappings updated') . " ($n)", 'success', 700);
}
if (isset($_GET['msg']) && $_GET['msg'] === 'csv_imported') {
    $n = (int)($_GET['n'] ?? 0);
    $r = (int)($_GET['r'] ?? 0);
    $f = (int)($_GET['f'] ?? 0);
    $txt = lang('@Imported') . " $n " . lang('@standard accounts.');
    if ($r > 0) $txt .= ' ' . $r . ' ' . lang('@existing mappings were reset and need to be reassigned below.');
    // RETTET (find-fem-fejl-gennemgang): de finansielle konti (3000-4999) får
    // en std_type ('finance'), der bevidst ikke matcher nogen rigtig
    // kontotype (income/expense/bank/vat/asset/liability/equity) - en
    // renteindtægt/renteudgift kan være begge dele, og en forkert automatisk
    // forslået type ville være værre end slet intet forslag. "Foreslå
    // automatisk"-logikken nedenfor kan derfor ALDRIG forslå disse konti -
    // tidligere stille og uden varsel, nu eksplicit nævnt her.
    if ($f > 0) $txt .= ' ' . sprintf(lang('@%d of these are financial accounts and will never be auto-suggested below (ambiguous income/expense direction) - they must be mapped manually.'), $f);
    htm_Alert($txt, 'success', 700);
}
if (isset($message)) echo $message;
if (isset($import_message)) echo $import_message;

htm_Card_('@Import Official Standard Chart of Accounts', 900, '', 'stdCsvImportForm');
echo '<p style="color:var(--text-muted); margin-top:0; font-size:0.9em;">'
    . lang('@Upload Erhvervsstyrelsen\'s official "standardkontoplan" CSV file (search for "standardkontoplan" on erhvervsstyrelsen.dk) to replace the list below with the real, official account numbers. This is optional - the bulk mapping below already works with the built-in list.')
    . '</p>';
echo '<form method="post" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:flex-end;">';
csrf_field();
echo '<div><label style="display:block; font-size:0.85em; color:var(--text-muted); margin-bottom:4px;">' . lang('@Standardkontoplan CSV') . '</label>';
echo '<input type="file" name="std_csv" accept=".csv" required></div>';
htm_Button(icon: 'fa-upload', labl: '@Import', type: 'success', attr: 'type="submit" name="import_std_csv" value="1"');
echo '</form>';
htm_Card_end();

htm_Card_('@Bulk Map to Standard Accounts', 900, '', 'stdMapForm');
if (!empty($n_hidden_off)) echo '<p style="color:var(--text-muted); font-size:0.85em;">' . sprintf(lang('@%d switched-off accounts are not shown - switch an account on in the chart of accounts to map it here.'), $n_hidden_off) . '</p>';
echo '<p style="color:var(--text-muted); margin-top:0;">'
    . lang('@Map all your accounts to the Danish standard chart of accounts (used for SAF-T reporting) from one page. Fields marked as a suggestion are pre-filled based on account type - review and adjust before saving.')
    . '</p>';

if (empty($accounts)) {
    echo "<p style='padding:20px; text-align:center; color:var(--text-muted);'>" . lang('@No accounts found') . "</p>";
} else {
    $headers = ['@No.', '@Account Name', '@Type', '@Standard Account Mapping'];
    $data = [];

    foreach ($accounts as $a) {
        $id = (int)$a['acc_id'];
        $current = $a['std_ref_id'];
        $is_suggestion = false;

        $selected = ($current !== null && $current !== '') ? (int)$current : '';
        if ($selected === '') {
            $candidates = $std_by_type[$a['acc_type']] ?? [];
            if (count($candidates) === 1) {
                $selected = $candidates[0];
                $is_suggestion = true;
            }
        }

        $sel_style = 'width:100%; padding:6px;' . ($is_suggestion ? ' border:1px dashed var(--color-warning) !important;' : '');
        $sel_html = htm_Select("std_ref[$id]", $std_options, (string)$selected, $sel_style, '', false);
        if ($is_suggestion) {
            $sel_html .= ' <span style="font-size:0.75em; color:var(--color-warning); font-weight:bold; white-space:nowrap;" data-hint="'
                . htmlspecialchars(lang('@This is only a suggestion based on the account type - review before saving.'))
                . '">' . lang('@Suggested') . '</span>';
        }

        $data[] = [
            $id,
            htmlspecialchars($a['acc_name']),
            lang($type_labels[$a['acc_type']] ?? $a['acc_type']),
            $sel_html
        ];
    }

    htm_Table($headers, $data, 'stdMapTbl');
}

echo '<div style="display:flex; justify-content:space-between; margin-top:15px;">';
htm_Button('fa-save', '@Save Mappings', 'primary', '', '', 'name="save_mappings" data-hint="' . lang('@Save all standard account mappings on this page') . '"');
htm_Button('fa-arrow-left', '@Back', 'secondary', 'chart_of_accounts.php', '', 'data-hint="' . lang('@Return to the chart of accounts without saving') . '"');
echo '</div>';

htm_Card_end();
echo "</div>";
htm_Footer();
