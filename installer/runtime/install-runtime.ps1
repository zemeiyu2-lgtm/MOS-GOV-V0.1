#Requires -RunAsAdministrator
#Requires -Version 5.1
[CmdletBinding()]
param()

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

$AppRoot = Split-Path $PSScriptRoot -Parent
$ProgramDataRoot = Join-Path $env:ProgramData "MOS-GOV"
$DataRoot = Join-Path $ProgramDataRoot "data"
$ConfigRoot = Join-Path $ProgramDataRoot "config"
$SecretRoot = Join-Path $ProgramDataRoot "secrets"
$LogRoot = Join-Path $ProgramDataRoot "logs"
$ChurchRoot = Join-Path $AppRoot "ChurchCRM"
$PhpRoot = Join-Path $AppRoot "PHP"
$ApacheRoot = Join-Path $AppRoot "Apache24"
$MariaRoot = Join-Path $AppRoot "MariaDB"
$ConfigPhp = Join-Path $ChurchRoot "Include/Config.php"
$ApacheConf = Join-Path $ApacheRoot "conf/httpd.conf"
$PhpIni = Join-Path $PhpRoot "php.ini"
$ApacheService = "MOS-GOV-Apache"
$MariaService = "MOS-GOV-MariaDB"

function Ensure-Dir([string[]]$Paths) {
    foreach ($path in $Paths) {
        New-Item -ItemType Directory -Force -Path $path | Out-Null
    }
}

function Write-Utf8NoBom([string]$Path,[string]$Content) {
    $utf8 = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path,$Content,$utf8)
}

function Write-InstallLog([string]$Message) {
    Ensure-Dir $LogRoot
    Add-Content -Path (Join-Path $LogRoot "installer.log") -Value "$(Get-Date -Format s) $Message"
    Write-Host $Message
}

function Test-PortFree([int]$Port) {
    return -not [bool](Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)
}

function Find-FreePort([int]$Start,[int]$End) {
    for ($p=$Start; $p -le $End; $p++) {
        if (Test-PortFree $p) { return $p }
    }
    throw "No free TCP port in range $Start-$End."
}

function New-RandomPassword([int]$Length=32) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*-_=+'
    $bytes = New-Object byte[] $Length
    $rng = New-Object System.Security.Cryptography.RNGCryptoServiceProvider
    try {
        $rng.GetBytes($bytes)
    } finally {
        $rng.Dispose()
    }
    $chars = New-Object char[] $Length
    for ($i=0; $i -lt $Length; $i++) { $chars[$i] = $alphabet[$bytes[$i] % $alphabet.Length] }
    return -join $chars
}

function Protect-Directory([string]$Path) {
    & icacls.exe $Path /inheritance:r /grant:r "*S-1-5-18:(OI)(CI)(F)" "*S-1-5-32-544:(OI)(CI)(F)" | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Could not protect $Path." }
}

function Invoke-MariaClient {
    param([string]$Sql,[string]$RootPassword)
    $client = Join-Path $MariaRoot "bin/mariadb.exe"
    $cred = Join-Path $SecretRoot "temporary-client.cnf"
    @"
[client]
user=root
password=$RootPassword
host=127.0.0.1
port=$script:DbPort
protocol=tcp
default-character-set=utf8mb4
"@ | Set-Content $cred -Encoding ASCII
    try {
        & $client "--defaults-extra-file=$cred" --batch --skip-column-names --execute=$Sql
        if ($LASTEXITCODE -ne 0) { throw "MariaDB command failed." }
    } finally {
        Remove-Item $cred -Force -ErrorAction SilentlyContinue
    }
}

function Wait-MariaDb([string]$RootPassword,[int]$TimeoutSeconds=90) {
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        try {
            Invoke-MariaClient -RootPassword $RootPassword -Sql "SELECT 1;" | Out-Null
            return
        } catch {
            Start-Sleep -Seconds 2
        }
    }
    throw "MariaDB did not become ready."
}

function Configure-PHP {
    $template = Join-Path $PhpRoot "php.ini-production"
    if (-not (Test-Path $template)) { throw "php.ini-production not found." }
    Copy-Item $template $PhpIni -Force

    $phpExtDir = (Join-Path $PhpRoot "ext") -replace '\\','/'
    $phpLog = (Join-Path $LogRoot "php-error.log") -replace '\\','/'

    $text = Get-Content $PhpIni -Raw
    $text = $text -replace '(?m)^;?extension_dir\s*=.*$', "extension_dir=`"$phpExtDir`""

    foreach ($ext in @("bcmath","curl","exif","fileinfo","gd","gettext","intl","mbstring","mysqli","pdo_mysql","zip")) {
        $pattern = "(?m)^;?extension\s*=\s*php_$([regex]::Escape($ext))\.dll\s*$"
        if ($text -match $pattern) {
            $text = [regex]::Replace($text,$pattern,"extension=php_$ext.dll")
        } elseif ($text -notmatch "(?m)^extension=php_$([regex]::Escape($ext))\.dll\s*$") {
            $text += [Environment]::NewLine + "extension=php_$ext.dll" + [Environment]::NewLine
        }
    }

    $text = $text -replace '(?m)^;?memory_limit\s*=.*$','memory_limit=512M'
    $text = $text -replace '(?m)^;?upload_max_filesize\s*=.*$','upload_max_filesize=32M'
    $text = $text -replace '(?m)^;?post_max_size\s*=.*$','post_max_size=32M'
    $text = $text -replace '(?m)^;?max_execution_time\s*=.*$','max_execution_time=120'
    $text = $text -replace '(?m)^;?display_errors\s*=.*$','display_errors=Off'
    $text = $text -replace '(?m)^;?log_errors\s*=.*$','log_errors=On'
    $text = $text -replace '(?m)^;?session\.cookie_httponly\s*=.*$','session.cookie_httponly=1'
    $text = $text -replace '(?m)^;?session\.cookie_samesite\s*=.*$','session.cookie_samesite=Lax'
    $text = $text -replace '(?m)^;?date\.timezone\s*=.*$','date.timezone=Asia/Shanghai'
    $phpErrorLogLine = 'error_log="' + $phpLog + '"'
    $text = $text -replace '(?m)^;?error_log\s*=.*$', $phpErrorLogLine
    Write-Utf8NoBom -Path $PhpIni -Content $text
}

function Configure-Apache([int]$Port) {
    $docRoot = $ChurchRoot -replace "\\","/"
    $apacheRoot = $ApacheRoot -replace '\\','/'
    $php = $PhpRoot -replace '\\','/'
    $logs = $LogRoot -replace '\\','/'
    $modules = "$apacheRoot/modules"

    foreach ($module in @(
        "mod_authn_core.so",
        "mod_authz_core.so",
        "mod_authz_host.so",
        "mod_dir.so",
        "mod_mime.so",
        "mod_rewrite.so",
        "mod_log_config.so",
        "mod_env.so",
        "mod_setenvif.so"
    )) {
        $modulePath = Join-Path $ApacheRoot ("modules/" + $module)
        if (-not (Test-Path $modulePath)) {
            throw "Apache module not found: $modulePath"
        }
    }

    if (-not (Test-Path (Join-Path $PhpRoot "php8ts.dll"))) {
        throw "PHP runtime DLL not found: $(Join-Path $PhpRoot "php8ts.dll")"
    }
    if (-not (Test-Path (Join-Path $PhpRoot "php8apache2_4.dll"))) {
        throw "PHP Apache module not found: $(Join-Path $PhpRoot "php8apache2_4.dll")"
    }

    $conf = @"
ServerRoot "$apacheRoot"
Listen 127.0.0.1:$Port
ServerName 127.0.0.1:$Port

LoadModule authn_core_module "$modules/mod_authn_core.so"
LoadModule authz_core_module "$modules/mod_authz_core.so"
LoadModule authz_host_module "$modules/mod_authz_host.so"
LoadModule dir_module "$modules/mod_dir.so"
LoadModule mime_module "$modules/mod_mime.so"
LoadModule rewrite_module "$modules/mod_rewrite.so"
LoadModule log_config_module "$modules/mod_log_config.so"
LoadModule env_module "$modules/mod_env.so"
LoadModule setenvif_module "$modules/mod_setenvif.so"
LoadFile "$php/php8ts.dll"
LoadModule php_module "$php/php8apache2_4.dll"

PHPIniDir "$php"
TypesConfig "$apacheRoot/conf/mime.types"
DirectoryIndex index.php index.html
AddDefaultCharset UTF-8
AddHandler application/x-httpd-php .php

DocumentRoot "$docRoot"
<Directory "$docRoot">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>

<Files ".env">
    Require all denied
</Files>
<FilesMatch "^\.">
    Require all denied
</FilesMatch>

ErrorLog "$logs/apache-error.log"
LogLevel warn
CustomLog "$logs/apache-access.log" common
AcceptFilter http none
EnableSendfile Off
EnableMMAP Off
ServerTokens Prod
ServerSignature Off
"@
    Write-Utf8NoBom -Path $ApacheConf -Content $conf
}

function Install-MariaDb([int]$Port,[string]$RootPassword) {
    $init = Join-Path $MariaRoot "bin/mariadb-install-db.exe"
    if (-not (Test-Path $init)) { throw "mariadb-install-db.exe not found." }
    Ensure-Dir $DataRoot
    if (Get-Service -Name $MariaService -ErrorAction SilentlyContinue) {
        Stop-Service $MariaService -Force
        & sc.exe delete $MariaService | Out-Null
        Start-Sleep -Seconds 2
    }
    & $init "--datadir=$DataRoot" "--service=$MariaService" "--password=$RootPassword" "--port=$Port" "--silent"
    if ($LASTEXITCODE -ne 0) { throw "MariaDB initialization failed." }

    $myIni = Join-Path $DataRoot "my.ini"
    @"
[mysqld]
basedir=$($MariaRoot -replace '\\','/')
datadir=$($DataRoot -replace '\\','/')
port=$Port
bind-address=127.0.0.1
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
max_connections=150

[client]
host=127.0.0.1
port=$Port
default-character-set=utf8mb4
"@ | ForEach-Object { Write-Utf8NoBom -Path $myIni -Content $_ }
    Start-Service $MariaService
    $deadline = (Get-Date).AddSeconds(45)
    do {
        $state = (Get-Service -Name $MariaService -ErrorAction SilentlyContinue).Status
        if ($state -eq "Running") { break }
        Start-Sleep -Seconds 1
    } while ((Get-Date) -lt $deadline)
    if ((Get-Service -Name $MariaService -ErrorAction SilentlyContinue).Status -ne "Running") {
        throw "MariaDB Windows service did not reach Running state."
    }
}

function Install-Apache([int]$Port) {
    $httpd = Join-Path $ApacheRoot "bin/httpd.exe"
    Write-InstallLog "Validating Apache configuration."
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = "Continue"
        $apacheTest = @(& $httpd -t -f $ApacheConf 2>&1)
        $apacheExitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    foreach ($line in $apacheTest) { Write-InstallLog ("Apache httpd -t: " + [string]$line) }
    if ($apacheExitCode -ne 0) { throw "Apache configuration check failed with exit code $apacheExitCode." }
    Write-InstallLog "Apache configuration is valid."

    if (Get-Service -Name $ApacheService -ErrorAction SilentlyContinue) {
        Stop-Service $ApacheService -Force
        & sc.exe delete $ApacheService | Out-Null
        Start-Sleep -Seconds 2
    }

    & $httpd -k install -n $ApacheService -f $ApacheConf
    if ($LASTEXITCODE -ne 0) { throw "Apache service installation failed." }
    Start-Service $ApacheService
    $deadline = (Get-Date).AddSeconds(45)
    do {
        $state = (Get-Service -Name $ApacheService -ErrorAction SilentlyContinue).Status
        if ($state -eq "Running") { break }
        Start-Sleep -Seconds 1
    } while ((Get-Date) -lt $deadline)
    if ((Get-Service -Name $ApacheService -ErrorAction SilentlyContinue).Status -ne "Running") {
        throw "Apache Windows service did not reach Running state."
    }
}

function Configure-ChurchCRM([int]$Port,[int]$DbPort,[string]$DbPassword) {
    $example = Join-Path $ChurchRoot "Include/Config.php.example"
    if (-not (Test-Path $example)) { throw "ChurchCRM Config.php.example not found." }
    $text = Get-Content $example -Raw
    $url = "http://127.0.0.1:$Port/"
    foreach ($pair in @(
        @("||DB_SERVER_NAME||","127.0.0.1"),
        @("||DB_SERVER_PORT||",[string]$DbPort),
        @("||DB_NAME||","churchcrm"),
        @("||DB_USER||","churchcrm_app"),
        @("||DB_PASSWORD||",$DbPassword),
        @("||ROOT_PATH||",""),
        @("||URL||",$url)
    )) {
        $text = $text.Replace($pair[0],$pair[1])
    }
    Write-Utf8NoBom -Path $ConfigPhp -Content $text
}

function Wait-Http([string]$Url,[int]$TimeoutSeconds=180) {
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        try {
            $r = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 10
            if ($r.StatusCode -ge 200 -and $r.StatusCode -lt 500) { return }
        } catch {}
        Start-Sleep -Seconds 2
    }
    throw "ChurchCRM did not become ready."
}

function Run-SqlFile([string]$File,[string]$RootPassword) {
    $client = Join-Path $MariaRoot "bin/mariadb.exe"
    if (-not (Test-Path $File)) { throw "SQL file missing: $File" }
    $cred = Join-Path $SecretRoot "temporary-client.cnf"
    @"
[client]
user=root
password=$RootPassword
host=127.0.0.1
port=$DbPort
protocol=tcp
default-character-set=utf8mb4
"@ | Set-Content $cred -Encoding ASCII
    try {
        Get-Content $File -Raw | & $client "--defaults-extra-file=$cred" churchcrm
        if ($LASTEXITCODE -ne 0) { throw "SQL import failed: $File" }
    } finally {
        Remove-Item $cred -Force -ErrorAction SilentlyContinue
    }
}

Ensure-Dir $ProgramDataRoot,$DataRoot,$ConfigRoot,$SecretRoot,$LogRoot
Write-Utf8NoBom -Path (Join-Path $ConfigRoot "runtime-started.txt") -Content "$(Get-Date -Format o)"
Protect-Directory $ProgramDataRoot
Protect-Directory $SecretRoot
Write-InstallLog "MOS-GOV one-click installation starting."

trap {
    try { Write-InstallLog ("FATAL: {0}" -f $_.Exception.Message) } catch {}
    exit 1
}

if (-not [Environment]::Is64BitOperatingSystem) { throw "MOS-GOV requires Windows x64." }

$script:HttpPort = Find-FreePort 8080 8099
$script:DbPort = Find-FreePort 3307 3316
$rootPassword = New-RandomPassword
$appPassword = New-RandomPassword

Write-InstallLog "Using HTTP port $HttpPort and MariaDB port $DbPort."

$vc = Join-Path $AppRoot "prereqs/vc_redist.x64.exe"
if (Test-Path $vc) {
    Write-InstallLog "Starting Microsoft VC++ Redistributable installation."
    $p = Start-Process $vc -ArgumentList "/install","/quiet","/norestart" -Wait -PassThru
    Write-InstallLog "Microsoft VC++ Redistributable exit code: $($p.ExitCode)."
    if ($p.ExitCode -notin @(0,3010,1638)) { throw "VC++ Redistributable failed: $($p.ExitCode)" }
}

Write-InstallLog "Configuring PHP."
Configure-PHP
Write-InstallLog "PHP configuration completed."
Write-InstallLog "Installing MariaDB."
Install-MariaDb -Port $DbPort -RootPassword $rootPassword
Write-InstallLog "MariaDB service installation completed."
Write-InstallLog "Waiting for MariaDB."
Wait-MariaDb -RootPassword $rootPassword
Write-InstallLog "MariaDB is ready."

Invoke-MariaClient -RootPassword $rootPassword -Sql @"
CREATE DATABASE IF NOT EXISTS churchcrm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'churchcrm_app'@'127.0.0.1' IDENTIFIED BY '$appPassword';
CREATE USER IF NOT EXISTS 'churchcrm_app'@'localhost' IDENTIFIED BY '$appPassword';
ALTER USER 'churchcrm_app'@'127.0.0.1' IDENTIFIED BY '$appPassword';
ALTER USER 'churchcrm_app'@'localhost' IDENTIFIED BY '$appPassword';
GRANT ALL PRIVILEGES ON churchcrm.* TO 'churchcrm_app'@'127.0.0.1';
GRANT ALL PRIVILEGES ON churchcrm.* TO 'churchcrm_app'@'localhost';
FLUSH PRIVILEGES;
"@

Write-InstallLog "Configuring ChurchCRM."
Configure-ChurchCRM -Port $HttpPort -DbPort $DbPort -DbPassword $appPassword
Write-InstallLog "Configuring Apache."
Configure-Apache -Port $HttpPort
Install-Apache -Port $HttpPort
Write-InstallLog "Apache service installation completed."

# Initialize the official ChurchCRM schema and seed data first.
# This creates the standard admin/changeme account used by ChurchCRM's fresh-install flow.
Write-InstallLog "Importing official ChurchCRM schema and seed data."
Run-SqlFile (Join-Path $ChurchRoot "mysql/install/Install.sql") $rootPassword
Write-InstallLog "Official ChurchCRM schema import completed."

Write-InstallLog "Waiting for ChurchCRM HTTP endpoint."
Wait-Http -Url "http://127.0.0.1:$HttpPort/" -TimeoutSeconds 180
Write-InstallLog "ChurchCRM HTTP endpoint is reachable."

Run-SqlFile (Join-Path $ChurchRoot "plugins/community/mos-gov/database/001_initial.sql") $rootPassword
Run-SqlFile (Join-Path $ChurchRoot "plugins/community/mos-gov/database/002_v02_authorization.sql") $rootPassword

$php = Join-Path $PhpRoot "php.exe"
$enable = Join-Path $AppRoot "runtime/enable-mosgov.php"
& $php $enable
if ($LASTEXITCODE -ne 0) { throw "MOS-GOV enablement failed." }

Write-Utf8NoBom -Path (Join-Path $SecretRoot "mariadb-root.txt") -Content "root-password=$rootPassword"
Protect-Directory $SecretRoot

$stateJson = @{ version="0.2.0"; httpPort=$HttpPort; dbPort=$DbPort; appUrl="http://127.0.0.1:$HttpPort/"; installedAtUtc=(Get-Date).ToUniversalTime().ToString("o") } |
    ConvertTo-Json
Write-Utf8NoBom -Path (Join-Path $ConfigRoot "install-state.json") -Content $stateJson

$openCmd = "@echo off" + [Environment]::NewLine + "start """" ""http://127.0.0.1:$HttpPort/plugins/mos-gov""" + [Environment]::NewLine
Set-Content (Join-Path $AppRoot "runtime/open-mosgov.cmd") $openCmd -Encoding ASCII

Write-Utf8NoBom -Path (Join-Path $ConfigRoot "runtime-complete.txt") -Content "$(Get-Date -Format o)"
Write-InstallLog "MOS-GOV installation completed."
Start-Process (Join-Path $AppRoot "runtime/open-mosgov.cmd")
exit 0