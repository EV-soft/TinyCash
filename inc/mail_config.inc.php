<?php # inc/mail_config.inc.php
// SMTP Indstillinger
define('MAIL_HOST', 'mail.ev-soft.dk');     // SMTP afsender
define('MAIL_USER', 'faktura@ev-soft.dk');  // Mail Konto
define('MAIL_PASS', 'faktura007');          // Passwork
define('MAIL_PORT', 587);                   // Typisk 587 for TLS eller 465 for SSL
define('MAIL_FROM', 'faktura@ev-soft.dk');  
define('MAIL_FROM_NAME', 'TinyCash - Faktura');
define('MAIL_REPLY_TO', 'faktura@ev-soft.dk');
