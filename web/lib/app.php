<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/totp.php';

const JOURS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
const FREQUENCES = ['semaine' => 'chaque semaine', '2semaines' => 'toutes les 2 semaines', 'mois' => 'chaque mois'];
const REGEX_CAMION = '/^[A-Za-z0-9._@-]{1,64}$/';
const REGEX_DATE   = '/^\d{2}-\d{2}-\d{4}$/';

// ============================================================================
//  Réglages partagés avec le script (/etc/sauvegarde-photos/reglages.conf)
// ============================================================================
const REGLAGES_DEFAUT = [
    'UTILISATEURS'         => 'Camion1 Camion2 Camion3',
    'FREQUENCE'            => '2semaines',
    'JOUR'                 => '1',
    'JOUR_MOIS'            => '1',
    'HEURE'                => '3',
    'PAUSE'                => '0',
    'PAUSE_CAMIONS'        => '',
    'AGE_MIN_MINUTES'      => '10',
    'ALERTE_SILENCE_JOURS' => '7',
    'ALERTE_DISQUE_PCT'    => '10',
    'RETENTION_MOIS'       => '0',
    'NTFY_URL'             => '',
    'NTFY_TOKEN'           => '',
];
// Mêmes règles que dans sauvegarde-photos.sh
const REGLAGES_REGEX = [
    'UTILISATEURS'         => '/^[A-Za-z0-9._@ -]*$/',
    'FREQUENCE'            => '/^(semaine|2semaines|mois)$/',
    'JOUR'                 => '/^[1-7]$/',
    'JOUR_MOIS'            => '/^([1-9]|1[0-9]|2[0-8])$/',
    'HEURE'                => '/^([0-9]|1[0-9]|2[0-3])$/',
    'PAUSE'                => '/^[01]$/',
    'PAUSE_CAMIONS'        => '/^[A-Za-z0-9._@ -]*$/',
    'AGE_MIN_MINUTES'      => '/^[0-9]{1,4}$/',
    'ALERTE_SILENCE_JOURS' => '/^[0-9]{1,3}$/',
    'ALERTE_DISQUE_PCT'    => '/^[0-9]{1,2}$/',
    'RETENTION_MOIS'       => '/^[0-9]{1,3}$/',
    'NTFY_URL'             => '~^(https?://[^\s"\'\\\\]+)?$~',
    'NTFY_TOKEN'           => '/^[A-Za-z0-9_-]*$/',
];

function lire_reglages(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $r = REGLAGES_DEFAUT;
    if (is_readable(REGLAGES)) {
        foreach (file(REGLAGES, FILE_IGNORE_NEW_LINES) as $ligne) {
            if (preg_match('/^([A-Z_]+)=(.*)$/', $ligne, $m)
                && isset(REGLAGES_REGEX[$m[1]]) && preg_match(REGLAGES_REGEX[$m[1]], $m[2])) {
                $r[$m[1]] = $m[2];
            }
        }
    }
    return $cache = $r;
}

/** Enregistre les réglages ; renvoie le nom du premier réglage invalide, ou null si tout va bien. */
function ecrire_reglages(array $r): ?string
{
    $txt = "# Réglages des sauvegardes photos — modifiés par l'interface web\n"
         . "# Format : CLE=valeur (sans guillemets)\n";
    foreach (REGLAGES_DEFAUT as $cle => $_) {
        $v = (string)($r[$cle] ?? '');
        if (!preg_match(REGLAGES_REGEX[$cle], $v)) return $cle;
        $txt .= "$cle=$v\n";
    }
    return file_put_contents(REGLAGES, $txt, LOCK_EX) === false ? 'ECRITURE' : null;
}

function liste_mots(string $s): array
{
    return array_values(array_filter(preg_split('/\s+/', trim($s)), fn($c) => preg_match(REGEX_CAMION, $c)));
}

function camions(?array $r = null): array { return liste_mots(($r ?? lire_reglages())['UTILISATEURS']); }

function en_pause(string $c, ?array $r = null): bool
{
    return in_array($c, liste_mots(($r ?? lire_reglages())['PAUSE_CAMIONS']), true);
}

// --- Nom et note de chaque camion (propre à l'interface) ---
function infos_camions(): array
{
    $f = DATA_WEB . '/camions.json';
    return is_readable($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
}

function enregistrer_infos_camions(array $i): void
{
    file_put_contents(DATA_WEB . '/camions.json', json_encode($i, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function nom_camion(string $c): string
{
    $n = trim((string)(infos_camions()[$c]['nom'] ?? ''));
    return $n !== '' ? $n : $c;
}

// ============================================================================
//  Planification (même calcul que prochain_creneau() dans le script)
// ============================================================================
function prochain_creneau(int $apres, array $r): ?int
{
    $seuil = $apres + ($r['FREQUENCE'] === '2semaines' ? 7 * 86400 : 0);
    $jour = (new DateTimeImmutable('@' . $seuil))->setTimezone(new DateTimeZone(date_default_timezone_get()))->setTime(0, 0);
    for ($i = 0; $i <= 62; $i++) {
        $c = $jour->modify("+$i day")->setTime((int)$r['HEURE'], 0);
        if ($c->getTimestamp() <= $seuil) continue;
        $ok = $r['FREQUENCE'] === 'mois'
            ? (int)$c->format('j') === (int)$r['JOUR_MOIS']
            : (int)$c->format('N') === (int)$r['JOUR'];
        if ($ok) return $c->getTimestamp();
    }
    return null;
}

function lire_epoch(string $f): ?int
{
    $v = @file_get_contents($f);
    return ($v !== false && ctype_digit(trim($v))) ? (int)trim($v) : null;
}

function prochaine_sauvegarde(?array $r = null): ?int
{
    $r ??= lire_reglages();
    if ($r['PAUSE'] === '1') return null;
    return prochain_creneau(lire_epoch(ETAT_DIR . '/derniere-planifiee') ?? time(), $r);
}

function description_planning(array $r): string
{
    $h = (int)$r['HEURE'] . ' h';
    return match ($r['FREQUENCE']) {
        'semaine'   => 'Chaque ' . JOURS[(int)$r['JOUR']] . " à $h",
        '2semaines' => 'Un ' . JOURS[(int)$r['JOUR']] . " sur deux à $h",
        default     => 'Le ' . $r['JOUR_MOIS'] . " de chaque mois à $h",
    };
}

function date_longue(int $t): string
{
    return JOURS[(int)date('N', $t)] . ' ' . date('d-m-Y', $t) . ' à ' . (int)date('G', $t) . ' h';
}

// ============================================================================
//  Fichiers
// ============================================================================
function dossier_camion(string $c): string { return NC_DATA . "/$c/files"; }

/** Chemin d'un dossier de photos ("en-attente" ou JJ-MM-AAAA), ou null. */
function dossier(string $c, string $d): ?string
{
    if (!in_array($c, camions(), true)) return null;
    if ($d === 'en-attente') {
        $p = dossier_camion($c) . '/' . DOSSIER_TAMPON;
    } elseif (preg_match(REGEX_DATE, $d)) {
        $p = dossier_camion($c) . '/' . DOSSIER_SAUVEGARDES . '/' . $d;
    } else {
        return null;
    }
    return is_dir($p) ? $p : null;
}

/** Liste récursive : [chemin relatif => ['taille', 'date', 'recu']], du plus récent au plus ancien. */
function lister_fichiers(string $dir): array
{
    $res = [];
    if (!is_dir($dir)) return $res;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = substr($f->getPathname(), strlen($dir) + 1);
        if (preg_match('~(^|/)\.~', $rel)) continue;   // fichiers cachés
        $res[$rel] = ['taille' => $f->getSize(), 'date' => $f->getMTime(), 'recu' => $f->getCTime()];
    }
    uasort($res, fn($a, $b) => $b['date'] <=> $a['date']);
    return $res;
}

/** Fichier demandé, en vérifiant qu'il reste bien dans le dossier (pas de ../). */
function fichier(string $dir, string $rel): ?string
{
    $base = realpath($dir);
    $p = realpath($dir . '/' . $rel);
    if (!$base || !$p || !is_file($p) || !str_starts_with($p, $base . '/')) return null;
    return $p;
}

function date_tri(string $d): string
{
    [$j, $m, $a] = explode('-', $d);
    return "$a$m$j";
}

/** Sauvegardes datées d'un camion, de la plus récente à la plus ancienne. */
function sauvegardes(string $c): array
{
    $base = dossier_camion($c) . '/' . DOSSIER_SAUVEGARDES;
    $res = [];
    foreach (is_dir($base) ? scandir($base) : [] as $d) {
        if (!preg_match(REGEX_DATE, $d) || !is_dir("$base/$d")) continue;
        $f = lister_fichiers("$base/$d");
        $res[$d] = ['nb' => count($f), 'taille' => array_sum(array_column($f, 'taille'))];
    }
    uksort($res, fn($a, $b) => date_tri($b) <=> date_tri($a));
    return $res;
}

/** Date d'arrivée du dernier envoi (relevée par le script chaque heure). */
function dernier_envoi(string $c, array $attente = []): ?int
{
    $t = lire_epoch(ETAT_DIR . "/envois/$c");
    foreach ($attente as $i) $t = max($t ?? 0, $i['recu']);
    return $t ?: null;
}

function resume_camion(string $c): array
{
    $r = lire_reglages();
    $attente = lister_fichiers(dossier_camion($c) . '/' . DOSSIER_TAMPON);
    $sauv = sauvegardes($c);
    $envoi = dernier_envoi($c, $attente);
    $compte = is_dir(dossier_camion($c));
    $silence = (int)$r['ALERTE_SILENCE_JOURS'];
    $ref = $envoi ?? lire_epoch(ETAT_DIR . "/envois/$c.depuis");

    if (en_pause($c, $r))                                  $etat = ['pause', 'En pause'];
    elseif (!$compte)                                      $etat = ['neutre', 'Jamais connecté'];
    elseif ($silence && $ref && time() - $ref > $silence * 86400) $etat = ['erreur', 'Silencieux'];
    elseif ($envoi || $sauv)                               $etat = ['ok', 'Actif'];
    else                                                   $etat = ['neutre', 'Aucun envoi'];

    return [
        'etat'           => $etat,
        'attente'        => count($attente),
        'attente_taille' => array_sum(array_column($attente, 'taille')),
        'dernier_envoi'  => $envoi,
        'sauvegardes'    => $sauv,
        'derniere_sauv'  => $sauv ? array_key_first($sauv) : null,
        'total_photos'   => array_sum(array_column($sauv, 'nb')),
        'total_taille'   => array_sum(array_column($sauv, 'taille')),
    ];
}

function supprimer_recursif(string $dir): void
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    rmdir($dir);
}

/** Nombre de sauvegardes qui seraient supprimées avec une rétention de $mois mois. */
function a_nettoyer(int $mois): int
{
    if ($mois <= 0) return 0;
    $limite = date('Ymd', strtotime("-$mois months"));
    $n = 0;
    foreach (camions() as $c) {
        foreach (array_keys(sauvegardes($c)) as $d) if (date_tri($d) < $limite) $n++;
    }
    return $n;
}

function type_fichier(string $nom): string
{
    $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) return 'image';
    if (in_array($ext, ['mp4', 'mov', '3gp', 'mkv', 'webm'], true)) return 'video';
    return 'autre';
}

/** Image réduite à $largeur px (miniature 320 ou aperçu plein écran 1600), mise en cache. */
function image_reduite(string $src, int $largeur = 320): ?string
{
    $cle = sha1($src . '|' . filemtime($src) . '|' . filesize($src) . '|' . $largeur);
    $dest = DATA_WEB . '/miniatures/' . substr($cle, 0, 2) . "/$cle.jpg";
    if (is_file($dest)) return $dest;
    @mkdir(dirname($dest), 0700, true);

    $orientation = 1;
    $img = false;
    if (preg_match('/\.jpe?g$/i', $src) && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $orientation = (int)($exif['Orientation'] ?? 1);
        if ($largeur <= 320) {   // miniature intégrée à la photo : bien plus rapide
            $t = @exif_thumbnail($src, $w, $h);
            if ($t && $w >= 240) $img = @imagecreatefromstring($t);
        }
    }
    if (!$img) {
        ini_set('memory_limit', '1024M');
        $data = @file_get_contents($src);
        $img = $data !== false ? @imagecreatefromstring($data) : false;
        unset($data);
    }
    if (!$img) return null;

    $img = match ($orientation) {
        3 => imagerotate($img, 180, 0),
        6 => imagerotate($img, -90, 0),
        8 => imagerotate($img, 90, 0),
        default => $img,
    };
    if (imagesx($img) > $largeur) $img = imagescale($img, $largeur, -1, IMG_BICUBIC);
    if (!$img || !imagejpeg($img, $dest, $largeur > 320 ? 85 : 78)) return null;
    return $dest;
}

function taille_lisible(int|float $o): string
{
    $u = ['o', 'Ko', 'Mo', 'Go', 'To'];
    $i = 0;
    while ($o >= 1024 && $i < 4) { $o /= 1024; $i++; }
    return ($i ? number_format($o, 1, ',', ' ') : (string)$o) . ' ' . $u[$i];
}

function disque(): ?array
{
    $total = @disk_total_space(NC_DATA);
    $libre = @disk_free_space(NC_DATA);
    if (!$total) return null;
    return ['total' => $total, 'libre' => $libre, 'pct_libre' => (int)floor($libre / $total * 100)];
}

/** Lit la fin d'un fichier (journal, historique) sans tout charger. */
function fin_fichier(string $f, int $lignes): array
{
    if (!is_readable($f)) return [];
    $fh = fopen($f, 'r');
    $taille = filesize($f);
    fseek($fh, max(0, $taille - 262144));
    $l = explode("\n", trim((string)stream_get_contents($fh)));
    fclose($fh);
    if ($taille > 262144) array_shift($l);   // première ligne coupée
    return array_slice(array_filter($l, 'strlen'), -$lignes);
}

function historique(int $n = 30): array
{
    $res = [];
    foreach (array_reverse(fin_fichier(ETAT_DIR . '/historique.jsonl', $n)) as $l) {
        $e = json_decode($l, true);
        if (is_array($e)) $res[] = $e;
    }
    return $res;
}

// ============================================================================
//  Notifications, commandes
// ============================================================================
/** Envoie une notification ntfy ; renvoie [ok, message]. */
function ntfy_envoyer(string $titre, string $msg, array $r): array
{
    if ($r['NTFY_URL'] === '') return [false, 'Aucune adresse ntfy réglée.'];
    $entetes = "Title: $titre\r\nContent-Type: text/plain; charset=utf-8\r\n";
    if ($r['NTFY_TOKEN'] !== '') $entetes .= "Authorization: Bearer {$r['NTFY_TOKEN']}\r\n";
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => $entetes, 'content' => $msg, 'timeout' => 10, 'ignore_errors' => true,
    ]]);
    $rep = @file_get_contents($r['NTFY_URL'], false, $ctx);
    $statut = $http_response_header[0] ?? '';
    if (preg_match('~ 2\d\d~', $statut)) return [true, 'Notification envoyée : vérifiez votre téléphone.'];
    if ($statut === '') return [false, 'Serveur ntfy injoignable (' . (error_get_last()['message'] ?? 'erreur réseau') . ').'];
    $detail = json_decode((string)$rep, true)['error'] ?? '';
    return [false, "Refusé par ntfy : $statut" . ($detail ? " — $detail" : '') . (str_contains($statut, '403') || str_contains($statut, '401') ? ' (jeton manquant ou sans droit d\'écriture ?)' : '')];
}

function lancer_sauvegarde(array $camions = []): array
{
    set_time_limit(0);
    $cmd = CMD_SAUVEGARDE;
    foreach ($camions as $c) $cmd .= ' ' . escapeshellarg($c);
    exec($cmd . ' 2>&1', $sortie, $rc);
    return [$rc, implode("\n", $sortie)];
}

function occ(array $args, array $env = []): array
{
    $p = proc_open(array_merge([PHP_CLI, NC_OCC], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        $env + ['PATH' => '/usr/local/bin:/usr/bin:/bin']);
    if (!is_resource($p)) return [1, 'Impossible de lancer occ'];
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), trim($out)];
}

// --- Réglages propres à l'interface (adresse publique de Nextcloud…) ---
function reglages_web(): array
{
    $f = DATA_WEB . '/web.json';
    return is_readable($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
}

function enregistrer_reglages_web(array $r): void
{
    file_put_contents(DATA_WEB . '/web.json', json_encode($r, JSON_PRETTY_PRINT), LOCK_EX);
}

/** Adresse publique de Nextcloud (celle que les téléphones utilisent), ou '' si inconnue. */
function nc_url(): string
{
    $u = (string)(reglages_web()['nc_url'] ?? '');
    if ($u !== '') return $u;
    // Par défaut : overwrite.cli.url de Nextcloud, si elle est en HTTPS
    [$rc, $out] = occ(['config:system:get', 'overwrite.cli.url']);
    $out = rtrim(trim($out), '/');
    return ($rc === 0 && preg_match('~^https://[^\s/]+(/\S*)?$~', $out)) ? $out : '';
}

/** Appareils (mots de passe d'application) connectés à un compte Nextcloud. */
function appareils(string $c): ?array
{
    [$rc, $out] = occ(['user:auth-tokens:list', '--output=json', $c]);
    if ($rc !== 0) return null;
    $l = json_decode($out, true);
    if (!is_array($l)) return null;
    $res = [];
    foreach ($l as $t) {
        $type = $t['type'] ?? '';
        if ($type === 0 || $type === '0' || $type === 'temporary') continue;   // sessions de navigateur
        $act = $t['lastActivity'] ?? $t['last_activity'] ?? null;
        $res[] = [
            'id'    => (string)($t['id'] ?? ''),
            'nom'   => (string)($t['name'] ?? '?'),
            'actif' => is_numeric($act) ? (int)$act : (is_string($act) ? (strtotime($act) ?: null) : null),
        ];
    }
    usort($res, fn($a, $b) => ($b['actif'] ?? 0) <=> ($a['actif'] ?? 0));
    return $res;
}

/** QR code (SVG) pour la double authentification, si qrencode est installé. */
function qr_svg(string $texte): ?string
{
    $p = @proc_open(['qrencode', '-t', 'SVG', '-m', '2', '-o', '-', $texte], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return null;
    $svg = stream_get_contents($pipes[1]);
    fclose($pipes[1]); fclose($pipes[2]);
    return proc_close($p) === 0 && str_contains($svg, '<svg') ? 'data:image/svg+xml;base64,' . base64_encode($svg) : null;
}

function uri_totp(string $id, string $secret): string
{
    return 'otpauth://totp/' . rawurlencode('Sauvegardes photos:' . $id)
         . '?secret=' . $secret . '&issuer=' . rawurlencode('Sauvegardes photos');
}

// ============================================================================
//  Comptes de l'interface (admin / lecture)
// ============================================================================
function comptes(): array
{
    $f = DATA_WEB . '/comptes.json';
    if (is_readable($f)) return json_decode((string)file_get_contents($f), true) ?: [];
    // Migration depuis la v1 (un seul compte dans auth.json)
    $ancien = DATA_WEB . '/auth.json';
    if (is_readable($ancien)) {
        $a = json_decode((string)file_get_contents($ancien), true);
        if (!empty($a['identifiant'])) {
            $c = [$a['identifiant'] => ['mdp' => $a['mdp'], 'totp' => $a['totp'],
                  'dernier_pas' => (int)($a['dernier_pas'] ?? 0), 'role' => 'admin']];
            enregistrer_comptes($c);
            @unlink($ancien);
            return $c;
        }
    }
    return [];
}

function enregistrer_comptes(array $c): void
{
    $f = DATA_WEB . '/comptes.json';
    file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT), LOCK_EX);
    @chmod($f, 0600);
}

function moi(): ?string { return $_SESSION['compte'] ?? null; }

function est_admin(): bool { return (comptes()[moi()]['role'] ?? '') === 'admin'; }

function exiger_admin(): void
{
    if (!est_admin()) {
        http_response_code(403);
        page_debut('Accès refusé');
        echo '<div class="carte"><h1>Accès refusé</h1><p>Votre compte est en lecture seule.</p><a class="bouton" href="./">Retour</a></div>';
        page_fin();
        exit;
    }
}

// ============================================================================
//  Session, sécurité
// ============================================================================
function est_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function demarrer_session(): void
{
    session_name('sp_session');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true,
        'samesite' => 'Strict', 'secure' => est_https(),
    ]);
    session_start();
    $expire = time() - ($_SESSION['vu'] ?? 0) > SESSION_DUREE;
    if (!empty($_SESSION['compte']) && ($expire || !isset(comptes()[$_SESSION['compte']]))) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['vu'] = time();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; media-src 'self'; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'");
}

function connecte(): bool { return moi() !== null; }

function csrf_champ(): string
{
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function verifier_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Requête invalide (jeton CSRF). Rechargez la page.');
    }
}

function ip_client(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    // Derrière Zoraxy, la vraie IP est la dernière ajoutée dans X-Forwarded-For
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
        && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $l = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        $ip = end($l);
    }
    return $ip;
}

/** Anti force brute : 5 échecs en 15 min → blocage 15 min. */
function tentatives(string $ip, bool $echec = false): int
{
    $f = DATA_WEB . '/tentatives.json';
    $t = is_readable($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $limite = time() - 900;
    foreach ($t as $k => $liste) {
        $t[$k] = array_values(array_filter($liste, fn($x) => $x > $limite));
        if (!$t[$k]) unset($t[$k]);
    }
    if ($echec) $t[$ip][] = time();
    file_put_contents($f, json_encode($t), LOCK_EX);
    return count($t[$ip] ?? []);
}

// ============================================================================
//  Affichage
// ============================================================================
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function url(array $p): string { return '?' . http_build_query($p); }

function flash(?string $msg = null, string $type = 'ok'): array
{
    if ($msg !== null) { $_SESSION['flash'][] = [$type, $msg]; return []; }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function rediriger(array $p = []): never
{
    header('Location: ' . ($p ? url($p) : './'));
    exit;
}

function page_debut(string $titre, string $actif = '', string $classe = ''): void
{
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . h($titre) . ' · Sauvegardes photos</title>'
       . '<link rel="stylesheet" href="style.css"></head><body' . ($classe ? ' class="' . h($classe) . '"' : '') . '>';
    if (connecte() && $classe !== 'visionneuse') {
        echo '<header class="barre"><a class="logo" href="./"><span class="pastille"></span>Sauvegardes photos</a><nav>';
        $liens = ['' => 'Tableau de bord'];
        if (est_admin()) $liens += ['reglages' => 'Réglages', 'comptes' => 'Comptes'];
        $liens['moncompte'] = h(moi());
        foreach ($liens as $p => $lib) {
            $cls = $actif === $p ? ' class="actif"' : '';
            echo '<a' . $cls . ' href="' . ($p ? url(['p' => $p]) : './') . '">' . $lib . '</a>';
        }
        echo '<form method="post" action="' . url(['p' => 'deconnexion']) . '">' . csrf_champ()
           . '<button class="lien">Déconnexion</button></form></nav></header>';
    }
    echo '<main>';
    foreach (flash() as [$type, $msg]) {
        echo '<div class="flash ' . h($type) . '">' . h($msg) . '</div>';
    }
}

function page_fin(): void { echo '</main></body></html>'; }

/** Bloc QR code + clé à afficher après création / réinitialisation d'un compte. */
function bloc_totp(string $id, string $secret): string
{
    $uri = uri_totp($id, $secret);
    $qr = qr_svg($uri);
    return '<div class="carte totp"><h2>Double authentification de « ' . h($id) . ' »</h2>'
         . '<p>À scanner <b>maintenant</b> avec l\'application d\'authentification (Google Authenticator, Aegis…). '
         . 'Cette clé ne sera plus affichée.</p>'
         . ($qr ? '<img class="qr" src="' . $qr . '" alt="QR code">' : '')
         . '<p>Clé à saisir manuellement :</p><pre class="cle">' . h(trim(chunk_split($secret, 4, ' '))) . '</pre></div>';
}
