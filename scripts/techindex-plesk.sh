#!/usr/bin/env bash
# =============================================================================
#  TechIndex – déploiement et exploitation sur un hébergement Plesk (sans Docker)
# =============================================================================
#  À lancer en SSH avec l'utilisateur système de l'abonnement Plesk, jamais en
#  root ni avec sudo : PHP s'exécute sous cet utilisateur, qui doit posséder
#  tous les fichiers. Le script est placé dans le dossier de déploiement, HORS
#  de la racine web (par exemple ~/techindex). Voir docs/deploiement-plesk.md.
#
#  Usage : ./techindex-plesk.sh <commande> [arguments]
#
#  check                 Vérifie l'hébergement (PHP, extensions, outils, base)
#  check-update          Recherche une nouvelle version publiée (signature vérifiée)
#  install [SOURCE]      Première installation
#  update [SOURCE]       Sauvegarde, nouvelle version, migrations, remise en service
#                        SOURCE : rien (dernière version publiée), --version X.Y.Z,
#                        ou archive techindex-plesk-<version>.tar.gz (déploiement forcé)
#  rollback              Revient à la version précédente (voir docs/mise-a-jour.md)
#  status                Version en service, versions conservées, maintenance
#  reload                Prend en compte une modification de shared/.env
#  down | up             Mise en maintenance / remise en service
#  logs [-f]             Journal de l'application
#  backup                Sauvegarde (base, pièces jointes, paramètres – sans les clés)
#  verify-backup F       Test de restauration (BACKUP_VERIFY_DATABASE)  [--identity fichier]
#  restore F             Restauration (remplace les données)            [--identity fichier]
#  backup-keygen         Génère une paire de clés age (clé privée à sortir du serveur)
#  keys-export F         Copie APP_KEY / SECRETS_KEY dans F (à stocker hors du serveur)
#  rotate-secrets-key    Nouvelle clé des secrets et rechiffrement (sauvegarde préalable)
#  create-admin | doctor | mail-test ADRESSE | artisan ...
#
#  Arborescence du dossier de déploiement :
#    app/        version en service ; racine du document Plesk : <dossier>/app/public
#    shared/     .env, storage/ (pièces jointes, journaux) et backups/ : conservés
#    releases/   versions précédentes, pour le retour arrière
#    archives/   archives téléchargées ou reçues (techindex.ps1 plesk-deploy)
# =============================================================================
set -Eeuo pipefail
umask 022

ROOT="${TECHINDEX_HOME:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)}"
ROOT="${ROOT%/}"
APP_DIR="$ROOT/app"
SHARED_DIR="$ROOT/shared"
ENV_FILE="$SHARED_DIR/.env"
RELEASES_DIR="$ROOT/releases"
ARCHIVES_DIR="$ROOT/archives"
STATE_DIR="$ROOT/.deploy"
LOCK_DIR="$STATE_DIR/lock"
INCOMING_DIR="$ROOT/.incoming"
DOWNLOAD_DIR="$ROOT/.download"
# Avancement lu par l'administration (Mises à jour) et journaux des mises à jour.
UPDATES_DIR="$SHARED_DIR/storage/app/private/updates"
KEEP_RELEASES="${TECHINDEX_KEEP_RELEASES:-2}"
KEEP_ARCHIVES=3
# Délai minimal en maintenance après un changement de version : l'OPcache de
# PHP-FPM ne revérifie un fichier déjà compilé qu'après opcache.revalidate_freq
# secondes (2 par défaut). À augmenter si l'hébergeur a relevé cette valeur.
SWITCH_DELAY="${TECHINDEX_SWITCH_DELAY:-5}"

# Canal officiel des versions, intégré lors de la construction de l'archive
# (config/release-channel.json) : sert à la première installation. Ensuite,
# c'est la configuration de la version installée qui fait foi.
RELEASE_REPOSITORY_BUILTIN="@RELEASE_REPOSITORY@"
RELEASE_PUBLIC_KEY_BUILTIN="@RELEASE_PUBLIC_KEY@"

PHP=""
STAGE=""
PROBLEMS=0
ARCHIVE_VERSION=""
PARKED_RELEASE=""
LOCKED=""
STATUS_ACTIVE=""
STATUS_VERSION=""
STATUS_FROM=""
STATUS_STEP=""
STATUS_LOG=""
STATUS_STARTED=""
STATUS_SOURCE="${TECHINDEX_UPDATE_SOURCE:-ssh}"
[[ "$STATUS_SOURCE" =~ ^[a-z]+$ ]] || STATUS_SOURCE=ssh
STATUS_REQUESTED_BY="${TECHINDEX_UPDATE_REQUESTED_BY:-}"
[[ "$STATUS_REQUESTED_BY" =~ ^[0-9]+$ ]] || STATUS_REQUESTED_BY=null
RELEASE_REPOSITORY=""
RELEASE_PUBLIC_KEY=""
RELEASE_BASE_URL=""
REL_VERSION=""
REL_TAG=""
REL_ARCHIVE=""
REL_SHA256=""
REL_MIGRATIONS=""
SOURCE_ARCHIVE=""
SOURCE_VERSION="latest"

# --- Affichage -----------------------------------------------------------------
info() {
    printf '\n\033[36m==> %s\033[0m\n' "$*"
    if [ -n "$STATUS_ACTIVE" ]; then
        STATUS_STEP="$*"
        status_write running ""
    fi
}
ok()      { printf '    \033[32m[OK]\033[0m %s\n' "$*"; }
warn()    { printf '    \033[33m[!]\033[0m  %s\n' "$*"; }
problem() { printf '    \033[31m[X]\033[0m  %s\n' "$*"; PROBLEMS=$((PROBLEMS + 1)); }

# Code 3 : message déjà affiché (le piège ERR du shell principal n'en ajoute pas).
fail() {
    printf '\n\033[31mERREUR : %s\033[0m\n' "$*" >&2
    if [ "$BASH_SUBSHELL" -eq 0 ]; then
        recover
        status_finish failed "$*"
    fi
    exit 3
}

# Remet le site dans un état cohérent après un échec en cours de mise à jour.
RECOVERED=""
recover() {
    local stage="$STAGE"
    STAGE=""
    RECOVERED="$stage"
    case "$stage" in
        staging)
            rm_tree "$INCOMING_DIR"
            ;;
        maintenance)
            rm_tree "$INCOMING_DIR"
            artisan up > /dev/null 2>&1 || true
            warn "Opération annulée : la version en service n'a pas changé et le site est de nouveau accessible."
            ;;
        switched)
            warn "La nouvelle version est en place mais une étape a échoué : le site reste en maintenance."
            warn "Après correction : ./techindex-plesk.sh up    ou retour arrière : ./techindex-plesk.sh rollback"
            if [ -n "$(state_get pre-update-backup)" ]; then
                warn "Si la base a été modifiée : ./techindex-plesk.sh restore $(state_get pre-update-backup)"
            fi
            ;;
    esac
}

on_error() {
    local status=$?
    # Dans un sous-shell ($(...), ( ... )), l'erreur est traitée par le shell principal.
    [ "$BASH_SUBSHELL" -eq 0 ] || exit "$status"
    trap - ERR
    if [ "$status" -ne 3 ]; then
        printf '\n\033[31mERREUR : étape interrompue (code %s, ligne %s du script).\033[0m\n' "$status" "$1" >&2
    fi
    recover
    status_finish failed "Échec pendant l'étape « ${STATUS_STEP:-préparation} » (code $status) : consultez le journal."
    exit "$status"
}
trap 'on_error $LINENO' ERR
trap 'printf "\n"; fail "Interrompu."' INT TERM
trap 'release_lock' EXIT

# --- Fichier .env (shared/.env) ---------------------------------------------------
env_get() {
    [ -f "$ENV_FILE" ] || return 0
    local line
    line="$(grep -E "^$1=" "$ENV_FILE" | tail -n1 || true)"
    line="${line#*=}"
    line="${line%$'\r'}"
    case "$line" in
        \"*\") line="${line:1:${#line}-2}" ;;
        \'*\') line="${line:1:${#line}-2}" ;;
    esac
    printf '%s' "$line"
}

# Remplace (ou ajoute) une valeur ; écriture atomique, droits 600.
env_set() {
    local key="$1" value="$2" tmp
    tmp="$(mktemp "$SHARED_DIR/.env.XXXXXX")"
    if grep -qE "^$key=" "$ENV_FILE"; then
        K="$key" V="$value" awk 'BEGIN { k = ENVIRON["K"]; v = ENVIRON["V"] } index($0, k "=") == 1 { print k "=" v; next } { print }' "$ENV_FILE" > "$tmp"
    else
        cat "$ENV_FILE" > "$tmp"
        [ -z "$(tail -c1 "$tmp")" ] || printf '\n' >> "$tmp"
        printf '%s=%s\n' "$key" "$value" >> "$tmp"
    fi
    chmod 600 "$tmp"
    mv -f "$tmp" "$ENV_FILE"
}

state_get() { cat "$STATE_DIR/$1" 2> /dev/null || true; }
state_set() { printf '%s\n' "$2" > "$STATE_DIR/$1"; }

# --- Verrou : une seule opération à la fois (mise à jour, retour arrière, restauration)
acquire_lock() {
    local pid
    mkdir -p "$STATE_DIR"
    if ! mkdir "$LOCK_DIR" 2> /dev/null; then
        pid="$(cat "$LOCK_DIR/pid" 2> /dev/null || true)"
        if [[ "$pid" =~ ^[0-9]+$ ]] && kill -0 "$pid" 2> /dev/null; then
            [ "$STATUS_SOURCE" != interface ] || { STATUS_ACTIVE=1; STATUS_STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"; }
            fail "Une autre opération est en cours (processus $pid) : réessayez lorsqu'elle sera terminée."
        fi
        # Verrou abandonné par une opération interrompue.
        rm -rf "$LOCK_DIR"
        mkdir "$LOCK_DIR" || fail "Verrou impossible : $LOCK_DIR"
    fi
    printf '%s\n' "$$" > "$LOCK_DIR/pid"
    LOCKED=1
}

release_lock() {
    if [ -n "$LOCKED" ]; then rm -rf "$LOCK_DIR"; fi
}

# --- Avancement d'une mise à jour (updates/status.json, lu par l'administration)
json_escape() {
    local value="$1"
    value="${value//\\/\\\\}"
    value="${value//\"/\\\"}"
    value="${value//[$'\n\r\t']/ }"
    printf '%s' "$value"
}

status_write() {  # état, message
    [ -n "$STATUS_ACTIVE" ] || return 0
    local tmp finished=""
    mkdir -p "$UPDATES_DIR" 2> /dev/null || return 0
    tmp="$(mktemp "$UPDATES_DIR/.status.XXXXXX" 2> /dev/null)" || return 0
    case "$1" in succeeded | failed) finished="$(date -u +%Y-%m-%dT%H:%M:%SZ)" ;; esac
    printf '{"state":"%s","version":"%s","from":"%s","step":"%s","message":"%s","source":"%s","log":"%s","started_at":"%s","finished_at":"%s","requested_by_id":%s,"pid":%s}\n' \
        "$1" "$(json_escape "$STATUS_VERSION")" "$(json_escape "$STATUS_FROM")" "$(json_escape "$STATUS_STEP")" "$(json_escape "$2")" \
        "$STATUS_SOURCE" "$STATUS_LOG" "$STATUS_STARTED" "$finished" "$STATUS_REQUESTED_BY" "$$" > "$tmp"
    mv -f "$tmp" "$UPDATES_DIR/status.json"
}

# Début du suivi : la sortie est aussi copiée dans updates/update-<date>.log.
status_begin() {
    STATUS_ACTIVE=1
    STATUS_FROM="$(cat "$APP_DIR/VERSION" 2> /dev/null || true)"
    STATUS_STARTED="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    STATUS_LOG="update-$(date +%Y%m%d-%H%M%S).log"
    mkdir -p "$UPDATES_DIR"
    find "$UPDATES_DIR" -maxdepth 1 -name 'update-*.log' -type f -printf '%f\n' | sort -r | tail -n +10 \
        | while IFS= read -r old; do rm -f "$UPDATES_DIR/$old"; done
    exec > >(tee -a "$UPDATES_DIR/$STATUS_LOG") 2>&1
    status_write running ""
}

status_finish() {  # état, message
    [ -n "$STATUS_ACTIVE" ] || return 0
    local message="$2"
    case "$RECOVERED" in
        switched) message="$message Le site reste en maintenance : intervention en SSH nécessaire (« up » ou « rollback »)." ;;
        staging | maintenance) message="$message La version en service n'a pas changé." ;;
    esac
    status_write "$1" "$message"
    STATUS_ACTIVE=""
}

# --- Saisie ------------------------------------------------------------------------
ask() {
    local answer=""
    read -r -p "    $1${2:+ [$2]} : " answer || true
    printf '%s' "${answer:-${2:-}}"
}

ask_identifier() {
    local value
    value="$(ask "$1" "${2:-}")"
    [[ "$value" =~ ^[A-Za-z0-9_.@-]+$ ]] || fail "Valeur absente ou invalide : $1"
    printf '%s' "$value"
}

ask_secret() {
    local value=""
    read -r -s -p "    $1 : " value || true
    printf '\n' >&2
    [ -n "$value" ] || fail "Valeur requise : $1"
    printf '%s' "$value"
}

# --- Fichiers ------------------------------------------------------------------------
absolute_path() {
    local dir
    dir="$(cd "$(dirname "$1")" 2> /dev/null && pwd -P)" || fail "Dossier introuvable : $(dirname "$1")"
    printf '%s/%s' "$dir" "$(basename "$1")"
}

# Suppression limitée au dossier de déploiement (le code livré est en lecture seule).
rm_tree() {
    local target="$1"
    [ -n "$target" ] || return 0
    case "$target" in
        "$ROOT"/?*) ;;
        *) fail "Suppression refusée hors du dossier de déploiement : $target" ;;
    esac
    if [ -L "$target" ]; then rm -f "$target"; return 0; fi
    [ -e "$target" ] || return 0
    chmod -R u+w "$target" 2> /dev/null || true
    rm -rf "$target"
}

latest_backup() {
    find "$SHARED_DIR/backups" -maxdepth 1 -type f -name 'techindex-backup-*' -printf '%f\n' 2> /dev/null | sort | tail -n1 || true
}

# Archive de sauvegarde : chemin existant, ou nom d'un fichier de shared/backups.
backup_file() {
    if [ -f "$1" ]; then absolute_path "$1"; return 0; fi
    [ -f "$SHARED_DIR/backups/$(basename "$1")" ] || fail "Archive introuvable : $1 (ni chemin valide, ni fichier de $SHARED_DIR/backups)"
    printf '%s' "$SHARED_DIR/backups/$(basename "$1")"
}

# Options transmises à Artisan : chemins de --identity rendus absolus.
IDENTITY_ARGS=()
parse_identity() {
    IDENTITY_ARGS=()
    while [ $# -gt 0 ]; do
        case "$1" in
            --identity=*) IDENTITY_ARGS+=("--identity=$(absolute_path "${1#--identity=}")") ;;
            --identity)
                [ $# -ge 2 ] || fail "--identity : indiquez le fichier de clé privée age."
                IDENTITY_ARGS+=("--identity=$(absolute_path "$2")")
                shift
                ;;
            *) IDENTITY_ARGS+=("$1") ;;
        esac
        shift
    done
}

# --- PHP et Artisan ---------------------------------------------------------------------
php_ok() {
    [ -n "$1" ] && [ -x "$1" ] && "$1" -r 'exit(PHP_VERSION_ID >= 80401 ? 0 : 1);' > /dev/null 2>&1
}

# Interpréteur PHP en ligne de commande : TECHINDEX_PHP (environnement puis .env),
# sinon la version Plesk la plus récente (/opt/plesk/php/<version>/bin/php).
find_php() {
    [ -z "$PHP" ] || return 0
    local candidate
    if [ -n "${TECHINDEX_PHP:-}" ]; then
        php_ok "$TECHINDEX_PHP" || fail "TECHINDEX_PHP=$TECHINDEX_PHP : PHP 8.4.1 ou plus récent introuvable à cet emplacement."
        PHP="$TECHINDEX_PHP"
        return 0
    fi
    candidate="$(env_get TECHINDEX_PHP)"
    if php_ok "$candidate"; then PHP="$candidate"; return 0; fi
    [ -z "$candidate" ] || warn "TECHINDEX_PHP=$candidate (shared/.env) inutilisable : recherche d'une autre version."
    while IFS= read -r candidate; do
        if php_ok "$candidate"; then PHP="$candidate"; return 0; fi
    done < <(printf '%s\n' /opt/plesk/php/*/bin/php | sort -rV; command -v php || true)
    fail "Aucun PHP 8.4.1 ou plus récent trouvé. Installez PHP 8.5 dans Plesk (Outils et paramètres > Mises à jour), ou indiquez-le : TECHINDEX_PHP=/chemin/vers/php ./techindex-plesk.sh ..."
}

artisan() { (cd "$APP_DIR" && "$PHP" artisan "$@"); }

# Sortie brute d'une commande Artisan (dernière ligne, sans couleurs).
artisan_value() { (cd "$APP_DIR" && "$PHP" artisan "$@" --no-ansi --no-interaction) | tr -d '\r' | tail -n1; }

assert_installed() {
    [ -f "$APP_DIR/artisan" ] && [ -f "$ENV_FILE" ] || fail "TechIndex n'est pas installé dans $ROOT : ./techindex-plesk.sh install"
    find_php
}

# --- Vérifications -------------------------------------------------------------------------
check_location() {
    if [ -z "${TECHINDEX_HOME:-}" ] && [ "$(basename "$ROOT")" = scripts ] && [ -f "$ROOT/../artisan" ]; then
        fail "Ce script se lance depuis le dossier de déploiement, pas depuis le code : copiez-le, par exemple dans ~/techindex/."
    fi
    case "$ROOT/" in
        */httpdocs/* | */public_html/* | */public/*)
            fail "Le dossier de déploiement $ROOT semble accessible depuis le Web : placez-le hors de toute racine de document (par exemple ~/techindex)." ;;
    esac
}

check_host() {
    info "Vérification de l'hébergement"
    PROBLEMS=0
    check_location
    if [ "$(id -u)" -eq 0 ]; then
        problem "Exécution en root : connectez-vous avec l'utilisateur système de l'abonnement Plesk (propriétaire du site), sans sudo."
    else
        ok "Utilisateur $(id -un) (groupe $(id -gn))"
    fi

    local tool
    for tool in tar gzip sha256sum awk sed grep find mktemp sort; do
        command -v "$tool" > /dev/null || problem "Commande « $tool » introuvable (accès SSH isolé « chroot » ? Demandez /bin/bash, voir docs/deploiement-plesk.md)."
    done
    [ -d /opt/plesk/php ] || warn "/opt/plesk/php absent : serveur non Plesk, ou accès SSH isolé (chroot) qui masque les versions de PHP."

    find_php
    ok "PHP $("$PHP" -r 'echo PHP_VERSION;') : $PHP"

    local level message
    while IFS='|' read -r level message; do
        case "$level" in
            ERR) problem "$message" ;;
            WARN) warn "$message" ;;
            OK) ok "$message" ;;
        esac
    done < <("$PHP" -d display_errors=stderr <<'PHPCODE'
<?php
if (PHP_VERSION_ID < 80500) {
    echo "WARN|PHP 8.5 est la version de référence (testée) ; PHP ", PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION, " est accepté.\n";
}
$missing = array_filter(['pdo_mysql', 'mbstring', 'intl', 'zip', 'fileinfo', 'openssl', 'ctype', 'tokenizer', 'dom', 'xml', 'xmlreader', 'filter', 'session'], fn ($e) => ! extension_loaded($e));
echo $missing === [] ? "OK|Extensions PHP requises présentes\n" : 'ERR|Extensions PHP manquantes : '.implode(', ', $missing)."\n";
foreach (['sodium' => 'chiffrement', 'curl' => 'requêtes HTTP sortantes', 'pcntl' => 'délai maximal des tâches de fond', 'posix' => 'gestion des tâches de fond'] as $e => $usage) {
    if (! extension_loaded($e)) {
        echo "WARN|Extension PHP $e absente en ligne de commande ($usage)\n";
    }
}
$disabled = array_filter(['proc_open', 'proc_get_status', 'proc_close', 'proc_terminate'], fn ($f) => ! function_exists($f));
echo $disabled === [] ? "OK|Fonctions proc_open autorisées\n" : 'ERR|Fonctions PHP désactivées (disable_functions) : '.implode(', ', $disabled)." – nécessaires aux sauvegardes et aux tâches de fond\n";
$limit = trim((string) ini_get('memory_limit'));
$bytes = $limit === '-1' ? PHP_INT_MAX : (int) $limit * match (strtoupper(substr($limit, -1))) { 'G' => 1 << 30, 'M' => 1 << 20, 'K' => 1 << 10, default => 1 };
if ($bytes < 256 << 20) {
    echo "WARN|memory_limit de PHP en ligne de commande : $limit (256M conseillé pour les imports)\n";
}
PHPCODE
    )

    local dump client
    dump="$(env_get BACKUP_MARIADB_DUMP)"
    client="$(env_get BACKUP_MARIADB)"
    if command -v "${dump:-mariadb-dump}" > /dev/null; then ok "Sauvegardes : ${dump:-mariadb-dump}"
    elif [ -z "$dump" ] && command -v mysqldump > /dev/null; then ok "Sauvegardes : mysqldump (mariadb-dump absent)"
    else problem "Outil ${dump:-mariadb-dump} introuvable : sauvegardes impossibles (demandez le client MariaDB à l'hébergeur)."; fi
    if command -v "${client:-mariadb}" > /dev/null; then ok "Restauration : ${client:-mariadb}"
    elif [ -z "$client" ] && command -v mysql > /dev/null; then ok "Restauration : mysql (mariadb absent)"
    else problem "Outil ${client:-mariadb} introuvable : restauration impossible."; fi

    command -v pgrep > /dev/null || warn "pgrep absent : les traitements en cours ne seront pas attendus pendant les mises à jour."
    command -v curl > /dev/null || warn "curl absent : l'accès au site ne sera pas contrôlé après une mise à jour."

    local free_mb
    free_mb="$(df -Pm "$ROOT" 2> /dev/null | awk 'NR == 2 { print $4 }' || true)"
    if [ -n "$free_mb" ] && [ "$free_mb" -lt 1024 ]; then
        warn "Moins de 1 Go libre sur le disque (quota Plesk éventuel non pris en compte)."
    fi

    [ "$PROBLEMS" -eq 0 ] || fail "$PROBLEMS problème(s) bloquant(s) : corrigez-les (Plesk > Paramètres PHP, accès SSH...) puis relancez."
}

# Connexion à la base, et serveur MariaDB (TechIndex ne prend pas en charge MySQL).
db_check() {
    info "Base de données"
    local output version major minor
    if ! output="$(cd "$APP_DIR" && "$PHP" artisan db:show --json --no-ansi --no-interaction 2>&1)"; then
        printf '%s\n' "$output" | grep -v '^[[:space:]]*$' | tail -n 3 | sed 's/^/      /' >&2
        fail "Connexion à la base impossible : vérifiez DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME et DB_PASSWORD dans $ENV_FILE (avec DB_HOST=localhost, essayez 127.0.0.1)."
    fi
    version="$(printf '%s\n' "$output" | tail -n1 | "$PHP" -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d) ? (string) ($d["platform"]["version"] ?? "") : "";')"
    if [[ "$version" =~ ^([0-9]+)\.([0-9]+) ]]; then
        major="${BASH_REMATCH[1]}"
        minor="${BASH_REMATCH[2]}"
        if [ "$major" -lt 10 ]; then
            fail "Serveur de base de données $version : TechIndex requiert MariaDB (10.11 ou plus récent), MySQL n'est pas pris en charge."
        elif [ "$major" -eq 10 ] && [ "$minor" -lt 11 ]; then
            warn "MariaDB $version : version ancienne, non testée (10.11 au minimum conseillé, 11.8 de référence)."
        else
            ok "MariaDB $version, base $(env_get DB_DATABASE)"
        fi
    else
        ok "Connexion établie (base $(env_get DB_DATABASE))"
    fi
}

# --- Traitements en cours -----------------------------------------------------------------
# Processus PHP de l'utilisateur exécutant un traitement de fond (worker, sauvegarde...).
running_tasks() {
    command -v pgrep > /dev/null || return 0
    pgrep -u "$(id -u)" -af '^[^ ]*php[0-9.]* (-f )?([^ ]*/)?artisan (-- )?(queue:work|techindex:|auth:clear-resets|queue:prune-failed)' 2> /dev/null || true
}

# Attend la fin des imports, sauvegardes... lancés par le planificateur (délai en secondes).
wait_idle() {
    local timeout="$1" waited=0 tasks
    while :; do
        tasks="$(running_tasks)"
        [ -n "$tasks" ] || return 0
        if [ "$waited" -ge "$timeout" ]; then
            printf '%s\n' "$tasks" | sed 's/^/      /' >&2
            return 1
        fi
        [ "$waited" -gt 0 ] || warn "Traitement en cours (import, sauvegarde...) : attente de sa fin, au plus ${timeout} s."
        sleep 5
        waited=$((waited + 5))
    done
}

# --- Canal officiel des versions (publications GitHub signées) ----------------------------
# Dépôt et clé publique : shared/.env (UPDATE_REPOSITORY, UPDATE_PUBLIC_KEY), sinon
# config/release-channel.json de la version installée, sinon ceux intégrés au script.
release_channel() {
    local file="$APP_DIR/config/release-channel.json" values=""
    RELEASE_REPOSITORY="${UPDATE_REPOSITORY:-$(env_get UPDATE_REPOSITORY)}"
    RELEASE_PUBLIC_KEY="${UPDATE_PUBLIC_KEY:-$(env_get UPDATE_PUBLIC_KEY)}"
    RELEASE_BASE_URL="${UPDATE_BASE_URL:-$(env_get UPDATE_BASE_URL)}"
    if [ -f "$file" ]; then
        values="$("$PHP" -r '$c = json_decode((string) file_get_contents($argv[1]), true); echo $c["repository"] ?? "", "\n", $c["public_key"] ?? "", "\n";' "$file")"
    fi
    [ -n "$RELEASE_REPOSITORY" ] || RELEASE_REPOSITORY="$(sed -n 1p <<< "$values")"
    [ -n "$RELEASE_PUBLIC_KEY" ] || RELEASE_PUBLIC_KEY="$(sed -n 2p <<< "$values")"
    case "$RELEASE_REPOSITORY_BUILTIN" in @*) ;; *) [ -n "$RELEASE_REPOSITORY" ] || RELEASE_REPOSITORY="$RELEASE_REPOSITORY_BUILTIN" ;; esac
    case "$RELEASE_PUBLIC_KEY_BUILTIN" in @*) ;; *) [ -n "$RELEASE_PUBLIC_KEY" ] || RELEASE_PUBLIC_KEY="$RELEASE_PUBLIC_KEY_BUILTIN" ;; esac
    RELEASE_BASE_URL="${RELEASE_BASE_URL:-https://github.com}"
    RELEASE_BASE_URL="${RELEASE_BASE_URL%/}"
    if ! [[ "$RELEASE_REPOSITORY" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || ! [[ "$RELEASE_PUBLIC_KEY" =~ ^[A-Za-z0-9+/=]{40,}$ ]]; then
        fail "Canal des versions non configuré (dépôt GitHub et clé publique, voir docs/publication-et-mises-a-jour.md) : indiquez une archive."
    fi
}

download() {  # adresse, destination
    command -v curl > /dev/null || fail "curl est nécessaire pour télécharger les versions."
    local proto=()
    case "$1" in https://*) proto=(--proto '=https' --proto-redir '=https') ;; esac
    curl -fsSL ${proto[@]+"${proto[@]}"} --retry 2 --connect-timeout 15 --max-time 900 -o "$2" "$1"
}

# Vérifie la signature Ed25519 du manifeste (extension sodium de PHP) puis
# affiche ses valeurs utiles au format CLÉ=valeur.
verify_manifest() {  # manifeste, signature
    "$PHP" -d display_errors=stderr -- "$1" "$2" "$RELEASE_PUBLIC_KEY" <<'PHPCODE'
<?php
[, $file, $signatureFile, $key] = $argv;
$content = (string) file_get_contents($file);
$signature = base64_decode(trim((string) file_get_contents($signatureFile)), true);
$publicKey = base64_decode(trim($key), true);
if (! function_exists('sodium_crypto_sign_verify_detached')) {
    fwrite(STDERR, "Extension PHP sodium absente : la signature ne peut pas être vérifiée.\n");
    exit(1);
}
if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || $publicKey === false
    || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || ! sodium_crypto_sign_verify_detached($signature, $content, $publicKey)) {
    fwrite(STDERR, "Signature invalide : cette version ne provient pas du canal officiel et a été refusée.\n");
    exit(1);
}
$manifest = json_decode($content, true);
$archive = is_array($manifest) ? ($manifest['assets']['plesk'] ?? []) : [];
$version = (string) ($manifest['version'] ?? '');
$values = [
    'VERSION' => $version,
    'TAG' => (string) ($manifest['tag'] ?? ''),
    'MIGRATIONS' => ! empty($manifest['migrations']) ? 'oui' : 'non',
    'ARCHIVE' => (string) ($archive['name'] ?? ''),
    'SHA256' => (string) ($archive['sha256'] ?? ''),
];
if (($manifest['format'] ?? null) !== 1 || preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version) !== 1 || $values['TAG'] !== 'v'.$version
    || $values['ARCHIVE'] !== "techindex-plesk-{$version}.tar.gz" || preg_match('/^[0-9a-f]{64}$/', $values['SHA256']) !== 1) {
    fwrite(STDERR, "Manifeste de version incomplet ou de format inconnu.\n");
    exit(1);
}
foreach ($values as $name => $value) {
    echo $name, '=', $value, "\n";
}
PHPCODE
}

# Manifeste signé de la version demandée (latest ou X.Y.Z) : REL_* renseignés.
fetch_release() {
    local wanted="$1" url values name value
    release_channel
    if [ "$wanted" = latest ]; then url="$RELEASE_BASE_URL/$RELEASE_REPOSITORY/releases/latest/download"
    else url="$RELEASE_BASE_URL/$RELEASE_REPOSITORY/releases/download/v$wanted"; fi
    info "Version publiée ${wanted/latest/la plus récente} ($RELEASE_REPOSITORY)"
    rm_tree "$DOWNLOAD_DIR"
    mkdir -p "$DOWNLOAD_DIR"
    download "$url/release.json" "$DOWNLOAD_DIR/release.json" \
        || fail "Manifeste introuvable ($url/release.json) : version publiée ? accès sortant du serveur à github.com ?"
    download "$url/release.json.sig" "$DOWNLOAD_DIR/release.json.sig" || fail "Signature du manifeste introuvable."
    values="$(verify_manifest "$DOWNLOAD_DIR/release.json" "$DOWNLOAD_DIR/release.json.sig")" || fail "Version refusée."
    while IFS='=' read -r name value; do
        case "$name" in
            VERSION) REL_VERSION="$value" ;;
            TAG) REL_TAG="$value" ;;
            MIGRATIONS) REL_MIGRATIONS="$value" ;;
            ARCHIVE) REL_ARCHIVE="$value" ;;
            SHA256) REL_SHA256="$value" ;;
        esac
    done <<< "$values"
    ok "Version $REL_VERSION, signature vérifiée (modifie la base de données : $REL_MIGRATIONS)"
}

# Archive de la version REL_* dans archives/, contrôlée par l'empreinte du manifeste signé.
download_release_archive() {
    local target="$ARCHIVES_DIR/$REL_ARCHIVE"
    mkdir -p "$ARCHIVES_DIR"
    chmod 700 "$ARCHIVES_DIR"
    if [ -f "$target" ] && [ "$(sha256sum "$target" | cut -d' ' -f1)" = "$REL_SHA256" ]; then
        ok "Archive déjà présente : $REL_ARCHIVE"
    else
        download "$RELEASE_BASE_URL/$RELEASE_REPOSITORY/releases/download/$REL_TAG/$REL_ARCHIVE" "$target.part" \
            || fail "Téléchargement de $REL_ARCHIVE impossible."
        mv -f "$target.part" "$target"
        ok "Archive téléchargée : $REL_ARCHIVE"
    fi
    printf '%s  %s\n' "$REL_SHA256" "$REL_ARCHIVE" > "$target.sha256"
    rm_tree "$DOWNLOAD_DIR"
    SOURCE_ARCHIVE="$target"
}

# Arguments d'install et d'update : archive, --version X.Y.Z ou --latest (défaut).
parse_source() {
    SOURCE_ARCHIVE=""
    SOURCE_VERSION="latest"
    while [ $# -gt 0 ]; do
        case "$1" in
            --latest) SOURCE_VERSION=latest ;;
            --version)
                [ $# -ge 2 ] || fail "--version : indiquez un numéro de version (ex. 1.4.0)."
                SOURCE_VERSION="${2#v}"
                shift
                ;;
            --version=*) SOURCE_VERSION="${1#--version=}" && SOURCE_VERSION="${SOURCE_VERSION#v}" ;;
            -*) fail "Option inconnue : $1" ;;
            *) SOURCE_ARCHIVE="$(absolute_path "$1")" ;;
        esac
        shift
    done
    [ "$SOURCE_VERSION" = latest ] || [[ "$SOURCE_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] \
        || fail "Numéro de version invalide : $SOURCE_VERSION"
}

version_compare() {  # a, b → -1, 0 ou 1
    "$PHP" -r 'echo version_compare($argv[1], $argv[2]);' "$1" "$2"
}

# --- Versions -----------------------------------------------------------------------------
verify_archive() {
    local archive="$1"
    [ -f "$archive" ] || fail "Archive introuvable : $archive"
    case "$archive" in
        *.tar.gz) ;;
        *) fail "Archive attendue : techindex-plesk-<version>.tar.gz" ;;
    esac
    info "Archive $(basename "$archive")"
    if [ -f "$archive.sha256" ]; then
        (cd "$(dirname "$archive")" && sha256sum -c --status "$(basename "$archive").sha256") \
            || fail "Empreinte SHA-256 différente : archive corrompue ou modifiée pendant le transfert."
        ok "Empreinte SHA-256 vérifiée"
    else
        warn "$(basename "$archive").sha256 absent : intégrité de l'archive non vérifiée."
    fi
    ARCHIVE_VERSION="$(tar -xzOf "$archive" ./VERSION 2> /dev/null | tr -d '\r\n' || true)"
    [[ "$ARCHIVE_VERSION" =~ ^[A-Za-z0-9._-]+$ ]] || fail "Archive TechIndex invalide (fichier VERSION absent)."
    ok "Version $ARCHIVE_VERSION"
}

extract_release() {
    local archive="$1" target="$2"
    rm_tree "$target"
    mkdir -p "$target"
    # -m : chaque fichier reçoit la date d'extraction, pour que l'OPcache de
    # PHP-FPM recompile tout le code de la nouvelle version.
    tar -xzmf "$archive" -C "$target" --no-same-owner
    [ -f "$target/artisan" ] && [ -f "$target/vendor/autoload.php" ] && [ -f "$target/public/index.php" ] \
        || fail "Archive incomplète : $archive"
}

prepare_shared() {
    mkdir -p "$SHARED_DIR/storage/app/private" "$SHARED_DIR/storage/framework/cache/data" \
        "$SHARED_DIR/storage/framework/sessions" "$SHARED_DIR/storage/framework/views" \
        "$SHARED_DIR/storage/logs" "$SHARED_DIR/backups" "$RELEASES_DIR" "$STATE_DIR"
    # Le serveur web doit traverser le dossier jusqu'à app/public, sans pouvoir
    # lister son contenu ; shared/ (clés, pièces jointes, sauvegardes) lui reste fermé.
    chmod 711 "$ROOT"
    chmod 700 "$SHARED_DIR" "$SHARED_DIR/backups" "$RELEASES_DIR" "$STATE_DIR"
    [ ! -d "$ARCHIVES_DIR" ] || chmod 700 "$ARCHIVES_DIR"
    [ ! -f "$ENV_FILE" ] || chmod 600 "$ENV_FILE"
}

# Liens relatifs vers shared/ : valables pour app/ et .incoming/ (même profondeur).
link_shared() {
    local dir="$1" name
    for name in storage .env backups; do
        if [ -e "$dir/$name" ] && [ ! -L "$dir/$name" ]; then rm_tree "$dir/$name"; fi
    done
    ln -sfn ../shared/storage "$dir/storage"
    ln -sfn ../shared/.env "$dir/.env"
    ln -sfn ../shared/backups "$dir/backups"
}

# Code en lecture seule, y compris pour PHP ; seul bootstrap/cache reste modifiable
# (le dossier lui-même aussi, pour pouvoir être déplacé lors d'une mise à jour).
harden_release() {
    local dir="$1"
    chmod -R a-w "$dir"
    chmod u+w "$dir" "$dir/bootstrap"
    chmod -R u+w "$dir/bootstrap/cache"
}

# Met en service un dossier préparé ; la version courante part dans releases/.
activate_release() {
    local source="$1" current parked
    PARKED_RELEASE=""
    if [ -d "$APP_DIR" ]; then
        current="$(cat "$APP_DIR/VERSION" 2> /dev/null || echo inconnue)"
        parked="$RELEASES_DIR/$current"
        [ ! -e "$parked" ] || parked="$parked-$(date +%Y%m%d%H%M%S)"
        mv "$APP_DIR" "$parked"
        PARKED_RELEASE="$(basename "$parked")"
    fi
    mv "$source" "$APP_DIR"
    link_shared "$APP_DIR"
}

# Attend SWITCH_DELAY secondes depuis le changement de version (instant $1, en SECONDS).
settle() {
    local remaining=$((SWITCH_DELAY - (SECONDS - $1)))
    [ "$remaining" -le 0 ] || sleep "$remaining"
}

build_caches() {
    artisan config:clear > /dev/null
    artisan route:clear > /dev/null
    artisan view:clear > /dev/null
    artisan event:clear > /dev/null
    artisan optimize
}

migrate() {
    info "Migrations"
    artisan migrate --force
    artisan db:seed --class=ReferenceDataSeeder --force
}

prune_releases() {
    local previous dir kept=0
    previous="$(state_get previous-release)"
    [ -z "$previous" ] || [ ! -d "$RELEASES_DIR/$previous" ] || kept=1
    while IFS= read -r dir; do
        [ -n "$dir" ] && [ "$dir" != "$previous" ] || continue
        if [ "$kept" -lt "$KEEP_RELEASES" ]; then
            kept=$((kept + 1))
        else
            rm_tree "$RELEASES_DIR/$dir"
            ok "Ancienne version supprimée : $dir"
        fi
    done < <(find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -rV)
}

prune_archives() {
    local archive="$1" old
    [ "$(dirname "$archive")" = "$ARCHIVES_DIR" ] || return 0
    while IFS= read -r old; do
        [ -n "$old" ] || continue
        rm -f "$ARCHIVES_DIR/$old" "$ARCHIVES_DIR/$old.sha256"
    done < <(find "$ARCHIVES_DIR" -maxdepth 1 -type f -name 'techindex-plesk-*.tar.gz' -printf '%f\n' | sort -r | tail -n +"$((KEEP_ARCHIVES + 1))")
}

# Le script livré avec la version en service remplace celui du dossier de déploiement.
install_script_copy() {
    local source="$APP_DIR/scripts/techindex-plesk.sh" target="$ROOT/techindex-plesk.sh"
    [ -f "$source" ] || return 0
    if [ -f "$target" ] && cmp -s "$source" "$target" 2> /dev/null; then return 0; fi
    cp "$source" "$target.new"
    chmod 700 "$target.new"
    mv -f "$target.new" "$target"
    ok "Script d'exploitation à jour : $target"
}

health_check() {
    local url code
    url="$(env_get APP_URL)"
    if ! command -v curl > /dev/null; then
        warn "Vérifiez l'accès au site dans un navigateur : $url"
        return 0
    fi
    code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$url/up" 2> /dev/null || true)"
    if [ "$code" = 200 ]; then
        ok "Site accessible : $url"
    else
        warn "$url/up répond « ${code:-aucune réponse} » : vérifiez la racine du document, PHP et le certificat dans Plesk (docs/deploiement-plesk.md)."
    fi
}

# --- Installation -------------------------------------------------------------------------
# Mode d'installation lu par l'administration : mises à jour depuis l'interface.
mark_deployment() {
    if [ "$(env_get TECHINDEX_DEPLOYMENT)" != plesk ]; then
        env_set TECHINDEX_DEPLOYMENT plesk
        ok "TECHINDEX_DEPLOYMENT=plesk"
    fi
}

configure_environment() {
    info "Configuration ($ENV_FILE)"
    if [ ! -f "$ENV_FILE" ]; then
        cp "$APP_DIR/.env.plesk.example" "$ENV_FILE"
        chmod 600 "$ENV_FILE"
        ok ".env créé à partir de .env.plesk.example"
    else
        chmod 600 "$ENV_FILE"
        ok ".env existant conservé (aucune valeur renseignée n'est remplacée)"
    fi

    local url domain host
    url="$(env_get APP_URL)"
    if [ -z "$url" ] || [ "$url" = "https://techindex.example.com" ]; then
        domain="$(ask "Domaine ou sous-domaine de TechIndex dans Plesk (ex. techindex.mondomaine.fr)")"
        domain="${domain#https://}"
        domain="${domain#http://}"
        domain="${domain%%/*}"
        [[ "$domain" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]] || fail "Nom de domaine invalide : $domain"
        env_set APP_URL "https://$domain"
        ok "APP_URL=https://$domain"
    fi

    if [ -z "$(env_get DB_DATABASE)" ] || [ -z "$(env_get DB_USERNAME)" ]; then
        printf '    Base créée dans Plesk (Sites Web & Domaines > Bases de données) :\n'
        local name user
        name="$(ask_identifier "Nom de la base")"
        user="$(ask_identifier "Utilisateur de la base")"
        host="$(ask "Serveur de la base" localhost)"
        if [[ "$host" =~ ^(.+):([0-9]+)$ ]]; then
            host="${BASH_REMATCH[1]}"
            env_set DB_PORT "${BASH_REMATCH[2]}"
        fi
        [[ "$host" =~ ^[A-Za-z0-9._-]+$ ]] || fail "Serveur de base invalide : $host"
        env_set DB_DATABASE "$name"
        env_set DB_USERNAME "$user"
        env_set DB_HOST "$host"
        ok "Base $name, utilisateur $user, serveur $host"
    fi
    if [ -z "$(env_get DB_PASSWORD)" ]; then
        local password
        password="$(ask_secret "Mot de passe de l'utilisateur $(env_get DB_USERNAME) (saisie masquée)")"
        case "$password" in
            *"'"* | *$'\n'*) fail "Mot de passe contenant une apostrophe : générez-en un autre dans Plesk." ;;
        esac
        # Entre apostrophes : aucun caractère n'est interprété (#, $, espaces...).
        env_set DB_PASSWORD "'$password'"
        ok "Mot de passe enregistré"
    fi

    if [ "$(env_get TECHINDEX_PHP)" != "$PHP" ]; then
        env_set TECHINDEX_PHP "$PHP"
        ok "TECHINDEX_PHP=$PHP"
    fi
    mark_deployment

    # Anciennes versions de MariaDB, ou client MySQL : outils de sauvegarde à indiquer.
    if [ -z "$(env_get BACKUP_MARIADB_DUMP)" ] && ! command -v mariadb-dump > /dev/null && command -v mysqldump > /dev/null; then
        env_set BACKUP_MARIADB_DUMP mysqldump
        ok "BACKUP_MARIADB_DUMP=mysqldump"
    fi
    if [ -z "$(env_get BACKUP_MARIADB)" ] && ! command -v mariadb > /dev/null && command -v mysql > /dev/null; then
        env_set BACKUP_MARIADB mysql
        ok "BACKUP_MARIADB=mysql"
    fi
}

generate_keys() {
    info "Clés de chiffrement"
    local key
    if [ -z "$(env_get APP_KEY)" ]; then
        key="$(artisan_value key:generate --show)"
        [[ "$key" == base64:* ]] || fail "Génération d'APP_KEY impossible."
        env_set APP_KEY "$key"
        ok "APP_KEY générée"
    else
        ok "APP_KEY existante conservée"
    fi
    if [ -z "$(env_get SECRETS_KEY)" ] && [ -z "$(env_get SECRETS_KEY_FILE)" ]; then
        key="$(artisan_value techindex:secrets-key --show)"
        [[ "$key" == base64:* ]] || fail "Génération de SECRETS_KEY impossible."
        env_set SECRETS_KEY "$key"
        ok "SECRETS_KEY générée"
    else
        ok "Clé des secrets existante conservée"
    fi
}

create_admin_if_needed() {
    info "Compte administrateur"
    if artisan techindex:has-admin > /dev/null 2>&1; then
        ok "Un administrateur existe déjà"
        return 0
    fi
    local answer
    answer="$(ask "Créer le premier administrateur maintenant ? (O/n)" o)"
    case "$answer" in
        [oOyY]*) artisan techindex:create-admin ;;
        *) warn "Création reportée : ./techindex-plesk.sh create-admin" ;;
    esac
}

show_plesk_steps() {
    local relative="$APP_DIR" version
    case "$APP_DIR" in "$HOME"/*) relative="${APP_DIR#"$HOME"/}" ;; esac
    version="$("$PHP" -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
    info "À régler dans Plesk (docs/deploiement-plesk.md, § 6)"
    printf '    1. Hébergement > Racine du document :      %s/public\n' "$relative"
    printf '    2. Paramètres PHP : PHP %s (FPM), upload_max_filesize 25M, post_max_size 26M\n' "$version"
    printf '    3. Tâches planifiées > Exécuter un script PHP (PHP %s), cron « * * * * * » :\n' "$version"
    printf '         script %s/artisan    arguments : schedule:run\n' "$relative"
    printf '    4. Certificats SSL/TLS (Let'"'"'s Encrypt) et redirection permanente vers HTTPS\n'
    printf '    Puis : ./techindex-plesk.sh doctor\n'
}

remind_keys() {
    printf '\n'
    warn "Sauvegardez APP_KEY et SECRETS_KEY HORS du serveur : ./techindex-plesk.sh keys-export <fichier>"
    warn "Sans elles, les secrets enregistrés deviennent définitivement illisibles."
}

# --- Commandes -------------------------------------------------------------------------------
cmd_install() {
    local archive installed
    parse_source "$@"
    check_host
    prepare_shared
    acquire_lock
    if [ -z "$SOURCE_ARCHIVE" ]; then
        fetch_release "$SOURCE_VERSION"
        download_release_archive
    fi
    archive="$SOURCE_ARCHIVE"
    verify_archive "$archive"
    [ -z "$REL_VERSION" ] || [ "$ARCHIVE_VERSION" = "$REL_VERSION" ] || fail "L'archive ne correspond pas au manifeste signé."

    if [ -d "$APP_DIR" ]; then
        installed="$(cat "$APP_DIR/VERSION" 2> /dev/null || true)"
        [ "$installed" = "$ARCHIVE_VERSION" ] || fail "TechIndex est déjà installé (version ${installed:-inconnue}) : utilisez « update »."
        ok "Version $installed déjà en place : reprise de l'installation"
    else
        info "Extraction"
        extract_release "$archive" "$INCOMING_DIR"
        link_shared "$INCOMING_DIR"
        harden_release "$INCOMING_DIR"
        activate_release "$INCOMING_DIR"
        ok "Code installé dans $APP_DIR"
    fi

    configure_environment
    link_shared "$APP_DIR"
    install_script_copy
    generate_keys
    db_check
    migrate
    info "Caches de configuration"
    build_caches
    create_admin_if_needed
    info "Diagnostic"
    artisan techindex:doctor || true
    prune_archives "$archive"
    show_plesk_steps
    remind_keys
    printf '\n\033[32mTechIndex %s est installé dans %s.\033[0m\n' "$ARCHIVE_VERSION" "$APP_DIR"
}

cmd_update() {
    local archive current
    parse_source "$@"
    assert_installed
    acquire_lock
    status_begin
    STATUS_VERSION="${SOURCE_VERSION/latest/}"
    check_host
    current="$(cat "$APP_DIR/VERSION" 2> /dev/null || true)"

    if [ -z "$SOURCE_ARCHIVE" ]; then
        # Version publiée : signature vérifiée, jamais de retour à une version antérieure.
        fetch_release "$SOURCE_VERSION"
        STATUS_VERSION="$REL_VERSION"
        case "$(version_compare "$REL_VERSION" "$current")" in
            0)
                ok "TechIndex est déjà à jour (version $current)."
                status_finish succeeded "Déjà à jour : version $current."
                return 0
                ;;
            -1) fail "La version $REL_VERSION est plus ancienne que la version installée ($current) : utilisez « rollback »." ;;
        esac
        download_release_archive
    fi
    archive="$SOURCE_ARCHIVE"
    verify_archive "$archive"
    STATUS_VERSION="$ARCHIVE_VERSION"
    [ -z "$REL_VERSION" ] || [ "$ARCHIVE_VERSION" = "$REL_VERSION" ] || fail "L'archive ne correspond pas au manifeste signé."
    [ "$current" != "$ARCHIVE_VERSION" ] || fail "La version $ARCHIVE_VERSION est déjà en service."
    prepare_shared

    info "Préparation de la version $ARCHIVE_VERSION (site en service)"
    STAGE=staging
    extract_release "$archive" "$INCOMING_DIR"
    link_shared "$INCOMING_DIR"
    (cd "$INCOMING_DIR" && "$PHP" artisan --version --no-ansi > /dev/null) \
        || fail "La nouvelle version ne démarre pas avec $PHP (version ou extensions de PHP ?)."
    harden_release "$INCOMING_DIR"
    ok "Nouvelle version prête"

    info "Sauvegarde préalable (obligatoire)"
    wait_idle 600 || fail "Un traitement est toujours en cours : réessayez plus tard."
    artisan techindex:backup || fail "Sauvegarde impossible : mise à jour annulée."
    state_set pre-update-backup "$(latest_backup)"

    info "Mise en maintenance"
    STAGE=maintenance
    artisan down --retry=60
    artisan queue:restart
    wait_idle 120 || fail "Un traitement est toujours en cours : mise à jour annulée."

    info "Mise en service de la version $ARCHIVE_VERSION"
    local switched_at=$SECONDS
    activate_release "$INCOMING_DIR"
    STAGE=switched
    state_set previous-release "$PARKED_RELEASE"
    # Ancienne copie de la même version (redéploiement après un retour arrière).
    rm_tree "$RELEASES_DIR/$ARCHIVE_VERSION"
    migrate
    info "Caches de configuration"
    mark_deployment
    build_caches
    settle "$switched_at"
    artisan up
    STAGE=""

    info "Finalisation"
    install_script_copy
    prune_releases
    prune_archives "$archive"
    health_check
    ok "Mise à jour terminée : version $ARCHIVE_VERSION (précédente : ${current:-inconnue})"
    warn "En cas de problème : ./techindex-plesk.sh rollback (voir docs/mise-a-jour.md)"
    status_finish succeeded "Version $ARCHIVE_VERSION en service (sauvegarde préalable : $(state_get pre-update-backup))."
}

cmd_check_update() {
    local current
    assert_installed
    current="$(cat "$APP_DIR/VERSION" 2> /dev/null || true)"
    fetch_release latest
    rm_tree "$DOWNLOAD_DIR"
    if [ "$(version_compare "$REL_VERSION" "$current")" = 1 ]; then
        ok "Nouvelle version disponible : $REL_VERSION (installée : $current)"
        printf '    Installation : ./techindex-plesk.sh update   (ou Administration > Mises à jour)\n'
    else
        ok "TechIndex est à jour (version installée : $current, dernière publiée : $REL_VERSION)."
    fi
}

cmd_rollback() {
    assert_installed
    acquire_lock
    local previous current
    previous="$(state_get previous-release)"
    [ -n "$previous" ] && [ -d "$RELEASES_DIR/$previous" ] || fail "Aucune version précédente disponible dans $RELEASES_DIR."
    status_begin
    STATUS_VERSION="$previous"
    check_host
    current="$(cat "$APP_DIR/VERSION" 2> /dev/null || true)"
    warn "Retour de la version ${current:-inconnue} à la version $previous."

    wait_idle 600 || fail "Un traitement est toujours en cours : réessayez plus tard."
    STAGE=maintenance
    artisan down --retry=60
    artisan queue:restart
    wait_idle 120 || fail "Un traitement est toujours en cours : retour arrière annulé."

    local switched_at=$SECONDS
    activate_release "$RELEASES_DIR/$previous"
    STAGE=switched
    state_set previous-release "$PARKED_RELEASE"
    build_caches
    settle "$switched_at"
    artisan up
    STAGE=""
    install_script_copy
    health_check
    ok "Version $previous en service (la version $PARKED_RELEASE est conservée dans releases/)."
    warn "Si la version abandonnée avait modifié la base, restaurez aussi la sauvegarde préalable :"
    warn "  ./techindex-plesk.sh restore $(state_get pre-update-backup)"
    status_finish succeeded "Retour arrière : version $previous en service."
}

cmd_status() {
    find_php
    info "TechIndex – $ROOT"
    printf '    Version en service   : %s\n' "$(cat "$APP_DIR/VERSION" 2> /dev/null || echo 'non installé')"
    printf '    Version précédente   : %s\n' "$(state_get previous-release)"
    printf '    Versions conservées  : %s\n' "$(find "$RELEASES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%f ' 2> /dev/null || true)"
    if [ -f "$SHARED_DIR/storage/framework/down" ]; then
        printf '    État                 : \033[33mEN MAINTENANCE\033[0m (./techindex-plesk.sh up)\n'
    else
        printf '    État                 : en service\n'
    fi
    printf '    PHP                  : %s (%s)\n' "$PHP" "$("$PHP" -r 'echo PHP_VERSION;')"
    printf '    Dernière sauvegarde  : %s\n' "$(latest_backup)"
    local tasks last=""
    tasks="$(running_tasks)"
    printf '    Traitements en cours : %s\n' "${tasks:-aucun}"
    if [ -f "$UPDATES_DIR/status.json" ]; then
        last="$("$PHP" -r '$s = json_decode((string) file_get_contents($argv[1]), true) ?: []; echo ($s["state"] ?? "?"), " – ", ($s["version"] ?? ""), " – ", ($s["finished_at"] ?: ($s["started_at"] ?? "")), " – ", ($s["message"] ?? "");' "$UPDATES_DIR/status.json")"
    fi
    printf '    Dernière mise à jour : %s\n' "${last:-aucune}"
    if [ -d "$LOCK_DIR" ]; then
        printf '    Opération en cours   : processus %s\n' "$(cat "$LOCK_DIR/pid" 2> /dev/null || echo '?')"
    fi
}

cmd_logs() {
    local file
    file="$(find "$SHARED_DIR/storage/logs" -maxdepth 1 -type f -name '*.log' -printf '%T@ %p\n' 2> /dev/null | sort -rn | head -n1 | cut -d' ' -f2- || true)"
    if [ -z "$file" ]; then
        ok "Aucun journal pour le moment ($SHARED_DIR/storage/logs)."
        return 0
    fi
    printf '==> %s\n' "$file"
    if [ "${1:-}" = "-f" ]; then tail -n 200 -f "$file"; else tail -n 200 "$file"; fi
}

cmd_reload() {
    assert_installed
    info "Prise en compte de shared/.env"
    chmod 600 "$ENV_FILE"
    build_caches
    artisan queue:restart
}

cmd_restore() {
    [ $# -ge 1 ] || fail "Usage : ./techindex-plesk.sh restore <archive> [--identity fichier-cle-age]"
    assert_installed
    local file
    file="$(backup_file "$1")"
    shift
    parse_identity "$@"
    acquire_lock
    wait_idle 600 || fail "Un traitement est toujours en cours : réessayez plus tard."
    STAGE=maintenance
    artisan down --retry=60
    artisan queue:restart
    wait_idle 120 || fail "Un traitement est toujours en cours : restauration annulée."
    artisan techindex:restore "$file" ${IDENTITY_ARGS[@]+"${IDENTITY_ARGS[@]}"}
    artisan up
    STAGE=""
}

cmd_verify_backup() {
    [ $# -ge 1 ] || fail "Usage : ./techindex-plesk.sh verify-backup <archive> [--identity fichier-cle-age]"
    assert_installed
    local file
    file="$(backup_file "$1")"
    shift
    parse_identity "$@"
    if [ -z "$(env_get BACKUP_VERIFY_DATABASE)" ]; then
        warn "BACKUP_VERIFY_DATABASE vide : une base temporaire sera créée, ce que Plesk refuse en général (voir docs/deploiement-plesk.md)."
    fi
    artisan techindex:backup-verify "$file" ${IDENTITY_ARGS[@]+"${IDENTITY_ARGS[@]}"}
}

cmd_backup_keygen() {
    assert_installed
    local keygen="$APP_DIR/bin/age-keygen" file recipient
    file="$(absolute_path "${1:-$ROOT/age-key-$(date +%Y%m%d).txt}")"
    [ -x "$keygen" ] || fail "age-keygen absent de la version installée : générez la paire de clés sur votre poste (docs/sauvegarde-restauration.md)."
    [ ! -e "$file" ] || fail "Le fichier $file existe déjà."
    (umask 077 && "$keygen" -o "$file" 2> /dev/null)
    recipient="$(grep -o 'age1[0-9a-z]*' "$file" | head -n1)"
    [ -n "$recipient" ] || fail "Clé publique introuvable dans $file."
    env_set BACKUP_AGE_RECIPIENT "$recipient"
    build_caches > /dev/null
    ok "Clé publique enregistrée dans shared/.env (BACKUP_AGE_RECIPIENT=$recipient)"
    warn "Clé PRIVÉE : $file – téléchargez-la dans un coffre, puis supprimez-la du serveur : shred -u $file"
}

cmd_keys_export() {
    [ $# -ge 1 ] || fail "Usage : ./techindex-plesk.sh keys-export <fichier>"
    assert_installed
    local file
    file="$(absolute_path "$1")"
    [ ! -e "$file" ] || fail "Le fichier $file existe déjà."
    (
        umask 077
        {
            echo "# Clés de chiffrement TechIndex – exportées le $(date '+%Y-%m-%d %H:%M')"
            echo "# À conserver hors du serveur et hors des sauvegardes."
            echo "APP_KEY=$(env_get APP_KEY)"
            echo "APP_PREVIOUS_KEYS=$(env_get APP_PREVIOUS_KEYS)"
            echo "SECRETS_KEY=$(env_get SECRETS_KEY)"
            echo "SECRETS_PREVIOUS_KEYS=$(env_get SECRETS_PREVIOUS_KEYS)"
        } > "$file"
    )
    ok "Clés exportées dans $file (droits 600)."
    warn "Téléchargez ce fichier dans un coffre, puis supprimez-le du serveur : shred -u $file"
}

cmd_rotate_secrets_key() {
    check_host
    assert_installed
    local current previous new_key
    current="$(env_get SECRETS_KEY)"
    [ -n "$current" ] || fail "SECRETS_KEY est vide (clé fournie par SECRETS_KEY_FILE ?) : suivez la procédure manuelle de docs/secrets-et-cles.md."
    info "Sauvegarde préalable (obligatoire)"
    artisan techindex:backup || fail "Sauvegarde impossible : rotation annulée."
    info "Nouvelle clé des secrets"
    new_key="$(artisan_value techindex:secrets-key --show)"
    [[ "$new_key" == base64:* ]] || fail "Clé générée invalide : rotation annulée."
    previous="$(env_get SECRETS_PREVIOUS_KEYS)"
    env_set SECRETS_PREVIOUS_KEYS "$current${previous:+,$previous}"
    env_set SECRETS_KEY "$new_key"
    ok "Ancienne clé conservée dans SECRETS_PREVIOUS_KEYS (déchiffrement uniquement)"
    build_caches > /dev/null
    artisan queue:restart
    info "Rechiffrement des secrets"
    artisan techindex:secrets-rotate
    artisan techindex:secrets-check
    remind_keys
}

main() {
    local cmd="${1:-help}"
    shift || true

    case "$cmd" in
        check)
            check_host
            if [ -f "$APP_DIR/artisan" ] && [ -f "$ENV_FILE" ]; then db_check; fi
            printf '\n\033[32mHébergement compatible.\033[0m\n'
            ;;
        check-update) cmd_check_update ;;
        install) cmd_install "$@" ;;
        update) cmd_update "$@" ;;
        rollback) cmd_rollback ;;
        status) cmd_status ;;
        reload) cmd_reload ;;
        down) assert_installed; artisan down --retry=60 ;;
        up) assert_installed; artisan up ;;
        logs) cmd_logs "$@" ;;
        backup) assert_installed; artisan techindex:backup ;;
        verify-backup) cmd_verify_backup "$@" ;;
        restore) cmd_restore "$@" ;;
        backup-keygen) cmd_backup_keygen "$@" ;;
        keys-export) cmd_keys_export "$@" ;;
        rotate-secrets-key) cmd_rotate_secrets_key ;;
        create-admin) assert_installed; artisan techindex:create-admin ;;
        doctor) assert_installed; artisan techindex:doctor ;;
        mail-test) assert_installed; artisan techindex:mail-test "$@" ;;
        artisan) assert_installed; artisan "$@" ;;
        help | -h | --help) sed -n '2,36p' "${BASH_SOURCE[0]}" ;;
        *)
            sed -n '2,36p' "${BASH_SOURCE[0]}"
            exit 1
            ;;
    esac
}

# Tout le script est lu avant exécution : il peut être remplacé pendant une mise à jour.
main "$@"
exit $?
