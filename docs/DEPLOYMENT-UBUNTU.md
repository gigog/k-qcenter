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

## ตรวจสถานะ

```bash
systemctl status nginx php8.5-fpm mariadb tailscaled --no-pager
tailscale funnel status
tailscale serve status
curl http://127.0.0.1:8088/api/index.php?action=health
curl http://127.0.0.1:8089/api/index.php?action=health
```

