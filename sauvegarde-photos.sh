#!/usr/bin/env bash
# =============================================================================
#  sauvegarde-photos.sh — v2
#
#  Lancé TOUTES LES HEURES par cron. À chaque passage, le script :
#    1. note le dernier envoi reçu de chaque téléphone
#    2. alerte (ntfy) si un téléphone n'a rien envoyé depuis X jours
#    3. alerte si le disque est presque plein
#    4. fait la sauvegarde si c'est le créneau prévu (jour / heure / fréquence)
#    5. supprime les sauvegardes trop anciennes (si activé)
#  Tous les réglages se font dans l'interface web (/etc/sauvegarde-photos/reglages.conf).
#
#  Usage :
#     sauvegarde-photos.sh           vérification horaire (cron)
#     sauvegarde-photos.sh --force   sauvegarde immédiate de tous les téléphones
#     sauvegarde-photos.sh --force Camion1 [Camion2...]   sauvegarde immédiate sélective
# =============================================================================
set -euo pipefail

# ------------------------- CONFIGURATION FIXE --------------------------------
NC_DATA="${NC_DATA:-/var/www/nextcloud-data}"
OCC="${OCC:-runuser -u www-data -- php /var/www/nextcloud/occ}"
DOSSIER_TAMPON="InstantUpload"
DOSSIER_SAUVEGARDES="Sauvegardes-photos"
ETAT_DIR="${ETAT_DIR:-/var/lib/sauvegarde-photos}"
LOG="${LOG:-/var/log/sauvegarde-photos.log}"
REGLAGES="${REGLAGES:-/etc/sauvegarde-photos/reglages.conf}"

# ------------------ RÉGLAGES (valeurs par défaut) ----------------------------
UTILISATEURS="Camion1 Camion2 Camion3"
FREQUENCE="2semaines"      # semaine | 2semaines | mois
JOUR=1                     # 1 = lundi ... 7 = dimanche
JOUR_MOIS=1                # 1 à 28 (fréquence "mois")
HEURE=3                    # 0 à 23
PAUSE=0                    # 1 = toutes les sauvegardes suspendues
PAUSE_CAMIONS=""           # camions en pause, séparés par des espaces
AGE_MIN_MINUTES=10
ALERTE_SILENCE_JOURS=7     # 0 = désactivé
ALERTE_DISQUE_PCT=10       # alerte sous X % d'espace libre, 0 = désactivé
RETENTION_MOIS=0           # 0 = on garde tout
NTFY_URL=""
NTFY_TOKEN=""

# Le fichier de réglages est LU (jamais exécuté) et chaque valeur est vérifiée.
declare -A REGLE=(
    [UTILISATEURS]='^[A-Za-z0-9._@ -]*$'
    [FREQUENCE]='^(semaine|2semaines|mois)$'
    [JOUR]='^[1-7]$'
    [JOUR_MOIS]='^([1-9]|1[0-9]|2[0-8])$'
    [HEURE]='^([0-9]|1[0-9]|2[0-3])$'
    [PAUSE]='^[01]$'
    [PAUSE_CAMIONS]='^[A-Za-z0-9._@ -]*$'
    [AGE_MIN_MINUTES]='^[0-9]{1,4}$'
    [ALERTE_SILENCE_JOURS]='^[0-9]{1,3}$'
    [ALERTE_DISQUE_PCT]='^[0-9]{1,2}$'
    [RETENTION_MOIS]='^[0-9]{1,3}$'
    [NTFY_URL]='^(https?://[^[:space:]"'\''\\]+)?$'
    [NTFY_TOKEN]='^[A-Za-z0-9_-]*$'
)
if [ -r "$REGLAGES" ]; then
    while IFS= read -r ligne || [ -n "$ligne" ]; do
        [[ "$ligne" =~ ^([A-Z_]+)=(.*)$ ]] || continue
        cle="${BASH_REMATCH[1]}"; val="${BASH_REMATCH[2]}"
        [ -n "${REGLE[$cle]+x}" ] || continue
        if [[ "$val" =~ ${REGLE[$cle]} ]]; then
            printf -v "$cle" '%s' "$val"
        else
            echo "Réglage $cle invalide, ignoré" >&2
        fi
    done < "$REGLAGES"
fi
# -----------------------------------------------------------------------------

log() { echo "[$(date '+%d-%m-%Y %H:%M:%S')] $*" | tee -a "$LOG"; }

notifier() {   # notifier "titre" "message" [priorité]
    [ -z "$NTFY_URL" ] && return 0
    local args=(-s -m 20 -H "Title: $1" -H "Priority: ${3:-default}" -d "$2")
    [ -n "$NTFY_TOKEN" ] && args+=(-H "Authorization: Bearer $NTFY_TOKEN")
    curl "${args[@]}" "$NTFY_URL" >/dev/null || log "Attention : notification ntfy non envoyée"
}

en_pause() { [[ " $PAUSE_CAMIONS " == *" $1 "* ]]; }

lire_nombre() { local v; v=$(cat "$1" 2>/dev/null || true); [[ "$v" =~ ^[0-9]+$ ]] && echo "$v" || echo "${2:-0}"; }

nom_libre() {   # nom de fichier libre : photo.jpg, photo_1.jpg, photo_2.jpg...
    local cible="$1" base ext i=1
    [ ! -e "$cible" ] && { echo "$cible"; return; }
    if [[ "$(basename "$cible")" == *.* ]]; then base="${cible%.*}"; ext=".${cible##*.}"; else base="$cible"; ext=""; fi
    while [ -e "${base}_${i}${ext}" ]; do i=$((i + 1)); done
    echo "${base}_${i}${ext}"
}

# Premier créneau de sauvegarde strictement après l'instant $1 (epoch)
prochain_creneau() {
    local seuil=$1 jour d i cand
    [ "$FREQUENCE" = "2semaines" ] && seuil=$((seuil + 7 * 86400))
    jour=$(date -d "@$seuil" +%F)
    for i in $(seq 0 62); do
        d=$(date -d "$jour +$i day" +%F)
        cand=$(date -d "$d $(printf '%02d' "$HEURE"):00" +%s)
        [ "$cand" -gt "$seuil" ] || continue
        if [ "$FREQUENCE" = "mois" ]; then
            [ "$(date -d "$d" +%-d)" -eq "$JOUR_MOIS" ] && { echo "$cand"; return; }
        else
            [ "$(date -d "$d" +%u)" -eq "$JOUR" ] && { echo "$cand"; return; }
        fi
    done
}

# Alerte si le script plante en cours de route
echec() {
    log "ERREUR : le script s'est arrêté (ligne $1)"
    notifier "❌ Sauvegarde photos : échec du script" "Le script s'est arrêté de façon inattendue (ligne $1). Voir $LOG sur le serveur." high
}
trap 'echec $LINENO' ERR

# --- Un seul exemplaire à la fois ---
mkdir -p "$ETAT_DIR/envois" "$(dirname "$LOG")"
exec 9>"$ETAT_DIR/verrou"
flock -n 9 || { echo "Le script tourne déjà, abandon."; exit 0; }

MODE=auto
[ "${1:-}" = "--force" ] && MODE=manuelle
MAINTENANT=$(date +%s)
read -ra LISTE <<< "$UTILISATEURS"

# Sauvegarde sélective : --force Camion1 Camion2 (uniquement des camions de la liste)
CIBLES=()
if [ "$MODE" = manuelle ] && [ $# -gt 1 ]; then
    for c in "${@:2}"; do
        if [[ "$c" =~ ^[A-Za-z0-9._@-]+$ && " ${LISTE[*]} " == *" $c "* ]]; then CIBLES+=("$c")
        else echo "Camion inconnu ignoré : $c" >&2; fi
    done
    [ ${#CIBLES[@]} -gt 0 ] || { echo "Aucun camion valide à sauvegarder." >&2; exit 1; }
fi

# =============================================================================
# 1. Dernier envoi reçu de chaque téléphone (date d'arrivée sur le serveur)
# =============================================================================
for u in "${LISTE[@]}"; do
    f="$ETAT_DIR/envois/$u"
    [ -f "$f.depuis" ] || echo "$MAINTENANT" > "$f.depuis"
    src="$NC_DATA/$u/files/$DOSSIER_TAMPON"
    [ -d "$src" ] || continue
    recu=$(find "$src" -type f ! -name '.*' -printf '%C@\n' 2>/dev/null | sort -n | tail -1 | cut -d. -f1)
    if [ -n "$recu" ] && [ "$recu" -gt "$(lire_nombre "$f")" ]; then echo "$recu" > "$f"; fi
done

# =============================================================================
# 2. Alerte « téléphone silencieux »
# =============================================================================
if [ "$MODE" = auto ] && [ "$PAUSE" = 0 ] && [ "$ALERTE_SILENCE_JOURS" -gt 0 ]; then
    delai=$((ALERTE_SILENCE_JOURS * 86400))
    for u in "${LISTE[@]}"; do
        en_pause "$u" && continue
        f="$ETAT_DIR/envois/$u"
        envoi=$(lire_nombre "$f"); depuis=$(lire_nombre "$f.depuis" "$MAINTENANT"); alerte=$(lire_nombre "$f.alerte")
        ref=$(( envoi > 0 ? envoi : depuis ))
        if (( MAINTENANT - ref > delai && MAINTENANT - alerte > delai )); then
            jours=$(( (MAINTENANT - ref) / 86400 ))
            if [ "$envoi" -gt 0 ]; then detail="Dernier envoi le $(date -d "@$envoi" '+%d-%m-%Y à %H:%M')."
            else detail="Aucun envoi depuis son ajout aux sauvegardes."; fi
            notifier "⚠️ $u : rien reçu depuis $jours jours" "$detail Vérifiez le téléphone : réseau, batterie (Non restreinte), app Nextcloud connectée." high
            log "$u : alerte, aucun envoi depuis $jours jours"
            echo "$MAINTENANT" > "$f.alerte"
        fi
    done
fi

# =============================================================================
# 3. Alerte espace disque
# =============================================================================
if [ "$ALERTE_DISQUE_PCT" -gt 0 ]; then
    libre=$(df -P "$NC_DATA" | awk 'NR==2 { gsub("%", "", $5); print 100 - $5 }')
    if [ "$libre" -lt "$ALERTE_DISQUE_PCT" ] && (( MAINTENANT - $(lire_nombre "$ETAT_DIR/alerte-disque") > 86400 )); then
        reste=$(df -hP "$NC_DATA" | awk 'NR==2 { print $4 }')
        notifier "💾 Disque presque plein" "Plus que $libre % d'espace libre sur le serveur ($reste restants). Les sauvegardes risquent d'échouer." high
        log "Alerte disque : $libre % libre"
        echo "$MAINTENANT" > "$ETAT_DIR/alerte-disque"
    fi
fi

# =============================================================================
# 4. Est-ce le moment de sauvegarder ?
# =============================================================================
if [ "$MODE" = auto ]; then
    [ "$PAUSE" = 1 ] && exit 0
    base=$(lire_nombre "$ETAT_DIR/derniere-planifiee" $((MAINTENANT - 3600)))
    prochain=$(prochain_creneau "$base")
    if [ -z "$prochain" ] || [ "$prochain" -gt "$MAINTENANT" ]; then exit 0; fi
fi

DEBUT=$SECONDS
DATE_DOSSIER=$(date +%d-%m-%Y)
resume=""; erreurs=0; total=0; json_camions=""
declare -A A_SCANNER=()

A_TRAITER=("${LISTE[@]}")
[ ${#CIBLES[@]} -gt 0 ] && A_TRAITER=("${CIBLES[@]}") && MODE=selective
log "=== Sauvegarde $( case $MODE in auto) echo planifiée;; selective) echo "manuelle (${CIBLES[*]})";; *) echo manuelle;; esac ) du $DATE_DOSSIER ==="

for u in "${A_TRAITER[@]}"; do
    src="$NC_DATA/$u/files/$DOSSIER_TAMPON"
    dest="$NC_DATA/$u/files/$DOSSIER_SAUVEGARDES/$DATE_DOSSIER"
    n=0; taille=0

    if en_pause "$u"; then
        etat=pause; log "$u : en pause, ignoré"; resume+="⏸ $u : en pause"$'\n'
    elif [ ! -d "$src" ]; then
        etat=absent; erreurs=$((erreurs + 1))
        log "$u : dossier « $DOSSIER_TAMPON » introuvable (téléphone jamais connecté ?)"
        resume+="❌ $u : aucun dossier d'envoi (téléphone jamais connecté ?)"$'\n'
    else
        while IFS= read -r -d '' f; do
            rel="${f#"$src"/}"
            mkdir -p "$(dirname "$dest/$rel")"
            taille=$((taille + $(stat -c %s "$f")))
            mv -- "$f" "$(nom_libre "$dest/$rel")"
            n=$((n + 1))
        done < <(find "$src" -type f -mmin +"$AGE_MIN_MINUTES" ! -name '.*' -print0)
        find "$src" -mindepth 1 -type d -empty -delete 2>/dev/null || true

        if [ "$n" -gt 0 ]; then
            # Les fichiers doivent appartenir au même propriétaire que le dossier de l'utilisateur
            # (www-data), sinon Nextcloud et l'interface web ne peuvent pas les lire.
            proprio=$(stat -c '%u:%g' "$NC_DATA/$u/files")
            chown -R "$proprio" "$NC_DATA/$u/files/$DOSSIER_SAUVEGARDES"
            chmod -R u+rwX,g+rX "$NC_DATA/$u/files/$DOSSIER_SAUVEGARDES"
            A_SCANNER[$u]=1
            etat=ok; log "$u : $n fichier(s) rangé(s) dans $DOSSIER_SAUVEGARDES/$DATE_DOSSIER"
            resume+="✅ $u : $n photo(s)"$'\n'
        else
            etat=vide; log "$u : aucune nouvelle photo"; resume+="➖ $u : aucune nouvelle photo"$'\n'
        fi
        total=$((total + n))
    fi
    json_camions+="${json_camions:+,}{\"id\":\"$u\",\"n\":$n,\"taille\":$taille,\"etat\":\"$etat\"}"
done

# =============================================================================
# 5. Nettoyage des sauvegardes trop anciennes (sauvegarde planifiée seulement)
# =============================================================================
supprimes=0
if [ "$MODE" = auto ] && [ "$RETENTION_MOIS" -gt 0 ]; then
    limite=$(date -d "-$RETENTION_MOIS months" +%Y%m%d)
    for u in "${LISTE[@]}"; do
        for d in "$NC_DATA/$u/files/$DOSSIER_SAUVEGARDES"/*; do
            nom=$(basename "$d")
            [[ "$nom" =~ ^([0-9]{2})-([0-9]{2})-([0-9]{4})$ ]] && [ -d "$d" ] || continue
            if [ "${BASH_REMATCH[3]}${BASH_REMATCH[2]}${BASH_REMATCH[1]}" -lt "$limite" ]; then
                rm -rf -- "$d"
                supprimes=$((supprimes + 1)); A_SCANNER[$u]=1
                log "$u : sauvegarde $nom supprimée (plus de $RETENTION_MOIS mois)"
            fi
        done
    done
    [ "$supprimes" -gt 0 ] && resume+="🗑 $supprimes ancienne(s) sauvegarde(s) supprimée(s)"$'\n'
fi

# Nextcloud doit "voir" les changements
for u in "${!A_SCANNER[@]}"; do
    if ! $OCC files:scan --path="/$u/files" >/dev/null 2>&1; then
        erreurs=$((erreurs + 1)); log "$u : échec de occ files:scan"
        resume+="⚠️ $u : fichiers rangés mais scan Nextcloud en échec"$'\n'
    fi
done

# =============================================================================
# 6. État, historique, notification
# =============================================================================
date +%s > "$ETAT_DIR/derniere-execution"
[ "$MODE" = auto ] && date +%s > "$ETAT_DIR/derniere-planifiee"
echo "{\"date\":$(date +%s),\"type\":\"$MODE\",\"duree\":$((SECONDS - DEBUT)),\"total\":$total,\"erreurs\":$erreurs,\"supprimes\":$supprimes,\"camions\":[$json_camions]}" \
    >> "$ETAT_DIR/historique.jsonl"
log "Terminé : $total fichier(s), $erreurs erreur(s)"

if [ "$erreurs" -gt 0 ]; then
    notifier "Sauvegarde photos $DATE_DOSSIER : $erreurs erreur(s)" "$resume" high
else
    notifier "Sauvegarde photos $DATE_DOSSIER" "$resume"
fi
exit 0
