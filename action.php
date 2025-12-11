<?php
header('Content-Type: application/json');

// Lecture du corps de la requête JSON
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!isset($data['action']) || !isset($data['joueur'])) {
    echo json_encode(['success' => false, 'message' => 'Action ou joueur manquant.']);
    exit;
}

$action = $data['action'];
$joueur_id = $data['joueur']; // j1 ou j2
$plateaux_file = "plateaux.json";

// Fonction pour lire et décoder le JSON
function lirePlateaux($file) {
    if (!file_exists($file) || filesize($file) == 0) {
        // Initialisation si le fichier est vide
        return [
            "j1" => ["grille" => array_fill(0, 10, array_fill(0, 10, 0)), "pret" => false],
            "j2" => ["grille" => array_fill(0, 10, array_fill(0, 10, 0)), "pret" => false]
        ];
    }
    return json_decode(file_get_contents($file), true);
}

// Fonction pour encoder et écrire le JSON
function ecrirePlateaux($file, $data) {
    // Utilisation de JSON_PRETTY_PRINT pour un fichier plus lisible
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

$plateaux = lirePlateaux($plateaux_file);

switch ($action) {
    case 'placer':
        $x = $data['x'];
        $y = $data['y'];

        // Vérification que le joueur n'est pas déjà prêt
        if ($plateaux[$joueur_id]['pret']) {
             echo json_encode(['success' => false, 'message' => 'Placement non autorisé : vous êtes déjà prêt.']);
            break;
        }

        // Simple Toggle : si la case est vide (0), on met 1 (segment bateau). Si elle est 1, on met 0 (vide).
        $nouvel_etat = $plateaux[$joueur_id]['grille'][$y][$x] == 1 ? 0 : 1;
        $plateaux[$joueur_id]['grille'][$y][$x] = $nouvel_etat;

        if (ecrirePlateaux($plateaux_file, $plateaux)) {
            echo json_encode(['success' => true, 'etat' => $nouvel_etat]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'écriture du fichier.']);
        }
        break;

    case 'set_pret':
        // filter_var permet de transformer 'true' en true booléen, etc.
        $pret_etat = filter_var($data['pret'], FILTER_VALIDATE_BOOLEAN);

        $plateaux[$joueur_id]['pret'] = $pret_etat;

        if (ecrirePlateaux($plateaux_file, $plateaux)) {
            echo json_encode(['success' => true, 'pret' => $pret_etat]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erreur lors de la mise à jour de l\'état prêt.']);
        }
        break;

    // TODO: Ajouter le cas 'tirer' et 'check_opponent_ready' pour les étapes suivantes
    // ...

    default:
        echo json_encode(['success' => false, 'message' => 'Action non reconnue.']);
        break;
}
?>