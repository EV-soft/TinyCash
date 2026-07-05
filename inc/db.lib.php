<?php # inc/db.lib.php v:1.1.0 d:2026-07-02 i:evs

function getDb() {
    // Vi antager, at din config ligger her eller hentes fra en fil
    $config = [
        'type' => 'sqlite', // eller 'mysql'
        'sqlite_file' => __DIR__ . '/../data/tinycash.sqlite',
        'mysql' => ['host' => 'localhost', 'db' => 'tinycash', 'user' => 'root', 'pass' => '']
    ];

    if ($config['type'] === 'sqlite') {
        // Tjek om mappen eksisterer
        if (!file_exists(dirname($config['sqlite_file']))) {
            mkdir(dirname($config['sqlite_file']), 0777, true);
        }
        $pdo = new PDO('sqlite:' . $config['sqlite_file']);
    } else {
        $dsn = "mysql:host={$config['mysql']['host']};dbname={$config['mysql']['db']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['mysql']['user'], $config['mysql']['pass']);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}


/* i stedet for at lave din new PDO(...) direkte i hver fil:
require_once 'inc/db.lib.php';
$db = getDb();

$stmt = $db->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([$id]);
$invoice = $stmt->fetch();
 */