$ErrorActionPreference = 'Stop'
$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$venv = Join-Path $PSScriptRoot '.venv'
$python = Join-Path $venv 'Scripts\python.exe'
function Invoke-Checked {
  param([scriptblock]$Command, [string]$Step)
  & $Command
  if ($LASTEXITCODE -ne 0) { throw "$Step failed with exit code $LASTEXITCODE" }
}
if (-not (Test-Path $python)) {
  python -m venv $venv
  if ($LASTEXITCODE -ne 0) { throw 'Unable to create Python virtual environment' }
}
& $python -c 'import PySide6, PyInstaller' 2>$null
if ($LASTEXITCODE -ne 0) {
  Invoke-Checked { & $python -m pip install --upgrade pip } 'pip upgrade'
  Invoke-Checked { & $python -m pip install -r (Join-Path $PSScriptRoot 'requirements.txt') } 'dependency installation'
}
$output = Join-Path $root 'dist\downloads'
Invoke-Checked { & $python -m PyInstaller --noconfirm --clean --onefile --windowed `
  --name 'K-QCall' `
  --icon (Join-Path $root 'dist\icons\kqcall.ico') `
  --add-data ((Join-Path $root 'dist\icons\kqcall.ico') + ';.') `
  --distpath $output `
  --workpath (Join-Path $PSScriptRoot 'build') `
  --specpath $PSScriptRoot `
  (Join-Path $PSScriptRoot 'kqcall.py') } 'PyInstaller build'
if (-not (Test-Path (Join-Path $output 'K-QCall.exe'))) { throw 'K-QCall.exe was not created' }
Write-Host "Built: $output\K-QCall.exe"
