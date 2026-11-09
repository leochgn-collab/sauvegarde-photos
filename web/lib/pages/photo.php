<?php
// Visionneuse plein écran (← → pour naviguer, Échap pour fermer, glisser sur mobile)
$c = (string)($_GET['c'] ?? '');
$d = (string)($_GET['d'] ?? '');
$dir = dossier($c, $d);
$rel = (string)($_GET['f'] ?? '');
if (!$dir || !fichier($dir, $rel)) rediriger();

$fichiers = lister_fichiers($dir);
$liste = array_keys($fichiers);
$pos = array_search($rel, $liste, true);
if ($pos === false) rediriger(['p' => 'dossier', 'c' => $c, 'd' => $d]);
$info = $fichiers[$rel];
$q = ['c' => $c, 'd' => $d];
$prec = $pos > 0 ? url(['p' => 'photo'] + $q + ['f' => $liste[$pos - 1]]) : null;
$suiv = $pos < count($liste) - 1 ? url(['p' => 'photo'] + $q + ['f' => $liste[$pos + 1]]) : null;
$page = intdiv($pos, PAR_PAGE) + 1;
$fermer = url(['p' => 'dossier'] + $q + ($page > 1 ? ['page' => $page] : []));
$type = type_fichier($rel);

page_debut(basename($rel), '', 'visionneuse');
echo '<div class="vis-barre"><div><b>' . h(basename($rel)) . '</b><small>' . h(nom_camion($c)) . ' · ' . h($d === 'en-attente' ? 'En attente' : $d)
   . ' · ' . date('d-m-Y H:i', $info['date']) . ' · ' . taille_lisible($info['taille']) . ' · ' . ($pos + 1) . ' / ' . count($liste) . '</small></div>'
   . '<div class="boutons"><a class="petit" href="' . url(['p' => 'fichier', 'dl' => 1] + $q + ['f' => $rel]) . '">Télécharger l\'original</a>'
   . '<a class="petit" id="fermer" href="' . $fermer . '" title="Fermer (Échap)">✕</a></div></div>';

echo '<div class="vis-scene">';
echo $prec ? '<a class="vis-nav gauche" id="prec" href="' . $prec . '" title="Précédente (←)">‹</a>' : '';
if ($type === 'image') {
    echo '<img id="media" src="' . url(['p' => 'apercu'] + $q + ['f' => $rel]) . '" alt="' . h(basename($rel)) . '">';
} elseif ($type === 'video') {
    echo '<video id="media" controls preload="metadata" src="' . url(['p' => 'fichier'] + $q + ['f' => $rel]) . '"></video>';
} else {
    echo '<div class="vis-autre">Aperçu impossible pour ce type de fichier.<br><a class="bouton" href="'
       . url(['p' => 'fichier', 'dl' => 1] + $q + ['f' => $rel]) . '">Télécharger</a></div>';
}
echo $suiv ? '<a class="vis-nav droite" id="suiv" href="' . $suiv . '" title="Suivante (→)">›</a>' : '';
echo '</div><script src="visionneuse.js"></script>';
page_fin();
