<?php
session_start();

$fichier = "etat_joueurs.json";

if (!file_exists($fichier)) {
  file_put_contents($fichier, json_encode(["j1" => null, "j2" => null]));
}


$etat = json_decode(file_get_contents($fichier), true);

if ($etat["j1"] != null && $etat["j2"] != null) {
  include('plateau.php'); 
} else {
  include('player-selected.php');
}

header('Refresh:2');