#Requires -Version 5.1
[CmdletBinding()]
param()

$ErrorActionPreference = "Stop"
$scriptPath = $MyInvocation.MyCommand.Path

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole(
    [Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Start-Process -FilePath "powershell.exe" -Verb RunAs -ArgumentList @(
        "-NoProfile",
        "-ExecutionPolicy", "Bypass",
        "-File", $scriptPath
    ) | Out-Null
    exit 0
}

Write-Host ""
Write-Host "==============================================" -ForegroundColor Yellow
Write-Host " MOS-GOV：新建教会 / 清除旧教会数据" -ForegroundColor Yellow
Write-Host "==============================================" -ForegroundColor Yellow
Write-Host ""
Write-Host "此操作会：" -ForegroundColor Cyan
Write-Host "  1. 停止 MOS-GOV 服务"
Write-Host "  2. 将当前 MariaDB 数据目录完整移入 ProgramData\MOS-GOV\backups"
Write-Host "  3. 创建全新的 ChurchCRM 数据库"
Write-Host "  4. 重新初始化 MOS-GOV"
Write-Host "  5. 保留程序、端口和安装路径"
Write-Host ""
Write-Host "原教会数据不会直接删除，而会先保存在本机备份目录。" -ForegroundColor Green
Write-Host ""

$confirm = Read-Host '确认开始请输入 NEW CHURCH；取消请直接按 Enter'
if ($confirm -ne "NEW CHURCH") {
    Write-Host "已取消，没有修改任何教会数据。" -ForegroundColor Green
    exit 0
}

$runtime = Join-Path $PSScriptRoot "install-runtime.ps1"
if (-not (Test-Path -LiteralPath $runtime)) {
    throw "找不到安装运行脚本：$runtime"
}

& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $runtime -ResetData
$exitCode = $LASTEXITCODE

if ($exitCode -eq 0) {
    Write-Host ""
    Write-Host "新教会环境已建立。" -ForegroundColor Green
    Write-Host "旧数据备份位置：C:\ProgramData\MOS-GOV\backups" -ForegroundColor Green
    Write-Host ""
    Read-Host "按 Enter 结束"
} else {
    Write-Host ""
    Write-Host "新教会初始化失败，返回码：$exitCode" -ForegroundColor Red
    Write-Host "请查看 C:\ProgramData\MOS-GOV\logs\installer.log 和 runtime-console.log" -ForegroundColor Yellow
    Read-Host "按 Enter 结束"
    exit $exitCode
}
