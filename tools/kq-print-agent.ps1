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
using System.IO;
public static class KqDirectPrinter {
  public static string DefaultPrinter() { using (PrintDocument d=new PrintDocument()) return d.PrinterSettings.IsValid?d.PrinterSettings.PrinterName:""; }
  public static void Print(string no,string department,string service,string slot,string date,string reference,string token,string qrUrl) {
    byte[] bytes=Convert.FromBase64String(qrUrl.Substring(qrUrl.IndexOf(',')+1));
    using(MemoryStream stream=new MemoryStream(bytes)) using(Image qr=Image.FromStream(stream)) using(PrintDocument doc=new PrintDocument()) {
      if(!doc.PrinterSettings.IsValid) throw new InvalidOperationException("ไม่พบเครื่องพิมพ์ Default ที่พร้อมใช้งาน");
      doc.DocumentName="K-QCENTER "+no; doc.PrintController=new StandardPrintController();
      doc.DefaultPageSettings.Margins=new Margins(8,8,6,6); doc.DefaultPageSettings.PaperSize=new PaperSize("K-QCENTER 80mm",315,650);
      doc.PrintPage += delegate(object sender,PrintPageEventArgs e) {
        float w=e.MarginBounds.Width,l=e.MarginBounds.Left,y=e.MarginBounds.Top; StringFormat c=new StringFormat{Alignment=StringAlignment.Center};
        using(Font brand=new Font("Sarabun",11,FontStyle.Bold)) using(Font label=new Font("Sarabun",8)) using(Font num=new Font("Arial",27,FontStyle.Bold)) using(Font title=new Font("Sarabun",13,FontStyle.Bold)) using(Font normal=new Font("Sarabun",9)) using(Font tiny=new Font("Arial",6)) using(Pen dash=new Pen(Color.Gray,1)) {
          dash.DashStyle=System.Drawing.Drawing2D.DashStyle.Dash;
          e.Graphics.DrawString("K-QCENTER",brand,Brushes.Black,new RectangleF(l,y,w,24),c);y+=22;e.Graphics.DrawString("บัตรคิวรับบริการ",label,Brushes.Black,new RectangleF(l,y,w,18),c);y+=28;
          e.Graphics.DrawString("หมายเลขคิว",label,Brushes.Black,new RectangleF(l,y,w,18),c);y+=16;e.Graphics.DrawString(no,num,Brushes.Black,new RectangleF(l,y,w,45),c);y+=51;e.Graphics.DrawLine(dash,l,y,l+w,y);y+=12;
          e.Graphics.DrawString(department,title,Brushes.Black,new RectangleF(l,y,w,28),c);y+=25;if(!String.IsNullOrWhiteSpace(service)){e.Graphics.DrawString(service,normal,Brushes.Black,new RectangleF(l,y,w,20),c);y+=19;}
          e.Graphics.DrawString(slot,normal,Brushes.Black,new RectangleF(l,y,w,20),c);y+=19;e.Graphics.DrawString(date,normal,Brushes.Black,new RectangleF(l,y,w,20),c);y+=19;e.Graphics.DrawString(reference,label,Brushes.Black,new RectangleF(l,y,w,18),c);y+=23;
          float size=Math.Min(180,w-30);e.Graphics.DrawImage(qr,l+(w-size)/2,y,size,size);y+=size+8;e.Graphics.DrawString("สแกน QR เพื่อติดตามสถานะคิว",normal,Brushes.Black,new RectangleF(l,y,w,22),c);y+=20;e.Graphics.DrawString(token,tiny,Brushes.Black,new RectangleF(l,y,w,18),c);y+=22;e.Graphics.DrawLine(dash,l,y,l+w,y);y+=8;e.Graphics.DrawString("โปรดเก็บบัตรนี้ไว้จนกว่าจะรับบริการเสร็จ",label,Brushes.Black,new RectangleF(l,y,w,22),c);
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
            if ($request -match '^GET /health ') { $printer=[KqDirectPrinter]::DefaultPrinter(); Reply $stream 200 ((@{ok=$true;service='K-QCENTER Print Agent';agent_version=2;printer_ready=[bool]$printer;default_printer=$printer}|ConvertTo-Json -Compress)) $origin; continue }
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
