<?php
session_start();
if (isset($_GET['l'])) {
$lang = $_GET['l'];
if ($lang === 'da' || $lang === 'en') {
$_SESSION['lang'] = $lang;
}
}
$referer = $_SERVER['HTTP_REFERER'] ?? 'index.page.php';
header("Location: " . $referer);
exit;
?>