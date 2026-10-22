@echo off
chcp 65001 >nul
title K-QCENTER Print Agent Setup
echo Installing K-QCENTER Print Agent...
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-kq-print-agent.ps1"
if errorlevel 1 (
  echo.
  echo Installation failed. Please send this screen to the administrator.
  pause
  exit /b 1
)
echo.
echo Installation completed. You can now close this window.
pause
