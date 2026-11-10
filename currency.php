<?php
session_start();

$target = isset($_GET['to']) && in_array($_GET['to'], ['IDR','USD']) ? $_GET['to'] : 'IDR';
$_SESSION['currency'] = $target;

// Redirect back to the referring page or index
$back = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'index.php';
header('Location: '.$back);
exit;
