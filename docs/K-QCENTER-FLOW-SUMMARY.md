# ผังสรุปการทำงาน K-QOnline และ K-QCENTER

เอกสารนี้สรุปเส้นทางตั้งแต่การตั้งค่าหลังบ้าน การจองออนไลน์ การรับคิว Walk-in การยืนยันรับบัตรคิว การเรียกคิว การติดตามสถานะ และการอ่านสถานะ Visit จาก HOSxP v3

## 1. ขอบเขตของสองระบบ

| ระบบ | ผู้ใช้งาน | หน้าที่หลัก |
|---|---|---|
| **K-QOnline** | ผู้รับบริการผ่านมือถือ/PWA | ดู Slot, จองคิว, เก็บ QR/รหัสอ้างอิง, เปิด QR รับบัตรคิว และติดตามสถานะ |
| **K-QCENTER** | ผู้ดูแล เจ้าหน้าที่ Kiosk Counter และจอ Monitor | ตั้งค่า Slot/Capacity, ตรวจการจอง, ออกบัตรคิว, เรียกคิว, ประกาศเสียง, แสดงสถานะ และเชื่อม HOSxP แบบอ่านอย่างเดียว |

K-QCENTER API เป็นแหล่งข้อมูลหลักของ Slot, Capacity, Booking และ Queue State ทุก Client ติดต่อผ่าน API เท่านั้น

```mermaid
flowchart LR
    U[ผู้รับบริการ] -->|มือถือ| O[K-QOnline PWA]
    U -->|หน้าสถานที่| K[Kiosk]
    A[ผู้ดูแล] --> ADM[K-QAdmin]
    S[เจ้าหน้าที่] --> C[K-QCounter]
    M[ผู้รอรับบริการ] --> D[K-QDisplay]

    O --> API[K-QCENTER API]
    K --> API
    ADM --> API
    C --> API
    D --> API

    API --> DB[(MariaDB)]
    API --> H[HOSxP Read-only Adapter]
    H --> HDB[(HOSxP v3 MariaDB)]
```

## 2. Flow การตั้งค่าจาก K-QCENTER

```mermaid
flowchart TD
    A[ผู้ดูแลเข้าสู่ K-QAdmin] --> B[ตั้งค่าอาคาร/จุดบริการ Kiosk]
    B --> C[ตั้งค่าสาขา แผนก และบริการ]
    C --> D[ตั้งเวลาทำการรายสัปดาห์]
    D --> E{มีวันหยุดหรือปิดเฉพาะวัน?}
    E -->|มี| F[ตั้ง Daily Override]
    E -->|ไม่มี| G[ใช้ตารางปกติ]
    F --> H[ตั้ง Slot และ Capacity]
    G --> H
    H --> I[กำหนดจำกัดจำนวนหรือไม่จำกัดคิว]
    I --> J[กำหนดเมนูหลัก/เมนูย่อยของ Kiosk Template]
    J --> K[เปิดใช้งาน]
    K --> L[K-QOnline และ Kiosk เห็นเฉพาะบริการที่เปิดรับ]
```

## 3. Flow จองออนไลน์ผ่าน K-QOnline

```mermaid
flowchart TD
    A[เปิด K-QOnline] --> B[เลือกวันที่ พ.ศ. และ Slot]
    B --> C[กรอกชื่อ CID และเบอร์มือถือ]
    C --> D[กดยืนยันการจอง]
    D --> E{มือถือเครื่องนี้มีรายการ<br/>ในวันรับบริการนี้แล้วหรือไม่?}
    E -->|มีแล้ว| F[ไม่สร้างรายการใหม่<br/>ให้เปิดเมนูคิวของฉัน]
    E -->|ยังไม่มี| G[K-QCENTER ล็อกและตรวจ Capacity]
    G --> H{Slot ยังรับได้หรือไม่?}
    H -->|เต็ม/ปิด| I[แจ้งเลือก Slot อื่น]
    H -->|รับได้| J[สร้าง Booking แบบ Transaction]
    J --> K[สถานะ CONFIRMED / BOOKED]
    K --> L[แสดงเลขอ้างอิง KQ-...]
    L --> M[แสดง QR สำหรับรับบัตรคิว]
    M --> N[บันทึกรายการในคิวของฉัน]
```

กติกาปัจจุบันคือหนึ่งอุปกรณ์จองออนไลน์ได้หนึ่งรายการต่อหนึ่งวันรับบริการ โดย Client เก็บ Device ID แบบสุ่ม ส่วนเซิร์ฟเวอร์เก็บเฉพาะค่า Hash ผูกกับวันที่ของ Slot

## 4. Flow ยืนยันการจองและรับบัตรคิวที่ Kiosk

```mermaid
flowchart TD
    A[มาถึงสถานที่] --> B[กดรับบัตรคิวจากการจองออนไลน์]
    B --> C{เลือกวิธียืนยัน}
    C -->|QR| D[เปิดคิวของฉัน > แสดง QR รับบัตรคิว]
    C -->|รหัส| E[กรอกรหัสอ้างอิง KQ-...]
    C -->|เบอร์มือถือ| F[กรอกเบอร์ที่ใช้จอง]
    C -->|CID| G[กรอกหรืออ่านบัตรประชาชน]
    D --> H[Kiosk ค้นหารายการของวันนี้ผ่าน API]
    E --> H
    F --> H
    G --> H
    H --> I{พบรายการหรือไม่?}
    I -->|ไม่พบ| J[ให้ตรวจข้อมูล/วันนัด<br/>หรือติดต่อเจ้าหน้าที่]
    I -->|พบหนึ่งรายการ| K[แสดงชื่อแบบปกปิด แผนก และช่วงเวลา]
    I -->|พบหลายรายการ| L[ให้เลือกรายการที่ถูกต้อง]
    L --> K
    K --> M[ผู้ใช้กดยืนยันการมาถึง]
    M --> N{มี Queue Ticket เดิมหรือไม่?}
    N -->|มี| O[คืนเลขคิวเดิมเพื่อพิมพ์ซ้ำ]
    N -->|ไม่มี| P[สร้าง Queue Ticket]
    P --> Q[สถานะ REGISTRATION_WAITING]
    O --> R[พิมพ์บัตรคิวพร้อม QR สถานะ]
    Q --> R
```

การค้นหารายการยังไม่เปลี่ยนสถานะ การเปลี่ยนจาก Booking เป็น Queue Ticket เกิดเมื่อกด **ยืนยันการมาถึงและพิมพ์บัตรคิว** เท่านั้น

## 5. Flow Walk-in ผ่าน Kiosk

```mermaid
flowchart TD
    A[ผู้รับบริการหน้า Kiosk] --> B{มีบัตรประชาชนหรือไม่?}
    B -->|มี| C[เสียบบัตรและอ่าน CID/ชื่อ]
    B -->|ไม่มี| D[กดไม่มีบัตร]
    C --> E{พบรายการจอง/คิวเดิมวันนี้?}
    E -->|รายการจอง| F[เสนอให้ยืนยันรับบัตรคิว]
    E -->|คิวเดิม| G[เสนอพิมพ์บัตรเดิมหรือออกคิวบริการอื่น]
    E -->|ไม่พบ| H[แสดงเมนูตาม Kiosk Template]
    D --> H
    H --> I[เลือกแผนก/บริการ]
    I --> J[K-QCENTER ตรวจเวลาเปิดและ Capacity]
    J --> K{มี Slot ที่รับได้หรือไม่?}
    K -->|ไม่มี| L[แจ้งติดต่อเจ้าหน้าที่]
    K -->|มีหนึ่ง Slot| M[ออกคิวอัตโนมัติ]
    K -->|มีหลาย Slot| N[เลือกช่วงเวลา]
    N --> M
    M --> O[สร้าง Booking + Queue Ticket ใน Transaction เดียว]
    O --> P[พิมพ์บัตรคิวพร้อม QR]
```

## 6. Flow การเรียกคิวและให้บริการ

```mermaid
flowchart TD
    A[Queue Ticket: REGISTRATION_WAITING] --> B[เจ้าหน้าที่ K-QCounter กดเรียก]
    B --> C[สถานะ REGISTRATION_CALLED]
    C --> D[K-QDisplay แสดงคิวและจุดติดต่อ]
    C --> E[ระบบประกาศเลขคิว ชื่อ และจุดติดต่อ]
    E --> F{จุดเดียวกันมีเสียงก่อนหน้าหรือไม่?}
    F -->|มี| G[เข้า FIFO รอเสียงก่อนหน้าอ่านจบ]
    F -->|ไม่มี| H[เล่นเสียงทันที]
    G --> H
    H --> I{ผู้รับบริการมาหรือไม่?}
    I -->|ยังไม่มา| J[เรียกซ้ำตามสิทธิ์และกติกา]
    I -->|มาแล้ว| K[เริ่มลงทะเบียน]
    K --> L[สถานะ REGISTRATION_IN_PROGRESS]
    L --> M[เจ้าหน้าที่เปิด Visit ใน HOSxP]
    M --> N[K-QCENTER อ่าน CID > HN > Visit วันนี้ > VN]
    N --> O[ผูก VN กับ Queue Ticket]
    O --> P[ส่งต่อแผนก]
    P --> Q[สถานะ SENT_TO_DEPARTMENT / COMPLETED]
```

ระยะแรก K-QCENTER อ่านสถานะ HOSxP อย่างเดียว ไม่ส่งตรวจและไม่เปิด Visit แทนเจ้าหน้าที่

## 7. Flow การติดตามสถานะ

```mermaid
flowchart LR
    A[QR/รหัสคิว] --> B{ช่องทางตรวจสอบ}
    B --> C[K-QOnline: คิวของฉัน]
    B --> D[มือถือสแกน QR บนใบคิว]
    B --> E[Kiosk: ตรวจสอบสถานะ]
    C --> API[K-QCENTER Status API]
    D --> API
    E --> API
    API --> F[สถานะปัจจุบัน]
    API --> G[เวลารอโดยประมาณ]
    API --> H[จุดที่ต้องไปต่อ]
    API --> I[Timeline การรับบริการ]
    API --> J[สถานะ Visit วันนี้จาก HOSxP]
```

การดูสถานะเป็น Read-only ไม่สร้างคิวและไม่เปลี่ยนสถานะ หาก Booking ยังไม่เช็กอิน K-QOnline จะแสดงปุ่ม **แสดง QR รับบัตรคิว**

## 8. สถานะหลักของ Booking และ Queue

```mermaid
stateDiagram-v2
    [*] --> CONFIRMED: จองออนไลน์สำเร็จ
    CONFIRMED --> CHECKED_IN: ยืนยันที่ Kiosk
    CHECKED_IN --> REGISTRATION_WAITING: สร้าง Queue Ticket
    REGISTRATION_WAITING --> REGISTRATION_CALLED: เจ้าหน้าที่เรียก
    REGISTRATION_CALLED --> REGISTRATION_CALLED: เรียกซ้ำ
    REGISTRATION_CALLED --> REGISTRATION_IN_PROGRESS: เริ่มลงทะเบียน
    REGISTRATION_IN_PROGRESS --> SENT_TO_DEPARTMENT: เปิด Visit/ส่งต่อ
    SENT_TO_DEPARTMENT --> COMPLETED: จบบริการ
    CONFIRMED --> CANCELLED: ยกเลิก
    CONFIRMED --> EXPIRED: ไม่มาเช็กอินตามเงื่อนไข
```

## 9. จุดควบคุมสำคัญ

- วันและเวลาที่ผู้ใช้เห็นเป็น พ.ศ. และ Asia/Bangkok แต่ Timestamp ภายในใช้ UTC/ISO 8601
- Booking และ Walk-in ต้อง Transaction-safe, Capacity-safe และ Idempotent
- การสแกนหรือค้นหาซ้ำต้องไม่สร้างเลขคิวซ้ำ
- K-QOnline และ Kiosk เชื่อมผ่าน API เท่านั้น ห้ามเชื่อม MariaDB/HOSxP โดยตรง
- หน้า Public แสดงชื่อแบบปกปิดและไม่เปิดเผย CID, HN หรือ VN
- Admin, Database, Redis, HOSxP และหน้าหลังบ้านต้องไม่เปิดผ่าน Tailscale Funnel
- สิทธิ์หลังบ้านจำกัดตามองค์กร สาขา แผนก และบทบาท

## 10. สรุปเส้นทางใช้งานแบบสั้น

```text
จองออนไลน์:
K-QOnline → เลือก Slot → จอง → QR/รหัส → Kiosk ยืนยัน → พิมพ์บัตร → Counter เรียก → เปิด Visit → ส่งแผนก

Walk-in:
Kiosk → อ่านบัตร/ไม่มีบัตร → เลือกบริการ → ตรวจ Slot → ออกและพิมพ์บัตร → Counter เรียก → เปิด Visit → ส่งแผนก

ติดตามสถานะ:
คิวของฉัน/QR ใบคิว/Kiosk → K-QCENTER Status API → สถานะ + เวลารอ + จุดถัดไป + HOSxP Visit
```
