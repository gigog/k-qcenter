$ErrorActionPreference = 'Stop'
$taskName = 'K-QCENTER Print Agent'
$targetDir = Join-Path $env:LOCALAPPDATA 'K-QCENTER\PrintAgent'
$agentTarget = Join-Path $targetDir 'kq-print-agent.ps1'

if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) {
    Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
}

Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" |
    Where-Object { $_.ProcessId -ne $PID -and $_.CommandLine -like "*$agentTarget*" } |
    ForEach-Object { Invoke-CimMethod -InputObject $_ -MethodName Terminate | Out-Null }

Start-Sleep -Milliseconds 500
if (Test-Path -LiteralPath $targetDir) {
    $resolvedTarget = [IO.Path]::GetFullPath($targetDir)
    $expectedRoot = [IO.Path]::GetFullPath((Join-Path $env:LOCALAPPDATA 'K-QCENTER'))
    if (-not $resolvedTarget.StartsWith($expectedRoot,[StringComparison]::OrdinalIgnoreCase)) {
        throw 'ตำแหน่งถอนการติดตั้งไม่ปลอดภัย ระบบจึงยกเลิกการทำงาน'
    }
    Remove-Item -LiteralPath $resolvedTarget -Recurse -Force
}

Write-Output 'K-QCENTER Print Agent uninstalled successfully.'
