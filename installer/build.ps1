#Requires -Version 5.1
[CmdletBinding()]
param([string]$Configuration = "Release")

$ErrorActionPreference = "Stop"
Set-StrictMode -Version Latest

$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$Manifest = Get-Content (Join-Path $PSScriptRoot "MANIFEST.json") -Raw | ConvertFrom-Json
$BuildRoot = Join-Path $RepoRoot "installer-build"
$DownloadRoot = Join-Path $BuildRoot "downloads"
$ExtractRoot = Join-Path $BuildRoot "extract"
$PayloadRoot = Join-Path $BuildRoot "payload"
$DistRoot = Join-Path $RepoRoot "dist"

Remove-Item $BuildRoot -Recurse -Force -ErrorAction SilentlyContinue
Remove-Item $DistRoot -Recurse -Force -ErrorAction SilentlyContinue
New-Item -ItemType Directory -Force -Path $DownloadRoot,$ExtractRoot,$PayloadRoot,$DistRoot | Out-Null

function Test-ZipReadable([string]$Path) {
    try {
        Add-Type -AssemblyName System.IO.Compression.FileSystem -ErrorAction SilentlyContinue
        $archive = [System.IO.Compression.ZipFile]::OpenRead($Path)
        try {
            return $archive.Entries.Count -gt 0
        } finally {
            $archive.Dispose()
        }
    } catch {
        return $false
    }
}

function Get-File([string]$Name,[string]$Url,[string]$Sha256) {
    $path = Join-Path $DownloadRoot $Name
    $curl = Get-Command curl.exe -ErrorAction SilentlyContinue
    for ($attempt = 1; $attempt -le 3; $attempt++) {
        Remove-Item $path -Force -ErrorAction SilentlyContinue
        Write-Host "Downloading $Name (attempt $attempt/3)"
        if ($curl) {
            & $curl.Source -L --fail --retry 6 --retry-all-errors --retry-delay 2 --http1.1 --silent --show-error --output $path $Url
            if ($LASTEXITCODE -ne 0) {
                Write-Warning "Download failed for $Name (curl exit $LASTEXITCODE)."
                continue
            }
        } else {
            try {
                Invoke-WebRequest -Uri $Url -OutFile $path -UseBasicParsing -MaximumRedirection 10
            } catch {
                Write-Warning "Download failed for $Name: $($_.Exception.Message)"
                continue
            }
        }

        if (-not (Test-Path $path)) {
            Write-Warning "Downloaded file missing: $Name"
            continue
        }

        if ($Name.EndsWith(".zip",[System.StringComparison]::OrdinalIgnoreCase) -and -not (Test-ZipReadable $path)) {
            Write-Warning "Downloaded ZIP failed integrity check: $Name"
            continue
        }

        $actual = (Get-FileHash $path -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($actual -eq $Sha256.ToLowerInvariant()) {
            return $path
        }

        Write-Warning "SHA256 mismatch for $Name. Expected $Sha256, got $actual."
    }

    throw "Unable to obtain a verified copy of $Name after 3 attempts."
}

function Expand-ArchiveSafe([string]$Archive,[string]$Destination) {
    New-Item -ItemType Directory -Force -Path $Destination | Out-Null
    Expand-Archive -LiteralPath $Archive -DestinationPath $Destination -Force
}

function Find-ComponentRoot([string]$Destination,[string]$RelativePath,[string]$ComponentName) {
    $suffix = ($RelativePath -replace '/','\')
    $matches = @(Get-ChildItem -LiteralPath $Destination -Recurse -File -Filter (Split-Path $suffix -Leaf) |
        Where-Object { $_.FullName.EndsWith($suffix, [System.StringComparison]::OrdinalIgnoreCase) } |
        Select-Object -First 2)
    if ($matches.Count -eq 0) {
        throw "$ComponentName root could not be located. Expected file: $RelativePath"
    }
    if ($matches.Count -gt 1) {
        throw "$ComponentName root is ambiguous; found multiple matches for $RelativePath."
    }
    return (Split-Path $matches[0].FullName -Parent)
}

$ccrmZip = Get-File "ChurchCRM-7.7.0.zip" $Manifest.churchcrm.url $Manifest.churchcrm.sha256
$phpZip = Get-File "php-8.4.25-Win32-vs17-x64.zip" $Manifest.php.url $Manifest.php.sha256
$apacheZip = Get-File "httpd-2.4.68-260610-Win64-VS18.zip" $Manifest.apache.url $Manifest.apache.sha256
$mariaZip = Get-File "mariadb-11.8.9-winx64.zip" $Manifest.mariadb.url $Manifest.mariadb.sha256

$vcPath = Join-Path $DownloadRoot "vc_redist.x64.exe"
$curl = Get-Command curl.exe -ErrorAction SilentlyContinue
if ($curl) {
    & $curl.Source -L --fail --retry 4 --retry-delay 2 --silent --show-error --output $vcPath $Manifest.vcRuntime.url
    if ($LASTEXITCODE -ne 0) { throw "Download failed for Microsoft VC++ Redistributable." }
} else {
    Invoke-WebRequest -Uri $Manifest.vcRuntime.url -OutFile $vcPath -UseBasicParsing -MaximumRedirection 10
}
$sig = Get-AuthenticodeSignature $vcPath
if ($sig.Status -ne "Valid" -or $sig.SignerCertificate.Subject -notmatch "Microsoft") {
    throw "Microsoft VC++ Redistributable Authenticode verification failed."
}

$churchExtract = Join-Path $ExtractRoot "churchcrm"
$phpExtract = Join-Path $ExtractRoot "php"
$apacheExtract = Join-Path $ExtractRoot "apache"
$mariaExtract = Join-Path $ExtractRoot "mariadb"

Expand-ArchiveSafe $ccrmZip $churchExtract
Expand-ArchiveSafe $phpZip $phpExtract
Expand-ArchiveSafe $apacheZip $apacheExtract
Expand-ArchiveSafe $mariaZip $mariaExtract

$church = Find-ComponentRoot $churchExtract "src/composer.json" "ChurchCRM"
$phpRoot = Find-ComponentRoot $phpExtract "php.exe" "PHP"
$apacheRoot = Find-ComponentRoot $apacheExtract "bin/httpd.exe" "Apache"
$mariaRoot = Find-ComponentRoot $mariaExtract "bin/mariadb.exe" "MariaDB"

$payloadChurch = Join-Path $PayloadRoot "ChurchCRM"
$payloadPhp = Join-Path $PayloadRoot "PHP"
$payloadApache = Join-Path $PayloadRoot "Apache24"
$payloadMaria = Join-Path $PayloadRoot "MariaDB"
$payloadRuntime = Join-Path $PayloadRoot "runtime"
$payloadPrereq = Join-Path $PayloadRoot "prereqs"
New-Item -ItemType Directory -Force -Path $payloadChurch,$payloadPhp,$payloadApache,$payloadMaria,$payloadRuntime,$payloadPrereq | Out-Null

Copy-Item (Join-Path $church "*") $payloadChurch -Recurse -Force
Copy-Item (Join-Path $phpRoot "*") $payloadPhp -Recurse -Force
Copy-Item (Join-Path $apacheRoot "*") $payloadApache -Recurse -Force
Copy-Item (Join-Path $mariaRoot "*") $payloadMaria -Recurse -Force

$mosDest = Join-Path $payloadChurch "src/plugins/community/mos-gov"
New-Item -ItemType Directory -Force -Path $mosDest | Out-Null
foreach ($item in @("plugin.json","routes","src","views","database")) {
    Copy-Item (Join-Path $RepoRoot $item) (Join-Path $mosDest $item) -Recurse -Force
}

Copy-Item $vcPath (Join-Path $payloadPrereq "vc_redist.x64.exe") -Force
Copy-Item (Join-Path $PSScriptRoot "runtime/install-runtime.ps1") (Join-Path $payloadRuntime "install-runtime.ps1") -Force
Copy-Item (Join-Path $PSScriptRoot "runtime/uninstall-runtime.ps1") (Join-Path $payloadRuntime "uninstall-runtime.ps1") -Force
Copy-Item (Join-Path $PSScriptRoot "runtime/enable-mosgov.php") (Join-Path $payloadRuntime "enable-mosgov.php") -Force

$iss = Get-Content (Join-Path $PSScriptRoot "MOS-GOV-V0.2.iss") -Raw
$iss = $iss.Replace("{{PAYLOAD_ROOT}}",($PayloadRoot -replace '\','/'))
$iss = $iss.Replace("{{OUTPUT_DIR}}",($DistRoot -replace '\','/'))
$issPath = Join-Path $BuildRoot "MOS-GOV-V0.2.iss"
Set-Content $issPath $iss -Encoding UTF8

$iscc = (Get-Command iscc.exe -ErrorAction SilentlyContinue).Source
if (-not $iscc) {
    $pf86 = [Environment]::GetEnvironmentVariable('ProgramFiles(x86)')
    $default = Join-Path (Join-Path $pf86 "Inno Setup 6") "ISCC.exe"
    if (Test-Path $default) { $iscc = $default }
}
if (-not $iscc) { throw "ISCC.exe not found. Install Inno Setup 6." }

& $iscc $issPath
if ($LASTEXITCODE -ne 0) { throw "Inno Setup compilation failed: $LASTEXITCODE" }

$exe = Join-Path $DistRoot "MOS-GOV-V0.2-Setup.exe"
if (-not (Test-Path $exe)) { throw "Installer was not produced." }
$hash = (Get-FileHash $exe -Algorithm SHA256).Hash
@{ product="MOS-GOV 教会治理平台"; version="0.2.0"; installer=(Split-Path $exe -Leaf); sha256=$hash; builtAtUtc=(Get-Date).ToUniversalTime().ToString("o") } |
    ConvertTo-Json | Set-Content (Join-Path $DistRoot "MOS-GOV-V0.2-Setup.sha256.json") -Encoding UTF8
Write-Host "Created: $exe"
Write-Host "SHA256: $hash"
