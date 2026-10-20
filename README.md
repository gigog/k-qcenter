# K-QCENTER

แพลตฟอร์มบริหารจัดการคิวส่วนกลาง โดยมี **K-QOnline** เป็น Mobile App สำหรับประชาชน และทำงานร่วมกับระบบหลังบ้าน เคาน์เตอร์บริการ และจอแสดงผล

เอกสารข้อกำหนดและสถาปัตยกรรมฉบับหลัก:

- [K-QCENTER System Specification](docs/K-QCENTER-SYSTEM-SPEC.md)
- [ผังสรุปการทำงาน K-QOnline และ K-QCENTER](docs/K-QCENTER-FLOW-SUMMARY.md)
- [คู่มือ Deploy และอัปเดตบน Windows/XAMPP](docs/DEPLOYMENT-WINDOWS.md)
- [คู่มือ Deploy และอัปเดตบน Ubuntu/Nginx/MariaDB](docs/DEPLOYMENT-UBUNTU.md)

## เปิดระบบต้นแบบ

เมื่อใช้ XAMPP/Apache เปิดได้ที่:

```text
http://localhost/k-qcenter/
```

ระบบต้องเปิดผ่าน Apache/PHP ไม่ควรเปิด `dist/index.html` โดยตรง เพราะหน้า Admin ใช้ Session และ API ฝั่งเซิร์ฟเวอร์

หน้าทดลองสำหรับผู้รับบริการ:

- K-QOnline (เครื่องพัฒนา): `http://localhost/k-qcenter/dist/online.html`
- Kiosk: `http://localhost/k-qcenter/dist/kiosk.html`
- ตรวจสอบสถานะคิว: `http://localhost/k-qcenter/dist/status.html`

### ติดตั้ง K-QOnline บนมือถือ

K-QOnline เป็น Progressive Web App (PWA) ติดตั้งจาก HTTPS ได้โดยไม่ต้องดาวน์โหลดจาก Store:

1. เปิดหน้า K-QOnline ด้วย Chrome บน Android หรือ Safari บน iPhone/iPad
2. Android กด **ติดตั้งแอป** หรือเมนู **เพิ่มลงในหน้าจอหลัก**
3. iPhone/iPad กด **แชร์** แล้วเลือก **เพิ่มไปยังหน้าจอโฮม**

แอปเปิดดูหน้าที่เคยใช้งานได้เมื่อสัญญาณขาดช่วง แต่การค้นหา Slot การจอง และการอัปเดตสถานะต้องออนไลน์เสมอเพื่อรักษา Capacity และสถานะคิวจาก K-QCENTER ให้ถูกต้อง

### แอปหลังบ้าน K-QCENTER

K-QCENTER มีชุดติดตั้ง PWA แยกจาก K-QOnline ทั้ง manifest, service worker, cache และไอคอน การใช้งานหลังบ้านยังบังคับ Login และต้องเข้าผ่าน Private HTTPS/Tailnet ส่วน K-QOnline ไม่ใช้บัญชีเจ้าหน้าที่และ Public Gateway เปิดเฉพาะ API สำหรับจองและดูสถานะคิวเท่านั้น

บน Ubuntu ช่วงทดสอบ Tailnet ให้ติดตั้งจากคนละ scope โดยใช้ K-QOnline ที่ `/online/` และ K-QCENTER ที่ `/dist/` ห้ามติดตั้ง K-QOnline จาก `/dist/online.html` เพราะจะใช้ scope ร่วมกับระบบหลังบ้าน

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

