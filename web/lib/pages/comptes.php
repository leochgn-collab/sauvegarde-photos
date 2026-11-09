<?php
// Comptes de l'interface (administrateurs uniquement)
exiger_admin();
$comptes = comptes();
const ROLES = ['admin' => 'Administrateur', 'lecture' => 'Lecture seule'];

if ($post) {
    $action = (string)($_POST['action'] ?? '');
    $id = trim((string)($_POST['id'] ?? ''));
    $nbAdmins = count(array_filter($comptes, fn($c) => $c['role'] === 'admin'));

    if ($action === 'ajouter') {
        $mdp = (string)($_POST['mdp'] ?? '');
        $role = (string)($_POST['role'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._@-]{2,64}$/', $id)) flash('Identifiant invalide (2 à 64 caractères : lettres, chiffres, . _ - @).', 'erreur');
        elseif (isset($comptes[$id])) flash("Le compte « $id » existe déjà.", 'erreur');
        elseif (strlen($mdp) < 12) flash('Mot de passe trop court (12 caractères minimum).', 'erreur');
        elseif (!isset(ROLES[$role])) flash('Rôle invalide.', 'erreur');
        else {
            $secret = b32_encode(random_bytes(20));
            $comptes[$id] = ['mdp' => password_hash($mdp, PASSWORD_DEFAULT), 'totp' => $secret, 'dernier_pas' => 0, 'role' => $role, 'cree' => time()];
            enregistrer_comptes($comptes);
            $_SESSION['totp_affiche'] = [$id, $secret];
            flash("Compte « $id » créé (" . ROLES[$role] . '). Configurez sa double authentification ci-dessous.');
        }
    } elseif (!isset($comptes[$id])) {
        flash('Compte introuvable.', 'erreur');
    } elseif ($action === 'supprimer') {
        if ($id === moi()) flash('Vous ne pouvez pas supprimer votre propre compte.', 'erreur');
        elseif ($comptes[$id]['role'] === 'admin' && $nbAdmins <= 1) flash('Impossible : il doit rester au moins un administrateur.', 'erreur');
        else { unset($comptes[$id]); enregistrer_comptes($comptes); flash("Compte « $id » supprimé."); }
    } elseif ($action === 'role') {
        $role = (string)($_POST['role'] ?? '');
        if (!isset(ROLES[$role])) flash('Rôle invalide.', 'erreur');
        elseif ($comptes[$id]['role'] === 'admin' && $role !== 'admin' && $nbAdmins <= 1) flash('Impossible : il doit rester au moins un administrateur.', 'erreur');
        else { $comptes[$id]['role'] = $role; enregistrer_comptes($comptes); flash("« $id » est maintenant : " . ROLES[$role] . '.'); }
    } elseif ($action === 'reinit_2fa') {
        $secret = b32_encode(random_bytes(20));
        $comptes[$id]['totp'] = $secret;
        $comptes[$id]['dernier_pas'] = 0;
        enregistrer_comptes($comptes);
        $_SESSION['totp_affiche'] = [$id, $secret];
        flash("Double authentification de « $id » réinitialisée : l'ancien code ne fonctionne plus.");
    } elseif ($action === 'reinit_mdp') {
        $mdp = (string)($_POST['mdp'] ?? '');
        if (strlen($mdp) < 12) flash('Mot de passe trop court (12 caractères minimum).', 'erreur');
        else { $comptes[$id]['mdp'] = password_hash($mdp, PASSWORD_DEFAULT); enregistrer_comptes($comptes); flash("Mot de passe de « $id » changé."); }
    }
    rediriger(['p' => 'comptes']);
}

page_debut('Comptes', 'comptes');
echo '<h1>Comptes de l\'interface</h1>';

if (!empty($_SESSION['totp_affiche'])) {
    [$tid, $tsecret] = $_SESSION['totp_affiche'];
    unset($_SESSION['totp_affiche']);
    echo bloc_totp($tid, $tsecret);
}

echo '<section class="carte tableau-defile"><table><thead><tr><th>Identifiant</th><th>Rôle</th><th>Dernière connexion</th><th>Actions</th></tr></thead><tbody>';
foreach ($comptes as $id => $c) {
    $f = fn(string $action, string $extra = '') => '<form method="post" class="en-ligne">' . csrf_champ()
        . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="id" value="' . h($id) . '">' . $extra;
    echo '<tr><td><b>' . h($id) . '</b>' . ($id === moi() ? ' <small>(vous)</small>' : '') . '</td><td>'
       . $f('role', '<select name="role">' . implode('', array_map(fn($k) => '<option value="' . $k . '"' . ($c['role'] === $k ? ' selected' : '') . '>' . ROLES[$k] . '</option>', array_keys(ROLES)))
            . '</select> <button class="petit">OK</button></form>')
       . '</td><td>' . (!empty($c['derniere_connexion']) ? date('d-m-Y H:i', $c['derniere_connexion']) : '—') . '</td><td class="actions">'
       . $f('reinit_mdp', '<input type="password" name="mdp" placeholder="Nouveau mot de passe" minlength="12" autocomplete="new-password" required> <button class="petit">Changer</button></form>')
       . $f('reinit_2fa', '<button class="petit">Nouvelle 2FA</button></form>')
       . ($id !== moi() ? $f('supprimer', '<button class="petit danger">Supprimer</button></form>') : '')
       . '</td></tr>';
}
echo '</tbody></table></section>';

echo '<section class="carte"><h2>Nouveau compte</h2><form method="post" class="ajout">' . csrf_champ() . '<input type="hidden" name="action" value="ajouter">'
   . '<label>Identifiant<input name="id" pattern="[A-Za-z0-9._@\-]{2,64}" required></label>'
   . '<label>Mot de passe <small>(12 caractères min.)</small><input type="password" name="mdp" minlength="12" autocomplete="new-password" required></label>'
   . '<label>Rôle<select name="role"><option value="lecture">Lecture seule</option><option value="admin">Administrateur</option></select></label>'
   . '<button class="bouton">Créer le compte</button></form>'
   . '<p class="aide"><b>Lecture seule</b> : voir et télécharger les photos. <b>Administrateur</b> : tout gérer (réglages, sauvegardes, suppressions, comptes).<br>Après la création, un QR code s\'affiche une seule fois : la personne doit le scanner avec son application d\'authentification.</p></section>';
page_fin();
