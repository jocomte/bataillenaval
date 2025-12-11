<?php
// Configuration de la connexion
$host = 'localhost';
$dbname   = 'bataillenavale_db'; // Nom de votre base de données
$user = 'jo';
$pass = '3003';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";
$options = [
    // Gestion des erreurs
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    // Format de récupération par défaut (tableau associatif)
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     // Création de l'objet PDO
     $pdo = new PDO($dsn, $user, $pass, $options);
     
} catch (\PDOException $e) {
     // Afficher l'erreur (à remplacer par une gestion d'erreur plus sécurisée en production)
     die("Erreur de connexion à la base de données : " . $e->getMessage());
}

// $pdo est maintenant disponible dans tous les fichiers qui incluent db_config.php
?>