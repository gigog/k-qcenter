# การ Deploy K-QCENTER บน Ubuntu/Nginx/MariaDB

สภาพแวดล้อม Pilot ปัจจุบัน:

| รายการ | ค่า |
|---|---|
| Server | `10.9.1.22` (`web-lab`) |
| OS | Ubuntu 26.04 LTS |
| Source | `/var/www/k-qcenter` |
| Web server | Nginx |
| Runtime | PHP 8.5-FPM แยก pool `kqcenter` |
| Database | MariaDB database `kqcenter`, user `kqcenter_app` |
| Public HTTPS | `https://web-lab.tail53909d.ts.net/` ผ่าน Tailscale Funnel |
| Private HTTPS | `https://web-lab.tail53909d.ts.net:8443/` ผ่าน Tailscale Serve |

## การแยกบริการ

- K-Asset เดิมคงใช้ Nginx พอร์ต 80 และ `/var/www/hospital`
- K-QCENTER Public Gateway ฟังเฉพาะ `127.0.0.1:8088`
- K-QCENTER Admin/Kiosk ฟังเฉพาะ `127.0.0.1:8089`
- Funnel เปิดเฉพาะ K-QOnline, Queue Status และ Public API แบบ allowlist
- Admin, Kiosk, MariaDB, phpMyAdmin และไฟล์ภายในไม่เปิดผ่าน Funnel

## ความปลอดภัยฐานข้อมูล

- K-QCENTER แยก database และ account จาก K-Asset
- Credential อยู่ใน `/etc/php/8.5/fpm/pool.d/kqcenter.conf` บน Server และไม่เก็บใน Git
- MariaDB ใช้ InnoDB และ Transaction
- Booking/Walk-in ล็อกแถว Slot ด้วย `SELECT ... FOR UPDATE`
- Firewall อนุญาต MariaDB เฉพาะ `10.9.1.0/24`; ไม่มี rule เปิด `3306` ให้ทุกแหล่ง
- Timestamp ในฐานข้อมูลใช้ UTC; หน้าเว็บแสดง พ.ศ. และ Asia/Bangkok

## อัปเดตในอนาคต

เปิด SSH แล้วรัน:

```bash
cd /var/www/k-qcenter
bash deploy/linux/update.sh
```

สคริปต์จะสำรอง MariaDB, pull แบบ fast-forward เท่านั้น, ตรวจ PHP/Nginx, reload service และตรวจ API health การสำรองเก็บที่ `/var/backups/k-qcenter`

## Sync การตั้งค่าระหว่างเครื่องพัฒนาและ Server

ข้อมูลที่ sync คือการตั้งค่าเท่านั้น ได้แก่ อาคาร สาขา แผนก บริการ ตารางเวลา Daily Override จุด Kiosk และปุ่ม Kiosk ไม่รวมผู้ใช้ รหัสผ่าน การจอง บัตรคิว Slot และ Audit log

### ดึงจาก Server ลงเครื่องพัฒนา

```bash
bash deploy/linux/export-config.sh /tmp/kqcenter-config.json
```

คัดลอกไฟล์มาที่เครื่องพัฒนาแล้วรัน `php tools/import-config.php <ไฟล์>` เครื่องมือนี้แทนที่ข้อมูลใน SQLite ทั้งหมด จึงใช้กับเครื่องพัฒนาเท่านั้น

### ส่งจากเครื่องพัฒนาขึ้น Server

1. Deploy โค้ดล่าสุดด้วย `update.sh` ก่อน เพื่อให้ schema ตรงกัน
2. บนเครื่องพัฒนา สร้างไฟล์ SQL:

   ```powershell
   C:\xampp\php\php.exe tools\export-config-sql.php
   ```

3. คัดลอกไฟล์ `data/exports/kqcenter-config-*.sql` ไปยัง `/tmp` ของ Server แล้วรัน:

   ```bash
   cd /var/www/k-qcenter
   bash deploy/linux/apply-config.sh /tmp/kqcenter-config-YYYYMMDD-HHMMSS.sql
   ```

พฤติกรรมของการส่งขึ้น Server:

- สำรอง MariaDB ไว้ที่ `/var/backups/k-qcenter/kqcenter-before-config-*.sql.gz` ก่อนทุกครั้ง
- ตรวจ schema และตรวจว่ารหัสอาคาร สาขา แผนก บริการ หรือ Kiosk ไม่ซ้ำกับรายการคนละ id ก่อนแก้ข้อมูล หากพบจะหยุดโดยไม่เปลี่ยนแปลงอะไร
- อาคาร สาขา แผนก บริการ และจุด Kiosk ปรับตาม id รายการที่ไม่มีในเครื่องพัฒนาจะถูกปิดใช้งาน ไม่ถูกลบ เพื่อรักษาการจองที่อ้างถึง
- ตารางเวลา Daily Override และปุ่ม Kiosk ถูกแทนที่ทั้งชุด
- ทำงานใน Transaction เดียว
- Slot ของวันนี้ที่สร้างแล้วไม่เปลี่ยนตามค่าแผนกใหม่ ใช้ Today Override หากต้องปรับภายในวัน
- ควรทำนอกช่วงที่ผู้ดูแลกำลังแก้การตั้งค่าบน Server เพราะการแก้บน Server หลังจากดึงข้อมูลลงมาจะถูกเขียนทับ

## ตรวจสถานะ

```bash
systemctl status nginx php8.5-fpm mariadb tailscaled --no-pager
tailscale funnel status
tailscale serve status
curl http://127.0.0.1:8088/api/index.php?action=health
curl http://127.0.0.1:8089/api/index.php?action=health
```

