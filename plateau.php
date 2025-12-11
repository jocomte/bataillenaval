<?php
session_start();

// --- Logique de réinitialisation ---
if (isset($_GET["reset"])) {
    // Détruire la session
    session_destroy();
    setcookie(session_name(), "", time() - 3600);

    // Réinitialiser les joueurs
    file_put_contents("etat_joueurs.json", json_encode([
        "j1" => null,
        "j2" => null
    ]));

    // Réinitialiser les plateaux avec des grilles vides 10x10
    file_put_contents("plateaux.json", json_encode([
        "j1" => ["grille" => array_fill(0, 10, array_fill(0, 10, 0)), "pret" => false],
        "j2" => ["grille" => array_fill(0, 10, array_fill(0, 10, 0)), "pret" => false]
    ]));


    // Repartir propre
    header("Location: index.php");
    exit;
}

if (!isset($_SESSION["role"])) {
    header("Location: index.php");
    exit;
}

$role = $_SESSION["role"]; // Ex: joueur1 ou joueur2
$joueur_id = ($role === "joueur1") ? "j1" : "j2";
$adversaire_id = ($role === "joueur1") ? "j2" : "j1";

// --- Lecture de l'état du jeu ---
// Initialisation du plateau si le fichier est vide (pour plus de robustesse)
$plateaux_content = file_get_contents("plateaux.json");
if (empty($plateaux_content)) {
    file_put_contents("plateaux.json", json_encode([
        "j1" => ["grille" => array_fill(0, 10, array_fill(0, 10, 0)), "pret" => false],
        "j2" => ["grille" => array_fill(0, 10, array_fill(0, 10, 0)), "pret" => false]
    ]));
    $plateaux_content = file_get_contents("plateaux.json");
}

$plateaux_data = json_decode($plateaux_content, true);

$mon_plateau = $plateaux_data[$joueur_id];
// Le plateau adversaire est utile pour la logique de tir, mais ici juste pour l'affichage du nom
$plateau_adversaire = $plateaux_data[$adversaire_id]; 

$ma_grille = $mon_plateau["grille"] ?? array_fill(0, 10, array_fill(0, 10, 0));
$pret = $mon_plateau["pret"] ?? false;

// La grille de tir est initialisée vide pour l'instant
$grille_tir = array_fill(0, 10, array_fill(0, 10, 0)); 


// Fonction utilitaire pour dessiner une grille
function dessiner_grille($grille, $mode, $cible) {
    global $pret, $plateaux_data, $adversaire_id;
    echo '<div class="grid ' . $mode . '">';
    for ($y = 0; $y < 10; $y++):
        for ($x = 0; $x < 10; $x++):
            $contenu_cellule = $grille[$y][$x] ?? 0;
            $classes = "cell";

            $clic_action = '';
            
            // 1. Logique de Ma Grille (Placement)
            if ($mode === 'ma-grille') {
                if (!$pret) {
                    // Si pas prêt, la cellule est cliquable pour PLACER
                    $classes .= " placable";
                    $clic_action = 'onclick="placerSegment(' . $x . ', ' . $y . ')"';
                }
                if ($contenu_cellule == 1) { // 1 = Segment de Bateau
                    $classes .= " bateau";
                }
            } 
            // 2. Logique de Grille de Tir (Tir)
            elseif ($mode === 'grille-tir') {
                // Le tir n'est permis que si les deux joueurs sont prêts
                $adversaire_pret = $plateaux_data[$adversaire_id]["pret"] ?? false;
                if ($pret && $adversaire_pret) { 
                    $clic_action = 'onclick="tirer(' . $x . ', ' . $y . ')"';
                }
            }

            echo '<div class="' . $classes . '" data-x="' . $x . '" data-y="' . $y . '" id="' . $cible . '-' . $x . '-' . $y . '" ' . $clic_action . '>';
            echo '</div>';
        endfor;
    endfor;
    echo '</div>';
}
require 'db_config.php';

function getPlateau($pdo, $joueur) {
    $stmt = $pdo->prepare("SELECT x, y, etat FROM plateaux WHERE joueur = ?");
    $stmt->execute([$joueur]);
    $cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $plateau = array_fill(0, 10, array_fill(0, 10, 'eau'));

    foreach ($cases as $case) {
        $plateau[$case['x']][$case['y']] = $case['etat'];
    }

    return $plateau;
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
                // Mise à jour de l'interface utilisateur après succès
                const button = document.getElementById('bouton-pret');
                const status = document.getElementById('statut-placement');

                if (estPret) {
                    button.textContent = 'Prêt ! (Attente)';
                    button.disabled = true;
                    button.classList.remove('non-pret');
                    button.classList.add('est-pret');
                    status.innerHTML = '🟢 Placement Terminé. En attente de l\'adversaire...';
                    
                } else {
                    button.textContent = 'J\'ai Placé mes Bateaux';
                    button.disabled = false;
                    button.classList.remove('est-pret');
                    button.classList.add('non-pret');
                    status.innerHTML = '🔴 Phase de Placement. Cliquez sur votre grille pour placer des segments de bateau.';
                }
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