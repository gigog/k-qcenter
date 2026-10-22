$ErrorActionPreference = 'Stop'
$targetDir = Join-Path $env:LOCALAPPDATA 'K-QCENTER\PrintAgent'
$agentTarget = Join-Path $targetDir 'kq-print-agent.ps1'
$hiddenTarget = Join-Path $targetDir 'kq-print-agent-hidden.vbs'
$taskName = 'K-QCENTER Print Agent'

if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) {
    Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
}
Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" | Where-Object { $_.ProcessId -ne $PID -and $_.CommandLine -like "*$agentTarget*" } | ForEach-Object { Invoke-CimMethod -InputObject $_ -MethodName Terminate | Out-Null }
New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'kq-print-agent.ps1') -Destination $agentTarget -Force
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'kq-print-agent-hidden.vbs') -Destination $hiddenTarget -Force
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'Sarabun-Regular.ttf') -Destination (Join-Path $targetDir 'Sarabun-Regular.ttf') -Force
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'Sarabun-Bold.ttf') -Destination (Join-Path $targetDir 'Sarabun-Bold.ttf') -Force

$action = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument "`"$hiddenTarget`""
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -RestartCount 10 -RestartInterval (New-TimeSpan -Minutes 1)
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'K-QCENTER silent printing service on 127.0.0.1:17891' -Force | Out-Null
Start-Process -FilePath 'wscript.exe' -ArgumentList "`"$hiddenTarget`"" -WindowStyle Hidden
Start-Sleep -Seconds 2
$health = Invoke-RestMethod -Uri 'http://127.0.0.1:17891/health' -Headers @{ Origin='https://web-lab.tail53909d.ts.net:8443' } -TimeoutSec 5
if (-not $health.ok -or [int]$health.agent_version -lt 3) { throw 'Print Agent เริ่มทำงานไม่สำเร็จหรือเป็นรุ่นเก่า' }
if (-not $health.printer_ready) { throw 'ติดตั้ง Agent แล้ว แต่ Windows ยังไม่มี Default Printer ที่พร้อมใช้งาน' }
Write-Output 'K-QCENTER Print Agent installed on 127.0.0.1:17891'
Write-Output "Default printer: $($health.default_printer)"
Write-Output 'Printing uses the Windows default printer without browser preview.'
