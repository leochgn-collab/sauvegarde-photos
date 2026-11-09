<?php
// Mon compte : changer son mot de passe
$comptes = comptes();
$moi = moi();

if ($post) {
    $actuel = (string)($_POST['actuel'] ?? '');
    $n1 = (string)($_POST['nouveau'] ?? '');
    $n2 = (string)($_POST['confirmation'] ?? '');
    if (!password_verify($actuel, $comptes[$moi]['mdp'])) { sleep(1); flash('Mot de passe actuel incorrect.', 'erreur'); }
    elseif (strlen($n1) < 12) flash('Nouveau mot de passe trop court (12 caractères minimum).', 'erreur');
    elseif ($n1 !== $n2) flash('Les deux nouveaux mots de passe ne correspondent pas.', 'erreur');
    else {
        $comptes[$moi]['mdp'] = password_hash($n1, PASSWORD_DEFAULT);
        enregistrer_comptes($comptes);
        flash('Mot de passe changé.');
    }
    rediriger(['p' => 'moncompte']);
}

page_debut('Mon compte', 'moncompte');
$c = $comptes[$moi];
echo '<h1>Mon compte</h1><div class="grille deux"><section class="carte"><h2>' . h($moi) . '</h2><dl>'
   . '<dt>Rôle</dt><dd>' . ($c['role'] === 'admin' ? 'Administrateur' : 'Lecture seule') . '</dd>'
   . '<dt>Double authentification</dt><dd>activée</dd>'
   . '<dt>Connecté depuis</dt><dd>' . (!empty($c['derniere_connexion']) ? date('d-m-Y H:i', $c['derniere_connexion']) : '—') . '</dd></dl>'
   . '<p class="aide">Téléphone d\'authentification perdu ? Un administrateur peut réinitialiser votre double authentification depuis « Comptes ».</p></section>'
   . '<section class="carte"><h2>Changer de mot de passe</h2><form method="post">' . csrf_champ()
   . '<label>Mot de passe actuel<input type="password" name="actuel" autocomplete="current-password" required></label>'
   . '<label>Nouveau mot de passe <small>(12 caractères min.)</small><input type="password" name="nouveau" minlength="12" autocomplete="new-password" required></label>'
   . '<label>Confirmer<input type="password" name="confirmation" minlength="12" autocomplete="new-password" required></label>'
   . '<button class="bouton">Changer</button></form></section></div>';
page_fin();
