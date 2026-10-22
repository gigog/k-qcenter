@echo off
chcp 65001 >nul
title Uninstall K-QCENTER Print Agent
echo Uninstalling K-QCENTER Print Agent...
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0uninstall-kq-print-agent.ps1"
if errorlevel 1 (
  echo.
  echo Uninstallation failed. Please send this screen to the administrator.
  pause
  exit /b 1
)
echo.
echo Uninstallation completed.
pause
