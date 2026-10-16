<?php
declare(strict_types=1);

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
    $pdo = new PDO('sqlite:' . KQ_DB, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  display_name TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'admin',
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
  active INTEGER NOT NULL DEFAULT 1
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
CREATE INDEX IF NOT EXISTS idx_slots_date ON slots(slot_date, department_id);
CREATE INDEX IF NOT EXISTS idx_calendar_date ON calendar_overrides(override_date, branch_id);
CREATE INDEX IF NOT EXISTS idx_weekly_scope ON weekly_hours(scope_type, scope_id, weekday);
CREATE INDEX IF NOT EXISTS idx_schedule_override ON schedule_overrides(scope_type, scope_id, override_date);
CREATE INDEX IF NOT EXISTS idx_bookings_slot_status ON bookings(slot_id, status);
CREATE INDEX IF NOT EXISTS idx_queue_status ON queue_tickets(status, created_at);
CREATE INDEX IF NOT EXISTS idx_queue_events_ticket ON queue_events(queue_ticket_id, created_at);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at);
SQL);

    ensureColumn($pdo, 'branches', 'building_id', 'INTEGER REFERENCES buildings(id)');
    if ((int)$pdo->query('SELECT COUNT(*) FROM buildings')->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO buildings(code,name,is_24_hours,active) VALUES ('MAIN-BLDG','อาคารหลัก',1,1)");
    }
    $defaultBuilding = (int)$pdo->query('SELECT id FROM buildings ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->prepare('UPDATE branches SET building_id=? WHERE building_id IS NULL')->execute([$defaultBuilding]);

    $count = (int)$pdo->query('SELECT COUNT(*) FROM branches')->fetchColumn();
    if ($count === 0) seed($pdo);
}

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
{
    $columns = $pdo->query("PRAGMA table_info($table)")->fetchAll();
    foreach ($columns as $item) if ($item['name'] === $column) return;
    $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
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
    $pdo->prepare('DELETE FROM slots WHERE slot_date=? AND booked_count=0')->execute([$date]);
    $departments = $pdo->query('SELECT d.*,b.open_time branch_open,b.close_time branch_close,b.closing_buffer_minutes,b.building_id,g.is_24_hours building_24,g.open_time building_open,g.close_time building_close FROM departments d JOIN branches b ON b.id=d.branch_id LEFT JOIN buildings g ON g.id=b.building_id WHERE d.active=1 AND b.active=1 AND (g.id IS NULL OR g.active=1)')->fetchAll();
    $created = 0;
    foreach ($departments as $department) {
        $serviceStmt = $pdo->prepare('SELECT * FROM services WHERE department_id=? AND active=1 ORDER BY id LIMIT 1');
        $serviceStmt->execute([$department['id']]);
        $service = $serviceStmt->fetch();
        if (!$service) continue;
        [$intervals,$counterOverride] = effectiveIntervals($pdo,$department,$date);
        $counterCount = $counterOverride ?: (int)$department['counter_count'];
        foreach ($intervals as [$startMinute,$endMinute]) {
          for ($minute=$startMinute; $minute+30<=$endMinute; $minute+=30) {
            $effective = max(1, (int)$service['average_minutes'] + (int)$service['preparation_minutes']);
            $capacity = max(1, (int)floor(30 * $counterCount * (float)$department['efficiency'] / $effective));
            $online = (int)floor($capacity * (int)$department['online_quota_percent'] / 100);
            $walkin = (int)floor($capacity * (int)$department['walkin_quota_percent'] / 100);
            $reserve = max(0, $capacity - $online - $walkin);
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO slots(department_id,service_id,slot_date,start_time,end_time,capacity,online_capacity,walkin_capacity,reserve_capacity) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$department['id'],$service['id'],$date,minutesToTime($minute),minutesToTime($minute+30),$capacity,$online,$walkin,$reserve]);
            $created += $stmt->rowCount();
          }
        }
    }
    return $created;
}

function effectiveIntervals(PDO $pdo, array $department, string $date): array
{
    $weekday=(int)(new DateTimeImmutable($date,new DateTimeZone('Asia/Bangkok')))->format('N');
    $building=(int)($department['building_24']??0)===1?[[0,1440]]:intervalFromTimes($department['building_open']??'00:00',$department['building_close']??'24:00');
    $branch=scopeIntervals($pdo,'BRANCH',(int)$department['branch_id'],$date,$weekday,$department['branch_open'],$department['branch_close']);
    if(!$branch)return [[],null];
    [$departmentIntervals,$counterOverride,$hasDepartmentRule]=scopeIntervalsWithOverride($pdo,'DEPARTMENT',(int)$department['id'],$date,$weekday);
    if($hasDepartmentRule&& !$departmentIntervals)return [[],null];
    $result=intersectIntervals($building,$branch);
    if($hasDepartmentRule)$result=intersectIntervals($result,$departmentIntervals);
    if($result){$last=count($result)-1;$result[$last][1]=max($result[$last][0],$result[$last][1]-(int)$department['closing_buffer_minutes']);}
    return [array_values(array_filter($result,fn($i)=>$i[1]>$i[0])),$counterOverride];
}

function scopeIntervals(PDO $pdo,string $type,int $id,string $date,int $weekday,string $fallbackOpen,string $fallbackClose): array
{
    [$intervals,,$hasRule]=scopeIntervalsWithOverride($pdo,$type,$id,$date,$weekday);
    return $hasRule?$intervals:intervalFromTimes($fallbackOpen,$fallbackClose);
}

function scopeIntervalsWithOverride(PDO $pdo,string $type,int $id,string $date,int $weekday): array
{
    $stmt=$pdo->prepare('SELECT * FROM schedule_overrides WHERE scope_type=? AND scope_id=? AND override_date=? AND active=1');$stmt->execute([$type,$id,$date]);$override=$stmt->fetch();
    if($override){if((int)$override['is_closed']===1)return [[],isset($override['counter_count'])?(int)$override['counter_count']:null,true];return [intervalFromTimes($override['open_time'],$override['close_time']),isset($override['counter_count'])?(int)$override['counter_count']:null,true];}
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
    $stmt = db()->prepare('SELECT id,username,display_name,role FROM users WHERE id=? AND active=1');
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

function audit(?int $userId, string $action, string $entityType, ?string $entityId = null, ?array $detail = null): void
{
    $stmt = db()->prepare('INSERT INTO audit_logs(user_id,action,entity_type,entity_id,detail,ip_address) VALUES (?,?,?,?,?,?)');
    $stmt->execute([$userId,$action,$entityType,$entityId,$detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,$_SERVER['REMOTE_ADDR'] ?? null]);
}

function newPublicId(): string { return bin2hex(random_bytes(16)); }

function nextQueueNo(PDO $pdo, int $departmentId, string $slotDate): string
{
    $prefix = chr(65 + (($departmentId - 1) % 26));
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM queue_tickets q JOIN bookings b ON b.id=q.booking_id JOIN slots s ON s.id=b.slot_id WHERE s.department_id=? AND s.slot_date=?');
    $stmt->execute([$departmentId,$slotDate]);
    return sprintf('%s-%03d',$prefix,(int)$stmt->fetchColumn()+1);
}

function createQueueTicket(PDO $pdo, array $booking): array
{
    $existing=$pdo->prepare('SELECT * FROM queue_tickets WHERE booking_id=?');$existing->execute([$booking['id']]);
    if($ticket=$existing->fetch())return $ticket;
    $slot=$pdo->prepare('SELECT department_id,slot_date FROM slots WHERE id=?');$slot->execute([$booking['slot_id']]);$slotRow=$slot->fetch();$departmentId=(int)($slotRow['department_id']??0);
    if($departmentId<1)throw new DomainException('ไม่พบแผนกของรายการนี้');
    $publicId=newPublicId();$queueNo=nextQueueNo($pdo,$departmentId,(string)$slotRow['slot_date']);
    $waiting=$pdo->prepare("SELECT COUNT(*) FROM queue_tickets q JOIN bookings b ON b.id=q.booking_id JOIN slots s ON s.id=b.slot_id WHERE s.department_id=? AND q.status IN ('WAITING','CALLED')");$waiting->execute([$departmentId]);
    $estimated=(int)$waiting->fetchColumn()*10;
    $stmt=$pdo->prepare("INSERT INTO queue_tickets(public_id,booking_id,queue_no,source,status,current_step,checked_in_at,estimated_wait_minutes) VALUES (?,?,?,?,?,?,datetime('now'),?)");
    $stmt->execute([$publicId,$booking['id'],$queueNo,$booking['source'],'WAITING','REGISTRATION_WAITING',$estimated]);
    $id=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO queue_events(queue_ticket_id,event_type,status,step,note) VALUES (?,?,?,?,?)")->execute([$id,'CHECKED_IN','WAITING','REGISTRATION_WAITING','ยืนยันการมาถึงและเข้าคิวแล้ว']);
    $pdo->prepare("UPDATE bookings SET status='CHECKED_IN',updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$booking['id']]);
    $stmt=$pdo->prepare('SELECT * FROM queue_tickets WHERE id=?');$stmt->execute([$id]);return $stmt->fetch();
}

function maskThaiName(string $name): string
{
    $parts=preg_split('/\s+/u',trim($name))?:[];
    return implode(' ',array_map(function($part){$length=mb_strlen($part);return $length<2?'*':mb_substr($part,0,1).str_repeat('•',min(5,$length-1));},$parts));
}

