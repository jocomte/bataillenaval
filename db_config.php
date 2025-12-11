<?php
$host = "localhost";
$dbname = "bataillenavale_db";
$user = "jo";
$pass = "3003"; // ton mot de passe

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Créer la base si elle n'existe pas
    $pdo->exec("CREATE DATABASE IF NOT EXISTS $dbname CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    // Sélectionner la base
    $pdo->exec("USE $dbname");

    // Créer la table plateau si elle n'existe pas
    $sql = "CREATE TABLE IF NOT EXISTS plateaux (
        id INT AUTO_INCREMENT PRIMARY KEY,
        joueur VARCHAR(10) NOT NULL,
        x INT NOT NULL,
        y INT NOT NULL,
        etat ENUM('eau','bateau','touche','coule') DEFAULT 'eau'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $pdo->exec($sql);

} catch (PDOException $e) {
    die("Erreur connexion : " . $e->getMessage());
}
