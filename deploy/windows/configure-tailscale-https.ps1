[CmdletBinding()]
param(
    [string]$ProjectPath = 'C:\xampp\htdocs\k-qcenter',
    [string]$ApacheRoot = 'C:\xampp\apache',
    [switch]$SkipFunnel,
    [switch]$SkipServe
)

$ErrorActionPreference = 'Stop'

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = [Security.Principal.WindowsPrincipal]::new($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this script from PowerShell as Administrator.'
}

$resolvedProject = [IO.Path]::GetFullPath($ProjectPath)
$resolvedApache = [IO.Path]::GetFullPath($ApacheRoot)
$publicRoot = Join-Path $resolvedProject 'public'
$sourceVhost = Join-Path $resolvedProject 'deploy\windows\apache-kqcenter-public.conf'
$apacheConf = Join-Path $resolvedApache 'conf\httpd.conf'
$targetVhost = Join-Path $resolvedApache 'conf\extra\httpd-kqcenter-public.conf'
$httpd = Join-Path $resolvedApache 'bin\httpd.exe'

foreach ($required in @($publicRoot, $sourceVhost, $apacheConf, $httpd)) {
    if (-not (Test-Path -LiteralPath $required)) { throw "Required path not found: $required" }
}

$tailscaleCandidates = @(
    (Join-Path $env:ProgramFiles 'Tailscale\tailscale.exe'),
    (Join-Path ${env:ProgramFiles(x86)} 'Tailscale\tailscale.exe')
)
$tailscale = $tailscaleCandidates | Where-Object { $_ -and (Test-Path -LiteralPath $_) } | Select-Object -First 1
if (-not $tailscale) { throw 'Tailscale CLI was not found. Install Tailscale on the server first.' }

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
Copy-Item -LiteralPath $apacheConf -Destination "$apacheConf.$stamp.bak"
Copy-Item -LiteralPath $sourceVhost -Destination $targetVhost -Force

$includeLine = 'Include "conf/extra/httpd-kqcenter-public.conf"'
$httpdText = Get-Content -LiteralPath $apacheConf -Raw
if ($httpdText -notmatch [regex]::Escape($includeLine)) {
    Add-Content -LiteralPath $apacheConf -Value "`r`n# K-QCENTER public gateway`r`n$includeLine`r`n"
}

& $httpd -t
if ($LASTEXITCODE -ne 0) { throw 'Apache configuration test failed.' }

$apacheProcess = Get-Process -Name httpd -ErrorAction SilentlyContinue
if ($apacheProcess) {
    & $httpd -k restart
} else {
    & $httpd -k start
}

Start-Sleep -Seconds 2
$publicHealth = Invoke-RestMethod 'http://127.0.0.1:8088/api/index.php?action=health' -TimeoutSec 10
if (-not $publicHealth.ok) { throw 'Public gateway health check failed.' }

if (-not $SkipFunnel) {
    & $tailscale funnel --bg --https=443 http://127.0.0.1:8088
    if ($LASTEXITCODE -ne 0) { throw 'Tailscale Funnel configuration failed.' }
}
if (-not $SkipServe) {
    & $tailscale serve --bg --https=8443 http://127.0.0.1:80
    if ($LASTEXITCODE -ne 0) { throw 'Tailscale Serve configuration failed.' }
}

Write-Host ''
Write-Host '=== K-QCENTER HTTPS status ==='
& $tailscale funnel status
& $tailscale serve status
Write-Host ''
Write-Host 'Public Funnel exposes only K-QOnline, Status, and Public API on HTTPS :443.'
Write-Host 'Private Serve exposes Admin and Kiosk to Tailnet devices on HTTPS :8443.'

