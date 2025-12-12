

<?php

session_start();

if (isset($_GET["reset"])) {
    // Détruire la session
    session_destroy();
    setcookie(session_name(), "", time() - 3600);

    // Réinitialiser les joueurs
    file_put_contents("joueurs.json", json_encode([
        "j1" => null,
        "j2" => null
    ]));

    // Repartir propre
    header("Location: index.php");
    exit;
}


function save_state($file, $data) {
  file_put_contents($file, json_encode($data));
}

if (isset($_POST["joueur1"])) {
  if ($etat["j1"] === null) {
    $etat["j1"] = session_id();
    $_SESSION["role"] = "joueur1";
    save_state("joueurs.json", $etat);
  }
}

if (isset($_POST["joueur2"])) {
  if ($etat["j2"] === null) {
    $etat["j2"] = session_id();
    $_SESSION["role"] = "joueur2";
    save_state("joueurs.json", $etat);
  }
}

$role = $_SESSION["role"] ?? "Aucun rôle";

?>

<!DOCTYPE html>
<html>
  <head>
      <meta charset="UTF-8">
      <title>Joueur 1 / Joueur 2</title>
  </head>
  <body>
    <h1>Connexion aux rôles</h1>
    <h2>Votre rôle actuel : <strong><?= $role ?></strong></h2>
    <p>
      Joueur 1 : <?= $etat["j1"] ? "🟢 Occupé" : "🔴 Libre" ?><br>
      Joueur 2 : <?= $etat["j2"] ? "🟢 Occupé" : "🔴 Libre" ?>
    </p>

    <form method="post">
      <button type="submit" name="joueur1"
          <?= $etat["j1"] !== null ? "disabled" : "" ?>>
          🎮 Devenir Joueur 1
      </button>
      <button type="submit" name="joueur2"
          <?= $etat["j2"] !== null ? "disabled" : "" ?>>
          🎮 Devenir Joueur 2
      </button>
    </form>
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