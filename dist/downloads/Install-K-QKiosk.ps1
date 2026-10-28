$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms

$baseUrl = 'https://web-lab.tail53909d.ts.net:8443/dist/downloads'
$appDir = Join-Path $env:LOCALAPPDATA 'K-QKiosk'
$appExe = Join-Path $appDir 'K-QKiosk.exe'
$newExe = Join-Path $appDir 'K-QKiosk.exe.download'
$hashFile = Join-Path $appDir 'K-QKiosk.sha256'

New-Item -ItemType Directory -Force -Path $appDir | Out-Null
Get-CimInstance Win32_Process -Filter "Name = 'K-QKiosk.exe'" -ErrorAction SilentlyContinue |
  Where-Object { $_.ExecutablePath -eq $appExe } |
  ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }

Remove-Item $newExe -Force -ErrorAction SilentlyContinue
Invoke-WebRequest -UseBasicParsing "$baseUrl/K-QKiosk.exe" -OutFile $newExe
Invoke-WebRequest -UseBasicParsing "$baseUrl/K-QKiosk.sha256" -OutFile $hashFile

$expected = (Get-Content -Raw $hashFile).Trim().Split(' ')[0].ToUpperInvariant()
$actual = (Get-FileHash -Path $newExe -Algorithm SHA256).Hash.ToUpperInvariant()
if ($actual -ne $expected) {
  Remove-Item $newExe -Force -ErrorAction SilentlyContinue
  throw 'ไฟล์ K-QKiosk.exe ไม่สมบูรณ์ กรุณาดาวน์โหลดใหม่'
}

Move-Item -Force $newExe $appExe
$shell = New-Object -ComObject WScript.Shell
$desktop = [Environment]::GetFolderPath('Desktop')
$programs = [Environment]::GetFolderPath('Programs')
foreach ($folder in @($desktop, $programs)) {
  $link = Join-Path $folder 'K-QKiosk - ระบบบริการคิวอัตโนมัติ.lnk'
  $shortcut = $shell.CreateShortcut($link)
  $shortcut.TargetPath = $appExe
  $shortcut.WorkingDirectory = $appDir
  $shortcut.IconLocation = "$appExe,0"
  $shortcut.Description = 'K-QKiosk - ระบบบริการคิวอัตโนมัติ'
  $shortcut.WindowStyle = 3
  $shortcut.Save()
}

Start-Process $appExe
[Windows.Forms.MessageBox]::Show(
  'ติดตั้ง K-QKiosk เรียบร้อยแล้ว และสร้างไอคอนบน Desktop แล้ว',
  'K-QKiosk - ระบบบริการคิวอัตโนมัติ'
) | Out-Null
