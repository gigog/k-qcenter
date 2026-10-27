$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Windows.Forms

$baseUrl = 'https://web-lab.tail53909d.ts.net:8443/dist/downloads'
$appDir = Join-Path $env:LOCALAPPDATA 'K-QCall'
$appExe = Join-Path $appDir 'K-QCall.exe'
$newExe = Join-Path $appDir 'K-QCall.exe.download'
$hashFile = Join-Path $appDir 'K-QCall.sha256'

New-Item -ItemType Directory -Force -Path $appDir | Out-Null

# Stop only the previous K-QCall desktop shell/controller installed in this folder.
Get-CimInstance Win32_Process -Filter "Name = 'K-QCall.exe'" -ErrorAction SilentlyContinue |
  Where-Object { $_.ExecutablePath -eq $appExe } |
  ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe'" -ErrorAction SilentlyContinue |
  Where-Object { $_.CommandLine -like '*K-QCall-Window.ps1*' } |
  ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
  Where-Object {
    $_.Name -in @('chrome.exe', 'msedge.exe') -and
    $_.CommandLine -like '*--app=*registration_app=1*'
  } |
  ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }

Remove-Item $newExe -Force -ErrorAction SilentlyContinue
Invoke-WebRequest -UseBasicParsing "$baseUrl/K-QCall.exe" -OutFile $newExe
Invoke-WebRequest -UseBasicParsing "$baseUrl/K-QCall.sha256" -OutFile $hashFile

$expected = (Get-Content -Raw $hashFile).Trim().Split(' ')[0].ToUpperInvariant()
$actual = (Get-FileHash -Path $newExe -Algorithm SHA256).Hash.ToUpperInvariant()
if ($actual -ne $expected) {
  Remove-Item $newExe -Force -ErrorAction SilentlyContinue
  throw 'ไฟล์ K-QCall.exe ไม่สมบูรณ์ กรุณาดาวน์โหลดใหม่'
}

Move-Item -Force $newExe $appExe
Remove-Item (Join-Path $appDir 'K-QCall-Window.ps1') -Force -ErrorAction SilentlyContinue

$shell = New-Object -ComObject WScript.Shell
$desktop = [Environment]::GetFolderPath('Desktop')
$programs = [Environment]::GetFolderPath('Programs')
foreach ($folder in @($desktop, $programs)) {
  $link = Join-Path $folder 'K-QCall - ระบบเรียกคิว.lnk'
  $shortcut = $shell.CreateShortcut($link)
  $shortcut.TargetPath = $appExe
  $shortcut.WorkingDirectory = $appDir
  $shortcut.IconLocation = "$appExe,0"
  $shortcut.Description = 'K-QCall - ระบบเรียกคิว'
  $shortcut.WindowStyle = 1
  $shortcut.Save()
}

Start-Process $appExe
[Windows.Forms.MessageBox]::Show(
  'ติดตั้ง K-QCall เรียบร้อยแล้ว และสร้างไอคอนบน Desktop แล้ว',
  'K-QCall - ระบบเรียกคิว'
) | Out-Null
