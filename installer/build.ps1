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
                Write-Warning ("Download failed for {0}: {1}" -f $Name, $_.Exception.Message)
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
    Add-Type -AssemblyName System.IO.Compression.FileSystem -ErrorAction SilentlyContinue
    try {
        [System.IO.Compression.ZipFile]::ExtractToDirectory($Archive,$Destination)
    } catch {
        throw ("ZIP extraction failed for {0}: {1}" -f $Archive, $_.Exception.Message)
    }
}

function Find-ComponentRoot([string]$Destination,[string]$RelativePath,[string]$ComponentName) {
    $relative = $RelativePath.Replace('/','\').TrimStart('\')
    $roots = @()
    if (Test-Path -LiteralPath $Destination -PathType Container) {
        $roots += Get-Item -LiteralPath $Destination
        $roots += @(Get-ChildItem -LiteralPath $Destination -Directory -Recurse -ErrorAction SilentlyContinue)
    }

    $matches = @()
    foreach ($root in $roots) {
        $candidate = Join-Path $root.FullName $relative
        if (Test-Path -LiteralPath $candidate -PathType Leaf) {
            $matches += $root.FullName
            if ($matches.Count -gt 1) { break }
        }
    }

    if ($matches.Count -eq 0) {
        $names = @(Get-ChildItem -LiteralPath $Destination -Force -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Name)
        throw ("{0} root could not be located. Expected file: {1}. Extracted top level: {2}" -f
            $ComponentName,$RelativePath,($names -join ', '))
    }
    if ($matches.Count -gt 1) {
        throw "$ComponentName root is ambiguous; found multiple matches for $RelativePath."
    }
    return $matches[0]
}

$ccrmZip = Get-File "ChurchCRM-7.7.0.zip" $Manifest.churchcrm.url $Manifest.churchcrm.sha256
$phpZip = Get-File "php-8.4.25-Win32-vs17-x64.zip" $Manifest.php.url $Manifest.php.sha256
$apacheZip = Get-File ("httpd-" + $Manifest.apache.version + "-Win64-VS18.zip") $Manifest.apache.url $Manifest.apache.sha256
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
# 空值安全：签名状态不可用时 $sig.SignerCertificate 为 $null，直接取 .Subject 会在
# StrictMode + ErrorActionPreference=Stop 下终止构建。这里保持同样严格的校验语义，
# 只避免对 $null 取属性。
$signerSubject = ''
if ($sig -and $sig.SignerCertificate) { $signerSubject = $sig.SignerCertificate.Subject }
if ($sig.Status -ne "Valid" -or $signerSubject -notmatch "Microsoft") {
    throw ("Microsoft VC++ Redistributable Authenticode verification failed (status={0}, signer='{1}')." -f $sig.Status, $signerSubject)
}

$churchExtract = Join-Path $ExtractRoot "churchcrm"
$phpExtract = Join-Path $ExtractRoot "php"
$apacheExtract = Join-Path $ExtractRoot "apache"
$mariaExtract = Join-Path $ExtractRoot "mariadb"

Expand-ArchiveSafe $ccrmZip $churchExtract
Expand-ArchiveSafe $phpZip $phpExtract
Expand-ArchiveSafe $apacheZip $apacheExtract
Expand-ArchiveSafe $mariaZip $mariaExtract

$churchSrc = Find-ComponentRoot $churchExtract "Include/Config.php.example" "ChurchCRM"
$church = $churchSrc
if (-not (Test-Path (Join-Path $churchSrc "index.php"))) {
    throw "ChurchCRM application root did not contain index.php: $churchSrc"
}
if (-not (Test-Path (Join-Path $churchSrc "vendor/autoload.php"))) {
    throw "ChurchCRM release does not contain packaged Composer dependencies: $churchSrc"
}

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

$mosDest = Join-Path $payloadChurch "plugins/community/mos-gov"
New-Item -ItemType Directory -Force -Path $mosDest | Out-Null
foreach ($item in @("plugin.json","routes","src","views","database")) {
    Copy-Item (Join-Path $RepoRoot $item) (Join-Path $mosDest $item) -Recurse -Force
}

Copy-Item $vcPath (Join-Path $payloadPrereq "vc_redist.x64.exe") -Force
Copy-Item (Join-Path $PSScriptRoot "runtime/install-runtime.cmd") (Join-Path $payloadRuntime "install-runtime.cmd") -Force
Copy-Item (Join-Path $PSScriptRoot "runtime/install-runtime.ps1") (Join-Path $payloadRuntime "install-runtime.ps1") -Force
Copy-Item (Join-Path $PSScriptRoot "runtime/uninstall-runtime.cmd") (Join-Path $payloadRuntime "uninstall-runtime.cmd") -Force
Copy-Item (Join-Path $PSScriptRoot "runtime/uninstall-runtime.ps1") (Join-Path $payloadRuntime "uninstall-runtime.ps1") -Force
Copy-Item (Join-Path $PSScriptRoot "runtime/reset-church.cmd") (Join-Path $payloadRuntime "reset-church.cmd") -Force
Copy-Item (Join-Path $PSScriptRoot "runtime/reset-church.ps1") (Join-Path $payloadRuntime "reset-church.ps1") -Force
Copy-Item (Join-Path $PSScriptRoot "runtime/enable-mosgov.php") (Join-Path $payloadRuntime "enable-mosgov.php") -Force
# V0.2.1 平台入口资源：启动器 / 图标 / 诊断工具
foreach ($runtimeAsset in @(
    "MOSLauncher.exe",
    "MOSLauncher.ps1",
    "MOSLauncher.vbs",
    "MOS.ico",
    "MOS-Diagnose.ps1",
    "MOS-Diagnose.cmd"
)) {
    $source = Join-Path $PSScriptRoot ("runtime/" + $runtimeAsset)
    if (-not (Test-Path -LiteralPath $source)) { throw "Required runtime asset missing: $source" }
    Copy-Item $source (Join-Path $payloadRuntime $runtimeAsset) -Force
}

# --- 生成构建用安装脚本 ---
# 关键修复（V0.2.1）：MOS-GOV-V0.2.iss 含大量中文字面量（AppName、桌面/开始菜单快捷
# 方式名、完成页按钮、安装向导提示等）。该源文件是不带 BOM 的 UTF-8，而
# Windows PowerShell 5.1 的 Get-Content 默认按“当前 ANSI 代码页”解码，会把中文解成
# 乱码并写进最终安装包：桌面图标名会变成 "MOS 骞冲彴"（ACP936）或 "MOS å¹³å°"（CP1252），
# 与规格要求的 "MOS 平台" 不符，且用户在向导中看到的全部中文都会损坏。
# 因此这里显式按 UTF-8 读取，并在写回时强制写入 UTF-8 BOM，使 ISCC 的解析结果与
# 构建机器的代码页完全无关。
$issSource = Join-Path $PSScriptRoot "MOS-GOV-V0.2.iss"
$sourceText = Get-Content $issSource -Raw -Encoding UTF8
$iss = $sourceText.TrimStart(@([char]0xFEFF))
$iss = $iss.Replace("{{PAYLOAD_ROOT}}",$PayloadRoot)
$iss = $iss.Replace("{{OUTPUT_DIR}}",$DistRoot)

# 编码自检 1：关键中文字面量必须原样存在。
# 校验词用码位构造（用 -join，不用 [string]::Concat：后者在 4 参数时无法在
# StrictMode 下绑定到 params 重载，会抛 ArgumentNullException 参数名 "argument"）。
# 之所以不直接写成中文字面量：若本文件在错误代码页下被解码，断言词会一起变成乱码，
# 自检就失去了意义。
$zhPlatform = -join @([char]0x5E73,[char]0x53F0)                                      # 平台
$zhOpenNow  = -join @([char]0x7ACB,[char]0x5373,[char]0x6253,[char]0x5F00)            # 立即打开
$requiredLiterals = @(
    ("MOS " + $zhPlatform),
    ($zhOpenNow + " MOS " + $zhPlatform),
    ("MOS " + $zhPlatform + " (MOS-GOV")
)
foreach ($literal in $requiredLiterals) {
    if ($iss.IndexOf($literal) -lt 0) {
        $msg = "编码损坏：生成安装脚本时丢失中文字面量 '{0}'。MOS-GOV-V0.2.iss 必须按 UTF-8 读取（当前构建机器代码页可能不是 UTF-8）。" -f $literal
        throw $msg
    }
}

# 编码自检 2：中日韩字符总量不变式。乱码会改变该数量（例如 2 个汉字变成 3 个），
# 可捕获任何未预料的中文损坏。
$cjkSource = ([regex]::Matches($sourceText,'[\u4e00-\u9fff]')).Count
$cjkOutput = ([regex]::Matches($iss,'[\u4e00-\u9fff]')).Count
if ($cjkOutput -ne $cjkSource) {
    throw ("编码损坏：中日韩字符数由 {0} 变为 {1}，生成的安装包会包含乱码中文。" -f $cjkSource,$cjkOutput)
}

$issPath = Join-Path $BuildRoot "MOS-GOV-V0.2.iss"
$utf8Bom = New-Object System.Text.UTF8Encoding($true)
[System.IO.File]::WriteAllText($issPath,$iss,$utf8Bom)

# 编码自检 3：复核落盘文件。ISCC 依据 BOM 判定脚本编码，必须确认磁盘上的内容同样正确。
$issOnDisk = [System.IO.File]::ReadAllText($issPath)
if ($issOnDisk.IndexOf("MOS " + $zhPlatform) -lt 0) {
    throw "编码损坏：落盘的构建脚本丢失中文快捷方式名，用户将看到乱码图标名。路径：$issPath"
}
Write-Host ("Build script generated and encoding-verified: {0} (CJK chars: {1})" -f $issPath,$cjkOutput)

# ISCC 定位：必须做空值安全处理。
# 旧写法 `(Get-Command iscc.exe -ErrorAction SilentlyContinue).Source` 在 ISCC 不在 PATH
# 时会对 $null 取属性；配合本脚本顶部的 $ErrorActionPreference='Stop' + StrictMode，
# 该语句会直接终止脚本，导致下面的回落查找永远执行不到（CI 因 Inno Setup 已在 PATH 上
# 而不会暴露此问题，非 CI 环境则整个构建失败）。
$iscc = $null
$isccCmd = Get-Command iscc.exe -ErrorAction SilentlyContinue
if ($isccCmd) { $iscc = $isccCmd.Source }
if (-not $iscc) {
    $candidates = New-Object System.Collections.Generic.List[string]
    foreach ($base in @([Environment]::GetEnvironmentVariable('ProgramFiles(x86)'), $env:ProgramFiles)) {
        if ($base) { $candidates.Add((Join-Path (Join-Path $base 'Inno Setup 6') 'ISCC.exe')) }
    }
    $candidates.Add('C:\Program Files (x86)\Inno Setup 6\ISCC.exe')
    $candidates.Add('C:\Program Files\Inno Setup 6\ISCC.exe')
    $candidates.Add((Join-Path $env:LOCALAPPDATA 'Programs\Inno Setup 6\ISCC.exe'))
    foreach ($candidate in $candidates) {
        if ($candidate -and (Test-Path -LiteralPath $candidate)) { $iscc = $candidate; break }
    }
}
if (-not $iscc) { throw "ISCC.exe not found. Install Inno Setup 6 (https://jrsoftware.org/isdl.php) or add it to PATH." }
Write-Host "Using ISCC: $iscc"

& $iscc $issPath
if ($LASTEXITCODE -ne 0) { throw "Inno Setup compilation failed: $LASTEXITCODE" }

$exe = Join-Path $DistRoot "MOS-GOV-V0.2.2-Setup.exe"
if (-not (Test-Path $exe)) { throw "Installer was not produced." }
$hash = (Get-FileHash $exe -Algorithm SHA256).Hash
$jsonPath = Join-Path $DistRoot "MOS-GOV-V0.2.2-Setup.sha256.json"
$json = @{ product="MOS-GOV 教会治理平台"; version="0.2.2"; installer=(Split-Path $exe -Leaf); sha256=$hash; builtAtUtc=(Get-Date).ToUniversalTime().ToString("o") } |
    ConvertTo-Json
# 与 .iss 同理：显式写入 UTF-8 BOM，避免 product 字段中的中文在不同 PowerShell 版本下损坏。
[System.IO.File]::WriteAllText($jsonPath,$json,$utf8Bom)
Write-Host "Created: $exe"
Write-Host "SHA256: $hash"
