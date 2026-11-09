<?php
// Sauvegarde immédiate : tous les camions, ou seulement ceux choisis
exiger_admin();
if (!$post) rediriger();

$choix = array_values(array_intersect((array)($_POST['c'] ?? []), camions()));
[$rc, $sortie] = lancer_sauvegarde($choix);
$_SESSION['derniere_sortie'] = $sortie;
$qui = $choix ? implode(', ', array_map('nom_camion', $choix)) : 'tous les téléphones';
$rc === 0 ? flash("Sauvegarde terminée ($qui).") : flash("La sauvegarde ($qui) a rencontré un problème (code $rc). Détail ci-dessous.", 'erreur');

$retour = (string)($_POST['retour'] ?? '');
rediriger($retour !== '' && in_array($retour, camions(), true) ? ['p' => 'camion', 'c' => $retour] : []);
