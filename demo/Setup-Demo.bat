@echo off
cd /d "%~dp0"
echo Starting the demo setup. This takes a few minutes.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup-demo.ps1"
echo.
pause
