<?php
// Fichier: game.php (Votre fichier fusionné)
session_start();
include 'db_config.php'; // Inclure la connexion SQL
require_once 'fonctions_bataille.php'; // Utilitaires de coordonnées

// --- Définition de la taille du plateau ---
$TAILLE_GRILLE = 10; // Renommée pour l'uniformité.

// --- Logique de réinitialisation (MISE À JOUR SQL) ---
if (isset($_GET["reset"])) {
    // 1. Détruire la session et les cookies (INCHANGÉ)
    session_destroy();
    setcookie(session_name(), "", time() - 3600);

    // 2. Réinitialiser les joueurs (JSON INCHANGÉ)
    file_put_contents("etat_joueurs.json", json_encode([
        "j1" => null,
        "j2" => null
    ]));

    // 3. Réinitialiser les plateaux (SUPPRESSION/MISE À JOUR SQL)
    try {
        // Supprimer toutes les données liées aux BATEAUX (Nouvelle logique)
        $pdo->exec("DELETE FROM bateaux"); 
        
        // Supprimer les données liées aux anciens systèmes (si vous les gardez)
        $pdo->exec("DELETE FROM Segments");
        $pdo->exec("DELETE FROM Coups");
        // $pdo->exec("DELETE FROM Plateaux"); // Si vous gardez la table Plateaux

    } catch (\PDOException $e) {
        error_log("Erreur lors de la réinitialisation SQL: " . $e->getMessage());
    }

    header("Location: index.php");
    exit;
}

if (!isset($_SESSION["role"])) {
    header("Location: index.php");
    exit;
}

// ... (Début du fichier)
$role = $_SESSION["role"]; // Ex: joueur1 ou joueur2
$joueur_id_json = ($role === "joueur1") ? "j1" : "j2"; // ID du joueur (j1 ou j2)
$adversaire_id_json = ($role === "joueur1") ? "j2" : "j1"; // ID de l'adversaire

// CORRECTION CLÉ: Utiliser un identifiant BDD basé sur le rôle ou l'ID de partie/joueur.
// Pour la table 'bateaux', utilisons 1 pour j1 et 2 pour j2.
$PARTIE_ID = 1; // ID de la partie, toujours 1 pour l'instant
$JOUEUR_ID_BDD = ($role === "joueur1") ? 1 : 2; // Utiliser 1 ou 2 pour différencier dans la table 'bateaux'
// REMPLACER toutes les instances de $JOUEUR_ID (qui valait 1) par $JOUEUR_ID_BDD.

// ... Dans la récupération des bateaux (lignes 86-93 du code précédent) :
$stmt_form = $pdo->prepare("SELECT nom_bateau FROM bateaux WHERE partie_id = ? AND joueur_id = ?");
$stmt_form->execute([$PARTIE_ID, $JOUEUR_ID_BDD]); // <--- Utilisation de JOUEUR_ID_BDD
// ...
// Idem pour la récupération des bateaux à afficher (lignes 97-99) :
$stmt_display = $pdo->prepare("SELECT case_depart, taille, orientation FROM bateaux WHERE partie_id = ? AND joueur_id = ?");
$stmt_display->execute([$PARTIE_ID, $JOUEUR_ID_BDD]); // <--- Utilisation de JOUEUR_ID_BDD

// Définition de TOUS les bateaux
// NOTE: Pensez à rendre les noms uniques (ex: 'Croiseur 1', 'Croiseur 2') dans le formulaire.
$bateaux_a_placer = [
    'Porte-avions' => 5,
    'Cuirassé' => 4,
    'Croiseur' => 3, 
    'Torpilleur' => 2
];

// --- 1. Préparation du Tableau d'Affichage ($plateau_affichage) ---
$plateau_affichage = array_fill(0, $TAILLE_GRILLE, array_fill(0, $TAILLE_GRILLE, 0)); // 0 = Eau (Code compatible avec l'ancienne grille)

try {
    // Récupérer les bateaux DÉJÀ placés par le joueur (pour le formulaire)
    $stmt_form = $pdo->prepare("SELECT nom_bateau FROM bateaux WHERE partie_id = ? AND joueur_id = ?");
    $stmt_form->execute([$PARTIE_ID, $JOUEUR_ID]);
    $noms_bateaux_places = $stmt_form->fetchAll(PDO::FETCH_COLUMN);

    // Filtrer la liste à afficher dans le formulaire
    $bateaux_restants = array_diff_key($bateaux_a_placer, array_flip($noms_bateaux_places));

    // 2. Lire les bateaux placés depuis la BDD (pour l'affichage)
    $stmt_display = $pdo->prepare("SELECT case_depart, taille, orientation FROM bateaux WHERE partie_id = ? AND joueur_id = ?");
    $stmt_display->execute([$PARTIE_ID, $JOUEUR_ID]);
    $bateaux_places = $stmt_display->fetchAll(PDO::FETCH_ASSOC);

    // 3. Marquer les cases occupées dans $plateau_affichage
    foreach ($bateaux_places as $bateau) {
        $depart_indices = coord_to_indices($bateau['case_depart']);
        
        if ($depart_indices) {
            $cases_occupees = calculer_cases_bateau(
                $depart_indices, 
                (int)$bateau['taille'], 
                $bateau['orientation']
            );

            foreach ($cases_occupees as $indices) {
                list($l, $c) = $indices;
                if ($l >= 0 && $l < $TAILLE_GRILLE && $c >= 0 && $c < $TAILLE_GRILLE) {
                    // 1 = Bateau (Compatible avec l'ancienne fonction dessiner_grille)
                    $plateau_affichage[$l][$c] = 1; 
                }
            }
        }
    }

    // MARQUAGE DES COUPS REÇUS (Si vous les gardez)
    // Ici, vous ajouteriez la logique pour récupérer les coups REÇUS (2=Plouf, 3=Touché) 
    // et les marquer dans $plateau_affichage, écrasant le '1' si le bateau est touché.

} catch (PDOException $e) {
    $error_db = "Erreur de chargement BDD : " . $e->getMessage();
    $bateaux_restants = $bateaux_a_placer;
}

// L'affichage de mon plateau est maintenant $plateau_affichage
$ma_grille = $plateau_affichage; 

// --- LECTURE DE L'ÉTAT 'PRET' (CONSERVÉE EN JSON) ---
$plateaux_content = file_get_contents("plateaux.json");
$plateaux_data = json_decode($plateaux_content, true) ?: [
    "j1" => ["pret" => false], 
    "j2" => ["pret" => false]
];

$pret = $plateaux_data[$joueur_id_json]["pret"] ?? false;
$adversaire_pret = $plateaux_data[$adversaire_id_json]["pret"] ?? false;

// --- Fonction utilitaire pour dessiner une grille  ---
function dessiner_grille($grille, $mode, $cible) {
    global $pret, $adversaire_pret, $TAILLE_GRILLE;
    
    // Début du rendu de la grille HTML
    // Utilisez 'grid-entetes' comme nouvelle classe pour le style spécifique aux entêtes
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
        
        // AFFICHAGE DES CELLULES DE JEU (comme avant)
        for ($x = 0; $x < $TAILLE_GRILLE; $x++) {
            // ... (Logique de contenu et de classe des cellules reste ici) ...
            
            $contenu_cellule = $grille[$y][$x] ?? 0; 
            $classes = "cell";
            $clic_action = '';
            
            // Logique de classe CSS (bateau, plouf, touche)
            if ($mode === 'ma-grille') {
                if ($contenu_cellule == 1) { 
                    $classes .= " bateau";
                } elseif ($contenu_cellule == 2) { 
                    $classes .= " plouf-recu";
                } elseif ($contenu_cellule == 3) { 
                    $classes .= " touche-recu";
                }
            } elseif ($mode === 'grille-tir') {
                // Logique des tirs
            }

            // Génération de la cellule
            echo '<div class="' . $classes . '" data-x="' . $x . '" data-y="' . $y . '" id="' . $cible . '-' . $x . '-' . $y . '" ' . $clic_action . '>';
            echo '</div>';
        }
    }
    
    // Fermeture de la grille
    echo '</div>';
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Plateau de jeu</title>
    
    <style>
        /* Conserver vos styles CSS */
        /* ... */
        .plateaux-container {
            display: flex;
            gap: 50px;
            margin-top: 20px;
        }
        .grid {
        display: grid;
        grid-template-columns: repeat(10, 40px);
        grid-template-rows: repeat(10, 40px);
        gap: 0; /* Si 'gap' ne marche pas, nous comptons sur la bordure */
        border: 2px solid #333; /* Bordure extérieure du plateau */
        }
        

        .grid {
    display: grid;
    grid-template-columns: repeat(10, 40px);
    grid-template-rows: repeat(10, 40px);
    gap: 0; /* Si 'gap' ne marche pas, nous comptons sur la bordure */
    border: 2px solid #333; /* Bordure extérieure du plateau */
    }
    .grid.grid-entetes {
    /* 11 colonnes : 1 pour les numéros de ligne + 10 pour les colonnes de jeu */
    grid-template-columns: repeat(11, 40px); 
    /* 11 lignes : 1 pour les lettres de colonne + 10 pour les lignes de jeu */
    grid-template-rows: repeat(11, 40px);
    gap: 0;
    border: 2px solid #333;
    }

    .cell.entete {
    background-color: #ddd;
    font-weight: bold;
    border: 1px solid #777; /* Bordure des entêtes */
    }
    .cell {
    width: 40px;
    height: 40px;
    /* Couleur de fond par défaut (eau/non touché) */
    background-color: #a8dadc; 
    /* BORDURE ESSENTIELLE pour voir la grille */
    border: 1px solid #000; 
    display: flex;
    justify-content: center;
    align-items: center;
    user-select: none;
    font-size: 0.8em;
    cursor: default; /* Non cliquable */
    }

    /* Styles spécifiques au jeu */
    /* ... */

    .ma-grille .cell.bateau {
        /* Utilisation de !important si nécessaire pour écraser le background par défaut */
        background-color: #3f51b5 !important; 
        color: white;
    }
        
        /* SUPPRIMER les styles qui concernent votre plateau en double */
        .grille { /* ANCIENNE GRILLE EN DOUBLE */
            display: none !important; /* Cacher la grille en double pour le moment */
        }
    </style>
</head>
<body>

<h1>Plateau de bataille navale</h1>
<h2>Vous êtes : <?= $role ?></h2>

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

<div class="plateaux-container">
    <div class="votre-plateau">
        <h3>🛥️ Ma Grille (Mes Bateaux)</h3>
        <?php dessiner_grille($ma_grille, 'ma-grille', $joueur_id_json); ?>
    </div>
    
    <div class="plateau-adversaire">
        <h3>💥 Grille de Tir (Adversaire : <?= $adversaire_id_json ?>)</h3>
        <?php 
        // TEMPORAIRE: Créer une grille vide pour éviter une erreur si buildGrille n'est pas définie
        $grille_tir_vide = array_fill(0, $TAILLE_GRILLE, array_fill(0, $TAILLE_GRILLE, 0));
        dessiner_grille($grille_tir_vide, 'grille-tir', $adversaire_id_json); 
        ?>
    </div>
</div>

<div class="grille" id="plateau-joueur-propre">
    </div>

</body>
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
</html>