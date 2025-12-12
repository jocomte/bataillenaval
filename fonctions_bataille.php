<?php

/**
 * Convertit une coordonnée de grille (Ex: A1, J10) en indices de tableau (Ex: [0, 0], [9, 9]).
 * @param string $coord La coordonnée textuelle (Ex: 'A1').
 * @return array|null Les indices [ligne, colonne] ou null si invalide.
 */
function coord_to_indices(string $coord): ?array {
    // La colonne est la première lettre (A=0, B=1, ... J=9)
    $col_lettre = substr($coord, 0, 1);
    $col_index = ord($col_lettre) - ord('A');

    // La ligne est le reste (1=0, 2=1, ... 10=9)
    $ligne_chiffre = (int)substr($coord, 1);
    $ligne_index = $ligne_chiffre - 1;

    // Vérification basique des limites (Grille 10x10)
    if ($col_index < 0 || $col_index > 9 || $ligne_index < 0 || $ligne_index > 9) {
        return null;
    }

    return [$ligne_index, $col_index];
}

/**
 * Calcule toutes les cases occupées par un bateau en indices [ligne, colonne].
 * @param array $depart_indices Indices de départ [ligne, colonne].
 * @param int $taille Taille du bateau.
 * @param string $orientation 'H' (Horizontal) ou 'V' (Vertical).
 * @return array Liste des indices [[l1, c1], [l2, c2], ...].
 */
function calculer_cases_bateau(array $depart_indices, int $taille, string $orientation): array {
    $ligne_dep = $depart_indices[0];
    $col_dep = $depart_indices[1];
    $cases = [];

    for ($i = 0; $i < $taille; $i++) {
        $l = $ligne_dep;
        $c = $col_dep;

        if ($orientation === 'H') {
            $c += $i; // Déplacement sur les colonnes
        } elseif ($orientation === 'V') {
            $l += $i; // Déplacement sur les lignes
        }
        
        // Vérifier si la case est bien dans la grille (0 à 9)
        if ($l < 0 || $l > 9 || $c < 0 || $c > 9) {
            // Retourner vide si le bateau dépasse
            return []; 
        }

        $cases[] = [$l, $c];
    }
    return $cases;
}

/**
 * Vérifie si deux listes de cases se chevauchent.
 * @param array $new_cases Nouvelles cases à placer.
 * @param array $existing_cases Cases déjà occupées.
 * @return bool Vrai si un chevauchement est trouvé.
 */
function check_overlap(array $new_cases, array $existing_cases): bool {
    // Convertir les tableaux d'indices en chaînes pour une comparaison facile (Ex: '0,0')
    $existing_map = array_map(fn($c) => implode(',', $c), $existing_cases);

    foreach ($new_cases as $new_case) {
        if (in_array(implode(',', $new_case), $existing_map)) {
            return true;
        }
    }
    return false;
}