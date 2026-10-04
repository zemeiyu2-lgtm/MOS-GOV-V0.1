param(
  [Parameter(Mandatory=$true)][string]$TargetPath,
  [Parameter(Mandatory=$true)][string]$WorkingDirectory,
  [Parameter(Mandatory=$true)][string]$IconPath
)

$ErrorActionPreference = 'Stop'
$logPath = Join-Path $env:TEMP 'MOS-GOV-shortcut.log'

try {
  "[$(Get-Date -Format o)] shortcut helper start" | Set-Content -LiteralPath $logPath -Encoding UTF8
  "TargetPath=$TargetPath" | Add-Content -LiteralPath $logPath -Encoding UTF8
  "WorkingDirectory=$WorkingDirectory" | Add-Content -LiteralPath $logPath -Encoding UTF8
  "IconPath=$IconPath" | Add-Content -LiteralPath $logPath -Encoding UTF8
  "User=$env:USERNAME" | Add-Content -LiteralPath $logPath -Encoding UTF8

  $desktop = [Environment]::GetFolderPath('Desktop')
  if ([string]::IsNullOrWhiteSpace($desktop)) {
    $desktop = Join-Path $env:USERPROFILE 'Desktop'
  }
  if (-not (Test-Path -LiteralPath $desktop)) {
    New-Item -ItemType Directory -Path $desktop -Force | Out-Null
  }
  "Desktop=$desktop" | Add-Content -LiteralPath $logPath -Encoding UTF8

  # WScript.Shell 的 CreateShortcut().Save() 在部分 Windows/代码页环境下
  # 不能直接创建含中文的 .lnk 文件名。先用纯 ASCII 临时名创建，再用
  # Windows PowerShell 的 Unicode 文件系统 API 重命名为最终中文名称。
  $tempLinkPath = Join-Path $desktop 'MOS-GOV.lnk'
  $linkPath = Join-Path $desktop 'MOS 平台.lnk'

  if (Test-Path -LiteralPath $tempLinkPath) {
    Remove-Item -LiteralPath $tempLinkPath -Force
  }
  if (Test-Path -LiteralPath $linkPath) {
    Remove-Item -LiteralPath $linkPath -Force
  }

  $ws = New-Object -ComObject WScript.Shell
  $shortcut = $ws.CreateShortcut($tempLinkPath)
  $shortcut.TargetPath = $TargetPath
  $shortcut.WorkingDirectory = $WorkingDirectory
  $shortcut.IconLocation = "$IconPath,0"
  $shortcut.Description = '启动 MOS 平台'
  $shortcut.Save()

  if (-not (Test-Path -LiteralPath $tempLinkPath)) {
    throw "临时快捷方式创建失败：$tempLinkPath"
  }

  Move-Item -LiteralPath $tempLinkPath -Destination $linkPath -Force

  if (-not (Test-Path -LiteralPath $linkPath)) {
    throw "快捷方式重命名后未找到：$linkPath"
  }

  "SUCCESS=$linkPath" | Add-Content -LiteralPath $logPath -Encoding UTF8
  Write-Output $linkPath
  exit 0
}
catch {
  "FAILED=$($_.Exception.GetType().FullName)" | Add-Content -LiteralPath $logPath -Encoding UTF8
  "MESSAGE=$($_.Exception.Message)" | Add-Content -LiteralPath $logPath -Encoding UTF8
  "HResult=$($_.Exception.HResult)" | Add-Content -LiteralPath $logPath -Encoding UTF8
  exit 1
}
