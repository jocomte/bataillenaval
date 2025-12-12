<?php
// action.php - Gère les actions de jeu (tir, prêt, etc.) via AJAX/JSON.

session_start();
require_once 'db_config.php';
require_once 'fonctions_bataille.php';
// DEFINIT LE TYPE DE CONTENU COMME JSON POUR TOUTES LES REPONSES
header('Content-Type: application/json');

// --- CONFIGURATION / IDENTIFICATION DU JOUEUR ---
$PARTIE_ID = 1; 
$role = $_SESSION["role"] ?? null; 
$JOUEUR_ID_SESSION = ($role === "joueur1") ? 1 : 2; 
// --- FONCTIONS UTILITAIRES DE JEU ---

/** Lit et retourne l'état JSON actuel du jeu. */
function get_etat_jeu() {
    $content = @file_get_contents("etat_joueurs.json");
    return json_decode($content, true) ?: [
        "j1" => ["pret" => false], 
        "j2" => ["pret" => false],
        "tour" => 1 
    ];
}

/** Met à jour l'état JSON du jeu. */
function set_etat_jeu($etat) {
    $result = file_put_contents("etat_joueurs.json", json_encode($etat, JSON_PRETTY_PRINT));
    if ($result === false) {
        throw new Exception("Impossible d'écrire dans etat_joueurs.json. Vérifiez les permissions.");
    }
}

// Vérification préliminaire du POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
    $_SESSION['error'] = 'Action non spécifiée.';
    header("Location: plateau.php");
    exit;
}

$action = $_POST['action'];

// --- LOGIQUE DE TIR (action=tirer) ---
if ($action === 'tirer') {
    
    // Le tir sera géré via JSON pour un meilleur flux AJAX.
    
    $x = (int)($_POST['x'] ?? -1);
    $y = (int)($_POST['y'] ?? -1);
    $JOUEUR_TIREUR_ID = (int)($_POST['joueur_tireur_id'] ?? 0);
    $JOUEUR_CIBLE_ID = ($JOUEUR_TIREUR_ID === 1) ? 2 : 1; 

    // 1. VÉRIFICATIONS PRÉLIMINAIRES
    if ($JOUEUR_TIREUR_ID !== $JOUEUR_ID_SESSION) {
        $_SESSION['error'] = "Erreur de sécurité: Identifiant de joueur invalide.";
        header("Location: plateau.php");
        exit;
    }
    if ($x < 0 || $y < 0) {
        $_SESSION['error'] = "Coordonnées de tir invalides.";
        header("Location: plateau.php");
        exit;
    }
    
    // 2. VÉRIFICATION DU TOUR DE JEU
    $etat_jeu = get_etat_jeu();
    $tour_actuel = $etat_jeu['tour'] ?? 1;

    if ($tour_actuel !== $JOUEUR_TIREUR_ID) {
        $_SESSION['error'] = "Ce n'est pas votre tour de jouer !";
        header("Location: plateau.php");
        exit;
    }
    
    // 3. LOGIQUE DE TRANSACTION
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        // 3.1 VÉRIFICATION : Coup déjà tiré ?
        $stmt_check_coup = $pdo->prepare("SELECT resultat FROM coups WHERE id_plateau_cible = ? AND coordonnee_x = ? AND coordonnee_y = ?");
        $stmt_check_coup->execute([$JOUEUR_TIREUR_ID, $x, $y]);
        
        if ($stmt_check_coup->fetchColumn()) {
            $pdo->rollBack();
            $_SESSION['error'] = "Case déjà ciblée. Choisissez un nouvel emplacement.";
            header("Location: plateau.php");
            exit;
        }

        // 3.2 DÉTERMINATION DU RÉSULTAT DU TIR
        $stmt_bateau_cible = $pdo->prepare("SELECT case_depart, taille, orientation, nom_bateau FROM bateaux WHERE joueur_id = ?");
        $stmt_bateau_cible->execute([$JOUEUR_CIBLE_ID]);
        $bateaux_adversaires = $stmt_bateau_cible->fetchAll(PDO::FETCH_ASSOC);
        
        $bateau_touche_info = null;
        foreach ($bateaux_adversaires as $bateau) {
            $depart_indices = coord_to_indices($bateau['case_depart']);
            if ($depart_indices) {
                $cases_occupees = calculer_cases_bateau($depart_indices, $bateau['taille'], $bateau['orientation']);
                if (in_array([$y, $x], $cases_occupees)) { 
                    $bateau_touche_info = $bateau;
                    break;
                }
            }
        }
        
        if ($bateau_touche_info) {
            $depart_indices = coord_to_indices($bateau_touche_info['case_depart']);
            $cases_bateau = calculer_cases_bateau($depart_indices, $bateau_touche_info['taille'], $bateau_touche_info['orientation']);
            $hits = 0;
            foreach ($cases_bateau as $case) {
                $cy = $case[0]; $cx = $case[1];
                if ($cy == $y && $cx == $x) {
                    $hits++; // current hit
                } else {
                    $stmt_hit = $pdo->prepare("SELECT COUNT(*) FROM coups WHERE id_plateau_cible = ? AND coordonnee_x = ? AND coordonnee_y = ? AND (resultat = 'touche' OR resultat = 'coule')");
                    $stmt_hit->execute([$JOUEUR_TIREUR_ID, $cx, $cy]);
                    if ($stmt_hit->fetchColumn() > 0) $hits++;
                }
            }
            $resultat = ($hits == $bateau_touche_info['taille']) ? 'coule' : 'touche';
        } else {
            $resultat = 'plouf';
        }
        
        // 3.3 ENREGISTREMENT DU COUP DANS LA BDD
        $stmt_insert_coup = $pdo->prepare("INSERT INTO coups (id_plateau_cible, coordonnee_x, coordonnee_y, resultat) VALUES (?, ?, ?, ?)");
        $stmt_insert_coup->execute([$JOUEUR_TIREUR_ID, $JOUEUR_CIBLE_ID, $x, $y, $resultat]);
        
        // 3.4 GESTION DU TOUR (Mise à jour du JSON)
        $message_tir = "";
        $rejouer = ($resultat === 'touche' || $resultat === 'coule');

        if (!$rejouer) {
            // Passer le tour à l'adversaire
            $etat_jeu['tour'] = $JOUEUR_CIBLE_ID; 
            set_etat_jeu($etat_jeu);
            $message_tir = "Plouf ! Tour de l'adversaire.";
        } else {
            $message_tir = ($resultat === 'coule') ? "Coulé ! Vous rejouez." : "Touché ! Vous rejouez.";
        }

        $pdo->commit();
        
        // 4. RETOURNER JSON au lieu de la redirection
        $_SESSION['message'] = $message_tir;
        header("Location: plateau.php");
        exit;

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $_SESSION['error'] = "Erreur BDD lors du tir : " . $e->getMessage();
        header("Location: plateau.php");
        exit;
    }
} 

// --- LOGIQUE DE PRÊT (action=set_pret) ---
else if ($action === 'set_pret') {
    // Le code ici est déjà correct pour les retours JSON
    
    $joueur_id_json = ($JOUEUR_ID_SESSION === 1) ? "j1" : "j2";
    $pret_state = ($_POST['pret'] ?? 'false') === 'true';

    try {
        $etat_jeu = get_etat_jeu();

        // 1. VÉRIFICATION DE LA LIMITE DES BATEAUX
        $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM bateaux WHERE joueur_id = ?");
        $stmt_count->execute([$JOUEUR_ID_SESSION]);
        $bateaux_places = $stmt_count->fetchColumn();
        $NOMBRE_MAX_BATEAUX = 5; // Assurez-vous que cette valeur est correcte 
        if ($pret_state && $bateaux_places < $NOMBRE_MAX_BATEAUX) { 
            $message = "Vous devez placer tous vos {$NOMBRE_MAX_BATEAUX} bateaux avant de vous déclarer prêt ! ({$bateaux_places} placés)";
            $_SESSION['error'] = $message;
            header("Location: plateau.php");
            exit;
        }

        // 2. MISE À JOUR DU STATUT JSON
        $etat_jeu[$joueur_id_json]["pret"] = $pret_state;
        
        // Pour test seul : simuler que l'adversaire est prêt
        $adversaire_id_json = ($joueur_id_json === "j1") ? "j2" : "j1";
        $etat_jeu[$adversaire_id_json]["pret"] = true;
        
        // 3. INITIALISATION DU TOUR DE JEU (Si les deux sont prêts)
        $j1_pret = $etat_jeu["j1"]["pret"] ?? false;
        $j2_pret = $etat_jeu["j2"]["pret"] ?? false;

        if ($j1_pret && $j2_pret) {
             $etat_jeu["tour"] = 1; 
        } else {
             unset($etat_jeu["tour"]);
        }

        set_etat_jeu($etat_jeu);
        
        // Retourner JSON si succès
        $message = $pret_state ? "Prêt enregistré." : "Placement réactivé.";
        $_SESSION['message'] = $message;
        header("Location: plateau.php");
        exit;

    } catch (Exception $e) {
        $_SESSION['error'] = "Erreur lors de la mise à jour du statut 'Prêt' : " . $e->getMessage();
        header("Location: plateau.php");
        exit;
    }
}
// ... (Autres actions futures ici) ...

else {
    echo json_encode(['success' => false, 'message' => "Action non reconnue."]); 
    exit;
}