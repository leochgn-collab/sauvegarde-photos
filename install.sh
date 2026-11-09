#!/usr/bin/env bash
# =============================================================================
#  Installation / mise à jour : script de sauvegarde + interface web de gestion
#  Usage (en root, depuis le dossier décompressé) :   bash install.sh [PORT]
#  PORT par défaut : 8090
# =============================================================================
set -euo pipefail

PORT="${1:-8090}"
ICI="$(cd "$(dirname "$0")" && pwd)"
WEB=/var/www/sauvegarde-photos
SCRIPT=/usr/local/bin/sauvegarde-photos.sh
ETC=/etc/sauvegarde-photos
DATA_WEB=/var/lib/sauvegarde-photos-web
CONF_APACHE=/etc/apache2/sites-available/sauvegarde-photos.conf

vert() { printf '\033[32m%s\033[0m\n' "$*"; }
rouge() { printf '\033[31m%s\033[0m\n' "$*" >&2; }
etape() { printf '\n\033[1m== %s\033[0m\n' "$*"; }

[ "$(id -u)" -eq 0 ] || { rouge "À lancer en root."; exit 1; }
[[ "$PORT" =~ ^[0-9]+$ ]] || { rouge "Port invalide : $PORT"; exit 1; }
command -v apache2ctl >/dev/null || { rouge "Apache introuvable sur ce serveur."; exit 1; }
command -v sudo >/dev/null || { rouge "sudo est nécessaire : apt install sudo"; exit 1; }
for m in gd zip exif; do
    php -m | grep -qix "$m" || rouge "Attention : module PHP '$m' absent (apt install php-$m) — miniatures/zip limités."
done

# Fichiers copiés depuis Windows : on retire les fins de ligne \r
find "$ICI" -type f \( -name '*.sh' -o -name '*.php' -o -name '*.css' \) -exec sed -i 's/\r$//' {} +

# -----------------------------------------------------------------------------
etape "1/6 Réglages partagés ($ETC/reglages.conf)"
# Reprend les valeurs du script déjà installé, s'il y en a un
ancien() {
    grep -oP "^$1=\"\\\$\\{$1:-\\K[^}]*" "$SCRIPT" 2>/dev/null | head -1 || true
}
mkdir -p "$ETC"
if [ ! -f "$ETC/reglages.conf" ]; then
    {
        echo "# Réglages des sauvegardes photos — modifiés par l'interface web"
        echo "# Format : CLE=valeur (sans guillemets)"
        echo "UTILISATEURS=$(v=$(ancien UTILISATEURS); echo "${v:-Camion1 Camion2 Camion3}")"
        echo "AGE_MIN_MINUTES=$(v=$(ancien AGE_MIN_MINUTES); echo "${v:-10}")"
        echo "NTFY_URL=$(ancien NTFY_URL)"
        echo "NTFY_TOKEN=$(ancien NTFY_TOKEN)"
    } > "$ETC/reglages.conf"
    vert "Créé avec : $(grep ^UTILISATEURS "$ETC/reglages.conf")"
else
    vert "Déjà présent, conservé."
fi
chown root:www-data "$ETC" "$ETC/reglages.conf"
chmod 750 "$ETC"
chmod 660 "$ETC/reglages.conf"

# -----------------------------------------------------------------------------
etape "2/6 Script de sauvegarde ($SCRIPT)"
install -m 755 -o root -g root "$ICI/sauvegarde-photos.sh" "$SCRIPT"
mkdir -p /var/lib/sauvegarde-photos && chmod 755 /var/lib/sauvegarde-photos
touch /var/log/sauvegarde-photos.log && chmod 644 /var/log/sauvegarde-photos.log
# v2 : le script est lancé toutes les heures et décide lui-même (planning réglé dans l'interface)
(crontab -l 2>/dev/null | grep -v 'sauvegarde-photos.sh' || true; echo "0 * * * * $SCRIPT") | crontab -
vert "Tâche cron : toutes les heures (le jour et l'heure de sauvegarde se règlent dans l'interface)."
mkdir -p /var/lib/sauvegarde-photos/envois && chmod 755 /var/lib/sauvegarde-photos/envois

# -----------------------------------------------------------------------------
etape "3/6 Interface web ($WEB)"
mkdir -p "$WEB"
rm -rf "$WEB/lib" "$WEB/public"   # mise à jour propre (les données sont ailleurs)
cp -r "$ICI/web/." "$WEB/"
chown -R root:www-data "$WEB"
find "$WEB" -type d -exec chmod 750 {} +
find "$WEB" -type f -exec chmod 640 {} +
mkdir -p "$DATA_WEB"
chown www-data:www-data "$DATA_WEB"
chmod 700 "$DATA_WEB"
vert "Fichiers installés."

# -----------------------------------------------------------------------------
etape "4/6 Autorisation du bouton « Sauvegarder maintenant »"
TMP=$(mktemp)
{
    echo "www-data ALL=(root) NOPASSWD: $SCRIPT --force"
    echo "www-data ALL=(root) NOPASSWD: $SCRIPT --force *"
} > "$TMP"
if visudo -cf "$TMP" >/dev/null; then
    install -m 440 -o root -g root "$TMP" /etc/sudoers.d/sauvegarde-photos
    vert "Règle sudo installée (www-data peut lancer uniquement : $SCRIPT --force [camions])."

else
    rouge "Règle sudo invalide, ignorée : le bouton ne fonctionnera pas."
fi
rm -f "$TMP"

# -----------------------------------------------------------------------------
etape "5/6 Site Apache sur le port $PORT"
if command -v ss >/dev/null && ss -ltn | grep -qE "[:.]$PORT\s" && ! grep -qs "Listen $PORT" "$CONF_APACHE"; then
    rouge "Le port $PORT est déjà utilisé. Relancez avec un autre port : bash install.sh 8091"
    exit 1
fi
cat > "$CONF_APACHE" <<EOF
Listen $PORT
<VirtualHost *:$PORT>
    DocumentRoot $WEB/public
    <Directory $WEB/public>
        Options -Indexes
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/sauvegarde-photos-error.log
    CustomLog \${APACHE_LOG_DIR}/sauvegarde-photos-access.log combined
</VirtualHost>
EOF
a2ensite -q sauvegarde-photos >/dev/null
apache2ctl configtest 2>&1 | grep -v 'Syntax OK' || true
systemctl restart apache2
sleep 1

REPONSE=$(curl -s -m 10 "http://127.0.0.1:$PORT/" || true)
if grep -q '<?php' <<<"$REPONSE"; then
    # PHP non exécuté sur ce site : on active PHP-FPM pour Apache s'il est présent
    FPM=$(ls /etc/apache2/conf-available/php*-fpm.conf 2>/dev/null | head -1 || true)
    if [ -n "$FPM" ]; then
        a2enmod -q proxy_fcgi setenvif >/dev/null
        a2enconf -q "$(basename "$FPM" .conf)" >/dev/null
        systemctl restart apache2; sleep 1
        REPONSE=$(curl -s -m 10 "http://127.0.0.1:$PORT/" || true)
    fi
fi
if grep -q 'Sauvegardes photos' <<<"$REPONSE"; then
    vert "Interface joignable sur le port $PORT."
else
    rouge "L'interface ne répond pas correctement. Voir /var/log/apache2/sauvegarde-photos-error.log"
fi

# -----------------------------------------------------------------------------
etape "6/6 Compte administrateur de l'interface"
command -v qrencode >/dev/null || apt-get install -y -qq qrencode >/dev/null 2>&1 || true   # QR codes de double authentification
if [ -f "$DATA_WEB/comptes.json" ] || [ -f "$DATA_WEB/auth.json" ]; then
    vert "Compte déjà créé (pour le refaire : runuser -u www-data -- php $WEB/setup.php)."
else
    runuser -u www-data -- php "$WEB/setup.php"
fi

IP=$(hostname -I | awk '{print $1}')
echo
vert "Installation terminée. Interface : http://$IP:$PORT"
