param(
  [Parameter(Mandatory=$true)][string]$TargetPath,
  [Parameter(Mandatory=$true)][string]$WorkingDirectory,
  [Parameter(Mandatory=$true)][string]$IconPath
)

$ErrorActionPreference = 'Stop'
$desktop = [Environment]::GetFolderPath('Desktop')
if ([string]::IsNullOrWhiteSpace($desktop)) { throw '无法确定当前用户桌面路径。' }

$linkPath = Join-Path $desktop 'MOS 平台.lnk'
$ws = New-Object -ComObject WScript.Shell
$shortcut = $ws.CreateShortcut($linkPath)
$shortcut.TargetPath = $TargetPath
$shortcut.WorkingDirectory = $WorkingDirectory
$shortcut.IconLocation = "$IconPath,0"
$shortcut.Description = '启动 MOS 平台'
$shortcut.Save()

if (-not (Test-Path -LiteralPath $linkPath)) {
  throw "快捷方式创建后未找到：$linkPath"
}

Write-Output $linkPath
