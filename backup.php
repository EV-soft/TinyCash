<?php # /backup.php v:1.3.0 d:2026-08-30 i:evs
# (Lang vejledning flyttet til hjælpesystemet, vist inline)
# v1.3.0: link til backup_decrypt_offline.html (offline dekrypteringsværktøj)
# v1.3.1: tip-boksen fik mere bundmargin (løste IKKE problemet alene)
# v1.3.2: tilføjet position:relative + z-index over floating-action-bar's 10000
# v1.3.3: margin-bottom sat til 70px (bruger-bekræftet virkende værdi)
require 'inc/auth.inc.php';
require 'inc/db_connect.inc.php';
require 'inc/menu.inc.php';
require_once 'inc/php2htm.lib.php';
require_once 'inc/help.lib.php';
// RETTET (bruger-rapporteret: "backup" var ikke et klart begreb - manuel og
// automatisk backup lå spredt på to helt forskellige sider uden nogen
// tydelig adskillelse. Automatisk-backup-boksen boede FØR på
// company_settings.php - en side om firmanavn/moms/valuta, ikke om backup -
// og "Backup Management" her viste kun de manuelle handlinger uden nogen
// status/fejlvisning for det automatiske system overhovedet). Flyttet hertil
// og siden opdelt i to tydelige sektioner, se nedenfor.
require_once 'inc/auto_backup_integration.php';

if (!isset($_SESSION['user_level']) || (int)$_SESSION['user_level'] < 3) {
    deny_access_gracefully();
}

htm_Header('@Backup Management');
showMenu();

// Find nyeste lokal ZIP-fil til integritets-tjek (uændret logik)
$backup_dir   = __DIR__ . '/backups/';
$latest_file  = null;
$latest_mtime = 0;
if (is_dir($backup_dir)) {
    foreach (glob($backup_dir . '*.zip') as $file) {
        if (filemtime($file) > $latest_mtime) {
            $latest_mtime = filemtime($file);
            $latest_file  = $file;
        }
    }
}

// Genbrugte inline-stilarter (holdt konsistente med resten af filen)
$card  = "background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px;";
$cardF = $card . " display: flex; flex-direction: column; justify-content: space-between;";
$h3    = "margin-top:0; color:#1e293b; font-size:1.1em; font-weight:bold;";
$p     = "color:#64748b; font-size:0.9em; line-height:1.4; margin: 10px 0 20px 0;";
$btn   = "display:block; width:100%; box-sizing:border-box; color:white; padding:12px; text-decoration:none; border-radius:4px; font-weight:bold; text-align:center;";
$sechd = "color:#1e293b; font-size:1.25em; font-weight:bold; margin: 30px 0 4px 0;";
$secsub= "color:#64748b; font-size:0.9em; line-height:1.4; margin: 0 0 15px 0;";

echo "<div style='max-width: 1000px; margin: 0 auto; font-family: sans-serif; box-sizing: border-box;'>";

    // ══ TOPNIVEAU-ADSKILLELSE (bruger-rapporteret) ═══════════════════════════
    // "Backup" dækkede reelt to helt uafhængige systemer: noget DU aktivt
    // klikker og genererer/downloader her og nu, og noget der kører usynligt
    // i baggrunden hver 7. dag og sender en krypteret mail. Gjort eksplicit
    // med to tydelige topoverskrifter, i stedet for at lade forskellen være
    // implicit i hvilke knapper der tilfældigvis stod på siden.
    echo "<div style='background:#eef2ff; border:1px solid #c7d2fe; border-radius:8px; padding:16px 20px; margin-bottom:10px; color:#3730a3; font-size:0.9em; line-height:1.5;'>";
    echo "<strong>" . lang('@Manual vs. Automatic') . ":</strong> " . lang('@This page covers two independent systems: backups YOU generate and download right now (below), and an automatic encrypted backup that runs by itself in the background and emails you a copy on a schedule (further down).');
    echo "</div>";

    // RETTET (bruger-præciseret: "jeg mente htm_Card() med fold"): erstatter
    // den håndrullede tonede div med et rigtigt htm_Card_()-kort - bruger den
    // nye $bclr-parameter (tonet baggrund) sammen med den allerede byggede
    // $fold-parameter (fold/luk-ikon), i stedet for en special-bygget wrapper.
    // Rav/gul tone - "noget du selv skal gøre". De hvide indre kort
    // ($cardF/$card) svæver oven på kortets tonede baggrund.
    htm_Card_(capt: '🔧 ' . lang('@Manual Backup'), wdth: 1000, info: lang('@Actions you trigger yourself, right now.'), fold: true, bclr: '#fffbeb');

    // ══ SEKTION 1: REGNSKABS-BACKUP ═════════════════════════════════════════
    echo "<h2 style='$sechd'>🧾 " . lang('@Accounting Backup') . "</h2>";
    echo "<p style='$secsub'>" . lang('@For the bookkeeper.') . "</p>";

    echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 10px;'>";

        // Fuld data-arkiv (primær regnskabs-backup)
        echo "<div style='$cardF'>";
        echo "<div><h3 style='$h3'>" . lang('@Full Data Archive') . "</h3>";
        echo "<p style='$p'>" . lang('@Compile the database, settings and all uploaded receipts into one ZIP snapshot of your accounting data.') . "</p></div>";
        echo "<a href='full_project_backup.php' style='$btn background:#8e44ad;'>🗄️ " . lang('@Generate & Download ZIP') . "</a>";
        echo "</div>";

        // Rå SQL-eksport
        echo "<div style='$cardF'>";
        echo "<div><h3 style='$h3'>" . lang('@Database Export') . "</h3>";
        echo "<p style='$p'>" . lang('@Download a raw .sql dump of all tables (structure and data).') . "</p></div>";
        echo "<a href='export_sql.php' style='$btn background:#3498db;'>📥 " . lang('@Export SQL Database') . "</a>";
        echo "</div>";

        // Gendan
        echo "<div style='$cardF'>";
        echo "<div><h3 style='$h3'>" . lang('@Restore System') . "</h3>";
        echo "<p style='$p'>" . lang('@Restore your system to a previous state using a backup file.') . "</p></div>";
        echo "<a href='backup_restore.php' style='$btn background:#e67e22;'>🔄 " . lang('@Open Restore Utility') . "</a>";
        echo "</div>";

    echo "</div>";

    // ══ SEKTION 2: PROGRAM-BACKUP ═══════════════════════════════════════════
    echo "<h2 style='$sechd'>📦 " . lang('@Program Backup') . "</h2>";
    echo "<p style='$secsub'>" . lang('@For the system administrator.') . "</p>";

    echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 10px;'>";

        // System- & konfigurations-backup
        // RETTET (§bugs-batch-14-review): beskrivelsen sagde "database structure
        // and configuration" - men backup_system.php bruger DB::dump_to_sql(),
        // som eksporterer ALLE tabellers FULDE indhold (inkl. users.password_hash
        // og alle kunde-/faktura-/regnskabsdata), ikke kun strukturen. Filens
        // egen kodekommentar sagde det korrekt hele tiden ("Indeholder alle
        // data: Kontoplan, brugere, transaktioner og FIRMADATA") - kun denne
        // bruger-vendte tekst var misvisende, og kunne føre til at man
        // behandlede filen som mindre følsom end den reelt er.
        echo "<div style='$cardF'>";
        echo "<div><h3 style='$h3'>" . lang('@System Configuration') . "</h3>";
        echo "<p style='$p'>" . lang('@Full database dump (all data, including customers, invoices and user accounts) plus language files - despite the name, this is not limited to structure/configuration and is just as sensitive as the Full Data Archive.') . "</p></div>";
        echo "<a href='backup_system.php' style='$btn background:#27ae60;'>📦 " . lang('@Download Config Backup') . "</a>";
        echo "</div>";

        // Program-backup (kildekode + DB-struktur)
        echo "<div style='$cardF'>";
        echo "<div><h3 style='$h3'>" . lang('@Program Source Backup') . "</h3>";
        echo "<p style='$p'>" . lang('@Archive the program source code and the database structure before and after an update. Excludes secrets and accounting data.') . "</p></div>";
        echo "<a href='program_backup.php' style='$btn background:#0ea5e9;'>💾 " . lang('@Generate Program Backup') . "</a>";
        echo "</div>";

    echo "</div>";

    // ══ SEKTION 3: INTEGRITET & NYESTE BACKUP ═══════════════════════════════
    echo "<h2 style='$sechd'>🔎 " . lang('@Latest Backup Integrity') . "</h2>";
    echo "<p style='$secsub'>" . lang('@Check the newest archive before you rely on it.') . "</p>";

    echo "<div style='$card margin-bottom: 10px;'>";
    echo "<p style='margin: 0; font-size:0.9em;'>";
    if ($latest_file) {
        if (class_exists('ZipArchive')) { // Sikring mod fatal fejl hvis zip-extension mangler
            $zip = new ZipArchive();
            if ($zip->open($latest_file, ZipArchive::CHECKCONS) === TRUE) {
                $file_md5 = md5_file($latest_file);
                $filename = htmlspecialchars(basename($latest_file), ENT_QUOTES, 'UTF-8');

                echo "<span style='color: #16a34a; font-weight: bold;'>✔️ " . lang('@Verified Valid') . "</span><br>";
                echo "<span style='font-size:0.85em; color:#64748b; display:block; margin: 5px 0;'>" . lang('@File:') . " " . $filename . "</span>";
                echo "<span data-hint='" . lang('@This token verifies the archive on the server is healthy. Compare it to your cloud upload to guarantee 0% data loss.') . "' style='color: #475569; display:block; margin-top:8px; font-size:0.85em; font-family:monospace; background:#f1f5f9; padding:6px; border-radius:3px; cursor:help; border:1px solid #e2e8f0;'>Local MD5: " . $file_md5 . "</span>";

                $zip->close();
            } else {
                echo "<span style='color: #dc2626; font-weight: bold;'>❌ " . lang('@Archive Corrupted') . "</span><br>";
                echo "<span style='font-size:0.85em; color:#dc2626;'>" . lang('@The zip file on the server is broken.') . "</span>";
            }
        } else {
            echo "<span style='color: #e67e22; font-weight: bold;'>⚠️ " . lang('@Cannot Verify') . "</span><br>";
            echo "<span style='font-size:0.85em; color:#475569;'>" . lang('@PHP ZipArchive extension is not enabled on this server.') . "</span>";
        }
    } else {
        echo "<span style='color: #64748b; font-style: italic;'>" . lang('@No archive found. Run a full backup above.') . "</span>";
    }
    echo "</p>";
    echo "</div>";

    // ══ SEKTION: LOVKRAV - FLYT BACKUPS OFF-SITE (bruger-rapporteret) ═══════
    // Flyttet hertil fra company_settings.php's "Sikkerhed - Om Backup"-kort -
    // samme oprydning som automatisk-backup-boksen nedenfor. RETTET undervejs:
    // den gamle tekst sagde "systemet gemmer AUTOMATISK backup-filer i
    // backups-mappen" - men det er reelt kun de MANUELLE handlinger ovenfor
    // (Full Data Archive/System Configuration/Program Source) der skriver
    // til backups/; den ægte automatiske baggrundsproces sender kun en mail
    // og gemmer intet lokalt (se inc/auto_backup.inc.php). At bruge ordet
    // "automatisk" her, lige ved siden af en side der nu også har en helt
    // bogstavelig "Automatisk Backup"-sektion, ville genindføre præcis den
    // tvetydighed, denne omlægning skal fjerne - omformuleret.
    echo "<div style='$card margin-bottom: 10px;'>";
    $backup_notice = '<strong style="color: var(--color-warning); font-size: 14px;"><i class="fa-solid fa-cloud-arrow-down"></i> ' . lang('@Important regarding backup (Bookkeeping Act):') . '</strong>'
        . '<p style="margin: 5px 0 0 0; font-size: 13px; color: var(--text-main); line-height: 1.5;">' . lang('@Manually generated backup files (above) are saved in the backups folder on the server. To comply with legal data protection requirements, you must regularly download these .zip files and store them on an external data medium (e.g., a local hard drive, USB drive, or secure cloud storage).') . '</p>'
        . '<div style="margin-top: 10px;"><a href="storage_browser.php?folder=backups" style="font-size: 13px; color: var(--color-primary); text-decoration: none; font-weight: bold;"><i class="fa-solid fa-folder-open"></i> ' . lang('@Go to System File Browser to Download Backups') . '</a></div>';
    htm_Banner($backup_notice, 'warning');
    echo "</div>";

    // ══ VEJLEDNING (fra hjælpesystemet, vist inline og oversat) ═════════════
    // RETTET (bruger-rapporteret): indholdet handler UDELUKKENDE om manuel
    // backup (download+upload til ekstern sky, MD5-verifikation af den
    // manuelt genererede ZIP fra §Integritet-sektionen ovenfor) - stod FØR
    // som en delt/generel sektion i bunden af siden, hvilket var misvisende.
    // Flyttet ind under selve Manuel Backup-kortet i stedet. Den ene
    // automatisk-relaterede sætning (master-kodeord) er samtidig fjernet fra
    // selve hjælpeteksten - den var reelt redundant, render_auto_backup_
    // settings() har allerede sin egen udførlige master-kodeord-status.
    // Al lang prosa bor i json-data/help_system.json (+ _da), så den
    // oversættes ordentligt og undgår extract_translations' 60-tegns-grænse.
    htm_Card_(capt: '📖 ' . lang('@Backup Guidance'), wdth: 1000, fold: 'closed');
    $user_lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'da';
    $json_help_content = _help_get_content('backup.php', $user_lang);
    if ($json_help_content) {
        echo "<div style='font-size: 0.9em; color: #2c3e50; line-height:1.6;'>";
        echo $json_help_content;
        echo "</div>";
    } else {
        echo "<p style='color:#7f8c8d; font-style:italic; margin:0;'>" . lang('@Documentation text could not be loaded from help system resource.') . "</p>";
    }
    htm_Card_end();

    htm_Card_end(); // Slutter Manuel Backup-kortet

    // ══ TOPNIVEAU: AUTOMATISK BACKUP (bruger-rapporteret adskillelse) ═══════
    // Flyttet hertil fra company_settings.php (§backup-system-model) - lå FØR
    // på en side om firmanavn/moms/valuta, adskilt fra alt andet der hedder
    // "backup", uden nogen krydshenvisning. render_auto_backup_settings()
    // viser status/fejl/opsætning for det system der reelt kører automatisk,
    // en helt anden ting end de manuelle handlinger ovenfor.
    // Samme princip som Manuel Backup-kortet ovenfor, men i en kølig blå tone
    // - "kører af sig selv, ingen handling krævet".
    htm_Card_(capt: '🤖 ' . lang('@Automatic Backup'), wdth: 1000, info: lang('@Runs by itself in the background - no action needed unless something below needs attention.'), fold: true, bclr: '#f0f9ff');

    render_auto_backup_settings($conn);

    // ══ SEKTION 4: ÅBN EN KRYPTERET BACKUP (offline-værktøj) ════════════════
    // Ren statisk HTML/JS (Web Crypto), ingen PHP/DB/auth. Linkes herfra som
    // en genvej, men SKAL også kunne findes/køres uden serveren - se filens
    // egen header-kommentar og backup-system-model. Hører hjemme under
    // Automatisk Backup, ikke Manuel - det er netop .zip.enc-filer FRA den
    // automatiske backup-mail, den dekrypterer.
    echo "<h2 style='$sechd'>🔓 " . lang('@Open Encrypted Backup') . "</h2>";
    echo "<p style='$secsub'>" . lang('@Decrypt a .zip.enc file from an automatic off-site backup email.') . "</p>";

    echo "<div style='$cardF margin-bottom: 10px;'>";
    echo "<div><h3 style='$h3'>" . lang('@Offline Decryption Tool') . "</h3>";
    echo "<p style='$p'>" . lang('@Runs entirely in your browser, no server or internet connection required. Also save this file locally so it works during a real disaster.') . "</p></div>";
    echo "<a href='backup_decrypt_offline.html' style='$btn background:#0d9488;'>🔓 " . lang('@Open Decryption Tool') . "</a>";
    echo "</div>";

    htm_Card_end(); // Slutter Automatisk Backup-kortet

    // TIP i bunden. margin-bottom: 70px løfter boksen fri af den faste
    // floating-action-bar nederst på siden (bruger-bekræftet værdi).
    // position:relative + z-index beholdt som ekstra sikkerhed, så boksen
    // også tegnes foran bjælken, hvis den skulle overlappe alligevel.
    echo '<div style="position: relative; z-index: 10001; margin-top: 30px; padding: 15px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 8px; color: #856404; font-size: 0.9em; margin-bottom: 70px;">';
    echo '<strong>💡 ' . lang('@Tip:') . '</strong> ' . lang('@Regular backups protect your data against server failures. Always store your backup files in a safe place outside the server.');
    echo '</div>';

echo '</div>'; // Container lukkes korrekt HER, før eksterne komponenter loades

htm_HelpSystem();
htm_FloatingActionBar();
htm_Footer();
?>
