<?php

$host = '127.0.0.1';
$dbname = 'bataillenavale_db';
$user = 'jo';
$pass = '3003';

try {
  $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
  echo "connexion ok";
} catch (Exception $e) {
  die('Erreur : ' . $e->getMessage());
}