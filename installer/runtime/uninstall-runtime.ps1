#Requires -RunAsAdministrator
#Requires -Version 5.1
$ErrorActionPreference = "SilentlyContinue"

foreach ($service in @("MOS-GOV-Apache","MOS-GOV-MariaDB")) {
    if (Get-Service -Name $service -ErrorAction SilentlyContinue) {
        Stop-Service $service -Force
        & sc.exe delete $service | Out-Null
    }
}

Write-Host "MOS-GOV services removed."
Write-Host "Church data under $env:ProgramData\MOS-GOV has been preserved."
exit 0
