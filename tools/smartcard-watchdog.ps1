$ErrorActionPreference = 'SilentlyContinue'
$listening = Get-NetTCPConnection -LocalAddress 127.0.0.1 -LocalPort 17890 -State Listen -ErrorAction SilentlyContinue
if ($listening) { exit 0 }
$task = Get-ScheduledTask -TaskName 'K-QCENTER Smart Card Bridge' -ErrorAction SilentlyContinue
if (-not $task) { exit 2 }
if ($task.State -eq 'Running') { Stop-ScheduledTask -TaskName 'K-QCENTER Smart Card Bridge' }
Start-ScheduledTask -TaskName 'K-QCENTER Smart Card Bridge'
