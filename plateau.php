<?php
// Fichier: game.php (Corrigé et consolidé)
session_start();
include 'db_config.php'; 
require_once 'fonctions_bataille.php';

// --- Définition de la taille du plateau ---
$TAILLE_GRILLE = 10; 

// --- IDENTIFICATION DU JOUEUR & PARTIE ---
if (!isset($_SESSION["role"])) {
    header("Location: index.php"); // Rediriger si le rôle n'est pas défini (sécurité)
    exit;
}
$role = $_SESSION["role"]; // Ex: joueur1 ou joueur2
$joueur_id_json = ($role === "joueur1") ? "j1" : "j2";
$adversaire_id_json = ($role === "joueur1") ? "j2" : "j1";

$PARTIE_ID = 1; 
$JOUEUR_ID_BDD = ($role === "joueur1") ? 1 : 2; // L'ID numérique pour la table 'bateaux'

// --- Logique de réinitialisation ---
if (isset($_GET["reset"])) {
    session_destroy();
    setcookie(session_name(), "", time() - 3600);
    
    file_put_contents("etat_joueurs.json", json_encode(["j1" => null, "j2" => null]));

    try {
        // Supprimer toutes les données de la partie pour assurer un reset propre
        $pdo->exec("DELETE FROM bateaux WHERE partie_id = " . $PARTIE_ID);
        $pdo->exec("DELETE FROM Segments");
        $pdo->exec("DELETE FROM Coups");

    } catch (\PDOException $e) {
        error_log("Erreur lors de la réinitialisation SQL: " . $e->getMessage());
    }

    header("Location: index.php");
    exit;
}

// Définition de TOUS les bateaux
$bateaux_a_placer = [
    'Porte-avions' => 5,
    'Cuirassé' => 4,
    'Croiseur' => 3, // Pensez à renommer en 'Croiseur 1' si vous en voulez un deuxième
    'Torpilleur' => 2
];

// --- 1. Préparation du Tableau d'Affichage ($plateau_affichage) ---
$plateau_affichage = array_fill(0, $TAILLE_GRILLE, array_fill(0, $TAILLE_GRILLE, 0)); // 0 = Eau
$error_db = null;

try {
    // 1.1 Récupérer les bateaux DÉJÀ placés par le joueur (pour le formulaire)
    $stmt_form = $pdo->prepare("SELECT nom_bateau FROM bateaux WHERE partie_id = ? AND joueur_id = ?");
    $stmt_form->execute([$PARTIE_ID, $JOUEUR_ID_BDD]); // CLÉ : Utiliser l'ID BDD correct
    $noms_bateaux_places = $stmt_form->fetchAll(PDO::FETCH_COLUMN);

    // Filtrer la liste à afficher dans le formulaire
    $bateaux_restants = array_diff_key($bateaux_a_placer, array_flip($noms_bateaux_places));

    // 1.2 Lire les bateaux placés depuis la BDD (pour l'affichage)
    $stmt_display = $pdo->prepare("SELECT case_depart, taille, orientation FROM bateaux WHERE partie_id = ? AND joueur_id = ?");
    $stmt_display->execute([$PARTIE_ID, $JOUEUR_ID_BDD]); // CLÉ : Utiliser l'ID BDD correct
    $bateaux_places = $stmt_display->fetchAll(PDO::FETCH_ASSOC);

    // 1.3 Marquer les cases occupées dans $plateau_affichage (1 = Bateau)
    foreach ($bateaux_places as $bateau) {
        $depart_indices = coord_to_indices($bateau['case_depart']);
        
        if ($depart_indices) {
            $cases_occupees = calculer_cases_bateau($depart_indices, (int)$bateau['taille'], $bateau['orientation']);

            foreach ($cases_occupees as $indices) {
                list($l, $c) = $indices;
                if ($l >= 0 && $l < $TAILLE_GRILLE && $c >= 0 && $c < $TAILLE_GRILLE) {
                    $plateau_affichage[$l][$c] = 1;
                }
            }
        }
    }

    // [FUTURE LOGIQUE : COUPS REÇUS]
    // Ici, vous récupéreriez les coups où id_plateau_cible = JOUEUR_ID_BDD pour marquer 2 ou 3
    
} catch (PDOException $e) {
    $error_db = "Erreur de chargement BDD : " . $e->getMessage();
    $bateaux_restants = $bateaux_a_placer;
}

// L'affichage de mon plateau est maintenant $plateau_affichage
$ma_grille = $plateau_affichage; 

// --- LECTURE DE L'ÉTAT 'PRET' (CONSERVÉE EN JSON) ---
$plateaux_content = file_get_contents("plateaux.json");
$plateaux_data = json_decode($plateaux_content, true) ?: [
    "j1" => ["pret" => false], "j2" => ["pret" => false]
];

$pret = $plateaux_data[$joueur_id_json]["pret"] ?? false;
$adversaire_pret = $plateaux_data[$adversaire_id_json]["pret"] ?? false;


// --- Fonction utilitaire pour dessiner une grille (avec entêtes) ---
function dessiner_grille($grille, $mode, $cible) {
    global $pret, $adversaire_pret, $TAILLE_GRILLE;
    
    // Début du rendu de la grille HTML (Utilise la classe 11x11)
    echo '<div class="grid ' . $mode . ' grid-entetes">';
    
    // 1. CELLULE VIDE (Coin supérieur gauche)
    echo '<div class="cell entete"></div>'; 
    
    // 2. EN-TÊTES DE COLONNES (A, B, C...)
    for ($c = 0; $c < $TAILLE_GRILLE; $c++) {
        $lettre = chr(ord('A') + $c);
        echo '<div class="cell entete">' . $lettre . '</div>';
    }

    // 3. AFFICHAGE DES LIGNES (avec entêtes numériques)
    for ($y = 0; $y < $TAILLE_GRILLE; $y++) {
        // EN-TÊTE DE LIGNE (1, 2, 3...)
        echo '<div class="cell entete">' . ($y + 1) . '</div>'; 
        
        // AFFICHAGE DES CELLULES DE JEU 
        for ($x = 0; $x < $TAILLE_GRILLE; $x++) {
            
            $contenu_cellule = $grille[$y][$x] ?? 0; 
            $classes = "cell";
            $clic_action = '';
            
            // Logique de classe CSS
            if ($mode === 'ma-grille') {
                if ($contenu_cellule == 1) { 
                    $classes .= " bateau"; // Votre bateau placé
                } elseif ($contenu_cellule == 2) { 
                    $classes .= " plouf-recu";
                } elseif ($contenu_cellule == 3) { 
                    $classes .= " touche-recu";
                }
            } elseif ($mode === 'grille-tir') {
                 // Future logique de clic ici pour tirer si c'est le tour
                 if ($pret && $adversaire_pret) {
                    $clic_action = 'onclick="tirer(' . $x . ', ' . $y . ')"';
                 }
                // Logique d'affichage des résultats de tirs (2, 3)
            }

            // Génération de la cellule
            echo '<div class="' . $classes . '" data-x="' . $x . '" data-y="' . $y . '" id="' . $cible . '-' . $x . '-' . $y . '" ' . $clic_action . '>';
            echo '</div>';
        }
    }
    
    echo '</div>';
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Plateau de jeu</title>
    
    <style>
        /* CONSOLIDATION ET CORRECTION CSS */
        body { font-family: Arial, sans-serif; display: flex; flex-direction: column; align-items: center; }
        .plateaux-container { display: flex; gap: 50px; margin-top: 20px; }
        
        /* Définition de la grille 10x10 standard (ancienne référence, peut être ignorée) */
        .grid {
            display: grid;
            grid-template-columns: repeat(10, 40px);
            grid-template-rows: repeat(10, 40px);
            gap: 0;
            border: 2px solid #333;
        }
        
        /* Définition de la grille 11x11 avec entêtes */
        .grid.grid-entetes {
            grid-template-columns: repeat(11, 40px); 
            grid-template-rows: repeat(11, 40px);
            gap: 0;
            border: 2px solid #333;
        }

        .cell {
            width: 40px;
            height: 40px;
            background-color: #a8dadc; /* Couleur de l'eau par défaut */
            border: 1px solid #000; /* BORDURE NOIRE CLAIRE */
            display: flex;
            justify-content: center;
            align-items: center;
            user-select: none;
            font-size: 0.8em;
            cursor: default; 
            line-height: 40px; /* Ajouté pour centrage vertical si contenu */
        }
        
        /* Style des entêtes */
        .cell.entete {
            background-color: #ddd;
            font-weight: bold;
            border: 1px solid #777; 
        }

        /* Style Bateau (Assure la visibilité) */
        .ma-grille .cell.bateau {
            background-color: #3f51b5 !important; /* Couleur forte */
            color: white;
            /* La bordure est déjà gérée par .cell */
        }
        
        /* Styles pour les coups reçus/tirés */
        .ma-grille .cell.plouf-recu, 
        .grille-tir .cell.plouf-tire { background-color: #4dd0e1 !important; }
        .ma-grille .cell.touche-recu, 
        .grille-tir .cell.touche-tire { background-color: #ef5350 !important; }

        /* Masquer la grille en double */
        .grille#plateau-joueur-propre { display: none !important; } 
    </style>
</head>
<body>

<h1>Plateau de bataille navale</h1>
<h2>Vous êtes : <?= $role ?></h2>

<div class="plateaux-container">
    <div class="votre-plateau">
        <h3>🛥️ Ma Grille (Mes Bateaux)</h3>
        <?php dessiner_grille($ma_grille, 'ma-grille', $joueur_id_json); ?>
    </div>
    
    <div class="plateau-adversaire">
        <h3>💥 Grille de Tir (Adversaire : <?= $adversaire_id_json ?>)</h3>
        <?php 
        $grille_tir_vide = array_fill(0, $TAILLE_GRILLE, array_fill(0, $TAILLE_GRILLE, 0));
        dessiner_grille($grille_tir_vide, 'grille-tir', $adversaire_id_json); 
        ?>
    </div>
</div>

<div id="zone-placement">
    <h2>Bateaux à placer :</h2>

    <?php if (isset($_GET['message'])): ?>
        <p style="color: green; font-weight: bold;"><?= htmlspecialchars($_GET['message']) ?></p>
    <?php endif; ?>
    <?php if (isset($_GET['erreur'])): ?>
        <p style="color: red; font-weight: bold;"><?= htmlspecialchars($_GET['erreur']) ?></p>
    <?php endif; ?>
    <?php if (isset($error_db)): ?>
        <p style="color: orange; font-weight: bold;"><?= htmlspecialchars($error_db) ?></p>
    <?php endif; ?>
    
    <?php if (empty($bateaux_restants)): ?>
        <p style="font-weight: bold;">✅ Tous vos bateaux sont placés !</p>
    <?php else: ?>
        <form id="form-placement" action="placement_bateau.php" method="POST">
            <?php foreach ($bateaux_restants as $nom => $taille): ?>
                <label>
                    <input type="radio" name="nom_bateau" value="<?= $nom ?>" required> 
                    <?= $nom ?> (<?= $taille ?> cases)
                </label><br>
            <?php endforeach; ?>
            
            <hr>
            
            <label>Case de départ (Ex: A1) : 
                <input type="text" name="coordonnee_depart" pattern="[A-J]([1-9]|10)" required title="Exemple: A1, J10">
            </label><br><br>
            
            <label>
                Orientation :
                <select name="orientation" required>
                    <option value="H">Horizontale</option>
                    <option value="V">Verticale</option>
                </select>
            </label><br><br>
            
            <button type="submit">Placer le bateau</button>
        </form>
    <?php endif; ?>
</div>

<a href="?reset=1" style="
    display:inline-block;
    margin-top:20px;
    padding:8px 15px;
    background:#c00;
    color:white;
    text-decoration:none;
    border-radius:5px;
    ">
    🔄 Réinitialiser la partie
</a>

<script>
    function tirer(x, y) {
    // Vérification de base (tour de jeu, etc.) à ajouter ici
    if (!estPret || !adversaireEstPret) { 
        alert("La phase de placement n'est pas terminée.");
        return;
    }
    
    // 1. Création dynamique du formulaire de tir
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'action.php';

    // 2. Champs requis pour l'action.php
    
    // Champ 1: Action (indique au serveur de tirer)
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'action';
    actionInput.value = 'tirer'; // <--- NOUVEAU

    // Champ 2: ID du joueur tireur
    const joueurInput = document.createElement('input');
    joueurInput.type = 'hidden';
    joueurInput.name = 'joueur_tireur_id';
    joueurInput.value = joueurBddId; // <--- NOUVEAU

    // Champ 3: Coordonnée X
    const xInput = document.createElement('input');
    xInput.type = 'hidden';
    xInput.name = 'x';
    xInput.value = x;

    // Champ 4: Coordonnée Y
    const yInput = document.createElement('input');
    yInput.type = 'hidden';
    yInput.name = 'y';
    yInput.value = y;
    
    // 3. Ajout des champs au formulaire
    form.appendChild(actionInput);
    form.appendChild(joueurInput);
    form.appendChild(xInput);
    form.appendChild(yInput);
    
    // 4. Soumission
    document.body.appendChild(form);
    form.submit(); 
    
    // Après submit(), la page se recharge avec les messages de action.php
    }
</script>

</body>
</html>