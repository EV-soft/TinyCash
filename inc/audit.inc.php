<?php # /inc/audit.inc.php v:1.3.0 d:2026-08-30 i:evs
/* ==========================================================================
   REVISIONSLOG-HJÆLPEFUNKTION

   Denne fil manglede helt i det oprindelige projekt (blev fundet som
   afhængighed af expense_actions.php: require_once 'inc/audit.inc.php').
   log_action() skriver til den eksisterende audit_log-tabel (allerede
   oprettet af setup/create_all_tables.php), og bruger DB::-abstraktionen,
   så den virker på både SQLite og MySQL.
   ========================================================================== */

if (!function_exists('log_action')) {
    /**
     * Logger en handling til audit_log-tabellen.
     *
     * @param mixed  $conn        Databaseforbindelsen ($conn fra db_connect.inc.php)
     * @param string $action_type Handlingstype, fx 'CANCEL_EXPENSE', 'DELETE_DRAFT'
     * @param string $table_name  Navnet på den berørte tabel, fx 'expenses'
     * @param int    $row_id      Den berørte rækkes primærnøgle
     * @param mixed  $old_values  Gamle værdier (array eller null) - gemmes som JSON
     * @param mixed  $new_values  Nye værdier (array eller null) - gemmes som JSON
     */
    function log_action($conn, $action_type, $table_name, $row_id, $old_values = null, $new_values = null) {
        $user_id = (int)($_SESSION['user_id'] ?? 0);

        $old_json = ($old_values !== null) ? json_encode($old_values, JSON_UNESCAPED_UNICODE) : null;
        $new_json = ($new_values !== null) ? json_encode($new_values, JSON_UNESCAPED_UNICODE) : null;

        DB::insert($conn, 'audit_log', [
            'user_id'     => $user_id,
            'action_type' => $action_type,
            'table_name'  => $table_name,
            'row_id'      => (int)$row_id,
            'old_values'  => $old_json,
            'new_values'  => $new_json,
        ]);
        // Bevidst ingen fejlhåndtering her, der stopper hovedhandlingen -
        // en fejlet logskrivning bør ikke forhindre selve annulleringen/
        // opdateringen i at gennemføres. DB::insert() logger evt. fejl
        // internt via PHP's error_log, hvis noget går galt.
    }
}
?>
