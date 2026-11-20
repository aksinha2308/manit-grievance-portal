@echo off
cd /d "%~dp0"
echo Starting the local setup. This takes a few minutes.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup-local.ps1"
echo.
pause
