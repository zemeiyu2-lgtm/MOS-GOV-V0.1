@echo off
rem MOS-Diagnose.cmd - MOS 平台一键诊断（双击运行）
setlocal
set "LOGDIR=%ProgramData%\MOS-GOV\logs"
if not exist "%LOGDIR%" mkdir "%LOGDIR%" >nul 2>&1
"%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "%~dp0MOS-Diagnose.ps1"
set "EXITCODE=%ERRORLEVEL%"
echo.
pause
exit /b %EXITCODE%
