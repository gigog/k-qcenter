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
        respond(['ok'=>true,'user'=>['id'=>$user['id'],'display_name'=>$user['display_name'],'role'=>$user['role']],'csrf_token'=>csrfToken()]);
    }

    if ($action === 'logout') {
        requireMethod('POST'); $user = currentUser();
        if ($user) audit((int)$user['id'],'LOGOUT','session');
        startSecureSession(); $_SESSION = []; session_destroy(); respond(['ok'=>true]);
    }

    if ($action === 'me') respond(['ok'=>true,'user'=>requireUser(),'csrf_token'=>csrfToken()]);

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
        $rows = $pdo->query('SELECT b.*,g.name building_name,g.is_24_hours building_24,COUNT(DISTINCT d.id) department_count,COALESCE(SUM(d.counter_count),0) counter_count FROM branches b LEFT JOIN buildings g ON g.id=b.building_id LEFT JOIN departments d ON d.branch_id=b.id GROUP BY b.id ORDER BY b.id')->fetchAll();
        respond(['ok'=>true,'data'=>$rows]);
    }

    if ($action === 'buildings') { requireUser();respond(['ok'=>true,'data'=>$pdo->query('SELECT * FROM buildings ORDER BY id')->fetchAll()]); }
    if ($action === 'save-building') {
        requireMethod('POST');$user=requireAdmin();requireCsrf();$in=jsonInput();$id=(int)($in['id']??0);$isEdit=$id>0;$code=strtoupper(trim((string)($in['code']??'')));$name=trim((string)($in['name']??''));$is24=!empty($in['is_24_hours'])?1:0;$open=$is24?null:(string)($in['open_time']??'');$close=$is24?null:(string)($in['close_time']??'');$active=!empty($in['active'])?1:0;
        if(!preg_match('/^[A-Z0-9_-]{2,20}$/',$code)||mb_strlen($name)<2)fail('ข้อมูลอาคารไม่ครบถ้วน');if(!$is24&&(!preg_match('/^\d{2}:\d{2}$/',$open)||!preg_match('/^\d{2}:\d{2}$/',$close)||$open>=$close))fail('เวลาเปิดอาคารไม่ถูกต้อง');
        try{if($id)$pdo->prepare('UPDATE buildings SET code=?,name=?,is_24_hours=?,open_time=?,close_time=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$code,$name,$is24,$open,$close,$active,$id]);else{$pdo->prepare('INSERT INTO buildings(code,name,is_24_hours,open_time,close_time,active) VALUES (?,?,?,?,?,?)')->execute([$code,$name,$is24,$open,$close,$active]);$id=(int)$pdo->lastInsertId();}}catch(PDOException $e){if(str_contains($e->getMessage(),'UNIQUE'))fail('รหัสอาคารนี้ถูกใช้งานแล้ว',409);throw $e;}audit((int)$user['id'],$isEdit?'UPDATE_BUILDING':'CREATE_BUILDING','building',(string)$id);respond(['ok'=>true,'id'=>$id]);
    }

    if ($action === 'save-branch') {
        requireMethod('POST'); $user=requireAdmin(); requireCsrf(); $in=jsonInput();
        $id=(int)($in['id']??0); $isEdit=$id>0; $code=strtoupper(trim((string)($in['code']??''))); $name=trim((string)($in['name']??''));$buildingId=(int)($in['building_id']??$pdo->query('SELECT id FROM buildings ORDER BY id LIMIT 1')->fetchColumn());
        $open=(string)($in['open_time']??''); $close=(string)($in['close_time']??''); $buffer=(int)($in['closing_buffer_minutes']??30); $active=!empty($in['active'])?1:0;
        if(!preg_match('/^[A-Z0-9_-]{2,20}$/',$code)) fail('รหัสสาขาไม่ถูกต้อง');
        if(mb_strlen($name)<2) fail('กรุณาระบุชื่อสาขา');
        if(!preg_match('/^\d{2}:\d{2}$/',$open)||!preg_match('/^\d{2}:\d{2}$/',$close)||$open>=$close) fail('เวลาทำการไม่ถูกต้อง');
        if($buffer<0||$buffer>180) fail('Closing buffer ต้องอยู่ระหว่าง 0–180 นาที');
        try {
            if($id){$old=$pdo->prepare('SELECT * FROM branches WHERE id=?');$old->execute([$id]);if(!$old->fetch())fail('ไม่พบสาขา',404);$pdo->prepare('UPDATE branches SET building_id=?,code=?,name=?,open_time=?,close_time=?,closing_buffer_minutes=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$buildingId,$code,$name,$open,$close,$buffer,$active,$id]);}
            else{$pdo->prepare('INSERT INTO branches(building_id,code,name,open_time,close_time,closing_buffer_minutes,active) VALUES (?,?,?,?,?,?,?)')->execute([$buildingId,$code,$name,$open,$close,$buffer,$active]);$id=(int)$pdo->lastInsertId();}
        } catch(PDOException $e){if(str_contains($e->getMessage(),'UNIQUE'))fail('รหัสสาขานี้ถูกใช้งานแล้ว',409);throw $e;}
        audit((int)$user['id'],$isEdit?'UPDATE_BRANCH':'CREATE_BRANCH','branch',(string)$id,['code'=>$code,'name'=>$name]); respond(['ok'=>true,'id'=>$id]);
    }

    if ($action === 'departments') {
        requireUser();
        $rows = $pdo->query('SELECT d.*,b.name branch_name FROM departments d JOIN branches b ON b.id=d.branch_id ORDER BY b.id,d.id')->fetchAll();
        respond(['ok'=>true,'data'=>$rows]);
    }

    if ($action === 'save-department') {
        requireMethod('POST'); $user=requireAdmin(); requireCsrf(); $in=jsonInput();
        $id=(int)($in['id']??0);$isEdit=$id>0;$branchId=(int)($in['branch_id']??0);$code=strtoupper(trim((string)($in['code']??'')));$name=trim((string)($in['name']??''));
        $counters=(int)($in['counter_count']??1);$efficiency=(float)($in['efficiency']??.8);$online=(int)($in['online_quota_percent']??70);$walkin=(int)($in['walkin_quota_percent']??20);$reserve=(int)($in['reserve_quota_percent']??10);$active=!empty($in['active'])?1:0;
        if($branchId<1||!preg_match('/^[A-Z0-9_-]{2,20}$/',$code)||mb_strlen($name)<2)fail('ข้อมูลแผนกไม่ครบถ้วน');
        if($counters<1||$counters>100||$efficiency<.1||$efficiency>1)fail('จำนวนช่องหรือประสิทธิภาพไม่ถูกต้อง');
        if($online+$walkin+$reserve!==100)fail('สัดส่วน Online, Walk-in และ Reserve ต้องรวม 100%');
        try{
            if($id){$pdo->prepare('UPDATE departments SET branch_id=?,code=?,name=?,counter_count=?,efficiency=?,online_quota_percent=?,walkin_quota_percent=?,reserve_quota_percent=?,active=? WHERE id=?')->execute([$branchId,$code,$name,$counters,$efficiency,$online,$walkin,$reserve,$active,$id]);}
            else{$pdo->prepare('INSERT INTO departments(branch_id,code,name,counter_count,efficiency,online_quota_percent,walkin_quota_percent,reserve_quota_percent,active) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$branchId,$code,$name,$counters,$efficiency,$online,$walkin,$reserve,$active]);$id=(int)$pdo->lastInsertId();}
        }catch(PDOException $e){if(str_contains($e->getMessage(),'UNIQUE'))fail('รหัสแผนกนี้ถูกใช้งานแล้ว',409);throw $e;}
        audit((int)$user['id'],$isEdit?'UPDATE_DEPARTMENT':'CREATE_DEPARTMENT','department',(string)$id,['code'=>$code,'name'=>$name]);respond(['ok'=>true,'id'=>$id]);
    }

    if ($action === 'services') {
        requireUser();
        $rows = $pdo->query('SELECT s.*,d.name department_name FROM services s JOIN departments d ON d.id=s.department_id ORDER BY d.id,s.id')->fetchAll();
        respond(['ok'=>true,'data'=>$rows]);
    }

    if ($action === 'save-service') {
        requireMethod('POST');$user=requireAdmin();requireCsrf();$in=jsonInput();
        $id=(int)($in['id']??0);$isEdit=$id>0;$departmentId=(int)($in['department_id']??0);$code=strtoupper(trim((string)($in['code']??'')));$name=trim((string)($in['name']??''));$average=(int)($in['average_minutes']??0);$prep=(int)($in['preparation_minutes']??0);$active=!empty($in['active'])?1:0;
        if($departmentId<1||!preg_match('/^[A-Z0-9_-]{2,30}$/',$code)||mb_strlen($name)<2)fail('ข้อมูลบริการไม่ครบถ้วน');
        if($average<1||$average>480||$prep<0||$prep>120)fail('ระยะเวลาบริการไม่ถูกต้อง');
        try{
            if($id){$pdo->prepare('UPDATE services SET department_id=?,code=?,name=?,average_minutes=?,preparation_minutes=?,active=? WHERE id=?')->execute([$departmentId,$code,$name,$average,$prep,$active,$id]);}
            else{$pdo->prepare('INSERT INTO services(department_id,code,name,average_minutes,preparation_minutes,active) VALUES (?,?,?,?,?,?)')->execute([$departmentId,$code,$name,$average,$prep,$active]);$id=(int)$pdo->lastInsertId();}
        }catch(PDOException $e){if(str_contains($e->getMessage(),'UNIQUE'))fail('รหัสบริการนี้ถูกใช้งานแล้ว',409);throw $e;}
        audit((int)$user['id'],$isEdit?'UPDATE_SERVICE':'CREATE_SERVICE','service',(string)$id,['code'=>$code,'name'=>$name]);respond(['ok'=>true,'id'=>$id]);
    }

    if ($action === 'kiosk-templates') {
        requireUser();
        $rows=$pdo->query('SELECT k.*,g.name building_name,(SELECT COUNT(*) FROM kiosk_menu_items m WHERE m.template_id=k.id) menu_count FROM kiosk_templates k JOIN buildings g ON g.id=k.building_id ORDER BY k.id')->fetchAll();
        respond(['ok'=>true,'data'=>$rows]);
    }

    if ($action === 'save-kiosk-template') {
        requireMethod('POST');$user=requireAdmin();requireCsrf();$in=jsonInput();$id=(int)($in['id']??0);$isEdit=$id>0;
        $buildingId=(int)($in['building_id']??0);$code=strtoupper(trim((string)($in['code']??'')));$name=trim((string)($in['name']??''));$location=trim((string)($in['location_label']??''));$active=!empty($in['active'])?1:0;
        if($buildingId<1||!preg_match('/^[A-Z0-9_-]{2,40}$/',$code)||mb_strlen($name)<2)fail('ข้อมูลจุด Kiosk ไม่ครบถ้วน');
        $exists=$pdo->prepare('SELECT 1 FROM buildings WHERE id=?');$exists->execute([$buildingId]);if(!$exists->fetchColumn())fail('ไม่พบอาคาร',404);
        try{if($id)$pdo->prepare('UPDATE kiosk_templates SET building_id=?,code=?,name=?,location_label=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$buildingId,$code,$name,$location,$active,$id]);else{$pdo->prepare('INSERT INTO kiosk_templates(building_id,code,name,location_label,active) VALUES (?,?,?,?,?)')->execute([$buildingId,$code,$name,$location,$active]);$id=(int)$pdo->lastInsertId();}}catch(PDOException $e){if(str_contains($e->getMessage(),'UNIQUE'))fail('รหัส Kiosk นี้ถูกใช้งานแล้ว',409);throw $e;}
        audit((int)$user['id'],$isEdit?'UPDATE_KIOSK_TEMPLATE':'CREATE_KIOSK_TEMPLATE','kiosk_template',(string)$id,['code'=>$code]);respond(['ok'=>true,'id'=>$id]);
    }

    if ($action === 'kiosk-menu-items') {
        requireUser();$templateId=(int)($_GET['template_id']??0);$stmt=$pdo->prepare('SELECT m.*,d.name department_name,s.name service_name FROM kiosk_menu_items m LEFT JOIN departments d ON d.id=m.department_id LEFT JOIN services s ON s.id=m.service_id WHERE m.template_id=? ORDER BY COALESCE(m.parent_id,0),m.sort_order,m.id');$stmt->execute([$templateId]);respond(['ok'=>true,'data'=>$stmt->fetchAll()]);
    }

    if ($action === 'save-kiosk-menu-item') {
        requireMethod('POST');$user=requireAdmin();requireCsrf();$in=jsonInput();$id=(int)($in['id']??0);$isEdit=$id>0;$templateId=(int)($in['template_id']??0);$parentId=(int)($in['parent_id']??0)?:null;$departmentId=(int)($in['department_id']??0)?:null;$serviceId=(int)($in['service_id']??0)?:null;
        $label=trim((string)($in['label']??''));$subtitle=trim((string)($in['subtitle']??''));$icon=mb_substr(trim((string)($in['icon']??'Q')),0,4);$order=max(0,(int)($in['sort_order']??0));$start=trim((string)($in['start_time']??''))?:null;$end=trim((string)($in['end_time']??''))?:null;$active=!empty($in['active'])?1:0;
        if($templateId<1||mb_strlen($label)<2)fail('กรุณาระบุชื่อปุ่มและ Kiosk');if($id&&$parentId===$id)fail('ปุ่มไม่สามารถเป็นเมนูย่อยของตัวเอง');
        if(($start===null)!==($end===null)||($start!==null&&(!preg_match('/^\d{2}:\d{2}$/',$start)||!preg_match('/^\d{2}:\d{2}$/',$end))))fail('ช่วงเวลาแสดงปุ่มไม่ถูกต้อง');
        if($parentId){$check=$pdo->prepare('SELECT 1 FROM kiosk_menu_items WHERE id=? AND template_id=? AND parent_id IS NULL');$check->execute([$parentId,$templateId]);if(!$check->fetchColumn())fail('เมนูหลักไม่ถูกต้อง');}
        if($serviceId){$check=$pdo->prepare('SELECT department_id FROM services WHERE id=?');$check->execute([$serviceId]);$serviceDepartment=(int)$check->fetchColumn();if(!$serviceDepartment)fail('ไม่พบบริการ',404);if($departmentId&&$departmentId!==$serviceDepartment)fail('บริการไม่อยู่ในแผนกที่เลือก');$departmentId=$serviceDepartment;}
        try{if($id)$pdo->prepare('UPDATE kiosk_menu_items SET template_id=?,parent_id=?,department_id=?,service_id=?,label=?,subtitle=?,icon=?,sort_order=?,start_time=?,end_time=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$templateId,$parentId,$departmentId,$serviceId,$label,$subtitle,$icon?:'Q',$order,$start,$end,$active,$id]);else{$pdo->prepare('INSERT INTO kiosk_menu_items(template_id,parent_id,department_id,service_id,label,subtitle,icon,sort_order,start_time,end_time,active) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$templateId,$parentId,$departmentId,$serviceId,$label,$subtitle,$icon?:'Q',$order,$start,$end,$active]);$id=(int)$pdo->lastInsertId();}}catch(PDOException $e){throw $e;}
        audit((int)$user['id'],$isEdit?'UPDATE_KIOSK_MENU':'CREATE_KIOSK_MENU','kiosk_menu_item',(string)$id,['template_id'=>$templateId,'label'=>$label]);respond(['ok'=>true,'id'=>$id]);
    }

    if ($action === 'public-kiosk-config') {
        $code=strtoupper(trim((string)($_GET['code']??'MAIN')));if(!preg_match('/^[A-Z0-9_-]{2,40}$/',$code))fail('รหัส Kiosk ไม่ถูกต้อง');
        $stmt=$pdo->prepare('SELECT k.id,k.code,k.name,k.location_label,k.building_id,g.name building_name FROM kiosk_templates k JOIN buildings g ON g.id=k.building_id WHERE k.code=? AND k.active=1 AND g.active=1');$stmt->execute([$code]);$template=$stmt->fetch();if(!$template)fail('ไม่พบจุด Kiosk หรือยังไม่ได้เปิดใช้งาน',404);
        $stmt=$pdo->prepare('SELECT m.id,m.parent_id,m.department_id,m.service_id,m.label,m.subtitle,m.icon,m.sort_order,m.start_time,m.end_time,d.name department_name,s.name service_name FROM kiosk_menu_items m LEFT JOIN departments d ON d.id=m.department_id LEFT JOIN branches b ON b.id=d.branch_id LEFT JOIN services s ON s.id=m.service_id WHERE m.template_id=? AND m.active=1 AND (d.id IS NULL OR (d.active=1 AND b.active=1 AND b.building_id=?)) AND (s.id IS NULL OR s.active=1) ORDER BY COALESCE(m.parent_id,0),m.sort_order,m.id');$stmt->execute([$template['id'],$template['building_id']]);$now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format('H:i');
        $items=array_values(array_filter($stmt->fetchAll(),function($item)use($now){if(!$item['start_time']||!$item['end_time'])return true;$start=substr((string)$item['start_time'],0,5);$end=substr((string)$item['end_time'],0,5);return $start<$end?($now>=$start&&$now<$end):($now>=$start||$now<$end);}));
        $visibleIds=array_fill_keys(array_map(fn($item)=>(int)$item['id'],$items),true);$items=array_values(array_filter($items,fn($item)=>!$item['parent_id']||isset($visibleIds[(int)$item['parent_id']])));respond(['ok'=>true,'template'=>$template,'data'=>$items,'server_time'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format(DATE_ATOM)]);
    }

    if ($action === 'calendars') {
        requireUser();$rows=$pdo->query("SELECT o.*,CASE WHEN o.scope_type='BRANCH' THEN b.name ELSE d.name END scope_name,b2.name branch_name FROM schedule_overrides o LEFT JOIN branches b ON o.scope_type='BRANCH' AND b.id=o.scope_id LEFT JOIN departments d ON o.scope_type='DEPARTMENT' AND d.id=o.scope_id LEFT JOIN branches b2 ON b2.id=d.branch_id ORDER BY o.override_date DESC,o.id DESC")->fetchAll();respond(['ok'=>true,'data'=>$rows]);
    }

    if ($action === 'save-calendar') {
        requireMethod('POST');$user=requireAdmin();requireCsrf();$in=jsonInput();
        $id=(int)($in['id']??0);$isEdit=$id>0;$scopeType=strtoupper((string)($in['scope_type']??'BRANCH'));$scopeId=(int)($in['scope_id']??0);$date=(string)($in['override_date']??'');$name=trim((string)($in['name']??''));$closed=!empty($in['is_closed'])?1:0;$open=$closed?null:(string)($in['open_time']??'');$close=$closed?null:(string)($in['close_time']??'');$counters=isset($in['counter_count'])&&$in['counter_count']!==''?(int)$in['counter_count']:null;$note=trim((string)($in['note']??''));$active=!empty($in['active'])?1:0;
        if(!in_array($scopeType,['BRANCH','DEPARTMENT'],true)||$scopeId<1||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||mb_strlen($name)<2)fail('ข้อมูลปฏิทินไม่ครบถ้วน');
        if(!$closed&&(!preg_match('/^\d{2}:\d{2}$/',$open)||!preg_match('/^\d{2}:\d{2}$/',$close)||$open>=$close))fail('เวลาทำการพิเศษไม่ถูกต้อง');
        try{
            if($id){$pdo->prepare('UPDATE schedule_overrides SET scope_type=?,scope_id=?,override_date=?,name=?,is_closed=?,open_time=?,close_time=?,counter_count=?,note=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$scopeType,$scopeId,$date,$name,$closed,$open,$close,$counters,$note,$active,$id]);}
            else{$pdo->prepare('INSERT INTO schedule_overrides(scope_type,scope_id,override_date,name,is_closed,open_time,close_time,counter_count,note,active) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$scopeType,$scopeId,$date,$name,$closed,$open,$close,$counters,$note,$active]);$id=(int)$pdo->lastInsertId();}
        }catch(PDOException $e){if(str_contains($e->getMessage(),'UNIQUE'))fail('มีการตั้งค่าสาขาและวันที่นี้แล้ว',409);throw $e;}
        audit((int)$user['id'],$isEdit?'UPDATE_CALENDAR':'CREATE_CALENDAR','calendar_override',(string)$id,['date'=>$date,'name'=>$name]);respond(['ok'=>true,'id'=>$id]);
    }

    if ($action === 'weekly-hours') { requireUser();$type=strtoupper((string)($_GET['scope_type']??''));$id=(int)($_GET['scope_id']??0);$stmt=$pdo->prepare('SELECT * FROM weekly_hours WHERE scope_type=? AND scope_id=? ORDER BY weekday,interval_order');$stmt->execute([$type,$id]);respond(['ok'=>true,'data'=>$stmt->fetchAll()]); }
    if ($action === 'save-weekly-hours') {
        requireMethod('POST');$user=requireAdmin();requireCsrf();$in=jsonInput();$type=strtoupper((string)($in['scope_type']??''));$scopeId=(int)($in['scope_id']??0);$items=$in['intervals']??[];if(!in_array($type,['BRANCH','DEPARTMENT'],true)||$scopeId<1||!is_array($items))fail('ข้อมูลตารางเวลาไม่ถูกต้อง');
        $clean=[];foreach($items as $item){$day=(int)($item['weekday']??0);$open=(string)($item['open_time']??'');$close=(string)($item['close_time']??'');if($day<1||$day>7||!preg_match('/^\d{2}:\d{2}$/',$open)||!preg_match('/^\d{2}:\d{2}$/',$close)||$open>=$close)fail('ช่วงเวลาไม่ถูกต้อง');$clean[$day][]=[$open,$close];}
        foreach($clean as $day=>$ranges){usort($ranges,fn($a,$b)=>strcmp($a[0],$b[0]));for($i=1;$i<count($ranges);$i++)if($ranges[$i][0]<$ranges[$i-1][1])fail('ช่วงเวลาในวันเดียวกันซ้อนกัน');}
        $pdo->beginTransaction();try{$pdo->prepare('DELETE FROM weekly_hours WHERE scope_type=? AND scope_id=?')->execute([$type,$scopeId]);upsertWeeklyScheduleConfig($pdo,$type,$scopeId);$stmt=$pdo->prepare('INSERT INTO weekly_hours(scope_type,scope_id,weekday,interval_order,open_time,close_time) VALUES (?,?,?,?,?,?)');foreach($clean as $day=>$ranges)foreach($ranges as $index=>$range)$stmt->execute([$type,$scopeId,$day,$index+1,$range[0],$range[1]]);$pdo->commit();}catch(Throwable $e){$pdo->rollBack();throw $e;}audit((int)$user['id'],'UPDATE_WEEKLY_HOURS',strtolower($type),(string)$scopeId,['interval_count'=>count($items)]);respond(['ok'=>true]);
    }

    if ($action === 'slots') {
        requireUser();
        $date = $_GET['date'] ?? date('Y-m-d'); generateSlots($pdo, $date);
        $stmt = $pdo->prepare('SELECT s.*,d.name department_name,sv.name service_name FROM slots s JOIN departments d ON d.id=s.department_id LEFT JOIN services sv ON sv.id=s.service_id WHERE s.slot_date=? ORDER BY s.start_time,d.id');
        $stmt->execute([$date]); respond(['ok'=>true,'data'=>$stmt->fetchAll()]);
    }

    if ($action === 'update-slot') {
        requireMethod('POST'); $user = requireAdmin(); requireCsrf(); $in = jsonInput();
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
        beginCapacityTransaction($pdo);
        try {
            if ($idempotency) {
                $stmt=$pdo->prepare('SELECT * FROM bookings WHERE idempotency_key=?'); $stmt->execute([$idempotency]);
                if ($existing=$stmt->fetch()) { $pdo->exec('COMMIT'); respond(['ok'=>true,'booking'=>$existing,'duplicate'=>true]); }
            }
            $slot=selectSlotForUpdate($pdo,(int)$in['slot_id']);
            if(!$slot) throw new DomainException('ไม่พบ Slot ที่เปิดใช้งาน');
            if((int)$slot['booked_count'] >= (int)$slot['capacity']) throw new DomainException('Slot นี้เต็มแล้ว');
            $source=strtoupper(trim((string)($in['source']??'ONLINE')));if($source!=='ONLINE')throw new DomainException('ช่องทางการจองไม่ถูกต้อง');
            $used=$pdo->prepare("SELECT COUNT(*) FROM bookings WHERE slot_id=? AND source='ONLINE' AND status NOT IN ('CANCELLED','EXPIRED')");$used->execute([$slot['id']]);if((int)$used->fetchColumn()>=(int)$slot['online_capacity'])throw new DomainException('โควตาจองออนไลน์ของ Slot นี้เต็มแล้ว');
            $publicId=newPublicId();
            $reference='KQ-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));
            $citizenHash=!empty($in['citizen_id'])?identityHash((string)$in['citizen_id']):null;
            $stmt=$pdo->prepare('INSERT INTO bookings(public_id,reference_no,slot_id,customer_name,customer_phone,source,status,idempotency_key,citizen_id_hash) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$publicId,$reference,(int)$in['slot_id'],trim((string)$in['customer_name']),trim((string)$in['customer_phone']),$source,'CONFIRMED',$idempotency,$citizenHash]);
            $bookingId=(int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE slots SET booked_count=booked_count+1 WHERE id=?')->execute([(int)$in['slot_id']]);
            $pdo->exec('COMMIT'); audit(null,'CREATE_BOOKING','booking',(string)$bookingId,['reference'=>$reference]);
            respond(['ok'=>true,'booking'=>['id'=>$bookingId,'public_id'=>$publicId,'reference_no'=>$reference,'status'=>'CONFIRMED']],201);
        } catch(Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable) {} if($e instanceof DomainException) fail($e->getMessage(),409); throw $e; }
    }

    if ($action === 'queue-status') {
        $publicId=trim((string)($_GET['token']??''));
        if(!preg_match('/^[a-f0-9]{32}$/',$publicId))fail('QR หรือรหัสติดตามไม่ถูกต้อง',404);
        $stmt=$pdo->prepare("SELECT q.*,b.public_id booking_public_id,b.reference_no,b.customer_name,b.status booking_status,s.slot_date,s.start_time,s.end_time,d.name department_name,sv.name service_name,br.name branch_name FROM queue_tickets q JOIN bookings b ON b.id=q.booking_id JOIN slots s ON s.id=b.slot_id JOIN departments d ON d.id=s.department_id JOIN branches br ON br.id=d.branch_id LEFT JOIN services sv ON sv.id=s.service_id WHERE q.public_id=? OR b.public_id=? LIMIT 1");
        $stmt->execute([$publicId,$publicId]);$row=$stmt->fetch();
        if(!$row){
            $stmt=$pdo->prepare("SELECT b.public_id booking_public_id,b.reference_no,b.customer_name,b.status booking_status,s.slot_date,s.start_time,s.end_time,d.name department_name,sv.name service_name,br.name branch_name FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN departments d ON d.id=s.department_id JOIN branches br ON br.id=d.branch_id LEFT JOIN services sv ON sv.id=s.service_id WHERE b.public_id=? LIMIT 1");$stmt->execute([$publicId]);$booking=$stmt->fetch();
            if(!$booking)fail('ไม่พบคิว หรือ QR หมดอายุแล้ว',404);
            respond(['ok'=>true,'data'=>['token'=>$booking['booking_public_id'],'reference_no'=>$booking['reference_no'],'queue_no'=>null,'customer_display'=>maskThaiName($booking['customer_name']),'status'=>$booking['booking_status'],'current_step'=>'BOOKED','branch_name'=>$booking['branch_name'],'department_name'=>$booking['department_name'],'service_name'=>$booking['service_name'],'slot_date'=>$booking['slot_date'],'start_time'=>$booking['start_time'],'end_time'=>$booking['end_time'],'estimated_wait_minutes'=>null,'events'=>[['event_type'=>'BOOKED','status'=>'CONFIRMED','step'=>'BOOKED','note'=>'ยืนยันการจองแล้ว รอเช็กอิน','created_at'=>null]]]]);
        }
        $events=$pdo->prepare('SELECT event_type,status,step,note,created_at FROM queue_events WHERE queue_ticket_id=? ORDER BY id');$events->execute([$row['id']]);
        respond(['ok'=>true,'data'=>['token'=>$row['public_id'],'reference_no'=>$row['reference_no'],'queue_no'=>$row['queue_no'],'customer_display'=>maskThaiName($row['customer_name']),'status'=>$row['status'],'current_step'=>$row['current_step'],'branch_name'=>$row['branch_name'],'department_name'=>$row['department_name'],'service_name'=>$row['service_name'],'slot_date'=>$row['slot_date'],'start_time'=>$row['start_time'],'end_time'=>$row['end_time'],'estimated_wait_minutes'=>$row['estimated_wait_minutes'],'checked_in_at'=>$row['checked_in_at'],'registration_started_at'=>$row['registration_started_at'],'visit_opened_at'=>$row['visit_opened_at'],'service_started_at'=>$row['service_started_at'],'service_completed_at'=>$row['service_completed_at'],'events'=>$events->fetchAll()]]);
    }

    if ($action === 'kiosk-check-in') {
        requireMethod('POST');$in=jsonInput();$token=trim((string)($in['token']??''));
        if(!preg_match('/^[a-f0-9]{32}$/',$token))fail('QR หรือรหัสยืนยันไม่ถูกต้อง');
        beginCapacityTransaction($pdo);
        try{$stmt=$pdo->prepare('SELECT * FROM bookings WHERE public_id=?');$stmt->execute([$token]);$booking=$stmt->fetch();if(!$booking)throw new DomainException('ไม่พบรายการจอง');if(in_array($booking['status'],['CANCELLED','EXPIRED'],true))throw new DomainException('รายการจองนี้ไม่สามารถเช็กอินได้');$ticket=createQueueTicket($pdo,$booking);$pdo->exec('COMMIT');audit(null,'KIOSK_CHECK_IN','queue_ticket',(string)$ticket['id'],['queue_no'=>$ticket['queue_no']]);respond(['ok'=>true,'ticket'=>$ticket]);}catch(Throwable $e){try{$pdo->exec('ROLLBACK');}catch(Throwable){}if($e instanceof DomainException)fail($e->getMessage(),409);throw $e;}
    }

    if ($action === 'kiosk-lookup') {
        requireMethod('POST');$in=jsonInput();$method=(string)($in['method']??'');$value=trim((string)($in['value']??''));
        if($value==='')fail('กรุณาระบุข้อมูลสำหรับค้นหา');
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
        $base="SELECT b.public_id,b.reference_no,b.status,b.customer_name,s.slot_date,s.start_time,s.end_time,d.name department_name,sv.name service_name,br.name branch_name FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN departments d ON d.id=s.department_id JOIN branches br ON br.id=d.branch_id LEFT JOIN services sv ON sv.id=s.service_id WHERE s.slot_date=? AND b.status NOT IN ('CANCELLED','EXPIRED')";
        if($method==='code'){$stmt=$pdo->prepare($base.' AND (b.reference_no=? OR b.public_id=?) ORDER BY s.start_time LIMIT 5');$stmt->execute([$today,$value,$value]);}
        elseif($method==='phone'){$phone=normalizePhone($value);if(strlen($phone)<9)fail('หมายเลขโทรศัพท์ไม่ถูกต้อง');$stmt=$pdo->prepare($base." AND REPLACE(REPLACE(REPLACE(b.customer_phone,'-',''),' ',''),'+66','0')=? ORDER BY s.start_time LIMIT 5");$stmt->execute([$today,$phone]);}
        elseif($method==='citizen'){$hash=identityHash($value);$stmt=$pdo->prepare($base.' AND b.citizen_id_hash=? ORDER BY s.start_time LIMIT 5');$stmt->execute([$today,$hash]);}
        else fail('วิธีค้นหาไม่ถูกต้อง');
        $rows=array_map(function($row){$row['customer_display']=maskThaiName((string)$row['customer_name']);unset($row['customer_name']);return $row;},$stmt->fetchAll());
        respond(['ok'=>true,'data'=>$rows]);
    }

    if ($action === 'kiosk-walk-in') {
        requireMethod('POST');$in=jsonInput();foreach(['slot_id','customer_name'] as $field)if(empty($in[$field]))fail("กรุณาระบุ $field");$idempotency=trim((string)($in['idempotency_key']??''))?:null;$citizenHash=!empty($in['citizen_id'])?identityHash((string)$in['citizen_id']):null;
        beginCapacityTransaction($pdo);
        try{if($idempotency){$existing=$pdo->prepare('SELECT * FROM bookings WHERE idempotency_key=?');$existing->execute([$idempotency]);if($booking=$existing->fetch()){$ticket=createQueueTicket($pdo,$booking);$pdo->exec('COMMIT');respond(['ok'=>true,'ticket'=>$ticket,'booking_public_id'=>$booking['public_id'],'duplicate'=>true]);}}$slot=selectSlotForUpdate($pdo,(int)$in['slot_id']);if(!$slot)throw new DomainException('ไม่พบ Slot ที่เปิดใช้งาน');$used=$pdo->prepare("SELECT COUNT(*) FROM bookings WHERE slot_id=? AND source='WALK_IN' AND status NOT IN ('CANCELLED','EXPIRED')");$used->execute([$slot['id']]);if((int)$used->fetchColumn()>=(int)$slot['walkin_capacity']+(int)$slot['reserve_capacity'])throw new DomainException('โควตา Walk-in ของ Slot นี้เต็มแล้ว');if((int)$slot['booked_count']>=(int)$slot['capacity'])throw new DomainException('Slot นี้เต็มแล้ว');$publicId=newPublicId();$reference='KQ-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));$stmt=$pdo->prepare("INSERT INTO bookings(public_id,reference_no,slot_id,customer_name,customer_phone,source,status,idempotency_key,citizen_id_hash) VALUES (?,?,?,?,?,'WALK_IN','CONFIRMED',?,?)");$stmt->execute([$publicId,$reference,$slot['id'],trim((string)$in['customer_name']),trim((string)($in['customer_phone']??'')),$idempotency,$citizenHash]);$bookingId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE slots SET booked_count=booked_count+1 WHERE id=?')->execute([$slot['id']]);$stmt=$pdo->prepare('SELECT * FROM bookings WHERE id=?');$stmt->execute([$bookingId]);$ticket=createQueueTicket($pdo,$stmt->fetch());$pdo->exec('COMMIT');audit(null,'CREATE_WALK_IN','queue_ticket',(string)$ticket['id'],['queue_no'=>$ticket['queue_no']]);respond(['ok'=>true,'ticket'=>$ticket,'booking_public_id'=>$publicId],201);}catch(Throwable $e){try{$pdo->exec('ROLLBACK');}catch(Throwable){}if($e instanceof DomainException)fail($e->getMessage(),409);throw $e;}
    }

    if ($action === 'public-slots') {
        $date=$_GET['date']??date('Y-m-d');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))fail('วันที่ไม่ถูกต้อง');generateSlots($pdo,$date);
        $where=['s.slot_date=?','s.active=1','s.booked_count<s.capacity'];$params=[$date];$departmentId=(int)($_GET['department_id']??0);$serviceId=(int)($_GET['service_id']??0);$kioskCode=strtoupper(trim((string)($_GET['kiosk']??'')));
        if($departmentId){$where[]='d.id=?';$params[]=$departmentId;}if($serviceId){$where[]='sv.id=?';$params[]=$serviceId;}if($kioskCode!==''){$where[]='b.building_id=(SELECT building_id FROM kiosk_templates WHERE code=? AND active=1)';$params[]=$kioskCode;}
        $stmt=$pdo->prepare("SELECT s.id,s.slot_date,s.start_time,s.end_time,s.capacity,s.booked_count,s.online_capacity,s.walkin_capacity,s.reserve_capacity,d.name department_name,sv.name service_name,b.name branch_name FROM slots s JOIN departments d ON d.id=s.department_id JOIN branches b ON b.id=d.branch_id LEFT JOIN services sv ON sv.id=s.service_id WHERE ".implode(' AND ',$where).' ORDER BY s.start_time,d.id');$stmt->execute($params);respond(['ok'=>true,'data'=>$stmt->fetchAll()]);
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

