@echo off
cd /d "%~dp0.."
echo.
echo  MANIT Grievance Portal DEMO
echo  Opening http://localhost:8080 in your browser in a few seconds.
echo  KEEP THIS WINDOW OPEN while presenting. Close it (or press Ctrl+C) to stop the portal.
echo.
start "" /min cmd /c "timeout /t 4 /nobreak >nul & start http://localhost:8080"
php spark serve --port 8080
pause
