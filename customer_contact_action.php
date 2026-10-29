<?php # /customer_contact_action.php v:1.3.1 d:2026-09-15 i:evs
# NY FUNKTION (bruger-anmodet, "kan bogholderen ønske sig mere", runde 33):
# gemmer/sletter en enkelt kontaktperson på customer_contacts - kaldet fra
# customer_edit.php's "Kontaktpersoner"-sektion. Rører aldrig selve customers.
require_once 'inc/auth.inc.php';
require_once 'inc/db_connect.inc.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header("Location: sales_hub.php"); exit;
}

$action  = $_POST['action'] ?? '';
$cust_id = (int)($_POST['cust_id'] ?? 0);

if ($cust_id <= 0) {
    header("Location: sales_hub.php"); exit;
}

if ($action === 'add') {
    $name  = trim($_POST['contact_name'] ?? '');
    $email = trim($_POST['contact_email'] ?? '');
    $phone = trim($_POST['contact_phone'] ?? '');
    $note  = trim($_POST['contact_note'] ?? '');
    if ($name !== '' || $email !== '') {
        DB::insert($conn, 'customer_contacts', [
            'cust_id'       => $cust_id,
            'contact_name'  => $name !== '' ? $name : null,
            'contact_email' => $email !== '' ? $email : null,
            'contact_phone' => $phone !== '' ? $phone : null,
            'contact_note'  => $note !== '' ? $note : null,
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }
} elseif ($action === 'delete') {
    $contact_id = (int)($_POST['contact_id'] ?? 0);
    if ($contact_id > 0) {
        DB::query($conn, "DELETE FROM customer_contacts WHERE contact_id = $contact_id AND cust_id = $cust_id");
    }
}

header("Location: customer_edit.php?id=$cust_id#contacts");
