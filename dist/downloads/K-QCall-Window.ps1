$ErrorActionPreference = 'Stop'

$appUrl = 'https://web-lab.tail53909d.ts.net:8443/dist/?registration_app=1'
$appDir = Join-Path $env:LOCALAPPDATA 'K-QCall'
$stateFile = Join-Path $appDir 'window-state.json'
$mutex = [Threading.Mutex]::new($false, 'Local\KQCallWindowController')

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing
Add-Type @'
using System;
using System.Runtime.InteropServices;
using System.Text;
public static class KQCallWindowApi {
  public delegate bool EnumWindowsProc(IntPtr hWnd, IntPtr lParam);
  [DllImport("user32.dll")] public static extern bool EnumWindows(EnumWindowsProc callback, IntPtr extraData);
  [DllImport("user32.dll", CharSet=CharSet.Unicode)] public static extern int GetWindowText(IntPtr hWnd, StringBuilder text, int count);
  [DllImport("user32.dll")] public static extern bool IsWindowVisible(IntPtr hWnd);
  [DllImport("user32.dll")] public static extern bool IsIconic(IntPtr hWnd);
  [DllImport("user32.dll")] public static extern bool ShowWindow(IntPtr hWnd, int command);
  [DllImport("user32.dll")] public static extern bool SetForegroundWindow(IntPtr hWnd);
  [DllImport("user32.dll")] public static extern bool GetWindowRect(IntPtr hWnd, out RECT rect);
  [DllImport("user32.dll")] public static extern bool MoveWindow(IntPtr hWnd, int x, int y, int width, int height, bool repaint);
  [DllImport("user32.dll")] public static extern IntPtr GetForegroundWindow();
  public struct RECT { public int Left; public int Top; public int Right; public int Bottom; }
}
'@

function Find-KQCallWindow {
  $script:foundWindow = [IntPtr]::Zero
  $callback = [KQCallWindowApi+EnumWindowsProc]{
    param([IntPtr]$handle, [IntPtr]$unused)
    if (-not [KQCallWindowApi]::IsWindowVisible($handle)) { return $true }
    $title = New-Object Text.StringBuilder 512
    [void][KQCallWindowApi]::GetWindowText($handle, $title, $title.Capacity)
    if ($title.ToString() -like 'K-QCall*') {
      $script:foundWindow = $handle
      return $false
    }
    return $true
  }
  [void][KQCallWindowApi]::EnumWindows($callback, [IntPtr]::Zero)
  return $script:foundWindow
}

if (-not $mutex.WaitOne(0, $false)) {
  $existing = Find-KQCallWindow
  if ($existing -ne [IntPtr]::Zero) {
    [void][KQCallWindowApi]::ShowWindow($existing, 9)
    [void][KQCallWindowApi]::SetForegroundWindow($existing)
    $shell = New-Object -ComObject WScript.Shell
    if ($shell.AppActivate('K-QCall - ระบบเรียกคิว')) { Start-Sleep -Milliseconds 150; $shell.SendKeys('^r') }
  }
  exit 0
}

function Get-Browser {
  $candidates = @(
    (Join-Path $env:ProgramFiles 'Google\Chrome\Application\chrome.exe'),
    (Join-Path ${env:ProgramFiles(x86)} 'Google\Chrome\Application\chrome.exe'),
    (Join-Path $env:ProgramFiles 'Microsoft\Edge\Application\msedge.exe'),
    (Join-Path ${env:ProgramFiles(x86)} 'Microsoft\Edge\Application\msedge.exe')
  )
  return $candidates | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1
}

function Save-State {
  param([bool]$AutoHide, [KQCallWindowApi+RECT]$Rect)
  @{ autoHide = $AutoHide; x = $Rect.Left; y = $Rect.Top; width = $Rect.Right - $Rect.Left; height = $Rect.Bottom - $Rect.Top } |
    ConvertTo-Json | Set-Content -Encoding UTF8 $stateFile
}

$state = @{ autoHide = $true; width = 460; height = 900; x = -1; y = 0 }
if (Test-Path $stateFile) {
  try {
    $saved = Get-Content -Raw $stateFile | ConvertFrom-Json
    foreach ($name in @('autoHide','width','height','x','y')) {
      if ($null -ne $saved.$name) { $state[$name] = $saved.$name }
    }
  } catch {}
}

$browser = Get-Browser
if (-not $browser) {
  [Windows.Forms.MessageBox]::Show('ไม่พบ Google Chrome หรือ Microsoft Edge', 'K-QCall') | Out-Null
  exit 1
}

$window = Find-KQCallWindow
if ($window -eq [IntPtr]::Zero) {
  Start-Process -FilePath $browser -ArgumentList @("--app=$appUrl") | Out-Null
  for ($i = 0; $i -lt 100 -and $window -eq [IntPtr]::Zero; $i++) {
    Start-Sleep -Milliseconds 100
    $window = Find-KQCallWindow
  }
}
if ($window -eq [IntPtr]::Zero) {
  [Windows.Forms.MessageBox]::Show('ไม่พบหน้าต่าง K-QCall กรุณาตรวจสอบการเชื่อมต่อ', 'K-QCall') | Out-Null
  exit 1
}

$work = [Windows.Forms.Screen]::FromHandle($window).WorkingArea
$width = [Math]::Max(420, [Math]::Min([int]$state.width, $work.Width))
$height = [Math]::Max(620, [Math]::Min([int]$state.height, $work.Height))
$x = if ([int]$state.x -ge $work.Left -and [int]$state.x -lt $work.Right) { [int]$state.x } else { $work.Right - $width }
$y = [Math]::Max($work.Top, [Math]::Min([int]$state.y, $work.Bottom - $height))
[void][KQCallWindowApi]::MoveWindow($window, $x, $y, $width, $height, $true)
[void][KQCallWindowApi]::ShowWindow($window, 9)

$iconPath = Join-Path $appDir 'K-QCall.ico'
$tray = New-Object Windows.Forms.NotifyIcon
$tray.Text = 'K-QCall - ระบบเรียกคิว'
$tray.Icon = if (Test-Path $iconPath) { New-Object Drawing.Icon($iconPath) } else { [Drawing.SystemIcons]::Application }
$tray.Visible = $true
$menu = New-Object Windows.Forms.ContextMenuStrip
$showItem = $menu.Items.Add('แสดง K-QCall')
$hideItem = $menu.Items.Add('ซ่อนชิดขอบ')
$autoItem = $menu.Items.Add('ซ่อนอัตโนมัติ')
$autoItem.CheckOnClick = $true
$autoItem.Checked = [bool]$state.autoHide
[void]$menu.Items.Add('-')
$exitItem = $menu.Items.Add('ปิดตัวควบคุมหน้าต่าง')
$tray.ContextMenuStrip = $menu

$script:hidden = $false
$script:lastOutside = [DateTime]::Now
$script:normalRect = New-Object KQCallWindowApi+RECT
[void][KQCallWindowApi]::GetWindowRect($window, [ref]$script:normalRect)

function Show-KQCall {
  if ([KQCallWindowApi]::IsIconic($window)) { [void][KQCallWindowApi]::ShowWindow($window, 9) }
  if ($script:hidden) {
    $rect = $script:normalRect
    [void][KQCallWindowApi]::MoveWindow($window, $rect.Left, $rect.Top, $rect.Right-$rect.Left, $rect.Bottom-$rect.Top, $true)
    $script:hidden = $false
  }
  [void][KQCallWindowApi]::SetForegroundWindow($window)
}

function Hide-KQCall {
  if ($script:hidden) { return }
  $rect = New-Object KQCallWindowApi+RECT
  if ([KQCallWindowApi]::GetWindowRect($window, [ref]$rect)) {
    $script:normalRect = $rect
    $screen = [Windows.Forms.Screen]::FromHandle($window).WorkingArea
    [void][KQCallWindowApi]::MoveWindow($window, $screen.Right - 10, $rect.Top, $rect.Right-$rect.Left, $rect.Bottom-$rect.Top, $true)
    $script:hidden = $true
  }
}

$showItem.Add_Click({ Show-KQCall })
$hideItem.Add_Click({ Hide-KQCall })
$tray.Add_DoubleClick({ Show-KQCall })
$autoItem.Add_CheckedChanged({
  $rect = if ($script:hidden) { $script:normalRect } else { $current = New-Object KQCallWindowApi+RECT; [void][KQCallWindowApi]::GetWindowRect($window,[ref]$current); $current }
  Save-State -AutoHide $autoItem.Checked -Rect $rect
  if (-not $autoItem.Checked) { Show-KQCall }
})

$context = New-Object Windows.Forms.ApplicationContext
$exitItem.Add_Click({ $tray.Visible = $false; $context.ExitThread() })
$timer = New-Object Windows.Forms.Timer
$timer.Interval = 200
$timer.Add_Tick({
  if (-not $autoItem.Checked) { return }
  $cursor = [Windows.Forms.Cursor]::Position
  if ($script:hidden) {
    $screen = [Windows.Forms.Screen]::FromPoint($cursor).WorkingArea
    if ([KQCallWindowApi]::GetForegroundWindow() -eq $window -or ($cursor.X -ge ($screen.Right - 2) -and $cursor.Y -ge $script:normalRect.Top -and $cursor.Y -le $script:normalRect.Bottom)) { Show-KQCall }
    return
  }
  $rect = New-Object KQCallWindowApi+RECT
  if (-not [KQCallWindowApi]::GetWindowRect($window, [ref]$rect)) { return }
  $inside = $cursor.X -ge $rect.Left -and $cursor.X -le $rect.Right -and $cursor.Y -ge $rect.Top -and $cursor.Y -le $rect.Bottom
  $active = [KQCallWindowApi]::GetForegroundWindow() -eq $window
  if ($inside -or $active) {
    $script:lastOutside = [DateTime]::Now
    $script:normalRect = $rect
  } elseif (([DateTime]::Now - $script:lastOutside).TotalSeconds -ge 2) {
    Hide-KQCall
  }
})
$timer.Start()
try { [Windows.Forms.Application]::Run($context) }
finally {
  $timer.Stop()
  $tray.Visible = $false
  if (-not $script:hidden) {
    $rect = New-Object KQCallWindowApi+RECT
    if ([KQCallWindowApi]::GetWindowRect($window,[ref]$rect)) { Save-State -AutoHide $autoItem.Checked -Rect $rect }
  }
  $mutex.ReleaseMutex()
  $mutex.Dispose()
}
