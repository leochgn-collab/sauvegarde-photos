<?php
// Installer un téléphone en 30 secondes : QR code de connexion pour l'app Nextcloud
exiger_admin();
$c = (string)($_GET['c'] ?? '');
if (!in_array($c, camions(), true)) rediriger();
$nom = nom_camion($c);
header('Cache-Control: no-store');   // le QR code contient un mot de passe : jamais en cache

if ($post) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'adresse') {
        $u = rtrim(trim((string)($_POST['nc_url'] ?? '')), '/');
        if (!preg_match('~^https://[A-Za-z0-9.-]+(:\d+)?(/[^\s"\'<>]*)?$~', $u)) {
            flash('Adresse invalide : elle doit commencer par https:// (ex. https://nc.leo44.cloud).', 'erreur');
        } else {
            $w = reglages_web();
            $w['nc_url'] = $u;
            enregistrer_reglages_web($w);
            flash("Adresse de Nextcloud enregistrée : $u");
        }

    } elseif ($action === 'generer') {
        $url = nc_url();
        if ($url === '') {
            flash('Indiquez d\'abord l\'adresse publique de Nextcloud.', 'erreur');
            rediriger(['p' => 'installer', 'c' => $c]);
        }
        $appareil = trim((string)($_POST['appareil'] ?? '')) ?: "Téléphone $nom";
        $appareil = mb_substr(preg_replace('/[^\p{L}\p{N} ._()-]/u', '', $appareil), 0, 60) . ' — ' . date('d-m-Y');
        // Sans le mot de passe du compte : suffisant pour envoyer et consulter des photos.
        // Délai long : pour un compte jamais utilisé, Nextcloud prépare d'abord son espace.
        set_time_limit(300);
        session_write_close();   // ne bloque pas les autres pages pendant ce temps
        $debut = microtime(true);
        [$rc, $out] = occ(['user:auth-tokens:add', '--no-interaction', '--name=' . $appareil, $c], [], 150);
        session_start();
        $duree = (int)round(microtime(true) - $debut);
        if ($rc !== 0 || !preg_match('/app password:\s*(\S+)/i', $out, $m)) {
            flash("Impossible de créer le mot de passe d'application (après {$duree} s) : " . mb_substr($out, 0, 300), 'erreur');
            rediriger(['p' => 'installer', 'c' => $c]);
        }
        // Le mot de passe n'est gardé que le temps d'un affichage
        $_SESSION['qr_installation'] = ['c' => $c, 'url' => $url, 'mdp' => $m[1], 'appareil' => $appareil];
        rediriger(['p' => 'installer', 'c' => $c, 'qr' => 1]);

    } elseif ($action === 'revoquer') {
        $id = (string)($_POST['id'] ?? '');
        if (!ctype_digit($id)) { flash('Appareil invalide.', 'erreur'); }
        else {
            [$rc, $out] = occ(['user:auth-tokens:delete', $c, $id]);
            $rc === 0 ? flash('Accès de cet appareil coupé : il ne peut plus rien envoyer ni consulter. Les photos déjà reçues sont conservées.')
                      : flash('Échec de la révocation : ' . mb_substr($out, 0, 200), 'erreur');
        }
    }
    rediriger(['p' => 'installer', 'c' => $c]);
}

// ============================================================================
page_debut("Installer $nom");
echo '<p class="fil"><a href="./">Tableau de bord</a> › <a href="' . url(['p' => 'camion', 'c' => $c]) . '">' . h($nom) . '</a> › Installer un téléphone</p>';

// --- QR code tout juste généré (affiché une seule fois) ---
$qr = $_SESSION['qr_installation'] ?? null;
if ($qr && ($qr['c'] ?? '') === $c && !empty($_GET['qr'])) {
    unset($_SESSION['qr_installation']);
    $lien = 'nc://login/user:' . $c . '&password:' . $qr['mdp'] . '&server:' . $qr['url'];
    $img = qr_svg($lien);
    echo '<section class="carte fiche">'
       . '<div class="fiche-entete"><div><h1>Installer « ' . h($nom) . ' »</h1><p class="note">' . h($qr['appareil']) . '</p></div>'
       . '<span class="avertissement">Ne pas photographier ni partager ce QR code</span></div>'
       . '<div class="fiche-corps">'
       . ($img ? '<img class="qr grand" src="' . $img . '" alt="QR code de connexion">'
               : '<div class="flash erreur">qrencode n\'est pas installé sur le serveur (apt install qrencode). Saisie manuelle ci-dessous.</div>')
       . '<ol class="etapes">'
       . '<li>Installer <b>Nextcloud</b> depuis le Play Store, puis l\'ouvrir.</li>'
       . '<li>Sur l\'écran de connexion, appuyer sur l\'<b>icône QR code</b> (« Scanner le code QR ») et scanner ce code.</li>'
       . '<li>☰ → <b>Paramètres</b> → <b>Envoi automatique</b> → dossier <b>Camera</b> : activer, dossier distant <code>/InstantUpload</code>, '
       . '« Le fichier original sera… » : <b>conservé</b> au début.</li>'
       . '<li>Paramètres Android → Applications → Nextcloud → Batterie → <b>Non restreinte</b>.</li>'
       . '<li>Prendre une photo de test : elle doit apparaître « en attente » sur la page du téléphone d\'ici 1 à 2 minutes.</li>'
       . '</ol></div>'
       . '<details class="manuel"><summary>Saisie manuelle (si le scan ne marche pas)</summary><dl>'
       . '<dt>Serveur</dt><dd><code>' . h($qr['url']) . '</code></dd>'
       . '<dt>Identifiant</dt><dd><code>' . h($c) . '</code></dd>'
       . '<dt>Mot de passe d\'application</dt><dd><code>' . h($qr['mdp']) . '</code></dd></dl></details>'
       . '<p class="aide no-print">Ce code ne sera plus affiché après avoir quitté la page. Imprimez la fiche (Ctrl+P) si besoin, puis détruisez-la une fois le téléphone installé. '
       . 'En cas de perte, coupez l\'accès de l\'appareil ci-dessous.</p>'
       . '<div class="boutons no-print"><a class="bouton" href="' . url(['p' => 'installer', 'c' => $c]) . '">Terminé</a></div></section>';
    page_fin();
    exit;
}

$url = nc_url();
echo '<h1>Installer un téléphone — ' . h($nom) . '</h1><div class="grille deux">';

// --- Générer ---
echo '<section class="carte"><h2>Nouveau QR code de connexion</h2>';
if ($url === '') {
    echo '<div class="flash erreur">Adresse publique de Nextcloud inconnue : renseignez-la ci-contre.</div>';
}
echo '<form method="post">' . csrf_champ() . '<input type="hidden" name="action" value="generer">'
   . '<label>Nom de l\'appareil <small>(pour le reconnaître dans la liste)</small>'
   . '<input name="appareil" maxlength="60" placeholder="Samsung A15 de ' . h($nom) . '"></label>'
   . '<p class="aide">Un <b>mot de passe d\'application</b> propre à ce téléphone est créé (le mot de passe du compte n\'est pas nécessaire) : '
   . 'il peut être coupé à tout moment sans toucher au compte ni aux autres téléphones.</p>'
   . '<button class="bouton"' . ($url === '' ? ' disabled' : '') . '>Générer le QR code</button></form></section>';

// --- Adresse ---
echo '<section class="carte"><h2>Adresse de Nextcloud</h2><form method="post">' . csrf_champ() . '<input type="hidden" name="action" value="adresse">'
   . '<label>Adresse publique utilisée par les téléphones<input type="url" name="nc_url" placeholder="https://nc.leo44.cloud" value="' . h($url) . '" required></label>'
   . '<p class="aide">Toujours en <b>https://</b>. Elle est commune à tous les téléphones.</p>'
   . '<button class="bouton secondaire">Enregistrer</button></form></section></div>';

// --- Appareils connectés ---
$liste = appareils($c);
echo '<section class="carte"><h2>Appareils connectés à ' . h($c) . '</h2>';
if ($liste === null) {
    echo '<p class="vide">Liste indisponible (compte inexistant ou version de Nextcloud trop ancienne).</p>';
} elseif (!$liste) {
    echo '<p class="vide">Aucun appareil connecté pour l\'instant.</p>';
} else {
    echo '<div class="tableau-defile"><table><thead><tr><th>Appareil</th><th>Dernière activité</th><th></th></tr></thead><tbody>';
    foreach ($liste as $a) {
        $recent = $a['actif'] && time() - $a['actif'] < 86400;
        echo '<tr><td>' . h($a['nom']) . '</td><td>' . ($a['actif'] ? date('d-m-Y H:i', $a['actif']) : '—')
           . ($recent ? ' <span class="etat ok">en ligne aujourd\'hui</span>' : '') . '</td><td class="actions">'
           . '<form method="post" class="en-ligne">' . csrf_champ() . '<input type="hidden" name="action" value="revoquer">'
           . '<input type="hidden" name="id" value="' . h($a['id']) . '"><button class="petit danger">Couper l\'accès</button></form></td></tr>';
    }
    echo '</tbody></table></div><p class="aide">Téléphone perdu, volé ou remplacé : « Couper l\'accès » le déconnecte immédiatement. Les photos déjà sauvegardées ne sont pas touchées.</p>';
}
echo '</section>';
page_fin();
