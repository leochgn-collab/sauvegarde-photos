<?php
// Galerie d'une sauvegarde datée (ou des photos en attente)
$c = (string)($_GET['c'] ?? '');
$d = (string)($_GET['d'] ?? '');
$dir = dossier($c, $d);
if (!$dir) rediriger();
$fichiers = lister_fichiers($dir);
$pages = max(1, (int)ceil(count($fichiers) / PAR_PAGE));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$titre = $d === 'en-attente' ? 'En attente' : $d;
$nom = nom_camion($c);

page_debut("$nom · $titre", "c:$c");
echo '<p class="fil"><a href="./">Tableau de bord</a> › <a href="' . url(['p' => 'camion', 'c' => $c]) . '">' . h($nom) . '</a> › ' . h($titre) . '</p>'
   . '<div class="entete"><h1>' . h($titre) . ' <small>' . count($fichiers) . ' fichiers · '
   . taille_lisible(array_sum(array_column($fichiers, 'taille'))) . '</small></h1><div class="boutons">'
   . '<a class="bouton" href="' . url(['p' => 'zip', 'c' => $c, 'd' => $d]) . '">Tout télécharger (.zip)</a>'
   . (est_admin() && $d !== 'en-attente' ? '<a class="bouton secondaire danger" href="' . url(['p' => 'supprimer', 'c' => $c, 'd' => $d]) . '">Supprimer</a>' : '')
   . '</div></div>';
echo '<div class="galerie">';
foreach (array_slice($fichiers, ($page - 1) * PAR_PAGE, PAR_PAGE, true) as $rel => $i) {
    $q = ['c' => $c, 'd' => $d, 'f' => $rel];
    echo '<figure><a href="' . url(['p' => 'photo'] + $q) . '">'
       . '<img loading="lazy" src="' . url(['p' => 'mini'] + $q) . '" alt="' . h(basename($rel)) . '"></a>'
       . '<figcaption><span title="' . h($rel) . '">' . h(basename($rel)) . '</span>'
       . '<a href="' . url(['p' => 'fichier', 'dl' => 1] + $q) . '" title="Télécharger l\'original">⬇</a></figcaption>'
       . '<small>' . date('d-m-Y H:i', $i['date']) . ' · ' . taille_lisible($i['taille']) . '</small></figure>';
}
echo '</div>';
if (!$fichiers) echo '<p class="vide">Dossier vide.</p>';
if ($pages > 1) {
    echo '<nav class="pagination">';
    for ($n = 1; $n <= $pages; $n++) {
        echo $n === $page ? "<b>$n</b>" : '<a href="' . url(['p' => 'dossier', 'c' => $c, 'd' => $d, 'page' => $n]) . '">' . $n . '</a>';
    }
    echo '</nav>';
}
page_fin();
