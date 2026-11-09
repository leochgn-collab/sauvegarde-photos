<?php
// Crée un compte administrateur de l'interface, ou réinitialise un compte existant
// (mot de passe + double authentification) — utile si vous êtes bloqué.
// À lancer en www-data :  runuser -u www-data -- php /var/www/sauvegarde-photos/setup.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("En ligne de commande uniquement.\n");
require __DIR__ . '/lib/app.php';

function demander(string $q, bool $cache = false): string
{
    echo $q;
    if ($cache) shell_exec('stty -echo 2>/dev/null');
    $l = fgets(STDIN);
    if ($cache) { shell_exec('stty echo 2>/dev/null'); echo "\n"; }
    if ($l === false) exit("\nAnnulé.\n");
    return trim($l);
}

echo "=== Compte administrateur de l'interface Sauvegardes photos ===\n\n";
@mkdir(DATA_WEB, 0700, true);
$comptes = comptes();
if ($comptes) echo "Comptes existants : " . implode(', ', array_keys($comptes)) . "\n(un identifiant existant sera réinitialisé)\n\n";
do {
    $id = demander('Identifiant : ');
} while (!preg_match('/^[A-Za-z0-9._@-]{2,64}$/', $id) && print("Lettres, chiffres, . _ - @ uniquement.\n"));

while (true) {
    $m1 = demander('Mot de passe (12 caractères minimum) : ', true);
    if (strlen($m1) < 12) { echo "Trop court.\n"; continue; }
    if (demander('Confirmer le mot de passe : ', true) !== $m1) { echo "Les mots de passe ne correspondent pas.\n"; continue; }
    break;
}

$secret = b32_encode(random_bytes(20));
$uri = uri_totp($id, $secret);

echo "\n--- Double authentification ---\n"
   . "Ajoutez ce compte dans votre application (Google Authenticator, Aegis, Microsoft Authenticator...)\n\n";
if (trim((string)shell_exec('command -v qrencode 2>/dev/null')) !== '') {
    passthru('qrencode -t ansiutf8 ' . escapeshellarg($uri));
} else {
    echo "(Astuce : 'apt install qrencode' pour afficher un QR code ici)\n";
}
echo "\nOu saisissez la clé manuellement : " . trim(chunk_split($secret, 4, ' ')) . "\n\n";

while (true) {
    $code = demander('Entrez le code à 6 chiffres affiché par l\'application pour vérifier : ');
    if (totp_verifier($secret, $code, 0)) break;
    echo "Code incorrect, réessayez (vérifiez que l'heure du téléphone et du serveur sont justes).\n";
}

$comptes[$id] = array_merge($comptes[$id] ?? ['cree' => time()], [
    'mdp'         => password_hash($m1, PASSWORD_DEFAULT),
    'totp'        => $secret,
    'dernier_pas' => 0,
    'role'        => 'admin',
]);
enregistrer_comptes($comptes);

echo "\nCompte enregistré. Vous pouvez vous connecter à l'interface.\n";
