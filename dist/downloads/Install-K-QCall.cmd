@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; $url='https://web-lab.tail53909d.ts.net:8443/dist/downloads/Install-K-QCall.ps1'; $file=Join-Path $env:TEMP 'Install-K-QCall.ps1'; Invoke-WebRequest -UseBasicParsing $url -OutFile $file; powershell.exe -NoProfile -STA -ExecutionPolicy Bypass -File $file; if($LASTEXITCODE -ne 0){exit $LASTEXITCODE}"
if errorlevel 1 (
  echo K-QCall installation failed. Please contact the administrator.
  pause
  exit /b 1
)
exit /b 0
