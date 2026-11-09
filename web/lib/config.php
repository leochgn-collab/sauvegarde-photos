<?php
// Configuration de l'interface de gestion des sauvegardes photos.
// (Les variables SP_* servent uniquement aux tests.)
declare(strict_types=1);

date_default_timezone_set('Europe/Paris');

define('NC_DATA',            getenv('SP_NC_DATA')  ?: '/var/www/nextcloud-data');
define('NC_OCC',             getenv('SP_NC_OCC')   ?: '/var/www/nextcloud/occ');
define('PHP_CLI',            getenv('SP_PHP')      ?: '/usr/bin/php');
define('DOSSIER_TAMPON',     'InstantUpload');
define('DOSSIER_SAUVEGARDES','Sauvegardes-photos');
// Commande du bouton « Sauvegarder maintenant » (les camions choisis sont ajoutés à la suite)
define('CMD_SAUVEGARDE',     getenv('SP_CMD')      ?: 'sudo -n /usr/local/bin/sauvegarde-photos.sh --force');
define('REGLAGES',           getenv('SP_REGLAGES') ?: '/etc/sauvegarde-photos/reglages.conf');
define('ETAT_DIR',           getenv('SP_ETAT')     ?: '/var/lib/sauvegarde-photos');
define('JOURNAL',            getenv('SP_JOURNAL')  ?: '/var/log/sauvegarde-photos.log');
define('DATA_WEB',           getenv('SP_DATA_WEB') ?: '/var/lib/sauvegarde-photos-web');
define('SESSION_DUREE',      1800);   // déconnexion après 30 min d'inactivité
define('PAR_PAGE',           120);    // photos par page
