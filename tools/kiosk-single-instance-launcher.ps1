$ErrorActionPreference = 'Stop'

$root = $PSScriptRoot
$profilePath = Join-Path $root 'EdgeKioskProfile'
$urlPath = Join-Path $root 'kiosk-url.txt'
$kioskUrl = if (Test-Path -LiteralPath $urlPath) { ([IO.File]::ReadAllText($urlPath, [Text.Encoding]::UTF8)).Trim() } else { 'https://web-lab.tail53909d.ts.net:8443/dist/kiosk.html' }
$mutex = [Threading.Mutex]::new($false, 'Local\K-QCENTER-Kiosk-Launcher')

try {
    if (-not $mutex.WaitOne(5000)) { exit 0 }
    $profileNeedle = [Regex]::Escape($profilePath)
    $existing = Get-CimInstance Win32_Process -Filter "Name='msedge.exe'" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -match $profileNeedle }
    if ($existing) {
        Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
public static class KqKioskWindow {
    [DllImport("user32.dll")] public static extern bool ShowWindow(IntPtr hWnd, int nCmdShow);
    [DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr hWnd);
}
'@
        $window = Get-Process -Id ($existing.ProcessId) -ErrorAction SilentlyContinue | Where-Object { $_.MainWindowHandle -ne 0 } | Select-Object -First 1
        if ($window) {
            [KqKioskWindow]::ShowWindow($window.MainWindowHandle, 9) | Out-Null
            [KqKioskWindow]::SetForegroundWindow($window.MainWindowHandle) | Out-Null
            $shell = New-Object -ComObject WScript.Shell
            if ($shell.AppActivate($window.Id)) { Start-Sleep -Milliseconds 180; $shell.SendKeys('{F5}') }
        }
        exit 0
    }

    $edgeCandidates = @(
        (Join-Path ${env:ProgramFiles(x86)} 'Microsoft\Edge\Application\msedge.exe'),
        (Join-Path $env:ProgramFiles 'Microsoft\Edge\Application\msedge.exe')
    ) | Where-Object { $_ -and (Test-Path -LiteralPath $_) }
    if (-not $edgeCandidates) { throw 'ไม่พบ Microsoft Edge' }
    New-Item -ItemType Directory -Path $profilePath -Force | Out-Null
    $arguments = @("--user-data-dir=$profilePath", '--kiosk', $kioskUrl, '--edge-kiosk-type=fullscreen', '--kiosk-printing', '--no-first-run', '--disable-pinch')
    Start-Process -FilePath $edgeCandidates[0] -ArgumentList $arguments
} finally {
    try { $mutex.ReleaseMutex() } catch {}
    $mutex.Dispose()
}
