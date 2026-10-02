@echo off
setlocal
"%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "%~dp0uninstall-runtime.ps1"
set "EXITCODE=%ERRORLEVEL%"
exit /b %EXITCODE%
