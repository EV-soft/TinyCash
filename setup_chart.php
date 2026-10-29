<?php # /setup_chart.php v:1.3.0 d:2026-08-30 i:evs
# v1.2.0: forhåndsvisningens farvelogik fulgte 'revenue' som ikke længere
# findes i coa_*.json (nu 'income') - se regnskabslov-status i hukommelsen
# v1.3.0: TRUNCATE/SET FOREIGN_KEY_CHECKS/ON DUPLICATE KEY UPDATE var ren
# MySQL-syntaks uden SQLite-gren - fejlede stille på SQLite (fanget af
# DB::query()'s try/catch, aldrig tjekket)
// RETTET: display_errors var tvunget TIL for alle (viste serverstier og SQL ved enhver advarsel - fejlvisning
// styres centralt med ?Debug=true i inc/db_connect.inc.php), og siden - der udskifter hele kontoplanen - havde
// ingen adgangskontrol.
//
// KOORDINERET med standardkontoplanen (inc/accounts.inc.php) og skiftet til den (chart_switch.php):
//  - Erhvervsstyrelsens standardkontoplan 2026 er nu et fjerde (anbefalet) valg ved siden af TinyCash' tre
//    skabeloner, og siden er også "Se standardkontoplanen" (?tpl=official): forhåndsvisningen virker altid, også
//    når kontoplanen allerede er sat op - så kan man se planen, før man beslutter at skifte, uden at importere.
//    Er kontoplanen sat op, fører knappen til skiftet (chart_switch.php) i stedet for at være en død knap.
//  - RETTET: skabelonerne (json-data/coa_*.json) brugte kontotypen 'revenue' (resten af programmet kender kun
//    'income'), momskoderne U25/I25/Uden (som ikke findes - programmet bruger S25/K25), bank- og momskonti var
//    typet 'asset'/'liability', og der var ingen debitor-/kreditor-/kursdifference-/tabskonto. Og installationen
//    satte ALDRIG Firmaindstillingernes bogføringskonti (conf_acc_*): de pegede fortsat på programmets standard-
//    numre (5000, 8100, 6900 ...), som ingen skabelon har - så den første faktura kunne ikke bogføres (ledger_post()
//    afviser en konto der ikke findes). Hver skabelon har nu en konto pr. rolle ("role"), og installationen gemmer
//    indstillingerne og momskodernes konti.
$rLev = 3;
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/audit.inc.php';
require_once 'inc/chart_switch.inc.php';     // CS_ROLE_TARGETS + inc/accounts.inc.php (ACC_STD_CHART_FILE, acc_import_standard())

const SC_TEMPLATES = ['official', 'simple', 'standard', 'extended'];
// Standardkontoplanens konti der tændes ved en ny installation ud over rollernes (kasse, overført resultat, skyldig moms)
const SC_OFFICIAL_ACTIVE = [6470, 6940, 7840];

function sc_count_accounts($conn): int {
    $r = DB::fetch_row(DB::query($conn, "SELECT COUNT(*) FROM accounts"));
    return (int)($r[0] ?? 0);
}

// Firmaindstillingernes bogføringskonti + momskoderne S25/K25 -> rollernes konti. [rolle => kontonr]
function sc_apply_roles($conn, array $roles): void {
    foreach ($roles as $role => $acc) {
        $k = 'conf_acc_' . $role;
        DB::query($conn, "DELETE FROM settings WHERE setting_key = '" . DB::escape($conn, $k) . "'");
        if (!DB::insert($conn, 'settings', ['setting_key' => $k, 'setting_value' => (string)(int)$acc])) throw new Exception(DB::error($conn));
    }
    foreach (['S25' => ['Salgsmoms 25%', 'vat'], 'K25' => ['Købsmoms 25%', 'purchase_vat']] as $id => [$name, $role]) {
        if (empty($roles[$role])) continue;
        if (DB::fetch_assoc(DB::query($conn, "SELECT vat_id FROM vat_codes WHERE vat_id = '$id'"))) {
            DB::query($conn, "UPDATE vat_codes SET vat_account = " . (int)$roles[$role] . " WHERE vat_id = '$id'");
        } elseif (!DB::insert($conn, 'vat_codes', ['vat_id' => $id, 'vat_name' => $name, 'vat_rate' => 25, 'vat_account' => (int)$roles[$role]])) {
            throw new Exception(DB::error($conn));
        }
    }
    $flag = DB::is_sqlite()
        ? "INSERT INTO settings (setting_key, setting_value) VALUES ('setup_complete', '1') ON CONFLICT(setting_key) DO UPDATE SET setting_value = '1'"
        : "INSERT INTO settings (setting_key, setting_value) VALUES ('setup_complete', '1') ON DUPLICATE KEY UPDATE setting_value='1'";
    DB::query($conn, $flag);
}

// Momskoderne skal findes, før kontiene oprettes (en konto med en ukendt momskode får ingen)
function sc_ensure_vat_codes($conn): void {
    foreach (['S25' => 'Salgsmoms 25%', 'K25' => 'Købsmoms 25%'] as $id => $name) {
        if (!DB::fetch_assoc(DB::query($conn, "SELECT vat_id FROM vat_codes WHERE vat_id = '$id'"))) {
            DB::insert($conn, 'vat_codes', ['vat_id' => $id, 'vat_name' => $name, 'vat_rate' => 25, 'vat_account' => null]);
        }
    }
}

$coaInUse = sc_count_accounts($conn) > 0;
$err = '';

// 1. INSTALLATION (kun i en tom kontoplan)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['template']) && !$coaInUse) {
    $template = (string)$_POST['template'];
    try {
        if (!in_array($template, SC_TEMPLATES, true)) throw new Exception(lang('@Unknown chart of accounts.'));
        sc_ensure_vat_codes($conn);

        if ($template === 'official') {
            // acc_import_standard() har sin egen transaktion; to samtidige installationer er harmløse (et nummer der
            // allerede findes, springes over)
            $r = acc_import_standard($conn);
            DB::begin_transaction($conn);
            try {
                if (acc_status_ready($conn)) {
                    DB::query($conn, "UPDATE accounts SET acc_status = 'dimmed' WHERE acc_type IN ('income', 'expense')");
                    $on = implode(',', array_map('intval', array_merge(array_values(CS_ROLE_TARGETS), SC_OFFICIAL_ACTIVE)));
                    DB::query($conn, "UPDATE accounts SET acc_status = 'active' WHERE acc_id IN ($on)");
                }
                $roles = [];
                foreach (CS_ROLE_TARGETS as $k => $no) $roles[substr($k, strlen('conf_acc_'))] = $no;
                sc_apply_roles($conn, $roles);
                log_action($conn, 'SETUP_CHART', 'accounts', 0, null, ['template' => 'official', 'added' => $r['added']]);
                DB::commit($conn);
            } catch (Throwable $e) { DB::rollback($conn); throw $e; }
        } else {
            $accounts = json_decode((string)@file_get_contents(__DIR__ . "/json-data/coa_{$template}.json"), true);
            if (!is_array($accounts) || !$accounts) throw new Exception(lang('@The chart of accounts file cannot be read.'));

            // RETTET (bruger-anmodet "find 5 fejl"-runde): $coaInUse ovenfor er et engangs-tjek FØR selve gemmet - to
            // samtidige indsendelser kunne begge nå herned. Genbekræftes ATOMISK inde i transaktionen, og en fejl
            // midtvejs ruller hele installationen tilbage i stedet for at efterlade en delvis kontoplan.
            DB::begin_transaction($conn);
            try {
                if (sc_count_accounts($conn) > 0) { DB::rollback($conn); header("Location: setup_chart.php"); exit; }
                // Skabelonerne er udsnit af standardkontoplanen (samme numre og navne): hver konto knyttes til sin
                // standardkonto (std_ref_id = eget nummer), så et senere skift eller en import af resten aldrig konflikter
                $std_doc = json_decode((string)@file_get_contents(ACC_STD_CHART_FILE), true);
                $std_nos = [];
                foreach ((array)($std_doc['accounts'] ?? []) as $sa) $std_nos[(int)$sa['no']] = true;
                if ($std_nos) acc_fill_std_table($conn, $std_doc);
                $roles = [];
                foreach ($accounts as $acc) {
                    $row = ['acc_id' => (int)$acc['account_no'], 'acc_name' => (string)$acc['name'], 'acc_type' => (string)$acc['type'],
                            'vat_rate' => (float)($acc['vat_rate'] ?? 0)];
                    if (isset($std_nos[$row['acc_id']])) $row['std_ref_id'] = $row['acc_id'];
                    if (!empty($acc['vat_code'])) $row['vat_code'] = (string)$acc['vat_code'];
                    if (!DB::insert($conn, 'accounts', $row)) throw new Exception(DB::error($conn));
                    foreach (array_filter(explode(',', (string)($acc['role'] ?? ''))) as $role) $roles[trim($role)] = (int)$acc['account_no'];
                }
                sc_apply_roles($conn, $roles);
                log_action($conn, 'SETUP_CHART', 'accounts', 0, null, ['template' => $template, 'added' => count($accounts)]);
                DB::commit($conn);
            } catch (Throwable $e) { DB::rollback($conn); throw $e; }
        }
        header("Location: chart_of_accounts.php");
        exit;
    } catch (Throwable $e) {
        $err = lang('@Could not install the chart of accounts') . ': ' . $e->getMessage();
        $coaInUse = sc_count_accounts($conn) > 0;
    }
}

// 2. DATA TIL FORHÅNDSVISNINGEN
// En konto er "aktuelt i brug", når den er tændt eller dæmpet (vises i valglisterne). Posteringer alene tæller ikke:
// en konto der er slukket ved et skift, har sin historik, men bruges ikke længere
$mine = [];  $in_use = [];  $mapped = [];
if ($coaInUse) {
    $res = DB::query($conn, "SELECT acc_id, acc_name, std_ref_id" . acc_status_col($conn) . " FROM accounts");
    while ($r = DB::fetch_assoc($res)) {
        $id = (int)$r['acc_id'];
        $mine[$id] = $r;
        if (($r['acc_status'] ?? 'active') !== 'off') $in_use[$id] = true;
    }
}
$preview = [];
foreach (['simple', 'standard', 'extended'] as $tpl) {
    $preview[$tpl] = [];
    foreach ((array)json_decode((string)@file_get_contents(__DIR__ . "/json-data/coa_{$tpl}.json"), true) as $a) {
        $preview[$tpl][] = ['no' => (int)$a['account_no'], 'name' => (string)$a['name'], 'type' => (string)$a['type'],
                            'vat' => (string)($a['vat_code'] ?? ''), 'rate' => (float)($a['vat_rate'] ?? 0), 'role' => (string)($a['role'] ?? '')];
    }
}
$sc_conf = $coaInUse ? cs_conflicts($conn) : [];
$sc_alt  = $coaInUse ? array_flip(cs_alt_map($conn)) : [];      // standardnr => erstatningsnummer
// Dine egne konti i brug der er knyttet til en standardkonto (SAF-T-tilknytningen): [standardnr => ['1000 Salg ...', ...]]
foreach ($mine as $id => $r) {
    $ref = (int)($r['std_ref_id'] ?? 0);
    if ($ref > 0 && $ref !== $id && isset($in_use[$id]) && ($sc_alt[$ref] ?? 0) !== $id) $mapped[$ref][] = $id . ' ' . $r['acc_name'];
}
// Dine konti i brug der IKKE er knyttet til standarden: den standardkonto skiftet vil foreslå som afløser (samme
// cs_propose() som chart_switch.php) - så forhåndsvisningen også siger noget, før kontiene er knyttet eller importeret
$proposed = [];
if ($coaInUse) {
    $cs_std = cs_std_available($conn);
    foreach (cs_own_accounts($conn) as $id => $a) {
        if (($a['acc_status'] ?? 'active') === 'off' || (int)($a['std_ref_id'] ?? 0) > 0) continue;
        $t = cs_propose($a, $cs_std);
        if ($t > 0) $proposed[(int)($cs_std[$t]['std_no'] ?? $t)][] = $id . ' ' . $a['acc_name'];
    }
}
$std_doc = json_decode((string)@file_get_contents(ACC_STD_CHART_FILE), true);
$target_role = [];
foreach (CS_ROLE_TARGETS as $k => $no) $target_role[$no][] = substr($k, strlen('conf_acc_'));
$preview['official'] = [];
foreach ((array)($std_doc['accounts'] ?? []) as $a) {
    $no = (int)$a['no'];
    // Konflikt = en af dine konti optager nummeret (cs_conflicts(), med løsningsforslaget); er standardkontoen
    // importeret under et erstatningsnummer, er den "i din kontoplan" som det nummer
    $state = '';  $sol = '';  $as = 0;
    if (isset($sc_conf[$no]))                                          { $state = 'clash';  $sol = $sc_conf[$no]['proposal']; }
    elseif (isset($sc_alt[$no]))                                       { $state = 'imported';  $as = $sc_alt[$no]; }
    elseif (isset($mine[$no]) && (int)($mine[$no]['std_ref_id'] ?? 0) === $no) $state = 'imported';
    $preview['official'][] = ['no' => $no, 'name' => (string)$a['name'], 'type' => (string)$a['type'], 'vat' => (string)($a['vat_code'] ?? ''),
                              'rate' => (float)($a['vat_rate'] ?? 0), 'role' => implode(',', $target_role[$no] ?? []),
                              'section' => (string)($a['section'] ?? ''), 'group' => (string)($a['group'] ?? ''),
                              'state' => $state, 'mine' => $state === 'clash' ? (string)$mine[$no]['acc_name'] : '', 'sol' => $sol, 'as' => $as,
                              // i brug: selve standardkontoen (eller dens erstatningsnummer) - og/eller dine konti knyttet til den
                              'use' => $state === 'imported' && isset($in_use[$as ?: $no]) ? 1 : 0, 'via' => $mapped[$no] ?? [], 'prop' => $proposed[$no] ?? []];
}
$sel = in_array($_GET['tpl'] ?? '', SC_TEMPLATES, true) ? $_GET['tpl'] : ($_POST['template'] ?? 'official');
if (!in_array($sel, SC_TEMPLATES, true)) $sel = 'official';

$js_txt = [
    'accounts' => lang('@accounts'), 'filter' => lang('@Click to show only this account type - click more to combine, click again to remove'), 'search' => lang('@Search account number or name'),
    'inuse' => lang('@in use'), 'via' => lang('@used via your account'), 'prop' => lang('@suggested replacement for your account'),
    'prop_hint' => lang('@Your account is in use and is not linked to the standard chart yet - this is the standard account the switch suggests for it'), 'inuse_hint' => lang('@The account is switched on (or dimmed) in your chart of accounts'),
    'imported' => lang('@already in your chart'), 'clash' => lang('@number used by your account'), 'as' => lang('@in your chart as %d'),
    'sol' => ['move' => lang('@suggestion: move your account'), 'takeover' => lang('@suggestion: your account becomes the standard account'),
              'keep' => lang('@suggestion: keep the number - choose another replacement')],
    'roles' => ['bank' => lang('@Bank'), 'debitor' => lang('@Debitors'), 'creditor' => lang('@Creditors'), 'sales' => lang('@Sales'),
                'vat' => lang('@Output VAT'), 'purchase_vat' => lang('@Input VAT'), 'fx' => lang('@Currency Gain/Loss'), 'bad_debt' => lang('@Bad debt')],
    'types' => ['income' => lang('@Income'), 'expense' => lang('@Expense'), 'asset' => lang('@Asset'), 'bank' => lang('@Bank'),
                'liability' => lang('@Liability'), 'vat' => lang('@VAT'), 'equity' => lang('@Equity')],
];

htm_Header("@Select Chart of Accounts");
showMenu();
echo "<div style='max-width: 1000px; margin: 0 auto;'>";
if ($err !== '') htm_Alert(htmlspecialchars($err), 'error', 950);
htm_Card_('@Chart of Accounts Configuration', 950);

$cards = [
    'official' => ['@Standard chart of accounts (Danish Business Authority)', '@The official standard chart of accounts 2026 with about 485 accounts. Recommended: it is the numbering that SAF-T and the authorities use. You switch on the accounts you need.', true],
    'simple'   => ['@Simple', '@Only the most necessary accounts for sales and simple expenses. Ideal for freelancers without inventory.', false],
    // (alle tre skabeloner er udsnit af standardkontoplanen - se noten under kortene)
    'standard' => ['@Traditional', '@Traditional chart of accounts with separated sales, minor inventory, vehicle expenses, administration, and precise VAT handling.', false],
    'extended' => ['@Extended', '@A broad selection for companies with employees, inventory, fixed assets and depreciation, bank loans and foreign trade.', false],
];
?>
<style>
    .sc-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:20px; }
    .sc-box { border:2px solid var(--border-color); border-radius:8px; padding:12px; background:var(--bg-panel); cursor:pointer; }
    .sc-box.sel { border-color:var(--color-primary); }
    .sc-box p { font-size:0.8rem; color:var(--text-muted); margin:8px 0 0; line-height:1.4; }
    .sc-rec { font-size:0.7rem; background:var(--color-primary); color:#fff; border-radius:8px; padding:1px 7px; margin-left:4px; }
    .sc-note { border-left:4px solid var(--color-primary); background:var(--bg-panel); padding:12px 15px; border-radius:4px; margin-bottom:18px; font-size:0.9rem; line-height:1.5; }
    .sc-prev { border:1px solid var(--border-color); border-radius:8px; padding:15px; margin-bottom:20px; }
    .sc-scroll { max-height:420px; overflow-y:auto; border:1px solid var(--border-color); border-radius:4px; }
    .sc-tbl { width:100%; border-collapse:collapse; font-size:0.85rem; }
    .sc-tbl th { position:sticky; top:0; background:var(--bg-panel); padding:8px 10px; text-align:left; border-bottom:2px solid var(--border-color); }
    .sc-tbl td { padding:6px 10px; border-bottom:1px solid var(--border-color); }
    .sc-tbl tr.sc-sec td { font-weight:bold; background:var(--bg-panel); }
    .sc-tbl tr.sc-grp td { font-style:italic; color:var(--text-muted); padding-left:18px; }
    .sc-tag { display:inline-block; font-size:0.75em; border:1px solid var(--border-color); border-radius:8px; padding:0 6px; margin-left:4px; }
    /* tydelig på både lys og mørk baggrund: fast gul baggrund med mørk tekst (gul tekst var for svag) */
    .sc-tag.use { background:#15803d; border-color:#15803d; color:#fff; font-weight:600; }
    .sc-tag.via { border-color:#15803d; color:inherit; }
    .sc-tag.prop { border:1px dashed #15803d; color:inherit; }
    /* ✓ efter kontonummeret: fuld grøn = i brug / knyttet til din konto, grøn kant = foreslået afløser for din konto */
    .sc-check { display:inline-block; width:1.35em; height:1.35em; line-height:1.35em; text-align:center; border-radius:50%;
                background:#15803d; color:#fff; font-size:0.8em; margin-left:6px; font-family:sans-serif; }
    .sc-check.prop { background:transparent; color:#15803d; box-shadow:inset 0 0 0 1.5px #15803d; }
    .sc-tbl tr.sc-used td:nth-child(2) { font-weight:600; }
    .sc-tag.warn { background:#fbd34d; border-color:#d97706; color:#1f1300; font-weight:600; }
    /* linjefarve efter kontotype - gennemsigtige toner, så de virker i både lyst og mørkt tema; afsnits- og
       gruppeoverskrifter får den type de første konti under dem har, i en kraftigere tone */
    .sc-t-income { --sc-c:47,133,90; }  .sc-t-expense { --sc-c:197,48,48; }  .sc-t-asset { --sc-c:90,103,216; }
    .sc-t-bank { --sc-c:43,108,176; }   .sc-t-liability { --sc-c:221,107,32; } .sc-t-vat { --sc-c:183,121,31; }
    .sc-t-equity { --sc-c:107,70,193; }
    .sc-tbl tr[class*="sc-t-"] td { background:rgba(var(--sc-c), 0.07); }
    .sc-tbl tr[class*="sc-t-"] td:first-child { box-shadow:inset 4px 0 0 rgba(var(--sc-c), 0.85); }
    .sc-tbl tr.sc-sec[class*="sc-t-"] td { background:rgba(var(--sc-c), 0.24); }
    .sc-tbl tr.sc-grp[class*="sc-t-"] td { background:rgba(var(--sc-c), 0.14); }
    .sc-legend { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 8px; font-size:0.8rem; }
    .sc-legend button { padding:2px 9px; border-radius:8px; border:1px solid transparent; cursor:pointer; font:inherit; color:inherit;
                        background:rgba(var(--sc-c), 0.2); box-shadow:inset 4px 0 0 rgba(var(--sc-c), 0.85); }
    .sc-legend button:hover { background:rgba(var(--sc-c), 0.35); }
    .sc-legend button.on { background:rgba(var(--sc-c), 0.45); border-color:rgba(var(--sc-c), 1); font-weight:bold; }
    .sc-legend.filtering button:not(.on) { opacity:0.45; }
    .sc-tbl th.sc-sort { cursor:pointer; user-select:none; white-space:nowrap; }
    .sc-tbl th.sc-sort:hover { color:var(--color-primary); }
    .sc-tbl th.sc-sort span { display:inline-block; width:1em; color:var(--text-muted); }
</style>
<form method="post" id="coa-form">
    <?php csrf_field(); ?>
    <div class="sc-grid">
    <?php foreach ($cards as $k => [$capt, $desc, $rec]): ?>
        <label class="sc-box" id="box-<?= $k ?>">
            <input type="radio" name="template" value="<?= $k ?>" <?= $k === $sel ? 'checked' : '' ?> onchange="selectTemplate('<?= $k ?>')">
            <strong><?= lang($capt) ?></strong><?= $rec ? ' <span class="sc-rec">' . lang('@recommended') . '</span>' : '' ?>
            <p><?= lang($desc) ?></p>
        </label>
    <?php endforeach; ?>
    </div>

    <div class="sc-note" id="note-free"<?= $coaInUse ? ' style="display:none;"' : '' ?>>
        <strong><i class="fa fa-info-circle"></i> <?= lang('@Notice regarding flexibility:') ?></strong><br>
        <?= lang('@Your choice above is just a starting point. Once the installation is complete, you can always freely create new accounts under your settings. You can also delete standard accounts that have not yet been used in your bookkeeping.') ?>
        <?= lang('@The posting accounts in Company Settings and the VAT codes are set to the accounts of the chosen chart.') ?>
        <?= lang('@The three templates are selections from the standard chart of accounts with the same numbers and names - you can import the rest of the standard chart later without any conflicts.') ?>
    </div>
    <?php if ($coaInUse): ?>
    <div class="sc-note">
        <strong><i class="fa fa-info-circle"></i> <?= lang('@Your chart of accounts is already set up.') ?></strong><br>
        <span id="note-official"><?= lang('@You can look through the standard chart of accounts here. To use it, switch to it from a date: your balances are transferred and settings, VAT codes and other references follow. Where one of your own accounts already uses a number, it is marked with a suggested solution - you choose the solution in the switch.') ?></span>
        <span id="note-tpl"><?= lang('@The templates can only be installed in an empty chart of accounts - here they are shown for reference.') ?></span>
    </div>
    <?php endif; ?>

    <div class="sc-prev">
        <div style="display:flex; gap:10px; align-items:center; justify-content:space-between; margin-bottom:10px; flex-wrap:wrap;">
            <strong><i class="fa fa-eye"></i> <?= lang('@Preview of the selected chart of accounts') ?></strong>
            <input type="search" id="sc-search" oninput="renderPreview()" style="flex:1; min-width:180px; max-width:320px; padding:5px 8px; border:1px solid var(--border-color); border-radius:4px;">
            <label id="sc-use-lbl" style="display:none; white-space:nowrap; cursor:pointer;"><input type="checkbox" id="sc-use" onchange="renderPreview()"> <?= lang('@Only accounts that match your accounts in use') ?> (<span id="sc-use-n"></span>)</label>
            <label id="sc-clash-lbl" style="display:none; white-space:nowrap; cursor:pointer;"><input type="checkbox" id="sc-clash" onchange="renderPreview()"> <?= lang('@Only accounts with a conflict') ?> (<span id="sc-clash-n"></span>)</label>
            <span id="account-count" class="sc-tag"></span>
        </div>
        <div class="sc-legend" id="sc-legend"></div>
        <div class="sc-scroll">
            <table class="sc-tbl">
                <thead><tr><?php foreach ([['no', '@Account', 'width:80px;'], ['name', '@Account Name', ''], ['type', '@Type', 'width:110px;'], ['vat', '@VAT %', 'width:90px;']] as [$k, $c, $w]): ?><th class="sc-sort" data-key="<?= $k ?>" style="<?= $w ?>" onclick="sortBy('<?= $k ?>')" title="<?= htmlspecialchars(lang('@Click to sort')) ?>"><?= lang($c) ?> <span></span></th><?php endforeach; ?></tr></thead>
                <tbody id="preview-table-body"></tbody>
            </table>
        </div>
    </div>

    <div style="text-align:right;">
    <?php if (!$coaInUse): ?>
        <button type="submit" style="padding:12px 30px; color:#fff; border:none; border-radius:6px; font-weight:bold; cursor:pointer; background:var(--color-primary);"
                onclick="return confirm(<?= htmlspecialchars(json_encode(lang('@Install the selected chart of accounts?')), ENT_QUOTES) ?>)">
            <i class="fa fa-check-circle"></i> <?= lang('@Install selected chart of accounts and start') ?>
        </button>
    <?php else: ?>
        <a id="btn-switch" href="chart_switch.php" data-hint="<?= htmlspecialchars(lang('@Go to the switch: choose replacements and a date, see the plan before anything is done')) ?>" style="display:inline-block; padding:12px 30px; color:#fff; border-radius:6px; font-weight:bold; text-decoration:none; background:var(--color-primary);">
            <i class="fa-solid fa-right-left"></i> <?= lang('@Switch to the Standard Chart of Accounts') ?></a>
        <button id="btn-locked" type="button" disabled style="padding:12px 30px; border:1px solid var(--border-color); border-radius:6px; font-weight:bold; cursor:not-allowed;"
                title="<?= htmlspecialchars(lang('@The system already contains data. Installation is locked.')) ?>">
            <?= lang('@Chart of accounts already set up') ?></button>
    <?php endif; ?>
    </div>
</form>
<?php
htm_Card_end();
echo "</div>";
?>
<script>
const coaTemplates = <?= json_encode($preview, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
const scTxt = <?= json_encode($js_txt, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
let scCurrent = <?= json_encode($sel) ?>;
let scSort = {key: 'no', dir: 1};
let scTypes = new Set();                 // farvekode-knapperne: valgte kontotyper (tom = alle)        // standard: kontonummer stigende - kun da vises afsnits-/gruppeoverskrifterne

function scCell(tr, text, css) { const td = document.createElement('td'); td.textContent = text; if (css) td.style.cssText = css; tr.appendChild(td); return td; }
function scTag(td, text, kind) { const s = document.createElement('span'); s.className = 'sc-tag' + (kind === true ? ' warn' : (kind ? ' ' + kind : '')); s.textContent = text; td.appendChild(s); return s; }

// Svarer kontoen til noget du bruger: selve standardkontoen er i brug, en af dine konti er knyttet til den, eller den er
// den foreslåede afløser for en af dine konti i brug
// Skabelonerne bruger standardnumrene, så deres rækker får samme oplysninger som standardkontoplanens række med samme nummer
const scOff = {};
(coaTemplates.official || []).forEach(a => scOff[a.no] = a);
function scInfo(a) { return scCurrent === 'official' ? a : (scOff[a.no] || {}); }
function scUsed(a) { const u = scInfo(a); return !!(u.use || (u.via && u.via.length) || (u.prop && u.prop.length)); }

function selectTemplate(name) {
    scCurrent = name;
    document.querySelectorAll('.sc-box').forEach(b => b.classList.toggle('sel', b.id === 'box-' + name));
    const off = name === 'official';
    [['note-official', off], ['note-tpl', !off], ['btn-switch', off], ['btn-locked', !off]].forEach(([id, show]) => {
        const el = document.getElementById(id); if (el) el.style.display = show ? '' : 'none';
    });
    const nUse = (coaTemplates[name] || []).filter(scUsed).length;
    document.getElementById('sc-use-lbl').style.display = nUse ? '' : 'none';
    document.getElementById('sc-use-n').textContent = nUse;
    if (!nUse) document.getElementById('sc-use').checked = false;
    const nClash = (coaTemplates[name] || []).filter(a => a.state === 'clash').length;
    document.getElementById('sc-clash-lbl').style.display = nClash ? '' : 'none';
    document.getElementById('sc-clash-n').textContent = nClash;
    if (!nClash) document.getElementById('sc-clash').checked = false;
    // Farvekode-knapper: kun de typer kontoplanen har, med antal - klik filtrerer (flere kan vælges)
    const cnt = {};
    (coaTemplates[name] || []).forEach(a => cnt[a.type] = (cnt[a.type] || 0) + 1);
    scTypes.forEach(t => { if (!cnt[t]) scTypes.delete(t); });
    const lg = document.getElementById('sc-legend');
    lg.textContent = '';
    Object.entries(scTxt.types).forEach(([k, t]) => {
        if (!cnt[k]) return;
        const b = document.createElement('button');
        b.type = 'button'; b.dataset.type = k; b.className = 'sc-t-' + k + (scTypes.has(k) ? ' on' : '');
        b.textContent = t + ' (' + cnt[k] + ')'; b.title = scTxt.filter;
        b.onclick = () => { scTypes.has(k) ? scTypes.delete(k) : scTypes.add(k); b.classList.toggle('on', scTypes.has(k)); renderPreview(); };
        lg.appendChild(b);
    });
    renderPreview();
}

function sortBy(key) {
    scSort = {key: key, dir: scSort.key === key ? -scSort.dir : 1};
    renderPreview();
}
function scSortVal(a, key) {
    if (key === 'no') return a.no;
    if (key === 'vat') return a.rate * 1000 + (a.vat ? 1 : 0);
    if (key === 'type') return (scTxt.types[a.type] || a.type).toLowerCase();
    return a.name.toLowerCase();
}

function renderPreview() {
    const q = document.getElementById('sc-search').value.trim().toLowerCase();
    const tbody = document.getElementById('preview-table-body');
    tbody.textContent = '';
    const onlyClash = document.getElementById('sc-clash').checked;
    const onlyUse = document.getElementById('sc-use').checked;
    const heads = scSort.key === 'no' && scSort.dir === 1;
    const rows = (coaTemplates[scCurrent] || []).slice();
    if (!heads) rows.sort((x, y) => { const a = scSortVal(x, scSort.key), b = scSortVal(y, scSort.key);
        return (a < b ? -1 : a > b ? 1 : x.no - y.no) * scSort.dir; });
    document.querySelectorAll('th.sc-sort').forEach(th => th.querySelector('span').textContent = th.dataset.key === scSort.key ? (scSort.dir > 0 ? '▲' : '▼') : '');
    let n = 0, sec = null, grp = null;
    rows.forEach(a => {
        if (onlyClash && a.state !== 'clash') return;
        if (onlyUse && !scUsed(a)) return;
        if (scTypes.size && !scTypes.has(a.type)) return;
        if (q && !(String(a.no).startsWith(q) || a.name.toLowerCase().includes(q) || (a.group || '').toLowerCase().includes(q))) return;
        n++;
        if (heads && a.section !== undefined && a.section !== sec) { sec = a.section; grp = null; const r = tbody.insertRow(); r.className = 'sc-sec sc-t-' + a.type; scCell(r, sec).colSpan = 4; }
        if (heads && a.group !== undefined && a.group !== grp && a.group !== sec) { grp = a.group; const r = tbody.insertRow(); r.className = 'sc-grp sc-t-' + a.type; scCell(r, grp).colSpan = 4; }
        const tr = tbody.insertRow();
        tr.className = 'sc-t-' + a.type;
        const u = scInfo(a);
        const noCell = scCell(tr, '', 'font-family:monospace; font-weight:bold; white-space:nowrap;');
        noCell.appendChild(document.createTextNode(a.no));      // nummeret først, så kolonnen flugter
        // ✓ efter nummeret: kontoen svarer til en af dine konti i brug (hint: hvordan)
        if (scUsed(a)) {
            const ck = document.createElement('span');
            ck.className = 'sc-check' + (u.use || (u.via && u.via.length) ? '' : ' prop');
            ck.textContent = '✓';
            ck.dataset.hint = u.use ? scTxt.inuse_hint : (u.via && u.via.length ? scTxt.via + ': ' + u.via.join(', ') : scTxt.prop + ': ' + u.prop.join(', '));
            noCell.appendChild(ck);
            tr.classList.add('sc-used');
        }
        const nm = scCell(tr, a.name);
        if (a.role) a.role.split(',').forEach(r => scTag(nm, scTxt.roles[r] || r));
        if (u.use) { const t = scTag(nm, scTxt.inuse, 'use'); t.dataset.hint = scTxt.inuse_hint; }
        else if (scCurrent === 'official' && a.state === 'imported') scTag(nm, a.as ? scTxt.as.replace('%d', a.as) : scTxt.imported);
        if (u.use && u.as) scTag(nm, scTxt.as.replace('%d', u.as));
        if (u.via && u.via.length) scTag(nm, scTxt.via + ': ' + u.via.join(', '), 'via');
        if (u.prop && u.prop.length) { const t = scTag(nm, scTxt.prop + ': ' + u.prop.join(', '), 'prop'); t.dataset.hint = scTxt.prop_hint; }
        if (a.state === 'clash') scTag(nm, scTxt.clash + ': ' + a.mine + (a.sol ? ' · ' + scTxt.sol[a.sol] : ''), true);
        scCell(tr, scTxt.types[a.type] || a.type, 'font-size:0.8rem;');
        scCell(tr, a.vat ? a.vat + ' (' + a.rate + '%)' : (a.rate ? a.rate + '%' : ''), 'font-family:monospace;');
    });
    document.getElementById('account-count').textContent = n + ' ' + scTxt.accounts;
    document.getElementById('sc-legend').classList.toggle('filtering', scTypes.size > 0);
}

document.getElementById('sc-search').placeholder = scTxt.search;
selectTemplate(scCurrent);
</script>
<?php
htm_Footer();
?>
