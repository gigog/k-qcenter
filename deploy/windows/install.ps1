param(
    [string]$InstallPath = 'C:\xampp\htdocs\k-qcenter',
    [string]$Repository = 'https://github.com/gigog/k-qcenter.git',
    [string]$Branch = 'main'
)

$ErrorActionPreference = 'Stop'
$git = 'C:\Program Files\Git\cmd\git.exe'
$php = 'C:\xampp\php\php.exe'

if (-not (Test-Path -LiteralPath $git)) { throw "Git not found: $git" }
if (-not (Test-Path -LiteralPath $php)) { throw "PHP not found: $php" }

$parent = Split-Path -Parent $InstallPath
if (-not (Test-Path -LiteralPath $parent)) {
    New-Item -ItemType Directory -Path $parent -Force | Out-Null
}

if (Test-Path -LiteralPath (Join-Path $InstallPath '.git')) {
    throw "Git repository already exists at $InstallPath. Run update.ps1 instead."
}
if (Test-Path -LiteralPath $InstallPath) {
    $contents = Get-ChildItem -LiteralPath $InstallPath -Force
    if ($contents.Count -gt 0) { throw "Install directory is not empty: $InstallPath" }
}

& $git clone --branch $Branch --single-branch $Repository $InstallPath
if ($LASTEXITCODE -ne 0) { throw 'Git clone failed.' }

$sessionPath = Join-Path $InstallPath 'data\sessions'
New-Item -ItemType Directory -Path $sessionPath -Force | Out-Null

& $php -l (Join-Path $InstallPath 'api\bootstrap.php')
if ($LASTEXITCODE -ne 0) { throw 'PHP syntax check failed.' }
& $php -l (Join-Path $InstallPath 'api\index.php')
if ($LASTEXITCODE -ne 0) { throw 'PHP syntax check failed.' }

Write-Host "Source installed at: $InstallPath"
Write-Host 'Start Apache and check http://127.0.0.1/k-qcenter/api/index.php?action=health'
Write-Host 'Open http://127.0.0.1/k-qcenter/ to create the first administrator account.'

