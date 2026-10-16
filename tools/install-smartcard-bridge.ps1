$ErrorActionPreference = 'Stop'
$source = Join-Path $PSScriptRoot 'smartcard-bridge.ps1'
$targetDir = Join-Path $env:LOCALAPPDATA 'K-QCENTER\SmartCardBridge'
$target = Join-Path $targetDir 'smartcard-bridge.ps1'
New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
Copy-Item -LiteralPath $source -Destination $target -Force
$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$target`""
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName 'K-QCENTER Smart Card Bridge' -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
Start-ScheduledTask -TaskName 'K-QCENTER Smart Card Bridge'
Write-Output 'K-QCENTER Smart Card Bridge installed and started on 127.0.0.1:17890'
