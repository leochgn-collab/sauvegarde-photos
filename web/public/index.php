<?php
declare(strict_types=1);
require __DIR__ . '/../lib/app.php';

demarrer_session();
$p = (string)($_GET['p'] ?? '');
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post) verifier_csrf();

if (!connecte()) {
    require __DIR__ . '/../lib/pages/connexion.php';
    exit;
}

if ($p === 'deconnexion') {
    if ($post) { $_SESSION = []; session_regenerate_id(true); }
    rediriger();
}

$page = match ($p) {
    'mini', 'apercu', 'fichier', 'zip' => 'medias',
    'lancer', 'camion', 'dossier', 'photo', 'supprimer', 'reglages', 'comptes', 'moncompte', 'installer' => $p,
    default => 'tableau',
};
require __DIR__ . "/../lib/pages/$page.php";
