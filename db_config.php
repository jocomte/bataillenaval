<?php
// =================================================================
// 1. CONNEXION A LA BASE DE DONNÉES (À adapter)
// =================================================================

$host = 'localhost'; 
$db   = 'bataillenavale_db'; 
$user = 'jo';
$pass = '3003';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     die("Erreur de connexion à la base de données: " . $e->getMessage());
}

// =================================================================
// 2. FONCTIONS UTILES (CRUD)
// =================================================================

// ---------------------- Lecture ----------------------
/**
 * Charge un plateau spécifique (grilles de tirs et de navires).
 */
function charger_plateau_sql(PDO $pdo, int $plateau_id): ?array {
    $stmt = $pdo->prepare("SELECT grille_tirs, grille_navires FROM plateaux WHERE id = ?");
    $stmt->execute([$plateau_id]);
    $resultat = $stmt->fetch();

    if ($resultat) {
        return [
            'grille_tirs' => json_decode($resultat['grille_tirs'], true),
            'grille_navires' => json_decode($resultat['grille_navires'], true)
        ];
    }
    return null;
}

// ---------------------- Écriture ----------------------
/**
 * Sauvegarde les nouvelles grilles dans la base de données.
 */
function sauvegarder_plateau_sql(PDO $pdo, int $plateau_id, array $grille_tirs, array $grille_navires): bool {
    $json_tirs = json_encode($grille_tirs);
    $json_navires = json_encode($grille_navires);

    $stmt = $pdo->prepare("
        UPDATE plateaux 
        SET grille_tirs = ?, grille_navires = ? 
        WHERE id = ?
    ");
    return $stmt->execute([$json_tirs, $json_navires, $plateau_id]);
}

// ---------------------- Logique de Jeu ----------------------
/**
 * Met à jour les dégâts d'un navire et vérifie s'il est coulé.
 * Retourne le statut du coup (touché ou coulé) et le nom du bateau.
 */
function mettre_a_jour_navire(PDO $pdo, int $bateau_id_db): array {
    $stmt = $pdo->prepare("
        UPDATE navires 
        SET degats_recus = degats_recus + 1 
        WHERE id = ?
    ");
    $stmt->execute([$bateau_id_db]);

    $stmt = $pdo->prepare("SELECT taille, degats_recus, type_navire FROM navires WHERE id = ?");
    $stmt->execute([$bateau_id_db]);
    $navire = $stmt->fetch();
    
    // Le statut du coup est basé sur les règles du jeu.
    $statut_coup = 'touché'; 
    
    if ($navire && $navire['degats_recus'] >= $navire['taille']) {
        // Bateau coulé, mettre à jour le statut dans la DB
        $pdo->prepare("UPDATE navires SET coule = TRUE WHERE id = ?")->execute([$bateau_id_db]);
        $statut_coup = 'coulé';
    }
    
    return ['statut' => $statut_coup, 'nom' => $navire['type_navire']];
}

/**
 * Vérifie si tous les navires d'un plateau sont coulés (Victoire).
 */
function verifier_victoire(PDO $pdo, int $plateau_id): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(id) 
        FROM navires 
        WHERE plateau_id = ? AND coule = FALSE
    ");
    $stmt->execute([$plateau_id]);
    $nb_navires_restants = $stmt->fetchColumn();
    
    return ($nb_navires_restants == 0);
}
?>