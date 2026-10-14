# Déploiement sur un serveur Linux

La production utilise les mêmes composants que le poste de développement
(même `Dockerfile`, même configuration Nginx et PHP), avec `compose.prod.yaml` :

- images compilées, sans Composer, Node.js ni outils de développement ;
- code en lecture seule pour l'utilisateur `www-data` ;
- `APP_DEBUG=false` et journaux sur la sortie standard, avec rotation ;
- MariaDB sur un réseau Docker interne, sans aucun port publié ;
- Nginx seul exposé (80/443), HTTPS Let's Encrypt renouvelé automatiquement
  ou certificat fourni ;
- worker, planificateur, contrôles de santé et redémarrage automatique.

## 1. Prérequis

| Élément | Minimum | Conseillé |
|---------|---------|-----------|
| Système | Linux 64 bits avec Docker Engine (Debian 12/13, Ubuntu 22.04/24.04 LTS...) | Distribution LTS maintenue |
| Processeur | 2 vCPU | 2 à 4 vCPU |
| Mémoire | 2 Go | 4 Go (jusqu'à environ 50 utilisateurs simultanés) |
| Disque | 20 Go + pièces jointes | SSD ; sauvegardes copiées sur un autre support |
| Logiciels | Docker Engine 24+ et le plugin Compose v2 ([installation](https://docs.docker.com/engine/install/)) | `git` pour les mises à jour |

Réseau :

| Port / flux | Sens | Usage |
|-------------|------|-------|
| 80/TCP | Entrant | Validation Let's Encrypt et redirection vers HTTPS |
| 443/TCP | Entrant | Application |
| 443/TCP | Sortant | Let's Encrypt, GitHub (versions publiées), téléchargement des images et dépendances lors de la construction |
| 587 ou 465/TCP | Sortant | Serveur SMTP (récupération de compte, invitations) |

MariaDB (3306) n'est jamais publié.

Avant l'installation :

- **DNS** : créez un enregistrement `A` (et `AAAA` si IPv6) du nom choisi
  (par exemple `techindex.votre-domaine.fr`) vers l'adresse publique du serveur.
- **Pare-feu** : autorisez uniquement 22 (administration, idéalement restreint),
  80 et 443. Les ports publiés par Docker contournent `ufw` ; seul le service
  `web` en publie.

## 2. Installation

```bash
# 1. Récupérer le code du dépôt GitHub public, sur la dernière version publiée
sudo git clone https://github.com/<compte>/<dépôt>.git /opt/techindex
cd /opt/techindex
sudo git checkout "$(git describe --tags --abbrev=0)"   # étiquette vX.Y.Z la plus récente

# 2. Installer
sudo chmod +x scripts/techindex.sh     # si le bit d'exécution a été perdu (copie depuis Windows)
sudo ./scripts/techindex.sh install
```

Le script, que l'on peut relancer sans risque :

1. vérifie Docker, Compose et la disponibilité des ports ;
2. crée `.env` à partir de `.env.production.example` s'il n'existe pas ;
3. demande le nom de domaine et l'adresse e-mail Let's Encrypt ;
4. génère les mots de passe MariaDB (aléatoires, jamais affichés) ;
5. applique les droits `root:www-data 640` au fichier `.env` ;
6. construit les images, avec un numéro de version horodaté ;
7. génère `APP_KEY` et `SECRETS_KEY` **uniquement si elles sont absentes** ;
8. démarre MariaDB, exécute les migrations et les données de référence ;
9. démarre les services et attend leurs contrôles de santé ;
10. obtient le certificat Let's Encrypt et active HTTPS ;
11. propose la création du premier administrateur ;
12. lance le diagnostic `techindex:doctor`.

Ensuite, sans attendre :

1. **Exportez les clés hors du serveur** (voir [secrets-et-cles.md](secrets-et-cles.md)) :
   ```bash
   sudo ./scripts/techindex.sh keys-export /root/techindex-cles.txt
   ```
   Placez ce fichier dans un coffre ou un gestionnaire de mots de passe, puis
   supprimez-le du serveur.
2. **Configurez la messagerie** (§ 4) et testez-la.
3. **Chiffrez les sauvegardes** : `sudo ./scripts/techindex.sh backup-keygen`
   (voir [sauvegarde-restauration.md](sauvegarde-restauration.md)).
4. **Organisez la copie des sauvegardes hors du serveur**.
5. Connectez-vous, changez le mot de passe si demandé, puis activez la double
   authentification.

## 3. Paramètres principaux (`.env`)

Le fichier `.env.production.example` est commenté ligne par ligne. Les
principaux paramètres :

| Paramètre | Rôle |
|-----------|------|
| `APP_DOMAIN`, `APP_URL` | Nom de domaine et URL publique (`https://...`). Seules les requêtes adressées à ce domaine sont acceptées. |
| `TLS_MODE` | `letsencrypt` (défaut), `custom` (certificat fourni) ou `off` (TLS assuré par un reverse proxy) |
| `LETSENCRYPT_EMAIL` | Adresse pour les avis d'expiration Let's Encrypt |
| `HTTP_PORT`, `HTTPS_PORT` | Ports publiés (80/443 par défaut) |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | Générés à l'installation |
| `MARIADB_BUFFER_POOL` | Cache InnoDB, environ 25 % de la RAM (512M par défaut) |
| `APP_KEY`, `SECRETS_KEY` | Clés de chiffrement : à sauvegarder hors du serveur |
| `TWO_FACTOR_REQUIRED_ROLES` | Rôles pour lesquels la double authentification est obligatoire |
| `SESSION_LIFETIME`, `SESSION_ABSOLUTE_LIFETIME` | Expiration des sessions, en minutes |
| `MAIL_*` | Serveur SMTP |
| `BACKUP_DAILY_AT`, `BACKUP_KEEP`, `BACKUP_AGE_RECIPIENT` | Sauvegarde automatique, rétention locale, chiffrement |
| `TRUSTED_PROXIES` | Adresses du reverse proxy éventuel (mode `off`) |

Après une modification de `.env` :

```bash
sudo ./scripts/techindex.sh restart
```

Les caches de configuration sont reconstruits au démarrage des conteneurs.

## 4. Messagerie

```ini
MAIL_MAILER=smtp
MAIL_HOST=smtp.votre-fournisseur.fr
MAIL_PORT=587
MAIL_USERNAME=techindex@votre-domaine.fr
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="techindex@votre-domaine.fr"
```

```bash
sudo ./scripts/techindex.sh restart
sudo ./scripts/techindex.sh mail-test vous@votre-domaine.fr
```

Pour un bon taux de délivrabilité, utilisez une adresse expéditrice de votre
domaine, autorisée par ses enregistrements SPF et DKIM.

## 5. HTTPS

### Let's Encrypt (`TLS_MODE=letsencrypt`)

- Le certificat est obtenu à l'installation (`tls-init`), ce qui exige que le
  DNS pointe vers le serveur et que le port 80 soit ouvert.
- Le service `certbot` tente un renouvellement deux fois par jour, et Nginx
  recharge sa configuration toutes les six heures.
- Si le certificat manque, Nginx démarre en HTTP pour permettre son obtention,
  puis `sudo ./scripts/techindex.sh tls-init` active HTTPS.
- Le serveur HTTPS envoie l'en-tête HSTS (un an) : les navigateurs refuseront
  ensuite tout accès en HTTP à ce domaine. Le domaine doit donc rester servi en HTTPS.

### Certificat fourni (`TLS_MODE=custom`)

```bash
sudo install -m 600 fullchain.pem privkey.pem docker/certs/
```

Puis, dans `.env` :

```ini
TLS_MODE=custom
TLS_CERT_FILE=/etc/nginx/certs/fullchain.pem
TLS_KEY_FILE=/etc/nginx/certs/privkey.pem
```

Le fichier `fullchain.pem` contient le certificat suivi des certificats
intermédiaires. Redémarrez ensuite avec `sudo ./scripts/techindex.sh restart`.
Le renouvellement du certificat est alors à votre charge.

### Derrière un reverse proxy (`TLS_MODE=off`)

Si un reverse proxy (Traefik, HAProxy, Nginx frontal, répartiteur de charge)
assure déjà le TLS :

```ini
TLS_MODE=off
HTTP_PORT=127.0.0.1:8080     # écoute locale si le proxy est sur le même serveur
HTTPS_PORT=127.0.0.1:8443    # inutilisé, mais doit rester libre
TRUSTED_PROXIES=127.0.0.1    # ou l'adresse / le sous-réseau du proxy
APP_URL=https://techindex.votre-domaine.fr
```

Le proxy doit transmettre les en-têtes `Host`, `X-Forwarded-For` et
`X-Forwarded-Proto: https`.

## 6. Droits et isolation

| Élément | Protection |
|---------|-----------|
| `.env` | `root:www-data`, droits `640` (réappliqués par le script), monté en lecture seule dans les conteneurs |
| Code de l'application | Appartient à `root` dans l'image, non modifiable par `www-data` |
| Écriture | Limitée à `storage/` et `bootstrap/cache/` ; pièces jointes dans le volume `app-storage` |
| `./backups` | Propriétaire `www-data` (uid 33), droits `700` |
| Processus PHP | Utilisateur `www-data`, aucun port publié |
| MariaDB | Réseau `backend` interne (sans accès Internet) |
| Nginx | Seuls `public/index.php` et les fichiers compilés sont servis ; fichiers cachés et autres scripts PHP refusés |

Les images ne contiennent ni `.env`, ni clés, ni sauvegardes (`.dockerignore`).

## 7. Exploitation

```bash
sudo ./scripts/techindex.sh status            # état et santé des services
sudo ./scripts/techindex.sh logs app          # journaux (Ctrl+C pour quitter)
sudo ./scripts/techindex.sh restart
sudo ./scripts/techindex.sh doctor            # diagnostic
sudo ./scripts/techindex.sh backup            # sauvegarde immédiate
sudo ./scripts/techindex.sh check-update      # nouvelle version publiée ?
sudo ./scripts/techindex.sh update            # mise à jour vers la dernière version signée (docs/mise-a-jour.md)
sudo ./scripts/techindex.sh artisan <cmd>     # commande Artisan
```

- **Journaux** : pilote `json-file` de Docker, avec rotation à 5 fichiers de 10 Mo
  par service.
- **Santé** :
  - PHP-FPM est contrôlé par son point `ping` ;
  - Nginx répond sur `/nginx-health` ;
  - pour le worker et le planificateur, la présence du processus est vérifiée ;
  - pour MariaDB, la connexion et le moteur InnoDB sont vérifiés.
- **Redémarrage** : les services repartent automatiquement (`unless-stopped`),
  y compris après un redémarrage du serveur, tant que le service Docker est
  activé (`systemctl enable docker`).

## 8. Liste de contrôle après installation

- [ ] `doctor` sans erreur ;
- [ ] HTTPS valide et redirection de HTTP vers HTTPS ;
- [ ] clés exportées et stockées hors du serveur ;
- [ ] e-mail de test reçu ;
- [ ] sauvegarde chiffrée (`BACKUP_AGE_RECIPIENT`), copiée hors du serveur et
      vérifiée avec `verify-backup` ;
- [ ] double authentification activée pour les administrateurs ;
- [ ] port 3306 inaccessible depuis l'extérieur, par exemple avec
      `nmap -p 3306 <ip>` depuis un autre poste ;
- [ ] mises à jour de sécurité du système planifiées (`unattended-upgrades`...).
