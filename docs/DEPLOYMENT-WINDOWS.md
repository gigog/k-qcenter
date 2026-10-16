# การ Deploy K-QCENTER บน Windows Server/XAMPP

## เป้าหมาย

- Server: `10.9.1.22`
- Source: `https://github.com/gigog/k-qcenter.git`
- Install path: `C:\xampp\htdocs\k-qcenter`
- Admin URL ภายใน: `http://10.9.1.22/k-qcenter/`
- Public API ช่วง Pilot: HTTPS ผ่าน Tailscale Funnel เฉพาะ endpoint ที่กำหนด
- Admin: Tailscale Serve หรือ LAN เท่านั้น

## HTTPS ผ่าน Tailscale (ช่วงทดลอง)

ระบบแยกช่องทางดังนี้:

- Public Funnel `:443` เปิดเฉพาะ K-QOnline, Queue Status และ Public API ผ่าน `127.0.0.1:8088`
- Private Serve `:8443` เปิด Admin และ Kiosk ให้เฉพาะอุปกรณ์ใน Tailnet ผ่าน Apache พอร์ต `80`
- Database, Redis, HOSxP, phpMyAdmin, RDP และหน้าหลังบ้านไม่ถูกเปิดผ่าน Funnel

หลังอัปเดต source บนเซิร์ฟเวอร์ ให้เปิด PowerShell แบบ Administrator แล้วรัน:

```powershell
Set-Location C:\xampp\htdocs\k-qcenter
powershell.exe -ExecutionPolicy Bypass -File .\deploy\windows\configure-tailscale-https.ps1
```

สคริปต์จะสำรอง `httpd.conf`, ติดตั้ง Apache VirtualHost สำหรับ Public Gateway, ตรวจ configuration, restart Apache, ตรวจ health และตั้งค่า Tailscale Funnel/Serve แบบ background

ตรวจสอบภายหลังด้วย:

```powershell
tailscale funnel status
tailscale serve status
```

ถ้าทดลองเฉพาะผู้ใช้ใน Tailnet และยังไม่ต้องการออกอินเทอร์เน็ต:

```powershell
powershell.exe -ExecutionPolicy Bypass -File .\deploy\windows\configure-tailscale-https.ps1 -SkipFunnel
```

## สิ่งที่ต้องมีบน Server

1. Windows Server/Windows PC ที่กำหนด IP `10.9.1.22`
2. XAMPP พร้อม Apache และ PHP 8.2+
3. PHP extensions: PDO, pdo_sqlite, mbstring, openssl
4. Git for Windows
5. Tailscale สำหรับ HTTPS ช่วง Pilot
6. Firewall อนุญาต HTTP เฉพาะ LAN/Tailnet และไม่ทำ Router Port Forward

## ติดตั้งครั้งแรก

เปิด PowerShell แบบ Administrator แล้วรันสคริปต์จากสำเนา repository หรือดาวน์โหลดเฉพาะไฟล์ installer:

```powershell
powershell -ExecutionPolicy Bypass -File .\deploy\windows\install.ps1
```

เปิด Apache แล้วตรวจ:

```text
http://127.0.0.1/k-qcenter/api/index.php?action=health
http://127.0.0.1/k-qcenter/
```

หน้าแรกจะให้สร้างบัญชีผู้ดูแล ไม่มีรหัสผ่านเริ่มต้น

## อัปเดตในอนาคต

```powershell
powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\k-qcenter\deploy\windows\update.ps1
```

สคริปต์จะสำรอง SQLite ก่อน `git pull --ff-only`, ตรวจ PHP syntax และเรียก health check การใช้ `--ff-only` ป้องกันการ merge หรือเขียนทับ source บน Server โดยไม่ตั้งใจ

## สำรองข้อมูล

```powershell
powershell -ExecutionPolicy Bypass -File C:\xampp\htdocs\k-qcenter\deploy\windows\backup.ps1
```

ควรตั้ง Windows Task Scheduler ให้สำรองทุกวันไปยังดิสก์หรือเครื่องอื่น ไม่ควรเก็บ backup ไว้เฉพาะ Server เดียว

## Tailscale

หลังบ้านควรเปิดเฉพาะ Tailnet:

```powershell
tailscale serve --bg http://127.0.0.1/k-qcenter/
```

Public API สำหรับ K-QOnline ต้องแยก path/virtual host ก่อนเปิด Funnel และต้องมี rate limit กับ authentication ห้าม Funnel ทั้งหน้า Admin หรือ document root

## Rollback

1. หยุดรับการจองใหม่ชั่วคราว
2. สำรองฐานข้อมูลปัจจุบัน
3. เลือก commit ที่ผ่านการทดสอบแล้ว
4. ใช้ `git switch --detach <commit>` เฉพาะ source ห้ามลบโฟลเดอร์ `data`
5. ตรวจ PHP syntax และ health endpoint
6. หากต้องย้อนข้อมูล ให้นำ backup กลับหลังตรวจสอบว่า schema เข้ากันได้

