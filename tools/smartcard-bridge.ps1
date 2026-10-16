param(
    [int]$Port = 17890,
    [string[]]$AllowedOrigins = @('https://web-lab.tail53909d.ts.net:8443')
)

$ErrorActionPreference = 'Stop'

Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
using System.Text;

public static class WinSCard {
    [StructLayout(LayoutKind.Sequential)]
    public struct IORequest { public UInt32 Protocol; public UInt32 Length; }

    [DllImport("winscard.dll")]
    public static extern int SCardEstablishContext(UInt32 scope, IntPtr r1, IntPtr r2, out IntPtr context);
    [DllImport("winscard.dll", CharSet=CharSet.Unicode)]
    public static extern int SCardListReaders(IntPtr context, string groups, StringBuilder readers, ref UInt32 length);
    [DllImport("winscard.dll", CharSet=CharSet.Unicode)]
    public static extern int SCardConnect(IntPtr context, string reader, UInt32 share, UInt32 protocols, out IntPtr card, out UInt32 activeProtocol);
    [DllImport("winscard.dll")]
    public static extern int SCardTransmit(IntPtr card, ref IORequest sendPci, byte[] send, UInt32 sendLength, IntPtr recvPci, byte[] recv, ref UInt32 recvLength);
    [DllImport("winscard.dll")]
    public static extern int SCardDisconnect(IntPtr card, UInt32 disposition);
    [DllImport("winscard.dll")]
    public static extern int SCardReleaseContext(IntPtr context);
}
'@

function Assert-SCardResult([int]$Code, [string]$Operation) {
    if ($Code -ne 0) { throw "$Operation ล้มเหลว (0x$($Code.ToString('X8')))" }
}

function Send-Apdu([IntPtr]$Card, [uint32]$Protocol, [byte[]]$Command) {
    $pci = New-Object WinSCard+IORequest
    $pci.Protocol = $Protocol
    $pci.Length = 8
    [byte[]]$receive = New-Object byte[] 512
    [uint32]$receiveLength = $receive.Length
    $result = [WinSCard]::SCardTransmit($Card, [ref]$pci, $Command, $Command.Length, [IntPtr]::Zero, $receive, [ref]$receiveLength)
    Assert-SCardResult $result 'ส่งคำสั่งไปยังบัตร'
    return [byte[]]$receive[0..($receiveLength - 1)]
}

function Read-ThaiIdField([IntPtr]$Card, [uint32]$Protocol, [int]$Offset, [int]$Length) {
    [byte[]]$request = @(0x80,0xB0,(($Offset -shr 8) -band 0xFF),($Offset -band 0xFF),0x02,0x00,($Length -band 0xFF))
    $response = Send-Apdu $Card $Protocol $request
    if ($response.Length -ge 2 -and $response[-2] -eq 0x61) {
        [byte[]]$getResponse = @(0x00,0xC0,0x00,0x00,($Length -band 0xFF))
        $response = Send-Apdu $Card $Protocol $getResponse
    }
    if ($response.Length -lt 2 -or $response[-2] -ne 0x90 -or $response[-1] -ne 0x00) { throw 'บัตรไม่ตอบกลับข้อมูลตามรูปแบบบัตรประชาชนไทย' }
    return [byte[]]$response[0..($response.Length - 3)]
}

function Read-ThaiIdentityCard {
    [IntPtr]$context = [IntPtr]::Zero
    [IntPtr]$card = [IntPtr]::Zero
    try {
        Assert-SCardResult ([WinSCard]::SCardEstablishContext(0,[IntPtr]::Zero,[IntPtr]::Zero,[ref]$context)) 'เชื่อมต่อ Smart Card Resource Manager'
        [uint32]$length = 0
        $first = [WinSCard]::SCardListReaders($context,$null,$null,[ref]$length)
        Assert-SCardResult $first 'ค้นหาเครื่องอ่านบัตร'
        $buffer = New-Object Text.StringBuilder ([int]$length)
        Assert-SCardResult ([WinSCard]::SCardListReaders($context,$null,$buffer,[ref]$length)) 'อ่านรายชื่อเครื่องอ่านบัตร'
        $reader = ($buffer.ToString() -split "`0" | Where-Object { $_ } | Select-Object -First 1)
        if (-not $reader) { throw 'ไม่พบเครื่องอ่านบัตร' }
        [uint32]$protocol = 0
        Assert-SCardResult ([WinSCard]::SCardConnect($context,$reader,2,3,[ref]$card,[ref]$protocol)) 'อ่านบัตร กรุณาตรวจว่าเสียบบัตรแล้ว'
        [byte[]]$selectThaiId = @(0x00,0xA4,0x04,0x00,0x08,0xA0,0x00,0x00,0x00,0x54,0x48,0x00,0x01)
        $selectResult = Send-Apdu $card $protocol $selectThaiId
        if ($selectResult.Length -lt 2 -or $selectResult[-2] -notin @(0x90,0x61)) { throw 'บัตรนี้ไม่ใช่บัตรประชาชนไทยที่รองรับ' }
        $citizenId = [Text.Encoding]::ASCII.GetString((Read-ThaiIdField $card $protocol 0x0004 13)).Trim()
        $thaiRaw = [Text.Encoding]::GetEncoding(874).GetString((Read-ThaiIdField $card $protocol 0x0011 100)).Trim([char]0x00, [char]0x20, [char]0xFF)
        $parts = @($thaiRaw -split '#' | ForEach-Object { $_.Trim() } | Where-Object { $_ })
        $displayName = ($parts -join ' ').Trim()
        if ($citizenId -notmatch '^\d{13}$') { throw 'ไม่สามารถอ่านเลขบัตรประชาชนได้' }
        return @{ citizen_id=$citizenId; display_name=$displayName; reader=$reader }
    } finally {
        if ($card -ne [IntPtr]::Zero) { [void][WinSCard]::SCardDisconnect($card,0) }
        if ($context -ne [IntPtr]::Zero) { [void][WinSCard]::SCardReleaseContext($context) }
    }
}

function Write-HttpResponse($Stream, [int]$Status, [string]$Body, [string]$Origin) {
    $statusText = if ($Status -eq 200) {'OK'} elseif ($Status -eq 204) {'No Content'} elseif ($Status -eq 403) {'Forbidden'} else {'Bad Request'}
    $payload = [Text.Encoding]::UTF8.GetBytes($Body)
    $headers = "HTTP/1.1 $Status $statusText`r`nContent-Type: application/json; charset=utf-8`r`nContent-Length: $($payload.Length)`r`nAccess-Control-Allow-Origin: $Origin`r`nAccess-Control-Allow-Methods: POST, OPTIONS`r`nAccess-Control-Allow-Headers: Content-Type`r`nCache-Control: no-store`r`nConnection: close`r`n`r`n"
    $headerBytes = [Text.Encoding]::ASCII.GetBytes($headers)
    $Stream.Write($headerBytes,0,$headerBytes.Length)
    if ($payload.Length) { $Stream.Write($payload,0,$payload.Length) }
    $Stream.Flush()
}

$listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,$Port)
$listener.Start()
try {
    while ($true) {
        $client = $listener.AcceptTcpClient()
        try {
            $stream = $client.GetStream(); $reader = [IO.StreamReader]::new($stream,[Text.Encoding]::ASCII,$false,4096,$true)
            $requestLine = $reader.ReadLine(); $headers = @{}
            while (($line = $reader.ReadLine()) -ne '') { $pair=$line.Split(':',2); if($pair.Count -eq 2){$headers[$pair[0].Trim().ToLowerInvariant()]=$pair[1].Trim()} }
            $origin = [string]$headers['origin']
            if ($origin -notin $AllowedOrigins) { Write-HttpResponse $stream 403 '{"ok":false,"error":"Origin ไม่ได้รับอนุญาต"}' 'null'; continue }
            if ($requestLine -match '^OPTIONS /read ') { Write-HttpResponse $stream 204 '' $origin; continue }
            if ($requestLine -notmatch '^POST /read ') { Write-HttpResponse $stream 400 '{"ok":false,"error":"Endpoint ไม่ถูกต้อง"}' $origin; continue }
            try { $data=Read-ThaiIdentityCard; Write-HttpResponse $stream 200 ((@{ok=$true;data=$data}|ConvertTo-Json -Compress)) $origin }
            catch { Write-HttpResponse $stream 400 ((@{ok=$false;error=$_.Exception.Message}|ConvertTo-Json -Compress)) $origin }
        } finally { $client.Dispose() }
    }
} finally { $listener.Stop() }
