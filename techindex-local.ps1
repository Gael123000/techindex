<#
.SYNOPSIS
    TechIndex – environnement local SANS Docker, pour tester l'application sur le poste Windows.

.DESCRIPTION
    Installe, sans droits administrateur ni modification du PATH du système,
    dans %USERPROFILE%\TechIndex-local (hors du projet) :
      - PHP 8.5     : serveur web intégré de PHP et file d'attente ;
      - MariaDB 11.8 : base de données, écoute uniquement sur 127.0.0.1 ;
      - Node.js 24 et Composer 2 : dépendances et compilation de l'interface.
    Les archives sont téléchargées depuis les sites officiels et vérifiées
    avec les empreintes SHA-256 figées dans ce script.

    Totalement séparé de l'installation Docker :
      - configuration dans .env.local (le .env de Docker n'est ni lu ni modifié) ;
      - base de données, journaux et pièces jointes dans %USERPROFILE%\TechIndex-local ;
      - ports différents : application http://127.0.0.1:8000, MariaDB 127.0.0.1:3308.
    Les e-mails (réinitialisation de mot de passe...) sont écrits dans le journal
    de l'application : .\techindex-local.cmd logs app

    Commandes :
      install          Installe ou répare (relançable sans risque), puis démarre
      start            Démarre MariaDB, le serveur web et la file d'attente
      stop | restart | status
      logs [web|worker|mariadb|app] [-Follow]
      test [options]   Tests automatisés (base techindex_test)
      update           Dépendances, recompilation de l'interface, migrations
      create-admin     Crée un compte administrateur (mot de passe saisi masqué)
      demo             Charge les données de démonstration fictives
      artisan ...      Exécute une commande Artisan dans cet environnement

.EXAMPLE
    .\techindex-local.cmd install
    .\techindex-local.cmd create-admin
    .\techindex-local.cmd test --filter=ClientAccessTest
    .\techindex-local.cmd logs app -Follow
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [string] $Command = 'help',

    [Parameter(Position = 1, ValueFromRemainingArguments = $true)]
    [string[]] $Arguments = @(),

    [switch] $Follow,

    # Ne pas proposer la création d'un administrateur à la fin de l'installation.
    [switch] $NoAdmin
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12

$Root = $PSScriptRoot
Set-Location $Root

# ---------------------------------------------------------------------------
# Emplacements (aucun chemin propre à un poste : tout dérive de %USERPROFILE%).
# Pas %LOCALAPPDATA% : pour une application empaquetée (MSIX), Windows y
# redirige les écritures vers un espace privé invisible des autres processus.
# ---------------------------------------------------------------------------
$LocalHome = if ($env:TECHINDEX_LOCAL_HOME) { $env:TECHINDEX_LOCAL_HOME } else { Join-Path $env:USERPROFILE 'TechIndex-local' }
$Downloads = Join-Path $LocalHome 'downloads'
$Runtime = Join-Path $LocalHome 'runtime'
$DataDir = Join-Path $LocalHome 'data\mariadb'
$StorageDir = Join-Path $LocalHome 'storage'
$LogDir = Join-Path $LocalHome 'logs'
$RunDir = Join-Path $LocalHome 'run'
$BackupDir = Join-Path $LocalHome 'backups'
$RootPasswordFile = Join-Path $LocalHome 'mariadb-root.txt'
$EnvFile = Join-Path $Root '.env.local'

# Versions figées et empreintes SHA-256 officielles (vérifiées le 14/10/2026).
$PhpPackage = @{
    Name = 'PHP 8.5.11'; File = 'php-8.5.11-nts-Win32-vs17-x64.zip'; Inner = $null
    Sha256 = '0ea96e0d2b9b737a6036f05cf4e95c49313faa6d0f27bd97edb2742503f0c043'
    Urls = @('https://windows.php.net/downloads/releases/php-8.5.11-nts-Win32-vs17-x64.zip',
             'https://windows.php.net/downloads/releases/archives/php-8.5.11-nts-Win32-vs17-x64.zip')
}
$MariaDbPackage = @{
    Name = 'MariaDB 11.8.9'; File = 'mariadb-11.8.9-winx64.zip'; Inner = 'mariadb-11.8.9-winx64'
    Sha256 = '830c46727d9278eae212ae3eca44eeb9e71b2a68704e95f344a64fba7b1963f5'
    Urls = @('https://archive.mariadb.org/mariadb-11.8.9/winx64-packages/mariadb-11.8.9-winx64.zip')
}
$NodePackage = @{
    Name = 'Node.js 24.21.0'; File = 'node-v24.21.0-win-x64.zip'; Inner = 'node-v24.21.0-win-x64'
    Sha256 = '158f7685b44de51f6c0df1d153526cbcd3e1bc739a8dfc607721cef75de9e541'
    Urls = @('https://nodejs.org/dist/v24.21.0/node-v24.21.0-win-x64.zip')
}
$ComposerPackage = @{
    Name = 'Composer 2.10.3'; File = 'composer-2.10.3.phar'
    Sha256 = '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6'
    Urls = @('https://getcomposer.org/download/2.10.3/composer.phar')
}

$PhpDir = Join-Path $Runtime 'php-8.5.11'
$PhpExe = Join-Path $PhpDir 'php.exe'
$MariaDbDir = Join-Path $Runtime 'mariadb-11.8.9'
$MariaDbBin = Join-Path $MariaDbDir 'bin'
$NodeDir = Join-Path $Runtime 'node-24.21.0'
$ComposerPhar = Join-Path $Runtime $ComposerPackage.File

# Ports par défaut (modifiables ensuite dans .env.local : APP_URL et DB_PORT).
$DefaultWebPort = 8000
$DefaultDbPort = 3308

# ---------------------------------------------------------------------------
# Affichage
# ---------------------------------------------------------------------------
function Write-Step([string] $Message) { Write-Host ''; Write-Host "==> $Message" -ForegroundColor Cyan }
function Write-Ok([string] $Message) { Write-Host "    [OK] $Message" -ForegroundColor Green }
function Write-Warn([string] $Message) { Write-Host "    [!]  $Message" -ForegroundColor Yellow }
function Fail([string] $Message) { Write-Host ''; Write-Host "ERREUR : $Message" -ForegroundColor Red; exit 1 }

# ---------------------------------------------------------------------------
# Fichiers
# ---------------------------------------------------------------------------
function Write-Utf8NoBom([string] $Path, [string[]] $Lines) {
    [System.IO.File]::WriteAllLines($Path, $Lines, (New-Object System.Text.UTF8Encoding($false)))
}

function New-RandomSecret([int] $Length = 32) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'.ToCharArray()
    $bytes = New-Object byte[] $Length
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $rng.GetBytes($bytes)
    return -join ($bytes | ForEach-Object { $alphabet[$_ % $alphabet.Length] })
}

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
    Write-Utf8NoBom $EnvFile $lines
}

function Get-WebPort {
    $url = Get-EnvValue 'APP_URL'
    if ($url) { try { return ([uri] $url).Port } catch { } }
    return $DefaultWebPort
}

function Get-DbPort {
    $port = Get-EnvValue 'DB_PORT'
    if ($port) { return [int] $port }
    return $DefaultDbPort
}

# ---------------------------------------------------------------------------
# Environnement des processus lancés par ce script
# ---------------------------------------------------------------------------
function Set-LocalEnvironment {
    # APP_ENV=local : Laravel charge .env.local au lieu de .env (Docker).
    $env:APP_ENV = 'local'
    $env:LARAVEL_STORAGE_PATH = $StorageDir
    $env:COMPOSER_HOME = Join-Path $LocalHome 'composer'
    $env:PATH = "$PhpDir;$MariaDbBin;$NodeDir;" + $env:PATH
}

# Commande native dont on ne garde que la sortie standard et le code retour
# (Windows PowerShell 5.1 transforme sinon les lignes d'erreur en exceptions).
function Invoke-Quiet {
    param([string] $Exe, [string[]] $ExeArgs = @(), [string] $InputText = $null)
    $ErrorActionPreference = 'Continue'
    if ($null -ne $InputText -and $InputText -ne '') { $output = $InputText | & $Exe @ExeArgs 2>&1 } else { $output = & $Exe @ExeArgs 2>&1 }
    return [pscustomobject]@{ Output = @($output | ForEach-Object { "$_" }); ExitCode = $LASTEXITCODE }
}

function Invoke-Php {
    param([Parameter(ValueFromRemainingArguments = $true)] [string[]] $PhpArgs)
    & $PhpExe @PhpArgs
    if ($LASTEXITCODE -ne 0) { Fail "php $($PhpArgs -join ' ') a échoué (code $LASTEXITCODE)." }
}

function Invoke-Artisan {
    param([Parameter(ValueFromRemainingArguments = $true)] [string[]] $ArtisanArgs)
    $all = @('artisan') + $ArtisanArgs
    Invoke-Php @all
}

# ---------------------------------------------------------------------------
# Prérequis
# ---------------------------------------------------------------------------
function Test-Port([int] $Port) {
    $client = New-Object System.Net.Sockets.TcpClient
    try { $client.Connect('127.0.0.1', $Port); return $true } catch { return $false } finally { $client.Dispose() }
}

function Test-Prerequisites {
    Write-Step 'Vérification des prérequis'
    if (-not [Environment]::Is64BitOperatingSystem) { Fail 'Windows 64 bits est requis.' }
    foreach ($dll in 'vcruntime140.dll', 'vcruntime140_1.dll') {
        if (-not (Test-Path (Join-Path $env:SystemRoot "System32\$dll"))) {
            Fail "Le runtime Visual C++ 2015-2022 (x64) est requis par PHP et MariaDB : https://aka.ms/vs/17/release/vc_redist.x64.exe"
        }
    }
    Write-Ok 'Windows 64 bits et runtime Visual C++ présents'
    foreach ($p in @(@{ Name = 'mariadb'; Port = (Get-DbPort) }, @{ Name = 'web'; Port = (Get-WebPort) })) {
        if ((Test-Port $p.Port) -and -not (Test-ServiceRunning $p.Name)) {
            Fail "Le port $($p.Port) est déjà utilisé par un autre programme (modifiez $(if ($p.Name -eq 'web') { 'APP_URL' } else { 'DB_PORT' }) dans .env.local)."
        }
    }
    Write-Ok "Ports $(Get-WebPort) (application) et $(Get-DbPort) (MariaDB) disponibles"
}

# ---------------------------------------------------------------------------
# Téléchargement et installation des outils
# ---------------------------------------------------------------------------
function Get-VerifiedDownload([hashtable] $Package) {
    $target = Join-Path $Downloads $Package.File
    if ((Test-Path $target) -and (Get-FileHash $target -Algorithm SHA256).Hash -eq $Package.Sha256) { return $target }

    New-Item -ItemType Directory -Force -Path $Downloads | Out-Null
    $partial = "$target.part"
    foreach ($url in $Package.Urls) {
        Write-Host "    Téléchargement de $($Package.Name) : $url" -ForegroundColor Gray
        try {
            Invoke-WebRequest -UseBasicParsing -Uri $url -OutFile $partial
        } catch {
            Write-Warn "Échec du téléchargement : $($_.Exception.Message)"
            continue
        }
        if ((Get-FileHash $partial -Algorithm SHA256).Hash -ne $Package.Sha256) {
            Remove-Item -LiteralPath $partial -Force
            Fail "Empreinte SHA-256 inattendue pour $($Package.File) : fichier rejeté."
        }
        Move-Item -LiteralPath $partial -Destination $target -Force
        return $target
    }
    Fail "Impossible de télécharger $($Package.Name). Vérifiez l'accès à Internet puis relancez."
}

function Install-Package([hashtable] $Package, [string] $Destination) {
    $marker = Join-Path $Destination '.techindex-ok'
    if (Test-Path $marker) { Write-Ok "$($Package.Name) déjà installé"; return }

    $zip = Get-VerifiedDownload $Package
    New-Item -ItemType Directory -Force -Path $Runtime | Out-Null
    $temp = Join-Path $Runtime ('_extraction-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
    New-Item -ItemType Directory -Path $temp | Out-Null

    $tar = Join-Path $env:SystemRoot 'System32\tar.exe'
    if (Test-Path $tar) {
        & $tar -xf $zip -C $temp
        if ($LASTEXITCODE -ne 0) { Fail "Extraction impossible : $zip" }
    } else {
        Add-Type -AssemblyName System.IO.Compression.FileSystem
        [System.IO.Compression.ZipFile]::ExtractToDirectory($zip, $temp)
    }

    # Une installation interrompue (sans marqueur) est refaite entièrement.
    if (Test-Path $Destination) { Remove-Item -LiteralPath $Destination -Recurse -Force }
    if ($Package.Inner) {
        Move-Item -LiteralPath (Join-Path $temp $Package.Inner) -Destination $Destination
        Remove-Item -LiteralPath $temp -Recurse -Force
    } else {
        Move-Item -LiteralPath $temp -Destination $Destination
    }
    New-Item -ItemType File -Path $marker | Out-Null
    Write-Ok "$($Package.Name) installé"
}

function Install-Runtimes {
    Write-Step "Outils locaux (dans $LocalHome)"
    foreach ($dir in $LocalHome, $Runtime, $LogDir, $RunDir, $BackupDir) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
    Install-Package $PhpPackage $PhpDir
    Install-Package $MariaDbPackage $MariaDbDir
    Install-Package $NodePackage $NodeDir
    if (-not (Test-Path $ComposerPhar)) {
        Copy-Item -LiteralPath (Get-VerifiedDownload $ComposerPackage) -Destination $ComposerPhar
        Write-Ok "$($ComposerPackage.Name) installé"
    } else {
        Write-Ok "$($ComposerPackage.Name) déjà installé"
    }
    Initialize-PhpConfig
}

function Initialize-PhpConfig {
    $ini = Join-Path $PhpDir 'php.ini'
    if (-not (Test-Path $ini)) {
        Write-Utf8NoBom $ini @(
            '; Généré par techindex-local.ps1 : configuration PHP de l''environnement local TechIndex.',
            "extension_dir = `"$(Join-Path $PhpDir 'ext')`"",
            'extension=curl', 'extension=fileinfo', 'extension=intl', 'extension=mbstring',
            'extension=openssl', 'extension=pdo_mysql', 'extension=sodium', 'extension=zip',
            'memory_limit = 512M', 'max_execution_time = 120', 'max_input_time = 120', 'max_input_vars = 5000',
            '; Pièces jointes (20 Mo côté application) et imports.',
            'upload_max_filesize = 25M', 'post_max_size = 26M',
            'date.timezone = UTC', 'default_charset = "UTF-8"', 'expose_php = Off',
            'error_reporting = E_ALL', 'display_errors = Off', 'display_startup_errors = Off', 'log_errors = On',
            'variables_order = "GPCS"', 'request_order = "GP"',
            'realpath_cache_size = 4096K', 'realpath_cache_ttl = 600',
            '; Cache du code compilé, revalidé à chaque requête (les modifications sont prises en compte immédiatement).',
            'opcache.enable = 1', 'opcache.enable_cli = 1', 'opcache.validate_timestamps = 1', 'opcache.revalidate_freq = 0',
            'session.use_strict_mode = 1', 'session.cookie_httponly = 1'
        )
        Write-Ok 'php.ini créé'
    }
    $modules = (Invoke-Quiet $PhpExe @('-m')).Output
    $missing = @('curl', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'sodium', 'zip') | Where-Object { $modules -notcontains $_ }
    if ($missing) { Fail "Extensions PHP non chargées : $($missing -join ', ')." }
    Write-Ok 'Extensions PHP chargées'
}

# ---------------------------------------------------------------------------
# Configuration de l'application (.env.local)
# ---------------------------------------------------------------------------
function Initialize-LocalConfiguration {
    Write-Step 'Configuration locale (.env.local)'
    if (-not (Test-Path $EnvFile)) {
        $overrides = [ordered]@{
            APP_ENV = 'local'
            APP_URL = "http://127.0.0.1:$DefaultWebPort"
            DB_HOST = '127.0.0.1'
            DB_PORT = "$DefaultDbPort"
            MAIL_MAILER = 'log'
            BACKUP_PATH = $BackupDir
        }
        $source = [System.IO.File]::ReadAllLines((Join-Path $Root '.env.example'))
        # L'en-tête de .env.example (installation Docker) est remplacé.
        $start = 0
        while ($start -lt $source.Count -and ($source[$start] -eq '' -or $source[$start].StartsWith('#'))) { $start++ }
        $lines = [System.Collections.Generic.List[string]]::new()
        foreach ($header in @(
            '# ==========================================================================',
            '#  TechIndex – environnement LOCAL SANS DOCKER (tests sur le poste)',
            '# ==========================================================================',
            '#  Généré par techindex-local.ps1 à partir de .env.example. Ce fichier est',
            '#  lu uniquement par les processus lancés par ce script (APP_ENV=local) ;',
            '#  le .env de l''installation Docker n''est ni lu ni modifié.',
            '#  Ne jamais versionner ni partager ce fichier (clés et mots de passe).',
            '# ==========================================================================',
            '')) { $lines.Add($header) }
        $applied = @{}
        for ($i = $start; $i -lt $source.Count; $i++) {
            $line = $source[$i]
            foreach ($key in $overrides.Keys) {
                if ($line -match "^$([regex]::Escape($key))=") { $line = "$key=$($overrides[$key])"; $applied[$key] = $true }
            }
            $lines.Add($line)
        }
        foreach ($key in $overrides.Keys) { if (-not $applied.ContainsKey($key)) { $lines.Add("$key=$($overrides[$key])") } }
        Write-Utf8NoBom $EnvFile $lines
        Write-Ok '.env.local créé à partir de .env.example'
    } else {
        Write-Ok '.env.local existant conservé'
    }
    Set-EnvValueIfEmpty 'DB_PASSWORD' (New-RandomSecret 32)

    foreach ($dir in 'app\private', 'app\public', 'framework\cache\data', 'framework\sessions', 'framework\testing', 'framework\views', 'logs') {
        New-Item -ItemType Directory -Force -Path (Join-Path $StorageDir $dir) | Out-Null
    }
    Write-Ok "Fichiers de l'application : $StorageDir"
}

# ---------------------------------------------------------------------------
# Services en arrière-plan (indépendants de la fenêtre PowerShell)
# ---------------------------------------------------------------------------
function Write-Launcher([string] $Name, [string[]] $Body) {
    $lines = @(
        '@echo off',
        'rem Généré par techindex-local.ps1 : ne pas modifier.',
        "set `"APP_ENV=local`"",
        "set `"LARAVEL_STORAGE_PATH=$StorageDir`"",
        "set `"PATH=$PhpDir;$MariaDbBin;$NodeDir;%PATH%`""
    ) + $Body
    # cmd.exe lit les fichiers .cmd dans la page de code OEM du poste.
    $encoding = [System.Text.Encoding]::GetEncoding([System.Globalization.CultureInfo]::CurrentCulture.TextInfo.OEMCodePage)
    [System.IO.File]::WriteAllText((Join-Path $RunDir "$Name.cmd"), (($lines -join "`r`n") + "`r`n"), $encoding)
}

function Write-Launchers {
    $webPort = Get-WebPort
    Write-Launcher 'mariadb' @(
        "`"$(Join-Path $MariaDbBin 'mariadbd.exe')`" --defaults-file=`"$(Join-Path $DataDir 'my.ini')`" --console >> `"$(Join-Path $LogDir 'mariadb.log')`" 2>&1"
    )
    # variables_order=EGPCS : le serveur intégré de PHP transmet alors
    # LARAVEL_STORAGE_PATH à Laravel ($_ENV).
    Write-Launcher 'web' @(
        "cd /d `"$(Join-Path $Root 'public')`"",
        "`"$PhpExe`" -d variables_order=EGPCS -S 127.0.0.1:$webPort `"$(Join-Path $Root 'vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php')`" >> `"$(Join-Path $LogDir 'web.log')`" 2>&1"
    )
    Write-Launcher 'worker' @(
        "cd /d `"$Root`"",
        ':boucle',
        "`"$PhpExe`" artisan queue:work --sleep=3 --tries=1 --max-time=3600 >> `"$(Join-Path $LogDir 'worker.log')`" 2>&1",
        'ping -n 4 127.0.0.1 > nul',
        'goto boucle'
    )
}

function Get-ServiceProcessId([string] $Name) {
    $file = Join-Path $RunDir "$Name.pid"
    if (-not (Test-Path $file)) { return $null }
    $value = ([System.IO.File]::ReadAllText($file)).Trim()
    if ($value -notmatch '^\d+$') { return $null }
    # Le processus doit toujours être notre lanceur (un identifiant peut être réutilisé).
    $process = Get-CimInstance Win32_Process -Filter "ProcessId = $value" -ErrorAction SilentlyContinue
    if ($process -and $process.CommandLine -and $process.CommandLine.Contains("$Name.cmd")) { return [int] $value }
    return $null
}

function Test-ServiceRunning([string] $Name) { return $null -ne (Get-ServiceProcessId $Name) }

function Start-LocalService([string] $Name, [int] $Port = 0, [int] $TimeoutSeconds = 30) {
    if (Test-ServiceRunning $Name) { Write-Ok "$Name déjà démarré"; return }
    if ($Port -and (Test-Port $Port)) { Fail "Le port $Port est déjà utilisé par un autre programme." }

    $launcher = Join-Path $RunDir "$Name.cmd"
    $startup = New-CimInstance -ClassName Win32_ProcessStartup -ClientOnly -Property @{ ShowWindow = [uint16] 0 }
    $result = Invoke-CimMethod -ClassName Win32_Process -MethodName Create -Arguments @{
        CommandLine = "cmd.exe /d /c `"$launcher`""
        CurrentDirectory = $Root
        ProcessStartupInformation = $startup
    }
    if ($result.ReturnValue -ne 0) { Fail "Impossible de démarrer $Name (code $($result.ReturnValue))." }
    [System.IO.File]::WriteAllText((Join-Path $RunDir "$Name.pid"), [string] $result.ProcessId)

    if ($Port) {
        for ($i = 0; $i -lt $TimeoutSeconds; $i++) {
            if (Test-Port $Port) { Write-Ok "$Name démarré (port $Port)"; return }
            if (-not (Test-ServiceRunning $Name)) { break }
            Start-Sleep -Seconds 1
        }
        Fail "$Name ne répond pas sur le port $Port. Consultez : .\techindex-local.cmd logs $Name"
    }
    Start-Sleep -Seconds 2
    if (-not (Test-ServiceRunning $Name)) { Fail "$Name s'est arrêté. Consultez : .\techindex-local.cmd logs $Name" }
    Write-Ok "$Name démarré"
}

function Stop-LocalService([string] $Name) {
    $processId = Get-ServiceProcessId $Name
    if ($null -ne $processId) {
        if ($Name -eq 'mariadb' -and (Test-Path $RootPasswordFile)) {
            # Arrêt propre : MariaDB vide ses tampons sur le disque.
            $cnf = New-RootOptionFile
            try { Invoke-Quiet (Join-Path $MariaDbBin 'mariadb-admin.exe') @("--defaults-extra-file=$cnf", 'shutdown') | Out-Null } finally { Remove-Item -LiteralPath $cnf -Force }
            for ($i = 0; $i -lt 30 -and (Test-ServiceRunning $Name); $i++) { Start-Sleep -Seconds 1 }
        }
        if (Test-ServiceRunning $Name) { Invoke-Quiet 'taskkill.exe' @('/PID', "$processId", '/T', '/F') | Out-Null }
    }
    $pidFile = Join-Path $RunDir "$Name.pid"
    if (Test-Path $pidFile) { Remove-Item -LiteralPath $pidFile -Force }
    Write-Ok "$Name arrêté"
}

function Start-All {
    Write-Step 'Démarrage'
    Write-Launchers
    Start-LocalService 'mariadb' (Get-DbPort) 60
    Start-LocalService 'web' (Get-WebPort) 20
    Start-LocalService 'worker'
}

function Stop-All {
    Write-Step 'Arrêt'
    foreach ($name in 'web', 'worker', 'mariadb') { Stop-LocalService $name }
}

function Assert-Installed {
    if (-not (Test-Path (Join-Path $PhpDir '.techindex-ok')) -or -not (Test-Path (Join-Path $DataDir 'my.ini')) -or -not (Test-Path $EnvFile)) {
        Fail "L'environnement local n'est pas installé : .\techindex-local.cmd install"
    }
}

# ---------------------------------------------------------------------------
# MariaDB
# ---------------------------------------------------------------------------
function New-RootOptionFile([switch] $WithoutPassword) {
    # Fichier d'options temporaire : le mot de passe n'apparaît jamais sur une ligne de commande.
    $path = Join-Path $RunDir ('root-' + [guid]::NewGuid().ToString('N') + '.cnf')
    $lines = @('[client]', 'user=root', 'host=127.0.0.1', "port=$(Get-DbPort)")
    if (-not $WithoutPassword) { $lines += "password=$(([System.IO.File]::ReadAllText($RootPasswordFile)).Trim())" }
    Write-Utf8NoBom $path $lines
    return $path
}

function Invoke-RootSql([string] $Sql, [switch] $WithoutPassword) {
    $cnf = New-RootOptionFile -WithoutPassword:$WithoutPassword
    try {
        return Invoke-Quiet (Join-Path $MariaDbBin 'mariadb.exe') @("--defaults-extra-file=$cnf", '--batch') $Sql
    } finally {
        Remove-Item -LiteralPath $cnf -Force
    }
}

function Initialize-MariaDb {
    Write-Step 'Base de données MariaDB'
    $myIni = Join-Path $DataDir 'my.ini'
    $dbPort = Get-DbPort
    $fresh = -not (Test-Path $myIni)

    if ($fresh) {
        if ((Test-Path $DataDir) -and @(Get-ChildItem -LiteralPath $DataDir -Force).Count -gt 0) {
            Fail "Le dossier $DataDir n'est pas vide mais ne contient pas de my.ini : vérifiez-le avant de relancer."
        }
        if (Test-Path $DataDir) { Remove-Item -LiteralPath $DataDir -Force }
        New-Item -ItemType Directory -Force -Path (Split-Path $DataDir) | Out-Null
        $init = Invoke-Quiet (Join-Path $MariaDbBin 'mariadb-install-db.exe') @("--datadir=$DataDir", "--port=$dbPort", '--silent')
        if ($init.ExitCode -ne 0) { $init.Output | ForEach-Object { Write-Host "    $_" }; Fail 'Initialisation de MariaDB impossible.' }
        $forward = $DataDir.Replace('\', '/')
        Write-Utf8NoBom $myIni @(
            '[mysqld]',
            "datadir=$forward",
            "port=$dbPort",
            '# Accessible uniquement depuis ce poste.',
            'bind-address=127.0.0.1',
            'character-set-server=utf8mb4',
            'collation-server=utf8mb4_unicode_ci',
            'innodb_buffer_pool_size=256M',
            'max_allowed_packet=64M',
            '[client]',
            "port=$dbPort",
            "plugin-dir=$($MariaDbDir.Replace('\', '/'))/lib/plugin"
        )
        # Mot de passe root aléatoire, enregistré avant d'être appliqué.
        Write-Utf8NoBom $RootPasswordFile @(New-RandomSecret 32)
        Write-Ok 'Base initialisée'
    } else {
        Write-Ok 'Base existante conservée'
    }

    Write-Launchers
    Start-LocalService 'mariadb' $dbPort 60

    # Compte root protégé par mot de passe (rejoué si une installation a été interrompue).
    if (-not (Test-Path $RootPasswordFile)) { Fail "Mot de passe root introuvable ($RootPasswordFile)." }
    if ((Invoke-RootSql 'SELECT 1;').ExitCode -ne 0) {
        $rootPassword = ([System.IO.File]::ReadAllText($RootPasswordFile)).Trim()
        $secure = Invoke-RootSql -WithoutPassword (@(
            "ALTER USER IF EXISTS 'root'@'localhost' IDENTIFIED BY '$rootPassword';",
            "ALTER USER IF EXISTS 'root'@'127.0.0.1' IDENTIFIED BY '$rootPassword';",
            "ALTER USER IF EXISTS 'root'@'::1' IDENTIFIED BY '$rootPassword';",
            "DROP USER IF EXISTS ''@'localhost';",
            'DROP DATABASE IF EXISTS test;',
            'FLUSH PRIVILEGES;'
        ) -join "`n")
        if ($secure.ExitCode -ne 0) { $secure.Output | ForEach-Object { Write-Host "    $_" }; Fail 'Impossible de sécuriser le compte root de MariaDB.' }
        Write-Ok 'Compte root protégé par un mot de passe aléatoire'
    }

    # Bases et compte applicatif (idempotent : le mot de passe suit .env.local).
    $database = Get-EnvValue 'DB_DATABASE'; $user = Get-EnvValue 'DB_USERNAME'; $password = Get-EnvValue 'DB_PASSWORD'
    foreach ($value in $database, $user, $password) {
        if ($value -notmatch '^[A-Za-z0-9_]+$') { Fail 'DB_DATABASE, DB_USERNAME et DB_PASSWORD de .env.local ne doivent contenir que des lettres, chiffres et « _ ».' }
    }
    $sql = [System.Collections.Generic.List[string]]::new()
    foreach ($db in $database, "${database}_test") { $sql.Add("CREATE DATABASE IF NOT EXISTS ``$db`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;") }
    foreach ($hostName in 'localhost', '127.0.0.1') {
        $sql.Add("CREATE USER IF NOT EXISTS '$user'@'$hostName' IDENTIFIED BY '$password';")
        $sql.Add("ALTER USER '$user'@'$hostName' IDENTIFIED BY '$password';")
        foreach ($db in $database, "${database}_test", "${database}_restore_check") { $sql.Add("GRANT ALL PRIVILEGES ON ``$db``.* TO '$user'@'$hostName';") }
    }
    $sql.Add('FLUSH PRIVILEGES;')
    $result = Invoke-RootSql ($sql -join "`n")
    if ($result.ExitCode -ne 0) { $result.Output | ForEach-Object { Write-Host "    $_" }; Fail 'Création des bases impossible.' }
    Write-Ok "Bases $database et ${database}_test prêtes (compte $user)"
}

# ---------------------------------------------------------------------------
# Application
# ---------------------------------------------------------------------------
function Install-Dependencies {
    Write-Step 'Dépendances PHP (Composer)'
    Invoke-Php $ComposerPhar install --no-interaction --prefer-dist --no-progress

    Write-Step "Compilation de l'interface (Node.js)"
    $npm = Join-Path $NodeDir 'npm.cmd'
    $installed = Join-Path $Root 'node_modules\.package-lock.json'
    if (-not (Test-Path $installed) -or (Get-Item (Join-Path $Root 'package-lock.json')).LastWriteTimeUtc -gt (Get-Item $installed).LastWriteTimeUtc) {
        & $npm ci --no-audit --no-fund
        if ($LASTEXITCODE -ne 0) { Fail 'npm ci a échoué.' }
    } else {
        Write-Ok 'Modules Node.js à jour'
    }
    & $npm run build
    if ($LASTEXITCODE -ne 0) { Fail 'La compilation de l''interface a échoué.' }
}

function Initialize-Keys {
    Write-Step 'Clés de chiffrement'
    if (-not (Get-EnvValue 'APP_KEY')) { Invoke-Artisan key:generate --force } else { Write-Ok 'APP_KEY existante conservée' }
    Invoke-Artisan techindex:secrets-key
}

function Initialize-Database {
    Write-Step 'Migrations'
    Invoke-Artisan migrate --force
    Invoke-Artisan db:seed --class=ReferenceDataSeeder --force
}

function Initialize-Admin {
    Write-Step 'Compte administrateur'
    & $PhpExe artisan techindex:has-admin | Out-Null
    if ($LASTEXITCODE -eq 0) { Write-Ok 'Un administrateur existe déjà'; return }
    if ($NoAdmin) { Write-Warn 'Aucun administrateur : .\techindex-local.cmd create-admin'; return }
    $answer = Read-Host '    Aucun administrateur. Le créer maintenant ? (O/n)'
    if ($answer -eq '' -or $answer -match '^[oOyY]') { Invoke-Artisan techindex:create-admin } else { Write-Warn 'Création reportée : .\techindex-local.cmd create-admin' }
}

function Show-Status {
    Write-Step 'État'
    foreach ($svc in @(@{ Name = 'mariadb'; Port = (Get-DbPort) }, @{ Name = 'web'; Port = (Get-WebPort) }, @{ Name = 'worker'; Port = 0 })) {
        $running = Test-ServiceRunning $svc.Name
        $label = if ($running) { 'démarré' } else { 'arrêté' }
        $port = if ($svc.Port) { " (127.0.0.1:$($svc.Port))" } else { '' }
        Write-Host ("    {0,-8} {1}{2}" -f $svc.Name, $label, $port) -ForegroundColor $(if ($running) { 'Green' } else { 'Gray' })
    }
}

function Show-Urls {
    Write-Host ''
    Write-Host "TechIndex (local) est disponible sur : http://127.0.0.1:$(Get-WebPort)" -ForegroundColor Green
    Write-Host "Journaux et e-mails envoyés : .\techindex-local.cmd logs app" -ForegroundColor Gray
}

# ---------------------------------------------------------------------------
# Commandes
# ---------------------------------------------------------------------------
Set-LocalEnvironment

switch ($Command.ToLowerInvariant()) {
    'install' {
        Test-Prerequisites
        Install-Runtimes
        Initialize-LocalConfiguration
        Initialize-MariaDb
        Install-Dependencies
        Initialize-Keys
        Initialize-Database
        Start-All
        Initialize-Admin
        Write-Host ''
        Write-Warn 'Environnement de test : les clés sont dans .env.local (non versionné, distinct de celui de Docker).'
        Show-Urls
    }
    'start' { Assert-Installed; Start-All; Show-Urls }
    'stop' { Stop-All }
    'restart' { Assert-Installed; Stop-All; Start-All; Show-Urls }
    'status' { Show-Status }
    'logs' {
        $name = if ($Arguments.Count -gt 0) { $Arguments[0] } else { 'web' }
        if ($name -eq 'app') {
            $file = Get-ChildItem -Path (Join-Path $StorageDir 'logs') -Filter 'laravel*.log' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
            if (-not $file) { Fail 'Aucun journal applicatif pour le moment.' }
            $path = $file.FullName
        } elseif (@('web', 'worker', 'mariadb') -contains $name) {
            $path = Join-Path $LogDir "$name.log"
        } else {
            Fail 'Journal inconnu : web, worker, mariadb ou app.'
        }
        if (-not (Test-Path $path)) { Fail "Journal absent : $path" }
        Write-Host "    $path" -ForegroundColor Gray
        if ($Follow) { Get-Content -LiteralPath $path -Tail 50 -Wait -Encoding UTF8 } else { Get-Content -LiteralPath $path -Tail 80 -Encoding UTF8 }
    }
    'test' {
        Assert-Installed
        Write-Launchers
        Start-LocalService 'mariadb' (Get-DbPort) 60
        # phpunit.xml impose APP_ENV=testing et la base techindex_test ; seuls
        # l'adresse et le compte MariaDB locaux sont fournis ici. APP_ENV est
        # retiré de l'environnement : PHPUnit ne le remplace que dans $_ENV,
        # et Laravel lirait sinon « local » dans $_SERVER (donc .env.local).
        $env:APP_ENV = $null
        $env:DB_HOST = '127.0.0.1'
        $env:DB_PORT = [string] (Get-DbPort)
        $env:DB_USERNAME = Get-EnvValue 'DB_USERNAME'
        $env:DB_PASSWORD = Get-EnvValue 'DB_PASSWORD'
        $env:DB_DATABASE = $null
        $testArgs = @('artisan', 'test') + $Arguments
        & $PhpExe @testArgs
        exit $LASTEXITCODE
    }
    'update' {
        Assert-Installed
        Stop-LocalService 'web'
        Stop-LocalService 'worker'
        Write-Launchers
        Start-LocalService 'mariadb' (Get-DbPort) 60
        Install-Dependencies
        Initialize-Database
        Start-All
        Show-Urls
    }
    'create-admin' { Assert-Installed; Start-All; Invoke-Artisan techindex:create-admin }
    'demo' { Assert-Installed; Start-All; Invoke-Artisan techindex:demo }
    'artisan' {
        Assert-Installed
        $all = @('artisan') + $Arguments
        & $PhpExe @all
        exit $LASTEXITCODE
    }
    default { Get-Help $PSCommandPath -Detailed; exit 1 }
}
