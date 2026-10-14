<#
.SYNOPSIS
    TechIndex – installation et exploitation locale (Windows, Docker Desktop + WSL2).

.DESCRIPTION
    Commande principale du poste de développement. Toutes les opérations
    passent par Docker : aucun PHP, MariaDB ni Nginx à installer sur le poste.
    Le script peut être relancé sans risque : il n'écrase jamais une
    configuration, une clé ou des données existantes.

    Commandes :
      install          Vérifie les prérequis, prépare .env, construit, installe,
                       migre la base et propose la création de l'administrateur.
      start            Démarre l'application   (option -ExposeDatabase)
      stop             Arrête les conteneurs (données conservées)
      restart          Redémarre
      status           État des services
      logs [service]   Journaux (option -Follow)
      update           Sauvegarde, met à jour les dépendances, recompile, migre
      backup           Sauvegarde base + fichiers + paramètres (sans les clés)
      verify-backup F  Teste la restauration d'une archive (base temporaire)
      restore F        Restaure une archive (remplace les données)
      keys-export F    Copie les clés de chiffrement dans le fichier F (à stocker hors du poste)
      demo             Charge les données de démonstration fictives
      create-admin     Crée un compte administrateur
      test             Lance les tests automatisés
      doctor           Diagnostic de l'installation
      artisan ...      Exécute une commande Artisan
      dev              Lance Vite avec rechargement à chaud (développement du frontend)
      release-init     Prépare Git, le dépôt GitHub (-Repository propriétaire/nom) et la
                       clé de signature des versions (une seule fois)
      release [V]      Publie une version : patch (défaut), minor, major ou X.Y.Z.
                       Tests, VERSION, CHANGELOG.md, commit, étiquette, envoi sur
                       GitHub, qui construit et signe la version (options -SkipTests,
                       -Yes, -Deploy pour mettre ensuite à jour la production Plesk)
      plesk-package    Construit une archive Plesk de test dans .\dist\plesk
      plesk-deploy     Met à jour la production Plesk par SSH :
                       -Release latest|X.Y.Z : version publiée, téléchargée par le serveur
                       sans -Release : archive construite sur ce poste (déploiement forcé)
                       (options -Target utilisateur@serveur, -RemotePath techindex ;
                       valeurs par défaut : PLESK_DEPLOY_TARGET et PLESK_DEPLOY_PATH de .env)

.EXAMPLE
    .\techindex.cmd install
    .\techindex.ps1 logs web -Follow
    .\techindex.ps1 restore .\backups\techindex-backup-20261014-120000.zip
    .\techindex.cmd release minor -Deploy
    .\techindex.cmd plesk-deploy -Release latest -Target techindex@serveur.example.com
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [string] $Command = 'install',

    [Parameter(Position = 1, ValueFromRemainingArguments = $true)]
    [string[]] $Arguments = @(),

    [switch] $Follow,
    [switch] $ExposeDatabase,
    [string] $Identity,
    [switch] $Yes,
    [string] $Target,
    [string] $RemotePath = 'techindex',
    [string] $Repository,
    [string] $Release,
    [switch] $SkipTests,
    [switch] $Deploy
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$RemotePathGiven = $PSBoundParameters.ContainsKey('RemotePath')
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$Root = $PSScriptRoot
Set-Location $Root

# ---------------------------------------------------------------------------
# Affichage
# ---------------------------------------------------------------------------
function Write-Step([string] $Message) { Write-Host ''; Write-Host "==> $Message" -ForegroundColor Cyan }
function Write-Ok([string] $Message) { Write-Host "    [OK] $Message" -ForegroundColor Green }
function Write-Warn([string] $Message) { Write-Host "    [!]  $Message" -ForegroundColor Yellow }
function Fail([string] $Message) { Write-Host ''; Write-Host "ERREUR : $Message" -ForegroundColor Red; exit 1 }

# ---------------------------------------------------------------------------
# Docker Compose
# ---------------------------------------------------------------------------
function Get-ComposeFiles {
    $files = @('-f', 'compose.yaml')
    if ($ExposeDatabase -or (Test-Path (Join-Path $Root '.techindex-expose-db'))) { $files += @('-f', 'compose.db-port.yaml') }
    return $files
}

function Invoke-Compose {
    param([Parameter(ValueFromRemainingArguments = $true)] [string[]] $ComposeArgs)
    $all = @('compose') + (Get-ComposeFiles) + $ComposeArgs
    & docker @all
    if ($LASTEXITCODE -ne 0) { Fail "docker $($ComposeArgs -join ' ') a échoué (code $LASTEXITCODE)." }
}

function Invoke-ComposeSoft {
    param([Parameter(ValueFromRemainingArguments = $true)] [string[]] $ComposeArgs)
    $all = @('compose') + (Get-ComposeFiles) + $ComposeArgs
    # Sortie affichée, jamais renvoyée : la fonction ne retourne que le code.
    & docker @all | Out-Host
    return $LASTEXITCODE
}

# Commande docker dont on ne veut que la sortie standard et le code retour.
# Windows PowerShell 5.1 transforme chaque ligne d'erreur redirigée en
# exception lorsque ErrorActionPreference vaut Stop : on l'assouplit ici.
function Invoke-DockerQuiet {
    param([Parameter(ValueFromRemainingArguments = $true)] [string[]] $DockerArgs)
    $ErrorActionPreference = 'Continue'
    $output = & docker @DockerArgs 2>$null
    return [pscustomobject]@{ Output = @($output | ForEach-Object { "$_".Trim() } | Where-Object { $_ -ne '' }); ExitCode = $LASTEXITCODE }
}

function Invoke-Artisan {
    param([Parameter(ValueFromRemainingArguments = $true)] [string[]] $ArtisanArgs)
    Invoke-Compose -ComposeArgs (@('run', '--rm', '-T', 'app', 'php', 'artisan') + $ArtisanArgs)
}

function Invoke-ArtisanInteractive {
    param([Parameter(ValueFromRemainingArguments = $true)] [string[]] $ArtisanArgs)
    Invoke-Compose -ComposeArgs (@('run', '--rm', 'app', 'php', 'artisan') + $ArtisanArgs)
}

# ---------------------------------------------------------------------------
# Fichier .env : lecture et complétion (jamais d'écrasement d'une valeur)
# ---------------------------------------------------------------------------
$EnvFile = Join-Path $Root '.env'

function Get-EnvValue([string] $Key) {
    if (-not (Test-Path $EnvFile)) { return $null }
    foreach ($line in [System.IO.File]::ReadAllLines($EnvFile)) {
        if ($line -match "^\s*$([regex]::Escape($Key))\s*=(.*)$") { return $Matches[1].Trim().Trim('"') }
    }
    return $null
}

function Set-EnvValueIfEmpty([string] $Key, [string] $Value) {
    $lines = [System.Collections.Generic.List[string]]::new([System.IO.File]::ReadAllLines($EnvFile))
    $found = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -match "^\s*$([regex]::Escape($Key))\s*=(.*)$") {
            $found = $true
            if ($Matches[1].Trim().Trim('"') -eq '') { $lines[$i] = "$Key=$Value"; Write-Ok "$Key généré" } else { Write-Ok "$Key déjà défini : conservé" }
        }
    }
    if (-not $found) { $lines.Add("$Key=$Value"); Write-Ok "$Key ajouté" }
    [System.IO.File]::WriteAllLines($EnvFile, $lines, (New-Object System.Text.UTF8Encoding($false)))
}

function New-RandomSecret([int] $Length = 32) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'.ToCharArray()
    $bytes = New-Object byte[] $Length
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $rng.GetBytes($bytes)
    return -join ($bytes | ForEach-Object { $alphabet[$_ % $alphabet.Length] })
}

# ---------------------------------------------------------------------------
# Prérequis
# ---------------------------------------------------------------------------
function Test-Docker {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        Fail "Docker Desktop n'est pas installé. Installez-le (avec le moteur WSL2) : https://docs.docker.com/desktop/setup/install/windows-install/"
    }

    $info = Invoke-DockerQuiet info --format '{{.OSType}}'
    if ($info.ExitCode -ne 0) { Fail 'Docker Desktop ne répond pas : démarrez-le puis relancez la commande.' }
    $osType = $info.Output -join ''
    if ($osType -ne 'linux') { Fail "Docker doit utiliser des conteneurs Linux (moteur WSL2). Type actuel : $osType" }
    Write-Ok 'Docker Desktop actif (conteneurs Linux)'

    $compose = Invoke-DockerQuiet compose version --short
    if ($compose.ExitCode -ne 0) { Fail 'Docker Compose v2 est introuvable (« docker compose »).' }
    Write-Ok ('Docker Compose ' + ($compose.Output -join ''))
}

function Test-Prerequisites {
    Write-Step 'Vérification des prérequis'
    Test-Docker

    # Conteneurs TechIndex déjà démarrés (sans lire compose.yaml, qui exige un .env complet).
    $running = (Invoke-DockerQuiet ps --filter 'label=com.docker.compose.project=techindex' --format '{{.Names}}').Output
    $ports = @(
        @{ Name = 'APP_PORT'; Default = 8080; Service = 'web' },
        @{ Name = 'MAILPIT_PORT'; Default = 8025; Service = 'mailpit' }
    )
    foreach ($p in $ports) {
        $value = Get-EnvValue $p.Name
        $port = if ($value) { [int] $value } else { $p.Default }
        if (@($running | Where-Object { $_ -match "^techindex-$($p.Service)-\d+$" }).Count -gt 0) { Write-Ok "Port $port déjà utilisé par TechIndex ($($p.Service))"; continue }
        $busy = Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue
        if ($busy) { Fail "Le port $port est déjà utilisé sur ce poste. Libérez-le ou modifiez $($p.Name) dans .env." }
        Write-Ok "Port $port disponible"
    }
}

# ---------------------------------------------------------------------------
# Configuration locale
# ---------------------------------------------------------------------------
function Initialize-Configuration {
    Write-Step 'Préparation de la configuration locale'

    if (-not (Test-Path $EnvFile)) {
        Copy-Item (Join-Path $Root '.env.example') $EnvFile
        Write-Ok '.env créé à partir de .env.example'
    } else {
        Write-Ok '.env existant conservé'
    }

    Set-EnvValueIfEmpty 'DB_PASSWORD' (New-RandomSecret 32)
    Set-EnvValueIfEmpty 'DB_ROOT_PASSWORD' (New-RandomSecret 32)

    foreach ($dir in 'backups', 'storage\app\private', 'storage\framework\cache\data', 'storage\framework\sessions', 'storage\framework\views', 'storage\logs', 'bootstrap\cache') {
        $path = Join-Path $Root $dir
        if (-not (Test-Path $path)) { New-Item -ItemType Directory -Path $path | Out-Null }
    }
}

function Wait-Database {
    Write-Step 'Attente de MariaDB'
    Invoke-Compose -ComposeArgs @('up', '-d', 'mariadb')
    $psArgs = @('compose') + (Get-ComposeFiles) + @('ps', '-q', 'mariadb')
    for ($i = 0; $i -lt 60; $i++) {
        $id = (Invoke-DockerQuiet @psArgs).Output -join ''
        if ($id) {
            $health = (Invoke-DockerQuiet inspect --format '{{.State.Health.Status}}' $id).Output -join ''
            if ($health -eq 'healthy') { Write-Ok 'MariaDB disponible'; return }
        }
        Start-Sleep -Seconds 3
    }
    Fail 'MariaDB ne répond pas. Consultez : .\techindex.ps1 logs mariadb'
}

function Install-Dependencies {
    Write-Step 'Installation des dépendances PHP (Composer, dans le conteneur)'
    Invoke-Compose -ComposeArgs @('run', '--rm', '-T', '--no-deps', 'app', 'composer', 'install', '--no-interaction', '--prefer-dist', '--no-progress')

    Write-Step 'Compilation de l''interface (Node.js, dans le conteneur)'
    Invoke-Compose -ComposeArgs @('--profile', 'tools', 'run', '--rm', 'node')
}

function Initialize-Keys {
    Write-Step 'Clés de chiffrement'
    if (-not (Get-EnvValue 'APP_KEY')) {
        Invoke-Compose -ComposeArgs @('run', '--rm', '-T', '--no-deps', 'app', 'php', 'artisan', 'key:generate', '--force')
        Write-Ok 'APP_KEY générée'
    } else {
        Write-Ok 'APP_KEY existante conservée'
    }
    Invoke-Compose -ComposeArgs @('run', '--rm', '-T', '--no-deps', 'app', 'php', 'artisan', 'techindex:secrets-key')
}

function Initialize-Database {
    Write-Step 'Migrations de la base de données'
    Invoke-Artisan migrate --force
    Invoke-Artisan db:seed --class=ReferenceDataSeeder --force
}

function Initialize-Admin {
    Write-Step 'Compte administrateur'
    $code = Invoke-ComposeSoft -ComposeArgs @('run', '--rm', '-T', 'app', 'php', 'artisan', 'techindex:has-admin')
    if ($code -eq 0) { Write-Ok 'Un administrateur existe déjà'; return }

    $answer = if ($Yes) { 'o' } else { Read-Host '    Aucun administrateur. Le créer maintenant ? (O/n)' }
    if ($answer -eq '' -or $answer -match '^[oOyY]') {
        Write-Host '    Le mot de passe est saisi de façon masquée et n''est jamais affiché.' -ForegroundColor Gray
        Invoke-ArtisanInteractive techindex:create-admin
    } else {
        Write-Warn 'Création reportée : .\techindex.ps1 create-admin'
    }
}

function Show-Urls {
    $port = Get-EnvValue 'APP_PORT'; if (-not $port) { $port = 8080 }
    $mail = Get-EnvValue 'MAILPIT_PORT'; if (-not $mail) { $mail = 8025 }
    Write-Host ''
    Write-Host "TechIndex est disponible sur : http://localhost:$port" -ForegroundColor Green
    Write-Host "E-mails de développement (Mailpit) : http://localhost:$mail" -ForegroundColor Gray
}

function Show-KeysReminder {
    Write-Host ''
    Write-Warn 'Sauvegardez APP_KEY et SECRETS_KEY hors du poste : .\techindex.ps1 keys-export <fichier>'
    Write-Warn 'Sans elles, les secrets enregistrés deviennent définitivement illisibles.'
}

# ---------------------------------------------------------------------------
# Hébergement Plesk (docs/deploiement-plesk.md)
# ---------------------------------------------------------------------------
$PleskDist = Join-Path $Root 'dist\plesk'
$ChannelFile = Join-Path $Root 'config\release-channel.json'
$VersionFile = Join-Path $Root 'VERSION'
$ChangelogFile = Join-Path $Root 'CHANGELOG.md'
$SigningKeyFile = Join-Path $env:USERPROFILE '.techindex\release-signing-key.txt'
$PhpCliImage = 'php:8.5.11-cli-trixie'
$Utf8 = New-Object System.Text.UTF8Encoding($false)

# Fichier texte en UTF-8 sans BOM, fins de ligne LF (comme dans le dépôt).
function Write-TextFile([string] $Path, [string] $Content) {
    [System.IO.File]::WriteAllText($Path, ($Content -replace "`r`n", "`n"), $Utf8)
}

# Fonctions simples (sans bloc param) : les options courtes de git (-q, -m, -a...)
# arrivent intactes dans $args au lieu d'être prises pour des paramètres PowerShell.
function Invoke-GitQuiet {
    $gitArgs = $args
    $ErrorActionPreference = 'Continue'
    $output = & git -C $Root @gitArgs 2>$null
    return [pscustomobject]@{ Output = @($output | ForEach-Object { "$_" }); ExitCode = $LASTEXITCODE }
}

function Invoke-Git {
    $result = Invoke-GitQuiet @args
    if ($result.ExitCode -ne 0) { Fail "git $($args -join ' ') a échoué (code $($result.ExitCode))." }
    return $result.Output
}

# Versions MAJEUR.MINEUR.CORRECTIF[-préversion] : -1, 0 ou 1.
function ConvertTo-SemVer([string] $Value) {
    if ($Value -notmatch '^v?(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?$') { return $null }
    return [pscustomobject]@{ Major = [int] $Matches[1]; Minor = [int] $Matches[2]; Patch = [int] $Matches[3]; Pre = $Matches[4] }
}

function Compare-SemVer([string] $A, [string] $B) {
    $x = ConvertTo-SemVer $A
    $y = ConvertTo-SemVer $B
    foreach ($part in 'Major', 'Minor', 'Patch') { if ($x.$part -ne $y.$part) { return [math]::Sign($x.$part - $y.$part) } }
    if (-not $x.Pre -and -not $y.Pre) { return 0 }
    if (-not $x.Pre) { return 1 }
    if (-not $y.Pre) { return -1 }
    return [math]::Sign([string]::CompareOrdinal($x.Pre, $y.Pre))
}

function Get-ReleaseChannel {
    $channel = [pscustomobject]@{ repository = ''; public_key = '' }
    if (Test-Path $ChannelFile) {
        $data = Get-Content $ChannelFile -Raw -Encoding UTF8 | ConvertFrom-Json
        if ($data.repository) { $channel.repository = [string] $data.repository }
        if ($data.public_key) { $channel.public_key = [string] $data.public_key }
    }
    return $channel
}

function Save-ReleaseChannel($Channel) {
    Write-TextFile $ChannelFile "{`n    `"repository`": `"$($Channel.repository)`",`n    `"public_key`": `"$($Channel.public_key)`"`n}`n"
}

function Get-InstalledVersion { return ([System.IO.File]::ReadAllText($VersionFile)).Trim() }

# Version d'une archive construite sur le poste : préversion « -dev » de la
# prochaine version, pour ne jamais passer devant une version publiée.
function Get-LocalBuildVersion {
    $version = ConvertTo-SemVer (Get-InstalledVersion)
    if (-not $version) { Fail 'Fichier VERSION invalide.' }
    $stamp = Get-Date -Format 'yyyyMMddHHmm'
    $released = (Test-Path (Join-Path $Root '.git')) -and (Invoke-GitQuiet rev-parse -q --verify "refs/tags/v$($version.Major).$($version.Minor).$($version.Patch)").ExitCode -eq 0
    if ($released) { return "$($version.Major).$($version.Minor).$($version.Patch + 1)-dev.$stamp" }
    return "$($version.Major).$($version.Minor).$($version.Patch)-dev.$stamp"
}

function Resolve-DeployTarget {
    if (-not $Target) { $script:Target = Get-EnvValue 'PLESK_DEPLOY_TARGET' }
    if (-not $RemotePathGiven -and (Get-EnvValue 'PLESK_DEPLOY_PATH')) { $script:RemotePath = Get-EnvValue 'PLESK_DEPLOY_PATH' }
    if (-not $Target) { Fail 'Indiquez le compte SSH de l''abonnement Plesk : -Target utilisateur@serveur, ou PLESK_DEPLOY_TARGET dans .env' }
    if ($Target -notmatch '^[A-Za-z0-9._@-]+$') { Fail "Cible SSH invalide : $Target (utilisez un alias de ~/.ssh/config pour un port ou une clé particuliers)" }
    if ($RemotePath -notmatch '^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$' -or $RemotePath -match '\.\.') { Fail "Dossier distant invalide : $RemotePath (chemin relatif au dossier personnel, sans ..)" }
    if (-not (Get-Command ssh -ErrorAction SilentlyContinue)) { Fail 'Client OpenSSH introuvable : Paramètres Windows > Système > Fonctionnalités facultatives > Client OpenSSH.' }
}

# Archive autonome (code, dépendances de production, interface compilée),
# construite dans Docker : ni Composer ni Node.js sur le poste ou le serveur.
function New-PleskPackage {
    Write-Step 'Vérification des prérequis'
    Test-Docker
    $version = Get-LocalBuildVersion
    Write-Step "Construction de l'archive Plesk (version $version)"
    # BuildKit affiche sa progression sur la sortie d'erreur : sous Windows
    # PowerShell 5.1, elle ne doit pas devenir une exception si elle est redirigée.
    $ErrorActionPreference = 'Continue'
    & docker build --target plesk-release --build-arg "TECHINDEX_VERSION=$version" --output "type=local,dest=$PleskDist" . 2>&1 | ForEach-Object { "$_" } | Out-Host
    $code = $LASTEXITCODE
    $ErrorActionPreference = 'Stop'
    if ($code -ne 0) { Fail "La construction de l'archive a échoué (code $code)." }
    $archive = Join-Path $PleskDist "techindex-plesk-$version.tar.gz"
    if (-not (Test-Path $archive)) { Fail "Archive introuvable après la construction : $archive" }
    $size = [math]::Round((Get-Item $archive).Length / 1MB, 1)
    Write-Ok "Archive : $archive ($size Mo)"
    Write-Ok "Empreinte SHA-256 : $archive.sha256"
    Write-Ok "Script serveur : $(Join-Path $PleskDist 'techindex-plesk.sh')"
    return $archive
}

# Mise à jour de la production Plesk par SSH (compte de l'abonnement) :
#  -Release latest|X.Y.Z : le serveur télécharge la version publiée et vérifie sa signature ;
#  sinon : archive construite sur ce poste (ou indiquée), envoyée puis installée (déploiement forcé).
function Invoke-PleskDeploy {
    Resolve-DeployTarget

    if ($Release) {
        $version = $Release.TrimStart('v')
        if ($version -ne 'latest' -and -not (ConvertTo-SemVer $version)) { Fail "Version invalide : $Release (latest ou X.Y.Z)" }
        $channel = Get-ReleaseChannel
        if (-not $channel.repository) { Fail 'Canal des versions non configuré : .\techindex.cmd release-init' }
        $option = if ($version -eq 'latest') { '' } else { " --version $version" }
        $download = if ($version -eq 'latest') { "https://github.com/$($channel.repository)/releases/latest/download" } else { "https://github.com/$($channel.repository)/releases/download/v$version" }
        Write-Step "Mise à jour de $Target : version publiée $version"
        # Première installation : le script d'exploitation est d'abord récupéré dans la publication.
        $remote = "mkdir -p $RemotePath && cd $RemotePath && if [ -d app ]; then ./techindex-plesk.sh update$option; else curl -fsSL --proto =https -o techindex-plesk.sh $download/techindex-plesk.sh && chmod 700 techindex-plesk.sh && ./techindex-plesk.sh install$option; fi"
        & ssh -t $Target $remote
        if ($LASTEXITCODE -ne 0) { Fail "L'opération a échoué sur le serveur (voir les messages ci-dessus)." }
        return
    }

    if (-not (Get-Command scp -ErrorAction SilentlyContinue)) { Fail 'Client OpenSSH (scp) introuvable : Paramètres Windows > Système > Fonctionnalités facultatives > Client OpenSSH.' }
    Write-Warn 'Déploiement forcé d''une archive construite sur ce poste (non publiée sur GitHub).'

    if ($Arguments.Count -ge 1) {
        $archive = (Resolve-Path $Arguments[0]).Path
        $script = Join-Path (Split-Path $archive) 'techindex-plesk.sh'
    } else {
        $archive = New-PleskPackage
        $script = Join-Path $PleskDist 'techindex-plesk.sh'
    }
    $name = Split-Path $archive -Leaf
    if ($name -notmatch '^techindex-plesk-[A-Za-z0-9._-]+\.tar\.gz$') { Fail "Nom d'archive inattendu : $name" }
    foreach ($file in $archive, "$archive.sha256", $script) { if (-not (Test-Path $file)) { Fail "Fichier introuvable : $file" } }

    Write-Step "Envoi vers ${Target} (dossier $RemotePath/archives)"
    & ssh $Target "mkdir -p $RemotePath/archives && chmod 700 $RemotePath/archives"
    if ($LASTEXITCODE -ne 0) { Fail "Connexion SSH impossible ($Target) : vérifiez l'accès SSH de l'abonnement (docs/deploiement-plesk.md)." }
    & scp $archive "$archive.sha256" $script "${Target}:$RemotePath/archives/"
    if ($LASTEXITCODE -ne 0) { Fail 'Envoi des fichiers impossible.' }
    Write-Ok "$name envoyé"

    Write-Step 'Installation ou mise à jour sur le serveur'
    $remote = "cd $RemotePath && if [ -d app ]; then action=update; else action=install; fi && TECHINDEX_HOME=`$(pwd -P) bash archives/techindex-plesk.sh `$action archives/$name"
    & ssh -t $Target $remote
    if ($LASTEXITCODE -ne 0) { Fail "L'opération a échoué sur le serveur (voir les messages ci-dessus)." }
}

# ---------------------------------------------------------------------------
# Git et publication des versions (docs/publication-et-mises-a-jour.md)
# ---------------------------------------------------------------------------
function Test-Git {
    if (-not (Get-Command git -ErrorAction SilentlyContinue)) { Fail 'Git n''est pas installé : https://git-scm.com/download/win' }
}

# Fichiers qui ne doivent jamais partir sur GitHub, même s'ils échappaient au .gitignore.
function Assert-NothingSecretStaged {
    $staged = Invoke-Git diff --cached --name-only
    $forbidden = @($staged | Where-Object {
        $_ -match '(^|/)\.env$' -or ($_ -match '(^|/)\.env\.' -and $_ -notmatch '\.env\.(example|production\.example|plesk\.example)$') `
            -or $_ -match '\.(key|pem|age)$' -or $_ -match '(^|/)(age-key|techindex-cles)' `
            -or ($_ -match '^(backups|dist|storage/app/private)/' -and $_ -notmatch '/\.gitignore$')
    })
    if ($forbidden.Count -gt 0) {
        Invoke-Git reset --quiet | Out-Null
        Fail "Fichiers sensibles prêts à être publiés, opération annulée : $($forbidden -join ', ')"
    }
}

# Une seule fois : dépôt Git local, dépôt GitHub (propriétaire/nom), clé de signature.
function Initialize-Release {
    Test-Git
    Write-Step 'Dépôt Git local'
    if (-not (Test-Path (Join-Path $Root '.git'))) {
        Invoke-Git init -b main | Out-Null
        Write-Ok 'Dépôt créé (branche main)'
    } else {
        Write-Ok 'Dépôt existant'
    }
    $name = (Invoke-GitQuiet config user.name).Output -join ''
    $email = (Invoke-GitQuiet config user.email).Output -join ''
    if (-not $name -or -not $email) {
        Fail "Identité Git absente. Exécutez : git config --global user.name `"Prénom Nom`" puis git config --global user.email `"vous@exemple.fr`""
    }
    Write-Ok "Auteur des commits : $name <$email>"

    $channel = Get-ReleaseChannel
    $repo = if ($Repository) { $Repository } elseif ($channel.repository) { $channel.repository } else { Read-Host '    Dépôt GitHub public (propriétaire/nom, ex. gael/techindex)' }
    if ($repo -notmatch '^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$') { Fail "Dépôt invalide : $repo (attendu : propriétaire/nom)" }

    Write-Step 'Clé de signature des versions'
    if ($channel.public_key) {
        Write-Ok 'Clé publique déjà présente dans config/release-channel.json : conservée'
        if (-not (Test-Path $SigningKeyFile)) { Write-Warn "Clé privée absente de ce poste ($SigningKeyFile) : la publication ne dépend que du secret GitHub." }
    } else {
        if (Test-Path $SigningKeyFile) { Fail "Une clé privée existe déjà ($SigningKeyFile) sans clé publique dans config/release-channel.json : retrouvez la clé publique correspondante, ou déplacez ce fichier." }
        Test-Docker
        $keys = Invoke-DockerQuiet run --rm --volume "$Root\scripts:/scripts:ro" $PhpCliImage php /scripts/release-tool.php keygen
        $secret = ($keys.Output | Where-Object { $_ -like 'SECRET_KEY=*' }) -replace '^SECRET_KEY=', ''
        $public = ($keys.Output | Where-Object { $_ -like 'PUBLIC_KEY=*' }) -replace '^PUBLIC_KEY=', ''
        if ($keys.ExitCode -ne 0 -or -not $secret -or -not $public) { Fail 'Génération de la paire de clés impossible (Docker).' }
        New-Item -ItemType Directory -Force -Path (Split-Path $SigningKeyFile) | Out-Null
        Write-TextFile $SigningKeyFile "$secret`n"
        # Lisible par le seul compte Windows courant.
        & icacls $SigningKeyFile /inheritance:r /grant:r "${env:USERNAME}:(R,W)" | Out-Null
        $channel.public_key = $public
        Write-Ok "Clé privée : $SigningKeyFile (secret GitHub TECHINDEX_SIGNING_KEY ; à conserver aussi dans votre coffre)"
    }
    $channel.repository = $repo
    Save-ReleaseChannel $channel
    Write-Ok "Canal des versions : $repo (config/release-channel.json)"

    Write-Step 'Dépôt distant'
    $remotes = @(Invoke-Git remote)
    if ($remotes -notcontains 'origin') {
        Invoke-Git remote add origin "https://github.com/$repo.git" | Out-Null
        Write-Ok "origin = https://github.com/$repo.git"
    } else {
        Write-Ok "origin = $((Invoke-Git remote get-url origin) -join '')"
    }

    Write-Step 'Commit'
    Invoke-Git add -A | Out-Null
    Assert-NothingSecretStaged
    if ((Invoke-GitQuiet rev-parse --verify HEAD).ExitCode -ne 0) {
        Invoke-Git commit --quiet -m 'Version initiale de TechIndex' | Out-Null
        Write-Ok 'Premier commit créé'
    } elseif (@(Invoke-Git diff --cached --name-only).Count -gt 0) {
        Invoke-Git commit --quiet -m "Canal des versions : $repo" | Out-Null
        Write-Ok 'Configuration enregistrée (commit)'
    } else {
        Write-Ok 'Rien à enregistrer'
    }

    Write-Step 'À faire sur GitHub (docs/publication-et-mises-a-jour.md)'
    Write-Host "    1. Créez le dépôt PUBLIC $repo, vide (sans README ni licence) : https://github.com/new"
    Write-Host "    2. Settings > Secrets and variables > Actions > New repository secret :"
    Write-Host "       nom TECHINDEX_SIGNING_KEY, valeur = contenu de $SigningKeyFile"
    Write-Host '    3. Envoyez le code : git push -u origin main'
    Write-Host "    4. Publiez la première version : .\techindex.cmd release $(Get-InstalledVersion)"
    $answer = if ($Yes) { 'n' } else { Read-Host '    Le dépôt GitHub est-il créé ? Envoyer le code maintenant (git push) ? (o/N)' }
    if ($answer -match '^[oOyY]') {
        & git -C $Root push -u origin main
        if ($LASTEXITCODE -ne 0) { Fail 'Envoi impossible : vérifiez que le dépôt existe et que vous êtes connecté à GitHub.' }
        Write-Ok "Code envoyé : https://github.com/$repo"
    }
}

# Section « Non publié » du journal (texte), ou messages des commits depuis la version précédente.
function Get-ReleaseNotes([string] $LastTag) {
    $content = ([System.IO.File]::ReadAllText($ChangelogFile)) -replace "`r`n", "`n"
    $start = $content.IndexOf('## [Non publié]')
    if ($start -lt 0) { Fail 'Section « ## [Non publié] » absente de CHANGELOG.md.' }
    $bodyStart = $content.IndexOf("`n", $start) + 1
    $next = $content.IndexOf("`n## [", $bodyStart)
    $body = if ($next -lt 0) { $content.Substring($bodyStart) } else { $content.Substring($bodyStart, $next - $bodyStart) }
    $notes = $body.Trim()
    if (-not $notes) {
        $range = if ($LastTag) { "$LastTag..HEAD" } else { 'HEAD' }
        $notes = (@(Invoke-Git log --no-merges '--format=- %s' $range) | Where-Object { $_ -notmatch '^- Version \d' }) -join "`n"
    }
    return [pscustomobject]@{ Notes = $notes.Trim(); Content = $content; Start = $start; Next = $next }
}

# Attend que GitHub Actions ait publié la version (manifeste signé disponible).
function Wait-PublishedRelease([string] $Repo, [string] $Version) {
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    $url = "https://github.com/$Repo/releases/download/v$Version/release.json"
    $temporary = [System.IO.Path]::GetTempFileName()
    Write-Step "Attente de la publication par GitHub Actions (au plus 30 minutes) : https://github.com/$Repo/actions"
    for ($i = 0; $i -lt 90; $i++) {
        try {
            Invoke-WebRequest -Uri $url -OutFile $temporary -UseBasicParsing -TimeoutSec 20 | Out-Null
            Remove-Item $temporary -ErrorAction SilentlyContinue
            Write-Ok "Version $Version publiée"
            return
        } catch {
            Start-Sleep -Seconds 20
        }
    }
    Remove-Item $temporary -ErrorAction SilentlyContinue
    Fail "Version $Version toujours absente après 30 minutes : consultez https://github.com/$Repo/actions"
}

# Publication : contrôles, tests, VERSION, CHANGELOG.md, commit, étiquette vX.Y.Z,
# envoi sur GitHub. GitHub Actions construit, signe et publie ensuite la version.
function Publish-Release {
    Test-Git
    if (-not (Test-Path (Join-Path $Root '.git'))) { Fail 'Dépôt Git absent : .\techindex.cmd release-init' }
    $channel = Get-ReleaseChannel
    if (-not $channel.repository -or -not $channel.public_key) { Fail 'Canal des versions non configuré : .\techindex.cmd release-init' }

    Write-Step 'Contrôles Git'
    $branch = (Invoke-Git rev-parse --abbrev-ref HEAD) -join ''
    if ($branch -ne 'main') { Fail "Les versions se publient depuis main (branche actuelle : $branch) : fusionnez votre travail, puis git switch main" }
    if (@(Invoke-Git status --porcelain).Count -gt 0) { Fail 'Des modifications ne sont pas enregistrées : git add -A puis git commit -m "Description", ou git stash.' }
    & git -C $Root fetch --quiet --tags origin
    if ($LASTEXITCODE -ne 0) { Fail 'Impossible de joindre GitHub (git fetch) : connexion, ou dépôt pas encore créé (release-init).' }
    if ((Invoke-GitQuiet rev-parse -q --verify origin/main).ExitCode -eq 0) {
        $behind = [int] ((Invoke-Git rev-list --count 'HEAD..origin/main') -join '')
        if ($behind -gt 0) { Fail "main a $behind commit(s) de retard sur GitHub : git pull --rebase" }
    }
    Write-Ok 'Branche main propre et à jour'

    $lastTag = (Invoke-GitQuiet describe --tags --abbrev=0 --match 'v[0-9]*').Output -join ''
    $current = Get-InstalledVersion
    $bump = if ($Arguments.Count -ge 1) { $Arguments[0].TrimStart('v') } else { 'patch' }
    $base = ConvertTo-SemVer $current
    if (-not $base) { Fail "Fichier VERSION invalide : $current" }
    $new = switch ($bump) {
        'major' { "$($base.Major + 1).0.0" }
        'minor' { "$($base.Major).$($base.Minor + 1).0" }
        'patch' { if ($lastTag) { "$($base.Major).$($base.Minor).$($base.Patch + 1)" } else { "$($base.Major).$($base.Minor).$($base.Patch)" } }
        default { $bump }
    }
    if (-not (ConvertTo-SemVer $new)) { Fail "Version invalide : $bump (patch, minor, major ou X.Y.Z)" }
    if ($lastTag -and (Compare-SemVer $new $lastTag) -le 0) { Fail "La version $new doit être supérieure à la dernière publiée ($lastTag)." }
    if ((Invoke-GitQuiet rev-parse -q --verify "refs/tags/v$new").ExitCode -eq 0) { Fail "L'étiquette v$new existe déjà." }

    $release = Get-ReleaseNotes $lastTag
    if (-not $release.Notes) { Fail "Aucune modification depuis $lastTag." }
    $migrations = @(if ($lastTag) { Invoke-Git diff --name-only "$lastTag" HEAD '--' database/migrations } else { '(première version)' })

    Write-Step "Version $new (précédente : $(if ($lastTag) { $lastTag } else { 'aucune' }))"
    Write-Host ($release.Notes -replace '(?m)^', '    ')
    if ($migrations.Count -gt 0) { Write-Warn "Modifie la base de données : $($migrations -join ', ')" } else { Write-Ok 'Aucune migration de base de données' }

    if (-not $SkipTests) {
        Write-Step 'Tests automatisés'
        if (-not (Test-Path $EnvFile)) { Fail 'Environnement Docker de développement absent (.\techindex.cmd install), ou relancez avec -SkipTests (les tests tournent aussi sur GitHub).' }
        Wait-Database
        Invoke-Compose -ComposeArgs @('run', '--rm', '-T', 'app', 'php', 'artisan', 'test')
    }

    $answer = if ($Yes) { 'o' } else { Read-Host "    Publier la version $new sur GitHub ? (o/N)" }
    if ($answer -notmatch '^[oOyY]') { Write-Warn 'Publication annulée : rien n''a été modifié.'; return }

    Write-Step 'Commit et étiquette'
    $date = Get-Date -Format 'yyyy-MM-dd'
    $head = $release.Content.Substring(0, $release.Start)
    $tail = if ($release.Next -lt 0) { '' } else { $release.Content.Substring($release.Next + 1) }
    Write-TextFile $ChangelogFile (($head + "## [Non publié]`n`n## [$new] - $date`n`n" + $release.Notes + "`n`n" + $tail).TrimEnd() + "`n")
    Write-TextFile $VersionFile "$new`n"
    Invoke-Git add VERSION CHANGELOG.md | Out-Null
    Invoke-Git commit --quiet -m "Version $new" | Out-Null
    Invoke-Git tag -a "v$new" -m "TechIndex $new" | Out-Null
    Write-Ok "Commit « Version $new » et étiquette v$new"

    Write-Step 'Envoi sur GitHub'
    & git -C $Root push --atomic origin main "v$new"
    if ($LASTEXITCODE -ne 0) { Fail "Envoi impossible. Le commit et l'étiquette sont prêts localement : git push --atomic origin main v$new" }
    Write-Ok "Publication en cours : https://github.com/$($channel.repository)/actions"
    Write-Host "    Les installations la proposeront dès la fin de la publication (Administration > Mises à jour)." -ForegroundColor Gray

    if ($Deploy) {
        Wait-PublishedRelease $channel.repository $new
        $script:Release = $new
        Invoke-PleskDeploy
    } else {
        Write-Host "    Mise à jour de la production : .\techindex.cmd plesk-deploy -Release $new" -ForegroundColor Gray
    }
}

# ---------------------------------------------------------------------------
# Commandes
# ---------------------------------------------------------------------------
switch ($Command.ToLowerInvariant()) {
    'install' {
        Test-Prerequisites
        Initialize-Configuration
        Write-Step 'Construction des images'
        Invoke-Compose -ComposeArgs @('build')
        Wait-Database
        Install-Dependencies
        Initialize-Keys
        Initialize-Database
        Write-Step 'Démarrage des services'
        Invoke-Compose -ComposeArgs @('up', '-d', '--remove-orphans')
        Initialize-Admin
        Show-KeysReminder
        Show-Urls
    }
    'start' {
        if ($ExposeDatabase) {
            New-Item -ItemType File -Path (Join-Path $Root '.techindex-expose-db') -Force | Out-Null
            Write-Warn "MariaDB accessible sur 127.0.0.1:$(if (Get-EnvValue 'DB_FORWARD_PORT') { Get-EnvValue 'DB_FORWARD_PORT' } else { 3307 }) (pour le désactiver : supprimez le fichier .techindex-expose-db puis relancez start)"
        }
        Invoke-Compose -ComposeArgs @('up', '-d', '--remove-orphans')
        Show-Urls
    }
    'stop' { Invoke-Compose -ComposeArgs @('stop'); Write-Ok 'Services arrêtés (données conservées)' }
    'restart' { Invoke-Compose -ComposeArgs @('restart'); Show-Urls }
    'status' { Invoke-Compose -ComposeArgs @('ps') }
    'logs' {
        $logArgs = @('logs', '--tail', '200')
        if ($Follow) { $logArgs += '--follow' }
        Invoke-Compose -ComposeArgs ($logArgs + $Arguments)
    }
    'update' {
        Test-Prerequisites
        Write-Step 'Sauvegarde préalable'
        Invoke-Artisan techindex:backup
        Initialize-Configuration
        Invoke-Compose -ComposeArgs @('build', '--pull')
        Wait-Database
        Install-Dependencies
        Initialize-Keys
        Initialize-Database
        Invoke-Compose -ComposeArgs @('up', '-d', '--remove-orphans')
        Invoke-Compose -ComposeArgs @('restart', 'worker', 'scheduler')
        Show-Urls
    }
    'backup' {
        Invoke-Artisan techindex:backup
        Write-Host '    Les archives sont dans le dossier .\backups : copiez-les hors du poste.' -ForegroundColor Gray
    }
    'verify-backup' {
        if ($Arguments.Count -lt 1) { Fail 'Indiquez l''archive : .\techindex.ps1 verify-backup .\backups\<archive>' }
        $name = Split-Path $Arguments[0] -Leaf
        $verifyArgs = @('techindex:backup-verify', "/var/www/html/backups/$name")
        if ($Identity) { $verifyArgs += "--identity=/var/www/html/backups/$(Split-Path $Identity -Leaf)" }
        Invoke-Artisan @verifyArgs
    }
    'restore' {
        if ($Arguments.Count -lt 1) { Fail 'Indiquez l''archive (placée dans .\backups) : .\techindex.ps1 restore .\backups\<archive>' }
        $name = Split-Path $Arguments[0] -Leaf
        if (-not (Test-Path (Join-Path $Root "backups\$name"))) { Fail "Archive introuvable dans .\backups : $name" }
        $restoreArgs = @('techindex:restore', "/var/www/html/backups/$name")
        if ($Identity) { $restoreArgs += "--identity=/var/www/html/backups/$(Split-Path $Identity -Leaf)" }
        if ($Yes) { $restoreArgs += '--force' }
        Invoke-ArtisanInteractive @restoreArgs
    }
    'keys-export' {
        if ($Arguments.Count -lt 1) { Fail 'Indiquez le fichier de destination (clé USB, coffre...) : .\techindex.ps1 keys-export E:\techindex-cles.txt' }
        $target = $Arguments[0]
        $lines = @('# Clés de chiffrement TechIndex – exportées le ' + (Get-Date -Format 'yyyy-MM-dd HH:mm'), '# À conserver hors du poste et hors des sauvegardes (coffre, gestionnaire de mots de passe).')
        foreach ($key in 'APP_KEY', 'APP_PREVIOUS_KEYS', 'SECRETS_KEY', 'SECRETS_PREVIOUS_KEYS') { $lines += "$key=$(Get-EnvValue $key)" }
        [System.IO.File]::WriteAllLines($target, $lines, (New-Object System.Text.UTF8Encoding($false)))
        Write-Ok "Clés exportées dans $target"
        Write-Warn 'Ce fichier donne accès à tous les secrets : stockez-le en lieu sûr puis supprimez les copies inutiles.'
    }
    'demo' { Invoke-Artisan techindex:demo }
    'create-admin' { Invoke-ArtisanInteractive techindex:create-admin }
    'doctor' { Invoke-Artisan techindex:doctor }
    'test' {
        Wait-Database
        Invoke-Compose -ComposeArgs @('run', '--rm', '-T', 'app', 'php', 'artisan', 'test')
    }
    'artisan' { Invoke-ArtisanInteractive @Arguments }
    'dev' {
        Write-Host 'Vite démarre avec rechargement à chaud (Ctrl+C pour arrêter).' -ForegroundColor Gray
        Invoke-Compose -ComposeArgs @('--profile', 'hmr', 'up', 'vite')
    }
    'plesk-package' { New-PleskPackage | Out-Null }
    'plesk-deploy' { Invoke-PleskDeploy }
    'release-init' { Initialize-Release }
    'release' { Publish-Release }
    default { Get-Help $PSCommandPath -Detailed; exit 1 }
}
