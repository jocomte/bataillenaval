<?php
// action.php - Gère les actions de jeu (tir, prêt, etc.) via POST simple.

session_start();
require_once 'db_config.php';
require_once 'fonctions_bataille.php';

// --- CONFIGURATION / IDENTIFICATION DU JOUEUR ---
$PARTIE_ID = 1; 
$role = $_SESSION["role"] ?? null; 
$JOUEUR_ID_SESSION = ($role === "joueur1") ? 1 : 2; 

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
    header('Location: index.php?erreur=' . urlencode('Action non spécifiée.'));
    exit;
}

$action = $_POST['action'];

// --- FONCTIONS UTILITAIRES DE JEU ---

/** Lit et retourne l'état JSON actuel du jeu. */
function get_etat_jeu() {
    $content = file_get_contents("etat_joueurs.json");
    return json_decode($content, true) ?: [
        "j1" => ["pret" => false], 
        "j2" => ["pret" => false],
        "tour" => 1 // ID BDD du joueur dont c'est le tour
    ];
}

/** Met à jour l'état JSON du jeu. */
function set_etat_jeu($etat) {
    file_put_contents("etat_joueurs.json", json_encode($etat, JSON_PRETTY_PRINT));
}

// --- LOGIQUE DE TIR (action=tirer) ---
if ($action === 'tirer') {
    
    $x = (int)($_POST['x'] ?? -1);
    $y = (int)($_POST['y'] ?? -1);
    $JOUEUR_TIREUR_ID = (int)($_POST['joueur_tireur_id'] ?? 0);
    $JOUEUR_CIBLE_ID = ($JOUEUR_TIREUR_ID === 1) ? 2 : 1; 

    // 1. VÉRIFICATIONS PRÉLIMINAIRES
    if ($JOUEUR_TIREUR_ID !== $JOUEUR_ID_SESSION) {
         $message = "Erreur de sécurité: Identifiant de joueur invalide.";
         header('Location: index.php?erreur=' . urlencode($message));
         exit;
    }
    if ($x < 0 || $y < 0) {
         $message = "Coordonnées de tir invalides.";
         header('Location: index.php?erreur=' . urlencode($message));
         exit;
    }
    
    // 2. VÉRIFICATION DU TOUR DE JEU
    $etat_jeu = get_etat_jeu();
    $tour_actuel = $etat_jeu['tour'] ?? 1;

    if ($tour_actuel !== $JOUEUR_TIREUR_ID) {
        $message = "Ce n'est pas votre tour de jouer !";
        header('Location: index.php?erreur=' . urlencode($message));
        exit;
    }
    
    // 3. LOGIQUE DE TRANSACTION
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        // 3.1 VÉRIFICATION : Le coup a-t-il déjà été tiré ?
        $stmt_check_coup = $pdo->prepare("SELECT resultat FROM coups WHERE partie_id = ? AND joueur_tireur_id = ? AND coordonnee_x = ? AND coordonnee_y = ?");
        $stmt_check_coup->execute([$PARTIE_ID, $JOUEUR_TIREUR_ID, $x, $y]);
        
        if ($stmt_check_coup->fetchColumn()) {
            $pdo->rollBack();
            $message = "Case déjà ciblée. Choisissez un nouvel emplacement.";
            header('Location: index.php?erreur=' . urlencode($message));
            exit;
        }

        // 3.2 DÉTERMINATION DU RÉSULTAT DU TIR
        $resultat = 'plouf'; 
        $bateau_touche_info = null;

        $stmt_bateau_cible = $pdo->prepare("SELECT case_depart, taille, orientation, nom_bateau FROM bateaux WHERE partie_id = ? AND joueur_id = ?");
        $stmt_bateau_cible->execute([$PARTIE_ID, $JOUEUR_CIBLE_ID]);
        $bateaux_adversaires = $stmt_bateau_cible->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($bateaux_adversaires as $bateau) {
            $depart_indices = coord_to_indices($bateau['case_depart']);
            if ($depart_indices) {
                $cases_occupees = calculer_cases_bateau($depart_indices, $bateau['taille'], $bateau['orientation']);
                
                if (in_array([$y, $x], $cases_occupees)) { 
                    $resultat = 'touche';
                    $bateau_touche_info = $bateau;
                    // On ne break pas car on veut vérifier si plusieurs bateaux sont coulés
                }
            }
        }
        
        // 3.3 ENREGISTREMENT DU COUP DANS LA BDD
        $stmt_insert_coup = $pdo->prepare("INSERT INTO coups (partie_id, joueur_tireur_id, joueur_cible_id, coordonnee_x, coordonnee_y, resultat) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt_insert_coup->execute([$PARTIE_ID, $JOUEUR_TIREUR_ID, $JOUEUR_CIBLE_ID, $x, $y, $resultat]);
        
        // 3.4 VÉRIFICATION DU COULÉ (uniquement si touché)
        if ($resultat === 'touche') {
             // Ceci est une logique placeholder. La vérification complète est complexe.
             // Pour l'instant, on maintient "touche" pour simplifier la démo.
             // Le résultat final doit être mis à jour dans la table 'coups' si l'état est "coulé".
        }
        
        // 3.5 GESTION DU TOUR (Mise à jour du JSON)
        $message_tir = "";
        $rejouer = ($resultat === 'touche' || $resultat === 'coule');

        if (!$rejouer) {
            // Passer le tour à l'adversaire
            $etat_jeu['tour'] = $JOUEUR_CIBLE_ID; 
            set_etat_jeu($etat_jeu);
            $message_tir = "Plouf ! Tour de l'adversaire.";
        } else {
            // Le joueur rejoue
            $message_tir = ($resultat === 'coule') ? "Coulé ! Vous rejouez." : "Touché ! Vous rejouez.";
        }

        $pdo->commit();
        
        // 4. REDIRECTION (pour afficher le plateau mis à jour)
        header('Location: index.php?message=' . urlencode($message_tir));
        exit;

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $message = "Erreur BDD lors du tir : " . $e->getMessage();
        header('Location: index.php?erreur=' . urlencode($message));
        exit;
    }
} 

// --- LOGIQUE DE PRÊT (action=set_pret) ---
else if ($action === 'set_pret') {
    
    $joueur_id_json = ($JOUEUR_ID_SESSION === 1) ? "j1" : "j2";
    $pret_state = ($_POST['pret'] ?? 'false') === 'true';

    try {
        $etat_jeu = get_etat_jeu();

        // Ajout d'une vérification : tous les bateaux sont-ils placés ?
        $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM bateaux WHERE partie_id = ? AND joueur_id = ?");
        $stmt_count->execute([$PARTIE_ID, $JOUEUR_ID_SESSION]);
        $bateaux_places = $stmt_count->fetchColumn();
        
        // Cette vérification doit être basée sur $NOMBRE_MAX_BATEAUX, mais on le simule ici:
        if ($pret_state && $bateaux_places < 4) { // Assumons 4 bateaux pour l'exemple
            $message = "Vous devez placer tous vos bateaux avant de vous déclarer prêt ! ({$bateaux_places} placés)";
            header('Location: index.php?erreur=' . urlencode($message));
            exit;
        }

        $etat_jeu[$joueur_id_json]["pret"] = $pret_state;
        
        // Initialiser le tour au Joueur 1 (ID 1) si la partie n'a pas commencé
        if ($etat_jeu["j1"]["pret"] && $etat_jeu["j2"]["pret"] && !isset($etat_jeu["tour"])) {
             $etat_jeu["tour"] = 1; 
        }

        set_etat_jeu($etat_jeu);
        
        $message = $pret_state ? "Prêt enregistré." : "Placement réactivé.";
        header('Location: index.php?message=' . urlencode($message));
        exit;

    } catch (Exception $e) {
        $message = "Erreur lors de la mise à jour du statut 'Prêt' : " . $e->getMessage();
        header('Location: index.php?erreur=' . urlencode($message));
        exit;
    }
}
// ... (Autres actions futures ici) ...

else {
    $message = "Action non reconnue.";
    header('Location: index.php?erreur=' . urlencode($message));
    exit;
}
?>