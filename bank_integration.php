<?php # /bank_integration.php v:1.3.0 d:2026-08-30 i:evs
# v2.3.0: psu_type-skiftet (v2.2.0) løste IKKE den rapporterede
# "server_error" - samme fejl gik igen uændret. Videresender nu også hver
# ASPSP's egen "maximum_consent_validity" (fra /aspsps) til
# bank_integration_connect.php, så eb_start_authorization() kan respektere
# den i stedet for blindt at bede om 90 dages gyldighed. Se
# inc/enablebanking.lib.php v1.1.0.
# v2.2.0: psu_type (personlig/erhverv) er nu valgbar pr. forbindelse i
# stedet for hårdkodet til "erhverv" - bruger-rapporteret "server_error" fra
# Enable Banking under selve bank-godkendelsen, sandsynligvis fordi sandbox-/
# mock-banken kun understøtter "personal". Se bank_integration_connect.php.
# v2.1.0: viser nu den reelle API-fejl (?detail=) ved en mislykket
# godkendelse, i stedet for kun en generisk "prøv igen"-besked - se
# bank_integration_callback.php v2.1.0. Bruger-bekræftet at en rigtig
# gennemført bank-godkendelse alligevel endte som "Afventer bekræftelse".
# v2.0.0: GoCardless Bank Account Data lukkede for nye tilmeldinger juli 2025
# (bekræftet: bankaccountdata.gocardless.com/new-signups-disabled, ingen
# venteliste/genåbningsdato) - omskrevet mod Enable Banking i stedet
# (inc/enablebanking.lib.php). Se db-setup/migrate_bank_integration.php v2.0.0
# for skemaændringerne (state_token, institution_country).
#
# Fra forslagslisten - sidste punkt under "Naturlige udvidelser". Kobler en
# rigtig bankkonto til via Enable Banking (PSD2/Open Banking) - hentede
# transaktioner lander i den EKSISTERENDE bank_statement_temp-tabel, så
# bankafstemningen (reconcile_list.php/reconcile_action.php) virker helt
# uændret, uanset om data kommer fra en CSV-fil eller en rigtig bank-API.
#
# Kræver en konto hos Enable Banking (enablebanking.com/sign-in) - se
# inc/data/env.ini's [enablebanking_config]-sektion. Uden konfigurerede
# nøgler viser siden kun en vejledning, i stedet for at fejle.
$rLev = 3; // admin-only: en privat RSA-nøgle til en ekstern bank-API er følsom
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';
require_once 'inc/enablebanking.lib.php';

// --- Afbryd forbindelse ---
if (isset($_GET['disconnect']) && (int)$_GET['disconnect'] > 0) {
    $did = (int)$_GET['disconnect'];
    $old = DB::fetch_assoc(DB::query($conn, "SELECT * FROM bank_connections WHERE conn_id = $did"));
    if (DB::query($conn, "DELETE FROM bank_connections WHERE conn_id = $did") && $old) {
        log_action($conn, 'DISCONNECT_BANK', 'bank_connections', $did, $old, null);
    }
    header("Location: bank_integration.php?msg=disconnected"); exit;
}

htm_Header('@Bank Integration (PSD2)', 1100);
showMenu();

// NYT (v2.1.0/v2.2.0-følge, se bank_integration_callback.php): viser den
// reelle API-/redirect-fejl, hvis en blev fanget, i stedet for kun en
// generisk "prøv igen"-besked - genbrugt af både "pending" og "error" nedenfor.
$showDetailBox = function() {
    if (!empty($_GET['detail'])) {
        htm_Banner('<span style="font-family:monospace; word-break:break-word;">' . htmlspecialchars($_GET['detail']) . '</span>', 'danger');
    }
};

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'disconnected') htm_Alert(lang('@Bank connection removed'), 'success');
    elseif ($_GET['msg'] === 'connected')    htm_Alert(lang('@Bank connected successfully! You can now sync transactions.'), 'success');
    elseif ($_GET['msg'] === 'pending') {
        htm_Alert(lang('@The bank has not confirmed the connection yet. Try again in a moment, or reconnect if this persists.'), 'error');
        $showDetailBox();
    } elseif ($_GET['msg'] === 'error') {
        htm_Alert(lang('@Could not connect to the bank. Please try again.'), 'error');
        $showDetailBox();
    }
}

$eb_ready = eb_credentials_configured();

// Vejledningskort vises ALTID øverst når nøglerne mangler - men blokerer
// ikke resten af siden. Eksisterende forbindelser (oprettet dengang
// nøglerne VAR sat) skal stadig kunne ses/afbrydes, selvom nøglerne siden
// er fjernet/roteret forkert - kun selve "forbind en ny bank"-sektionen
// nedenfor kræver reelt gyldige nøgler.
if (!$eb_ready) {
    htm_Card_(capt: '@Bank Integration (PSD2)', wdth: 1100);
    echo '<p>' . lang('@Real bank integration is not set up yet. This connects TinyCash directly to your bank via Enable Banking (Open Banking/PSD2) - transactions are fetched automatically instead of importing a CSV file by hand.') . '</p>';
    echo '<ol style="line-height:2;">';
    echo '<li>' . lang('@Create an account at') . ' <a href="https://enablebanking.com/sign-in/" target="_blank">enablebanking.com</a></li>';
    echo '<li>' . lang('@Register a new "application" in their Control Panel - let the browser generate the key pair, the private key file downloads automatically') . '</li>';
    echo '<li>' . lang('@Add the application ID and the path to the downloaded private key file to the [enablebanking_config] section in inc/data/env.ini on the server (EB_APPLICATION_ID, EB_PRIVATE_KEY_PATH) - store the key file somewhere not web-accessible, e.g. directly in inc/') . '</li>';
    echo '<li>' . lang('@Register EB_REDIRECT_URL in Enable Banking\'s Control Panel exactly as you set it in inc/data/env.ini - unlike some other providers this address cannot vary per connection') . '</li>';
    echo '</ol>';
    echo '<p style="color:var(--text-muted); font-size:0.9em;">' . lang('@Note: pricing for real production use is not published and requires contacting their sales team, but an application and sandbox can be created without a sales conversation.') . '</p>';
    echo '<p style="color:var(--text-muted); font-size:0.9em;">' . lang('@This page will show the connection options automatically once the keys are in place - no other change needed.') . '</p>';
    htm_Card_end();
}

// --- Eksisterende forbindelser ---
htm_Card_(capt: '@Connected Banks', wdth: 1100);
$res = DB::query($conn, "SELECT bc.*, a.acc_name FROM bank_connections bc LEFT JOIN accounts a ON bc.acc_id = a.acc_id ORDER BY bc.created_at DESC");
$headers = ['@Bank', '@Linked Account', '@Status', '@Last Sync', '@Actions'];
$data = [];
if ($res) {
    while ($row = DB::fetch_assoc($res)) {
        $status_map = [
            'CR' => ['@Pending confirmation', 'var(--color-warning)'],
            'LN' => ['@Connected', 'var(--color-success)'],
            'EX' => ['@Expired - please reconnect', 'var(--color-danger)'],
        ];
        [$status_label, $status_color] = $status_map[$row['status']] ?? [$row['status'], 'var(--text-muted)'];

        $actions = '';
        if ($row['status'] === 'LN') {
            $actions .= '<a href="bank_integration_sync.php?conn_id=' . $row['conn_id'] . '" style="margin-right:6px; padding:4px 10px; background:var(--color-info); color:#fff; border-radius:4px; text-decoration:none; font-size:0.85em;"><i class="fa fa-sync"></i> ' . lang('@Sync Now') . '</a>';
        }
        $actions .= htm_ConfirmLink(
            icon: 'fa-unlink', labl: '@Disconnect', link: 'bank_integration.php?disconnect=' . $row['conn_id'],
            mess: '@Disconnect this bank? Already-imported transactions are kept, but no more will be fetched until you reconnect.',
            type: 'danger', styl: 'padding:4px 10px; font-size:0.85em;',
            attr: 'data-hint="'.lang('@Remove this bank connection').'"', echo: false
        );

        $bank_label = $row['institution_name'] ?: $row['institution_id'];
        if (!empty($row['institution_country'])) $bank_label .= ' (' . $row['institution_country'] . ')';

        $data[] = [
            htmlspecialchars($bank_label),
            htmlspecialchars($row['acc_name'] ?? ('#' . $row['acc_id'])),
            '<span style="color:' . $status_color . '; font-weight:bold;">' . lang($status_label) . '</span>',
            $row['last_sync_at'] ? date(CONF_DATE_FORMAT . ' H:i', strtotime($row['last_sync_at'])) : lang('@Never'),
            $actions,
        ];
    }
}
if (empty($data)) {
    echo "<p style='padding:20px; text-align:center; color:var(--text-muted);'>" . lang('@No banks connected yet - use the form below to connect one.') . "</p>";
} else {
    htm_Table($headers, $data, 'bankConnTbl', 50, '', true,
        ['width:220px;', 'width:180px;', 'width:150px;', 'width:150px;', 'width:220px; text-align:left;']);
}
htm_Card_end();

// --- Ny forbindelse: land + institution (kræver gyldige nøgler) ---
if ($eb_ready) {
    $country = strtoupper($_GET['country'] ?? 'DK');
    htm_Card_(capt: '@Connect a New Bank', wdth: 1100);

    echo '<form method="get" action="bank_integration.php" style="display:flex; gap:10px; align-items:flex-end; margin-bottom:15px;">';
    htm_Field(icon: 'fa-globe', labl: '@Country', name: 'country', valu: $country, type: 'sele',
        opti: ['DK' => lang('@Denmark'), 'SE' => lang('@Sweden'), 'NO' => lang('@Norway'), 'DE' => lang('@Germany'), 'GB' => lang('@United Kingdom')], wdth: '200px');
    htm_Button(icon: 'fa-search', labl: '@Show Banks', type: 'secondary', link: '', attr: 'data-hint="'.lang('@List banks available in the selected country').'"');
    echo '</form>';

    $institutions = eb_list_institutions($country);
    if (empty($institutions)) {
        echo '<p style="color:var(--text-muted);">' . lang('@No banks found for this country, or the connection to Enable Banking failed - check that your API keys in inc/data/env.ini are correct and active.') . '</p>';
    } else {
        $account_res = DB::query($conn, "SELECT acc_id, acc_name FROM accounts WHERE acc_type = 'bank' OR acc_id = " . (int)(get_settings($conn)['conf_acc_bank'] ?? 5000) . " ORDER BY acc_id");
        $account_options = [];
        while ($a = DB::fetch_assoc($account_res)) { $account_options[$a['acc_id']] = $a['acc_id'] . ' - ' . $a['acc_name']; }
        if (empty($account_options)) {
            // Fallback: intet fandtes med acc_type='bank' - vis alle konti i stedet
            // for en tom, ubrugelig dropdown.
            $account_res2 = DB::query($conn, "SELECT acc_id, acc_name FROM accounts ORDER BY acc_id");
            while ($a = DB::fetch_assoc($account_res2)) { $account_options[$a['acc_id']] = $a['acc_id'] . ' - ' . $a['acc_name']; }
        }

        echo '<div style="display:flex; flex-direction:column; gap:10px;">';
        foreach ($institutions as $inst) {
            // Enable Banking identificerer en bank via navn+land, ikke ét
            // samlet id (i modsætning til GoCardless).
            $inst_name = $inst['name'] ?? null;
            if (!$inst_name) continue;
            $inst_country = $inst['country'] ?? $country;

            echo '<form method="post" action="bank_integration_connect.php" style="display:flex; align-items:center; gap:12px; padding:10px; border:1px solid var(--border-color); border-radius:6px;">';
            csrf_field();
            if (!empty($inst['logo'])) {
                echo '<img src="' . htmlspecialchars($inst['logo']) . '" style="height:28px; width:28px; object-fit:contain;" alt="">';
            }
            echo '<span style="flex:1; font-weight:600;">' . htmlspecialchars($inst_name) . '</span>';
            echo '<input type="hidden" name="institution_name" value="' . htmlspecialchars($inst_name) . '">';
            echo '<input type="hidden" name="institution_country" value="' . htmlspecialchars($inst_country) . '">';
            // NYT (v2.3.0-følge, se inc/enablebanking.lib.php): hver ASPSP kan
            // oplyse sin egen "maximum_consent_validity" (i sekunder) i
            // /aspsps-svaret - sendes videre uændret, så eb_start_authorization()
            // kan respektere den i stedet for blindt at bede om 90 dage for alle.
            $inst_max_validity = isset($inst['maximum_consent_validity']) ? (int)$inst['maximum_consent_validity'] : '';
            echo '<input type="hidden" name="max_validity_seconds" value="' . htmlspecialchars((string)$inst_max_validity) . '">';
            echo '<div style="width:220px;">';
            htm_Select('acc_id', $account_options, (string)(get_settings($conn)['conf_acc_bank'] ?? ''), 'width:100%; padding:6px;');
            echo '</div>';
            // NYT (v2.1.0-følge, se bank_integration_connect.php): psu_type
            // var hårdkodet til "business" - flere sandbox-/mock-banker
            // understøtter kun "personal". Standard sat til "personal" (mest
            // sandsynlige for en sandbox-test), men valgbar pr. forbindelse.
            echo '<div style="width:150px;">';
            htm_Select('psu_type', ['personal' => lang('@Personal'), 'business' => lang('@Business')], 'personal', 'width:100%; padding:6px;');
            echo '</div>';
            htm_Button(labl: '@Connect', type: 'primary', attr: 'data-hint="'.lang('@Start the bank connection process').'"');
            echo '</form>';
        }
        echo '</div>';
    }
    htm_Card_end();
}

htm_Footer();
ob_end_flush();
?>
