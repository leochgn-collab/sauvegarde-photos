<?php
// Page de connexion (identifiant + mot de passe + code à 6 chiffres)
$comptes = comptes();
$erreur = null;

if (!$comptes) {
    page_debut('Installation');
    echo '<div class="carte connexion"><h1>Pas encore configuré</h1><p>Créez le compte administrateur sur le serveur :</p>'
       . '<pre>runuser -u www-data -- php /var/www/sauvegarde-photos/setup.php</pre></div>';
    page_fin();
    exit;
}

if ($post && $p === 'connexion') {
    $ip = ip_client();
    if (tentatives($ip) >= 5) {
        $erreur = 'Trop de tentatives. Réessayez dans 15 minutes.';
    } else {
        $id = (string)($_POST['identifiant'] ?? '');
        $c = $comptes[$id] ?? null;
        // On vérifie toujours les deux pour ne pas révéler si l'identifiant existe
        $hash = $c['mdp'] ?? '$2y$10$abcdefghijklmnopqrstuuJ0rXRlnUeQ0Yy3m6bZ3yqv0wQ0uGBe';
        $mdpOk = password_verify((string)($_POST['mdp'] ?? ''), $hash);
        $pas = null;
        $codeOk = $c && totp_verifier($c['totp'], (string)($_POST['code'] ?? ''), (int)($c['dernier_pas'] ?? 0), $pas);
        if ($c && $mdpOk && $codeOk) {
            $comptes[$id]['dernier_pas'] = $pas;
            $comptes[$id]['derniere_connexion'] = time();
            enregistrer_comptes($comptes);
            session_regenerate_id(true);
            $_SESSION['compte'] = $id;
            rediriger();
        }
        tentatives($ip, true);
        sleep(1);
        $erreur = 'Identifiant, mot de passe ou code incorrect.';
    }
}

page_debut('Connexion');
echo '<form class="carte connexion" method="post" action="' . url(['p' => 'connexion']) . '">' . csrf_champ()
   . '<div class="logo grand"><span class="pastille"></span>Sauvegardes photos</div>'
   . ($erreur ? '<div class="flash erreur">' . h($erreur) . '</div>' : '')
   . '<label>Identifiant<input name="identifiant" autocomplete="username" required autofocus></label>'
   . '<label>Mot de passe<input type="password" name="mdp" autocomplete="current-password" required></label>'
   . '<label>Code de l\'application d\'authentification<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]{6,7}" maxlength="7" required></label>'
   . '<button class="bouton">Se connecter</button></form>';
page_fin();
