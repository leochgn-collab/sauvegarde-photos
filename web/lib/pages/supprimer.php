<?php
// Suppression manuelle d'une sauvegarde datée (avec confirmation)
exiger_admin();
$c = (string)($_GET['c'] ?? '');
$d = (string)($_GET['d'] ?? '');
$dir = preg_match(REGEX_DATE, $d) ? dossier($c, $d) : null;   // jamais le dossier « en attente »
if (!$dir) rediriger();

if ($post) {
    if (($_POST['confirmation'] ?? '') !== $d) {
        flash('Confirmation incorrecte : tapez exactement la date du dossier.', 'erreur');
        rediriger(['p' => 'supprimer', 'c' => $c, 'd' => $d]);
    }
    supprimer_recursif($dir);
    [$rc] = occ(['files:scan', '--path=/' . $c . '/files']);
    flash("Sauvegarde du $d de " . nom_camion($c) . ' supprimée.' . ($rc ? ' (Nextcloud : relancez « occ files:scan » si elle apparaît encore.)' : ''));
    rediriger(['p' => 'camion', 'c' => $c]);
}

$f = lister_fichiers($dir);
page_debut('Supprimer une sauvegarde');
echo '<form class="carte confirmation" method="post">' . csrf_champ()
   . '<h1>Supprimer la sauvegarde du ' . h($d) . ' ?</h1>'
   . '<p><b>' . h(nom_camion($c)) . '</b> — ' . count($f) . ' fichiers, ' . taille_lisible(array_sum(array_column($f, 'taille'))) . '.</p>'
   . '<div class="flash erreur">Les photos seront supprimées <b>définitivement</b> du serveur (pas de corbeille). '
   . 'Pensez à télécharger le .zip avant si besoin.</div>'
   . '<label>Pour confirmer, tapez la date du dossier : <b>' . h($d) . '</b>'
   . '<input name="confirmation" autocomplete="off" required></label>'
   . '<div class="boutons"><button class="bouton danger">Supprimer définitivement</button>'
   . '<a class="bouton secondaire" href="' . url(['p' => 'camion', 'c' => $c]) . '">Annuler</a></div></form>';
page_fin();
