@echo off
setlocal
set "LOGDIR=%ProgramData%\MOS-GOV\logs"
if not exist "%LOGDIR%" mkdir "%LOGDIR%" >nul 2>&1
echo %DATE% %TIME% install-runtime.cmd entry > "%LOGDIR%\runtime-entry.txt"
"%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-runtime.ps1" > "%LOGDIR%\runtime-console.log" 2>&1
set "EXITCODE=%ERRORLEVEL%"
echo %DATE% %TIME% install-runtime.ps1 exit code %EXITCODE% >> "%LOGDIR%\runtime-entry.txt"
exit /b %EXITCODE%
