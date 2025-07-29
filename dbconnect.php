<?php
$server = "localhost";
$user = "root";
$pass = ""; 
$db = "vault_casino";

$link = new mysqli($server, $user, $pass, $db);

if ($link->connect_error) {
    die("Connection failed: " . $link->connect_error);
}
?>
