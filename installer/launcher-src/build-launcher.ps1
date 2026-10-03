# MOSLauncher.exe 构建脚本
#
# 为什么需要这个脚本：
#   双击桌面图标后必须能真正启动 MOS-GOV 的两个 Windows 服务。启动服务需要管理员权限，
#   因此 exe 的清单必须是 requireAdministrator（--uac-admin）。若用默认的 asInvoker 清单，
#   启动器会以普通权限运行：既无法直接启动服务（只能退回每次都弹 UAC 的 net.exe 兜底），
#   也无法写入 C:\ProgramData\MOS-GOV\logs（该目录只允许 SYSTEM/Administrators），
#   结果是 launcher.log 被静默丢弃、诊断信息窗口打开后没有证据。
#
# 产物：
#   installer\runtime\MOSLauncher.exe   （GUI 子系统，无控制台窗口）
#
# 用法：
#   powershell -NoProfile -ExecutionPolicy Bypass -File installer\launcher-src\build-launcher.ps1

[CmdletBinding()]
param(
    [string]$PythonExe,
    [switch]$SkipVerify
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$SourceRoot = $PSScriptRoot
$InstallerRoot = Split-Path $SourceRoot -Parent
$RepoRoot = Split-Path $InstallerRoot -Parent
$Entry = Join-Path $SourceRoot 'MOSLauncher.py'
$Icon = Join-Path $InstallerRoot 'runtime\MOS.ico'
$OutDir = Join-Path $SourceRoot 'build'
$Destination = Join-Path $InstallerRoot 'runtime\MOSLauncher.exe'

if (-not (Test-Path -LiteralPath $Entry)) { throw "Launcher entry point not found: $Entry" }
if (-not (Test-Path -LiteralPath $Icon)) { throw "Launcher icon not found: $Icon" }

# --- 定位 Python + PyInstaller ---
if (-not $PythonExe) {
    $candidates = @(
        (Join-Path $env:USERPROFILE '.workbuddy\binaries\python\envs\default\Scripts\python.exe'),
        (Join-Path $env:USERPROFILE '.workbuddy\binaries\python\versions\3.13.12\python.exe')
    )
    foreach ($candidate in $candidates) {
        if ($candidate -and (Test-Path -LiteralPath $candidate)) { $PythonExe = $candidate; break }
    }
}
if (-not $PythonExe) {
    $pyCmd = Get-Command python.exe -ErrorAction SilentlyContinue
    if ($pyCmd) { $PythonExe = $pyCmd.Source }
}
if (-not $PythonExe) { throw 'Python not found. Pass -PythonExe <path to python.exe>.' }
Write-Host "Using Python: $PythonExe"

$hasPyInstaller = (& $PythonExe -c "import PyInstaller, sys; sys.stdout.write(PyInstaller.__version__)" 2>$null)
if (-not $hasPyInstaller) {
    throw "PyInstaller is not installed for $PythonExe (pip install pyinstaller)."
}
Write-Host "Using PyInstaller: $hasPyInstaller"

# --- 语法自检：先证明能编译，再打包 ---
& $PythonExe -c "import py_compile, sys; py_compile.compile(sys.argv[1], doraise=True); sys.stdout.write('py_compile ok')" $Entry
if ($LASTEXITCODE -ne 0) { throw "MOSLauncher.py failed to compile." }
Write-Host ''

# --- 打包 ---
# --uac-admin  : requireAdministrator 清单（见文件头说明，这是本脚本存在的理由）
# --noconsole : GUI 子系统，双击不出现命令行黑框
# --onefile   : 单个 exe，便于安装器分发
Remove-Item $OutDir -Recurse -Force -ErrorAction SilentlyContinue
Push-Location $RepoRoot
try {
    & $PythonExe -m PyInstaller `
        --noconfirm `
        --clean `
        --onefile `
        --noconsole `
        --uac-admin `
        --name MOSLauncher `
        --icon $Icon `
        --distpath $OutDir `
        --workpath (Join-Path $OutDir 'work') `
        --specpath (Join-Path $OutDir 'spec') `
        $Entry
    if ($LASTEXITCODE -ne 0) { throw "PyInstaller failed with exit code $LASTEXITCODE." }
} finally {
    Pop-Location
}

$built = Join-Path $OutDir 'MOSLauncher.exe'
if (-not (Test-Path -LiteralPath $built)) { throw "PyInstaller did not produce $built" }

Copy-Item -LiteralPath $built -Destination $Destination -Force
$hash = (Get-FileHash -LiteralPath $Destination -Algorithm SHA256).Hash
$size = (Get-Item -LiteralPath $Destination).Length
Write-Host ("MOSLauncher.exe written: {0} ({1} bytes)" -f $Destination, $size)
Write-Host ("SHA256 {0}" -f $hash)

if ($SkipVerify) { Write-Host 'Skipped binary verification (-SkipVerify).'; exit 0 }

# --- 产物自检：清单必须是 requireAdministrator，子系统必须是 GUI ---
$bytes = [System.IO.File]::ReadAllBytes($Destination)
$text = [System.Text.Encoding]::ASCII.GetString($bytes)

if ($text -notmatch 'requestedExecutionLevel') {
    throw 'Built exe does not contain a requestedExecutionLevel manifest entry.'
}
# 清单以明文存在 PE 资源里；换行/缩进不固定，因此用跨行宽松匹配。
if ($text -notmatch 'requestedExecutionLevel[\s\S]{0,60}?level="requireAdministrator"') {
    $found = [regex]::Match($text, 'requestedExecutionLevel[\s\S]{0,60}')
    throw ("Built exe must run elevated; expected requireAdministrator but found: " + $found.Value)
}
Write-Host 'Manifest check: requireAdministrator'

# PE 子系统：2 = Windows GUI（无控制台窗口），3 = Console
$peOffset = [BitConverter]::ToInt32($bytes, 0x3C)
$subsystem = [BitConverter]::ToUInt16($bytes, $peOffset + 0x5C)
Write-Host ("PE subsystem: {0}" -f $subsystem)
if ($subsystem -ne 2) { throw "Expected GUI subsystem (2) so no console window is shown, got $subsystem." }

Write-Host 'Launcher build verified.'
