<?php # /inc/camt.inc.php v:1.3.1 d:2026-09-15 i:evs
// Bankens kontoudtog i ISO 20022-formatet CAMT (camt.053 kontoudtog, camt.052/054 intradag/advis) - standardformatet
// i hele EU. Strukturen er fast, så der er ingen kolonnemapping som ved CSV. Kun bogførte poster (Sts = BOOK)
// indlæses; reserverede (PDNG) kommer i et senere udtog. Navnerummets version (.02-.13) er ligegyldig: der søges på
// elementernes lokale navne.

// Læser posterne: [['date' => 'Y-m-d', 'text' => ..., 'amount' => +/-float, 'currency' => 'DKK', 'ref' => ...], ...]
// eller kaster en Exception med en forklaring.
function camt_parse(string $xml): array {
    if (trim($xml) === '') throw new Exception(lang('@The file is empty.'));
    if (stripos($xml, '<!DOCTYPE') !== false) throw new Exception(lang('@The file is not a CAMT bank statement.'));      // (ingen DTD/entiteter)
    $prev = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
    libxml_use_internal_errors($prev);
    if ($doc === false) throw new Exception(lang('@The file is not valid XML.'));
    $ns = (string)(array_values($doc->getDocNamespaces())[0] ?? '');
    if (!preg_match('/camt\.0(52|53|54)\./', $ns) && !$doc->xpath('//*[local-name()="BkToCstmrStmt" or local-name()="BkToCstmrAcctRpt" or local-name()="BkToCstmrDbtCdtNtfctn"]')) {
        throw new Exception(lang('@The file is not a CAMT bank statement.'));
    }
    $one = function ($node, string $path): string {
        $r = $node->xpath($path);
        return $r ? trim((string)$r[0]) : '';
    };
    $rows = [];
    foreach ($doc->xpath('//*[local-name()="Ntry"]') as $n) {
        $sts = $one($n, './*[local-name()="Sts"]/*[local-name()="Cd"]') ?: $one($n, './*[local-name()="Sts"]');
        if ($sts !== '' && strtoupper($sts) !== 'BOOK') continue;
        $amt_node = $n->xpath('./*[local-name()="Amt"]');
        if (!$amt_node) continue;
        $amount = (float)trim((string)$amt_node[0]);
        $cur = strtoupper((string)($amt_node[0]['Ccy'] ?? ''));
        if (strtoupper($one($n, './*[local-name()="CdtDbtInd"]')) === 'DBIT') $amount = -$amount;
        $date = $one($n, './*[local-name()="BookgDt"]/*[local-name()="Dt"]') ?: substr($one($n, './*[local-name()="BookgDt"]/*[local-name()="DtTm"]'), 0, 10)
             ?: $one($n, './*[local-name()="ValDt"]/*[local-name()="Dt"]');
        if (!tc_is_date($date)) continue;
        // Tekst: modpartens navn + den ustrukturerede meddelelse / postens ekstra tekst
        $tx = $n->xpath('./*[local-name()="NtryDtls"]/*[local-name()="TxDtls"]');
        $tx = $tx ? $tx[0] : null;
        $party = '';
        if ($tx) {
            $who = $amount < 0 ? 'Cdtr' : 'Dbtr';
            $party = $one($tx, './*[local-name()="RltdPties"]/*[local-name()="' . $who . '"]//*[local-name()="Nm"]');
        }
        $msg = $tx ? $one($tx, './*[local-name()="RmtInf"]/*[local-name()="Ustrd"]') : '';
        // (den strukturerede reference - KID/OCR/RF/FIK - kommer altid med, så afstemningen kan finde fakturaen)
        $cref = $tx ? $one($tx, './/*[local-name()="CdtrRefInf"]/*[local-name()="Ref"]') : '';
        if ($cref !== '' && stripos($msg, $cref) === false) $msg = trim($msg . ' ' . $cref);
        if ($msg === '') $msg = $one($n, './*[local-name()="AddtlNtryInf"]');
        if ($msg === '' && $tx) $msg = $one($tx, './*[local-name()="AddtlTxInf"]');
        // (står navnet allerede i meddelelsen, er meddelelsen hele teksten - ellers "navn - meddelelse")
        if ($party === '') $text = $msg;
        elseif ($msg === '') $text = $party;
        else $text = stripos($msg, $party) !== false ? $msg : $party . ' - ' . $msg;
        $text = trim($text);
        if ($text === '') $text = $party !== '' ? $party : ($amount < 0 ? 'Betaling' : 'Indbetaling');
        $ref = $one($n, './*[local-name()="AcctSvcrRef"]') ?: ($tx ? $one($tx, './/*[local-name()="AcctSvcrRef"]') : '');
        $rows[] = ['date' => $date, 'text' => mb_substr($text, 0, 250), 'amount' => round($amount, 2), 'currency' => $cur, 'ref' => $ref];
    }
    return $rows;
}

// Indsætter posterne i bank_statement_temp med samme dubletregel som CSV-importen (bank_import_process.php): den n'te
// ens post (dato, tekst, beløb) i filen er kun en dublet, hvis der allerede findes n af dem fra tidligere importer.
// Returnerer ['imported', 'duplicates', 'foreign' => poster i en anden valuta end regnskabets].
// $acc_id: bankkontoen kontoudtoget hører til (0 = standard-bankkontoen, se tc_bank_line_account())
function camt_import($conn, array $rows, string $source = 'camt', int $acc_id = 0): array {
    $base = strtoupper(setting('currency', 'DKK'));
    $out = ['imported' => 0, 'duplicates' => 0, 'foreign' => 0];
    $seen = [];
    foreach ($rows as $r) {
        if ($r['currency'] !== '' && $r['currency'] !== $base) {      // (afstemningen regner i regnskabets valuta)
            $r['text'] = '[' . $r['currency'] . '] ' . $r['text'];
            $out['foreign']++;
        }
        $k = $r['date'] . '|' . $r['text'] . '|' . $r['amount'];
        $nth = ($seen[$k]['seen'] ?? 0) + 1;
        $ins = $seen[$k]['inserted'] ?? 0;
        $seen[$k] = ['seen' => $nth, 'inserted' => $ins];
        $c = DB::fetch_row(DB::prepare_and_execute($conn, "SELECT COUNT(*) FROM bank_statement_temp WHERE trans_date = ? AND text_val = ? AND amount = ? AND " . tc_bank_dup_acc_sql($acc_id), [$r['date'], $r['text'], $r['amount']]));
        if ((int)($c[0] ?? 0) - $ins >= $nth) { $out['duplicates']++;  continue; }
        // (DB::insert - prepare_and_execute() returnerer false for en INSERT på MySQL, der er intet resultatsæt)
        if (DB::insert($conn, 'bank_statement_temp', ['trans_date' => $r['date'], 'text_val' => $r['text'], 'amount' => $r['amount'], 'fee_amount' => 0, 'import_source' => $source] + ($acc_id > 0 ? ['acc_id' => $acc_id] : []))) {
            $seen[$k]['inserted']++;
            $out['imported']++;
        }
    }
    return $out;
}
