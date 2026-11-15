<?php # /inc/vies.inc.php v:1.3.1 d:2026-09-15 i:evs
// Kontrol af et EU-momsnummer i EU-Kommissionens VIES. Et EU-salg uden moms (rubrik B) kræver, at kunden har et
// gyldigt momsnummer - kontrollen og dens svar logges i revisionssporet som dokumentation (VIES_CHECK).
// VIES svarer kun "gyldig"/"ugyldig" når landets egen server svarer; ellers 'unavailable' (prøv igen senere).

// ['status' => 'valid'|'invalid'|'unavailable'|'error', 'vat_id', 'name', 'address', 'request_id', 'checked_at', 'message']
function tc_vies_check(string $raw): array {
    $vat = tc_vat_id_clean($raw);
    $res = ['status' => 'error', 'vat_id' => (string)$vat, 'name' => '', 'address' => '', 'request_id' => '', 'checked_at' => date('Y-m-d H:i:s'), 'message' => ''];
    if (!$vat) { $res['message'] = lang('@The VAT number must start with the country code, e.g. SE556677889901, or leave the field empty.'); return $res; }
    $cc = substr($vat, 0, 2);  $no = substr($vat, 2);
    if ($cc === 'GR') $cc = 'EL';                                   // (Grækenlands momsnumre bruger EL)
    if (!in_array($cc === 'EL' ? 'GR' : $cc, TC_EU_COUNTRIES, true) && $cc !== 'XI') {
        $res['message'] = lang('@VIES only covers VAT numbers from EU countries (and Northern Ireland, XI).');
        return $res;
    }
    $url = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/' . rawurlencode($cc) . '/vat/' . rawurlencode($no);
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => "Accept: application/json\r\nUser-Agent: TinyCash VIES check\r\n"]]);
    $raw_json = @file_get_contents($url, false, $ctx);
    $j = is_string($raw_json) ? json_decode($raw_json, true) : null;
    if (!is_array($j)) {
        $res['status'] = 'unavailable';
        $res['message'] = lang('@VIES could not be reached - try again later.');
        return $res;
    }
    $clean = fn($s) => in_array(trim((string)$s), ['', '---'], true) ? '' : trim((string)$s);
    $res['vat_id'] = $cc . $no;
    $res['name'] = $clean($j['name'] ?? '');
    $res['address'] = $clean($j['address'] ?? '');
    $res['request_id'] = (string)($j['requestIdentifier'] ?? '');
    $err = strtoupper((string)($j['userError'] ?? ''));
    if (!empty($j['isValid'])) {
        $res['status'] = 'valid';
    } elseif ($err === 'INVALID' || $err === 'INVALID_INPUT') {
        $res['status'] = 'invalid';
        $res['message'] = lang('@The VAT number is not valid according to VIES.');
    } else {
        $res['status'] = 'unavailable';      // MS_UNAVAILABLE, MS_MAX_CONCURRENT_REQ, TIMEOUT, SERVICE_UNAVAILABLE ...
        $res['message'] = sprintf(lang('@The tax authority of %s did not answer VIES (%s) - try again later.'), $cc, $err !== '' ? $err : '?');
    }
    return $res;
}

// Seneste VIES-kontrol af en kunde/leverandør (fra revisionssporet) eller null
function tc_vies_last($conn, string $table, int $row_id): ?array {
    if ($row_id <= 0 || !db_has_table($conn, 'audit_log')) return null;
    $table = $table === 'suppliers' ? 'suppliers' : 'customers';
    $r = DB::fetch_assoc(DB::query($conn, "SELECT log_date, new_values FROM audit_log WHERE action_type = 'VIES_CHECK' AND table_name = '$table' AND row_id = $row_id ORDER BY log_id DESC LIMIT 1"));
    if (!$r) return null;
    $v = json_decode((string)$r['new_values'], true);
    return is_array($v) ? $v + ['log_date' => db_ts_local($r['log_date'])] : null;      // (databasens egen tid - UTC på SQLite)
}

// Knappen "Tjek i VIES" ved et momsnummerfelt + resultatlinjen (seneste kontrol vises fra revisionssporet).
// $field = feltets name, $table = customers|suppliers, $row_id = 0 for en ny (kontrollen logges så uden række).
function htm_vies_button($conn, string $field, string $table, int $row_id): void {
    static $js_done = false;
    $last = tc_vies_last($conn, $table, $row_id);
    $last_txt = '';
    if ($last) {
        $ok = ($last['status'] ?? '') === 'valid';
        $last_txt = '<span style="color:var(' . ($ok ? '--color-success' : '--color-danger') . ');">' . ($ok ? '✔ ' . lang('@Valid in VIES') : '✖ ' . lang('@Not valid in VIES'))
                  . '</span> · ' . htmlspecialchars((string)($last['vat_id'] ?? '')) . ' · ' . sprintf(lang('@checked %s'), date(CONF_DATE_FORMAT . ' H:i', strtotime((string)$last['log_date'])))
                  . (!empty($last['name']) ? ' · ' . htmlspecialchars((string)$last['name']) : '');
    }
    echo '<div style="display:flex; gap:8px; align-items:center; margin:2px 4px 8px; font-size:0.85em;">';
    echo '<button type="button" class="tc-info-fill" onclick="tcViesCheck(this, ' . htmlspecialchars(json_encode([$field, $table, $row_id]), ENT_QUOTES) . ')" style="padding:5px 10px; background:var(--color-info); color:var(--text-light); border:none; border-radius:4px; cursor:pointer;" data-hint="'
       . htmlspecialchars(lang('@Check the VAT number in the EU VIES register. Required before invoicing an EU business customer without VAT - the result is saved in the audit log as documentation.'), ENT_QUOTES) . '"><i class="fa-solid fa-earth-europe"></i> ' . lang('@Check in VIES') . '</button>';
    echo '<span class="tc-vies-result">' . $last_txt . '</span></div>';
    if ($js_done) return;
    $js_done = true;
    $t = ['wait' => lang('@Checking ...'), 'valid' => lang('@Valid in VIES'), 'invalid' => lang('@Not valid in VIES'), 'checked' => lang('@checked %s'), 'empty' => lang('@Enter the VAT number with country code first.')];
    echo '<script>
    function tcViesCheck(btn, a) {
        var T = ' . json_encode($t) . ', out = btn.parentNode.querySelector(".tc-vies-result");
        var v = (document.querySelector("[name=\"" + a[0] + "\"]").value || "").trim();
        if (!v) { out.textContent = T.empty; return; }
        out.textContent = T.wait;  btn.disabled = true;
        var fd = new FormData();  fd.append("csrf_token", ' . json_encode(csrf_token()) . ');  fd.append("vat_id", v);  fd.append("table", a[1]);  fd.append("row_id", a[2]);
        fetch("vies_check.php", {method: "POST", body: fd}).then(function (r) { return r.json(); }).then(function (r) {
            out.innerHTML = "";
            var s = document.createElement("span");
            if (r.status === "valid" || r.status === "invalid") {
                s.style.color = r.status === "valid" ? "var(--color-success)" : "var(--color-danger)";
                s.textContent = (r.status === "valid" ? "✔ " + T.valid : "✖ " + T.invalid);
                out.appendChild(s);
                out.appendChild(document.createTextNode(" · " + r.vat_id + " · " + T.checked.replace("%s", r.checked_at_fmt) + (r.name ? " · " + r.name : "")));
            } else {
                s.style.color = "var(--color-warning)";  s.textContent = r.message || "VIES ?";  out.appendChild(s);
            }
        }).catch(function () { out.textContent = "VIES ?"; }).finally(function () { btn.disabled = false; });
    }
    </script>';
}
