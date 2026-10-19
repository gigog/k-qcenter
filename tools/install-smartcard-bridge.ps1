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
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$launcherTarget`""
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName 'K-QCENTER Smart Card Bridge' -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
$watchdogAction = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$watchdogTarget`""
$watchdogTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$watchdogSettings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName 'K-QCENTER Smart Card Watchdog' -Action $watchdogAction -Trigger $watchdogTrigger -Principal $principal -Settings $watchdogSettings -Force | Out-Null
$bridgeArguments = "-NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$launcherTarget`""
Start-Process -FilePath 'powershell.exe' -ArgumentList $bridgeArguments -WindowStyle Hidden
Write-Output 'K-QCENTER Smart Card Bridge installed and started on 127.0.0.1:17890'
