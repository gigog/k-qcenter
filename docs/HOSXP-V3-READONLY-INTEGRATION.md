# การเชื่อม K-QCENTER กับ HOSxP v3 แบบอ่านอย่างเดียว

## ขอบเขตระยะแรก

ระบบใช้ CID ที่อ่านจากบัตรเฉพาะระหว่างคำขอเพื่อค้นหา `patient.hn` จาก HOSxP จากนั้นค้นหา Visit ของวันปัจจุบันใน `ovst` และชื่อจุดบริการจาก `kskdepartment` โดยไม่เขียนข้อมูลกลับ HOSxP

K-QCENTER เก็บ HN และ VN แบบ AES-256-GCM และเก็บ HN hash สำหรับอ้างอิง ข้อมูล CID ยังคงเก็บเฉพาะ HMAC ตามข้อกำหนดเดิม หน้าสาธารณะแสดงเพียงว่าพบ Visit หรือไม่และชื่อจุดบริการล่าสุด ไม่แสดง HN, VN, CID หรือข้อมูลการรักษา

## บัญชีฐานข้อมูล HOSxP

สร้างบัญชีเฉพาะสำหรับ K-QCENTER บน MariaDB ของ HOSxP และให้สิทธิ์ขั้นต่ำดังนี้

```sql
CREATE USER 'kqcenter_hosxp_ro'@'KQCENTER_SERVER_IP' IDENTIFIED BY 'รหัสผ่านแบบสุ่มที่ยาวและไม่ซ้ำ';
GRANT SELECT ON hos.patient TO 'kqcenter_hosxp_ro'@'KQCENTER_SERVER_IP';
GRANT SELECT ON hos.ovst TO 'kqcenter_hosxp_ro'@'KQCENTER_SERVER_IP';
GRANT SELECT ON hos.kskdepartment TO 'kqcenter_hosxp_ro'@'KQCENTER_SERVER_IP';
FLUSH PRIVILEGES;
```

จำกัด Firewall ให้พอร์ต MariaDB รับเฉพาะ IP ของ K-QCENTER ภายใน LAN ห้ามเปิดผ่าน Tailscale Funnel และห้ามใช้บัญชี `root` หรือบัญชีของโปรแกรม HOSxP

## Environment บน PHP-FPM

```ini
env[KQCENTER_HOSXP_DSN] = "mysql:host=HOSXP_DB_IP;port=3306;dbname=hos;charset=utf8mb4"
env[KQCENTER_HOSXP_USER] = "kqcenter_hosxp_ro"
env[KQCENTER_HOSXP_PASSWORD] = "รหัสผ่านจริงเก็บเฉพาะบนเซิร์ฟเวอร์"
```

หลังแก้ค่าให้ตรวจ configuration และ reload PHP-FPM จากนั้นออกคิวด้วยบัตรประชาชนหนึ่งครั้ง ระบบจะสร้างการเชื่อมโยงและหน้าสถานะจะตรวจ Visit วันนี้ใหม่ทุกครั้งที่ผู้ใช้กดอัปเดต

## สถานะการเชื่อมโยง

- `PATIENT_NOT_FOUND` — ไม่พบ CID ใน `patient`
- `WAITING_FOR_VISIT` — พบ HN แต่ยังไม่พบ Visit วันนี้
- `VISIT_FOUND` — พบ VN วันนี้และชื่อจุดบริการล่าสุด
- `ERROR` — เชื่อมต่อหรืออ่าน HOSxP ไม่สำเร็จ โดยระบบคิวยังทำงานต่อ
- `NOT_LINKED` — คิวนี้ไม่ได้สร้างหรือยืนยันด้วยบัตรประชาชน

ก่อนใช้งานจริงควรให้ผู้ดูแล HOSxP/BMS ตรวจสอบ SQL และสิทธิ์บัญชี read-only ตามนโยบายของโรงพยาบาล
