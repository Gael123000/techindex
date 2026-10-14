@echo off
rem Environnement local SANS Docker (tests) : voir techindex-local.ps1.
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0techindex-local.ps1" %*
