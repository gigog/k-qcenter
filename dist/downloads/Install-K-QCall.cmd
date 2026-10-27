@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "$ErrorActionPreference='Stop'; $base='https://web-lab.tail53909d.ts.net:8443/dist'; $app=Join-Path $env:LOCALAPPDATA 'K-QCall'; New-Item -ItemType Directory -Force -Path $app | Out-Null; $icon=Join-Path $app 'K-QCall.ico'; $controller=Join-Path $app 'K-QCall-Window.ps1'; Invoke-WebRequest -UseBasicParsing ($base+'/icons/kqcall.ico') -OutFile $icon; Invoke-WebRequest -UseBasicParsing ($base+'/downloads/K-QCall-Window.ps1') -OutFile $controller; $shell=New-Object -ComObject WScript.Shell; $desktop=[Environment]::GetFolderPath('Desktop'); $programs=[Environment]::GetFolderPath('Programs'); $powershell=(Get-Command powershell.exe).Source; foreach($folder in @($desktop,$programs)){ $link=Join-Path $folder 'K-QCall - ระบบเรียกคิว.lnk'; $shortcut=$shell.CreateShortcut($link); $shortcut.TargetPath=$powershell; $shortcut.Arguments='-NoProfile -STA -WindowStyle Hidden -ExecutionPolicy Bypass -File '+[char]34+$controller+[char]34; $shortcut.WorkingDirectory=$app; $shortcut.IconLocation=$icon+',0'; $shortcut.Description='K-QCall - ระบบเรียกคิว'; $shortcut.WindowStyle=7; $shortcut.Save() }; Start-Process (Join-Path $desktop 'K-QCall - ระบบเรียกคิว.lnk')"
if errorlevel 1 (
  echo ไม่สามารถติดตั้ง K-QCall ได้ กรุณาติดต่อผู้ดูแลระบบ
  pause
  exit /b 1
)
exit /b 0
