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
CREATE INDEX IF NOT EXISTS idx_bookings_slot_status ON bookings(slot_id, status);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at);
SQL);

    $count = (int)$pdo->query('SELECT COUNT(*) FROM branches')->fetchColumn();
    if ($count === 0) seed($pdo);
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
    $departments = $pdo->query('SELECT d.*, b.open_time, b.close_time, b.closing_buffer_minutes FROM departments d JOIN branches b ON b.id=d.branch_id WHERE d.active=1 AND b.active=1')->fetchAll();
    $created = 0;
    foreach ($departments as $department) {
        $overrideStmt = $pdo->prepare('SELECT * FROM calendar_overrides WHERE branch_id=? AND override_date=? AND active=1');
        $overrideStmt->execute([$department['branch_id'], $date]);
        $override = $overrideStmt->fetch();
        if ($override && (int)$override['is_closed'] === 1) continue;
        $serviceStmt = $pdo->prepare('SELECT * FROM services WHERE department_id=? AND active=1 ORDER BY id LIMIT 1');
        $serviceStmt->execute([$department['id']]);
        $service = $serviceStmt->fetch();
        if (!$service) continue;
        $openTime = ($override && $override['open_time']) ? $override['open_time'] : $department['open_time'];
        $closeTime = ($override && $override['close_time']) ? $override['close_time'] : $department['close_time'];
        $start = new DateTimeImmutable("$date $openTime", new DateTimeZone('Asia/Bangkok'));
        $close = new DateTimeImmutable("$date $closeTime", new DateTimeZone('Asia/Bangkok'));
        $close = $close->modify('-'.(int)$department['closing_buffer_minutes'].' minutes');
        while ($start->modify('+30 minutes') <= $close) {
            $end = $start->modify('+30 minutes');
            $effective = max(1, (int)$service['average_minutes'] + (int)$service['preparation_minutes']);
            $capacity = max(1, (int)floor(30 * (int)$department['counter_count'] * (float)$department['efficiency'] / $effective));
            $online = (int)floor($capacity * (int)$department['online_quota_percent'] / 100);
            $walkin = (int)floor($capacity * (int)$department['walkin_quota_percent'] / 100);
            $reserve = max(0, $capacity - $online - $walkin);
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO slots(department_id,service_id,slot_date,start_time,end_time,capacity,online_capacity,walkin_capacity,reserve_capacity) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$department['id'],$service['id'],$date,$start->format('H:i'),$end->format('H:i'),$capacity,$online,$walkin,$reserve]);
            $created += $stmt->rowCount();
            $start = $end;
        }
    }
    return $created;
}

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

