<?php
// Envoi des fichiers : miniature, aperçu plein écran, original, zip
$c = (string)($_GET['c'] ?? '');
$d = (string)($_GET['d'] ?? '');
$dir = dossier($c, $d);
if (!$dir) { http_response_code(404); exit('Introuvable'); }
session_write_close();   // ne bloque pas les autres pages pendant l'envoi

if ($p === 'zip') {
    set_time_limit(0);
    ignore_user_abort(true);
    $tmp = tempnam(sys_get_temp_dir(), 'sp-zip');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    foreach (lister_fichiers($dir) as $rel => $_) {
        $nom = "$c/$d/$rel";
        $z->addFile("$dir/$rel", $nom);
        $z->setCompressionName($nom, ZipArchive::CM_STORE);   // photos déjà compressées
    }
    $z->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . "{$c}_{$d}.zip" . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

$f = fichier($dir, (string)($_GET['f'] ?? ''));
if (!$f) { http_response_code(404); exit('Introuvable'); }

if ($p === 'mini' || $p === 'apercu') {
    header('Cache-Control: private, max-age=86400');
    $img = type_fichier($f) === 'image' ? image_reduite($f, $p === 'mini' ? 320 : 1600) : null;
    if ($img) {
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($img));
        readfile($img);
    } else {
        $icone = type_fichier($f) === 'video'
            ? '<path d="M130 95v70l60-35z" fill="#8fa3bf"/>'
            : '<text x="160" y="140" font-family="sans-serif" font-size="28" fill="#8fa3bf" text-anchor="middle">'
              . h(strtoupper(pathinfo($f, PATHINFO_EXTENSION))) . '</text>';
        header('Content-Type: image/svg+xml');
        echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 240"><rect width="320" height="240" fill="#1b2433"/>' . $icone . '</svg>';
    }
    exit;
}

// Fichier original (avec prise en charge des plages pour lire les vidéos)
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f) ?: 'application/octet-stream';
$dl = !empty($_GET['dl']) || !in_array(type_fichier($f), ['image', 'video'], true);
$taille = filesize($f);
$debut = 0;
$fin = $taille - 1;
header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Content-Disposition: ' . ($dl ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', basename($f)) . '"');
if (preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'] ?? '', $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') { $debut = max(0, $taille - (int)$m[2]); }
    else { $debut = (int)$m[1]; if ($m[2] !== '') $fin = min($fin, (int)$m[2]); }
    if ($debut > $fin) { http_response_code(416); header("Content-Range: bytes */$taille"); exit; }
    http_response_code(206);
    header("Content-Range: bytes $debut-$fin/$taille");
}
header('Content-Length: ' . ($fin - $debut + 1));
$fh = fopen($f, 'rb');
fseek($fh, $debut);
$reste = $fin - $debut + 1;
while ($reste > 0 && !feof($fh)) {
    $bloc = fread($fh, min(1048576, $reste));
    echo $bloc;
    $reste -= strlen($bloc);
    flush();
}
fclose($fh);
exit;
