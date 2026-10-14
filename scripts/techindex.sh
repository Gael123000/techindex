#!/usr/bin/env bash
# =============================================================================
#  TechIndex – exploitation en production (serveur Linux, Docker Compose v2)
# =============================================================================
#  Usage : sudo ./scripts/techindex.sh <commande> [arguments]
#
#  install              Installation initiale (configuration, images, base, HTTPS, administrateur)
#  start | stop | restart | status
#  logs [service]       Journaux (suivi continu)
#  check-update         Recherche une nouvelle version publiée (signature vérifiée)
#  update [X.Y.Z]       Sauvegarde, dernière version publiée (ou X.Y.Z), migrations, redémarrage
#  rollback             Revient à l'image précédente (voir docs/mise-a-jour.md)
#  backup               Sauvegarde (base, pièces jointes, paramètres – sans les clés)
#  verify-backup F      Test de restauration dans une base temporaire
#  restore F            Restauration (remplace les données)        [--identity fichier]
#  backup-keygen        Génère la paire de clés age de chiffrement des sauvegardes
#  keys-export F        Copie APP_KEY / SECRETS_KEY dans F (à stocker hors du serveur)
#  rotate-secrets-key   Nouvelle clé des secrets et rechiffrement (sauvegarde préalable)
#  tls-init             Obtient le certificat Let's Encrypt puis active HTTPS
#  create-admin | doctor | mail-test ADRESSE | artisan ...
#
#  Aucun chemin propre à un poste : tout est relatif au dossier du projet.
# =============================================================================
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

ENV_FILE="$ROOT/.env"
COMPOSE=(docker compose -f "$ROOT/compose.prod.yaml" --env-file "$ENV_FILE")
STATE_DIR="$ROOT/.deploy"
WWW_UID=33   # utilisateur www-data de l'image PHP (Debian)

info()  { printf '\n\033[36m==> %s\033[0m\n' "$*"; }
ok()    { printf '    \033[32m[OK]\033[0m %s\n' "$*"; }
warn()  { printf '    \033[33m[!]\033[0m  %s\n' "$*"; }
fail()  { printf '\n\033[31mERREUR : %s\033[0m\n' "$*" >&2; exit 1; }

# --- Fichier .env ------------------------------------------------------------
env_get() {
    [ -f "$ENV_FILE" ] || return 0
    grep -E "^$1=" "$ENV_FILE" | tail -n1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
}

# Définit une valeur uniquement si elle est vide ou absente (jamais d'écrasement).
env_set_if_empty() {
    local key="$1" value="$2" current
    current="$(env_get "$key")"
    if [ -n "$current" ]; then ok "$key déjà défini : conservé"; return 0; fi
    env_set "$key" "$value"
    ok "$key défini"
}

env_set() {
    local key="$1" value="$2" tmp
    tmp="$(mktemp)"
    if grep -qE "^$key=" "$ENV_FILE"; then
        awk -v k="$key" -v v="$value" 'BEGIN { FS = OFS = "=" } $1 == k { print k "=" v; next } { print }' "$ENV_FILE" > "$tmp"
    else
        cat "$ENV_FILE" > "$tmp"
        printf '%s=%s\n' "$key" "$value" >> "$tmp"
    fi
    cat "$tmp" > "$ENV_FILE"
    rm -f "$tmp"
}

random_secret() { head -c 96 /dev/urandom | base64 | LC_ALL=C tr -dc 'A-Za-z0-9' | cut -c1-"${1:-32}"; }

secure_env_permissions() {
    # Lisible par root et par le groupe www-data (uid 33) des conteneurs, personne d'autre.
    chown "root:$WWW_UID" "$ENV_FILE"
    chmod 640 "$ENV_FILE"
}

# --- Prérequis ---------------------------------------------------------------
check_prerequisites() {
    info "Vérification des prérequis"
    [ "$(id -u)" -eq 0 ] || fail "Lancez ce script avec sudo (gestion des permissions de .env et des volumes)."
    command -v docker > /dev/null || fail "Docker Engine n'est pas installé (https://docs.docker.com/engine/install/)."
    docker info > /dev/null 2>&1 || fail "Le service Docker ne répond pas (systemctl start docker)."
    docker compose version > /dev/null 2>&1 || fail "Le plugin Docker Compose v2 est absent."
    ok "Docker $(docker version --format '{{.Server.Version}}'), Compose $(docker compose version --short)"
}

check_ports() {
    local port
    for port in "$(env_get HTTP_PORT)" "$(env_get HTTPS_PORT)"; do
        [ -n "$port" ] || continue
        if "${COMPOSE[@]}" ps --status running --services 2> /dev/null | grep -qx web; then return 0; fi
        if ss -ltn "( sport = :$port )" 2> /dev/null | grep -q LISTEN; then
            fail "Le port $port est déjà utilisé sur ce serveur."
        fi
        ok "Port $port disponible"
    done
}

# --- Étapes ------------------------------------------------------------------
prepare_configuration() {
    info "Configuration"
    if [ ! -f "$ENV_FILE" ]; then
        cp "$ROOT/.env.production.example" "$ENV_FILE"
        ok ".env créé à partir de .env.production.example"
    else
        ok ".env existant conservé"
    fi
    secure_env_permissions

    local domain
    domain="$(env_get APP_DOMAIN)"
    if [ -z "$domain" ] || [ "$domain" = "techindex.example.com" ]; then
        read -r -p "    Nom de domaine de TechIndex (ex. techindex.mondomaine.fr) : " domain
        [ -n "$domain" ] || fail "Nom de domaine requis."
        env_set APP_DOMAIN "$domain"
        env_set APP_URL "https://$domain"
    fi

    if [ "$(env_get TLS_MODE)" = "letsencrypt" ] && [ "$(env_get LETSENCRYPT_EMAIL)" = "admin@example.com" ]; then
        local email
        read -r -p "    Adresse e-mail pour Let's Encrypt (alertes d'expiration) : " email
        env_set LETSENCRYPT_EMAIL "$email"
    fi

    env_set_if_empty DB_PASSWORD "$(random_secret 32)"
    env_set_if_empty DB_ROOT_PASSWORD "$(random_secret 32)"
    env_set_if_empty TECHINDEX_VERSION "$(date +%Y%m%d%H%M)"
    env_set_if_empty TECHINDEX_DEPLOYMENT docker

    mkdir -p "$ROOT/backups" "$ROOT/docker/certs" "$STATE_DIR"
    chown "$WWW_UID:$WWW_UID" "$ROOT/backups"
    chmod 700 "$ROOT/backups" "$STATE_DIR"
}

build_images() {
    info "Construction des images (version $(env_get TECHINDEX_VERSION))"
    "${COMPOSE[@]}" build --pull
}

generate_keys() {
    info "Clés de chiffrement"
    if [ -z "$(env_get APP_KEY)" ]; then
        env_set APP_KEY "$("${COMPOSE[@]}" run --rm --no-deps -T --entrypoint php app artisan key:generate --show --no-ansi | tr -d '\r')"
        ok "APP_KEY générée"
    else
        ok "APP_KEY existante conservée"
    fi
    if [ -z "$(env_get SECRETS_KEY)" ] && [ -z "$(env_get SECRETS_KEY_FILE)" ]; then
        env_set SECRETS_KEY "$("${COMPOSE[@]}" run --rm --no-deps -T --entrypoint php app artisan techindex:secrets-key --show --no-ansi | tr -d '\r')"
        ok "SECRETS_KEY générée"
    else
        ok "Clé des secrets existante conservée"
    fi
    secure_env_permissions
}

wait_database() {
    info "Démarrage de MariaDB"
    "${COMPOSE[@]}" up -d mariadb
    local _attempt
    for _attempt in $(seq 1 60); do
        if [ "$(docker inspect --format '{{.State.Health.Status}}' "$("${COMPOSE[@]}" ps -q mariadb)" 2> /dev/null)" = "healthy" ]; then
            ok "MariaDB disponible"
            return 0
        fi
        sleep 3
    done
    fail "MariaDB ne répond pas : ./scripts/techindex.sh logs mariadb"
}

artisan() { "${COMPOSE[@]}" run --rm -T app php artisan "$@"; }
artisan_tty() { "${COMPOSE[@]}" run --rm app php artisan "$@"; }

migrate() {
    info "Migrations"
    artisan migrate --force
    artisan db:seed --class=ReferenceDataSeeder --force
}

start_services() {
    info "Démarrage des services"
    local profiles=()
    [ "$(env_get TLS_MODE)" = "letsencrypt" ] && profiles=(--profile letsencrypt)
    "${COMPOSE[@]}" "${profiles[@]}" up -d --remove-orphans
}

wait_healthy() {
    local _attempt
    for _attempt in $(seq 1 40); do
        if "${COMPOSE[@]}" exec -T web wget -q -O /dev/null http://127.0.0.1/nginx-health 2> /dev/null \
            && "${COMPOSE[@]}" exec -T app php-fpm-healthcheck 2> /dev/null; then
            ok "Services opérationnels"
            return 0
        fi
        sleep 3
    done
    fail "Les services ne répondent pas : ./scripts/techindex.sh status / logs"
}

tls_init() {
    [ "$(env_get TLS_MODE)" = "letsencrypt" ] || { ok "TLS_MODE=$(env_get TLS_MODE) : pas de certificat Let's Encrypt à obtenir"; return 0; }
    local domain email
    domain="$(env_get APP_DOMAIN)"
    email="$(env_get LETSENCRYPT_EMAIL)"
    info "Certificat Let's Encrypt pour $domain"
    warn "Le DNS de $domain doit pointer vers ce serveur et le port 80 être ouvert."
    warn "En poursuivant, vous acceptez les conditions d'utilisation de Let's Encrypt (https://letsencrypt.org/repository/)."
    "${COMPOSE[@]}" --profile letsencrypt run --rm --entrypoint certbot certbot certonly \
        --webroot -w /var/www/certbot -d "$domain" --email "$email" --agree-tos --no-eff-email --non-interactive --keep-until-expiring
    "${COMPOSE[@]}" restart web
    ok "HTTPS activé ; le service certbot renouvelle le certificat automatiquement."
}

create_admin_if_needed() {
    info "Compte administrateur"
    if artisan techindex:has-admin > /dev/null 2>&1; then ok "Un administrateur existe déjà"; return 0; fi
    read -r -p "    Créer le premier administrateur maintenant ? (O/n) " answer
    case "${answer:-o}" in
        [oOyY]*) artisan_tty techindex:create-admin ;;
        *) warn "Création reportée : ./scripts/techindex.sh create-admin" ;;
    esac
}

# --- Versions publiées (GitHub, manifeste signé) ------------------------------
channel_value() {  # repository | public_key
    local override
    override="$(env_get "UPDATE_$(echo "$1" | tr '[:lower:]' '[:upper:]')")"
    if [ -n "$override" ]; then printf '%s' "$override"; return 0; fi
    sed -n "s/.*\"$1\" *: *\"\([^\"]*\)\".*/\1/p" "$ROOT/config/release-channel.json" 2> /dev/null | head -n1
}

# PHP de l'image en service (le serveur hôte n'a pas besoin de PHP).
image_php() {
    docker run --rm -i --network none --entrypoint php "$(env_get TECHINDEX_IMAGE):$(env_get TECHINDEX_VERSION)" "$@"
}

# Manifeste signé de la version voulue (latest ou X.Y.Z), vérifié avec la clé
# publique du code installé : REL_VERSION, REL_COMMIT renseignés.
fetch_release() {
    local wanted="$1" repository public_key base url work values
    repository="$(channel_value repository)"
    public_key="$(channel_value public_key)"
    [ -n "$repository" ] && [ -n "$public_key" ] || return 1
    command -v curl > /dev/null || fail "curl est nécessaire pour rechercher les versions publiées."
    base="$(env_get UPDATE_BASE_URL)"
    base="${base:-https://github.com}"
    if [ "$wanted" = latest ]; then url="${base%/}/$repository/releases/latest/download"; else url="${base%/}/$repository/releases/download/v$wanted"; fi
    info "Version publiée ${wanted/latest/la plus récente} ($repository)"
    work="$(mktemp -d)"
    chmod 755 "$work"
    if ! curl -fsSL --retry 2 -o "$work/release.json" "$url/release.json" || ! curl -fsSL --retry 2 -o "$work/release.json.sig" "$url/release.json.sig"; then
        rm -rf "$work"
        fail "Manifeste introuvable ($url) : version publiée ? accès sortant à github.com ?"
    fi
    chmod 644 "$work"/*
    values="$(docker run --rm --network none -v "$work:/release:ro" -v "$ROOT/scripts/release-tool.php:/release-tool.php:ro" --entrypoint php \
        "$(env_get TECHINDEX_IMAGE):$(env_get TECHINDEX_VERSION)" /release-tool.php verify /release/release.json /release/release.json.sig "$public_key")" \
        || { rm -rf "$work"; fail "Version refusée : signature ou manifeste invalide."; }
    rm -rf "$work"
    REL_VERSION="$(sed -n 's/^VERSION=//p' <<< "$values")"
    REL_COMMIT="$(sed -n 's/^COMMIT=//p' <<< "$values")"
    ok "Version $REL_VERSION, signature vérifiée (modifie la base de données : $(sed -n 's/^MIGRATIONS=//p' <<< "$values"))"
}

version_compare() {  # a, b → -1, 0 ou 1
    image_php -r 'echo version_compare($argv[1], $argv[2]);' "$1" "$2"
}

# Version à installer, déterminée avant toute modification. Sans canal
# configuré, la mise à jour suivra la branche Git (git pull --ff-only).
resolve_release() {
    local wanted="$1" current
    REL_VERSION=""
    [ -d "$ROOT/.git" ] || { warn "Dossier sans Git : remplacez le code à la main (docs/mise-a-jour.md)."; return 0; }
    [ -z "$(git -C "$ROOT" status --porcelain --untracked-files=no)" ] || fail "Le code a été modifié sur le serveur (git status) : mise à jour annulée."
    if ! fetch_release "$wanted"; then
        warn "Canal des versions non configuré (config/release-channel.json) : suivi de la branche Git."
        return 0
    fi
    current="$(cat "$ROOT/VERSION" 2> /dev/null || echo 0.0.0)"
    case "$(version_compare "$REL_VERSION" "$current")" in
        0) ok "TechIndex est déjà à jour (version $current)."; exit 0 ;;
        -1) fail "La version $REL_VERSION est plus ancienne que la version installée ($current) : utilisez « rollback »." ;;
    esac
}

# Code de la version publiée : étiquette Git dont le commit est celui du manifeste signé.
checkout_release() {
    [ -d "$ROOT/.git" ] || return 0
    if [ -z "$REL_VERSION" ]; then
        info "Récupération du code (branche suivie)"
        git -C "$ROOT" pull --ff-only
        return 0
    fi
    info "Récupération de la version $REL_VERSION"
    git -C "$ROOT" fetch --tags --force origin
    [ "$(git -C "$ROOT" rev-parse "refs/tags/v$REL_VERSION^{commit}")" = "$REL_COMMIT" ] \
        || fail "L'étiquette v$REL_VERSION ne correspond pas au commit signé : mise à jour refusée."
    git -C "$ROOT" -c advice.detachedHead=false checkout --detach "$REL_COMMIT"
    ok "Code de la version $REL_VERSION (commit ${REL_COMMIT:0:12})"
}

remind_keys() {
    printf '\n'
    warn "Sauvegardez APP_KEY et SECRETS_KEY HORS du serveur : ./scripts/techindex.sh keys-export <fichier>"
    warn "Sans elles, les secrets enregistrés deviennent définitivement illisibles."
}

# --- Commandes ---------------------------------------------------------------
cmd="${1:-help}"
shift || true

case "$cmd" in
    install)
        check_prerequisites
        prepare_configuration
        check_ports
        build_images
        generate_keys
        wait_database
        migrate
        start_services
        wait_healthy
        tls_init
        create_admin_if_needed
        artisan techindex:doctor || true
        remind_keys
        printf '\n\033[32mTechIndex est installé : %s\033[0m\n' "$(env_get APP_URL)"
        ;;
    start) start_services ;;
    stop) "${COMPOSE[@]}" --profile letsencrypt stop ;;
    restart) "${COMPOSE[@]}" --profile letsencrypt restart ;;
    status) "${COMPOSE[@]}" --profile letsencrypt ps ;;
    logs) "${COMPOSE[@]}" logs --tail 200 --follow "$@" ;;
    check-update)
        check_prerequisites
        fetch_release latest || fail "Canal des versions non configuré (config/release-channel.json)."
        current="$(cat "$ROOT/VERSION" 2> /dev/null || echo 0.0.0)"
        if [ "$(version_compare "$REL_VERSION" "$current")" = 1 ]; then
            ok "Nouvelle version disponible : $REL_VERSION (installée : $current) – sudo ./scripts/techindex.sh update"
        else
            ok "TechIndex est à jour (version $current)."
        fi
        ;;
    update)
        check_prerequisites
        wanted="${1:-latest}"
        wanted="${wanted#v}"
        [ "$wanted" = latest ] || [[ "$wanted" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] || fail "Numéro de version invalide : $wanted"
        resolve_release "$wanted"
        info "Sauvegarde préalable (obligatoire)"
        artisan techindex:backup || fail "Sauvegarde impossible : mise à jour annulée."
        previous="$(env_get TECHINDEX_VERSION)"
        echo "$previous" > "$STATE_DIR/previous-version"
        ls -1t "$ROOT/backups"/techindex-backup-* | head -n1 > "$STATE_DIR/pre-update-backup"
        checkout_release
        env_set_if_empty TECHINDEX_DEPLOYMENT docker
        env_set TECHINDEX_VERSION "$(date +%Y%m%d%H%M)"
        build_images
        info "Mise en maintenance"
        "${COMPOSE[@]}" exec -T app php artisan down --retry=60 || true
        "${COMPOSE[@]}" stop worker scheduler
        migrate
        start_services
        wait_healthy
        "${COMPOSE[@]}" exec -T app php artisan up || true
        ok "Mise à jour terminée (version $(env_get TECHINDEX_VERSION), précédente : $previous)"
        warn "En cas de problème : ./scripts/techindex.sh rollback (voir docs/mise-a-jour.md)"
        ;;
    rollback)
        check_prerequisites
        [ -f "$STATE_DIR/previous-version" ] || fail "Aucune version précédente enregistrée."
        previous="$(cat "$STATE_DIR/previous-version")"
        docker image inspect "$(env_get TECHINDEX_IMAGE):$previous" > /dev/null 2>&1 || fail "Image précédente $previous introuvable."
        warn "Retour à la version $previous."
        warn "Si la mise à jour a modifié la base, restaurez aussi la sauvegarde préalable :"
        warn "  ./scripts/techindex.sh restore $(cat "$STATE_DIR/pre-update-backup" 2> /dev/null)"
        env_set TECHINDEX_VERSION "$previous"
        start_services
        wait_healthy
        ;;
    backup) artisan techindex:backup ;;
    verify-backup)
        [ $# -ge 1 ] || fail "Usage : verify-backup <archive dans ./backups> [--identity fichier]"
        file="/backups/$(basename "$1")"; shift
        artisan techindex:backup-verify "$file" "$@"
        ;;
    restore)
        [ $# -ge 1 ] || fail "Usage : restore <archive dans ./backups> [--identity /backups/cle.txt]"
        file="/backups/$(basename "$1")"; shift
        "${COMPOSE[@]}" exec -T app php artisan down --retry=60 || true
        "${COMPOSE[@]}" stop worker scheduler
        artisan_tty techindex:restore "$file" "$@"
        "${COMPOSE[@]}" up -d
        "${COMPOSE[@]}" exec -T app php artisan up || true
        ;;
    backup-keygen)
        key_file="${1:-$ROOT/age-key-$(date +%Y%m%d).txt}"
        "${COMPOSE[@]}" run --rm --no-deps -T --entrypoint age-keygen app > "$key_file"
        chmod 600 "$key_file"
        recipient="$(grep -o 'age1[0-9a-z]*' "$key_file" | head -n1)"
        env_set BACKUP_AGE_RECIPIENT "$recipient"
        secure_env_permissions
        ok "Clé publique enregistrée dans .env (BACKUP_AGE_RECIPIENT=$recipient)"
        warn "Clé PRIVÉE : $key_file — déplacez-la HORS du serveur (coffre, poste d'administration) puis supprimez-la ici."
        ;;
    keys-export)
        [ $# -ge 1 ] || fail "Usage : keys-export <fichier>"
        umask 077
        {
            echo "# Clés de chiffrement TechIndex – exportées le $(date '+%Y-%m-%d %H:%M')"
            echo "# À conserver hors du serveur et hors des sauvegardes."
            echo "APP_KEY=$(env_get APP_KEY)"
            echo "APP_PREVIOUS_KEYS=$(env_get APP_PREVIOUS_KEYS)"
            echo "SECRETS_KEY=$(env_get SECRETS_KEY)"
            echo "SECRETS_PREVIOUS_KEYS=$(env_get SECRETS_PREVIOUS_KEYS)"
        } > "$1"
        ok "Clés exportées dans $1 (droits 600). Stockez ce fichier en lieu sûr puis supprimez-le du serveur."
        ;;
    rotate-secrets-key)
        check_prerequisites
        current="$(env_get SECRETS_KEY)"
        [ -n "$current" ] || fail "SECRETS_KEY est vide (clé fournie par SECRETS_KEY_FILE ?) : suivez la procédure manuelle de docs/secrets-et-cles.md."
        info "Sauvegarde préalable (obligatoire)"
        artisan techindex:backup || fail "Sauvegarde impossible : rotation annulée."
        info "Nouvelle clé des secrets"
        new_key="$("${COMPOSE[@]}" run --rm --no-deps -T --entrypoint php app artisan techindex:secrets-key --show --no-ansi | tr -d '\r')"
        case "$new_key" in base64:*) ;; *) fail "Clé générée invalide : rotation annulée." ;; esac
        previous="$(env_get SECRETS_PREVIOUS_KEYS)"
        env_set SECRETS_PREVIOUS_KEYS "$current${previous:+,$previous}"
        env_set SECRETS_KEY "$new_key"
        secure_env_permissions
        ok "Ancienne clé conservée dans SECRETS_PREVIOUS_KEYS (déchiffrement uniquement)"
        # Les conteneurs relisent .env à leur démarrage.
        "${COMPOSE[@]}" restart app worker scheduler
        info "Rechiffrement des secrets"
        artisan techindex:secrets-rotate
        artisan techindex:secrets-check
        remind_keys
        ;;
    tls-init) tls_init ;;
    create-admin) artisan_tty techindex:create-admin ;;
    doctor) artisan techindex:doctor ;;
    mail-test) artisan techindex:mail-test "$@" ;;
    artisan) artisan_tty "$@" ;;
    help | *)
        sed -n '2,23p' "$0"
        ;;
esac
