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
  public static void Print(string no,string department,string service,string slot,string date,string reference,string token,string qrUrl) {
    byte[] bytes=Convert.FromBase64String(qrUrl.Substring(qrUrl.IndexOf(',')+1));
    string fontDir=Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),"K-QCENTER","PrintAgent");
    using(PrivateFontCollection fonts=new PrivateFontCollection()) using(MemoryStream stream=new MemoryStream(bytes)) using(Image qr=Image.FromStream(stream)) using(PrintDocument doc=new PrintDocument()) {
      fonts.AddFontFile(Path.Combine(fontDir,"Sarabun-Regular.ttf")); fonts.AddFontFile(Path.Combine(fontDir,"Sarabun-Bold.ttf")); FontFamily thai=fonts.Families[0];
      if(!doc.PrinterSettings.IsValid) throw new InvalidOperationException("\u0E44\u0E21\u0E48\u0E1E\u0E1A\u0E40\u0E04\u0E23\u0E37\u0E48\u0E2D\u0E07\u0E1E\u0E34\u0E21\u0E1E\u0E4C Default \u0E17\u0E35\u0E48\u0E1E\u0E23\u0E49\u0E2D\u0E21\u0E43\u0E0A\u0E49\u0E07\u0E32\u0E19");
      doc.DocumentName="K-QCENTER "+no; doc.PrintController=new StandardPrintController();
      doc.DefaultPageSettings.Margins=new Margins(0,0,2,2); doc.DefaultPageSettings.PaperSize=new PaperSize("K-QCENTER Compact 80mm",315,370);
      doc.PrintPage += delegate(object sender,PrintPageEventArgs e) {
        float w=e.MarginBounds.Width,l=e.MarginBounds.Left,y=e.MarginBounds.Top; StringFormat c=new StringFormat{Alignment=StringAlignment.Center};
        bool repeatedService=String.Equals((service??"").Trim(),(department??"").Trim(),StringComparison.OrdinalIgnoreCase)||((department??"").Contains("\u0E43\u0E1A\u0E23\u0E31\u0E1A\u0E23\u0E2D\u0E07\u0E41\u0E1E\u0E17\u0E22\u0E4C")&&(service??"").Contains("\u0E43\u0E1A\u0E23\u0E31\u0E1A\u0E23\u0E2D\u0E07\u0E41\u0E1E\u0E17\u0E22\u0E4C"));
        using(Font brand=new Font(thai,13,FontStyle.Bold)) using(Font label=new Font(thai,8.5f)) using(Font num=new Font("Arial",30,FontStyle.Bold)) using(Font title=new Font(thai,14,FontStyle.Bold)) using(Font normal=new Font(thai,10)) using(Font serviceFont=new Font(thai,11,FontStyle.Bold)) using(Pen dash=new Pen(Color.Gray,1)) {
          dash.DashStyle=System.Drawing.Drawing2D.DashStyle.Dash;
          e.Graphics.DrawString("K-QCENTER",brand,Brushes.Black,new RectangleF(l,y,w,25),c);y+=23;e.Graphics.DrawString("\u0E1A\u0E31\u0E15\u0E23\u0E04\u0E34\u0E27\u0E23\u0E31\u0E1A\u0E1A\u0E23\u0E34\u0E01\u0E32\u0E23\u0E2B\u0E49\u0E2D\u0E07\u0E1A\u0E31\u0E15\u0E23",label,Brushes.Black,new RectangleF(l,y,w,17),c);y+=17;
          e.Graphics.DrawString(no,num,Brushes.Black,new RectangleF(l,y,w,44),c);y+=44;e.Graphics.DrawLine(dash,l,y,l+w,y);y+=7;
          e.Graphics.DrawString(department,title,Brushes.Black,new RectangleF(l,y,w,29),c);y+=27;if(!String.IsNullOrWhiteSpace(service)&&!repeatedService){e.Graphics.DrawString(service,serviceFont,Brushes.Black,new RectangleF(l,y,w,23),c);y+=21;}
          e.Graphics.DrawString(slot,normal,Brushes.Black,new RectangleF(l,y,w,20),c);y+=19;e.Graphics.DrawString(date,normal,Brushes.Black,new RectangleF(l,y,w,20),c);y+=19;e.Graphics.DrawString(reference,label,Brushes.Black,new RectangleF(l,y,w,17),c);y+=18;
          float size=Math.Min(112,w-30);e.Graphics.DrawImage(qr,l+(w-size)/2,y,size,size);y+=size+5;e.Graphics.DrawString("\u0E2A\u0E41\u0E01\u0E19 QR \u0E40\u0E1E\u0E37\u0E48\u0E2D\u0E15\u0E34\u0E14\u0E15\u0E32\u0E21\u0E2A\u0E16\u0E32\u0E19\u0E30\u0E04\u0E34\u0E27",normal,Brushes.Black,new RectangleF(l,y,w,20),c);y+=19;e.Graphics.DrawLine(dash,l,y,l+w,y);y+=5;e.Graphics.DrawString("\u0E42\u0E1B\u0E23\u0E14\u0E40\u0E01\u0E47\u0E1A\u0E1A\u0E31\u0E15\u0E23\u0E19\u0E35\u0E49\u0E44\u0E27\u0E49\u0E08\u0E19\u0E01\u0E27\u0E48\u0E32\u0E08\u0E30\u0E23\u0E31\u0E1A\u0E1A\u0E23\u0E34\u0E01\u0E32\u0E23\u0E40\u0E2A\u0E23\u0E47\u0E08",label,Brushes.Black,new RectangleF(l,y,w,18),c);
        } e.HasMorePages=false;
      }; doc.Print();
    }
  }
}
'@
function Reply($stream,[int]$status,[string]$body,[string]$origin){$bytes=[Text.Encoding]::UTF8.GetBytes($body);$text=if($status-eq 200){'OK'}elseif($status-eq 204){'No Content'}elseif($status-eq 403){'Forbidden'}else{'Bad Request'};$headers="HTTP/1.1 $status $text`r`nContent-Type: application/json; charset=utf-8`r`nContent-Length: $($bytes.Length)`r`nAccess-Control-Allow-Origin: $origin`r`nAccess-Control-Allow-Methods: GET, POST, OPTIONS`r`nAccess-Control-Allow-Headers: Content-Type`r`nAccess-Control-Allow-Private-Network: true`r`nVary: Origin, Access-Control-Request-Private-Network`r`nCache-Control: no-store`r`nConnection: close`r`n`r`n";$h=[Text.Encoding]::ASCII.GetBytes($headers);$stream.Write($h,0,$h.Length);if($bytes.Length){$stream.Write($bytes,0,$bytes.Length)};$stream.Flush()}
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
            if ($request -match '^GET /health ') { $printer=[KqDirectPrinter]::DefaultPrinter(); Reply $stream 200 ((@{ok=$true;service='K-QCENTER Print Agent';agent_version=5;printer_ready=[bool]$printer;default_printer=$printer;layout='compact-wide';font='Sarabun'}|ConvertTo-Json -Compress)) $origin; continue }
            if ($request -match '^POST /print ') {
                try {
                    $wrapper=$body|ConvertFrom-Json
                    if (-not $wrapper.payload_base64) { throw 'ไม่พบ payload สำหรับพิมพ์' }
                    $json=[Text.Encoding]::UTF8.GetString([Convert]::FromBase64String([string]$wrapper.payload_base64))
                    $ticket=$json|ConvertFrom-Json
                    if (-not $ticket.queue_no -or $ticket.qr_data_url -notmatch '^data:image/(png|gif);base64,') { throw 'ข้อมูลบัตรคิวไม่ถูกต้อง' }
                    $printer=[KqDirectPrinter]::DefaultPrinter()
                    [KqDirectPrinter]::Print([string]$ticket.queue_no,[string]$ticket.department,[string]$ticket.service,[string]$ticket.slot,[string]$ticket.date,[string]$ticket.reference,[string]$ticket.token,[string]$ticket.qr_data_url)
                    Reply $stream 200 ((@{ok=$true;printed=$true;printer=$printer}|ConvertTo-Json -Compress)) $origin
                } catch { Reply $stream 400 ((@{ok=$false;error=$_.Exception.Message}|ConvertTo-Json -Compress)) $origin }
                continue
            }
            Reply $stream 400 '{"ok":false,"error":"Endpoint ไม่ถูกต้อง"}' $origin
        } finally { $client.Dispose() }
    }
} finally { $listener.Stop() }
