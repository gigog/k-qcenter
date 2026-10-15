<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

try {
    $pdo = db();
    $action = $_GET['action'] ?? 'health';

    if ($action === 'health') {
        respond(['ok'=>true,'service'=>'K-QCENTER API','database'=>'connected','time'=>(new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format(DATE_ATOM)]);
    }

    if ($action === 'setup-status') {
        respond(['ok'=>true,'needs_setup'=>(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0]);
    }

    if ($action === 'setup') {
        requireMethod('POST');
        if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) fail('ตั้งค่าระบบแล้ว', 409);
        $in = jsonInput();
        $username = trim((string)($in['username'] ?? ''));
        $displayName = trim((string)($in['display_name'] ?? ''));
        $password = (string)($in['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) fail('ชื่อผู้ใช้ต้องมี 3–40 ตัวอักษร');
        if (mb_strlen($displayName) < 2) fail('กรุณาระบุชื่อผู้ดูแล');
        if (strlen($password) < 10) fail('รหัสผ่านต้องมีอย่างน้อย 10 ตัวอักษร');
        $stmt = $pdo->prepare('INSERT INTO users(username,display_name,password_hash,role) VALUES (?,?,?,?)');
        $stmt->execute([$username,$displayName,password_hash($password, PASSWORD_DEFAULT),'admin']);
        audit((int)$pdo->lastInsertId(),'SYSTEM_SETUP','user',(string)$pdo->lastInsertId());
        respond(['ok'=>true], 201);
    }

    if ($action === 'login') {
        requireMethod('POST');
        $in = jsonInput();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username=? AND active=1');
        $stmt->execute([trim((string)($in['username'] ?? ''))]);
        $user = $stmt->fetch();
        if (!$user || !password_verify((string)($in['password'] ?? ''), $user['password_hash'])) {
            usleep(300000); fail('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง', 401);
        }
        startSecureSession(); session_regenerate_id(true); $_SESSION['user_id'] = (int)$user['id'];
        $pdo->prepare('UPDATE users SET last_login_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$user['id']]);
        audit((int)$user['id'],'LOGIN','session');
        respond(['ok'=>true,'user'=>['id'=>$user['id'],'display_name'=>$user['display_name'],'role'=>$user['role']]]);
    }

    if ($action === 'logout') {
        requireMethod('POST'); $user = currentUser();
        if ($user) audit((int)$user['id'],'LOGOUT','session');
        startSecureSession(); $_SESSION = []; session_destroy(); respond(['ok'=>true]);
    }

    if ($action === 'me') respond(['ok'=>true,'user'=>requireUser()]);

    if ($action === 'dashboard') {
        requireUser();
        $date = $_GET['date'] ?? (new DateTimeImmutable('today', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
        generateSlots($pdo, $date);
        $stmt = $pdo->prepare("SELECT COUNT(*) total, SUM(CASE WHEN status='COMPLETED' THEN 1 ELSE 0 END) completed, SUM(CASE WHEN status IN ('CONFIRMED','CHECKED_IN','WAITING','CALLED','SERVING') THEN 1 ELSE 0 END) active FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.slot_date=?");
        $stmt->execute([$date]); $summary = $stmt->fetch();
        $stmt = $pdo->prepare('SELECT d.id,d.name,d.counter_count,d.efficiency,COALESCE(SUM(s.capacity),0) capacity,COALESCE(SUM(s.booked_count),0) booked FROM departments d LEFT JOIN slots s ON s.department_id=d.id AND s.slot_date=? WHERE d.active=1 GROUP BY d.id ORDER BY d.id');
        $stmt->execute([$date]); $departments = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(capacity),0) capacity,COALESCE(SUM(booked_count),0) booked FROM slots WHERE slot_date=? AND active=1');
        $stmt->execute([$date]); $cap = $stmt->fetch();
        respond(['ok'=>true,'date'=>$date,'summary'=>['total'=>(int)($summary['total']??0),'completed'=>(int)($summary['completed']??0),'active'=>(int)($summary['active']??0),'capacity'=>(int)$cap['capacity'],'booked'=>(int)$cap['booked']],'departments'=>$departments]);
    }

    if ($action === 'branches') {
        requireUser();
        $rows = $pdo->query('SELECT b.*, COUNT(DISTINCT d.id) department_count, COALESCE(SUM(d.counter_count),0) counter_count FROM branches b LEFT JOIN departments d ON d.branch_id=b.id GROUP BY b.id ORDER BY b.id')->fetchAll();
        respond(['ok'=>true,'data'=>$rows]);
    }

    if ($action === 'departments') {
        requireUser();
        $rows = $pdo->query('SELECT d.*,b.name branch_name FROM departments d JOIN branches b ON b.id=d.branch_id ORDER BY b.id,d.id')->fetchAll();
        respond(['ok'=>true,'data'=>$rows]);
    }

    if ($action === 'services') {
        requireUser();
        $rows = $pdo->query('SELECT s.*,d.name department_name FROM services s JOIN departments d ON d.id=s.department_id ORDER BY d.id,s.id')->fetchAll();
        respond(['ok'=>true,'data'=>$rows]);
    }

    if ($action === 'slots') {
        requireUser();
        $date = $_GET['date'] ?? date('Y-m-d'); generateSlots($pdo, $date);
        $stmt = $pdo->prepare('SELECT s.*,d.name department_name,sv.name service_name FROM slots s JOIN departments d ON d.id=s.department_id LEFT JOIN services sv ON sv.id=s.service_id WHERE s.slot_date=? ORDER BY s.start_time,d.id');
        $stmt->execute([$date]); respond(['ok'=>true,'data'=>$stmt->fetchAll()]);
    }

    if ($action === 'update-slot') {
        requireMethod('POST'); $user = requireUser(); $in = jsonInput();
        $id = (int)($in['id'] ?? 0); $capacity = (int)($in['capacity'] ?? -1);
        $stmt = $pdo->prepare('SELECT * FROM slots WHERE id=?'); $stmt->execute([$id]); $slot = $stmt->fetch();
        if (!$slot) fail('ไม่พบ Slot',404);
        if ($capacity < (int)$slot['booked_count']) fail('Capacity ต้องไม่น้อยกว่าจำนวนที่จองแล้ว');
        $online=(int)floor($capacity*.7); $walkin=(int)floor($capacity*.2); $reserve=$capacity-$online-$walkin;
        $pdo->prepare('UPDATE slots SET capacity=?,online_capacity=?,walkin_capacity=?,reserve_capacity=? WHERE id=?')->execute([$capacity,$online,$walkin,$reserve,$id]);
        audit((int)$user['id'],'UPDATE_SLOT','slot',(string)$id,['old_capacity'=>(int)$slot['capacity'],'new_capacity'=>$capacity]);
        respond(['ok'=>true]);
    }

    if ($action === 'bookings') {
        requireUser();
        $date = $_GET['date'] ?? date('Y-m-d');
        $stmt=$pdo->prepare('SELECT b.*,s.slot_date,s.start_time,s.end_time,d.name department_name,sv.name service_name FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN departments d ON d.id=s.department_id LEFT JOIN services sv ON sv.id=s.service_id WHERE s.slot_date=? ORDER BY s.start_time,b.id');
        $stmt->execute([$date]); respond(['ok'=>true,'data'=>$stmt->fetchAll()]);
    }

    if ($action === 'create-booking') {
        requireMethod('POST'); $in=jsonInput();
        foreach(['slot_id','customer_name','customer_phone'] as $field) if(empty($in[$field])) fail("กรุณาระบุ $field");
        $idempotency = trim((string)($in['idempotency_key'] ?? '')) ?: null;
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            if ($idempotency) {
                $stmt=$pdo->prepare('SELECT * FROM bookings WHERE idempotency_key=?'); $stmt->execute([$idempotency]);
                if ($existing=$stmt->fetch()) { $pdo->exec('COMMIT'); respond(['ok'=>true,'booking'=>$existing,'duplicate'=>true]); }
            }
            $stmt=$pdo->prepare('SELECT * FROM slots WHERE id=? AND active=1'); $stmt->execute([(int)$in['slot_id']]); $slot=$stmt->fetch();
            if(!$slot) throw new DomainException('ไม่พบ Slot ที่เปิดใช้งาน');
            if((int)$slot['booked_count'] >= (int)$slot['capacity']) throw new DomainException('Slot นี้เต็มแล้ว');
            $publicId=bin2hex(random_bytes(16));
            $reference='KQ-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));
            $stmt=$pdo->prepare('INSERT INTO bookings(public_id,reference_no,slot_id,customer_name,customer_phone,source,status,idempotency_key) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$publicId,$reference,(int)$in['slot_id'],trim((string)$in['customer_name']),trim((string)$in['customer_phone']),$in['source']??'ONLINE','CONFIRMED',$idempotency]);
            $bookingId=(int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE slots SET booked_count=booked_count+1 WHERE id=?')->execute([(int)$in['slot_id']]);
            $pdo->exec('COMMIT'); audit(null,'CREATE_BOOKING','booking',(string)$bookingId,['reference'=>$reference]);
            respond(['ok'=>true,'booking'=>['id'=>$bookingId,'public_id'=>$publicId,'reference_no'=>$reference,'status'=>'CONFIRMED']],201);
        } catch(Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable) {} if($e instanceof DomainException) fail($e->getMessage(),409); throw $e; }
    }

    if ($action === 'audit') {
        requireUser();
        $rows=$pdo->query('SELECT a.*,u.display_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100')->fetchAll();
        respond(['ok'=>true,'data'=>$rows]);
    }

    fail('ไม่พบ API endpoint',404);
} catch (Throwable $e) {
    error_log($e->__toString());
    fail('เกิดข้อผิดพลาดภายในระบบ',500);
}

