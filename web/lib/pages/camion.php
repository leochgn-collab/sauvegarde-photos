<?php
// Page d'un camion : ses sauvegardes datées
$c = (string)($_GET['c'] ?? '');
if (!in_array($c, camions(), true)) rediriger();
$admin = est_admin();
$s = resume_camion($c);
$nom = nom_camion($c);
$note = trim((string)(infos_camions()[$c]['note'] ?? ''));

page_debut($nom, "c:$c");
echo '<p class="fil"><a href="./">Tableau de bord</a> › ' . h($nom) . '</p>'
   . '<div class="entete"><div><h1>' . h($nom) . ' <span class="etat ' . $s['etat'][0] . '">' . $s['etat'][1] . '</span>'
   . ($nom !== $c ? ' <small>' . h($c) . '</small>' : '') . '</h1>'
   . ($note !== '' ? '<p class="note">' . h($note) . '</p>' : '') . '</div>';
if ($admin) {
    echo '<div class="boutons"><a class="bouton secondaire" href="' . url(['p' => 'installer', 'c' => $c]) . '">Installer / gérer le téléphone</a>';
    if (!en_pause($c)) {
        echo '<form method="post" action="' . url(['p' => 'lancer']) . '">' . csrf_champ()
           . '<input type="hidden" name="c[]" value="' . h($c) . '"><input type="hidden" name="retour" value="' . h($c) . '">'
           . '<button class="bouton">Sauvegarder ce téléphone maintenant</button></form>';
    }
    echo '</div>';
}
echo '</div>';

if (!empty($_SESSION['derniere_sortie'])) {
    echo '<pre class="journal">' . h($_SESSION['derniere_sortie']) . '</pre>';
    unset($_SESSION['derniere_sortie']);
}

echo '<div class="grille stats">'
   . '<div class="carte stat"><span>Dernier envoi reçu</span><b>' . ($s['dernier_envoi'] ? date('d-m-Y', $s['dernier_envoi']) : '—') . '</b><small>'
   . ($s['dernier_envoi'] ? 'à ' . date('H:i', $s['dernier_envoi']) : 'aucun envoi connu') . '</small></div>'
   . '<div class="carte stat"><span>En attente</span><b>' . $s['attente'] . '</b><small>' . taille_lisible($s['attente_taille']) . '</small></div>'
   . '<div class="carte stat"><span>Sauvegardes</span><b>' . count($s['sauvegardes']) . '</b><small>' . $s['total_photos'] . ' fichiers</small></div>'
   . '<div class="carte stat"><span>Espace utilisé</span><b>' . taille_lisible($s['total_taille']) . '</b><small>sauvegardes datées</small></div></div>';

echo '<section class="carte tableau-defile"><table><thead><tr><th>Dossier</th><th>Fichiers</th><th>Taille</th><th></th></tr></thead><tbody>';
if ($s['attente']) {
    echo '<tr class="attente"><td>En attente <small>(sera rangé à la prochaine sauvegarde)</small></td><td>' . $s['attente'] . '</td><td>'
       . taille_lisible($s['attente_taille']) . '</td><td class="actions"><a class="petit" href="' . url(['p' => 'dossier', 'c' => $c, 'd' => 'en-attente']) . '">Voir</a></td></tr>';
}
foreach ($s['sauvegardes'] as $d => $i) {
    echo '<tr><td>' . h($d) . '</td><td>' . $i['nb'] . '</td><td>' . taille_lisible($i['taille']) . '</td><td class="actions">'
       . '<a class="petit" href="' . url(['p' => 'dossier', 'c' => $c, 'd' => $d]) . '">Voir</a>'
       . '<a class="petit" href="' . url(['p' => 'zip', 'c' => $c, 'd' => $d]) . '">Télécharger (.zip)</a>'
       . ($admin ? '<a class="petit danger" href="' . url(['p' => 'supprimer', 'c' => $c, 'd' => $d]) . '">Supprimer</a>' : '')
       . '</td></tr>';
}
if (!$s['attente'] && !$s['sauvegardes']) echo '<tr><td colspan="4" class="vide">Aucune photo pour l\'instant.</td></tr>';
echo '</tbody></table></section>';
page_fin();
