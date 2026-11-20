@echo off
cd /d "%~dp0.."
echo Latest OTP codes (newest at the bottom). The code is the 6-digit number.
echo.
powershell -NoProfile -Command "$f = Get-ChildItem writable\logs\otp-*.log -ErrorAction SilentlyContinue | Sort-Object LastWriteTime | Select-Object -Last 1; if ($f) { Get-Content $f.FullName -Tail 8 } else { Write-Host 'No OTP has been requested yet.' }"
echo.
pause
