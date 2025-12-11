<?php

// Fichier: game.php
session_start();
include 'db_config.php'; // Inclure la connexion SQL

// --- Définition de la taille du plateau ---
$TAILLE_PLATEAU = 10;

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
        // Suppression de toutes les données liées aux plateaux, bateaux et coups.
        // C'est l'approche la plus simple pour une réinitialisation complète.
        $pdo->exec("DELETE FROM Segments");
        $pdo->exec("DELETE FROM Coups");
        $pdo->exec("DELETE FROM Plateaux");
        
        // Optionnel : Réinitialiser l'auto-incrément après la suppression
        $pdo->exec("ALTER TABLE Plateaux AUTO_INCREMENT = 1"); 
        
    } catch (\PDOException $e) {
        // Gérer l'erreur de suppression SQL
        error_log("Erreur lors de la réinitialisation SQL: " . $e->getMessage());
    }

    // Repartir propre
    header("Location: index.php");
    exit;
}

if (!isset($_SESSION["role"])) {
    header("Location: index.php");
    exit;
}

$role = $_SESSION["role"]; // Ex: joueur1 ou joueur2
$joueur_id = ($role === "joueur1") ? "j1" : "j2"; // ID du joueur (j1 ou j2)
$adversaire_id = ($role === "joueur1") ? "j2" : "j1"; // ID de l'adversaire

// --- FONCTIONS SQL POUR LA LECTURE DES DONNÉES ---

// Fonction pour récupérer l'ID du plateau à partir de l'ID du joueur
function getPlateauId($pdo, $joueur_id) {
    $stmt = $pdo->prepare("SELECT id_plateau FROM Plateaux WHERE id_joueur = ?");
    $stmt->execute([$joueur_id]);
    return $stmt->fetchColumn() ?: null;
}

// Fonction pour construire la grille du plateau
function buildGrille($pdo, $plateau_id, $is_my_board) {
    global $TAILLE_PLATEAU;
    $grille = array_fill(0, $TAILLE_PLATEAU, array_fill(0, $TAILLE_PLATEAU, 0)); // 0 = Mer

    if (!$plateau_id) {
        return $grille; // Retourne une grille vide si aucun plateau n'existe
    }

    // 1. Récupérer les segments de bateaux (utilisé pour mon_plateau)
    if ($is_my_board) {
        $stmt_bateaux = $pdo->prepare("SELECT coordonnee_x AS x, coordonnee_y AS y FROM Segments WHERE id_plateau = ?");
        $stmt_bateaux->execute([$plateau_id]);
        foreach ($stmt_bateaux->fetchAll() as $segment) {
            $grille[$segment['y']][$segment['x']] = 1; // 1 = Segment de Bateau
        }
    }

    // 2. Récupérer les coups enregistrés sur ce plateau
    // Note: Pour la grille de tir, nous affichons les coups tirés sur l'ADVERSAIRE.
    // Pour ma grille, nous affichons les coups que j'AI REÇUS.
    $stmt_coups = $pdo->prepare("SELECT coordonnee_x AS x, coordonnee_y AS y, resultat FROM Coups WHERE id_plateau_cible = ?");
    $stmt_coups->execute([$plateau_id]);
    foreach ($stmt_coups->fetchAll() as $coup) {
        // Codes pour l'affichage: 2 = Manqué (Plouf), 3 = Touché
        $code = ($coup['resultat'] === 'plouf') ? 2 : 3;
        $grille[$coup['y']][$coup['x']] = $code;
    }

    return $grille;
}

// --- LECTURE DE L'ÉTAT DU JEU (MIXTE SQL/JSON) ---

// Récupération de l'ID de mes plateaux et de ceux de l'adversaire
$mon_plateau_id = getPlateauId($pdo, $joueur_id);
$adversaire_plateau_id = getPlateauId($pdo, $adversaire_id);

// Récupération des données de la grille SQL
// Ma Grille : affiche mes bateaux (1) et les coups reçus (2 ou 3)
$ma_grille = buildGrille($pdo, $mon_plateau_id, true);

// Grille de Tir : affiche les coups que j'ai tirés sur l'adversaire (2 ou 3)
// Pour cela, nous lisons les coups enregistrés sur le plateau ADVERSAIRE.
$grille_tir = buildGrille($pdo, $adversaire_plateau_id, false);


// --- LECTURE DE L'ÉTAT 'PRET' (CONSERVÉE EN JSON) ---
$plateaux_content = file_get_contents("plateaux.json");
// Rendre le code plus robuste pour éviter les erreurs de lecture JSON
$plateaux_data = json_decode($plateaux_content, true) ?: [
    "j1" => ["pret" => false], 
    "j2" => ["pret" => false]
];

$pret = $plateaux_data[$joueur_id]["pret"] ?? false;
$adversaire_pret = $plateaux_data[$adversaire_id]["pret"] ?? false;


// --- Le reste de votre logique de dessin et d'interface reste le même ---

// Fonction utilitaire pour dessiner une grille (MISE À JOUR POUR LES NOUVEAUX CODES)
// TEMPORAIRE : Remplacez VOTRE fonction dessiner_grille
// Modifiez la déclaration de la fonction pour utiliser les variables déjà calculées

// ... (Toute la logique PHP jusqu'à la ligne 104 reste inchangée) ...

// Fonction utilitaire pour dessiner une grille (CORRIGÉE)
function dessiner_grille($grille, $mode, $cible) {
    
    // VARIABLES GLOBALES NÉCESSAIRES
    global $pret, $adversaire_pret; 
    $TAILLE_PLATEAU = 10;
    
    // Début du rendu de la grille HTML
    echo '<div class="grid ' . $mode . '">';
    
    for ($y = 0; $y < $TAILLE_PLATEAU; $y++) {
        for ($x = 0; $x < $TAILLE_PLATEAU; $x++) {
            
            $contenu_cellule = $grille[$y][$x] ?? 0; 
            $classes = "cell";
            $clic_action = '';
            
            // --- Logique du Plateau Actuel (Mes Bateaux / Coups reçus) ---
            if ($mode === 'ma-grille') {
                
                // 1. Placement
                if (!$pret) { 
                    $classes .= " placable";
                    $clic_action = 'onclick="placerSegment(' . $x . ', ' . $y . ')"';
                }
                
                // 2. Affichage
                if ($contenu_cellule == 1) {       
                    $classes .= " bateau";
                } elseif ($contenu_cellule == 2) { 
                    $classes .= " plouf-recu";
                } elseif ($contenu_cellule == 3) { 
                    $classes .= " touche-recu";
                }
            } 
            
            // --- Logique du Plateau de Tir (Coups envoyés) ---
            elseif ($mode === 'grille-tir') {
                
                // CORRECTION CRITIQUE : Ajout des accolades {}
                if ($pret && $adversaire_pret) {
                    $clic_action = 'onclick="tirer(' . $x . ', ' . $y . ')"';
                } // La boucle continue ici
                
                // Affichage des résultats de mes tirs sur l'adversaire
                if ($contenu_cellule == 2) {       
                    $classes .= " plouf-tire";
                } elseif ($contenu_cellule == 3) { 
                    $classes .= " touche-tire";
                }
            }

            // Génération de la cellule
            echo '<div class="' . $classes . '" data-x="' . $x . '" data-y="' . $y . '" id="' . $cible . '-' . $x . '-' . $y . '" ' . $clic_action . '>';
            echo '</div>';
            
        } // Fin de la boucle X
    } // Fin de la boucle Y
    
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
        body {
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .plateaux-container {
            display: flex;
            gap: 50px;
            margin-top: 20px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(10, 40px);
            grid-template-rows: repeat(10, 40px);
            gap: 2px;
        }
        .cell {
            width: 40px;
            height: 40px;
            background-color: #eee;
            border: 1px solid #999;
            display: flex;
            justify-content: center;
            align-items: center;
            user-select: none;
            font-size: 0.8em;
        }
        /* Styles spécifiques au jeu */
        .ma-grille .cell.placable:hover {
            background-color: #c8e6c9; /* Vert clair au survol pour placement */
            cursor: pointer;
        }
        .grille-tir .cell {
            background-color: #e0f7fa; /* Fond plus clair pour la zone de tir */
        }
        .grille-tir .cell:hover {
             background-color: #b3e5fc; /* Bleu plus clair au survol pour tir */
            cursor: pointer;
        }

        .ma-grille .cell.bateau {
            background-color: #3f51b5 !important;
            color: white;
        }

        /* Nouveaux styles pour les codes SQL (2=Plouf, 3=Touché) */
        .ma-grille .cell.plouf-recu, 
        .grille-tir .cell.plouf-tire {
            background-color: #4dd0e1 !important; /* Bleu clair */
            /* content: '💧'; <-- Non valide dans CSS, à afficher en JS/PHP */
        }
        .ma-grille .cell.touche-recu, 
        .grille-tir .cell.touche-tire {
            background-color: #ef5350 !important; /* Rouge clair */
            /* content: '🔥'; <-- Non valide dans CSS, à afficher en JS/PHP */
        }

        .actions {
            margin-top: 30px;
            padding: 15px;
            border: 1px solid #ccc;
            border-radius: 8px;
            background: #f9f9f9;
            text-align: center;
        }
        .pret-button {
            padding: 10px 20px;
            font-size: 1.1em;
            cursor: pointer;
            border: none;
            border-radius: 5px;
            color: white;
        }
        .pret-button:disabled {
            background-color: #aaa;
            cursor: not-allowed;
        }
        #bouton-pret.non-pret {
             background-color: #4CAF50; /* Vert */
        }
        #bouton-pret.est-pret {
             background-color: #FF9800; /* Orange */
        }
        .status-message {
            font-weight: bold;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>

<h1>Plateau de bataille navale</h1>
<h2>Vous êtes : <?= $role ?></h2>

<div class="actions">
    <div id="statut-placement" class="status-message">
        <?php if ($pret): ?>
            🟢 Placement Terminé. En attente de l'adversaire...
        <?php else: ?>
            🔴 Phase de Placement. Cliquez sur votre grille pour placer/retirer des segments de bateau.
        <?php endif; ?>
    </div>
    <button id="bouton-pret" class="pret-button <?= $pret ? 'est-pret' : 'non-pret' ?>" 
            onclick="setPret(<?= $pret ? 'false' : 'true' ?>)"
            <?= $pret ? 'disabled' : '' ?>>
        <?= $pret ? 'Prêt ! (Attente)' : 'J\'ai Placé mes Bateaux' ?>
    </button>
</div>

<div class="plateaux-container">
    <div class="votre-plateau">
        <h3>🛥️ Ma Grille (Mes Bateaux)</h3>
        <?php dessiner_grille($ma_grille, 'ma-grille', $joueur_id); ?>
    </div>

    <div class="plateau-adversaire">
        <h3>💥 Grille de Tir (Adversaire : <?= $adversaire_id ?>)</h3>
        <?php dessiner_grille($grille_tir, 'grille-tir', $adversaire_id); ?>
    </div>
</div>

<script>
    const joueurId = '<?= $joueur_id ?>';
    let estPret = <?= $pret ? 'true' : 'false' ?>;

    // --- Logique de Placement ---
    function placerSegment(x, y) {
        if (estPret) {
            alert("Vous êtes déjà prêt. Réinitialisez la partie pour un nouveau placement.");
            return;
        }
        
        // Envoi de la requête AJAX pour placer/retirer un segment
        fetch('action.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'placer',
                joueur: joueurId,
                x: x,
                y: y
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Mise à jour visuelle immédiate (ajout/retrait de la classe 'bateau')
                const cell = document.querySelector(`.ma-grille .cell[data-x="${x}"][data-y="${y}"]`);
                if (cell) {
                    if (data.etat === 1) {
                         cell.classList.add('bateau');
                    } else {
                         cell.classList.remove('bateau');
                    }
                }
            } else {
                alert("Erreur de placement: " + data.message);
            }
        })
        .catch(error => console.error('Erreur AJAX:', error));
    }

    // --- Logique "Prêt" ---
    function setPret(pretState) {
          estPret = (pretState === 'true' || pretState === true);
          
        // Envoi de la requête AJAX pour changer l'état de 'pret'
        fetch('action.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'set_pret',
                joueur: joueurId,
                pret: estPret
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Rechargement pour mettre à jour l'affichage de l'adversaire
                window.location.reload(); 
            } else {
                alert("Erreur lors de la mise à jour de l'état 'Prêt': " + data.message);
                estPret = !estPret; // Rétablit l'état en cas d'erreur
            }
        })
        .catch(error => {
            console.error('Erreur AJAX:', error);
            estPret = !estPret; // Rétablit l'état en cas d'erreur
        });
    }

    // --- Logique de Tir (Placeholder) ---
    function tirer(x, y) {
        // Sera développé à l'étape suivante (logique de tir)
        console.log(`Tir effectué en X: ${x}, Y: ${y}`);
        alert(`Tir en (${x}, ${y})! La logique de tir sera implémentée à la prochaine étape.`);
    }

</script>

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

</body>
</html>