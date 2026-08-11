<?php # /expense_edit.php v:1.2.0 d:2026-08-11 i:evs 
# (Valuta-sektion gates af module_currency; bevarer data når slået fra)
ob_start();
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/depot_worker.inc.php';
require_once 'inc/help.lib.php';

$id  = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$msg = ""; $err = "";

$date         = date('Y-m-d');
$supp         = '';
$desc         = '';
$acc          = '';
$amount       = '';
$vouch        = '';
$proj         = 0;
$exp_type     = 'expense';
$currency     = 'DKK';
$orig_currency = null;
$orig_amount   = null;
$exch_rate     = null;
$current_attachment = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_ai_action']) && $_POST['toggle_ai_action'] === '1') {
    $_SESSION['use_ai_scan'] = isset($_POST['use_ai_scan_toggle']) ? 1 : 0;
    header("Location: expense_edit.php?id=" . $id);
    exit;
}
$use_ai_scan = isset($_SESSION['use_ai_scan']) ? (int)$_SESSION['use_ai_scan'] : 1;

if ($id === 0 && isset($_GET['type']) && $_GET['type'] === 'income') {
    $exp_type = 'income';
}

if ($id > 0) {
    $res = DB::query($conn, "SELECT * FROM expenses WHERE exp_id = $id AND is_cancelled = 0");
    if ($exp = DB::fetch_assoc($res)) {
        $date          = $exp['exp_date'];
        $supp          = $exp['supplier'];
        $desc          = $exp['description'];
        $acc           = $exp['account_id'];
        $amount        = $exp['amount'];
        $vouch         = $exp['voucher_no'];
        $proj          = (int)($exp['proj_id'] ?? 0);
        $exp_type      = $exp['exp_type'] ?? 'expense';
        $currency      = $exp['currency'] ?? 'DKK';
        $orig_currency = $exp['orig_currency'] ?? null;
        $orig_amount   = $exp['orig_amount']   ?? null;
        $exch_rate     = $exp['exch_rate']     ?? null;
        $current_attachment = $exp['attachment'];
    } else {
        header("Location: expense_list.php?err=not_found");
        exit;
    }
}

$s = get_settings($conn);
$currency_module = !empty($s['module_currency']) && $s['module_currency'] == '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attachment_sq      = "";
    $new_filename       = null;
    $file_to_delete     = null;
    $depot_file_to_remove = null;
    $file_to_scan       = null;

    if (isset($_POST['delete_attachment']) && $_POST['delete_attachment'] == '1' && $id > 0 && !empty($current_attachment)) {
        $file_to_delete     = "uploads/" . $current_attachment;
        $attachment_sq      = ", attachment = ''";
        $current_attachment = "";
    }

    $target_dir = "uploads/";
    if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);

    if (!empty($_FILES['attachment']['name']) && $_FILES['attachment']['error'] == 0) {
        $ext          = strtolower(pathinfo($_FILES["attachment"]["name"], PATHINFO_EXTENSION));
        $new_filename = "EXP_" . date('Ymd') . "_" . uniqid() . "." . $ext;
        if (move_uploaded_file($_FILES["attachment"]["tmp_name"], $target_dir . $new_filename)) {
            $file_to_scan = $target_dir . $new_filename;
            if (!empty($current_attachment)) $file_to_delete = "uploads/" . $current_attachment;
        }
    } elseif (!empty($_POST['depot_file_path'])) {
        $depot_source_path   = $_POST['depot_file_path'];
        $absolute_depot_path = file_exists(__DIR__ . '/../' . $depot_source_path)
            ? __DIR__ . '/../' . $depot_source_path
            : __DIR__ . '/' . $depot_source_path;
        if (file_exists($absolute_depot_path)) {
            $ext          = strtolower(pathinfo($absolute_depot_path, PATHINFO_EXTENSION));
            $new_filename = "EXP_" . date('Ymd') . "_" . uniqid() . "." . $ext;
            if (copy($absolute_depot_path, $target_dir . $new_filename)) {
                $file_to_scan         = $target_dir . $new_filename;
                $depot_file_to_remove = $absolute_depot_path;
                if (!empty($current_attachment)) $file_to_delete = "uploads/" . $current_attachment;
            }
        }
    }

    $date     = htmlspecialchars($_POST['exp_date']    ?? $date);
    $amount   = htmlspecialchars($_POST['amount']      ?? $amount);
    $supp     = htmlspecialchars($_POST['supplier']    ?? $supp);
    $desc     = htmlspecialchars($_POST['description'] ?? $desc);
    $acc      = htmlspecialchars($_POST['account_id']  ?? $acc);
    $proj     = (int)($_POST['proj_id']   ?? 0);
    $exp_type = in_array($_POST['exp_type'] ?? '', ['expense','income']) ? $_POST['exp_type'] : 'expense';

    // Valuta-felter fra POST
    $post_currency      = strtoupper(preg_replace('/[^A-Z]/', '', $_POST['currency']      ?? 'DKK'));
    $post_orig_currency = strtoupper(preg_replace('/[^A-Z]/', '', $_POST['orig_currency'] ?? ''));
    $post_orig_amount   = (float)str_replace(',', '.', $_POST['orig_amount'] ?? '');
    $post_exch_rate     = (float)str_replace(',', '.', $_POST['exch_rate']   ?? '');

    // Hvis fremmed valuta er valgt: beregn/gem kursdata
    if ($post_orig_currency !== '' && $post_orig_currency !== 'DKK' && $post_exch_rate > 0) {
        $orig_currency = $post_orig_currency;
        $orig_amount   = $post_orig_amount > 0 ? $post_orig_amount : null;
        $exch_rate     = $post_exch_rate;
        $currency      = 'DKK'; // bogføres altid i DKK
    } else {
        $orig_currency = null;
        $orig_amount   = null;
        $exch_rate     = null;
        $currency      = $post_currency ?: 'DKK';
    }

    if ($use_ai_scan === 1 && $file_to_scan && file_exists($file_to_scan)) {
        $ai_data = scanBilagMedOpenAI($file_to_scan);
        if (isset($ai_data['error'])) { echo "<pre>OpenAI Error: "; print_r($ai_data['error']); echo "</pre>"; die(); }
        if ($ai_data) {
            $is_scan_action = isset($_POST['action_scan_only']);
            if ($is_scan_action || empty($_POST['exp_date']) || $_POST['exp_date'] === date('Y-m-d')) {
                if (!empty($ai_data['dato'])) { $date = $ai_data['dato']; $_POST['exp_date'] = $date; }
            }
            if ($is_scan_action || empty($_POST['amount']) || $_POST['amount'] === '0,00' || $_POST['amount'] === '0') {
                if (isset($ai_data['total'])) { $amount = (string)$ai_data['total']; $_POST['amount'] = $amount; }
            }
            if ($is_scan_action || empty($_POST['supplier'])) {
                if (!empty($ai_data['leverandor'])) { $supp = $ai_data['leverandor']; $_POST['supplier'] = $supp; }
            }
            if ($new_filename) { $current_attachment = $new_filename; $new_filename = null; }
        }
    }

    if (isset($_POST['action_save'])) {
        $db_supp         = DB::real_escape_string($conn, $supp);
        $db_date         = DB::real_escape_string($conn, $date);
        $db_desc         = DB::real_escape_string($conn, $desc);
        $db_amount       = (float)str_replace(',', '.', $amount);
        $db_acc          = DB::real_escape_string($conn, $acc);
        $db_proj         = ($proj > 0) ? $proj : 'NULL';
        $db_currency     = DB::real_escape_string($conn, $currency);
        $db_orig_cur     = $orig_currency ? "'".DB::real_escape_string($conn, $orig_currency)."'" : 'NULL';
        $db_orig_amount  = $orig_amount   ? $orig_amount  : 'NULL';
        $db_exch_rate    = $exch_rate     ? $exch_rate    : 'NULL';
        $current_user_id = (int)($_SESSION['user_id'] ?? 1);

        DB::begin_transaction($conn);
        $final_file    = $new_filename ?? $current_attachment;
        $attachment_sq = ", attachment = '$final_file'";

        if ($id > 0) {
            $db_exp_type = ($exp_type === 'income') ? 'income' : 'expense';
            $sql = "UPDATE expenses SET
                        exp_date      = '$db_date',
                        supplier      = '$db_supp',
                        description   = '$db_desc',
                        account_id    = '$db_acc',
                        amount        = '$db_amount',
                        proj_id       = $db_proj,
                        exp_type      = '$db_exp_type',
                        currency      = '$db_currency',
                        orig_currency = $db_orig_cur,
                        orig_amount   = $db_orig_amount,
                        exch_rate     = $db_exch_rate
                        $attachment_sq
                    WHERE exp_id = $id AND is_cancelled = 0";
            $final_voucher = $vouch;
        } else {
            $vouch_res     = DB::query($conn, "SELECT MAX(voucher_no) AS max_v FROM expenses");
            $vouch_row     = DB::fetch_assoc($vouch_res);
            $final_voucher = ($vouch_row['max_v'] > 0) ? ((int)$vouch_row['max_v'] + 1) : 10001;
            $db_exp_type   = ($exp_type === 'income') ? 'income' : 'expense';
            $sql = "INSERT INTO expenses
                        (exp_date, supplier, description, account_id, amount, attachment, voucher_no,
                         created_by, proj_id, exp_type, currency, orig_currency, orig_amount, exch_rate)
                    VALUES
                        ('$db_date', '$db_supp', '$db_desc', '$db_acc', '$db_amount', '$final_file',
                         $final_voucher, $current_user_id, $db_proj, '$db_exp_type',
                         '$db_currency', $db_orig_cur, $db_orig_amount, $db_exch_rate)";
        }

        if (DB::query($conn, $sql)) {
            $expense_id   = ($id > 0) ? $id : DB::insert_id($conn);
            $journal_text = "Udgift: " . $db_supp . ($db_desc ? " - " . $db_desc : "");
            $check_jou    = DB::query($conn, "SELECT jou_id FROM journal WHERE jou_text LIKE 'Udgift ID %' OR (voucher_no = '$final_voucher' AND is_cancelled = 0) LIMIT 1");
            if ($jou_row = DB::fetch_assoc($check_jou)) {
                DB::query($conn, "UPDATE journal SET jou_date = '$db_date', voucher_no = '$final_voucher', jou_text = 'Udgift ID $expense_id: $journal_text', proj_id = $db_proj WHERE jou_id = ".$jou_row['jou_id']);
            } else {
                DB::query($conn, "INSERT INTO journal (jou_date, voucher_no, jou_text, proj_id) VALUES ('$db_date', '$final_voucher', 'Udgift ID $expense_id: $journal_text', $db_proj)");
            }
            DB::commit($conn);
            if ($file_to_delete && file_exists($file_to_delete)) @unlink($file_to_delete);
            if ($depot_file_to_remove && file_exists($depot_file_to_remove)) @unlink($depot_file_to_remove);
            header("Location: expense_list.php?msg=saved");
            exit;
        } else {
            DB::rollback($conn);
            $err = "SQL Error: " . DB::error($conn);
        }
    }
}

$page_title = $id > 0 ? lang('@Edit Document / Voucher') : lang('@Register Document / Voucher');

htm_Header($page_title);
showMenu();
htm_HelpSystem();
if ($err) htm_Alert($err, 'error');

echo '<form id="global_exp_form" method="POST" action="expense_edit.php?id='.$id.'" enctype="multipart/form-data" target="_self">';
echo '<div id="ai_toggle_container" style="display:none;"><input type="hidden" id="hidden_ai_action" name="toggle_ai_action" value="0"><input type="checkbox" id="hidden_ai_checkbox" name="use_ai_scan_toggle" value="1" '.($use_ai_scan === 1 ? 'checked' : '').'></div>';
echo '<div style="display:flex; gap:10px; max-width:1400px; margin:0 auto; padding:0 10px; align-items:flex-start;">';

    // VENSTRE SIDE (depot — uændret)
    echo '<div style="flex:6; min-width:300px; position:sticky; top:20px;">';
        htm_Card_('🗄️ ' . lang('@Depot & Dropzone'), '100%', '', false);
          echo '<div id="MainDropzone" style="margin-bottom:15px; border:2px dashed #34495e; border-radius:4px; height:calc(100vh - 510px); min-height:350px; background:#f1f2f6; position:relative; overflow:hidden !important; user-select:none;">';
                echo '<div id="previewPlaceholder" style="color:#7f8c8d; text-align:center; padding:20px; position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); z-index:1; width:80%;">
                    <i class="fa-solid fa-cloud-arrow-up" style="font-size:3em; color:#718096; margin-bottom:10px;"></i><br>
                    <strong>' . lang('@Drag voucher here') . '</strong><br>
                    <span style="font-size:12px; color:#95a5a6;">' . lang('@Or click a document below') . '</span>
                    <div style="margin-top:20px; padding:8px; border-top:1px solid #e2e8f0; font-size:11px; color:#7f8c8d; line-height:1.5;">
                        <i class="fa-solid fa-circle-info"></i> <strong>' . lang('@Navigation:') . '</strong> ' . lang('@Scroll wheel to zoom') . ' • ' . lang('@Click & drag to pan') . ' • ' . lang('@Double click to reset') . '
                    </div>
                </div>';
                echo '<div class="zoom-help-tooltip" style="position:absolute; top:10px; right:10px; z-index:20; background:rgba(52,73,94,0.8); color:white; width:22px; height:22px; border-radius:50%; text-align:center; line-height:22px; font-weight:bold; cursor:help; font-size:12px;">?
                    <span class="zoom-help-text" style="visibility:hidden; width:220px; background-color:#2c3e50; color:#fff; text-align:left; border-radius:6px; padding:10px; position:absolute; z-index:21; top:30px; right:0; opacity:0; transition:opacity 0.2s; font-weight:normal; font-size:11px; line-height:1.6; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
                        <strong>🔍 ' . lang('@Zoom & Navigation:') . '</strong><br>
                        • <strong>' . lang('@Scroll wheel:') . '</strong> ' . lang('@Zoom in/out on mouse position.') . '<br>
                        • <strong>' . lang('@Click & Drag:') . '</strong> ' . lang('@Move around the document.') . '<br>
                        • <strong>' . lang('@Double click:') . '</strong> ' . lang('@Reset to 100% view.') . '
                    </span>
                </div>';
        echo '<div id="zoomWrapper" style="width:100%; display:none; transform-origin:0px 0px; transform:scale(1) translate(0px,0px); cursor:grab; position:absolute; top:0; left:0; margin:0; padding:0; overflow:hidden !important;">';
            echo '<iframe id="pdfPreview" style="width:104%; height:100%; border:none; display:block; pointer-events:none; margin:0; padding:0; overflow:hidden !important;" scrolling="no"></iframe>';
            echo '<img id="imgPreview" style="width:100%; height:auto; display:none; margin:0; padding:0; object-fit:contain;" src="">';
        echo '</div>';
                echo '<div id="selectedBadge" style="display:none; position:absolute; bottom:0; left:0; right:0; background:rgba(46,204,113,0.95); color:white; padding:8px; text-align:center; font-weight:bold; font-size:12px; z-index:10;"></div>';
            echo '</div>';

            $depot = getDepotFiles();
            echo '<div class="depot-tabs" style="display:flex; background:#e2e8f0; border-radius:4px 4px 0 0; padding:4px 4px 0 4px; gap:2px;">';
            $active_tab = "mail";
            foreach (['mail' => lang('@Mail'), 'scanner' => lang('@Scanner'), 'photo' => lang('@Photo'), 'download' => lang('@Download')] as $key => $lbl) {
                $count = isset($depot[$key]) ? count($depot[$key]) : 0;
                echo '<button type="button" class="tab-lnk '.($key === $active_tab ? 'active':'').'" onclick="skiftDepotTab(\''.$key.'\')" id="tab_btn_'.$key.'" style="flex:1; padding:6px; border:none; border-radius:4px 4px 0 0; font-size:11px; font-weight:bold; cursor:pointer; background:#cbd5e0;">'.$lbl.' ('.$count.')</button>';
            }
            echo '</div>';
            echo '<div style="max-height:140px; overflow-y:auto; border:1px solid #cbd5e0; border-top:none; padding:10px; border-radius:0 0 4px 4px; background:#fafafa; font-size:13px; margin-bottom:10px;">';
            foreach (['mail', 'scanner', 'photo', 'download'] as $source) {
                $files = $depot[$source] ?? [];
                echo '<div class="tab-content-panel" id="panel_'.$source.'" style="display:'.($source === $active_tab ? 'block':'none').';">';
                if ($source === 'mail') {
                    echo '<div style="margin-bottom:8px; padding:6px 10px; background:#edf2f7; border-radius:4px; font-size:11px; color:#4a5568; display:flex; align-items:center; justify-content:space-between; gap:10px;">';
                    echo '<span><i class="fa-solid fa-circle-info" style="color:#3182ce;"></i> '.lang('@Open the document inbox to register a voucher directly from an email.').'</span>';
                    echo '<a href="mail_inbox.php?box=voucher" style="color:#2b6cb0; font-weight:bold; text-decoration:none; white-space:nowrap;"><i class="fa-solid fa-envelope"></i> '.lang('@Open Inbox').'</a>';
                    echo '</div>';
                }
                if (empty($files)) {
                    echo '<div style="color:#999; padding:15px; text-align:center; font-style:italic;">'.lang('@No files found').'</div>';
                } else {
                    foreach ($files as $file) {
                        echo '<div class="depot-item" id="item_'.md5($file['rel_path']).'" style="padding:6px 10px; display:flex; justify-content:space-between; align-items:center; border-bottom:1px dashed #eee; cursor:pointer;" onclick="previewDepotFile(\''.$file['rel_path'].'\')">';
                        echo '<span style="color:#2980b9; word-break:break-all;">📄 '.htmlspecialchars($file['filename']).'</span>';
                        echo '<button type="button" onclick="selectDepotFile(event, \''.$file['rel_path'].'\', \''.htmlspecialchars($file['filename']).'\')" style="background:#e67e22; color:white; border:none; padding:3px 8px; border-radius:3px; font-size:11px; cursor:pointer; font-weight:bold;">'.lang('@Select').'</button>';
                        echo '</div>';
                    }
                }
                echo '</div>';
            }
            echo '</div>';
            echo '<div style="display:flex; gap:8px;">';
            echo '<label style="flex:2; display:block; background:#e2e8f0; color:#4a5568; text-align:center; padding:10px; border-radius:4px; cursor:pointer; font-weight:bold; font-size:12px; border:1px solid #cbd5e0; margin:0;">📁 '.lang('@Choose local file').'<input type="file" name="attachment" style="display:none;" onchange="handleLocalFileSelect(this);"></label>';
            echo '<button type="submit" name="action_scan_only" value="1" id="AiScanButton" formnovalidate style="flex:1; display:none; background:#e67e22; color:white; border:none; padding:10px; border-radius:4px; font-weight:bold; cursor:pointer; font-size:12px;"><i class="fa-solid fa-robot"></i> '.lang('@Fetch data with AI').'</button>';
            echo '</div>';
        htm_Card_end();
    echo '</div>';

    // HØJRE SIDE
    echo '<div style="flex:6; min-width:320px;">';
        htm_Card_('📝 ' . $page_title, '100%');

            htm_InputGroup(icon:'fa-tag', labl:'@Type', name:'exp_type', valu:$exp_type, type:'sele',
                opti:['expense' => '📤 '.lang('@Expense'), 'income' => '📥 '.lang('@Income')], wdth:'30%');
            htm_ProjektCodeField($conn, $proj ?: null, '30%');

            $header_switch = '<br><label style="font-weight:bold; cursor:help; margin:15px; align-items:center; gap:6px; font-size:14px; user-select:none; color:#7f8c8d;">'
                . '<input type="checkbox" id="visible_ai_trigger" '.($use_ai_scan === 1 ? 'checked' : '').' onchange="triggerAiToggle(this.checked);" style="width:14px; height:14px; cursor:pointer; margin:0; vertical-align:middle;">🤖 '.lang('@AI Scan').': ';
            $header_switch .= $use_ai_scan === 1
                ? '<span style="color:#2ecc71;">'.lang('@Active').'<span style="color:#000; font-weight:normal;"> -- '.lang('@Colored fields can be filled by AI Scan.').'</span></span>'
                : '<span style="color:#e67e22;">'.lang('@Off').'</span>';
            $header_switch .= '</label><br><br>';
            echo $header_switch;

        echo '<div style="margin-bottom:15px; font-size:12px; color:#4a5568; line-height:1.4;">';
            htm_InputGroup('fa-hashtag', '@Voucher',     'vouch',    ($id > 0 ? $vouch : lang('@Generated')), 'text', null, 'readonly class="text-center" leg:left', '30%');
            htm_InputGroup('fa-calendar', '@Date',       'exp_date', $date,   'date', null, 'required', '40%');
            htm_InputGroup('fa-coins',    '@Amount DKK', 'amount',   number_format((float)$amount, 2, ',', ''), 'text', null, 'required style="text-align:right;"', '30%');

            // ── Valuta-sektion (kun når valuta-modulet er aktivt) ────────────
            if ($currency_module) {
            echo '<div id="currency-section" style="margin:10px 5px; padding:10px; background:var(--bg-panel); border-radius:8px; border:2px dashed blue;">';
            echo '<div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">';
            echo '<label style="font-weight:bold; font-size:13px; color:var(--text-main); white-space:nowrap;"><i class="fa fa-exchange" style="margin-right:5px; color:#7f8c8d;"></i>'.lang('@Foreign currency').'</label>';
            echo '<label style="font-size:12px; cursor:pointer; display:flex; align-items:center; gap:5px; color:var(--text-muted);">';
            $fc_checked = ($orig_currency && $orig_currency !== 'DKK') ? 'checked' : '';
            echo '<input type="checkbox" id="fc-toggle" '.$fc_checked.' onchange="toggleForeignCurrency(this.checked)"> '.lang('@Receipt is in foreign currency');
            echo '</label></div>';

            echo '<div id="fc-fields" style="display:'.($fc_checked ? 'grid' : 'none').'; grid-template-columns:1fr 1fr 1fr; gap:8px; align-items:end;">';

            // Originalvaluta
            echo '<div><label style="font-size:11px; font-weight:bold; color:var(--text-muted);">'.lang('@Currency').'</label>';
            echo '<select name="orig_currency" id="fc-currency" onchange="fetchRate()" style="width:100%; padding:6px; border-radius:4px; border:1px solid var(--border-color); background:var(--bg-card); color:var(--text-main); font-size:13px;">';
            $currencies = ['EUR','USD','GBP','SEK','NOK','CHF','JPY','CAD','AUD','PLN','CZK','HUF','RON','ISK','DKK'];
            foreach ($currencies as $c) {
                $sel = ($orig_currency === $c) ? ' selected' : '';
                echo '<option value="'.$c.'"'.$sel.'>'.$c.'</option>';
            }
            echo '</select></div>';

            // Beløb i originalvaluta
            echo '<div><label style="font-size:11px; font-weight:bold; color:var(--text-muted);">'.lang('@Amount in currency').'</label>';
            echo '<input type="text" name="orig_amount" id="fc-orig-amount" value="'.($orig_amount ? number_format($orig_amount, 2, ',', '') : '').'"
                oninput="calcDkk()" placeholder="0,00"
                style="width:100%; padding:6px; border-radius:4px; border:1px solid var(--border-color); background:var(--bg-card); color:var(--text-main); font-size:13px; text-align:right; box-sizing:border-box;"></div>';

            // Kurs
            echo '<div><label style="font-size:11px; font-weight:bold; color:var(--text-muted);">'.lang('@Exchange rate').' <span id="fc-rate-date" style="font-weight:normal; color:var(--text-muted); font-size:10px;"></span></label>';
            echo '<div style="display:flex; gap:4px;">';
            echo '<input type="text" name="exch_rate" id="fc-rate" value="'.($exch_rate ? number_format($exch_rate, 4, ',', '') : '').'"
                oninput="calcDkk()" placeholder="0,0000"
                style="flex:1; padding:6px; border-radius:4px; border:1px solid var(--border-color); background:var(--bg-card); color:var(--text-main); font-size:13px; text-align:right;">';
            echo '<button type="button" onclick="fetchRate()" title="'.lang('@Fetch current rate').'"
                style="padding:6px 8px; background:var(--color-primary); color:white; border:none; border-radius:4px; cursor:pointer; font-size:13px;">↻</button>';
            echo '</div></div>';

            echo '</div>'; // fc-fields

            // Info-linje
            echo '<div id="fc-info" style="display:'.($fc_checked ? 'block' : 'none').'; margin-top:8px; font-size:11px; color:var(--text-muted); background:var(--bg-card); padding:6px 10px; border-radius:4px; border-left:3px solid var(--color-primary);">';
            echo '<i class="fa fa-info-circle"></i> '.lang('@DKK amount is calculated automatically. Exchange rate is saved with the voucher per Danish bookkeeping law.');
            echo '</div>';
            echo '</div>'; // currency-section
            } // if currency_module
            // ── Slut valuta-sektion ─────────────────────────────────────────

            htm_InputGroup('fa-truck',       '@Supplier',    'supplier',    $supp, 'text', null, 'required leg:left', '100%');
            htm_InputGroup('fa-info-circle', '@Description', 'description', $desc, 'text', null, 'leg:left',          '100%');

            echo '<div style="margin-bottom:15px; padding:0 5px; margin-top:15px;">';
            echo '<label style="display:block; font-weight:bold; margin-bottom:8px; font-size:13px; color:#2c3e50; text-align:left;"><i class="fa fa-list" style="margin-right:5px; color:#7f8c8d;"></i>'.lang('@Select Finance Account');
            echo '<span id="AccountPanelHint" style="font-size:11px; color:#e67e22; font-style:italic; font-weight:normal; margin-left:10px;">(<i class="fa-solid fa-lock"></i> '.lang('@Please select or upload a voucher first to unlock account selection.').')</span>';
            echo '</label>';
            echo '<div id="QuickCategoryPanel" style="display:flex; flex-wrap:wrap; gap:8px; opacity:0.3; pointer-events:none; transition:opacity 0.3s;">';
            $a_res = DB::query($conn, "SELECT acc_id, acc_name FROM accounts WHERE acc_type = 'expense' ORDER BY acc_id");
            while ($account = DB::fetch_assoc($a_res)) {
                $acc_id_val = $account['acc_id']; $full_name = $acc_id_val." - ".$account['acc_name'];
                echo '<label class="radio-card-btn '.($acc == $acc_id_val ? 'active-radio-btn' : '').'" title="'.htmlspecialchars($full_name).'"><input type="radio" name="account_id" value="'.$acc_id_val.'" '.($acc == $acc_id_val ? 'checked' : '').' onchange="highlightSelectedRadio(this)" required><span>'.$full_name.'</span></label>';
            }
            echo '</div></div>';

            if (!empty($current_attachment)) {
                echo '<div style="margin-bottom:15px; padding:10px; background:#f8f9fa; border-left:4px solid #e67e22; display:flex; justify-content:space-between; align-items:center; border-radius:4px;">
                    <div>'.htm_GetDocIcon($current_attachment, 'expense').' <a href="uploads/'.$current_attachment.'" target="_blank" style="font-weight:bold; text-decoration:none;">'.lang('@View current attachment').'</a><br>
                    <span style="font-size:11px; color:#7f8c8d;">'.lang('@Filename').': '.$current_attachment.'</span></div>
                    <label style="color:#c0392b; font-size:13px; cursor:pointer;"><input type="checkbox" name="delete_attachment" value="1" style="margin-right:5px; vertical-align:middle;"> '.lang('@Remove attachment').'</label></div>';
            }

            echo '<input type="hidden" id="depot_file_path" name="depot_file_path" value="'.(isset($_POST['depot_file_path']) ? htmlspecialchars($_POST['depot_file_path']) : '').'">';
            echo '<input type="hidden" name="currency" value="DKK">';
            if (!$currency_module && $orig_currency && $orig_currency !== 'DKK') {
                // Modul deaktiveret: bevar eksisterende valuta-værdier, så de ikke tabes ved gem
                echo '<input type="hidden" name="orig_currency" value="'.htmlspecialchars($orig_currency).'">';
                echo '<input type="hidden" name="orig_amount" value="'.($orig_amount ? number_format($orig_amount, 2, ',', '') : '').'">';
                echo '<input type="hidden" name="exch_rate" value="'.($exch_rate ? number_format($exch_rate, 4, ',', '') : '').'">';
            }

            $save_label = ($exp_type === 'income') ? lang('@Save Income') : lang('@Save Expense');
            echo '<div style="display:flex; gap:10px; margin-top:20px; border-top:1px solid #eee; padding-top:20px;">
                <button type="submit" name="action_save" value="1" style="flex:2; background:#2ecc71; color:white; border:none; padding:10px; border-radius:4px; font-weight:bold; cursor:pointer;"><i class="fa fa-save"></i> '.$save_label.'</button>';
            htm_Button('fa-times', lang('@Cancel'), 'secondary', 'expense_list.php', 'flex:1;');
            echo '</div>';
        htm_Card_end();
    echo '</div>';
echo '</div>';
htm_Footer();
echo '</form>';
?>

<script>
<?php if ($currency_module): ?>
// ── Valuta-widget ─────────────────────────────────────────────────────────────
var _fcRates = {};
var _fcBase  = 'EUR';

function toggleForeignCurrency(on) {
    document.getElementById('fc-fields').style.display = on ? 'grid' : 'none';
    document.getElementById('fc-info').style.display   = on ? 'block' : 'none';
    if (on && Object.keys(_fcRates).length === 0) fetchRate();
}

function fetchRate() {
    var currency = document.getElementById('fc-currency').value;
    if (!currency || currency === 'DKK') return;
    fetch('currency_proxy.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            _fcRates = data.rates;
            _fcBase  = data.base;
            document.getElementById('fc-rate-date').textContent = '(' + data.date + ')';
            setRate(currency);
        })
        .catch(function() {
            document.getElementById('fc-rate').placeholder = '<?php echo lang('@Could not load rates'); ?>';
        });
}

function setRate(currency) {
    var rate = 0;
    if (currency === _fcBase) {
        // EUR til DKK
        rate = _fcRates['DKK'] || 0;
    } else if (currency === 'DKK') {
        rate = 1;
    } else {
        // Kryds: currency → EUR → DKK
        var toEur = _fcRates[currency] ? (1 / _fcRates[currency]) : 0;
        rate = toEur * (_fcRates['DKK'] || 1);
    }
    if (rate > 0) {
        document.getElementById('fc-rate').value = rate.toFixed(4).replace('.', ',');
        calcDkk();
    }
}

function calcDkk() {
    var origStr = document.getElementById('fc-orig-amount').value.replace(',', '.');
    var rateStr = document.getElementById('fc-rate').value.replace(',', '.');
    var orig    = parseFloat(origStr);
    var rate    = parseFloat(rateStr);
    if (!isNaN(orig) && !isNaN(rate) && rate > 0) {
        var dkk = orig * rate;
        var amountField = document.querySelector('input[name="amount"]');
        if (amountField) amountField.value = dkk.toFixed(2).replace('.', ',');
    }
}

// Når valuta skiftes: hent ny kurs
document.getElementById('fc-currency').addEventListener('change', function() {
    if (Object.keys(_fcRates).length > 0) {
        setRate(this.value);
    } else {
        fetchRate();
    }
});

// Initialisér hvis fremmed valuta allerede er sat (redigering)
<?php if ($orig_currency && $orig_currency !== 'DKK'): ?>
document.addEventListener('DOMContentLoaded', function() {
    toggleForeignCurrency(true);
});
<?php endif; ?>
<?php endif; /* currency_module */ ?>

// ── Depot & zoom (uændret fra v1.2.0) ────────────────────────────────────────
let MidlertidigFilFraPC = null;
let currentZoom = 1, isDragging = false, startX, startY, translateX = 0, translateY = 0;

const dropzone   = document.getElementById('MainDropzone');
const panel      = document.getElementById('QuickCategoryPanel');
const placeholder= document.getElementById('previewPlaceholder');
const pdfPreview = document.getElementById('pdfPreview');
const imgPreview = document.getElementById('imgPreview');
const badge      = document.getElementById('selectedBadge');
const scanBtn    = document.getElementById('AiScanButton');
const zoomWrapper= document.getElementById('zoomWrapper');

function skiftDepotTab(t) {
    document.querySelectorAll('.tab-content-panel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.tab-lnk').forEach(b => { b.style.background = '#cbd5e0'; b.style.color = '#000'; });
    document.getElementById('panel_' + t).style.display = 'block';
    const a = document.getElementById('tab_btn_' + t); a.style.background = '#fafafa'; a.style.color = '#2980b9';
}
function triggerAiToggle(c) { document.getElementById('hidden_ai_checkbox').checked = c; document.getElementById('hidden_ai_action').value = "1"; document.getElementById('global_exp_form').submit(); }
function tjekVisScanKnap() { scanBtn.style.display = (<?php echo $use_ai_scan; ?> === 1 && (MidlertidigFilFraPC || document.getElementById('depot_file_path').value !== '')) ? 'block' : 'none'; }
function nulstilVisning() { placeholder.style.display='none'; pdfPreview.style.display='none'; imgPreview.style.display='none'; zoomWrapper.style.display='block'; currentZoom=1; translateX=0; translateY=0; if(document.getElementById('AccountPanelHint')) document.getElementById('AccountPanelHint').style.display='inline'; const dzRect=dropzone.getBoundingClientRect(); zoomWrapper.style.height=(dzRect.width*1.414)+"px"; zoomWrapper.style.width=(pdfPreview.style.display==='block')?(dzRect.width*1.04)+"px":dzRect.width+"px"; opdaterTransform(); }
function previewDepotFile(p) { nulstilVisning(); const e=p.split('.').pop().toLowerCase(); if(e==='pdf'){pdfPreview.src=p+"#toolbar=0&navpanes=0";pdfPreview.style.display='block';}else if(['jpg','jpeg','png','webp'].includes(e)){imgPreview.src=p;imgPreview.style.display='block';} panel.style.opacity='1'; panel.style.pointerEvents='auto'; if(document.getElementById('AccountPanelHint')) document.getElementById('AccountPanelHint').style.display='none'; }
function selectDepotFile(e,p,f) { e.stopPropagation(); MidlertidigFilFraPC=null; document.getElementById('depot_file_path').value=p; document.getElementsByName('attachment')[0].value=""; badge.innerText='✓ '+"<?php echo lang('@Depot'); ?>"+': '+f; badge.style.background='#2ecc71'; badge.style.display='block'; previewDepotFile(p); tjekVisScanKnap(); }
function opdaterTransform() { const dzRect=dropzone.getBoundingClientRect(); const rh=parseFloat(zoomWrapper.style.height)||dzRect.height; const tw=dzRect.width*currentZoom; const th=rh*currentZoom; translateX=(tw<=dzRect.width)?0:Math.min(0,Math.max(dzRect.width-tw,translateX)); translateY=(th<=dzRect.height)?0:Math.min(0,Math.max(dzRect.height-th,translateY)); zoomWrapper.style.transform=`translate(${translateX}px,${translateY}px) scale(${currentZoom})`; }
dropzone.addEventListener('mousedown',(e)=>{ const dzRect=dropzone.getBoundingClientRect(); const rh=parseFloat(zoomWrapper.style.height)||dzRect.height; if(currentZoom===1&&(rh<=dzRect.height))return; isDragging=true; zoomWrapper.style.cursor='grabbing'; startX=e.clientX-translateX; startY=e.clientY-translateY; });
window.addEventListener('mousemove',(e)=>{ if(!isDragging)return; translateX=e.clientX-startX; translateY=e.clientY-startY; opdaterTransform(); });
window.addEventListener('mouseup',()=>{ isDragging=false; zoomWrapper.style.cursor='grab'; });
dropzone.addEventListener('wheel',(e)=>{ e.preventDefault(); const zi=0.15; const delta=e.deltaY<0?1:-1; const nz=Math.min(Math.max(1,currentZoom+delta*zi),5); if(nz===currentZoom)return; const dzRect=dropzone.getBoundingClientRect(); const mx=e.clientX-dzRect.left; const my=e.clientY-dzRect.top; const dx=(mx-translateX)/currentZoom; const dy=(my-translateY)/currentZoom; currentZoom=nz; translateX=mx-dx*currentZoom; translateY=my-dy*currentZoom; opdaterTransform(); });
dropzone.addEventListener('dblclick',()=>{ nulstilVisning(); });
document.addEventListener("DOMContentLoaded",()=>{ skiftDepotTab('mail'); const f='<?php echo $current_attachment; ?>'; if(f!==''){previewDepotFile('uploads/'+f);badge.innerText='✓ '+"<?php echo lang('@Voucher ready'); ?>"+': '+f;badge.style.background='#2ecc71';badge.style.display='block';} if(document.getElementById('depot_file_path').value!==''||f!==''||MidlertidigFilFraPC!==null){panel.style.opacity='1';panel.style.pointerEvents='auto';if(document.getElementById('AccountPanelHint'))document.getElementById('AccountPanelHint').style.display='none';scanBtn.style.display='block';}else{panel.style.opacity='0.3';panel.style.pointerEvents='none';} });
function highlightSelectedRadio(r) { document.querySelectorAll('.radio-card-btn').forEach(l=>l.classList.remove('active-radio-btn')); if(r.checked){r.closest('.radio-card-btn').classList.add('active-radio-btn');if(MidlertidigFilFraPC||document.getElementById('depot_file_path').value!==''||'<?php echo $current_attachment; ?>'!==''){badge.innerText='📂 '+"<?php echo lang('@Voucher to account'); ?>"+'#'+r.value;badge.style.background='#2ecc71';}}}
function handleLocalFileSelect(i) { if(i.files&&i.files[0]){MidlertidigFilFraPC=i.files[0];document.getElementById('depot_file_path').value='';if(MidlertidigFilFraPC.type==="application/pdf"){pdfPreview.style.display='block';}else{imgPreview.style.display='block';} nulstilVisning(); const u=URL.createObjectURL(MidlertidigFilFraPC); if(MidlertidigFilFraPC.type==="application/pdf"){pdfPreview.src=u;}else if(MidlertidigFilFraPC.type.startsWith("image/")){imgPreview.src=u;} badge.innerText='📂 '+"<?php echo lang('@Local file'); ?>"+': '+MidlertidigFilFraPC.name;badge.style.background='#34495e';badge.style.display='block';panel.style.opacity='1';panel.style.pointerEvents='auto';if(document.getElementById('AccountPanelHint'))document.getElementById('AccountPanelHint').style.display='none';tjekVisScanKnap();}}
dropzone.addEventListener('dragover',(e)=>{e.preventDefault();dropzone.style.background='#e1e8ed';dropzone.style.borderColor='#2980b9';});
dropzone.addEventListener('dragleave',()=>{dropzone.style.background='#f1f2f6';dropzone.style.borderColor='#34495e';});
dropzone.addEventListener('drop',(e)=>{e.preventDefault();dropzone.style.background='#f1f2f6';dropzone.style.borderColor='#34495e';if(e.dataTransfer.files.length>0){MidlertidigFilFraPC=e.dataTransfer.files[0];const inp=document.getElementsByName('attachment')[0],dt=new DataTransfer();dt.items.add(MidlertidigFilFraPC);inp.files=dt.files;handleLocalFileSelect(inp);}});
</script>

<style>
.depot-item:hover { background-color: #ebedf0 !important; }
.tab-lnk.active { background: #fafafa !important; color: #2980b9 !important; border-bottom: 2px solid #2980b9 !important; }
.zoom-help-tooltip:hover .zoom-help-text { visibility: visible !important; opacity: 1 !important; }
.zoom-help-text::after { content: ""; position: absolute; bottom: 100%; right: 5px; margin-left: -5px; border-width: 5px; border-style: solid; border-color: transparent transparent #2c3e50 transparent; }
</style>
