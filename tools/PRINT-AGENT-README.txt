K-QCENTER Print Agent
====================

วิธีติดตั้ง
1. แตกไฟล์ ZIP ทั้งหมดก่อน ห้ามเปิดไฟล์ CMD จากใน ZIP โดยตรง
2. ดับเบิลคลิก Install-K-QCENTER-Print-Agent.cmd
3. รอจนแสดงข้อความ Installation completed
4. ตั้งเครื่องพิมพ์บัตรคิวเป็น Default Printer ของ Windows
5. เปิด Google Chrome และอนุญาต Local network access ให้เว็บไซต์ K-QCENTER

ตรวจสอบ Agent:
curl.exe -H "Origin: https://web-lab.tail53909d.ts.net:8443" http://127.0.0.1:17891/health

Agent ทำงานเบื้องหลังโดยไม่มีหน้าต่าง และเริ่มอัตโนมัติเมื่อเข้าสู่ระบบ Windows

การเริ่มอัตโนมัติ
- ตัวติดตั้งสร้าง Scheduled Task ชื่อ K-QCENTER Print Agent
- Agent เริ่มเองทุกครั้งเมื่อผู้ใช้บัญชีที่ติดตั้งเข้าสู่ Windows
- หาก Agent หยุดผิดปกติ Windows จะพยายามเริ่มใหม่อัตโนมัติ

วิธีถอนการติดตั้ง
1. ดับเบิลคลิก Uninstall-K-QCENTER-Print-Agent.cmd
2. ระบบจะหยุด Agent ลบ Scheduled Task และลบไฟล์ใน Local AppData
