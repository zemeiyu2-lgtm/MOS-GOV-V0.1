' MOSLauncher.vbs - hidden launcher host (no console window)
' Resolves the MOS-GOV install directory relative to this script and runs
' MOSLauncher.ps1 with a fully hidden PowerShell window.
Option Explicit

Dim sh, fso, scriptDir, installDir, ps
Set sh = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

scriptDir = fso.GetParentFolderName(WScript.ScriptFullName)
installDir = fso.GetParentFolderName(scriptDir)

ps = sh.ExpandEnvironmentStrings("%SystemRoot%") & "\System32\WindowsPowerShell\v1.0\powershell.exe"

sh.CurrentDirectory = installDir
sh.Run """" & ps & """ -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File """ & _
    scriptDir & "\MOSLauncher.ps1"" -RuntimeDir """ & scriptDir & """", 0, False
