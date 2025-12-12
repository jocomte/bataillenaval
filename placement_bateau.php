<?php
// placement_bateau.php

// DOIT ÊTRE LA PREMIÈRE INSTRUCTION EXÉCUTABLE
session_start();

// 1. Connexion et Utilitaires
require_once 'db_config.php';
require_once 'fonctions_bataille.php';

// --- CONFIGURATION / IDENTIFICATION DU JOUEUR ---
$PARTIE_ID = 1; 
$role = $_SESSION["role"] ?? 'joueur1'; 
$JOUEUR_ID_BDD = ($role === "joueur1") ? 1 : 2;
$ERREUR = null;

// Définit les bateaux standards (Pour la validation)
$bateaux_definitions = [
    'Porte-avions' => 5,
    'Cuirassé' => 4,
    'Croiseur (1)' => 3,
    'Croiseur (2)' => 3,
    'Torpilleur' => 2
];
$NOMBRE_MAX_BATEAUX = count($bateaux_definitions);

// --- 0. VÉRIFICATION DE LA MÉTHODE ET REDIRECTION ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// 2. Récupération des données du formulaire (VALIDE UNIQUEMENT DANS CE BLOC POST)
$nom_bateau = $_POST['nom_bateau'] ?? '';
$case_depart = strtoupper(trim($_POST['coordonnee_depart'] ?? ''));
$orientation = strtoupper(trim($_POST['orientation'] ?? ''));

// --- Détermination de la Taille ---
$taille = 0;
foreach ($bateaux_definitions as $name => $size) {
    if ($nom_bateau === $name) {
        $taille = $size;
        break;
    }
}

// Validation initiale des données (Formulaire)
if ($taille === 0 || empty($case_depart) || !in_array($orientation, ['H', 'V'])) {
    $ERREUR = "Données du formulaire invalides (taille/coordonnée manquante).";
}

if (!$ERREUR) {
    $depart_indices = coord_to_indices($case_depart);

    if (is_null($depart_indices)) {
        $ERREUR = "Coordonnée de départ invalide.";
    }
}

// 3. Logique de Placement et de Validation (PHP)
if (!$ERREUR) {
    // Calcul des cases que le NOUVEAU bateau occuperait
    $cases_a_occuper = calculer_cases_bateau($depart_indices, $taille, $orientation);

    if (empty($cases_a_occuper)) {
        $ERREUR = "Le bateau dépasse de la grille.";
    }
}

// --- 4. LOGIQUE DE TRANSACTION BDD (SQL) ---
if (!$ERREUR) {
    try {
        // Lancer la transaction UNIQUEMENT si aucune n'est active
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
        
        // 4.1. VÉRIFICATION DE LA LIMITE MAXIMALE DE BATEAUX
        $stmt_total = $pdo->prepare("SELECT COUNT(*) FROM bateaux WHERE partie_id = ? AND joueur_id = ?");
        $stmt_total->execute([$PARTIE_ID, $JOUEUR_ID_BDD]);
        $bateaux_deja_places = $stmt_total->fetchColumn();
        
        if ($bateaux_deja_places >= $NOMBRE_MAX_BATEAUX) {
            // Vérifier si le bateau soumis est déjà dans la BDD (si oui, on le laisse passer pour le test d'unicité)
            $stmt_check_unicite = $pdo->prepare("SELECT COUNT(*) FROM bateaux WHERE partie_id = ? AND joueur_id = ? AND nom_bateau = ?");
            $stmt_check_unicite->execute([$PARTIE_ID, $JOUEUR_ID_BDD, $nom_bateau]);
            
            if ($stmt_check_unicite->fetchColumn() === 0) {
                 $ERREUR = "Limite atteinte : Vous avez déjà placé le nombre maximum de bateaux ({$NOMBRE_MAX_BATEAUX}).";
            }
        }
        
        // 4.2. VÉRIFICATION D'UNICITÉ (pour les types de bateaux)
        if (!$ERREUR) {
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM bateaux WHERE partie_id = ? AND joueur_id = ? AND nom_bateau = ?");
            $stmt_check->execute([$PARTIE_ID, $JOUEUR_ID_BDD, $nom_bateau]);
            if ($stmt_check->fetchColumn() > 0) {
                $ERREUR = "Le bateau '{$nom_bateau}' est déjà placé.";
            }
        }

        // 4.3. VÉRIFICATION DU CHEVAUCHEMENT
        if (!$ERREUR) {
            $stmt_existants = $pdo->prepare("SELECT case_depart, taille, orientation FROM bateaux WHERE partie_id = ? AND joueur_id = ?");
            $stmt_existants->execute([$PARTIE_ID, $JOUEUR_ID_BDD]);
            $bateaux_existants = $stmt_existants->fetchAll(PDO::FETCH_ASSOC);

            foreach ($bateaux_existants as $bateau) {
                $cases_existantes = calculer_cases_bateau(
                    coord_to_indices($bateau['case_depart']), 
                    $bateau['taille'], 
                    $bateau['orientation']
                );
                
                if (check_overlap($cases_a_occuper, $cases_existantes)) {
                    $ERREUR = "Le placement chevauche un bateau existant.";
                    break;
                }
            }
        }

        // 4.4. INSERTION (Si tout est OK)
        if (!$ERREUR) {
            $sql = "INSERT INTO bateaux (partie_id, joueur_id, nom_bateau, taille, case_depart, orientation) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$PARTIE_ID, $JOUEUR_ID_BDD, $nom_bateau, $taille, $case_depart, $orientation]);

            $pdo->commit();
            header('Location: index.php?message=Bateau+placé+avec+succès');
            exit;

        } else {
             // Erreur détectée dans la transaction
            $pdo->rollBack();
            header('Location: index.php?erreur=' . urlencode($ERREUR));
            exit;
        }

    } catch (PDOException $e) {
        // Erreur SQL
        if ($pdo->inTransaction()) { 
            $pdo->rollBack();
        }
        header('Location: index.php?erreur=' . urlencode("Erreur SQL: " . $e->getMessage()));
        exit;
    }
} else {
    // Erreur de validation initiale (Formulaire/PHP)
    header('Location: index.php?erreur=' . urlencode($ERREUR));
    exit;
}