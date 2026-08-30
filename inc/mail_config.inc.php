<?php # /inc/mail_config.inc.php v:1.3.0 d:2026-08-30 i:evs
// SMTP-afsender. Nøglerne ligger i inc/data/env.ini under sektionen
// [mail_config] (single source of connection settings, jf. Kodestandard).
// Værdierne herunder er kun fallback, hvis env.ini eller sektionen mangler -
// så al udgående post (fakturaer via mail_handler.lib.php OG den automatiske
// backup) trækker fra samme ét sted i stedet for hardkodede hemmeligheder i
// denne fil. Gammel sti (inc/env.ini) bevaret som bagudkompatibel fallback.
$__mc_ini = @parse_ini_file(__DIR__ . '/data/env.ini', true) ?: @parse_ini_file(__DIR__ . '/env.ini', true);
$__mc = (is_array($__mc_ini) && isset($__mc_ini['mail_config'])) ? $__mc_ini['mail_config'] : [];

define('MAIL_HOST',      $__mc['MAIL_HOST']      ?? 'mail.ev-soft.dk');
define('MAIL_USER',      $__mc['MAIL_USER']      ?? 'faktura@ev-soft.dk');
define('MAIL_PASS',      $__mc['MAIL_PASS']      ?? '');
define('MAIL_PORT',      (int)($__mc['MAIL_PORT'] ?? 587));
define('MAIL_FROM',      $__mc['MAIL_FROM']      ?? (($__mc['MAIL_USER'] ?? '') ?: 'faktura@ev-soft.dk'));
define('MAIL_FROM_NAME', $__mc['MAIL_FROM_NAME'] ?? 'TinyCash - Faktura');
define('MAIL_REPLY_TO',  $__mc['MAIL_REPLY_TO']  ?? (($__mc['MAIL_USER'] ?? '') ?: 'faktura@ev-soft.dk'));

unset($__mc_ini, $__mc);
