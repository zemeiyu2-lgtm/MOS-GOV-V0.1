# MOS 平台用户桌面快捷方式创建器
# 由 Inno Setup 通过 ExecAsOriginalUser 调用，确保写入真正登录用户的桌面。
param(
    [Parameter(Mandatory=$true)][string]$Target,
    [Parameter(Mandatory=$true)][string]$Icon,
    [Parameter(Mandatory=$true)][string]$Shortcut
)
$ErrorActionPreference = 'Stop'
$parent = Split-Path -Parent $Shortcut
if (-not (Test-Path -LiteralPath $parent)) {
    New-Item -ItemType Directory -Path $parent -Force | Out-Null
}
$ws = New-Object -ComObject WScript.Shell
$sc = $ws.CreateShortcut($Shortcut)
$sc.TargetPath = $Target
$sc.WorkingDirectory = Split-Path -Parent $Target
$sc.IconLocation = "$Icon,0"
$sc.Description = '启动 MOS 平台'
$sc.Save()
if (-not (Test-Path -LiteralPath $Shortcut)) {
    throw "Shortcut was not created: $Shortcut"
}
Write-Output $Shortcut
