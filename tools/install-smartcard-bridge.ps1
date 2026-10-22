param(
    [string]$KioskUrl = 'https://web-lab.tail53909d.ts.net:8443/dist/kiosk.html'
)

$ErrorActionPreference = 'Stop'
$source = Join-Path $PSScriptRoot 'smartcard-bridge.ps1'
$targetDir = Join-Path $env:LOCALAPPDATA 'K-QCENTER\SmartCardBridge'
$target = Join-Path $targetDir 'smartcard-bridge.ps1'
$launcherSource = Join-Path $PSScriptRoot 'smartcard-bridge-launcher.ps1'
$launcherTarget = Join-Path $targetDir 'smartcard-bridge-launcher.ps1'
$watchdogSource = Join-Path $PSScriptRoot 'smartcard-watchdog.ps1'
$watchdogTarget = Join-Path $targetDir 'smartcard-watchdog.ps1'
$hiddenSource = Join-Path $PSScriptRoot 'smartcard-bridge-hidden.vbs'
$hiddenTarget = Join-Path $targetDir 'smartcard-bridge-hidden.vbs'
$watchdogHiddenSource = Join-Path $PSScriptRoot 'smartcard-watchdog-hidden.vbs'
$watchdogHiddenTarget = Join-Path $targetDir 'smartcard-watchdog-hidden.vbs'
$kioskLauncherSource = Join-Path $PSScriptRoot 'kiosk-single-instance-launcher.ps1'
$kioskLauncherTarget = Join-Path $targetDir 'kiosk-single-instance-launcher.ps1'
$kioskLauncherVbsSource = Join-Path $PSScriptRoot 'kiosk-single-instance-launcher.vbs'
$kioskLauncherVbsTarget = Join-Path $targetDir 'kiosk-single-instance-launcher.vbs'
if (Get-ScheduledTask -TaskName 'K-QCENTER Smart Card Watchdog' -ErrorAction SilentlyContinue) { Stop-ScheduledTask -TaskName 'K-QCENTER Smart Card Watchdog' -ErrorAction SilentlyContinue }
if (Get-ScheduledTask -TaskName 'K-QCENTER Smart Card Bridge' -ErrorAction SilentlyContinue) { Stop-ScheduledTask -TaskName 'K-QCENTER Smart Card Bridge' -ErrorAction SilentlyContinue }
Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" | Where-Object { $_.ProcessId -ne $PID -and $_.CommandLine -like "*$targetDir*" } | ForEach-Object { Invoke-CimMethod -InputObject $_ -MethodName Terminate | Out-Null }
Unregister-ScheduledTask -TaskName 'K-QCENTER Smart Card Watchdog' -Confirm:$false -ErrorAction SilentlyContinue
Unregister-ScheduledTask -TaskName 'K-QCENTER Smart Card Bridge' -Confirm:$false -ErrorAction SilentlyContinue
Start-Sleep -Milliseconds 500
New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
Copy-Item -LiteralPath $source -Destination $target -Force
Copy-Item -LiteralPath $launcherSource -Destination $launcherTarget -Force
Copy-Item -LiteralPath $watchdogSource -Destination $watchdogTarget -Force
Copy-Item -LiteralPath $hiddenSource -Destination $hiddenTarget -Force
Copy-Item -LiteralPath $watchdogHiddenSource -Destination $watchdogHiddenTarget -Force
Copy-Item -LiteralPath $kioskLauncherSource -Destination $kioskLauncherTarget -Force
Copy-Item -LiteralPath $kioskLauncherVbsSource -Destination $kioskLauncherVbsTarget -Force
[IO.File]::WriteAllText((Join-Path $targetDir 'kiosk-url.txt'), $KioskUrl, [Text.UTF8Encoding]::new($false))
$action = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument "`"$hiddenTarget`""
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName 'K-QCENTER Smart Card Bridge' -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
$watchdogAction = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument "`"$watchdogHiddenTarget`""
$watchdogTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$watchdogSettings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName 'K-QCENTER Smart Card Watchdog' -Action $watchdogAction -Trigger $watchdogTrigger -Principal $principal -Settings $watchdogSettings -Force | Out-Null
Start-Process -FilePath 'wscript.exe' -ArgumentList "`"$hiddenTarget`"" -WindowStyle Hidden

$edgeCandidates = @(
    (Join-Path ${env:ProgramFiles(x86)} 'Microsoft\Edge\Application\msedge.exe'),
    (Join-Path $env:ProgramFiles 'Microsoft\Edge\Application\msedge.exe')
) | Where-Object { $_ -and (Test-Path -LiteralPath $_) }
if (-not $edgeCandidates) { throw 'ไม่พบ Microsoft Edge กรุณาติดตั้ง Edge ก่อนสร้าง K-QCENTER Kiosk Launcher' }
$edgePath = $edgeCandidates[0]
$desktop = [Environment]::GetFolderPath('Desktop')
$shortcutPath = Join-Path $desktop 'K-QCENTER Kiosk.lnk'
$edgeProfile = Join-Path $targetDir 'EdgeKioskProfile'
New-Item -ItemType Directory -Path $edgeProfile -Force | Out-Null
$shell = New-Object -ComObject WScript.Shell
$shortcut = $shell.CreateShortcut($shortcutPath)
$shortcut.TargetPath = "$env:WINDIR\System32\wscript.exe"
$shortcut.Arguments = "`"$kioskLauncherVbsTarget`""
$shortcut.WorkingDirectory = $targetDir
$shortcut.IconLocation = "$edgePath,0"
$shortcut.Description = 'K-QCENTER Kiosk - single window and refresh on repeated launch'
$shortcut.Save()

Write-Output 'K-QCENTER Smart Card and Silent Print Bridge installed and started on 127.0.0.1:17890'
Write-Output "K-QCENTER Kiosk shortcut created: $shortcutPath"
Write-Output 'Silent printing uses the Windows default printer and a dedicated Edge profile. Close the old PWA/browser window and open Kiosk from this shortcut.'
