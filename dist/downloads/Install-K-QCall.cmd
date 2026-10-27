@echo off
setlocal
set "KQCALL_URL=https://web-lab.tail53909d.ts.net:8443/dist/?registration_app=1"
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; $app=Join-Path $env:LOCALAPPDATA 'K-QCall'; New-Item -ItemType Directory -Force -Path $app | Out-Null; $icon=Join-Path $app 'K-QCall.ico'; Invoke-WebRequest -UseBasicParsing 'https://web-lab.tail53909d.ts.net:8443/dist/icons/kqcall.ico' -OutFile $icon; $candidates=@((Join-Path $env:ProgramFiles 'Google\Chrome\Application\chrome.exe'),(Join-Path ${env:ProgramFiles(x86)} 'Google\Chrome\Application\chrome.exe'),(Join-Path $env:ProgramFiles 'Microsoft\Edge\Application\msedge.exe'),(Join-Path ${env:ProgramFiles(x86)} 'Microsoft\Edge\Application\msedge.exe')); $browser=$candidates | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1; if(-not $browser){throw 'ไม่พบ Google Chrome หรือ Microsoft Edge'}; $shell=New-Object -ComObject WScript.Shell; $desktop=[Environment]::GetFolderPath('Desktop'); $programs=[Environment]::GetFolderPath('Programs'); foreach($folder in @($desktop,$programs)){ $link=Join-Path $folder 'K-QCall - ระบบเรียกคิว.lnk'; $shortcut=$shell.CreateShortcut($link); $shortcut.TargetPath=$browser; $shortcut.Arguments='--app='+[char]34+'%KQCALL_URL%'+[char]34; $shortcut.WorkingDirectory=Split-Path $browser; $shortcut.IconLocation=$icon+',0'; $shortcut.Description='K-QCall - ระบบเรียกคิว'; $shortcut.WindowStyle=1; $shortcut.Save() }; Start-Process (Join-Path $desktop 'K-QCall - ระบบเรียกคิว.lnk')"
if errorlevel 1 (
  echo ไม่สามารถติดตั้ง K-QCall ได้ กรุณาติดต่อผู้ดูแลระบบ
  pause
  exit /b 1
)
exit /b 0
