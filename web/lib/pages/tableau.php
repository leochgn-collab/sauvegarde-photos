<?php
// Tableau de bord
$r = lire_reglages();
$admin = est_admin();
$derniere = lire_epoch(ETAT_DIR . '/derniere-execution');
$prochaine = prochaine_sauvegarde($r);
$disque = disque();
$infos = infos_camions();

page_debut('Tableau de bord', '');
echo '<div class="entete"><h1>Tableau de bord</h1>';
if ($admin) {
    echo '<form method="post" action="' . url(['p' => 'lancer']) . '">' . csrf_champ()
       . '<button class="bouton">Sauvegarder tous les téléphones</button></form>';
}
echo '</div>';

// --- Cartes d'état ---
if ($r['PAUSE'] === '1') {
    $prochTxt = '<b class="alerte-txt">En pause</b><small>toutes les sauvegardes sont suspendues</small>';
} elseif ($prochaine === null) {
    $prochTxt = '<b>—</b><small>planning invalide</small>';
} elseif ($prochaine <= time()) {
    $prochTxt = '<b>Imminente</b><small>au prochain passage (moins d\'1 h)</small>';
} else {
    $prochTxt = '<b>' . date('d-m-Y', $prochaine) . '</b><small>' . h(JOURS[(int)date('N', $prochaine)]) . ' à '
              . (int)date('G', $prochaine) . ' h · ' . h(FREQUENCES[$r['FREQUENCE']]) . '</small>';
}
echo '<div class="grille stats">'
   . '<div class="carte stat"><span>Prochaine sauvegarde</span>' . $prochTxt . '</div>'
   . '<div class="carte stat"><span>Dernière sauvegarde</span><b>' . ($derniere ? date('d-m-Y', $derniere) : '—') . '</b><small>'
   . ($derniere ? 'à ' . date('H:i', $derniere) : 'jamais lancée') . '</small></div>';
if ($disque) {
    $bas = (int)$r['ALERTE_DISQUE_PCT'] > 0 && $disque['pct_libre'] < (int)$r['ALERTE_DISQUE_PCT'];
    echo '<div class="carte stat"><span>Espace disque libre</span><b' . ($bas ? ' class="alerte-txt"' : '') . '>'
       . $disque['pct_libre'] . ' %</b><meter min="0" max="100" low="15" optimum="100" value="' . $disque['pct_libre'] . '"></meter><small>'
       . taille_lisible($disque['libre']) . ' libres sur ' . taille_lisible($disque['total']) . '</small></div>';
}
echo '<div class="carte stat"><span>Notifications</span><b>' . ($r['NTFY_URL'] ? 'ntfy' : 'aucune') . '</b><small>'
   . ($r['NTFY_URL'] ? h(parse_url($r['NTFY_URL'], PHP_URL_HOST)) : 'à régler dans Réglages') . '</small></div></div>';

// --- Téléphones ---
echo '<h2>Téléphones</h2><div class="grille camions">';
foreach (camions($r) as $c) {
    $s = resume_camion($c);
    $nom = nom_camion($c);
    $note = trim((string)($infos[$c]['note'] ?? ''));
    echo '<div class="carte camion"><div class="ligne"><div><h3><a href="' . url(['p' => 'camion', 'c' => $c]) . '">' . h($nom) . '</a></h3>'
       . ($nom !== $c ? '<small class="id">' . h($c) . '</small>' : '') . '</div>'
       . '<span class="etat ' . $s['etat'][0] . '">' . $s['etat'][1] . '</span></div>'
       . ($note !== '' ? '<p class="note">' . h($note) . '</p>' : '')
       . '<dl><dt>Dernier envoi</dt><dd>' . ($s['dernier_envoi'] ? date('d-m-Y H:i', $s['dernier_envoi']) : '—') . '</dd>'
       . '<dt>En attente</dt><dd>' . $s['attente'] . ' fichier' . ($s['attente'] > 1 ? 's' : '') . '</dd>'
       . '<dt>Dernière sauvegarde</dt><dd>' . h($s['derniere_sauv'] ?? '—') . '</dd>'
       . '<dt>Total sauvegardé</dt><dd>' . $s['total_photos'] . ' · ' . taille_lisible($s['total_taille']) . '</dd></dl>'
       . '<div class="pied"><a class="petit" href="' . url(['p' => 'camion', 'c' => $c]) . '">Voir les photos</a>';
    if ($admin && !en_pause($c, $r)) {
        echo '<form method="post" action="' . url(['p' => 'lancer']) . '">' . csrf_champ()
           . '<input type="hidden" name="c[]" value="' . h($c) . '"><button class="petit">Sauvegarder maintenant</button></form>';
    }
    echo '</div></div>';
}
if (!camions($r)) echo '<p class="vide">Aucun téléphone. Ajoutez-en dans <a href="' . url(['p' => 'reglages']) . '">Réglages</a>.</p>';
echo '</div>';

if (!empty($_SESSION['derniere_sortie'])) {
    echo '<h2>Résultat de la sauvegarde manuelle</h2><pre class="journal">' . h($_SESSION['derniere_sortie']) . '</pre>';
    unset($_SESSION['derniere_sortie']);
}

// --- Historique ---
$etats = ['ok' => '✅', 'vide' => '➖', 'pause' => '⏸', 'absent' => '❌'];
$types = ['auto' => 'Planifiée', 'manuelle' => 'Manuelle', 'selective' => 'Manuelle (sélection)'];
echo '<h2>Historique des sauvegardes</h2><section class="carte tableau-defile"><table class="historique"><thead><tr>'
   . '<th>Date</th><th>Type</th><th>Téléphones</th><th>Photos</th><th>Durée</th><th>Résultat</th></tr></thead><tbody>';
$hist = historique(30);
foreach ($hist as $e) {
    $det = [];
    foreach ($e['camions'] ?? [] as $cam) {
        $det[] = '<span class="puce ' . h($cam['etat']) . '" title="' . h($cam['etat']) . '">' . ($etats[$cam['etat']] ?? '?') . ' '
               . h(nom_camion((string)$cam['id'])) . ($cam['n'] ? ' : ' . (int)$cam['n'] : '') . '</span>';
    }
    $res = ($e['erreurs'] ?? 0) ? '<span class="etat erreur">' . (int)$e['erreurs'] . ' erreur(s)</span>' : '<span class="etat ok">OK</span>';
    if (!empty($e['supprimes'])) $res .= ' <small>' . (int)$e['supprimes'] . ' anc. supprimée(s)</small>';
    echo '<tr><td>' . date('d-m-Y H:i', (int)$e['date']) . '</td><td>' . h($types[$e['type']] ?? $e['type']) . '</td>'
       . '<td class="puces">' . implode(' ', $det) . '</td><td>' . (int)($e['total'] ?? 0) . '</td><td>' . (int)($e['duree'] ?? 0) . ' s</td><td>' . $res . '</td></tr>';
}
if (!$hist) echo '<tr><td colspan="6" class="vide">Aucune sauvegarde pour l\'instant.</td></tr>';
echo '</tbody></table></section>';

echo '<details class="journal-details"><summary>Journal détaillé</summary><pre class="journal">'
   . (h(implode("\n", fin_fichier(JOURNAL, 60))) ?: 'Journal vide.') . '</pre></details>';
page_fin();
