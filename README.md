# K-QCENTER

แพลตฟอร์มบริหารจัดการคิวส่วนกลาง โดยมี **K-QOnline** เป็น Mobile App สำหรับประชาชน และทำงานร่วมกับระบบหลังบ้าน เคาน์เตอร์บริการ และจอแสดงผล

เอกสารข้อกำหนดและสถาปัตยกรรมฉบับหลัก:

- [K-QCENTER System Specification](docs/K-QCENTER-SYSTEM-SPEC.md)
- [คู่มือ Deploy และอัปเดตบน Windows/XAMPP](docs/DEPLOYMENT-WINDOWS.md)
- [คู่มือ Deploy และอัปเดตบน Ubuntu/Nginx/MariaDB](docs/DEPLOYMENT-UBUNTU.md)

## เปิดระบบต้นแบบ

เมื่อใช้ XAMPP/Apache เปิดได้ที่:

```text
http://localhost/k-qcenter/
```

ระบบต้องเปิดผ่าน Apache/PHP ไม่ควรเปิด `dist/index.html` โดยตรง เพราะหน้า Admin ใช้ Session และ API ฝั่งเซิร์ฟเวอร์

หน้าทดลองสำหรับผู้รับบริการ:

- K-QOnline: `http://localhost/k-qcenter/dist/online.html`
- Kiosk: `http://localhost/k-qcenter/dist/kiosk.html`
- ตรวจสอบสถานะคิว: `http://localhost/k-qcenter/dist/status.html`

เมื่อเปิดครั้งแรก ระบบจะสร้างฐานข้อมูล SQLite และแสดงหน้าสร้างบัญชีผู้ดูแล ให้ใช้รหัสผ่านอย่างน้อย 10 ตัวอักษร จากนั้นข้อมูลสาขา แผนก บริการ Slot การจอง และ Audit log จะถูกบันทึกใน `data/kqcenter.sqlite`

โฟลเดอร์ที่ใช้ให้บริการหน้าเว็บคือ `dist/` และสามารถนำพอร์ตของ Apache ออก HTTPS ผ่าน Tailscale Serve สำหรับหลังบ้านได้

## Backend API

API อยู่ที่ `api/index.php` และรองรับส่วนหลักแล้ว:

- First-run setup และ Session login
- Dashboard
- สาขา แผนก และประเภทบริการ
- สร้าง Slot อัตโนมัติตามเวลาเปิด–ปิดและ Closing buffer
- คำนวณ Capacity ตามช่องบริการ เวลาเฉลี่ย และ Efficiency
- Booking transaction พร้อมตรวจ Capacity
- Idempotency key ป้องกันการจองซ้ำ
- ปรับ Capacity ของ Slot
- Audit log

ระบบรองรับ SQLite สำหรับเครื่องพัฒนาและ MariaDB สำหรับ Server/Production โดยกำหนด `KQCENTER_DB_DSN`, `KQCENTER_DB_USER` และ `KQCENTER_DB_PASSWORD` ผ่าน environment ของ PHP-FPM การติดตั้งบน Linux ใช้ MariaDB/InnoDB พร้อม row locking เพื่อป้องกันการรับจองเกิน Capacity

มาตรฐานร่วมของทุกระบบ:

- ใช้ฟอนต์ **Sarabun** ทั้งระบบ
- แสดงวันที่ด้วยปฏิทินไทยและปี **พ.ศ.**
- ใช้เขตเวลา **Asia/Bangkok (UTC+7)** สำหรับการแสดงผลและกฎเวลาทำการ
- K-QCENTER เป็นแหล่งข้อมูลและผู้ควบคุมสถานะคิวเพียงระบบเดียว

