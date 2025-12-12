<?php
// placement_bateau.php

// 1. Connexion et Utilitaires
require_once 'db_config.php'; // Doit fournir la variable $pdo (PDO instance)
require_once 'fonctions_bataille.php';

// Définit les bateaux standards (Pour la validation)
$bateaux_definitions = [
    'Porte-avions' => 5,
    'Cuirassé' => 4,
    'Croiseur' => 3, // On pourrait en avoir plusieurs de taille 3
    'Torpilleur' => 2
];

// Simule l'ID de partie et de joueur (A adapter pour une vraie gestion de session)
$PARTIE_ID = 1; 
$JOUEUR_ID = 1; 
$ERREUR = null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// 2. Récupération des données du formulaire
$nom_bateau = $_POST['nom_bateau'] ?? ''; // Ex: 'Porte-avions'
$case_depart = strtoupper(trim($_POST['coordonnee_depart'] ?? '')); // Ex: 'B3'
$orientation = strtoupper(trim($_POST['orientation'] ?? '')); // Ex: 'H'

// Validation initiale des données
if (!isset($bateaux_definitions[$nom_bateau]) || empty($case_depart) || !in_array($orientation, ['H', 'V'])) {
    $ERREUR = "Données du formulaire invalides.";
}

if (!$ERREUR) {
    $taille = $bateaux_definitions[$nom_bateau];
    $depart_indices = coord_to_indices($case_depart);

    if (is_null($depart_indices)) {
        $ERREUR = "Coordonnée de départ invalide.";
    }
}

// 3. Logique de Placement et de Validation
if (!$ERREUR) {
    // Calcul des cases que le NOUVEAU bateau occuperait
    $cases_a_occuper = calculer_cases_bateau($depart_indices, $taille, $orientation);

    if (empty($cases_a_occuper)) {
        $ERREUR = "Le bateau dépasse de la grille.";
    }
}

if (!$ERREUR) {
    try {
        $pdo->beginTransaction();

        // A. Vérifier si ce type de bateau n'est pas déjà placé (pour les bateaux uniques)
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM bateaux WHERE partie_id = ? AND joueur_id = ? AND nom_bateau = ?");
        $stmt_check->execute([$PARTIE_ID, $JOUEUR_ID, $nom_bateau]);
        if ($stmt_check->fetchColumn() > 0) {
            $ERREUR = "Le bateau '{$nom_bateau}' est déjà placé.";
        }
        
        // B. Récupérer les bateaux déjà placés pour vérifier les chevauchements
        if (!$ERREUR) {
            $stmt_existants = $pdo->prepare("SELECT case_depart, taille, orientation FROM bateaux WHERE partie_id = ? AND joueur_id = ?");
            $stmt_existants->execute([$PARTIE_ID, $JOUEUR_ID]);
            $bateaux_existants = $stmt_existants->fetchAll(PDO::FETCH_ASSOC);

            foreach ($bateaux_existants as $bateau) {
                // Calculer les cases occupées par le bateau existant
                $cases_existantes = calculer_cases_bateau(
                    coord_to_indices($bateau['case_depart']), 
                    $bateau['taille'], 
                    $bateau['orientation']
                );
                
                // Vérifier le chevauchement
                if (check_overlap($cases_a_occuper, $cases_existantes)) {
                    $ERREUR = "Le placement chevauche un bateau existant.";
                    break;
                }
            }
        }

        // 4. Insertion du nouveau bateau dans la BDD (Si la validation passe)
        if (!$ERREUR) {
            $sql = "INSERT INTO bateaux (partie_id, joueur_id, nom_bateau, taille, case_depart, orientation) 
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $PARTIE_ID, 
                $JOUEUR_ID, 
                $nom_bateau, 
                $taille, 
                $case_depart, 
                $orientation
            ]);

            $pdo->commit();
            header('Location: index.php?message=Bateau+placé+avec+succès');
            exit;

        } else {
             // Si erreur pendant la transaction (chevauchement, déjà placé, etc.)
            $pdo->rollBack();
            header('Location: index.php?erreur=' . urlencode($ERREUR));
            exit;
        }

    } catch (PDOException $e) {
        // Erreur SQL grave (connexion, syntaxe, etc.)
        $pdo->rollBack();
        header('Location: index.php?erreur=' . urlencode("Erreur SQL: " . $e->getMessage()));
        exit;
    }
} else {
    // Erreur de validation initiale (formulaire)
    header('Location: index.php?erreur=' . urlencode($ERREUR));
    exit;
}