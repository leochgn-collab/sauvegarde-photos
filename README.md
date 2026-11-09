# Sauvegarde photos

Sauvegarde automatique des photos de téléphones (Samsung / Android) vers un serveur **Nextcloud**,
avec une **interface web de gestion**.

Chaque téléphone a son compte Nextcloud. L'app Nextcloud du téléphone envoie les photos en continu
dans `InstantUpload/` ; à la date prévue, le script les range dans un dossier daté :

```
<compte>/Sauvegardes-photos/09-11-2026/
<compte>/Sauvegardes-photos/23-11-2026/
```

## Fonctionnalités

- **Planification** : jour, heure et fréquence (chaque semaine, toutes les 2 semaines, chaque mois) ; rattrapage si le serveur était éteint
- **Sauvegarde immédiate** de tous les téléphones ou d'un seul
- **Pause** globale ou par téléphone
- **Alertes ntfy** : téléphone silencieux depuis X jours, disque presque plein, plantage du script ; bouton de test
- **Historique** des sauvegardes et journal détaillé
- **Galerie** + **visionneuse plein écran** (← → / glisser), téléchargement des photos ou d'un dossier en .zip
- **Suppression** d'une sauvegarde (avec confirmation) et **nettoyage automatique** des anciennes (désactivé par défaut)
- **Nom et note** par téléphone (immatriculation, chauffeur…)
- **Comptes** administrateur / lecture seule, connexion avec **double authentification (TOTP)**

## Prérequis

- Debian 12/13 avec **Nextcloud** installé (Apache, PHP 8.2+ avec `gd`, `zip`, `exif`)
- Accès root
- Un reverse proxy HTTPS (ex. Zoraxy) recommandé pour l'accès depuis l'extérieur

## Installation

Sur le serveur Nextcloud, en root :

```bash
apt install -y git
git clone https://github.com/leochgn-collab/sauvegarde-photos.git
cd sauvegarde-photos
bash install.sh            # port 8090 par défaut, ou : bash install.sh 8091
```

L'installation demande à la fin un identifiant, un mot de passe et l'ajout de la double authentification
(QR code). Interface : `http://IP-DU-SERVEUR:8090`.

> Si le dossier de données de Nextcloud n'est pas `/var/www/nextcloud-data`, modifiez `NC_DATA`
> dans `sauvegarde-photos.sh` et `web/lib/config.php` avant de lancer `install.sh`.

## Mise à jour

```bash
cd sauvegarde-photos
git pull
bash install.sh
```

Réglages, comptes, noms des téléphones et historique sont conservés.

## Sur chaque téléphone

1. Installer l'app **Nextcloud** et se connecter avec le compte du téléphone.
2. ☰ → Paramètres → **Envoi automatique** → dossier **Camera** : activer, dossier distant `/InstantUpload`.
3. Paramètres Android → Applications → Nextcloud → Batterie → **Non restreinte**.

## Fichiers installés

| Chemin | Rôle |
|---|---|
| `/usr/local/bin/sauvegarde-photos.sh` | script, lancé toutes les heures par cron |
| `/etc/sauvegarde-photos/reglages.conf` | réglages modifiés par l'interface |
| `/var/lib/sauvegarde-photos/` | état, historique, derniers envois |
| `/var/www/sauvegarde-photos/` | interface web |
| `/var/lib/sauvegarde-photos-web/` | comptes, noms des téléphones, miniatures |
| `/etc/sudoers.d/sauvegarde-photos` | autorise uniquement `sauvegarde-photos.sh --force [téléphones]` |

## Commandes utiles

```bash
sauvegarde-photos.sh --force             # sauvegarde immédiate de tous les téléphones
sauvegarde-photos.sh --force Camion1     # sauvegarde immédiate d'un seul téléphone
runuser -u www-data -- php /var/www/sauvegarde-photos/setup.php   # créer / débloquer un compte admin
```

## Sécurité

- Le fichier de réglages est **lu, jamais exécuté** par le script ; chaque valeur est vérifiée.
- L'interface ne peut lancer que `sauvegarde-photos.sh --force` (règle sudo limitée).
- Exposez l'interface uniquement via HTTPS (reverse proxy) et limitez le port 8090 à l'IP du proxy.
