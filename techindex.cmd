@echo off
rem Lance techindex.ps1 sans modifier la strategie d'execution PowerShell du poste.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0techindex.ps1" %*
