param(
    [int]$Port = 17890,
    [string[]]$AllowedOrigins = @(
        'https://web-lab.tail53909d.ts.net:8443',
        'http://localhost',
        'http://127.0.0.1'
    ),
    [int]$StartupDelayMs = 0
)

$ErrorActionPreference = 'Stop'
if ($StartupDelayMs -gt 0) { Start-Sleep -Milliseconds $StartupDelayMs }

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

Add-Type -AssemblyName System.Drawing
Add-Type -ReferencedAssemblies System.Drawing -TypeDefinition @'
using System;
using System.Drawing;
using System.Drawing.Printing;
using System.IO;

public static class KqTicketPrinter {
    public static void Print(string queueNo, string department, string service, string slot, string date, string reference, string token, string qrDataUrl) {
        byte[] qrBytes = Convert.FromBase64String(qrDataUrl.Substring(qrDataUrl.IndexOf(',') + 1));
        using (MemoryStream qrStream = new MemoryStream(qrBytes))
        using (Image qr = Image.FromStream(qrStream))
        using (PrintDocument document = new PrintDocument()) {
            document.DocumentName = "K-QCENTER " + queueNo;
            document.PrintController = new StandardPrintController();
            document.DefaultPageSettings.Margins = new Margins(8, 8, 6, 6);
            document.DefaultPageSettings.PaperSize = new PaperSize("K-QCENTER 80mm", 315, 650);
            document.PrintPage += delegate(object sender, PrintPageEventArgs e) {
                float width = e.MarginBounds.Width;
                float left = e.MarginBounds.Left;
                float y = e.MarginBounds.Top;
                StringFormat center = new StringFormat { Alignment = StringAlignment.Center };
                using (Font brand = new Font("Sarabun", 11, FontStyle.Bold))
                using (Font label = new Font("Sarabun", 8, FontStyle.Regular))
                using (Font number = new Font("Arial", 27, FontStyle.Bold))
                using (Font title = new Font("Sarabun", 13, FontStyle.Bold))
                using (Font normal = new Font("Sarabun", 9, FontStyle.Regular))
                using (Font tokenFont = new Font("Arial", 6, FontStyle.Regular))
                using (Pen dashed = new Pen(Color.Gray, 1)) {
                    dashed.DashStyle = System.Drawing.Drawing2D.DashStyle.Dash;
                    e.Graphics.DrawString("K-QCENTER", brand, Brushes.Black, new RectangleF(left, y, width, 24), center); y += 22;
                    e.Graphics.DrawString("บัตรคิวรับบริการ", label, Brushes.Black, new RectangleF(left, y, width, 18), center); y += 28;
                    e.Graphics.DrawString("หมายเลขคิว", label, Brushes.Black, new RectangleF(left, y, width, 18), center); y += 16;
                    e.Graphics.DrawString(queueNo, number, Brushes.Black, new RectangleF(left, y, width, 45), center); y += 51;
                    e.Graphics.DrawLine(dashed, left, y, left + width, y); y += 12;
                    e.Graphics.DrawString(department, title, Brushes.Black, new RectangleF(left, y, width, 28), center); y += 25;
                    if (!String.IsNullOrWhiteSpace(service)) { e.Graphics.DrawString(service, normal, Brushes.Black, new RectangleF(left, y, width, 20), center); y += 19; }
                    e.Graphics.DrawString(slot, normal, Brushes.Black, new RectangleF(left, y, width, 20), center); y += 19;
                    e.Graphics.DrawString(date, normal, Brushes.Black, new RectangleF(left, y, width, 20), center); y += 19;
                    e.Graphics.DrawString(reference, label, Brushes.Black, new RectangleF(left, y, width, 18), center); y += 23;
                    float qrSize = Math.Min(180, width - 30);
                    e.Graphics.DrawImage(qr, left + (width - qrSize) / 2, y, qrSize, qrSize); y += qrSize + 8;
                    e.Graphics.DrawString("สแกน QR เพื่อติดตามสถานะคิว", normal, Brushes.Black, new RectangleF(left, y, width, 22), center); y += 20;
                    e.Graphics.DrawString(token, tokenFont, Brushes.Black, new RectangleF(left, y, width, 18), center); y += 22;
                    e.Graphics.DrawLine(dashed, left, y, left + width, y); y += 8;
                    e.Graphics.DrawString("โปรดเก็บบัตรนี้ไว้จนกว่าจะรับบริการเสร็จ", label, Brushes.Black, new RectangleF(left, y, width, 22), center);
                }
                e.HasMorePages = false;
            };
            if (!document.PrinterSettings.IsValid) throw new InvalidOperationException("ไม่พบเครื่องพิมพ์ Default ที่พร้อมใช้งาน");
            document.Print();
        }
    }
}
'@

function Assert-SCardResult([int]$Code, [string]$Operation) {
    if ($Code -ne 0) { throw "$Operation ล้มเหลว (0x$($Code.ToString('X8')))" }
}

function Get-SmartCardReaderHealth {
    [IntPtr]$context = [IntPtr]::Zero
    try {
        $result = [WinSCard]::SCardEstablishContext(0,[IntPtr]::Zero,[IntPtr]::Zero,[ref]$context)
        if ($result -ne 0) { return @{ connected=$false; error_code=('0x' + $result.ToString('X8')) } }
        [uint32]$length = 0
        $result = [WinSCard]::SCardListReaders($context,$null,$null,[ref]$length)
        if ($result -ne 0 -or $length -le 1) { return @{ connected=$false; error_code=('0x' + $result.ToString('X8')) } }
        $buffer = New-Object Text.StringBuilder ([int]$length)
        $result = [WinSCard]::SCardListReaders($context,$null,$buffer,[ref]$length)
        if ($result -ne 0) { return @{ connected=$false; error_code=('0x' + $result.ToString('X8')) } }
        $reader = ($buffer.ToString() -split "`0" | Where-Object { $_ } | Select-Object -First 1)
        return @{ connected=[bool]$reader; reader=$reader; error_code=$null }
    } finally {
        if ($context -ne [IntPtr]::Zero) { [void][WinSCard]::SCardReleaseContext($context) }
    }
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
    $headers = "HTTP/1.1 $Status $statusText`r`nContent-Type: application/json; charset=utf-8`r`nContent-Length: $($payload.Length)`r`nAccess-Control-Allow-Origin: $Origin`r`nAccess-Control-Allow-Methods: GET, POST, OPTIONS`r`nAccess-Control-Allow-Headers: Content-Type`r`nAccess-Control-Allow-Private-Network: true`r`nVary: Origin, Access-Control-Request-Private-Network`r`nCache-Control: no-store`r`nConnection: close`r`n`r`n"
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
            [int]$contentLength = 0
            if ($headers.ContainsKey('content-length')) {
                if (-not [int]::TryParse([string]$headers['content-length'], [ref]$contentLength) -or $contentLength -lt 0 -or $contentLength -gt 262144) {
                    Write-HttpResponse $stream 400 '{"ok":false,"error":"Request body ไม่ถูกต้องหรือมีขนาดใหญ่เกินไป"}' 'null'
                    continue
                }
            }
            $bodyText = ''
            if ($contentLength -gt 0) {
                $bodyBuffer = New-Object char[] $contentLength
                $bodyRead = 0
                while ($bodyRead -lt $contentLength) {
                    $readCount = $reader.Read($bodyBuffer, $bodyRead, $contentLength - $bodyRead)
                    if ($readCount -le 0) { break }
                    $bodyRead += $readCount
                }
                if ($bodyRead -ne $contentLength) {
                    Write-HttpResponse $stream 400 '{"ok":false,"error":"Request body ไม่สมบูรณ์"}' 'null'
                    continue
                }
                $bodyText = -join $bodyBuffer
            }
            $origin = [string]$headers['origin']
            if ($origin -notin $AllowedOrigins) { Write-HttpResponse $stream 403 '{"ok":false,"error":"Origin ไม่ได้รับอนุญาต"}' 'null'; continue }
            if ($requestLine -match '^OPTIONS /(read|health|restart|print) ') { Write-HttpResponse $stream 204 '' $origin; continue }
            if ($requestLine -match '^GET /health ') {
                $health = Get-SmartCardReaderHealth
                $healthBody = @{ ok=$true; service='K-QCENTER Smart Card Bridge'; bridge_version=3; silent_print=$true; reader_connected=[bool]$health.connected; reader=$health.reader; error_code=$health.error_code } | ConvertTo-Json -Compress
                Write-HttpResponse $stream 200 $healthBody $origin
                continue
            }
            if ($requestLine -match '^POST /restart ') {
                [IntPtr]$testContext = [IntPtr]::Zero
                $testResult = [WinSCard]::SCardEstablishContext(0,[IntPtr]::Zero,[IntPtr]::Zero,[ref]$testContext)
                if ($testContext -ne [IntPtr]::Zero) { [void][WinSCard]::SCardReleaseContext($testContext) }
                if ($testResult -ne 0) { Write-HttpResponse $stream 400 '{"ok":false,"error":"Smart Card Resource Manager ไม่ตอบสนอง"}' $origin; continue }
                Write-HttpResponse $stream 200 '{"ok":true,"restarted":true}' $origin
                continue
            }
            if ($requestLine -match '^POST /print ') {
                try {
                    $ticket = $bodyText | ConvertFrom-Json
                    if (-not $ticket.queue_no -or -not $ticket.qr_data_url -or $ticket.qr_data_url -notmatch '^data:image/(png|gif);base64,') { throw 'ข้อมูลบัตรคิวไม่ถูกต้อง' }
                    [KqTicketPrinter]::Print([string]$ticket.queue_no,[string]$ticket.department,[string]$ticket.service,[string]$ticket.slot,[string]$ticket.date,[string]$ticket.reference,[string]$ticket.token,[string]$ticket.qr_data_url)
                    Write-HttpResponse $stream 200 '{"ok":true,"printed":true}' $origin
                } catch { Write-HttpResponse $stream 400 ((@{ok=$false;error=$_.Exception.Message}|ConvertTo-Json -Compress)) $origin }
                continue
            }
            if ($requestLine -notmatch '^POST /read ') { Write-HttpResponse $stream 400 '{"ok":false,"error":"Endpoint ไม่ถูกต้อง"}' $origin; continue }
            try { $data=Read-ThaiIdentityCard; Write-HttpResponse $stream 200 ((@{ok=$true;data=$data}|ConvertTo-Json -Compress)) $origin }
            catch { Write-HttpResponse $stream 400 ((@{ok=$false;error=$_.Exception.Message}|ConvertTo-Json -Compress)) $origin }
        } finally { $client.Dispose() }
    }
} finally { $listener.Stop() }
