<?php
declare(strict_types=1);

require_once __DIR__ . '/hosxp.php';

const KQ_ROOT = __DIR__ . '/..';
define('KQ_DATA', getenv('KQCENTER_DATA_PATH') ?: KQ_ROOT . '/data');
define('KQ_DB', getenv('KQCENTER_DB_PATH') ?: KQ_DATA . '/kqcenter.sqlite');

date_default_timezone_set('Asia/Bangkok');

if (!is_dir(KQ_DATA) && !mkdir(KQ_DATA, 0770, true) && !is_dir(KQ_DATA)) {
    throw new RuntimeException('Cannot create data directory');
}
ini_set('log_errors', '1');
ini_set('error_log', KQ_DATA . '/php-error.log');

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = trim((string)getenv('KQCENTER_DB_DSN'));
    $user = (string)getenv('KQCENTER_DB_USER');
    $password = (string)getenv('KQCENTER_DB_PASSWORD');
    $pdo = new PDO($dsn ?: 'sqlite:' . KQ_DB, $dsn ? $user : null, $dsn ? $password : null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    } else {
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        migrateMariaDb($pdo);
        return;
    }
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  display_name TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'admin',
  scope_type TEXT NOT NULL DEFAULT 'ALL',
  scope_id INTEGER,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at TEXT
);
CREATE TABLE IF NOT EXISTS buildings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  is_24_hours INTEGER NOT NULL DEFAULT 0,
  open_time TEXT,
  close_time TEXT,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS branches (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  open_time TEXT NOT NULL DEFAULT '08:30',
  close_time TEXT NOT NULL DEFAULT '20:00',
  closing_buffer_minutes INTEGER NOT NULL DEFAULT 30,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS departments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  branch_id INTEGER NOT NULL REFERENCES branches(id),
  code TEXT NOT NULL,
  name TEXT NOT NULL,
  counter_count INTEGER NOT NULL DEFAULT 1 CHECK(counter_count > 0),
  efficiency REAL NOT NULL DEFAULT 0.80 CHECK(efficiency > 0 AND efficiency <= 1),
  online_quota_percent INTEGER NOT NULL DEFAULT 70,
  walkin_quota_percent INTEGER NOT NULL DEFAULT 20,
  reserve_quota_percent INTEGER NOT NULL DEFAULT 10,
  daily_capacity INTEGER,
  unlimited_capacity INTEGER NOT NULL DEFAULT 0,
  slot_minutes INTEGER,
  custom_slot_config TEXT,
  active INTEGER NOT NULL DEFAULT 1,
  UNIQUE(branch_id, code)
);
CREATE TABLE IF NOT EXISTS services (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  department_id INTEGER NOT NULL REFERENCES departments(id),
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  average_minutes INTEGER NOT NULL CHECK(average_minutes > 0),
  preparation_minutes INTEGER NOT NULL DEFAULT 0,
  slot_minutes INTEGER NOT NULL DEFAULT 30,
  default_capacity INTEGER NOT NULL DEFAULT 1,
  active INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS kiosk_templates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  building_id INTEGER NOT NULL REFERENCES buildings(id),
  code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, location_label TEXT,
  active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS kiosk_menu_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  template_id INTEGER NOT NULL REFERENCES kiosk_templates(id) ON DELETE CASCADE,
  parent_id INTEGER REFERENCES kiosk_menu_items(id) ON DELETE CASCADE,
  department_id INTEGER REFERENCES departments(id), service_id INTEGER REFERENCES services(id),
  label TEXT NOT NULL, subtitle TEXT, icon TEXT NOT NULL DEFAULT 'Q', sort_order INTEGER NOT NULL DEFAULT 0,
  start_time TEXT, end_time TEXT, schedule_json TEXT, active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS calendar_overrides (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  branch_id INTEGER NOT NULL REFERENCES branches(id),
  override_date TEXT NOT NULL,
  name TEXT NOT NULL,
  is_closed INTEGER NOT NULL DEFAULT 0,
  open_time TEXT,
  close_time TEXT,
  note TEXT,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(branch_id, override_date)
);
CREATE TABLE IF NOT EXISTS weekly_hours (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  scope_type TEXT NOT NULL CHECK(scope_type IN ('BRANCH','DEPARTMENT')),
  scope_id INTEGER NOT NULL,
  weekday INTEGER NOT NULL CHECK(weekday BETWEEN 1 AND 7),
  interval_order INTEGER NOT NULL DEFAULT 1,
  open_time TEXT NOT NULL,
  close_time TEXT NOT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  UNIQUE(scope_type, scope_id, weekday, interval_order)
);
CREATE TABLE IF NOT EXISTS weekly_schedule_config (
  scope_type TEXT NOT NULL CHECK(scope_type IN ('BRANCH','DEPARTMENT')),
  scope_id INTEGER NOT NULL,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(scope_type, scope_id)
);
CREATE TABLE IF NOT EXISTS schedule_overrides (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  scope_type TEXT NOT NULL CHECK(scope_type IN ('BRANCH','DEPARTMENT')),
  scope_id INTEGER NOT NULL,
  override_date TEXT NOT NULL,
  name TEXT NOT NULL,
  is_closed INTEGER NOT NULL DEFAULT 0,
  open_time TEXT,
  close_time TEXT,
  counter_count INTEGER,
  note TEXT,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(scope_type, scope_id, override_date)
);
CREATE TABLE IF NOT EXISTS slots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  department_id INTEGER NOT NULL REFERENCES departments(id),
  service_id INTEGER REFERENCES services(id),
  slot_date TEXT NOT NULL,
  start_time TEXT NOT NULL,
  end_time TEXT NOT NULL,
  capacity INTEGER NOT NULL CHECK(capacity >= 0),
  online_capacity INTEGER NOT NULL DEFAULT 0,
  walkin_capacity INTEGER NOT NULL DEFAULT 0,
  reserve_capacity INTEGER NOT NULL DEFAULT 0,
  provider_count INTEGER NOT NULL DEFAULT 1,
  capacity_locked INTEGER NOT NULL DEFAULT 0,
  capacity_mode TEXT NOT NULL DEFAULT 'AUTO',
  unlimited_capacity INTEGER NOT NULL DEFAULT 0,
  booked_count INTEGER NOT NULL DEFAULT 0 CHECK(booked_count >= 0),
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(department_id, service_id, slot_date, start_time)
);
CREATE TABLE IF NOT EXISTS bookings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id TEXT NOT NULL UNIQUE,
  reference_no TEXT NOT NULL UNIQUE,
  slot_id INTEGER NOT NULL REFERENCES slots(id),
  customer_name TEXT NOT NULL,
  customer_phone TEXT NOT NULL,
  source TEXT NOT NULL DEFAULT 'ONLINE',
  status TEXT NOT NULL DEFAULT 'CONFIRMED',
  idempotency_key TEXT UNIQUE,
  citizen_id_hash TEXT,
  device_day_hash TEXT UNIQUE,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS queue_tickets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id TEXT NOT NULL UNIQUE,
  booking_id INTEGER UNIQUE REFERENCES bookings(id),
  queue_no TEXT NOT NULL,
  source TEXT NOT NULL CHECK(source IN ('ONLINE','WALK_IN')),
  status TEXT NOT NULL DEFAULT 'WAITING',
  current_step TEXT NOT NULL DEFAULT 'REGISTRATION_WAITING',
  checked_in_at TEXT NOT NULL,
  first_called_at TEXT,
  registration_started_at TEXT,
  visit_opened_at TEXT,
  service_started_at TEXT,
  service_completed_at TEXT,
  estimated_wait_minutes INTEGER,
  counter_no INTEGER,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS queue_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  queue_ticket_id INTEGER NOT NULL REFERENCES queue_tickets(id),
  event_type TEXT NOT NULL,
  status TEXT NOT NULL,
  step TEXT NOT NULL,
  note TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS hosxp_visit_links (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  queue_ticket_id INTEGER NOT NULL UNIQUE REFERENCES queue_tickets(id) ON DELETE CASCADE,
  hn_hash TEXT NOT NULL, hn_ciphertext TEXT NOT NULL, vn_ciphertext TEXT,
  visit_date TEXT, visit_time TEXT, current_department TEXT, current_department_code TEXT, raw_status TEXT,
  sync_status TEXT NOT NULL DEFAULT 'WAITING_FOR_VISIT', last_error TEXT, last_synced_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS audit_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER REFERENCES users(id),
  action TEXT NOT NULL,
  entity_type TEXT NOT NULL,
  entity_id TEXT,
  detail TEXT,
  ip_address TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS system_settings (
  setting_key TEXT PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_by INTEGER REFERENCES users(id),
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_slots_date ON slots(slot_date, department_id);
CREATE INDEX IF NOT EXISTS idx_calendar_date ON calendar_overrides(override_date, branch_id);
CREATE INDEX IF NOT EXISTS idx_weekly_scope ON weekly_hours(scope_type, scope_id, weekday);
CREATE INDEX IF NOT EXISTS idx_schedule_override ON schedule_overrides(scope_type, scope_id, override_date);
CREATE INDEX IF NOT EXISTS idx_bookings_slot_status ON bookings(slot_id, status);
CREATE INDEX IF NOT EXISTS idx_queue_status ON queue_tickets(status, created_at);
CREATE INDEX IF NOT EXISTS idx_queue_events_ticket ON queue_events(queue_ticket_id, created_at);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_kiosk_menu_template ON kiosk_menu_items(template_id,parent_id,sort_order);
SQL);

    ensureColumn($pdo, 'branches', 'building_id', 'INTEGER REFERENCES buildings(id)');
    ensureColumn($pdo, 'bookings', 'citizen_id_hash', 'TEXT');
    ensureColumn($pdo, 'bookings', 'device_day_hash', 'TEXT');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_bookings_device_day ON bookings(device_day_hash)');
    ensureColumn($pdo, 'users', 'scope_type', "TEXT NOT NULL DEFAULT 'ALL'");
    ensureColumn($pdo, 'users', 'scope_id', 'INTEGER');
    ensureColumn($pdo, 'kiosk_menu_items', 'schedule_json', 'TEXT');
    ensureColumn($pdo, 'slots', 'capacity_locked', 'INTEGER NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'slots', 'provider_count', 'INTEGER NOT NULL DEFAULT 1');
    ensureColumn($pdo, 'slots', 'capacity_mode', "TEXT NOT NULL DEFAULT 'AUTO'");
    ensureColumn($pdo, 'services', 'slot_minutes', 'INTEGER NOT NULL DEFAULT 30');
    ensureColumn($pdo, 'services', 'default_capacity', 'INTEGER NOT NULL DEFAULT 1');
    ensureColumn($pdo, 'departments', 'daily_capacity', 'INTEGER');
    ensureColumn($pdo, 'departments', 'unlimited_capacity', 'INTEGER NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'departments', 'slot_minutes', 'INTEGER');
    ensureColumn($pdo, 'departments', 'custom_slot_config', 'TEXT');
    ensureColumn($pdo, 'slots', 'unlimited_capacity', 'INTEGER NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'queue_tickets', 'counter_no', 'INTEGER');
    if ((int)$pdo->query('SELECT COUNT(*) FROM buildings')->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO buildings(code,name,is_24_hours,active) VALUES ('MAIN-BLDG','อาคารหลัก',1,1)");
    }
    $defaultBuilding = (int)$pdo->query('SELECT id FROM buildings ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->prepare('UPDATE branches SET building_id=? WHERE building_id IS NULL')->execute([$defaultBuilding]);

    $count = (int)$pdo->query('SELECT COUNT(*) FROM branches')->fetchColumn();
    if ($count === 0) seed($pdo);
}

function migrateMariaDb(PDO $pdo): void
{
    $sql = file_get_contents(__DIR__ . '/schema-mariadb.sql');
    if ($sql === false) throw new RuntimeException('Cannot read MariaDB schema');
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) $pdo->exec($statement);
    ensureColumn($pdo, 'branches', 'building_id', 'BIGINT UNSIGNED NULL');
    ensureColumn($pdo, 'bookings', 'citizen_id_hash', 'CHAR(64) NULL');
    ensureColumn($pdo, 'bookings', 'device_day_hash', 'CHAR(64) NULL');
    ensureUniqueIndex($pdo,'bookings','uq_bookings_device_day','device_day_hash');
    ensureColumn($pdo, 'users', 'scope_type', "VARCHAR(20) NOT NULL DEFAULT 'ALL'");
    ensureColumn($pdo, 'users', 'scope_id', 'BIGINT UNSIGNED NULL');
    ensureColumn($pdo, 'kiosk_menu_items', 'schedule_json', 'TEXT NULL');
    ensureColumn($pdo, 'slots', 'capacity_locked', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'slots', 'provider_count', 'INT UNSIGNED NOT NULL DEFAULT 1');
    ensureColumn($pdo, 'slots', 'capacity_mode', "VARCHAR(20) NOT NULL DEFAULT 'AUTO'");
    ensureColumn($pdo, 'services', 'slot_minutes', 'INT UNSIGNED NOT NULL DEFAULT 30');
    ensureColumn($pdo, 'services', 'default_capacity', 'INT UNSIGNED NOT NULL DEFAULT 1');
    ensureColumn($pdo, 'departments', 'daily_capacity', 'INT UNSIGNED NULL');
    ensureColumn($pdo, 'departments', 'unlimited_capacity', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'departments', 'slot_minutes', 'INT UNSIGNED NULL');
    ensureColumn($pdo, 'departments', 'custom_slot_config', 'LONGTEXT NULL');
    ensureColumn($pdo, 'slots', 'unlimited_capacity', 'TINYINT(1) NOT NULL DEFAULT 0');
    ensureColumn($pdo, 'queue_tickets', 'counter_no', 'TINYINT UNSIGNED NULL');
    if ((int)$pdo->query('SELECT COUNT(*) FROM buildings')->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO buildings(code,name,is_24_hours,active) VALUES ('MAIN-BLDG','อาคารหลัก',1,1)");
    }
    $defaultBuilding = (int)$pdo->query('SELECT id FROM buildings ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->prepare('UPDATE branches SET building_id=? WHERE building_id IS NULL')->execute([$defaultBuilding]);
    if ((int)$pdo->query('SELECT COUNT(*) FROM branches')->fetchColumn() === 0) seed($pdo);
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $stmt=$pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
        $stmt->execute([$table,$column]);
        if($stmt->fetchColumn())return;
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        return;
    }
    $columns = $pdo->query("PRAGMA table_info($table)")->fetchAll();
    foreach ($columns as $item) if ($item['name'] === $column) return;
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
}

function ensureUniqueIndex(PDO $pdo,string $table,string $index,string $column): void
{
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')return;
    $stmt=$pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
    $stmt->execute([$table,$index]);
    if(!$stmt->fetchColumn())$pdo->exec("ALTER TABLE `$table` ADD UNIQUE KEY `$index` (`$column`)");
}

function ticketTemplateDefaults(): array
{
    return [
        'system_name'=>'K-QCENTER','header_text'=>'บัตรคิวรับบริการห้องบัตร',
        'show_service'=>true,'show_slot'=>true,'show_date'=>true,'show_reference'=>true,
        'show_qr'=>true,'show_qr_caption'=>true,'hide_duplicate_service'=>true,
        'qr_caption'=>'สแกน QR เพื่อติดตามสถานะคิว',
        'footer_text'=>'โปรดเก็บบัตรนี้ไว้จนกว่าจะรับบริการเสร็จ',
        'paper_height'=>370,'qr_size'=>112,'brand_font_size'=>13,'queue_font_size'=>30,
        'department_font_size'=>14,'service_font_size'=>11,'detail_font_size'=>10,
    ];
}

function cleanTicketTemplate(array $input): array
{
    $defaults=ticketTemplateDefaults();$out=$defaults;
    $limits=['system_name'=>[1,40],'header_text'=>[0,100],'qr_caption'=>[0,100],'footer_text'=>[0,140]];
    foreach($limits as $key=>[$min,$max]){$value=trim((string)($input[$key]??$defaults[$key]));if(mb_strlen($value)<$min||mb_strlen($value)>$max)fail('ข้อความบนบัตรคิวมีความยาวไม่ถูกต้อง');$out[$key]=$value;}
    foreach(['show_service','show_slot','show_date','show_reference','show_qr','show_qr_caption','hide_duplicate_service'] as $key)$out[$key]=filter_var($input[$key]??$defaults[$key],FILTER_VALIDATE_BOOL);
    $ranges=['paper_height'=>[260,700],'qr_size'=>[70,160],'brand_font_size'=>[9,22],'queue_font_size'=>[20,48],'department_font_size'=>[9,24],'service_font_size'=>[8,20],'detail_font_size'=>[7,16]];
    foreach($ranges as $key=>[$min,$max]){$value=(int)($input[$key]??$defaults[$key]);if($value<$min||$value>$max)fail('ขนาดองค์ประกอบบัตรคิวไม่ถูกต้อง');$out[$key]=$value;}
    return $out;
}

function ticketTemplate(PDO $pdo): array
{
    $stmt=$pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='ticket_template'");$stmt->execute();$json=$stmt->fetchColumn();
    if(!$json)return ticketTemplateDefaults();$decoded=json_decode((string)$json,true);return is_array($decoded)?cleanTicketTemplate($decoded):ticketTemplateDefaults();
}

function featureDefaults(): array
{
    return [
        'kiosk'=>true,'online_booking'=>true,'queue_status'=>true,'smartcard_reader'=>true,
        'ticket_printing'=>true,'hosxp_integration'=>true,'queue_voice'=>true,'qr_scanning'=>true,
    ];
}

function cleanFeatures(array $input): array
{
    $out=[];
    foreach(featureDefaults() as $key=>$default)$out[$key]=filter_var($input[$key]??$default,FILTER_VALIDATE_BOOL);
    return $out;
}

function featureSettings(PDO $pdo): array
{
    $stmt=$pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key='system_features'");$stmt->execute();$json=$stmt->fetchColumn();
    if(!$json)return featureDefaults();$decoded=json_decode((string)$json,true);return is_array($decoded)?cleanFeatures($decoded):featureDefaults();
}

function requireFeature(PDO $pdo,string $key): void
{
    $features=featureSettings($pdo);
    if(empty($features[$key]))fail('ส่วนนี้ถูกปิดใช้งานโดยผู้ดูแลระบบ',503);
}

function seed(PDO $pdo): void
{
    $pdo->beginTransaction();
    try {
        $pdo->exec("INSERT INTO branches(code,name,open_time,close_time,closing_buffer_minutes) VALUES ('MAIN','สาขาหลัก','08:30','20:00',30)");
        $branchId = (int)$pdo->lastInsertId();
        $departments = [
            ['REG','แผนกทะเบียน',4,.80], ['GEN','แผนกตรวจทั่วไป',3,.80],
            ['PAY','แผนกชำระเงิน',2,.80], ['DOC','แผนกรับเอกสาร',1,.75]
        ];
        $serviceMinutes = [15,20,10,8];
        foreach ($departments as $i => $d) {
            $stmt = $pdo->prepare('INSERT INTO departments(branch_id,code,name,counter_count,efficiency) VALUES (?,?,?,?,?)');
            $stmt->execute([$branchId,$d[0],$d[1],$d[2],$d[3]]);
            $departmentId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare('INSERT INTO services(department_id,code,name,average_minutes) VALUES (?,?,?,?)');
            $stmt->execute([$departmentId,$d[0].'-01',$d[1],$serviceMinutes[$i]]);
        }
        generateSlots($pdo, (new DateTimeImmutable('today', new DateTimeZone('Asia/Bangkok')))->format('Y-m-d'));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function generateSlots(PDO $pdo, string $date): int
{
    $todayBangkok=(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');$refreshExistingCapacity=$date>=$todayBangkok;
    $pdo->prepare('UPDATE slots SET active=0 WHERE slot_date=?')->execute([$date]);
    $departments = $pdo->query('SELECT d.*,b.open_time branch_open,b.close_time branch_close,b.closing_buffer_minutes,b.building_id,g.is_24_hours building_24,g.open_time building_open,g.close_time building_close FROM departments d JOIN branches b ON b.id=d.branch_id LEFT JOIN buildings g ON g.id=b.building_id WHERE d.active=1 AND b.active=1 AND (g.id IS NULL OR g.active=1)')->fetchAll();
    $created = 0;
    foreach ($departments as $department) {
        $serviceStmt = $pdo->prepare('SELECT * FROM services WHERE department_id=? AND active=1 ORDER BY id LIMIT 1');
        $serviceStmt->execute([$department['id']]);
        $service = $serviceStmt->fetch();
        if (!$service) {
            $autoCode='AUTO-D'.(int)$department['id'];
            try{$pdo->prepare('INSERT INTO services(department_id,code,name,average_minutes,preparation_minutes,slot_minutes,default_capacity,active) VALUES (?,?,?,15,0,30,1,1)')->execute([$department['id'],$autoCode,$department['name']]);}catch(PDOException $e){if(!str_contains($e->getMessage(),'UNIQUE'))throw $e;}
            $serviceStmt->execute([$department['id']]);$service=$serviceStmt->fetch();
            if(!$service)continue;
        }
        $slotDefinitions=[];$configuredMinutes=(int)($department['slot_minutes']??0);$customRule=null;$customEnabled=false;
        $customConfig=json_decode((string)($department['custom_slot_config']??''),true);
        if(is_array($customConfig)&&!empty($customConfig['enabled'])&&is_array($customConfig['rules']??null)){
            $customEnabled=true;
            $dateObject=new DateTimeImmutable($date,new DateTimeZone('Asia/Bangkok'));$weekday=(int)$dateObject->format('N');$weekOfMonth=(int)ceil((int)$dateObject->format('j')/7);
            foreach($customConfig['rules'] as $rule){$weekdays=array_map('intval',(array)($rule['weekdays']??[]));$weeks=array_map('intval',(array)($rule['weeks']??[]));if(in_array($weekday,$weekdays,true)&&(!$weeks||in_array($weekOfMonth,$weeks,true))){$customRule=$rule;break;}}
        }
        // เมื่อเปิดใช้กฎ Slot ให้วันและเวลาจากกฎเป็นแหล่งข้อมูลหลักของแผนก
        // แต่ยังคงเคารพเวลาระดับสถานที่และวันหยุด/วันปิดพิเศษ
        [$intervals,$counterOverride] = effectiveIntervals($pdo,$department,$date,$customEnabled);
        $counterCount = $counterOverride ?: (int)$department['counter_count'];
        if($customRule){
            foreach((array)($customRule['periods']??[]) as $period){$startText=(string)($period['start_time']??'');$endText=(string)($period['end_time']??'');if(!preg_match('/^\d{2}:\d{2}$/',$startText)||!preg_match('/^\d{2}:\d{2}$/',$endText))continue;$start=timeToMinutes($startText);$end=timeToMinutes($endText);$customUnlimited=!empty($period['unlimited']);$capacity=$customUnlimited?1:(int)($period['capacity']??0);foreach($intervals as [$open,$close]){$from=max($start,$open);$to=min($end,$close);if($to>$from&&$capacity>0)$slotDefinitions[]=[$from,$to,$capacity,$customUnlimited];}}
        }elseif(!$customEnabled){
            foreach ($intervals as [$startMinute,$endMinute]) {
              if($configuredMinutes<1){$slotDefinitions[]=[$startMinute,$endMinute,null,false];continue;}
              $slotMinutes=max(15,min(240,$configuredMinutes));
              for($minute=$startMinute;$minute<$endMinute;$minute+=$slotMinutes)$slotDefinitions[]=[$minute,min($minute+$slotMinutes,$endMinute),null,false];
            }
        }
        $slotCount=count($slotDefinitions);if($slotCount<1)continue;$unlimited=(int)($department['unlimited_capacity']??0)===1;$dailyCapacity=(int)($department['daily_capacity']??0);if($dailyCapacity<1)$dailyCapacity=max(1,(int)($service['default_capacity']??1))*$slotCount;$baseCapacity=intdiv($dailyCapacity,$slotCount);$capacityRemainder=$dailyCapacity%$slotCount;
        foreach ($slotDefinitions as $slotIndex=>[$minute,$slotEndMinute,$customCapacity,$customUnlimited]) {
            $capacity = $customCapacity??($baseCapacity+($slotIndex<$capacityRemainder?1:0));
            // กฎ Slot แบบกำหนดเองควบคุมจำนวนรับของแต่ละช่วงโดยตรง
            // ค่าไม่จำกัดระดับแผนกใช้เฉพาะ Slot รูปแบบเดิมเท่านั้น
            $slotUnlimited=$customRule?$customUnlimited:$unlimited;
            $online = (int)floor($capacity * (int)$department['online_quota_percent'] / 100);
            $walkin = (int)floor($capacity * (int)$department['walkin_quota_percent'] / 100);
            $reserve = max(0, $capacity - $online - $walkin);
            $canRefresh=$refreshExistingCapacity?'1':'0';
            if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql="INSERT INTO slots(department_id,service_id,slot_date,start_time,end_time,capacity,online_capacity,walkin_capacity,reserve_capacity,provider_count,capacity_mode,unlimited_capacity,active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE end_time=IF($canRefresh=1 AND capacity_locked=0 AND (booked_count=0 OR VALUES(end_time)>end_time),VALUES(end_time),end_time),capacity=IF($canRefresh=1 AND booked_count=0 AND capacity_locked=0,VALUES(capacity),capacity),online_capacity=IF($canRefresh=1 AND booked_count=0 AND capacity_locked=0,VALUES(online_capacity),online_capacity),walkin_capacity=IF($canRefresh=1 AND booked_count=0 AND capacity_locked=0,VALUES(walkin_capacity),walkin_capacity),reserve_capacity=IF($canRefresh=1 AND booked_count=0 AND capacity_locked=0,VALUES(reserve_capacity),reserve_capacity),provider_count=IF($canRefresh=1 AND booked_count=0 AND capacity_locked=0,VALUES(provider_count),provider_count),capacity_mode=VALUES(capacity_mode),unlimited_capacity=VALUES(unlimited_capacity),active=1";
            else $sql="INSERT INTO slots(department_id,service_id,slot_date,start_time,end_time,capacity,online_capacity,walkin_capacity,reserve_capacity,provider_count,capacity_mode,unlimited_capacity,active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1) ON CONFLICT(department_id,service_id,slot_date,start_time) DO UPDATE SET end_time=CASE WHEN $canRefresh=1 AND slots.capacity_locked=0 AND (slots.booked_count=0 OR excluded.end_time>slots.end_time) THEN excluded.end_time ELSE slots.end_time END,capacity=CASE WHEN $canRefresh=1 AND slots.booked_count=0 AND slots.capacity_locked=0 THEN excluded.capacity ELSE slots.capacity END,online_capacity=CASE WHEN $canRefresh=1 AND slots.booked_count=0 AND slots.capacity_locked=0 THEN excluded.online_capacity ELSE slots.online_capacity END,walkin_capacity=CASE WHEN $canRefresh=1 AND slots.booked_count=0 AND slots.capacity_locked=0 THEN excluded.walkin_capacity ELSE slots.walkin_capacity END,reserve_capacity=CASE WHEN $canRefresh=1 AND slots.booked_count=0 AND slots.capacity_locked=0 THEN excluded.reserve_capacity ELSE slots.reserve_capacity END,provider_count=CASE WHEN $canRefresh=1 AND slots.booked_count=0 AND slots.capacity_locked=0 THEN excluded.provider_count ELSE slots.provider_count END,capacity_mode=excluded.capacity_mode,unlimited_capacity=excluded.unlimited_capacity,active=1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$department['id'],$service['id'],$date,minutesToTime($minute),minutesToTime($slotEndMinute),$capacity,$online,$walkin,$reserve,$counterCount,$slotUnlimited?'UNLIMITED':($customCapacity!==null?'CUSTOM_RULE':'DEPARTMENT_CONFIG'),$slotUnlimited?1:0]);
            $created += $stmt->rowCount();
        }
    }
    return $created;
}

function effectiveIntervals(PDO $pdo, array $department, string $date, bool $useSlotSchedule=false): array
{
    $weekday=(int)(new DateTimeImmutable($date,new DateTimeZone('Asia/Bangkok')))->format('N');
    if($useSlotSchedule){
        // กฎ Slot เป็นแหล่งวันและเวลาหลัก ไม่ตัดด้วยเวลาเปิดอาคาร/สาขาเดิม
        // Calendar override ยังใช้สำหรับปิดบริการและจำนวนจุดบริการประจำวัน
        [$branchOverride,$branchCounters,$hasBranchOverride]=scopeIntervalsWithOverride($pdo,'BRANCH',(int)$department['branch_id'],$date,$weekday,false);
        if($hasBranchOverride&&!$branchOverride)return [[],null];
        [$departmentOverride,$departmentCounters,$hasDepartmentOverride]=scopeIntervalsWithOverride($pdo,'DEPARTMENT',(int)$department['id'],$date,$weekday,false);
        if($hasDepartmentOverride&&!$departmentOverride)return [[],null];
        return [[[0,1440]],$departmentCounters??$branchCounters];
    }
    $building=(int)($department['building_24']??0)===1?[[0,1440]]:intervalFromTimes($department['building_open']??'00:00',$department['building_close']??'24:00');
    $branch=scopeIntervals($pdo,'BRANCH',(int)$department['branch_id'],$date,$weekday,$department['branch_open'],$department['branch_close']);
    if(!$branch)return [[],null];
    [$departmentIntervals,$counterOverride,$hasDepartmentRule]=scopeIntervalsWithOverride($pdo,'DEPARTMENT',(int)$department['id'],$date,$weekday,!$useSlotSchedule);
    if($hasDepartmentRule&& !$departmentIntervals)return [[],null];
    $result=intersectIntervals($building,$branch);
    if($hasDepartmentRule)$result=intersectIntervals($result,$departmentIntervals);
    return [array_values(array_filter($result,fn($i)=>$i[1]>$i[0])),$counterOverride];
}

function scopeIntervals(PDO $pdo,string $type,int $id,string $date,int $weekday,string $fallbackOpen,string $fallbackClose): array
{
    [$intervals,,$hasRule]=scopeIntervalsWithOverride($pdo,$type,$id,$date,$weekday);
    return $hasRule?$intervals:intervalFromTimes($fallbackOpen,$fallbackClose);
}

function scopeIntervalsWithOverride(PDO $pdo,string $type,int $id,string $date,int $weekday,bool $includeWeekly=true): array
{
    $stmt=$pdo->prepare('SELECT * FROM schedule_overrides WHERE scope_type=? AND scope_id=? AND override_date=? AND active=1');$stmt->execute([$type,$id,$date]);$override=$stmt->fetch();
    if($override){if((int)$override['is_closed']===1)return [[],isset($override['counter_count'])?(int)$override['counter_count']:null,true];return [intervalFromTimes($override['open_time'],$override['close_time']),isset($override['counter_count'])?(int)$override['counter_count']:null,true];}
    if(!$includeWeekly)return [[],null,false];
    $stmt=$pdo->prepare('SELECT open_time,close_time FROM weekly_hours WHERE scope_type=? AND scope_id=? AND weekday=? AND active=1 ORDER BY interval_order');$stmt->execute([$type,$id,$weekday]);$rows=$stmt->fetchAll();
    if(!$rows){$configured=$pdo->prepare('SELECT 1 FROM weekly_schedule_config WHERE scope_type=? AND scope_id=?');$configured->execute([$type,$id]);return [[],null,(bool)$configured->fetchColumn()];}
    return [array_map(fn($r)=>[timeToMinutes($r['open_time']),timeToMinutes($r['close_time'])],$rows),null,true];
}

function intervalFromTimes(?string $open,?string $close): array
{
    if(!$open||!$close)return [];$start=timeToMinutes($open);$end=timeToMinutes($close);return $end>$start?[[$start,$end]]:[];
}
function timeToMinutes(string $time): int { if($time==='24:00')return 1440;[$h,$m]=array_map('intval',explode(':',$time));return $h*60+$m; }
function minutesToTime(int $minutes): string { if($minutes>=1440)return '24:00';return sprintf('%02d:%02d',intdiv($minutes,60),$minutes%60); }
function intersectIntervals(array $left,array $right): array { $out=[];foreach($left as $a)foreach($right as $b){$start=max($a[0],$b[0]);$end=min($a[1],$b[1]);if($end>$start)$out[]=[$start,$end];}return $out; }

function jsonInput(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new InvalidArgumentException('รูปแบบ JSON ไม่ถูกต้อง');
    return $data;
}

function normalizeCitizenId(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function normalizePhone(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function validThaiCitizenId(string $value): bool
{
    $cid=normalizeCitizenId($value);
    if(!preg_match('/^\d{13}$/',$cid)||preg_match('/^(\d)\1{12}$/',$cid))return false;
    $sum=0;for($i=0;$i<12;$i++)$sum+=(int)$cid[$i]*(13-$i);
    return ((11-($sum%11))%10)===(int)$cid[12];
}

function identityHash(string $citizenId): string
{
    $normalized=normalizeCitizenId($citizenId);
    if(!validThaiCitizenId($normalized))throw new DomainException('เลขบัตรประชาชนไม่ถูกต้อง กรุณาตรวจสอบ 13 หลัก');
    return hash_hmac('sha256',$normalized,identityKey());
}

function identityKey(): string
{
    $keyFile=KQ_DATA . '/identity.key';
    if(!is_file($keyFile)){
        $key=bin2hex(random_bytes(32));
        if(file_put_contents($keyFile,$key,LOCK_EX)===false)throw new RuntimeException('ไม่สามารถสร้างกุญแจปกป้องข้อมูลระบุตัวตน');
        @chmod($keyFile,0600);
    }
    return trim((string)file_get_contents($keyFile));
}

function deviceDayHash(string $deviceId,string $slotDate): string
{
    $deviceId=trim($deviceId);
    if(!preg_match('/^[A-Za-z0-9_-]{16,128}$/',$deviceId))throw new DomainException('ไม่พบรหัสอุปกรณ์ กรุณาปิดและเปิด K-QOnline ใหม่');
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$slotDate))throw new DomainException('วันที่จองไม่ถูกต้อง');
    return hash_hmac('sha256',$deviceId.'|'.$slotDate,identityKey());
}

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400, array $extra = []): never
{
    respond(['ok'=>false,'error'=>$message] + $extra, $status);
}

function requireMethod(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) fail('Method not allowed', 405);
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $sessionPath = KQ_DATA . '/sessions';
    if (!is_dir($sessionPath)) mkdir($sessionPath, 0770, true);
    session_save_path($sessionPath);
    session_name('KQCENTERSESSID');
    $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'));
    $cookiePath = str_replace('\\', '/', (string)dirname($scriptDirectory));
    $cookiePath = rtrim($cookiePath, '/.');
    $cookiePath = ($cookiePath === '' ? '/' : $cookiePath . '/');
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Strict','secure'=>isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off','path'=>$cookiePath]);
    session_start();
}

function currentUser(): ?array
{
    startSecureSession();
    if (empty($_SESSION['user_id'])) return null;
    $stmt = db()->prepare('SELECT id,username,display_name,role,scope_type,scope_id FROM users WHERE id=? AND active=1');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function requireUser(): array
{
    $user = currentUser();
    if (!$user) fail('กรุณาเข้าสู่ระบบ', 401, ['code'=>'AUTH_REQUIRED']);
    return $user;
}

function csrfToken(): string
{
    startSecureSession();
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['csrf_token'];
}

function requireCsrf(): void
{
    startSecureSession();
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$provided || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], (string)$provided)) {
        fail('คำขอหมดอายุ กรุณาลองใหม่', 419, ['code'=>'CSRF_INVALID']);
    }
}

function requireAdmin(): array
{
    $user = requireUser();
    if (($user['role'] ?? '') !== 'admin') fail('ไม่มีสิทธิ์ดำเนินการ', 403);
    return $user;
}

function requireRoles(array $roles): array
{
    $user = requireUser();
    if (!in_array((string)($user['role'] ?? ''), $roles, true)) fail('ไม่มีสิทธิ์ดำเนินการ', 403);
    return $user;
}

function userScopeClause(array $user, string $departmentAlias = 'd'): array
{
    $type = strtoupper((string)($user['scope_type'] ?? 'ALL'));
    $id = (int)($user['scope_id'] ?? 0);
    if ($type === 'BRANCH' && $id > 0) return ["$departmentAlias.branch_id=?", [$id]];
    if ($type === 'DEPARTMENT' && $id > 0) return ["$departmentAlias.id=?", [$id]];
    return ['1=1', []];
}

function userCanAccessBuilding(PDO $pdo, array $user, int $buildingId): bool
{
    $type=strtoupper((string)($user['scope_type']??'ALL'));$scopeId=(int)($user['scope_id']??0);
    if($type==='ALL')return true;
    if($type==='BRANCH'){$stmt=$pdo->prepare('SELECT 1 FROM branches WHERE id=? AND building_id=?');$stmt->execute([$scopeId,$buildingId]);return (bool)$stmt->fetchColumn();}
    if($type==='DEPARTMENT'){$stmt=$pdo->prepare('SELECT 1 FROM departments d JOIN branches b ON b.id=d.branch_id WHERE d.id=? AND b.building_id=?');$stmt->execute([$scopeId,$buildingId]);return (bool)$stmt->fetchColumn();}
    return false;
}

function userCanAccessDepartment(array $user, int $departmentId, ?int $branchId = null): bool
{
    $type=strtoupper((string)($user['scope_type']??'ALL'));$scopeId=(int)($user['scope_id']??0);
    if($type==='ALL')return true;
    if($type==='DEPARTMENT')return $scopeId===$departmentId;
    return $type==='BRANCH'&&$branchId!==null&&$scopeId===$branchId;
}

function audit(?int $userId, string $action, string $entityType, ?string $entityId = null, ?array $detail = null): void
{
    $stmt = db()->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,detail,ip_address) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$userId,$action,$entityType,$entityId,$detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,$_SERVER['REMOTE_ADDR'] ?? null]);
}

function beginCapacityTransaction(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') $pdo->exec('BEGIN IMMEDIATE');
    else $pdo->beginTransaction();
}

function selectSlotForUpdate(PDO $pdo, int $slotId): array|false
{
    $sql='SELECT * FROM slots WHERE id=? AND active=1';
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$sql.=' FOR UPDATE';
    $stmt=$pdo->prepare($sql);$stmt->execute([$slotId]);return $stmt->fetch();
}

function publicBookingPayload(PDO $pdo,int $bookingId): array
{
    $stmt=$pdo->prepare('SELECT b.id,b.public_id,b.reference_no,b.status,s.slot_date,s.start_time,s.end_time,s.unlimited_capacity,s.capacity,s.booked_count,d.name department_name,sv.name service_name,br.name branch_name FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN departments d ON d.id=s.department_id JOIN branches br ON br.id=d.branch_id LEFT JOIN services sv ON sv.id=s.service_id WHERE b.id=?');
    $stmt->execute([$bookingId]);$row=$stmt->fetch();
    if(!$row)throw new DomainException('ไม่พบรายการจอง');
    $row['id']=(int)$row['id'];$row['is_unlimited']=(int)$row['unlimited_capacity']===1;
    $row['available_count']=$row['is_unlimited']?null:max(0,(int)$row['capacity']-(int)$row['booked_count']);
    unset($row['unlimited_capacity'],$row['capacity'],$row['booked_count']);
    return $row;
}

function upsertWeeklyScheduleConfig(PDO $pdo,string $type,int $scopeId): void
{
    $sql=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
        ? 'INSERT INTO weekly_schedule_config(scope_type,scope_id,updated_at) VALUES (?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE updated_at=CURRENT_TIMESTAMP'
        : 'INSERT INTO weekly_schedule_config(scope_type,scope_id,updated_at) VALUES (?,?,CURRENT_TIMESTAMP) ON CONFLICT(scope_type,scope_id) DO UPDATE SET updated_at=CURRENT_TIMESTAMP';
    $pdo->prepare($sql)->execute([$type,$scopeId]);
}

function newPublicId(): string { return bin2hex(random_bytes(16)); }

function queuePrefix(?string $configured, int $departmentId): string
{
    $prefix=mb_strtoupper(trim((string)$configured),'UTF-8');
    $prefix=preg_replace('/[^\p{L}\p{N}]/u','',$prefix)??'';
    $prefix=mb_substr($prefix,0,4,'UTF-8');
    return $prefix!==''?$prefix:chr(65+(($departmentId-1)%26));
}

function nextQueueNo(PDO $pdo, int $departmentId, string $slotDate, ?string $configuredPrefix=null): string
{
    $prefix=queuePrefix($configuredPrefix,$departmentId);
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM queue_tickets q JOIN bookings b ON b.id=q.booking_id JOIN slots s ON s.id=b.slot_id WHERE s.department_id=? AND s.slot_date=? AND q.queue_no LIKE ?');
    $stmt->execute([$departmentId,$slotDate,$prefix.'-%']);
    return sprintf('%s-%03d',$prefix,(int)$stmt->fetchColumn()+1);
}

function createQueueTicket(PDO $pdo, array $booking, ?string $configuredPrefix=null): array
{
    $existing=$pdo->prepare('SELECT * FROM queue_tickets WHERE booking_id=?');$existing->execute([$booking['id']]);
    if($ticket=$existing->fetch())return queueTicketDetail($pdo,(int)$ticket['id']);
    $slot=$pdo->prepare('SELECT s.department_id,s.slot_date,d.code department_code FROM slots s JOIN departments d ON d.id=s.department_id WHERE s.id=?');$slot->execute([$booking['slot_id']]);$slotRow=$slot->fetch();$departmentId=(int)($slotRow['department_id']??0);
    if($departmentId<1)throw new DomainException('ไม่พบแผนกของรายการนี้');
    if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){$departmentLock=$pdo->prepare('SELECT id FROM departments WHERE id=? FOR UPDATE');$departmentLock->execute([$departmentId]);}
    $publicId=newPublicId();$queueNo=nextQueueNo($pdo,$departmentId,(string)$slotRow['slot_date'],(string)$slotRow['department_code']);
    $waiting=$pdo->prepare("SELECT COUNT(*) FROM queue_tickets q JOIN bookings b ON b.id=q.booking_id JOIN slots s ON s.id=b.slot_id WHERE s.department_id=? AND q.status IN ('WAITING','CALLED')");$waiting->execute([$departmentId]);
    $estimated=(int)$waiting->fetchColumn()*10;
    $stmt=$pdo->prepare("INSERT INTO queue_tickets(public_id,booking_id,queue_no,source,status,current_step,checked_in_at,estimated_wait_minutes) VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP,?)");
    $stmt->execute([$publicId,$booking['id'],$queueNo,$booking['source'],'WAITING','REGISTRATION_WAITING',$estimated]);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO queue_events(queue_ticket_id,event_type,status,step,note) VALUES (?,?,?,?,?)")->execute([$id,'CHECKED_IN','WAITING','REGISTRATION_WAITING','ยืนยันการมาถึงและเข้าคิวแล้ว']);
    $pdo->prepare("UPDATE bookings SET status='CHECKED_IN',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$booking['id']]);
    return queueTicketDetail($pdo,$id);
}

function queueTicketDetail(PDO $pdo,int $ticketId): array
{
    $stmt=$pdo->prepare('SELECT q.*,b.reference_no,s.slot_date,s.start_time,s.end_time,d.name department_name,sv.name service_name,br.name branch_name FROM queue_tickets q JOIN bookings b ON b.id=q.booking_id JOIN slots s ON s.id=b.slot_id JOIN departments d ON d.id=s.department_id JOIN branches br ON br.id=d.branch_id LEFT JOIN services sv ON sv.id=s.service_id WHERE q.id=?');$stmt->execute([$ticketId]);$ticket=$stmt->fetch();if(!$ticket)throw new DomainException('ไม่พบข้อมูลบัตรคิว');return $ticket;
}

function maskThaiName(string $name): string
{
    $parts=preg_split('/\s+/u',trim($name))?:[];
    return implode(' ',array_map(function($part){$length=mb_strlen($part);return $length<2?'*':mb_substr($part,0,1).str_repeat('•',min(5,$length-1));},$parts));
}

