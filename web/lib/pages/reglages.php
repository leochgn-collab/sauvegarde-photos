<?php
// Réglages (administrateurs uniquement)
exiger_admin();
$r = lire_reglages();
$infos = infos_camions();

const LIBELLES = [
    'UTILISATEURS' => 'liste des téléphones', 'FREQUENCE' => 'fréquence', 'JOUR' => 'jour', 'JOUR_MOIS' => 'jour du mois (1 à 28)',
    'HEURE' => 'heure (0 à 23)', 'PAUSE' => 'pause', 'PAUSE_CAMIONS' => 'pause par téléphone',
    'AGE_MIN_MINUTES' => 'délai d\'attente (0 à 9999 min)', 'ALERTE_SILENCE_JOURS' => 'alerte silence (0 à 999 jours)',
    'ALERTE_DISQUE_PCT' => 'seuil disque (0 à 99 %)', 'RETENTION_MOIS' => 'conservation (0 à 999 mois)',
    'NTFY_URL' => 'adresse ntfy (http(s)://…)', 'NTFY_TOKEN' => 'jeton ntfy (lettres, chiffres, _ et -)',
    'ECRITURE' => 'écriture du fichier de réglages',
];

function enregistrer(array $r, string $ok): void
{
    $err = ecrire_reglages($r);
    $err === null ? flash($ok) : flash('Valeur invalide : ' . LIBELLES[$err] . '. Rien n\'a été enregistré.', 'erreur');
}

if ($post) {
    $action = (string)($_POST['action'] ?? '');
    $v = fn(string $k) => trim((string)($_POST[$k] ?? ''));
    $liste = camions($r);

    switch ($action) {
    case 'planning':
        $r['FREQUENCE'] = $v('frequence');
        $r['JOUR'] = $v('jour');
        $r['JOUR_MOIS'] = $v('jour_mois');
        $r['HEURE'] = $v('heure');
        $r['PAUSE'] = empty($_POST['pause']) ? '0' : '1';
        enregistrer($r, $r['PAUSE'] === '1' ? 'Planning enregistré — sauvegardes automatiques EN PAUSE.' : 'Planning enregistré.');
        break;

    case 'telephones':
        $pauses = [];
        foreach ($liste as $c) {
            $infos[$c] = [
                'nom'  => mb_substr(trim((string)($_POST['nom'][$c] ?? '')), 0, 60),
                'note' => mb_substr(trim((string)($_POST['note'][$c] ?? '')), 0, 200),
            ];
            if (!empty($_POST['pause'][$c])) $pauses[] = $c;
        }
        enregistrer_infos_camions($infos);
        $r['PAUSE_CAMIONS'] = implode(' ', $pauses);
        enregistrer($r, 'Téléphones enregistrés.');
        break;

    case 'ajouter':
        $nom = $v('camion');
        $mdp = (string)($_POST['mdp'] ?? '');
        if (!preg_match(REGEX_CAMION, $nom)) { flash('Identifiant invalide (lettres, chiffres, . _ - @ uniquement, sans espace).', 'erreur'); break; }
        if (in_array($nom, $liste, true)) { flash("$nom est déjà dans la liste.", 'erreur'); break; }
        if ($mdp !== '') {
            // On vérifie l'existence du compte plutôt que de se fier au code de retour d'occ
            // (occ peut afficher un avertissement et renvoyer une erreur alors que le compte est créé).
            [$existe] = occ(['user:info', $nom]);
            if ($existe === 0) {
                flash("Le compte Nextcloud $nom existait déjà : il est simplement ajouté aux sauvegardes (mot de passe inchangé).");
            } else {
                [$rc, $out] = occ(['user:add', '--password-from-env', '--display-name=' . ($v('nom') ?: $nom), $nom], ['OC_PASS' => $mdp]);
                [$cree] = occ(['user:info', $nom]);
                if ($cree !== 0) { flash("Création du compte Nextcloud impossible : $out", 'erreur'); break; }
                flash("Compte Nextcloud $nom créé.");
            }
        }
        $infos[$nom] = ['nom' => mb_substr($v('nom'), 0, 60), 'note' => mb_substr($v('note'), 0, 200)];
        enregistrer_infos_camions($infos);
        $liste[] = $nom;
        $r['UTILISATEURS'] = implode(' ', $liste);
        enregistrer($r, "$nom ajouté aux sauvegardes.");
        break;

    case 'retirer':
        $nom = $v('camion');
        $r['UTILISATEURS'] = implode(' ', array_diff($liste, [$nom]));
        $r['PAUSE_CAMIONS'] = implode(' ', array_diff(liste_mots($r['PAUSE_CAMIONS']), [$nom]));
        enregistrer($r, "$nom retiré des sauvegardes (son compte Nextcloud et ses photos sont conservés).");
        break;

    case 'surveillance':
        $r['ALERTE_SILENCE_JOURS'] = $v('silence');
        $r['ALERTE_DISQUE_PCT'] = $v('disque');
        $r['AGE_MIN_MINUTES'] = $v('age');
        enregistrer($r, 'Surveillance enregistrée.');
        break;

    case 'ntfy':
        $r['NTFY_URL'] = $v('ntfy_url');
        if (!empty($_POST['ntfy_token_suppr'])) $r['NTFY_TOKEN'] = '';
        elseif ($v('ntfy_token') !== '') $r['NTFY_TOKEN'] = $v('ntfy_token');
        enregistrer($r, 'Notifications enregistrées.');
        break;

    case 'test_ntfy':
        [$ok, $msg] = ntfy_envoyer('🔔 Test Sauvegardes photos', 'Les notifications fonctionnent. Envoyé depuis l\'interface le ' . date('d-m-Y à H:i') . '.', $r);
        flash($msg, $ok ? 'ok' : 'erreur');
        break;

    case 'nettoyage':
        $mois = $v('retention');
        if ($mois !== '0' && $mois !== $r['RETENTION_MOIS'] && empty($_POST['confirme'])) {
            flash('Cochez la case de confirmation pour activer la suppression automatique.', 'erreur');
            break;
        }
        $r['RETENTION_MOIS'] = $mois;
        enregistrer($r, $mois === '0' ? 'Nettoyage automatique désactivé : toutes les sauvegardes sont conservées.'
                                     : "Nettoyage activé : les sauvegardes de plus de $mois mois seront supprimées à chaque sauvegarde planifiée.");
        break;
    }
    rediriger(['p' => 'reglages']);
}

// ============================================================================
page_debut('Réglages', 'reglages');
if (!is_writable(REGLAGES)) echo '<div class="flash erreur">Le fichier ' . h(REGLAGES) . ' n\'est pas modifiable par le serveur web.</div>';
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$prochaine = prochaine_sauvegarde($r);

echo '<h1>Réglages</h1><div class="grille deux">';

// --- Planification ---
echo '<section class="carte"><h2>Planification</h2><form method="post">' . csrf_champ() . '<input type="hidden" name="action" value="planning">'
   . '<label>Fréquence<select name="frequence">';
foreach (FREQUENCES as $k => $lib) echo '<option value="' . $k . '"' . $sel($k, $r['FREQUENCE']) . '>' . ucfirst($lib) . '</option>';
echo '</select></label><div class="cote-a-cote"><label>Jour<select name="jour">';
foreach (JOURS as $n => $j) echo '<option value="' . $n . '"' . $sel($n, $r['JOUR']) . '>' . ucfirst($j) . '</option>';
echo '</select></label><label>Jour du mois <small>(mensuel)</small><select name="jour_mois">';
for ($n = 1; $n <= 28; $n++) echo '<option value="' . $n . '"' . $sel($n, $r['JOUR_MOIS']) . '>' . $n . '</option>';
echo '</select></label><label>Heure<select name="heure">';
for ($n = 0; $n <= 23; $n++) echo '<option value="' . $n . '"' . $sel($n, $r['HEURE']) . '>' . $n . ' h</option>';
echo '</select></label></div>'
   . '<label class="case"><input type="checkbox" name="pause" value="1"' . ($r['PAUSE'] === '1' ? ' checked' : '') . '> <span>Mettre toutes les sauvegardes automatiques en pause</span></label>'
   . '<p class="aide">' . ($r['PAUSE'] === '1' ? '⏸ En pause.' : h(description_planning($r)) . '. Prochaine : <b>'
       . ($prochaine ? ($prochaine <= time() ? 'imminente' : h(date_longue($prochaine))) : '—') . '</b>.')
   . ' Les boutons « Sauvegarder maintenant » restent utilisables.</p>'
   . '<button class="bouton">Enregistrer</button></form></section>';

// --- Surveillance ---
echo '<section class="carte"><h2>Surveillance</h2><form method="post">' . csrf_champ() . '<input type="hidden" name="action" value="surveillance">'
   . '<label>Alerter si un téléphone n\'envoie rien depuis (jours) <small>0 = jamais</small>'
   . '<input type="number" name="silence" min="0" max="999" value="' . h($r['ALERTE_SILENCE_JOURS']) . '" required></label>'
   . '<label>Alerter si l\'espace disque libre passe sous (%) <small>0 = jamais</small>'
   . '<input type="number" name="disque" min="0" max="99" value="' . h($r['ALERTE_DISQUE_PCT']) . '" required></label>'
   . '<label>Ignorer les photos reçues depuis moins de (minutes) <small>évite de prendre une photo en cours d\'envoi</small>'
   . '<input type="number" name="age" min="0" max="9999" value="' . h($r['AGE_MIN_MINUTES']) . '" required></label>'
   . '<p class="aide">Les alertes partent sur ntfy, au plus une fois par période. Un plantage du script envoie aussi une alerte.</p>'
   . '<button class="bouton">Enregistrer</button></form></section>';

echo '</div>';

// --- Téléphones ---
echo '<section class="carte"><h2>Téléphones</h2><form method="post" id="f-tel">' . csrf_champ() . '<input type="hidden" name="action" value="telephones"></form>'
   . '<div class="tableau-defile"><table class="tel"><thead><tr><th>Identifiant</th><th>Nom affiché</th><th>Note</th><th>Pause</th><th></th></tr></thead><tbody>';
foreach (camions($r) as $c) {
    $existe = is_dir(dossier_camion($c));
    echo '<tr><td><b>' . h($c) . '</b>' . ($existe ? '' : '<br><em class="alerte">jamais connecté</em>') . '</td>'
       . '<td><input form="f-tel" name="nom[' . h($c) . ']" maxlength="60" placeholder="' . h($c) . '" value="' . h($infos[$c]['nom'] ?? '') . '"></td>'
       . '<td><input form="f-tel" name="note[' . h($c) . ']" maxlength="200" placeholder="Immatriculation, chauffeur…" value="' . h($infos[$c]['note'] ?? '') . '"></td>'
       . '<td class="centre"><input form="f-tel" type="checkbox" name="pause[' . h($c) . ']" value="1"' . (en_pause($c, $r) ? ' checked' : '') . ' title="Mettre en pause"></td>'
       . '<td class="actions"><a class="petit" href="' . url(['p' => 'installer', 'c' => $c]) . '">Installer</a> <form method="post" class="en-ligne">' . csrf_champ() . '<input type="hidden" name="action" value="retirer">'
       . '<input type="hidden" name="camion" value="' . h($c) . '"><button class="petit danger">Retirer</button></form></td></tr>';
}
if (!camions($r)) echo '<tr><td colspan="5" class="vide">Aucun téléphone.</td></tr>';
echo '</tbody></table></div><button form="f-tel" class="bouton">Enregistrer les téléphones</button>'
   . '<p class="aide">Pause = ce téléphone n\'est plus sauvegardé ni surveillé (ses photos restent en attente). '
   . 'Retirer = il disparaît de la liste ; son compte Nextcloud et ses photos restent en place. '
   . 'Installer = QR code à scanner dans l\'app Nextcloud du téléphone.</p>';

echo '<h3 class="sous-titre">Ajouter un téléphone</h3><form method="post" class="ajout">' . csrf_champ() . '<input type="hidden" name="action" value="ajouter">'
   . '<label>Identifiant Nextcloud<input name="camion" placeholder="Camion4" pattern="[A-Za-z0-9._@\-]{1,64}" required></label>'
   . '<label>Nom affiché <small>(facultatif)</small><input name="nom" maxlength="60"></label>'
   . '<label>Note <small>(facultatif)</small><input name="note" maxlength="200"></label>'
   . '<label>Mot de passe <small>(pour créer le compte Nextcloud, sinon vide)</small><input type="password" name="mdp" autocomplete="new-password"></label>'
   . '<button class="bouton">Ajouter</button></form></section>';

echo '<div class="grille deux">';

// --- Notifications ---
echo '<section class="carte"><h2>Notifications ntfy</h2><form method="post" id="f-ntfy">' . csrf_champ() . '<input type="hidden" name="action" value="ntfy">'
   . '<label>Adresse <small>(vide = pas de notification)</small><input type="url" name="ntfy_url" placeholder="https://ntfy.exemple.fr/sauvegardes-photos" value="' . h($r['NTFY_URL']) . '"></label>'
   . '<label>Jeton <small>' . ($r['NTFY_TOKEN'] !== '' ? '(enregistré — laisser vide pour le garder)' : '(si le sujet est protégé)') . '</small>'
   . '<input type="password" name="ntfy_token" autocomplete="off"></label>'
   . ($r['NTFY_TOKEN'] !== '' ? '<label class="case"><input type="checkbox" name="ntfy_token_suppr" value="1"> Supprimer le jeton</label>' : '')
   . '</form><form method="post" id="f-test">' . csrf_champ() . '<input type="hidden" name="action" value="test_ntfy"></form>'
   . '<div class="boutons"><button form="f-ntfy" class="bouton">Enregistrer</button>'
   . '<button form="f-test" class="bouton secondaire"' . ($r['NTFY_URL'] === '' ? ' disabled' : '') . '>Tester la notification</button></div>'
   . '<p class="aide">Le test utilise les réglages <b>enregistrés</b> : enregistrez avant de tester.</p></section>';

// --- Nettoyage ---
$mois = (int)$r['RETENTION_MOIS'];
echo '<section class="carte"><h2>Nettoyage automatique</h2><form method="post">' . csrf_champ() . '<input type="hidden" name="action" value="nettoyage">'
   . '<label>Supprimer les sauvegardes de plus de (mois) <small>0 = tout garder</small>'
   . '<input type="number" name="retention" min="0" max="999" value="' . $mois . '" required></label>'
   . '<p class="aide">' . ($mois ? "Actif : <b>" . a_nettoyer($mois) . '</b> sauvegarde(s) seraient supprimées au prochain passage planifié.'
                               : 'Désactivé : toutes les sauvegardes sont conservées.') . '</p>'
   . '<label class="case"><input type="checkbox" name="confirme" value="1"> <span>Je comprends que les sauvegardes plus anciennes seront supprimées <b>définitivement</b></span></label>'
   . '<button class="bouton">Enregistrer</button></form></section>';

echo '</div>';
page_fin();
