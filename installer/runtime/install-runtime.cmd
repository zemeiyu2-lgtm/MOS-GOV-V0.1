@echo off
setlocal EnableExtensions
set "BOOTSTRAP_LOG=%TEMP%\MOS-GOV-runtime-bootstrap.log"
set "LOGDIR=%ProgramData%\MOS-GOV\logs"

> "%BOOTSTRAP_LOG%" echo [%DATE% %TIME%] install-runtime.cmd entry %*
if not exist "%LOGDIR%" mkdir "%LOGDIR%" >> "%BOOTSTRAP_LOG%" 2>&1
>> "%BOOTSTRAP_LOG%" echo [%DATE% %TIME%] LOGDIR=%LOGDIR%
>> "%BOOTSTRAP_LOG%" echo [%DATE% %TIME%] Script=%~dp0install-runtime.ps1

if not exist "%~dp0install-runtime.ps1" (
  >> "%BOOTSTRAP_LOG%" echo [%DATE% %TIME%] FATAL: install-runtime.ps1 not found
  exit /b 2
)

set "PS=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"
if not exist "%PS%" (
  >> "%BOOTSTRAP_LOG%" echo [%DATE% %TIME%] FATAL: Windows PowerShell 5.1 not found
  exit /b 3
)

if /I "%~1"=="/reset-data" (
  >> "%BOOTSTRAP_LOG%" echo [%DATE% %TIME%] Launching PowerShell -ResetData
  "%PS%" -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%~dp0install-runtime.ps1" -ResetData >> "%BOOTSTRAP_LOG%" 2>&1
) else (
  >> "%BOOTSTRAP_LOG%" echo [%DATE% %TIME%] Launching PowerShell
  "%PS%" -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%~dp0install-runtime.ps1" >> "%BOOTSTRAP_LOG%" 2>&1
)

set "EXITCODE=%ERRORLEVEL%"
>> "%BOOTSTRAP_LOG%" echo [%DATE% %TIME%] install-runtime.ps1 exit code %EXITCODE%
if exist "%LOGDIR%" copy /Y "%BOOTSTRAP_LOG%" "%LOGDIR%\bootstrap.log" >nul 2>&1
exit /b %EXITCODE%
