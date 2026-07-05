<?php # /mail_inbox.php v:1.1.0 d:2026-07-02 i:evs
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/menu.inc.php';

// Fejlsikker indlæsning af INI
$ini_path = __DIR__ . '/inc/env.ini';
if (!file_exists($ini_path)) {
    die(lang('@Critical error: Configuration file inc/env.ini was not found.'));
}

$config = [];
$lines = file($ini_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos(trim($line), '#') === 0 || strpos(trim($line), ';') === 0) continue;
    if (strpos($line, '=') !== false) {
        list($key, $value) = explode('=', $line, 2);
        $config[trim($key)] = trim($value, " \t\n\r\0\x0B\"");
    }
}

// Internt valg af boks (default er voucher / bilag)
$box_type = $_GET['box'] ?? 'voucher';

if ($box_type === 'invoice') {
    $imap_server = $config['IMAP_INVOICE_SERVER'] ?? '';
    $imap_user   = $config['IMAP_INVOICE_USER'] ?? '';
    $imap_pass   = $config['IMAP_INVOICE_PASS'] ?? '';
    $page_title  = lang('@Invoice Copies (Sales)');
} elseif ($box_type === 'vendor') {
    $imap_server = $config['IMAP_VENDOR_SERVER'] ?? '';
    $imap_user   = $config['IMAP_VENDOR_USER'] ?? '';
    $imap_pass   = $config['IMAP_VENDOR_PASS'] ?? '';
    $page_title  = lang('@Supplier Invoices');
} else {
    $imap_server = $config['IMAP_VOUCHER_SERVER'] ?? '';
    $imap_user   = $config['IMAP_VOUCHER_USER'] ?? '';
    $imap_pass   = $config['IMAP_VOUCHER_PASS'] ?? '';
    $page_title  = lang('@Vouchers & Receipts');
}

if (empty($imap_server) || empty($imap_user)) {
    die(lang('@Critical error: Invalid or unreadable configuration format in env.ini.'));
}

$mbox = @imap_open($imap_server, $imap_user, $imap_pass);

$view_id = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
$view_part = isset($_GET['view_part']) ? $_GET['view_part'] : '';
$download_part = isset($_GET['download_part']) ? $_GET['download_part'] : '';
$file_name = isset($_GET['file_name']) ? $_GET['file_name'] : '';

// --- STREAMING-HÅNDTERING ---
if ($mbox && $view_id > 0 && (!empty($download_part) || !empty($view_part))) {
    $active_part = !empty($download_part) ? $download_part : $view_part;
    $msg_no = imap_msgno($mbox, $view_id);
    
    if ($msg_no > 0) {
        $file_content = imap_fetchbody($mbox, $msg_no, $active_part);
        $structure = imap_fetchstructure($mbox, $msg_no);
        $part_structure = $structure;
        
        if (strpos($active_part, '.') !== false) {
            $sub_parts = explode('.', $active_part);
            foreach ($sub_parts as $p) {
                if (isset($part_structure->parts[$p - 1])) $part_structure = $part_structure->parts[$p - 1];
            }
        } elseif (isset($structure->parts[$active_part - 1])) {
            $part_structure = $structure->parts[$active_part - 1];
        }

        if ($part_structure->encoding == 3) $file_content = base64_decode($file_content);
        elseif ($part_structure->encoding == 4) $file_content = quoted_printable_decode($file_content);

        $ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $mime = 'application/octet-stream';
        if ($ext === 'pdf') $mime = 'application/pdf';
        elseif ($ext === 'jpg' || $ext === 'jpeg') $mime = 'image/jpeg';
        elseif ($ext === 'png') $mime = 'image/png';
        elseif ($ext === 'gif') $mime = 'image/gif';

        header('Content-Type: ' . $mime);
        if (!empty($download_part)) {
            header('Content-Disposition: attachment; filename="' . basename($file_name) . '"');
        } else {
            header('Content-Disposition: inline; filename="' . basename($file_name) . '"');
        }
        header('Content-Length: ' . strlen($file_content));
        echo $file_content;
        imap_close($mbox);
        exit;
    }
}

$mail_body = "";
$mail_details = null;
$attachments = [];

if ($mbox && $view_id > 0) {
    $msg_no = imap_msgno($mbox, $view_id);
    if ($msg_no > 0) {
        $header = imap_headerinfo($mbox, $msg_no);
        $mail_details = [
            'subject' => isset($header->subject) ? imap_utf8($header->subject) : lang('@(No subject)'),
            'from'    => htmlspecialchars($header->fromaddress),
            'date'    => date('d.m.Y H:i', strtotime($header->date))
        ];
        
        $structure = imap_fetchstructure($mbox, $msg_no);
        $body_raw = imap_fetchbody($mbox, $msg_no, 1);
        if (isset($structure->encoding) && $structure->encoding == 3) $mail_body = base64_decode($body_raw);
        elseif (isset($structure->encoding) && $structure->encoding == 4) $mail_body = quoted_printable_decode($body_raw);
        else $mail_body = $body_raw;
        
        $mail_body = nl2br(htmlspecialchars(strip_tags($mail_body)));

        if (isset($structure->parts) && count($structure->parts) > 1) {
            foreach ($structure->parts as $part_index => $part) {
                $is_attachment = false;
                $filename = '';

                if ($part->ifdparameters) {
                    foreach ($part->dparameters as $object) {
                        if (strtolower($object->attribute) == 'filename') { $is_attachment = true; $filename = imap_utf8($object->value); }
                    }
                }
                if (!$is_attachment && $part->ifparameters) {
                    foreach ($part->parameters as $object) {
                        if (strtolower($object->attribute) == 'name') { $is_attachment = true; $filename = imap_utf8($object->value); }
                    }
                }

                if ($is_attachment) {
                    $attachments[] = [
                        'name' => !empty($filename) ? $filename : lang('@Unknown file'),
                        'part' => ($part_index + 1),
                        'size' => $part->bytes
                    ];
                }
            }
        }
    }
}

htm_Header(lang('@Document Inbox'));
showMenu();

$base_url = 'mail_inbox.php?box=' . $box_type;

echo '<style>
    .page-title-bar { max-width: 1800px; margin: 10px auto 5px auto; display: flex; justify-content: space-between; align-items: center; padding-bottom: 5px; }
    .page-title-bar h1 { margin: 0; font-size: 24px; color: var(--theme-text-main, #2c3e50); font-weight: 600; display: flex; align-items: center; gap: 10px; }
    
    .mailbox-tabs { max-width: 1800px; margin: 0 auto 15px auto; display: flex; gap: 5px; border-bottom: 2px solid var(--theme-primary, #3498db); }
    .mail-tab { padding: 10px 20px; font-size: 14px; font-weight: 600; text-decoration: none; color: var(--theme-text-muted, #7f8c8d); background: var(--theme-bg-panel, #f8f9fa); border: 1px solid var(--theme-border-color, #ced4da); border-bottom: none; border-radius: 6px 6px 0 0; transition: all 0.2s; }
    .mail-tab:hover { background: var(--theme-bg-hover, #e9ecef); color: var(--theme-text-main, #2c3e50); }
    .mail-tab.active { background: var(--theme-primary, #3498db); color: white; border-color: var(--theme-primary, #3498db); }

    .mailbox-container { display: flex; max-width: 1800px; margin: 0 auto 40px auto; background: var(--theme-bg-card, #fff); border-radius: 0 0 8px 8px; box-shadow: 0 4px 15px var(--theme-shadow, rgba(0,0,0,0.08)); min-height: 800px; border: 1px solid var(--theme-border-color, #ced4da); overflow: hidden; }
    .mail-sidebar { width: 25%; border-right: 1px solid var(--theme-border-color, #ced4da); display: flex; flex-direction: column; background: var(--theme-bg-panel, #f8f9fa); }
    .sidebar-header { padding: 15px 20px; border-bottom: 1px solid var(--theme-border-color, #ced4da); background: var(--theme-bg-card, #fff); }
    .sidebar-header h2 { margin: 0; font-size: 15px; color: var(--theme-text-main, #2c3e50); }
    .sidebar-header p { margin: 3px 0 0 0; font-size: 11px; color: var(--theme-text-muted, #7f8c8d); }
    .mail-list { flex: 1; overflow-y: auto; list-style: none; margin: 0; padding: 0; }
    .mail-item { padding: 15px 20px; border-bottom: 1px solid var(--theme-border-subtle, #e9ecef); cursor: pointer; transition: background 0.2s; display: block; text-decoration: none; color: inherit; }
    .mail-item:hover { background: var(--theme-bg-hover, #e9ecef); }
    .mail-item.active { background: var(--theme-bg-hover, #e9ecef); border-left: 4px solid var(--theme-primary, #3498db); padding-left: 16px; }
    .mail-item .mail-meta { display: flex; justify-content: space-between; font-size: 11px; color: var(--theme-text-muted, #7f8c8d); margin-bottom: 4px; }
    .mail-item .mail-from { font-weight: bold; font-size: 13px; color: var(--theme-text-main, #2c3e50); margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mail-item .mail-subject { font-size: 13px; color: var(--theme-text-muted, #7f8c8d); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mail-workspace { width: 75%; display: flex; background: var(--theme-bg-card, #fff); }
    .mail-text-pane { width: 35%; padding: 25px; display: flex; flex-direction: column; border-right: 1px solid var(--theme-border-color, #ced4da); }
    .mail-preview-pane { width: 65%; background: var(--theme-preview-bg, #525659); display: flex; flex-direction: column; position: relative; }
    .mail-view-header { border-bottom: 2px solid var(--theme-border-subtle, #e9ecef); padding-bottom: 15px; margin-bottom: 20px; }
    .mail-view-subject { font-size: 18px; margin: 0 0 10px 0; color: var(--theme-text-main, #2c3e50); line-height: 1.3; }
    .mail-view-meta { font-size: 12px; color: var(--theme-text-muted, #7f8c8d); line-height: 1.5; }
    .mail-view-body { font-size: 13px; line-height: 1.6; color: var(--theme-text-main, #2c3e50); white-space: pre-wrap; flex: 1; overflow-y: auto; margin-bottom: 20px; padding-right: 5px; }
    .attachments-section { border-top: 1px dashed var(--theme-border-color, #ced4da); padding-top: 15px; }
    .attachments-title { font-size: 13px; font-weight: bold; margin-bottom: 10px; color: var(--theme-text-main, #2c3e50); }
    .attachment-grid { display: flex; flex-direction: column; gap: 6px; }
    .attachment-card { background: var(--theme-bg-panel, #f8f9fa); border: 1px solid var(--theme-border-subtle, #e9ecef); padding: 8px 12px; border-radius: 4px; display: flex; align-items: center; justify-content: space-between; text-decoration: none; color: var(--theme-text-main, #2c3e50); font-size: 12px; }
    .attachment-card:hover, .attachment-card.viewing { background: var(--theme-bg-hover, #e9ecef); border-color: var(--theme-primary, #3498db); }
    .attachment-main-click { display: flex; align-items: center; gap: 10px; cursor: pointer; flex: 1; text-decoration: none; color: inherit; }
    .preview-iframe { width: 100%; height: 100%; border: none; background: var(--theme-preview-bg, #525659); }
    .preview-img-container { width: 100%; height: 100%; overflow: auto; display: flex; align-items: center; justify-content: center; padding: 20px; background: var(--theme-preview-dark, #333); }
    .preview-img { max-width: 100%; max-height: 100%; object-fit: contain; box-shadow: 0 4px 10px rgba(0,0,0,0.5); }
    .preview-top-bar { background: var(--theme-preview-bar, #2c3e50); padding: 10px 15px; display: flex; justify-content: space-between; align-items: center; color: white; font-size: 12px; }
    .no-mail-selected, .imap-error { display: flex; align-items: center; justify-content: center; flex: 1; color: var(--theme-text-muted, #7f8c8d); font-style: italic; font-size: 14px; text-align: center; padding: 40px; width: 100%; }
    .sidebar-pagination { padding: 12px 15px; border-top: 1px solid var(--theme-border-color, #ced4da); background: var(--theme-bg-card, #fff); display: flex; justify-content: space-between; align-items: center; gap: 10px; }
    .pagination-btn { padding: 5px 12px; font-size: 12px; font-weight: 600; text-decoration: none; color: var(--theme-text-main, #2c3e50); background: var(--theme-bg-panel, #f8f9fa); border: 1px solid var(--theme-border-subtle, #e9ecef); border-radius: 4px; transition: background 0.2s; }
    .pagination-btn:hover { background: var(--theme-bg-hover, #e9ecef); }
    .pagination-btn.disabled { color: var(--theme-text-muted, #7f8c8d); pointer-events: none; opacity: 0.5; background: transparent; border-color: transparent; }
    .pagination-info { font-size: 11px; color: var(--theme-text-muted, #7f8c8d); }
</style>';

echo '<div class="page-title-bar">';
echo '  <h1><i class="fa-regular fa-envelope" style="color:var(--theme-primary, #3498db)"></i> ' . lang('@Document Inbox') . '</h1>';
htm_Button(icon: 'fa-door-open', labl: lang('@Leave'), type: 'danger', link: 'sales_hub.php');
echo '</div>';

// VIS INTERNE FANEBLADE (Tabs) TIL SKIFT MELLEM INDBAKKER
echo '<div class="mailbox-tabs">';
echo '  <a href="mail_inbox.php?box=voucher" class="mail-tab' . ($box_type === 'voucher' ? ' active' : '') . '">📬 ' . lang('@Vouchers & Receipts') . '</a>';
echo '  <a href="mail_inbox.php?box=vendor" class="mail-tab' . ($box_type === 'vendor' ? ' active' : '') . '">📦 ' . lang('@Supplier Invoices') . '</a>';
echo '  <a href="mail_inbox.php?box=invoice" class="mail-tab' . ($box_type === 'invoice' ? ' active' : '') . '">📄 ' . lang('@Invoice Copies (Sales)') . '</a>';
echo '</div>';

echo '<div class="mailbox-container">';

if (!$mbox) {
    echo '<div class="imap-error"><div><i class="fa-solid fa-triangle-exclamation"></i><br>' . lang('@Could not connect to the mail server:') . '<br><small>' . htmlspecialchars(imap_last_error()) . '</small></div></div>';
} else {
    $total_messages = imap_num_msg($mbox);
    $max_emails = 15; // Mails pr. side
    
    $current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $total_pages = max(1, ceil($total_messages / $max_emails));
    
    $start = $total_messages - (($current_page - 1) * $max_emails);
    $end = max(1, $start - $max_emails + 1);
    
    echo '<div class="mail-sidebar">';
    echo '  <div class="sidebar-header"><h2>' . $page_title . '</h2><p>' . htmlspecialchars($imap_user) . ' (' . $total_messages . ' ' . lang('@mails') . ')</p></div>';

   // echo '<div class="mail-sidebar">';
   // echo '  <div class="sidebar-header"><h2>' . ($box_type === 'invoice' ? lang('@Supplier Invoices') : lang('@Voucher Inbox')) . '</h2><p>' . htmlspecialchars($imap_user) . ' (' . $total_messages . ' ' . lang('@mails') . ')</p></div>';
    echo '  <div class="mail-list">';
    
    if ($total_messages == 0) {
        echo '    <div class="no-mail-selected">' . lang('@The inbox is empty.') . '</div>';
    } else {
        for ($i = $start; $i >= $end; $i--) {
            $header = imap_headerinfo($mbox, $i);
            $uid = imap_uid($mbox, $i);
            $date = date("d.m H:i", strtotime($header->date));
            $subject = isset($header->subject) ? imap_utf8($header->subject) : lang('@(No subject)');
            $from = htmlspecialchars($header->fromaddress);
            $active_class = ($view_id === $uid) ? ' active' : '';
            
            echo '<a href="' . $base_url . '&uid=' . $uid . '&page=' . $current_page . '" class="mail-item' . $active_class . '">';
            echo '  <div class="mail-meta"><span class="mail-date">' . $date . '</span></div>';
            echo '  <div class="mail-from">' . $from . '</div>';
            echo '  <div class="mail-subject">' . $subject . '</div>';
            echo '</a>';
        }
    }
    echo '  </div>';
    
    if ($total_messages > 0) {
        echo '  <div class="sidebar-pagination">';
        if ($current_page > 1) {
            echo '    <a href="' . $base_url . '&page=' . ($current_page - 1) . '" class="pagination-btn"><i class="fa-solid fa-chevron-left"></i> ' . lang('@Newer') . '</a>';
        } else {
            echo '    <span class="pagination-btn disabled"><i class="fa-solid fa-chevron-left"></i> ' . lang('@Newer') . '</span>';
        }
        echo '    <span class="pagination-info">' . lang('@Page') . ' ' . $current_page . ' / ' . $total_pages . '</span>';
        if ($current_page < $total_pages) {
            echo '    <a href="' . $base_url . '&page=' . ($current_page + 1) . '" class="pagination-btn">' . lang('@Older') . ' <i class="fa-solid fa-chevron-right"></i></a>';
        } else {
            echo '    <span class="pagination-btn disabled">' . lang('@Older') . ' <i class="fa-solid fa-chevron-right"></i></span>';
        }
        echo '  </div>';
    }
    echo '</div>';
    
    if ($view_id > 0 && $mail_details) {
echo '<div class="mail-workspace">';
        echo '  <div class="mail-text-pane">';
        echo '    <div class="mail-view-header">';
        echo '      <h1 class="mail-view-subject">' . $mail_details['subject'] . '</h1>';
        echo '      <div class="mail-view-meta">';
        echo '        <strong>' . lang('@From:') . '</strong> ' . $mail_details['from'] . '<br>';
        echo '        <strong>' . lang('@Date:') . '</strong> ' . $mail_details['date'];
        echo '      </div>';
        echo '    </div>';
        
        // 1. VEDHÆFTEDE FILER & REGISTRERINGSKNAP (Flyttet op)
        $selected_part_name = '';
        $selected_part_ext = '';
        if (!empty($attachments)) {
            echo '<div class="attachments-section" style="margin-bottom: 15px;">';
            echo '  <div class="attachments-title"><i class="fa-solid fa-paperclip"></i> ' . lang('@Attachments') . ' (' . count($attachments) . ')</div>';
            echo '  <div class="attachment-grid">';
            
            if (empty($view_part) && !empty($attachments[0]['part'])) {
                $view_part = $attachments[0]['part'];
            }

            foreach ($attachments as $att) {
                $size_kb = round($att['size'] / 1024, 1) . ' KB';
                $ext = strtolower(pathinfo($att['name'], PATHINFO_EXTENSION));
                $is_viewing = ($view_part == $att['part']);
                if ($is_viewing) {
                    $selected_part_name = $att['name'];
                    $selected_part_ext = $ext;
                }

                $icon = 'fa-file';
                if ($ext === 'pdf') $icon = 'fa-file-pdf';
                elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) $icon = 'fa-file-image';

                echo '<div class="attachment-card' . ($is_viewing ? ' viewing' : '') . '">';
                echo '  <a href="' . $base_url . '&uid=' . $view_id . '&view_part=' . $att['part'] . '&file_name=' . urlencode($att['name']) . '" class="attachment-main-click">';
                echo '    <i class="fa-solid ' . $icon . '" style="color:var(--theme-primary, #3498db); font-size:16px;"></i>';
                echo '    <div><strong>' . htmlspecialchars($att['name']) . '</strong> <span style="font-size:10px; color:var(--theme-text-muted, #7f8c8d);">(' . $size_kb . ')</span></div>';
                echo '  </a>';
                
                if ($box_type === 'voucher' || $box_type === 'vendor') {
                    $create_expense_url = 'expense_edit.php?id=0&source_voucher=' . (int)$view_id;
                    echo '  <a href="' . $create_expense_url . '" class="pagination-btn" title="' . lang('@Create Expense') . '" style="margin-left:auto; margin-right:5px; padding:3px 8px; font-size:11px; background:var(--theme-primary, #3498db); color:white; border:none; display:flex; align-items:center; gap:4px;">';
                    echo '    <i class="fa-solid fa-file-invoice-dollar"></i> ' . lang('@Register') . '</a>';
                }

                echo '  <a href="' . $base_url . '&uid=' . $view_id . '&download_part=' . $att['part'] . '&file_name=' . urlencode($att['name']) . '" title="' . lang('@Download') . '" style="color:var(--theme-text-muted, #7f8c8d); margin-left:5px;"><i class="fa-solid fa-download"></i></a>';
                echo '</div>';
            }
            echo '  </div>';
            echo '</div>';
        }

        // 2. SKILLESTREG OVER BESKEDEN
        echo '<hr style="border:0; border-top:1px solid var(--theme-border-subtle, #e9ecef); margin: 15px 0 20px 0;">';
        
        // 3. SELVE EMAIL-BESKEDEN
        echo '    <div class="mail-view-body">' . $mail_body . '</div>';
        echo '  </div>';
        
        echo '  <div class="mail-preview-pane">';
        if (!empty($view_part) && !empty($selected_part_name)) {
            $preview_url = $base_url . '&uid=' . $view_id . '&view_part=' . $view_part . '&file_name=' . urlencode($selected_part_name);
            
            echo '    <div class="preview-top-bar">';
            echo '      <span><i class="fa-regular fa-file"></i> ' . htmlspecialchars($selected_part_name) . '</span>';
            echo '      <a href="' . $preview_url . '" target="_blank" style="color:white; text-decoration:none; background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:3px;"><i class="fa-solid fa-expand"></i> Fuld skærm</a>';
            echo '    </div>';

            if ($selected_part_ext === 'pdf') {
                echo '    <iframe src="' . $preview_url . '" class="preview-iframe"></iframe>';
            } elseif (in_array($selected_part_ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                echo '    <div class="preview-img-container"><img src="' . $preview_url . '" class="preview-img"></div>';
            } else {
                echo '    <div class="no-mail-selected" style="color:white;"><div><i class="fa-solid fa-eye-slash" style="font-size:30px; margin-bottom:10px;"></i><br>' . lang('@Preview not available for this file type.') . '<br><a href="' . $base_url . '&uid=' . $view_id . '&download_part=' . $view_part . '&file_name=' . urlencode($selected_part_name) . '" style="color:#3498db;">' . lang('@Click here to download') . '</a></div></div>';
            }
        } else {
            echo '    <div class="no-mail-selected" style="color:#aaa;"><div><i class="fa-solid fa-receipt" style="font-size:40px; margin-bottom:10px; display:block;"></i>' . lang('@No document attachments to preview.') . '</div></div>';
        }
        echo '  </div>'; 
        echo '</div>'; 
    } else {
        echo '<div class="mail-workspace"><div class="no-mail-selected"><div><i class="fa-regular fa-envelope" style="font-size:40px; margin-bottom:10px; display:block; color: var(--theme-secondary, #e74c3c);"></i>' . lang('@Select an email from the list to read its content.') . '</div></div></div>';
    }
    
    imap_close($mbox);
}

echo '</div>'; 
htm_Footer();
?>