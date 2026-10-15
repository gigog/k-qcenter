param(
    [string]$InstallPath = 'C:\xampp\htdocs\k-qcenter',
    [string]$BackupPath = 'D:\K-QCENTER-Backup'
)

$ErrorActionPreference = 'Stop'
$dbPath = Join-Path $InstallPath 'data\kqcenter.sqlite'
if (-not (Test-Path -LiteralPath $dbPath)) { throw "ไม่พบฐานข้อมูล: $dbPath" }
New-Item -ItemType Directory -Path $BackupPath -Force | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$target = Join-Path $BackupPath "kqcenter-$stamp.sqlite"
Copy-Item -LiteralPath $dbPath -Destination $target
Write-Host "สำรองฐานข้อมูลแล้ว: $target"

