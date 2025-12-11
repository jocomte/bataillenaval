<?php
// Fichier: action.php
include 'db_config.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['action']) || !isset($input['joueur'])) {
    echo json_encode(['success' => false, 'message' => 'Action ou Joueur manquant.']);
    exit;
}

$action = $input['action'];
$joueur_id = $input['joueur'];

// Fonction pour obtenir ou créer le Plateau ID pour le joueur
function getOrCreatePlateauId($pdo, $joueur_id) {
    $stmt = $pdo->prepare("SELECT id_plateau FROM Plateaux WHERE id_joueur = ?");
    $stmt->execute([$joueur_id]);
    $id_plateau = $stmt->fetchColumn();

    if (!$id_plateau) {
        $stmt = $pdo->prepare("INSERT INTO Plateaux (id_joueur) VALUES (?)");
        $stmt->execute([$joueur_id]);
        $id_plateau = $pdo->lastInsertId();
    }
    return $id_plateau;
}

// --- LOGIQUE DE PLACEMENT (MIGRÉE VERS SQL) ---
if ($action === 'placer') {
    $x = $input['x'] ?? null;
    $y = $input['y'] ?? null;
    if ($x === null || $y === null) {
        echo json_encode(['success' => false, 'message' => 'Coordonnées manquantes.']);
        exit;
    }

    $id_plateau = getOrCreatePlateauId($pdo, $joueur_id);

    // 1. Vérifier si le segment existe déjà
    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM Segments WHERE id_plateau = ? AND coordonnee_x = ? AND coordonnee_y = ?");
    $stmt_check->execute([$id_plateau, $x, $y]);
    $exists = $stmt_check->fetchColumn();

    $pdo->beginTransaction();
    try {
        if ($exists) {
            // Le segment existe (retirer le bateau)
            $stmt_delete = $pdo->prepare("DELETE FROM Segments WHERE id_plateau = ? AND coordonnee_x = ? AND coordonnee_y = ?");
            $stmt_delete->execute([$id_plateau, $x, $y]);
            $etat_retour = 0; // 0 = Mer
        } else {
            // Le segment n'existe pas (placer le bateau)
            // Note: Ici, vous pourriez ajouter une logique de limite de segments/bateaux.
            $stmt_insert = $pdo->prepare("INSERT INTO Segments (id_plateau, coordonnee_x, coordonnee_y) VALUES (?, ?, ?)");
            $stmt_insert->execute([$id_plateau, $x, $y]);
            $etat_retour = 1; // 1 = Bateau
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'etat' => $etat_retour]);
    } catch (\PDOException $e) {
        $pdo->rollBack();
        error_log("Erreur SQL lors du placement: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Erreur base de données lors du placement.']);
    }
    exit;
}

// --- LOGIQUE DE "PRÊT" (CONSERVÉE EN JSON) ---
if ($action === 'set_pret') {
    $pret_state = $input['pret'] ?? false;

    // Lecture et mise à jour de plateaux.json (INCHANGÉ)
    $plateaux_content = file_get_contents("plateaux.json");
    $plateaux_data = json_decode($plateaux_content, true);

    $plateaux_data[$joueur_id]["pret"] = $pret_state;

    file_put_contents("plateaux.json", json_encode($plateaux_data));

    echo json_encode(['success' => true, 'pret' => $pret_state]);
    exit;
}

// --- LOGIQUE DE TIR (À IMPLÉMENTER PLUS TARD) ---
// La logique de 'tirer' sera ajoutée ici, utilisant la structure SQL 'Coups'.

echo json_encode(['success' => false, 'message' => 'Action inconnue.']);
?>