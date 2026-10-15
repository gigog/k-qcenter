param(
    [string]$InstallPath = 'C:\xampp\htdocs\k-qcenter',
    [string]$Branch = 'main',
    [int]$BackupRetentionDays = 30
)

$ErrorActionPreference = 'Stop'
$git = 'C:\Program Files\Git\cmd\git.exe'
$php = 'C:\xampp\php\php.exe'
$repoPath = Join-Path $InstallPath '.git'
$dbPath = Join-Path $InstallPath 'data\kqcenter.sqlite'
$backupDirectory = Join-Path $InstallPath 'data\backups'

if (-not (Test-Path -LiteralPath $repoPath)) { throw "Repository not found: $InstallPath" }
if (-not (Test-Path -LiteralPath $git)) { throw "Git not found: $git" }

New-Item -ItemType Directory -Path $backupDirectory -Force | Out-Null
if (Test-Path -LiteralPath $dbPath) {
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    Copy-Item -LiteralPath $dbPath -Destination (Join-Path $backupDirectory "kqcenter-$stamp.sqlite")
}

& $git -C $InstallPath fetch origin $Branch
if ($LASTEXITCODE -ne 0) { throw 'Git fetch failed.' }
& $git -C $InstallPath pull --ff-only origin $Branch
if ($LASTEXITCODE -ne 0) { throw 'Git fast-forward pull failed. No files were force-overwritten.' }

& $php -l (Join-Path $InstallPath 'api\bootstrap.php')
if ($LASTEXITCODE -ne 0) { throw 'PHP syntax check failed.' }
& $php -l (Join-Path $InstallPath 'api\index.php')
if ($LASTEXITCODE -ne 0) { throw 'PHP syntax check failed.' }

$cutoff = (Get-Date).AddDays(-$BackupRetentionDays)
Get-ChildItem -LiteralPath $backupDirectory -Filter 'kqcenter-*.sqlite' -File |
    Where-Object { $_.LastWriteTime -lt $cutoff } |
    Remove-Item -Force

$health = Invoke-RestMethod 'http://127.0.0.1/k-qcenter/api/index.php?action=health' -TimeoutSec 15
if (-not $health.ok) { throw 'Health check failed.' }
Write-Host "K-QCENTER updated. Database: $($health.database)"

