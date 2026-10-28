param(
    [int]$Port = 17891,
    [string[]]$AllowedOrigins = @('https://web-lab.tail53909d.ts.net:8443','http://localhost','http://127.0.0.1')
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
Add-Type -ReferencedAssemblies System.Drawing -TypeDefinition @'
using System;
using System.Drawing;
using System.Drawing.Printing;
using System.Drawing.Text;
using System.IO;
public static class KqDirectPrinter {
  public static string DefaultPrinter() { using (PrintDocument d=new PrintDocument()) return d.PrinterSettings.IsValid?d.PrinterSettings.PrinterName:""; }
  public static void Print(string no,string department,string service,string slot,string date,string reference,string token,string qrUrl,string systemName,string headerText,string qrCaption,string footerText,bool showService,bool showSlot,bool showDate,bool showReference,bool showQr,bool showQrCaption,bool hideDuplicateService,int paperHeight,int qrSize,float brandSize,float queueSize,float departmentSize,float serviceSize,float detailSize) {
    byte[] bytes=Convert.FromBase64String(qrUrl.Substring(qrUrl.IndexOf(',')+1));
    string fontDir=Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),"K-QCENTER","PrintAgent");
    using(PrivateFontCollection fonts=new PrivateFontCollection()) using(MemoryStream stream=new MemoryStream(bytes)) using(Image qr=Image.FromStream(stream)) using(PrintDocument doc=new PrintDocument()) {
      fonts.AddFontFile(Path.Combine(fontDir,"Sarabun-Regular.ttf")); fonts.AddFontFile(Path.Combine(fontDir,"Sarabun-Bold.ttf")); FontFamily thai=fonts.Families[0];
      if(!doc.PrinterSettings.IsValid) throw new InvalidOperationException("\u0E44\u0E21\u0E48\u0E1E\u0E1A\u0E40\u0E04\u0E23\u0E37\u0E48\u0E2D\u0E07\u0E1E\u0E34\u0E21\u0E1E\u0E4C Default \u0E17\u0E35\u0E48\u0E1E\u0E23\u0E49\u0E2D\u0E21\u0E43\u0E0A\u0E49\u0E07\u0E32\u0E19");
      doc.DocumentName="K-QCENTER "+no; doc.PrintController=new StandardPrintController();
      doc.DefaultPageSettings.Margins=new Margins(0,0,2,2); doc.DefaultPageSettings.PaperSize=new PaperSize("K-QCENTER Custom 80mm",315,Math.Max(260,Math.Min(700,paperHeight)));
      doc.PrintPage += delegate(object sender,PrintPageEventArgs e) {
        float w=e.MarginBounds.Width,l=e.MarginBounds.Left,y=e.MarginBounds.Top; StringFormat c=new StringFormat{Alignment=StringAlignment.Center};
        bool repeatedService=String.Equals((service??"").Trim(),(department??"").Trim(),StringComparison.OrdinalIgnoreCase)||((department??"").Contains("\u0E43\u0E1A\u0E23\u0E31\u0E1A\u0E23\u0E2D\u0E07\u0E41\u0E1E\u0E17\u0E22\u0E4C")&&(service??"").Contains("\u0E43\u0E1A\u0E23\u0E31\u0E1A\u0E23\u0E2D\u0E07\u0E41\u0E1E\u0E17\u0E22\u0E4C"));
        using(Font brand=new Font(thai,brandSize,FontStyle.Bold)) using(Font label=new Font(thai,Math.Max(7,detailSize-1))) using(Font num=new Font("Arial",queueSize,FontStyle.Bold)) using(Font title=new Font(thai,departmentSize,FontStyle.Bold)) using(Font normal=new Font(thai,detailSize)) using(Font serviceFont=new Font(thai,serviceSize,FontStyle.Bold)) using(Pen dash=new Pen(Color.Gray,1)) {
          dash.DashStyle=System.Drawing.Drawing2D.DashStyle.Dash;
          e.Graphics.DrawString(systemName,brand,Brushes.Black,new RectangleF(l,y,w,30),c);y+=27;if(!String.IsNullOrWhiteSpace(headerText)){e.Graphics.DrawString(headerText,label,Brushes.Black,new RectangleF(l,y,w,22),c);y+=20;}
          e.Graphics.DrawString(no,num,Brushes.Black,new RectangleF(l,y,w,44),c);y+=44;e.Graphics.DrawLine(dash,l,y,l+w,y);y+=7;
          e.Graphics.DrawString(department,title,Brushes.Black,new RectangleF(l,y,w,32),c);y+=30;if(showService&&!String.IsNullOrWhiteSpace(service)&&(!hideDuplicateService||!repeatedService)){e.Graphics.DrawString(service,serviceFont,Brushes.Black,new RectangleF(l,y,w,26),c);y+=24;}
          if(showSlot){e.Graphics.DrawString(slot,normal,Brushes.Black,new RectangleF(l,y,w,22),c);y+=21;}if(showDate){e.Graphics.DrawString(date,normal,Brushes.Black,new RectangleF(l,y,w,22),c);y+=21;}if(showReference){e.Graphics.DrawString(reference,label,Brushes.Black,new RectangleF(l,y,w,20),c);y+=21;}
          if(showQr){float size=Math.Min(qrSize,w-20);e.Graphics.DrawImage(qr,l+(w-size)/2,y,size,size);y+=size+5;if(showQrCaption&&!String.IsNullOrWhiteSpace(qrCaption)){e.Graphics.DrawString(qrCaption,normal,Brushes.Black,new RectangleF(l,y,w,22),c);y+=21;}}e.Graphics.DrawLine(dash,l,y,l+w,y);y+=5;if(!String.IsNullOrWhiteSpace(footerText))e.Graphics.DrawString(footerText,label,Brushes.Black,new RectangleF(l,y,w,28),c);
        } e.HasMorePages=false;
      }; doc.Print();
    }
  }
}
'@
function Reply($stream,[int]$status,[string]$body,[string]$origin){$bytes=[Text.Encoding]::UTF8.GetBytes($body);$text=if($status-eq 200){'OK'}elseif($status-eq 204){'No Content'}elseif($status-eq 403){'Forbidden'}else{'Bad Request'};$headers="HTTP/1.1 $status $text`r`nContent-Type: application/json; charset=utf-8`r`nContent-Length: $($bytes.Length)`r`nAccess-Control-Allow-Origin: $origin`r`nAccess-Control-Allow-Methods: GET, POST, OPTIONS`r`nAccess-Control-Allow-Headers: Content-Type`r`nAccess-Control-Allow-Private-Network: true`r`nVary: Origin, Access-Control-Request-Private-Network`r`nCache-Control: no-store`r`nConnection: close`r`n`r`n";$h=[Text.Encoding]::ASCII.GetBytes($headers);$stream.Write($h,0,$h.Length);if($bytes.Length){$stream.Write($bytes,0,$bytes.Length)};$stream.Flush()}
function Get-DefaultPrinterHealth {
    $fallback=[KqDirectPrinter]::DefaultPrinter()
    if (-not $fallback) { return @{ready=$false;name='';error='ไม่พบเครื่องพิมพ์ Default'} }
    if ($fallback -match 'Microsoft Print to PDF|Microsoft XPS|OneNote|Fax') { return @{ready=$false;name=$fallback;error='Default Printer ไม่ใช่เครื่องพิมพ์บัตรคิว'} }
    try {
        $printer=Get-CimInstance Win32_Printer -ErrorAction Stop | Where-Object Default | Select-Object -First 1
        if (-not $printer) { return @{ready=$false;name='';error='ไม่พบเครื่องพิมพ์ Default'} }
        $issues=New-Object Collections.Generic.List[string]
        if ([string]$printer.Name -match 'Microsoft Print to PDF|Microsoft XPS|OneNote|Fax') { $issues.Add('Default Printer ไม่ใช่เครื่องพิมพ์บัตรคิว') }
        if ([bool]$printer.WorkOffline) { $issues.Add('เครื่องพิมพ์ Offline') }
        if ([int]$printer.PrinterStatus -in @(6,7)) { $issues.Add('เครื่องพิมพ์หยุดหรือ Offline') }
        if ($null-ne $printer.DetectedErrorState -and [int]$printer.DetectedErrorState -notin @(0,1,2)) {
            $labels=@{3='กระดาษใกล้หมด';4='กระดาษหมด';5='หมึกใกล้หมด';6='หมึกหมด';7='ฝาเครื่องพิมพ์เปิด';8='กระดาษติด';9='เครื่องพิมพ์ Offline';10='ต้องเข้ารับบริการ'}
            $code=[int]$printer.DetectedErrorState;$issues.Add($(if($labels[$code]){$labels[$code]}else{"เครื่องพิมพ์ขัดข้อง ($code)"}))
        }
        $escaped=[WildcardPattern]::Escape([string]$printer.Name)
        $badJob=Get-CimInstance Win32_PrintJob -ErrorAction SilentlyContinue | Where-Object { $_.Name -like "$escaped,*" -and ([string]$_.Status -match 'Error|Offline|PaperOut|Blocked|UserIntervention') } | Select-Object -First 1
        if ($badJob) { $issues.Add('มีงานพิมพ์ขัดข้องค้างอยู่') }
        return @{ready=($issues.Count-eq 0);name=[string]$printer.Name;error=($issues -join ', ')}
    } catch {
        return @{ready=[bool]$fallback;name=$fallback;error=$(if($fallback){''}else{'ไม่พบเครื่องพิมพ์ Default'})}
    }
}
$listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,$Port)
$listener.Start()
try {
    while ($true) {
        $client=$listener.AcceptTcpClient()
        try {
            $stream=$client.GetStream()
            $reader=[IO.StreamReader]::new($stream,[Text.Encoding]::UTF8,$false,4096,$true)
            $request=$reader.ReadLine()
            $headers=@{}
            while (($line=$reader.ReadLine()) -ne '') { $pair=$line.Split(':',2); if($pair.Count -eq 2){$headers[$pair[0].Trim().ToLowerInvariant()]=$pair[1].Trim()} }
            $origin=[string]$headers['origin']
            if ($origin -notin $AllowedOrigins) { Reply $stream 403 '{"ok":false,"error":"Origin ไม่ได้รับอนุญาต"}' 'null'; continue }
            [int]$length=0
            if ($headers['content-length']) { [int]::TryParse($headers['content-length'],[ref]$length)|Out-Null }
            if ($length -lt 0 -or $length -gt 262144) { Reply $stream 400 '{"ok":false,"error":"ข้อมูลมีขนาดไม่ถูกต้อง"}' $origin; continue }
            $body=''
            if ($length) { $buffer=New-Object char[] $length; $read=0; while($read -lt $length){$n=$reader.Read($buffer,$read,$length-$read);if($n -le 0){break};$read+=$n};$body=-join $buffer }
            if ($request -match '^OPTIONS /(health|print) ') { Reply $stream 204 '' $origin; continue }
            if ($request -match '^GET /health ') { $printer=Get-DefaultPrinterHealth; Reply $stream 200 ((@{ok=$true;service='K-QCENTER Print Agent';agent_version=9;printer_ready=[bool]$printer.ready;default_printer=$printer.name;printer_error=$printer.error;layout='customizable';font='Sarabun'}|ConvertTo-Json -Compress)) $origin; continue }
            if ($request -match '^POST /print ') {
                try {
                    $wrapper=$body|ConvertFrom-Json
                    if (-not $wrapper.payload_base64) { throw 'ไม่พบ payload สำหรับพิมพ์' }
                    $json=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String([string]$wrapper.payload_base64))
                    $ticket=$json|ConvertFrom-Json
                    if (-not $ticket.queue_no -or $ticket.qr_data_url -notmatch '^data:image/(png|gif);base64,') { throw 'ข้อมูลบัตรคิวไม่ถูกต้อง' }
                    $printerHealth=Get-DefaultPrinterHealth
                    if (-not $printerHealth.ready) { throw 'ไม่พบเครื่องพิมพ์หรือเครื่องพิมพ์ขัดข้อง กรุณาติดต่อเจ้าหน้าที่' }
                    $printer=[string]$printerHealth.name
                    $t=$ticket.template;if(-not $t){$t=[pscustomobject]@{system_name='K-QCENTER';header_text='บัตรคิวรับบริการห้องบัตร';qr_caption='สแกน QR เพื่อติดตามสถานะคิว';footer_text='โปรดเก็บบัตรนี้ไว้จนกว่าจะรับบริการเสร็จ';show_service=$true;show_slot=$true;show_date=$true;show_reference=$true;show_qr=$true;show_qr_caption=$true;hide_duplicate_service=$true;paper_height=370;qr_size=112;brand_font_size=13;queue_font_size=30;department_font_size=14;service_font_size=11;detail_font_size=10}}
                    [KqDirectPrinter]::Print([string]$ticket.queue_no,[string]$ticket.department,[string]$ticket.service,[string]$ticket.slot,[string]$ticket.date,[string]$ticket.reference,[string]$ticket.token,[string]$ticket.qr_data_url,[string]$t.system_name,[string]$t.header_text,[string]$t.qr_caption,[string]$t.footer_text,[bool]$t.show_service,[bool]$t.show_slot,[bool]$t.show_date,[bool]$t.show_reference,[bool]$t.show_qr,[bool]$t.show_qr_caption,[bool]$t.hide_duplicate_service,[int]$t.paper_height,[int]$t.qr_size,[float]$t.brand_font_size,[float]$t.queue_font_size,[float]$t.department_font_size,[float]$t.service_font_size,[float]$t.detail_font_size)
                    Reply $stream 200 ((@{ok=$true;printed=$true;printer=$printer}|ConvertTo-Json -Compress)) $origin
                } catch { Reply $stream 400 ((@{ok=$false;error=$_.Exception.Message}|ConvertTo-Json -Compress)) $origin }
                continue
            }
            Reply $stream 400 '{"ok":false,"error":"Endpoint ไม่ถูกต้อง"}' $origin
        } finally { $client.Dispose() }
    }
} finally { $listener.Stop() }
