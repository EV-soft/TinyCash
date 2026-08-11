Rename: db_connect.doc.php to db_connect.inc.php and fillout data:
<?php # db_connect.inc.php - Example 

$db_host = "localhost";
$db_user = "xxxxxx_root";
$db_pass = "zzzzzzzzzzz";
$db_name = "yyyyyyyyyyy_TinyCash";

$conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
if (!$conn) {
    die("<div style='font-family:sans-serif; padding:50px; text-align:center;'>
            <h1 style='color:#e74c3c;'>Database Error</h1>
            <p style='color:#7f8c8d; font-size:0.9em;'>(Could not connect to the database. Please check your settings.)</p>
            <hr style='max-width:300px; border:0; border-top:1px solid #eee;'>
            <code>" . mysqli_connect_error() . "</code>
         </div>");
}
mysqli_set_charset($conn, "utf8mb4");

?>
