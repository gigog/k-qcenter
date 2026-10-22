<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

try {
    $pdo = db();
    $action = $_GET['action'] ?? 'health';

    if ($action === 'health') {
        respond(['ok'=>true,'service'=>'K-QCENTER API','database'=>'connected','time'=>(new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok')))->format(DATE_ATOM)]);
    }

    if ($action === 'public-ticket-template') {
        respond(['ok'=>true,'data'=>ticketTemplate($pdo)]);
    }

    if ($action === 'ticket-template-settings') {
        $user=requireAdmin();
        if($_SERVER['REQUEST_METHOD']==='GET')respond(['ok'=>true,'data'=>ticketTemplate($pdo),'defaults'=>ticketTemplateDefaults()]);
        requireMethod('POST');requireCsrf();$template=cleanTicketTemplate(jsonInput());$json=json_encode($template,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){$stmt=$pdo->prepare("INSERT INTO system_settings(setting_key,setting_value,updated_by) VALUES ('ticket_template',?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP");}
        else{$stmt=$pdo->prepare("INSERT INTO system_settings(setting_key,setting_value,updated_by) VALUES ('ticket_template',?,?) ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,updated_by=excluded.updated_by,updated_at=CURRENT_TIMESTAMP");}
        $stmt->execute([$json,(int)$user['id']]);audit((int)$user['id'],'UPDATE_TICKET_TEMPLATE','system_setting','ticket_template',$template);respond(['ok'=>true,'data'=>$template]);
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

    if ($action === 'tts') {
        requireMethod('POST');requireUser();requireCsrf();$in=jsonInput();
        $text=trim((string)($in['text']??''));$rate=max(.5,min(1.5,(float)($in['rate']??.9)));
        if($text===''||mb_strlen($text)>300)fail('ข้อความเสียงต้องมีความยาว 1–300 ตัวอักษร');
        $python=getenv('KQCENTER_TTS_PYTHON')?:'/opt/kqcenter-tts/runtime/bin/python';$model=getenv('KQCENTER_TTS_MODEL')?:'/opt/kqcenter-tts/models/th_TH-tsync2-medium.onnx';
        if(!is_executable($python)||!is_readable($model))fail('บริการเสียง Offline ยังไม่พร้อมใช้งาน',503,['code'=>'TTS_UNAVAILABLE']);
        $cacheDir=KQ_DATA.'/tts-cache';if(!is_dir($cacheDir)&&!mkdir($cacheDir,0770,true)&&!is_dir($cacheDir))fail('ไม่สามารถเตรียมพื้นที่เก็บเสียงได้',500);
        $file=$cacheDir.'/'.hash('sha256','v2|'.$text.'|'.number_format($rate,1,'.','')).'.wav';
        if(!is_file($file)){
            $tmp=$file.'.'.bin2hex(random_bytes(6)).'.tmp.wav';$lengthScale=.82/max(.5,$rate);$stderr='';$stdout='';$exit=1;
            $serviceUrl=getenv('KQCENTER_TTS_URL')?:'http://127.0.0.1:59125/synthesize';
            if(function_exists('curl_init')){$curl=curl_init($serviceUrl);curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['text'=>$text,'length_scale'=>$lengthScale],JSON_UNESCAPED_UNICODE),CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>1,CURLOPT_TIMEOUT=>15]);$audio=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$stderr=(string)curl_error($curl);curl_close($curl);if($status===200&&is_string($audio)&&strlen($audio)>=44&&file_put_contents($tmp,$audio,LOCK_EX)!==false)$exit=0;}
            if($exit!==0){$command=[$python,'-m','piper','-m',$model,'-f',$tmp,'--length-scale',(string)$lengthScale,'--',$text];$pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['HOME'=>'/tmp','PATH'=>'/usr/bin:/bin']);if(is_resource($process)){fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);$stderr.=stream_get_contents($pipes[2]);fclose($pipes[2]);$exit=proc_close($process);}}
            if($exit!==0||!is_file($tmp)||filesize($tmp)<44){@unlink($tmp);error_log('Piper TTS failed: '.$stderr.$stdout);fail('สร้างเสียงไม่สำเร็จ ระบบจะใช้เสียงสำรอง',503,['code'=>'TTS_GENERATION_FAILED']);}
            if(!rename($tmp,$file)){@unlink($tmp);fail('ไม่สามารถบันทึกเสียงได้',500);}@chmod($file,0660);
        }
        header('Content-Type: audio/wav');header('Content-Length: '.filesize($file));header('Cache-Control: private, max-age=86400');readfile($file);exit;
    }

    if ($action === 'users') {
        requireAdmin();
        $rows=$pdo->query("SELECT u.id,u.username,u.display_name,u.role,u.scope_type,u.scope_id,u.active,u.created_at,u.last_login_at,CASE WHEN u.scope_type='BRANCH' THEN (SELECT name FROM branches WHERE id=u.scope_id) WHEN u.scope_type='DEPARTMENT' THEN (SELECT name FROM departments WHERE id=u.scope_id) ELSE 'ทุกสาขาและแผนก' END scope_name FROM users u ORDER BY u.active DESC,u.display_name,u.id")->fetchAll();
        respond(['ok'=>true,'data'=>$rows]);
    }

    if ($action === 'save-user') {
        requireMethod('POST');$admin=requireAdmin();requireCsrf();$in=jsonInput();$id=(int)($in['id']??0);$isEdit=$id>0;
        $username=trim((string)($in['username']??''));$displayName=trim((string)($in['display_name']??''));$password=(string)($in['password']??'');$role=strtolower(trim((string)($in['role']??'')));$scopeType=strtoupper(trim((string)($in['scope_type']??'ALL')));$scopeId=(int)($in['scope_id']??0)?:null;$active=!empty($in['active'])?1:0;
        $roles=['admin','kiosk_admin','kiosk_manager','counter','viewer'];if(!preg_match('/^[A-Za-z0-9._-]{3,40}$/',$username)||mb_strlen($displayName)<2)fail('ชื่อผู้ใช้หรือชื่อแสดงผลไม่ถูกต้อง');if(!in_array($role,$roles,true))fail('บทบาทไม่ถูกต้อง');if(!in_array($scopeType,['ALL','BRANCH','DEPARTMENT'],true))fail('ขอบเขตสิทธิ์ไม่ถูกต้อง');
        if($scopeType==='ALL')$scopeId=null;else{if(!$scopeId)fail('กรุณาเลือกขอบเขตสาขาหรือแผนก');$table=$scopeType==='BRANCH'?'branches':'departments';$check=$pdo->prepare("SELECT 1 FROM $table WHERE id=?");$check->execute([$scopeId]);if(!$check->fetchColumn())fail('ไม่พบขอบเขตที่เลือก',404);}
        if(!$isEdit&&strlen($password)<10)fail('รหัสผ่านต้องมีอย่างน้อย 10 ตัวอักษร');if($password!==''&&strlen($password)<10)fail('รหัสผ่านใหม่ต้องมีอย่างน้อย 10 ตัวอักษร');
        if($id===(int)$admin['id']&&(!$active||$role!=='admin'))fail('ไม่สามารถปิดบัญชีหรือลดสิทธิ์บัญชีที่กำลังใช้งาน');
        try{if($isEdit){$check=$pdo->prepare('SELECT id FROM users WHERE id=?');$check->execute([$id]);if(!$check->fetchColumn())fail('ไม่พบบัญชีผู้ใช้',404);if($password!=='')$pdo->prepare('UPDATE users SET username=?,display_name=?,password_hash=?,role=?,scope_type=?,scope_id=?,active=? WHERE id=?')->execute([$username,$displayName,password_hash($password,PASSWORD_DEFAULT),$role,$scopeType,$scopeId,$active,$id]);else $pdo->prepare('UPDATE users SET username=?,display_name=?,role=?,scope_type=?,scope_id=?,active=? WHERE id=?')->execute([$username,$displayName,$role,$scopeType,$scopeId,$active,$id]);}else{$pdo->prepare('INSERT INTO users(username,display_name,password_hash,role,scope_type,scope_id,active) VALUES (?,?,?,?,?,?,?)')->execute([$username,$displayName,password_hash($password,PASSWORD_DEFAULT),$role,$scopeType,$scopeId,$active]);$id=(int)$pdo->lastInsertId();}}catch(PDOException $e){if(str_contains($e->getMessage(),'UNIQUE'))fail('ชื่อผู้ใช้นี้ถูกใช้งานแล้ว',409);throw $e;}
        audit((int)$admin['id'],$isEdit?'UPDATE_USER':'CREATE_USER','user',(string)$id,['username'=>$username,'role'=>$role,'scope_type'=>$scopeType,'scope_id'=>$scopeId,'active'=>$active,'password_changed'=>$password!=='']);respond(['ok'=>true,'id'=>$id]);
    }

    if ($action === 'dashboard') {
        $user=requireUser();[$scopeSql,$scopeParams]=userScopeClause($user,'d');
        $date = $_GET['date'] ?? (new DateTimeImmutable('today', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
        generateSlots($pdo, $date);
        $stmt = $pdo->prepare("SELECT COUNT(*) total, SUM(CASE WHEN b.status='COMPLETED' THEN 1 ELSE 0 END) completed, SUM(CASE WHEN b.status IN ('CONFIRMED','CHECKED_IN','WAITING','CALLED','SERVING') THEN 1 ELSE 0 END) active FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN departments d ON d.id=s.department_id WHERE s.slot_date=? AND $scopeSql");
        $stmt->execute(array_merge([$date],$scopeParams)); $summary = $stmt->fetch();
        $stmt = $pdo->prepare("SELECT d.id,d.name,d.counter_count,d.efficiency,COALESCE(SUM(s.capacity),0) capacity,COALESCE(SUM(s.booked_count),0) booked FROM departments d LEFT JOIN slots s ON s.department_id=d.id AND s.slot_date=? WHERE d.active=1 AND $scopeSql GROUP BY d.id ORDER BY d.id");
        $stmt->execute(array_merge([$date],$scopeParams)); $departments = $stmt->fetchAll();
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(s.capacity),0) capacity,COALESCE(SUM(s.booked_count),0) booked FROM slots s JOIN departments d ON d.id=s.department_id WHERE s.slot_date=? AND s.active=1 AND $scopeSql");
        $stmt->execute(array_merge([$date],$scopeParams)); $cap = $stmt->fetch();
        respond(['ok'=>true,'date'=>$date,'summary'=>['total'=>(int)($summary['total']??0),'completed'=>(int)($summary['completed']??0),'active'=>(int)($summary['active']??0),'capacity'=>(int)$cap['capacity'],'booked'=>(int)$cap['booked']],'departments'=>$departments]);
    }

    if ($action === 'branches') {
        $user=requireUser();$scopeType=strtoupper((string)($user['scope_type']??'ALL'));$scopeId=(int)($user['scope_id']??0);$where='1=1';$params=[];if($scopeType==='BRANCH'&&$scopeId){$where='b.id=?';$params=[$scopeId];}elseif($scopeType==='DEPARTMENT'&&$scopeId){$where='b.id=(SELECT branch_id FROM departments WHERE id=?)';$params=[$scopeId];}$stmt=$pdo->prepare("SELECT b.*,g.name building_name,g.is_24_hours building_24,COUNT(DISTINCT d.id) department_count,COALESCE(SUM(d.counter_count),0) counter_count FROM branches b LEFT JOIN buildings g ON g.id=b.building_id LEFT JOIN departments d ON d.branch_id=b.id WHERE $where GROUP BY b.id ORDER BY b.id");$stmt->execute($params);$rows=$stmt->fetchAll();
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
        $user=requireUser();[$scopeSql,$scopeParams]=userScopeClause($user,'d');$stmt=$pdo->prepare("SELECT d.*,b.name branch_name FROM departments d JOIN branches b ON b.id=d.branch_id WHERE $scopeSql ORDER BY b.id,d.id");$stmt->execute($scopeParams);$rows=$stmt->fetchAll();
        respond(['ok'=>true,'data'=>$rows]);
    }

    if ($action === 'save-department-slot-rules') {
        requireMethod('POST');$user=requireAdmin();requireCsrf();$in=jsonInput();$departmentId=(int)($in['department_id']??0);$enabled=!empty($in['enabled']);$rules=$in['rules']??[];
        if($departmentId<1||!is_array($rules)||count($rules)>30)fail('ข้อมูลกฎ Slot ไม่ถูกต้อง');
        $department=$pdo->prepare('SELECT id,branch_id FROM departments WHERE id=?');$department->execute([$departmentId]);$department=$department->fetch();if(!$department)fail('ไม่พบแผนก',404);if(!userCanAccessDepartment($user,$departmentId,(int)$department['branch_id']))fail('ไม่มีสิทธิ์จัดการแผนกนี้',403);
        $cleanRules=[];foreach($rules as $ruleIndex=>$rule){$name=trim((string)($rule['name']??''));$weekdays=array_values(array_unique(array_filter(array_map('intval',(array)($rule['weekdays']??[])),fn($v)=>$v>=1&&$v<=7)));$weeks=array_values(array_unique(array_filter(array_map('intval',(array)($rule['weeks']??[])),fn($v)=>$v>=1&&$v<=5)));$periods=$rule['periods']??[];if(!$name||!$weekdays||!is_array($periods)||!$periods||count($periods)>20)fail('กฎที่ '.($ruleIndex+1).' ต้องมีชื่อ วัน และช่วงเวลาอย่างน้อย 1 ช่วง');$cleanPeriods=[];foreach($periods as $period){$start=substr((string)($period['start_time']??''),0,5);$end=substr((string)($period['end_time']??''),0,5);$unlimited=!empty($period['unlimited']);$capacity=(int)($period['capacity']??0);if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$start)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$end)||$start>=$end||(!$unlimited&&($capacity<1||$capacity>10000)))fail('ช่วงเวลาและจำนวนรับในกฎที่ '.($ruleIndex+1).' ไม่ถูกต้อง');$cleanPeriods[]=['start_time'=>$start,'end_time'=>$end,'capacity'=>$unlimited?null:$capacity,'unlimited'=>$unlimited];}usort($cleanPeriods,fn($a,$b)=>strcmp($a['start_time'],$b['start_time']));for($i=1;$i<count($cleanPeriods);$i++)if($cleanPeriods[$i]['start_time']<$cleanPeriods[$i-1]['end_time'])fail('ช่วงเวลาในกฎที่ '.($ruleIndex+1).' ซ้อนกัน');sort($weekdays);sort($weeks);$cleanRules[]=['name'=>$name,'weekdays'=>$weekdays,'weeks'=>$weeks,'periods'=>$cleanPeriods];}
        if($enabled&&!$cleanRules)fail('กรุณาเพิ่มกฎ Slot อย่างน้อย 1 กฎ');$config=json_encode(['enabled'=>$enabled,'rules'=>$cleanRules],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$pdo->prepare('UPDATE departments SET custom_slot_config=? WHERE id=?')->execute([$config,$departmentId]);$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');$pdo->prepare('UPDATE slots SET active=0 WHERE department_id=? AND slot_date>=? AND booked_count=0 AND capacity_locked=0')->execute([$departmentId,$today]);audit((int)$user['id'],'UPDATE_DEPARTMENT_SLOT_RULES','department',(string)$departmentId,['enabled'=>$enabled,'rule_count'=>count($cleanRules)]);respond(['ok'=>true,'data'=>json_decode($config,true)]);
    }

    if ($action === 'save-department') {
        requireMethod('POST'); $user=requireAdmin(); requireCsrf(); $in=jsonInput();
        $id=(int)($in['id']??0);$isEdit=$id>0;$branchId=(int)($in['branch_id']??0);$code=strtoupper(trim((string)($in['code']??'')));$name=trim((string)($in['name']??''));
        $counters=(int)($in['counter_count']??1);$efficiency=(float)($in['efficiency']??.8);$online=(int)($in['online_quota_percent']??70);$walkin=(int)($in['walkin_quota_percent']??20);$reserve=(int)($in['reserve_quota_percent']??10);$unlimited=!empty($in['unlimited_capacity'])?1:0;$dailyCapacity=$unlimited?null:(trim((string)($in['daily_capacity']??''))===''?null:(int)$in['daily_capacity']);$slotMinutes=trim((string)($in['slot_minutes']??''))===''?null:(int)$in['slot_minutes'];$active=!empty($in['active'])?1:0;
        if($branchId<1||!preg_match('/^[A-Z0-9]{1,4}$/',$code)||mb_strlen($name)<2)fail('รหัสแผนกต้องเป็นอักษรอังกฤษหรือตัวเลข 1–4 ตัว');
        if($counters<1||$counters>100||$efficiency<.1||$efficiency>1)fail('จำนวนช่องหรือประสิทธิภาพไม่ถูกต้อง');
        if($dailyCapacity!==null&&($dailyCapacity<1||$dailyCapacity>10000))fail('จำนวนรับต่อวันต้องอยู่ระหว่าง 1–10,000 คน');
        if($slotMinutes!==null&&($slotMinutes<15||$slotMinutes>240))fail('ระยะเวลา Slot ต้องอยู่ระหว่าง 15–240 นาที');
        if($online+$walkin+$reserve!==100)fail('สัดส่วน Online, Walk-in และ Reserve ต้องรวม 100%');
        try{
            if($id){$pdo->prepare('UPDATE departments SET branch_id=?,code=?,name=?,counter_count=?,efficiency=?,online_quota_percent=?,walkin_quota_percent=?,reserve_quota_percent=?,daily_capacity=?,unlimited_capacity=?,slot_minutes=?,active=? WHERE id=?')->execute([$branchId,$code,$name,$counters,$efficiency,$online,$walkin,$reserve,$dailyCapacity,$unlimited,$slotMinutes,$active,$id]);}
            else{$pdo->prepare('INSERT INTO departments(branch_id,code,name,counter_count,efficiency,online_quota_percent,walkin_quota_percent,reserve_quota_percent,daily_capacity,unlimited_capacity,slot_minutes,active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$branchId,$code,$name,$counters,$efficiency,$online,$walkin,$reserve,$dailyCapacity,$unlimited,$slotMinutes,$active]);$id=(int)$pdo->lastInsertId();}
        }catch(PDOException $e){if(str_contains($e->getMessage(),'UNIQUE'))fail('รหัสแผนกนี้ถูกใช้งานแล้ว',409);throw $e;}
        $pdo->prepare("UPDATE slots SET unlimited_capacity=?,capacity_mode=? WHERE department_id=? AND slot_date>=? AND capacity_locked=0")->execute([$unlimited,$unlimited?'UNLIMITED':'DEPARTMENT_CONFIG',$id,(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d')]);
        audit((int)$user['id'],$isEdit?'UPDATE_DEPARTMENT':'CREATE_DEPARTMENT','department',(string)$id,['code'=>$code,'name'=>$name,'unlimited_capacity'=>(bool)$unlimited]);respond(['ok'=>true,'id'=>$id]);
    }

    if ($action === 'services') {
        $user=requireUser();[$scopeSql,$scopeParams]=userScopeClause($user,'d');$stmt=$pdo->prepare("SELECT s.*,d.name department_name FROM services s JOIN departments d ON d.id=s.department_id WHERE $scopeSql ORDER BY d.id,s.id");$stmt->execute($scopeParams);$rows=$stmt->fetchAll();
        respond(['ok'=>true,'data'=>$rows]);
    }

    if ($action === 'save-service') {
        requireMethod('POST');$user=requireAdmin();requireCsrf();$in=jsonInput();
        $id=(int)($in['id']??0);$isEdit=$id>0;$departmentId=(int)($in['department_id']??0);$code=strtoupper(trim((string)($in['code']??'')));$name=trim((string)($in['name']??''));$average=(int)($in['average_minutes']??0);$prep=(int)($in['preparation_minutes']??0);$slotMinutes=(int)($in['slot_minutes']??30);$defaultCapacity=(int)($in['default_capacity']??1);$active=!empty($in['active'])?1:0;
        if($departmentId<1||!preg_match('/^[A-Z0-9_-]{2,30}$/',$code)||mb_strlen($name)<2)fail('ข้อมูลบริการไม่ครบถ้วน');
        if($average<1||$average>480||$prep<0||$prep>120||$slotMinutes<15||$slotMinutes>240||$defaultCapacity<1||$defaultCapacity>1000)fail('ระยะเวลา Slot หรือจำนวนรับไม่ถูกต้อง');
        try{
            if($id){$pdo->prepare('UPDATE services SET department_id=?,code=?,name=?,average_minutes=?,preparation_minutes=?,slot_minutes=?,default_capacity=?,active=? WHERE id=?')->execute([$departmentId,$code,$name,$average,$prep,$slotMinutes,$defaultCapacity,$active,$id]);}
            else{$pdo->prepare('INSERT INTO services(department_id,code,name,average_minutes,preparation_minutes,slot_minutes,default_capacity,active) VALUES (?,?,?,?,?,?,?,?)')->execute([$departmentId,$code,$name,$average,$prep,$slotMinutes,$defaultCapacity,$active]);$id=(int)$pdo->lastInsertId();}
        }catch(PDOException $e){if(str_contains($e->getMessage(),'UNIQUE'))fail('รหัสบริการนี้ถูกใช้งานแล้ว',409);throw $e;}
        audit((int)$user['id'],$isEdit?'UPDATE_SERVICE':'CREATE_SERVICE','service',(string)$id,['code'=>$code,'name'=>$name]);respond(['ok'=>true,'id'=>$id]);
    }

    if ($action === 'kiosk-templates') {
        $user=requireRoles(['admin','kiosk_admin']);$rows=$pdo->query('SELECT k.*,g.name building_name,(SELECT COUNT(*) FROM kiosk_menu_items m WHERE m.template_id=k.id) menu_count FROM kiosk_templates k JOIN buildings g ON g.id=k.building_id ORDER BY k.id')->fetchAll();$rows=array_values(array_filter($rows,fn($row)=>userCanAccessBuilding($pdo,$user,(int)$row['building_id'])));
        respond(['ok'=>true,'data'=>$rows]);
    }

    if ($action === 'save-kiosk-template') {
        requireMethod('POST');$user=requireRoles(['admin','kiosk_admin']);requireCsrf();$in=jsonInput();$id=(int)($in['id']??0);$isEdit=$id>0;
        $buildingId=(int)($in['building_id']??0);$code=strtoupper(trim((string)($in['code']??'')));$name=trim((string)($in['name']??''));$location=trim((string)($in['location_label']??''));$active=!empty($in['active'])?1:0;
        if($buildingId<1||!preg_match('/^[A-Z0-9_-]{2,40}$/',$code)||mb_strlen($name)<2)fail('ข้อมูลจุด Kiosk ไม่ครบถ้วน');
        if(!userCanAccessBuilding($pdo,$user,$buildingId))fail('ไม่มีสิทธิ์จัดการ Kiosk ของอาคารนี้',403);
        $exists=$pdo->prepare('SELECT 1 FROM buildings WHERE id=?');$exists->execute([$buildingId]);if(!$exists->fetchColumn())fail('ไม่พบอาคาร',404);
        try{if($id)$pdo->prepare('UPDATE kiosk_templates SET building_id=?,code=?,name=?,location_label=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$buildingId,$code,$name,$location,$active,$id]);else{$pdo->prepare('INSERT INTO kiosk_templates(building_id,code,name,location_label,active) VALUES (?,?,?,?,?)')->execute([$buildingId,$code,$name,$location,$active]);$id=(int)$pdo->lastInsertId();}}catch(PDOException $e){if(str_contains($e->getMessage(),'UNIQUE'))fail('รหัส Kiosk นี้ถูกใช้งานแล้ว',409);throw $e;}
        audit((int)$user['id'],$isEdit?'UPDATE_KIOSK_TEMPLATE':'CREATE_KIOSK_TEMPLATE','kiosk_template',(string)$id,['code'=>$code]);respond(['ok'=>true,'id'=>$id]);
    }

    if ($action === 'kiosk-menu-items') {
        $user=requireRoles(['admin','kiosk_admin']);$templateId=(int)($_GET['template_id']??0);$access=$pdo->prepare('SELECT building_id FROM kiosk_templates WHERE id=?');$access->execute([$templateId]);$buildingId=(int)$access->fetchColumn();if(!$buildingId)fail('ไม่พบ Kiosk Template',404);if(!userCanAccessBuilding($pdo,$user,$buildingId))fail('ไม่มีสิทธิ์จัดการ Kiosk นี้',403);$stmt=$pdo->prepare('SELECT m.*,d.name department_name,s.name service_name FROM kiosk_menu_items m LEFT JOIN departments d ON d.id=m.department_id LEFT JOIN services s ON s.id=m.service_id WHERE m.template_id=? ORDER BY COALESCE(m.parent_id,0),m.sort_order,m.id');$stmt->execute([$templateId]);respond(['ok'=>true,'data'=>$stmt->fetchAll()]);
    }

    if ($action === 'kiosk-template-selector') {
        $user=requireUser();if(!in_array((string)$user['role'],['admin','kiosk_admin','kiosk_manager'],true))fail('บัญชีนี้ไม่มีสิทธิ์เปลี่ยน Template ของ Kiosk',403);
        $scopeType=strtoupper((string)($user['scope_type']??'ALL'));$scopeId=(int)($user['scope_id']??0);$scopeSql='1=1';$scopeParams=[];if($scopeType==='BRANCH'&&$scopeId){$scopeSql='EXISTS(SELECT 1 FROM branches b WHERE b.id=? AND b.building_id=k.building_id)';$scopeParams=[$scopeId];}elseif($scopeType==='DEPARTMENT'&&$scopeId){$scopeSql='EXISTS(SELECT 1 FROM departments d JOIN branches b ON b.id=d.branch_id WHERE d.id=? AND b.building_id=k.building_id)';$scopeParams=[$scopeId];}$stmt=$pdo->prepare("SELECT k.code,k.name,k.location_label,g.name building_name FROM kiosk_templates k JOIN buildings g ON g.id=k.building_id WHERE k.active=1 AND g.active=1 AND $scopeSql ORDER BY g.name,k.name");$stmt->execute($scopeParams);$rows=$stmt->fetchAll();
        audit((int)$user['id'],'VIEW_KIOSK_TEMPLATE_SELECTOR','kiosk_template');respond(['ok'=>true,'data'=>$rows]);
    }

    if ($action === 'kiosk-template-selector-auth') {
        requireMethod('POST');$in=jsonInput();$stmt=$pdo->prepare('SELECT * FROM users WHERE username=? AND active=1');$stmt->execute([trim((string)($in['username']??''))]);$user=$stmt->fetch();
        if(!$user||!password_verify((string)($in['password']??''),$user['password_hash'])){usleep(300000);fail('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง',401);}
        if(!in_array((string)$user['role'],['admin','kiosk_admin','kiosk_manager'],true))fail('บัญชีนี้ไม่มีสิทธิ์เปลี่ยน Template ของ Kiosk',403);
        $scopeType=strtoupper((string)($user['scope_type']??'ALL'));$scopeId=(int)($user['scope_id']??0);$scopeSql='1=1';$scopeParams=[];if($scopeType==='BRANCH'&&$scopeId){$scopeSql='EXISTS(SELECT 1 FROM branches b WHERE b.id=? AND b.building_id=k.building_id)';$scopeParams=[$scopeId];}elseif($scopeType==='DEPARTMENT'&&$scopeId){$scopeSql='EXISTS(SELECT 1 FROM departments d JOIN branches b ON b.id=d.branch_id WHERE d.id=? AND b.building_id=k.building_id)';$scopeParams=[$scopeId];}$stmt=$pdo->prepare("SELECT k.code,k.name,k.location_label,g.name building_name FROM kiosk_templates k JOIN buildings g ON g.id=k.building_id WHERE k.active=1 AND g.active=1 AND $scopeSql ORDER BY g.name,k.name");$stmt->execute($scopeParams);$rows=$stmt->fetchAll();
        audit((int)$user['id'],'VERIFY_KIOSK_TEMPLATE_SELECTOR','kiosk_template');respond(['ok'=>true,'user'=>['display_name'=>$user['display_name']],'data'=>$rows]);
    }

    if ($action === 'save-kiosk-menu-item') {
        requireMethod('POST');$user=requireRoles(['admin','kiosk_admin']);requireCsrf();$in=jsonInput();$id=(int)($in['id']??0);$isEdit=$id>0;$templateId=(int)($in['template_id']??0);$parentId=(int)($in['parent_id']??0)?:null;$departmentId=(int)($in['department_id']??0)?:null;$serviceId=(int)($in['service_id']??0)?:null;
        $label=trim((string)($in['label']??''));$subtitle=trim((string)($in['subtitle']??''));$icon=mb_substr(trim((string)($in['icon']??'Q')),0,4);$order=max(0,(int)($in['sort_order']??0));$start=trim((string)($in['start_time']??''))?:null;$end=trim((string)($in['end_time']??''))?:null;$active=!empty($in['active'])?1:0;$scheduleJson=null;
        if(isset($in['schedule_rules'])){if(!is_array($in['schedule_rules'])||!$in['schedule_rules']||count($in['schedule_rules'])>20)fail('กรุณาเพิ่มกฎการแสดงปุ่มอย่างน้อย 1 กฎ');$rules=[];foreach($in['schedule_rules'] as $index=>$rule){$weekdays=array_values(array_unique(array_filter(array_map('intval',(array)($rule['weekdays']??[])),fn($v)=>$v>=1&&$v<=7)));$weeks=array_values(array_unique(array_filter(array_map('intval',(array)($rule['weeks']??[])),fn($v)=>$v>=1&&$v<=5)));$ruleStart=trim((string)($rule['start_time']??''));$ruleEnd=trim((string)($rule['end_time']??''));sort($weekdays);sort($weeks);if(!$weekdays||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$ruleStart)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$ruleEnd))fail('กฎการแสดงปุ่มที่ '.($index+1).' ไม่ถูกต้อง');$rules[]=['weekdays'=>$weekdays,'weeks'=>$weeks,'start_time'=>$ruleStart,'end_time'=>$ruleEnd];}$scheduleJson=json_encode(['version'=>2,'rules'=>$rules],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$start=$end=null;}
        elseif(isset($in['schedule'])){if(!is_array($in['schedule']))fail('ตารางเวลาแสดงปุ่มไม่ถูกต้อง');$schedule=[];$activeDays=0;foreach($in['schedule'] as $day){$weekday=(int)($day['weekday']??0);$dayActive=!empty($day['active']);$dayStart=trim((string)($day['start_time']??''));$dayEnd=trim((string)($day['end_time']??''));$weeks=array_values(array_unique(array_filter(array_map('intval',(array)($day['weeks']??[])),fn($v)=>$v>=1&&$v<=5)));sort($weeks);if($weekday<1||$weekday>7||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$dayStart)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/',$dayEnd))fail('ตารางเวลาแสดงปุ่มไม่ถูกต้อง');if($dayActive)$activeDays++;$schedule[$weekday]=['weekday'=>$weekday,'active'=>$dayActive,'start_time'=>$dayStart,'end_time'=>$dayEnd,'weeks'=>$weeks];}if(count($schedule)!==7||$activeDays<1)fail('กรุณาเลือกวันแสดงปุ่มอย่างน้อย 1 วัน');ksort($schedule);$scheduleJson=json_encode(array_values($schedule),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$start=$end=null;}
        if($templateId<1||mb_strlen($label)<2)fail('กรุณาระบุชื่อปุ่มและ Kiosk');if($id&&$parentId===$id)fail('ปุ่มไม่สามารถเป็นเมนูย่อยของตัวเอง');
        $access=$pdo->prepare('SELECT building_id FROM kiosk_templates WHERE id=?');$access->execute([$templateId]);$templateBuilding=(int)$access->fetchColumn();if(!$templateBuilding)fail('ไม่พบ Kiosk Template',404);if(!userCanAccessBuilding($pdo,$user,$templateBuilding))fail('ไม่มีสิทธิ์จัดการ Kiosk นี้',403);
        if(($start===null)!==($end===null)||($start!==null&&(!preg_match('/^\d{2}:\d{2}$/',$start)||!preg_match('/^\d{2}:\d{2}$/',$end))))fail('ช่วงเวลาแสดงปุ่มไม่ถูกต้อง');
        if($parentId){$check=$pdo->prepare('SELECT 1 FROM kiosk_menu_items WHERE id=? AND template_id=? AND parent_id IS NULL');$check->execute([$parentId,$templateId]);if(!$check->fetchColumn())fail('เมนูหลักไม่ถูกต้อง');}
        if($serviceId){$check=$pdo->prepare('SELECT department_id FROM services WHERE id=?');$check->execute([$serviceId]);$serviceDepartment=(int)$check->fetchColumn();if(!$serviceDepartment)fail('ไม่พบบริการ',404);if($departmentId&&$departmentId!==$serviceDepartment)fail('บริการไม่อยู่ในแผนกที่เลือก');$departmentId=$serviceDepartment;}
        if($departmentId){$check=$pdo->prepare('SELECT d.branch_id,b.building_id FROM departments d JOIN branches b ON b.id=d.branch_id WHERE d.id=?');$check->execute([$departmentId]);$department=$check->fetch();if(!$department)fail('ไม่พบแผนก',404);if((int)$department['building_id']!==$templateBuilding)fail('แผนกไม่ได้อยู่ในอาคารของ Kiosk');if(!userCanAccessDepartment($user,$departmentId,(int)$department['branch_id']))fail('ไม่มีสิทธิ์ใช้แผนกนี้',403);}
        try{if($id)$pdo->prepare('UPDATE kiosk_menu_items SET template_id=?,parent_id=?,department_id=?,service_id=?,label=?,subtitle=?,icon=?,sort_order=?,start_time=?,end_time=?,schedule_json=?,active=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$templateId,$parentId,$departmentId,$serviceId,$label,$subtitle,$icon?:'Q',$order,$start,$end,$scheduleJson,$active,$id]);else{$pdo->prepare('INSERT INTO kiosk_menu_items(template_id,parent_id,department_id,service_id,label,subtitle,icon,sort_order,start_time,end_time,schedule_json,active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$templateId,$parentId,$departmentId,$serviceId,$label,$subtitle,$icon?:'Q',$order,$start,$end,$scheduleJson,$active]);$id=(int)$pdo->lastInsertId();}}catch(PDOException $e){throw $e;}
        audit((int)$user['id'],$isEdit?'UPDATE_KIOSK_MENU':'CREATE_KIOSK_MENU','kiosk_menu_item',(string)$id,['template_id'=>$templateId,'label'=>$label]);respond(['ok'=>true,'id'=>$id]);
    }

    if ($action === 'public-kiosk-config') {
        $code=strtoupper(trim((string)($_GET['code']??'MAIN')));if(!preg_match('/^[A-Z0-9_-]{2,40}$/',$code))fail('รหัส Kiosk ไม่ถูกต้อง');
        $stmt=$pdo->prepare('SELECT k.id,k.code,k.name,k.location_label,k.building_id,g.name building_name FROM kiosk_templates k JOIN buildings g ON g.id=k.building_id WHERE k.code=? AND k.active=1 AND g.active=1');$stmt->execute([$code]);$template=$stmt->fetch();if(!$template)fail('ไม่พบจุด Kiosk หรือยังไม่ได้เปิดใช้งาน',404);
        $stmt=$pdo->prepare('SELECT m.id,m.parent_id,m.department_id,m.service_id,m.label,m.subtitle,m.icon,m.sort_order,m.start_time,m.end_time,m.schedule_json,d.name department_name,s.name service_name FROM kiosk_menu_items m LEFT JOIN departments d ON d.id=m.department_id LEFT JOIN branches b ON b.id=d.branch_id LEFT JOIN services s ON s.id=m.service_id WHERE m.template_id=? AND m.active=1 AND (d.id IS NULL OR (d.active=1 AND b.active=1 AND b.building_id=?)) AND (s.id IS NULL OR s.active=1) ORDER BY COALESCE(m.parent_id,0),m.sort_order,m.id');$stmt->execute([$template['id'],$template['building_id']]);$bangkokNow=new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok'));$now=$bangkokNow->format('H:i');$weekday=(int)$bangkokNow->format('N');
        $weekOfMonth=(int)ceil((int)$bangkokNow->format('j')/7);$items=array_values(array_filter($stmt->fetchAll(),function($item)use($now,$weekday,$weekOfMonth){if(!empty($item['schedule_json'])){$schedule=json_decode((string)$item['schedule_json'],true);if(!is_array($schedule))return false;if(isset($schedule['rules'])&&is_array($schedule['rules'])){foreach($schedule['rules'] as $rule){$weekdays=array_map('intval',(array)($rule['weekdays']??[]));$weeks=array_map('intval',(array)($rule['weeks']??[]));if(!in_array($weekday,$weekdays,true)||($weeks&&!in_array($weekOfMonth,$weeks,true)))continue;$start=substr((string)($rule['start_time']??''),0,5);$end=substr((string)($rule['end_time']??''),0,5);if($start&&$end&&($start===$end||($start<$end?($now>=$start&&$now<$end):($now>=$start||$now<$end))))return true;}return false;}$day=null;foreach($schedule as $entry)if((int)($entry['weekday']??0)===$weekday){$day=$entry;break;}if(!$day||empty($day['active']))return false;$weeks=array_map('intval',(array)($day['weeks']??[]));if($weeks&&!in_array($weekOfMonth,$weeks,true))return false;$start=substr((string)($day['start_time']??''),0,5);$end=substr((string)($day['end_time']??''),0,5);if(!$start||!$end)return false;return $start===$end||($start<$end?($now>=$start&&$now<$end):($now>=$start||$now<$end));}if(!$item['start_time']||!$item['end_time'])return true;$start=substr((string)$item['start_time'],0,5);$end=substr((string)$item['end_time'],0,5);return $start===$end||($start<$end?($now>=$start&&$now<$end):($now>=$start||$now<$end));}));
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

    if ($action === 'sync-hosxp-holidays') {
        requireMethod('POST');$user=requireAdmin();requireCsrf();$in=jsonInput();$bangkok=new DateTimeZone('Asia/Bangkok');$year=(int)(new DateTimeImmutable('now',$bangkok))->format('Y');
        $from=(string)($in['from_date']??sprintf('%04d-01-01',$year));$to=(string)($in['to_date']??sprintf('%04d-12-31',$year+1));if(!hosxpEnabled())fail('ยังไม่ได้ตั้งค่าการเชื่อมต่อ HOSxP',409);
        try{$holidays=hosxpHolidayRows($from,$to);}catch(Throwable $e){error_log('HOSxP holiday sync error: '.$e->getMessage());fail('อ่านตารางวันหยุดจาก HOSxP ไม่สำเร็จ กรุณาตรวจสอบสิทธิ์อ่านตาราง holiday และการเชื่อมต่อ',502);}
        $branches=$pdo->query('SELECT id FROM branches WHERE active=1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);if(!$branches)fail('ยังไม่มีสาขาที่เปิดใช้งานใน K-QCENTER',409);
        $source='[HOSXP_SYNC]';$created=0;$updated=0;$skipped=0;$find=$pdo->prepare("SELECT id,note FROM schedule_overrides WHERE scope_type='BRANCH' AND scope_id=? AND override_date=?");$insert=$pdo->prepare("INSERT INTO schedule_overrides(scope_type,scope_id,override_date,name,is_closed,open_time,close_time,counter_count,note,active) VALUES ('BRANCH',?,?,?,1,NULL,NULL,NULL,?,1)");$update=$pdo->prepare('UPDATE schedule_overrides SET name=?,is_closed=1,open_time=NULL,close_time=NULL,note=?,active=1,updated_at=CURRENT_TIMESTAMP WHERE id=?');
        $pdo->beginTransaction();try{foreach($branches as $branchId)foreach($holidays as $holiday){$find->execute([(int)$branchId,$holiday['date']]);$existing=$find->fetch();$note=$source.' ซิงค์จากตาราง holiday ของ HOSxP';if(!$existing){$insert->execute([(int)$branchId,$holiday['date'],$holiday['name'],$note]);$created++;}elseif(str_starts_with((string)($existing['note']??''),$source)){$update->execute([$holiday['name'],$note,(int)$existing['id']]);$updated++;}else{$skipped++;}}$pdo->commit();}catch(Throwable $e){$pdo->rollBack();throw $e;}
        audit((int)$user['id'],'SYNC_HOSXP_HOLIDAYS','schedule_override','',['from'=>$from,'to'=>$to,'holiday_count'=>count($holidays),'created'=>$created,'updated'=>$updated,'skipped'=>$skipped]);respond(['ok'=>true,'data'=>['from_date'=>$from,'to_date'=>$to,'holiday_count'=>count($holidays),'branch_count'=>count($branches),'created'=>$created,'updated'=>$updated,'skipped'=>$skipped]]);
    }

    if ($action === 'weekly-hours') { requireUser();$type=strtoupper((string)($_GET['scope_type']??''));$id=(int)($_GET['scope_id']??0);$stmt=$pdo->prepare('SELECT * FROM weekly_hours WHERE scope_type=? AND scope_id=? ORDER BY weekday,interval_order');$stmt->execute([$type,$id]);respond(['ok'=>true,'data'=>$stmt->fetchAll()]); }
    if ($action === 'save-weekly-hours') {
        requireMethod('POST');$user=requireAdmin();requireCsrf();$in=jsonInput();$type=strtoupper((string)($in['scope_type']??''));$scopeId=(int)($in['scope_id']??0);$items=$in['intervals']??[];if(!in_array($type,['BRANCH','DEPARTMENT'],true)||$scopeId<1||!is_array($items))fail('ข้อมูลตารางเวลาไม่ถูกต้อง');
        $clean=[];foreach($items as $item){$day=(int)($item['weekday']??0);$open=(string)($item['open_time']??'');$close=(string)($item['close_time']??'');if($day<1||$day>7||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',$open)||!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/',$close))fail('รูปแบบช่วงเวลาไม่ถูกต้อง');$open=substr($open,0,5);$close=substr($close,0,5);if($open>=$close)fail('เวลาเริ่มต้องน้อยกว่าเวลาสิ้นสุด');$clean[$day][]=[$open,$close];}
        foreach($clean as $day=>$ranges){usort($ranges,fn($a,$b)=>strcmp($a[0],$b[0]));for($i=1;$i<count($ranges);$i++)if($ranges[$i][0]<$ranges[$i-1][1])fail('ช่วงเวลาในวันเดียวกันซ้อนกัน');}
        $pdo->beginTransaction();try{$pdo->prepare('DELETE FROM weekly_hours WHERE scope_type=? AND scope_id=?')->execute([$type,$scopeId]);upsertWeeklyScheduleConfig($pdo,$type,$scopeId);$stmt=$pdo->prepare('INSERT INTO weekly_hours(scope_type,scope_id,weekday,interval_order,open_time,close_time) VALUES (?,?,?,?,?,?)');foreach($clean as $day=>$ranges)foreach($ranges as $index=>$range)$stmt->execute([$type,$scopeId,$day,$index+1,$range[0],$range[1]]);$pdo->commit();}catch(Throwable $e){$pdo->rollBack();throw $e;}audit((int)$user['id'],'UPDATE_WEEKLY_HOURS',strtolower($type),(string)$scopeId,['interval_count'=>count($items)]);respond(['ok'=>true]);
    }

    if ($action === 'slots') {
        $user=requireUser();[$scopeSql,$scopeParams]=userScopeClause($user,'d');
        $date = $_GET['date'] ?? date('Y-m-d');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))fail('วันที่ไม่ถูกต้อง');generateSlots($pdo, $date);$departmentId=(int)($_GET['department_id']??0);$where=['s.slot_date=?','s.active=1',$scopeSql];$params=array_merge([$date],$scopeParams);if($departmentId){$where[]='d.id=?';$params[]=$departmentId;}
        $stmt = $pdo->prepare("SELECT s.*,d.name department_name,d.counter_count,d.efficiency,sv.name service_name,sv.average_minutes,sv.preparation_minutes FROM slots s JOIN departments d ON d.id=s.department_id LEFT JOIN services sv ON sv.id=s.service_id WHERE ".implode(' AND ',$where).' ORDER BY s.start_time,d.id');
        $stmt->execute($params); respond(['ok'=>true,'date'=>$date,'department_id'=>$departmentId,'data'=>$stmt->fetchAll()]);
    }

    if ($action === 'update-slot') {
        requireMethod('POST'); $user = requireAdmin(); requireCsrf(); $in = jsonInput();
        $id = (int)($in['id'] ?? 0); $capacity = (int)($in['capacity'] ?? -1);
        $stmt = $pdo->prepare('SELECT s.*,d.online_quota_percent,d.walkin_quota_percent FROM slots s JOIN departments d ON d.id=s.department_id WHERE s.id=?'); $stmt->execute([$id]); $slot = $stmt->fetch();
        if (!$slot) fail('ไม่พบ Slot',404);
        $nowBangkok=new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok'));$slotEnd=new DateTimeImmutable($slot['slot_date'].' '.$slot['end_time'],new DateTimeZone('Asia/Bangkok'));if($slotEnd<=$nowBangkok)fail('ไม่สามารถแก้ไข Slot ที่สิ้นสุดแล้ว',409);
        if ($capacity < (int)$slot['booked_count']) fail('Capacity ต้องไม่น้อยกว่าจำนวนที่จองแล้ว');
        $online=(int)floor($capacity*(int)$slot['online_quota_percent']/100); $walkin=(int)floor($capacity*(int)$slot['walkin_quota_percent']/100); $reserve=max(0,$capacity-$online-$walkin);
        $pdo->prepare('UPDATE slots SET capacity=?,online_capacity=?,walkin_capacity=?,reserve_capacity=?,capacity_locked=1,capacity_mode=? WHERE id=?')->execute([$capacity,$online,$walkin,$reserve,'MANUAL',$id]);
        audit((int)$user['id'],'UPDATE_SLOT','slot',(string)$id,['old_capacity'=>(int)$slot['capacity'],'new_capacity'=>$capacity]);
        respond(['ok'=>true]);
    }

    if ($action === 'adjust-remaining-slots') {
        requireMethod('POST');$user=requireAdmin();requireCsrf();$in=jsonInput();$departmentId=(int)($in['department_id']??0);$adjustment=(int)($in['adjustment']??0);if($departmentId<1||$adjustment===0||abs($adjustment)>10000)fail('กรุณาระบุแผนกและจำนวนเพิ่มหรือลดที่ถูกต้อง');
        $departmentStmt=$pdo->prepare('SELECT id,branch_id,name,online_quota_percent,walkin_quota_percent FROM departments WHERE id=? AND active=1');$departmentStmt->execute([$departmentId]);$department=$departmentStmt->fetch();if(!$department)fail('ไม่พบแผนก',404);if(!userCanAccessDepartment($user,$departmentId,(int)$department['branch_id']))fail('ไม่มีสิทธิ์จัดการแผนกนี้',403);
        $nowBangkok=new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok'));$today=$nowBangkok->format('Y-m-d');generateSlots($pdo,$today);$stmt=$pdo->prepare('SELECT * FROM slots WHERE department_id=? AND slot_date=? AND active=1 AND start_time>? AND booked_count=0 AND capacity_locked=0 ORDER BY start_time,id');$stmt->execute([$departmentId,$today,$nowBangkok->format('H:i:s')]);$slots=$stmt->fetchAll();if(!$slots)fail('ไม่มี Slot ที่ยังไม่เริ่มและสามารถปรับได้',409);
        $oldTotal=array_sum(array_map(fn($slot)=>(int)$slot['capacity'],$slots));$newTotal=$oldTotal+$adjustment;if($newTotal<0)fail('จำนวนที่ลดมากกว่าจำนวนรับของ Slot ที่ปรับได้',409);$count=count($slots);$base=intdiv($newTotal,$count);$remainder=$newTotal%$count;
        beginCapacityTransaction($pdo);try{$update=$pdo->prepare("UPDATE slots SET capacity=?,online_capacity=?,walkin_capacity=?,reserve_capacity=?,capacity_locked=1,capacity_mode='TODAY_OVERRIDE' WHERE id=? AND booked_count=0 AND capacity_locked=0");foreach($slots as $index=>$slot){$capacity=$base+($index<$remainder?1:0);$online=(int)floor($capacity*(int)$department['online_quota_percent']/100);$walkin=(int)floor($capacity*(int)$department['walkin_quota_percent']/100);$reserve=max(0,$capacity-$online-$walkin);$update->execute([$capacity,$online,$walkin,$reserve,$slot['id']]);if($update->rowCount()!==1)throw new DomainException('Slot มีการเปลี่ยนแปลง กรุณาลองใหม่');}$pdo->exec('COMMIT');}catch(Throwable $e){try{$pdo->exec('ROLLBACK');}catch(Throwable){}if($e instanceof DomainException)fail($e->getMessage(),409);throw $e;}
        audit((int)$user['id'],'ADJUST_REMAINING_SLOTS','department',(string)$departmentId,['date'=>$today,'adjustment'=>$adjustment,'old_total'=>$oldTotal,'new_total'=>$newTotal,'slot_count'=>$count]);respond(['ok'=>true,'department_name'=>$department['name'],'slot_count'=>$count,'old_total'=>$oldTotal,'new_total'=>$newTotal]);
    }

    if ($action === 'bookings') {
        $user=requireUser();[$scopeSql,$scopeParams]=userScopeClause($user,'d');
        $date = $_GET['date'] ?? date('Y-m-d');
        $stmt=$pdo->prepare("SELECT b.*,s.slot_date,s.start_time,s.end_time,d.name department_name,sv.name service_name FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN departments d ON d.id=s.department_id LEFT JOIN services sv ON sv.id=s.service_id WHERE s.slot_date=? AND $scopeSql ORDER BY s.start_time,b.id");
        $stmt->execute(array_merge([$date],$scopeParams)); respond(['ok'=>true,'data'=>$stmt->fetchAll()]);
    }

    if ($action === 'registration-monitor') {
        $user=requireRoles(['admin','counter','viewer']);[$scopeSql,$scopeParams]=userScopeClause($user,'d');
        $date=$_GET['date']??(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))fail('วันที่ไม่ถูกต้อง');generateSlots($pdo,$date);
        $stmt=$pdo->prepare("SELECT q.id,q.queue_no,q.source,q.status,q.current_step,q.checked_in_at,q.first_called_at,q.registration_started_at,q.visit_opened_at,b.reference_no,b.customer_name,s.id slot_id,s.slot_date,s.start_time,s.end_time,s.department_id,d.name department_name,sv.name service_name FROM queue_tickets q JOIN bookings b ON b.id=q.booking_id JOIN slots s ON s.id=b.slot_id JOIN departments d ON d.id=s.department_id LEFT JOIN services sv ON sv.id=s.service_id WHERE s.slot_date=? AND $scopeSql ORDER BY s.start_time,q.checked_in_at,q.id");
        $stmt->execute(array_merge([$date],$scopeParams));$tickets=$stmt->fetchAll();
        $slotStmt=$pdo->prepare("SELECT s.id,s.department_id,s.start_time,s.end_time,s.capacity,s.booked_count,d.name department_name,sv.name service_name FROM slots s JOIN departments d ON d.id=s.department_id LEFT JOIN services sv ON sv.id=s.service_id WHERE s.slot_date=? AND s.active=1 AND $scopeSql ORDER BY s.start_time,s.id");
        $slotStmt->execute(array_merge([$date],$scopeParams));$slots=$slotStmt->fetchAll();
        $nowBangkok=new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok'));$nowUtc=new DateTimeImmutable('now',new DateTimeZone('UTC'));$departments=[];
        foreach($slots as $slot){$departmentId=(int)$slot['department_id'];if(!isset($departments[$departmentId]))$departments[$departmentId]=['department_id'=>$departmentId,'department_name'=>$slot['department_name'],'arrived'=>0,'waiting'=>0,'in_progress'=>0,'sent'=>0,'average_wait_minutes'=>0,'longest_wait_minutes'=>0,'current_slot'=>null,'next_slot'=>null,'minutes_to_next_slot'=>null];$start=new DateTimeImmutable($date.' '.$slot['start_time'],new DateTimeZone('Asia/Bangkok'));$end=new DateTimeImmutable($date.' '.$slot['end_time'],new DateTimeZone('Asia/Bangkok'));$summary=['id'=>(int)$slot['id'],'start_time'=>$slot['start_time'],'end_time'=>$slot['end_time'],'capacity'=>(int)$slot['capacity'],'booked_count'=>(int)$slot['booked_count'],'service_name'=>$slot['service_name']];if($nowBangkok>=$start&&$nowBangkok<$end)$departments[$departmentId]['current_slot']=$summary;if($start>$nowBangkok&&$departments[$departmentId]['next_slot']===null){$departments[$departmentId]['next_slot']=$summary;$departments[$departmentId]['minutes_to_next_slot']=max(0,(int)ceil(($start->getTimestamp()-$nowBangkok->getTimestamp())/60));}}
        $canManage=in_array((string)$user['role'],['admin','counter'],true);$waitTotals=[];$waitCounts=[];foreach($tickets as &$ticket){$departmentId=(int)$ticket['department_id'];if(!isset($departments[$departmentId]))$departments[$departmentId]=['department_id'=>$departmentId,'department_name'=>$ticket['department_name'],'arrived'=>0,'waiting'=>0,'in_progress'=>0,'sent'=>0,'average_wait_minutes'=>0,'longest_wait_minutes'=>0,'current_slot'=>null,'next_slot'=>null,'minutes_to_next_slot'=>null];$departments[$departmentId]['arrived']++;$step=(string)$ticket['current_step'];if(in_array($step,['REGISTRATION_WAITING','REGISTRATION_CALLED'],true))$departments[$departmentId]['waiting']++;elseif($step==='REGISTRATION_IN_PROGRESS')$departments[$departmentId]['in_progress']++;elseif($step==='SENT_TO_DEPARTMENT')$departments[$departmentId]['sent']++;$startUtc=new DateTimeImmutable((string)$ticket['checked_in_at'],new DateTimeZone('UTC'));$finishUtc=!empty($ticket['registration_started_at'])?new DateTimeImmutable((string)$ticket['registration_started_at'],new DateTimeZone('UTC')):$nowUtc;$wait=max(0,(int)floor(($finishUtc->getTimestamp()-$startUtc->getTimestamp())/60));$ticket['wait_minutes']=$wait;$ticket['customer_display']=maskThaiName((string)$ticket['customer_name']);$spokenName=trim((string)$ticket['customer_name']);if(in_array($spokenName,['ผู้รับบริการไม่ใช้บัตรประชาชน','ผู้รับบริการ','ไม่ระบุชื่อ','ไม่ทราบชื่อ','-'],true))$spokenName='';if($canManage)$ticket['customer_spoken_name']=$spokenName;unset($ticket['customer_name']);if(in_array($step,['REGISTRATION_WAITING','REGISTRATION_CALLED'],true)){$waitTotals[$departmentId]=($waitTotals[$departmentId]??0)+$wait;$waitCounts[$departmentId]=($waitCounts[$departmentId]??0)+1;$departments[$departmentId]['longest_wait_minutes']=max($departments[$departmentId]['longest_wait_minutes'],$wait);}}unset($ticket);
        foreach($departments as $id=>&$department)$department['average_wait_minutes']=($waitCounts[$id]??0)>0?(int)round($waitTotals[$id]/$waitCounts[$id]):0;unset($department);
        respond(['ok'=>true,'date'=>$date,'generated_at'=>$nowUtc->format(DATE_ATOM),'can_manage'=>$canManage,'departments'=>array_values($departments),'tickets'=>$tickets]);
    }

    if ($action === 'queue-transition') {
        requireMethod('POST');$user=requireRoles(['admin','counter']);requireCsrf();$in=jsonInput();$ticketId=(int)($in['ticket_id']??0);$transition=strtoupper(trim((string)($in['transition']??'')));
        $stmt=$pdo->prepare('SELECT q.*,s.department_id,d.branch_id FROM queue_tickets q JOIN bookings b ON b.id=q.booking_id JOIN slots s ON s.id=b.slot_id JOIN departments d ON d.id=s.department_id WHERE q.id=?');$stmt->execute([$ticketId]);$ticket=$stmt->fetch();if(!$ticket)fail('ไม่พบคิว',404);if(!userCanAccessDepartment($user,(int)$ticket['department_id'],(int)$ticket['branch_id']))fail('ไม่มีสิทธิ์จัดการคิวของแผนกนี้',403);
        $map=['CALL'=>[['REGISTRATION_WAITING'],"status='CALLED',current_step='REGISTRATION_CALLED',first_called_at=COALESCE(first_called_at,CURRENT_TIMESTAMP)",'CALLED','เรียกผู้ป่วยเข้าห้องบัตร'], 'START_REGISTRATION'=>[['REGISTRATION_WAITING','REGISTRATION_CALLED'],"status='SERVING',current_step='REGISTRATION_IN_PROGRESS',registration_started_at=COALESCE(registration_started_at,CURRENT_TIMESTAMP)",'REGISTRATION_STARTED','เริ่มลงทะเบียนและเปิด Visit'], 'RECALL'=>[['REGISTRATION_CALLED'],"status='CALLED'",'RECALLED','เรียกซ้ำเข้าห้องบัตร'], 'SEND_DEPARTMENT'=>[['REGISTRATION_IN_PROGRESS'],"status='COMPLETED',current_step='SENT_TO_DEPARTMENT',visit_opened_at=COALESCE(visit_opened_at,CURRENT_TIMESTAMP),service_started_at=COALESCE(service_started_at,CURRENT_TIMESTAMP)",'SENT_TO_DEPARTMENT','เปิด Visit และส่งผู้ป่วยไปยังแผนกแล้ว']];
        if(!isset($map[$transition]))fail('คำสั่งเปลี่ยนสถานะไม่ถูกต้อง');[$allowed,$set,$eventType,$note]=$map[$transition];if(!in_array((string)$ticket['current_step'],$allowed,true))fail('สถานะคิวเปลี่ยนไปแล้ว กรุณาโหลดข้อมูลใหม่',409);
        beginCapacityTransaction($pdo);try{$update=$pdo->prepare("UPDATE queue_tickets SET $set,updated_at=CURRENT_TIMESTAMP WHERE id=? AND current_step=?");$update->execute([$ticketId,$ticket['current_step']]);if($update->rowCount()!==1&&$transition!=='RECALL')throw new DomainException('สถานะคิวเปลี่ยนไปแล้ว กรุณาโหลดข้อมูลใหม่');$newStep=in_array($transition,['CALL','RECALL'],true)?'REGISTRATION_CALLED':($transition==='START_REGISTRATION'?'REGISTRATION_IN_PROGRESS':'SENT_TO_DEPARTMENT');$newStatus=in_array($transition,['CALL','RECALL'],true)?'CALLED':($transition==='START_REGISTRATION'?'SERVING':'COMPLETED');$pdo->prepare('INSERT INTO queue_events(queue_ticket_id,event_type,status,step,note) VALUES (?,?,?,?,?)')->execute([$ticketId,$eventType,$newStatus,$newStep,$note]);$pdo->prepare('UPDATE bookings SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$newStatus,$ticket['booking_id']]);$pdo->exec('COMMIT');}catch(Throwable $e){try{$pdo->exec('ROLLBACK');}catch(Throwable){}if($e instanceof DomainException)fail($e->getMessage(),409);throw $e;}audit((int)$user['id'],$eventType,'queue_ticket',(string)$ticketId,['department_id'=>(int)$ticket['department_id']]);respond(['ok'=>true]);
    }

    if ($action === 'create-booking') {
        requireMethod('POST'); $in=jsonInput();
        foreach(['slot_id','customer_name','customer_phone'] as $field) if(empty($in[$field])) fail("กรุณาระบุ $field");
        $idempotency = trim((string)($in['idempotency_key'] ?? '')) ?: null;
        beginCapacityTransaction($pdo);
        try {
            if ($idempotency) {
                $stmt=$pdo->prepare('SELECT * FROM bookings WHERE idempotency_key=?'); $stmt->execute([$idempotency]);
                if ($existing=$stmt->fetch()) { $pdo->exec('COMMIT'); respond(['ok'=>true,'booking'=>['id'=>(int)$existing['id'],'public_id'=>$existing['public_id'],'reference_no'=>$existing['reference_no'],'status'=>$existing['status']],'duplicate'=>true]); }
            }
            $slot=selectSlotForUpdate($pdo,(int)$in['slot_id']);
            if(!$slot) throw new DomainException('ไม่พบ Slot ที่เปิดใช้งาน');
            if(empty($slot['unlimited_capacity'])&&(int)$slot['booked_count'] >= (int)$slot['capacity']) throw new DomainException('Slot นี้เต็มแล้ว');
            $source=strtoupper(trim((string)($in['source']??'ONLINE')));if($source!=='ONLINE')throw new DomainException('ช่องทางการจองไม่ถูกต้อง');
            $deviceDayHash=deviceDayHash((string)($in['device_id']??''),(string)$slot['slot_date']);
            $deviceBooking=$pdo->prepare("SELECT reference_no FROM bookings WHERE device_day_hash=? LIMIT 1");$deviceBooking->execute([$deviceDayHash]);
            if($existingDeviceBooking=$deviceBooking->fetch())throw new DomainException('อุปกรณ์นี้จองคิวออนไลน์สำหรับวันนี้แล้ว เลขอ้างอิง '.$existingDeviceBooking['reference_no']);
            $publicId=newPublicId();
            $reference='KQ-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));
            $citizenHash=!empty($in['citizen_id'])?identityHash((string)$in['citizen_id']):null;
            $stmt=$pdo->prepare('INSERT INTO bookings(public_id,reference_no,slot_id,customer_name,customer_phone,source,status,idempotency_key,citizen_id_hash,device_day_hash) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$publicId,$reference,(int)$in['slot_id'],trim((string)$in['customer_name']),trim((string)$in['customer_phone']),$source,'CONFIRMED',$idempotency,$citizenHash,$deviceDayHash]);
            $bookingId=(int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE slots SET booked_count=booked_count+1 WHERE id=?')->execute([(int)$in['slot_id']]);
            $pdo->exec('COMMIT'); audit(null,'CREATE_BOOKING','booking',(string)$bookingId,['reference'=>$reference]);
            respond(['ok'=>true,'booking'=>['id'=>$bookingId,'public_id'=>$publicId,'reference_no'=>$reference,'status'=>'CONFIRMED']],201);
        } catch(Throwable $e) { try { $pdo->exec('ROLLBACK'); } catch (Throwable) {} if($e instanceof DomainException) fail($e->getMessage(),409);if($e instanceof PDOException&&$e->getCode()==='23000')fail('อุปกรณ์นี้มีรายการจองออนไลน์สำหรับวันนี้แล้ว กรุณาเปิดเมนูคิวของฉัน',409); throw $e; }
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

    if ($action === 'kiosk-cancel-queue') {
        requireMethod('POST');$in=jsonInput();$token=trim((string)($in['token']??''));$citizenId=normalizeCitizenId((string)($in['citizen_id']??''));
        if(!preg_match('/^[a-f0-9]{32}$/',$token)||!validThaiCitizenId($citizenId))fail('ข้อมูลยืนยันไม่ถูกต้อง');
        beginCapacityTransaction($pdo);
        try{
            $stmt=$pdo->prepare('SELECT q.id ticket_id,q.booking_id,q.status queue_status,q.queue_no,b.slot_id,b.citizen_id_hash FROM queue_tickets q JOIN bookings b ON b.id=q.booking_id WHERE q.public_id=?');$stmt->execute([$token]);$row=$stmt->fetch();
            if(!$row||empty($row['citizen_id_hash'])||!hash_equals((string)$row['citizen_id_hash'],identityHash($citizenId)))throw new DomainException('ไม่พบคิวที่ตรงกับบัตรประชาชน');
            if(!in_array($row['queue_status'],['WAITING','CALLED'],true))throw new DomainException('คิวนี้เริ่มให้บริการแล้ว กรุณาติดต่อเจ้าหน้าที่');
            $pdo->prepare("UPDATE queue_tickets SET status='CANCELLED',current_step='CANCELLED',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$row['ticket_id']]);
            $pdo->prepare("UPDATE bookings SET status='CANCELLED',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$row['booking_id']]);
            $pdo->prepare('UPDATE slots SET booked_count=CASE WHEN booked_count>0 THEN booked_count-1 ELSE 0 END WHERE id=?')->execute([$row['slot_id']]);
            $pdo->prepare("INSERT INTO queue_events(queue_ticket_id,event_type,status,step,note) VALUES (?,'CANCELLED','CANCELLED','CANCELLED','ผู้รับบริการยกเลิกคิวที่ Kiosk ด้วยบัตรประชาชน')")->execute([$row['ticket_id']]);
            $pdo->exec('COMMIT');audit(null,'KIOSK_CANCEL_QUEUE','queue_ticket',(string)$row['ticket_id'],['queue_no'=>$row['queue_no']]);respond(['ok'=>true]);
        }catch(Throwable $e){try{$pdo->exec('ROLLBACK');}catch(Throwable){}if($e instanceof DomainException)fail($e->getMessage(),409);throw $e;}
    }

    if ($action === 'kiosk-lookup') {
        requireMethod('POST');$in=jsonInput();$method=(string)($in['method']??'');$value=trim((string)($in['value']??''));
        if($value==='')fail('กรุณาระบุข้อมูลสำหรับค้นหา');
        $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
        $base="SELECT b.public_id,b.reference_no,b.status,b.customer_name,s.slot_date,s.start_time,s.end_time,d.name department_name,sv.name service_name,br.name branch_name,q.public_id queue_public_id,q.queue_no,q.current_step,q.status queue_status FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN departments d ON d.id=s.department_id JOIN branches br ON br.id=d.branch_id LEFT JOIN services sv ON sv.id=s.service_id LEFT JOIN queue_tickets q ON q.booking_id=b.id WHERE s.slot_date=? AND b.status NOT IN ('CANCELLED','EXPIRED')";
        if($method==='code'){$stmt=$pdo->prepare($base.' AND (b.reference_no=? OR b.public_id=? OR q.public_id=? OR q.queue_no=?) ORDER BY s.start_time LIMIT 5');$stmt->execute([$today,$value,$value,$value,$value]);}
        elseif($method==='phone'){$phone=normalizePhone($value);if(strlen($phone)<9)fail('หมายเลขโทรศัพท์ไม่ถูกต้อง');$stmt=$pdo->prepare($base." AND REPLACE(REPLACE(REPLACE(b.customer_phone,'-',''),' ',''),'+66','0')=? ORDER BY s.start_time LIMIT 5");$stmt->execute([$today,$phone]);}
        elseif($method==='citizen'){$citizenId=normalizeCitizenId($value);if(!validThaiCitizenId($citizenId))fail('เลขบัตรประชาชนไม่ถูกต้อง กรุณาตรวจสอบ 13 หลัก');$hash=identityHash($citizenId);$stmt=$pdo->prepare($base.' AND b.citizen_id_hash=? ORDER BY s.start_time LIMIT 5');$stmt->execute([$today,$hash]);}
        else fail('วิธีค้นหาไม่ถูกต้อง');
        $rows=array_map(function($row){$row['customer_display']=maskThaiName((string)$row['customer_name']);unset($row['customer_name']);return $row;},$stmt->fetchAll());
        respond(['ok'=>true,'data'=>$rows]);
    }

    if ($action === 'kiosk-walk-in') {
        requireMethod('POST');$in=jsonInput();foreach(['slot_id','customer_name'] as $field)if(empty($in[$field]))fail("กรุณาระบุ $field");$idempotency=trim((string)($in['idempotency_key']??''))?:null;$citizenHash=!empty($in['citizen_id'])?identityHash((string)$in['citizen_id']):null;
        beginCapacityTransaction($pdo);
        try{if($idempotency){$existing=$pdo->prepare('SELECT * FROM bookings WHERE idempotency_key=?');$existing->execute([$idempotency]);if($booking=$existing->fetch()){$ticket=createQueueTicket($pdo,$booking);$pdo->exec('COMMIT');respond(['ok'=>true,'ticket'=>$ticket,'booking_public_id'=>$booking['public_id'],'duplicate'=>true]);}}$slot=selectSlotForUpdate($pdo,(int)$in['slot_id']);if(!$slot)throw new DomainException('ไม่พบ Slot ที่เปิดใช้งาน');if(empty($slot['unlimited_capacity'])&&(int)$slot['booked_count']>=(int)$slot['capacity'])throw new DomainException('Slot นี้เต็มแล้ว');$queuePrefix=null;$menuItemId=(int)($in['menu_item_id']??0);if($menuItemId){$menu=$pdo->prepare('SELECT m.icon,COALESCE(m.department_id,p.department_id) department_id,COALESCE(m.service_id,p.service_id) service_id,k.building_id FROM kiosk_menu_items m LEFT JOIN kiosk_menu_items p ON p.id=m.parent_id JOIN kiosk_templates k ON k.id=m.template_id WHERE m.id=? AND m.active=1 AND k.active=1');$menu->execute([$menuItemId]);$menuRow=$menu->fetch();if(!$menuRow)throw new DomainException('ไม่พบปุ่มบริการที่เลือก');$slotBuilding=$pdo->prepare('SELECT b.building_id FROM departments d JOIN branches b ON b.id=d.branch_id WHERE d.id=?');$slotBuilding->execute([(int)$slot['department_id']]);if((int)$slotBuilding->fetchColumn()!==(int)$menuRow['building_id'])throw new DomainException('ปุ่มบริการไม่อยู่ในอาคารของ Slot');if($menuRow['department_id']!==null&&(int)$menuRow['department_id']!==(int)$slot['department_id'])throw new DomainException('ปุ่มบริการไม่ตรงกับแผนกของ Slot');if($menuRow['service_id']!==null&&(int)$menuRow['service_id']!==(int)$slot['service_id'])throw new DomainException('ปุ่มบริการไม่ตรงกับประเภทบริการ');$queuePrefix=(string)$menuRow['icon'];}$publicId=newPublicId();$reference='KQ-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,6));$stmt=$pdo->prepare("INSERT INTO bookings(public_id,reference_no,slot_id,customer_name,customer_phone,source,status,idempotency_key,citizen_id_hash) VALUES (?,?,?,?,?,'WALK_IN','CONFIRMED',?,?)");$stmt->execute([$publicId,$reference,$slot['id'],trim((string)$in['customer_name']),trim((string)($in['customer_phone']??'')),$idempotency,$citizenHash]);$bookingId=(int)$pdo->lastInsertId();$pdo->prepare('UPDATE slots SET booked_count=booked_count+1 WHERE id=?')->execute([$slot['id']]);$stmt=$pdo->prepare('SELECT * FROM bookings WHERE id=?');$stmt->execute([$bookingId]);$ticket=createQueueTicket($pdo,$stmt->fetch(),$queuePrefix);$pdo->exec('COMMIT');audit(null,'CREATE_WALK_IN','queue_ticket',(string)$ticket['id'],['queue_no'=>$ticket['queue_no'],'menu_item_id'=>$menuItemId]);respond(['ok'=>true,'ticket'=>$ticket,'booking_public_id'=>$publicId],201);}catch(Throwable $e){try{$pdo->exec('ROLLBACK');}catch(Throwable){}if($e instanceof DomainException)fail($e->getMessage(),409);throw $e;}
    }

    if ($action === 'hosxp-health') {
        requireAdmin();if(!hosxpEnabled())respond(['ok'=>true,'data'=>['enabled'=>false,'connected'=>false]]);
        try{$check=hosxpDb()->query('SELECT 1 ready FROM patient LIMIT 1')->fetch();respond(['ok'=>true,'data'=>['enabled'=>true,'connected'=>true,'patient_table'=>isset($check['ready'])]]);}catch(Throwable $e){error_log('HOSxP health error: '.$e->getMessage());respond(['ok'=>true,'data'=>['enabled'=>true,'connected'=>false]]);}
    }

    if ($action === 'hosxp-link') {
        requireMethod('POST');$in=jsonInput();$token=trim((string)($in['token']??''));$citizenId=preg_replace('/\D/','',(string)($in['citizen_id']??''))??'';
        if(!preg_match('/^[a-f0-9]{32}$/',$token)||!validThaiCitizenId($citizenId))fail('ข้อมูลสำหรับเชื่อมสถานะ HOSxP ไม่ถูกต้อง');
        $stmt=$pdo->prepare('SELECT q.id,b.citizen_id_hash FROM queue_tickets q JOIN bookings b ON b.id=q.booking_id WHERE q.public_id=? LIMIT 1');$stmt->execute([$token]);$ticket=$stmt->fetch();
        if(!$ticket||!hash_equals((string)$ticket['citizen_id_hash'],identityHash($citizenId)))fail('ไม่สามารถยืนยันตัวตนเพื่อเชื่อมสถานะ HOSxP',403);
        $result=hosxpLinkTicketByCitizen($pdo,(int)$ticket['id'],$citizenId);audit(null,'HOSXP_LINK','queue_ticket',(string)$ticket['id'],['sync_status'=>$result['sync_status']]);respond(['ok'=>true,'data'=>$result]);
    }

    if ($action === 'hosxp-status') {
        $token=trim((string)($_GET['token']??''));if(!preg_match('/^[a-f0-9]{32}$/',$token))fail('รหัสติดตามไม่ถูกต้อง',404);
        $stmt=$pdo->prepare('SELECT id FROM queue_tickets WHERE public_id=? LIMIT 1');$stmt->execute([$token]);$ticketId=(int)$stmt->fetchColumn();if(!$ticketId)fail('ไม่พบคิว',404);
        respond(['ok'=>true,'data'=>hosxpStatusForTicket($pdo,$ticketId,true)]);
    }

    if ($action === 'public-slots') {
        $date=$_GET['date']??date('Y-m-d');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))fail('วันที่ไม่ถูกต้อง');generateSlots($pdo,$date);
        $where=['s.slot_date=?','s.active=1','(s.unlimited_capacity=1 OR s.booked_count<s.capacity)'];$params=[$date];$departmentId=(int)($_GET['department_id']??0);$serviceId=(int)($_GET['service_id']??0);$kioskCode=strtoupper(trim((string)($_GET['kiosk']??'')));$bangkokNow=new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok'));if($date===$bangkokNow->format('Y-m-d')){$where[]='s.end_time>?';$params[]=$bangkokNow->format('H:i:s');}
        if($departmentId){$where[]='d.id=?';$params[]=$departmentId;}if($serviceId){$where[]='sv.id=?';$params[]=$serviceId;}if($kioskCode!==''){$where[]='b.building_id=(SELECT building_id FROM kiosk_templates WHERE code=? AND active=1)';$params[]=$kioskCode;}
        $stmt=$pdo->prepare("SELECT s.id,s.slot_date,s.start_time,s.end_time,s.capacity,s.booked_count,s.online_capacity,s.walkin_capacity,s.reserve_capacity,s.provider_count,s.capacity_locked,s.capacity_mode,s.unlimited_capacity,d.name department_name,sv.name service_name,sv.average_minutes,sv.preparation_minutes,b.name branch_name FROM slots s JOIN departments d ON d.id=s.department_id JOIN branches b ON b.id=d.branch_id LEFT JOIN services sv ON sv.id=s.service_id WHERE ".implode(' AND ',$where).' ORDER BY s.start_time,d.id');$stmt->execute($params);$rows=array_values(array_filter(array_map(function($row){$row['is_unlimited']=(int)$row['unlimited_capacity']===1;$row['available_count']=$row['is_unlimited']?null:max(0,(int)$row['capacity']-(int)$row['booked_count']);$row['capacity_source']=(string)$row['capacity_mode'];return $row;},$stmt->fetchAll()),fn($row)=>$row['is_unlimited']||(int)$row['available_count']>0));respond(['ok'=>true,'data'=>$rows]);
    }

    if ($action === 'audit') {
        requireAdmin();
        $rows=$pdo->query('SELECT a.*,u.display_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100')->fetchAll();
        respond(['ok'=>true,'data'=>$rows]);
    }

    fail('ไม่พบ API endpoint',404);
} catch (Throwable $e) {
    error_log($e->__toString());
    fail('เกิดข้อผิดพลาดภายในระบบ',500);
}

