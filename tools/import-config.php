<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$inputPath = $argv[1] ?? '';
if ($inputPath === '' || !is_file($inputPath)) {
    fwrite(STDERR, "Usage: php tools/import-config.php <kqcenter-config.json>\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
$databasePath = getenv('KQCENTER_DB_PATH') ?: $projectRoot . '/data/kqcenter.sqlite';
$payload = json_decode((string)file_get_contents($inputPath), true, 512, JSON_THROW_ON_ERROR);
if (($payload['format'] ?? '') !== 'k-qcenter-config-v1' || !is_array($payload['tables'] ?? null)) {
    throw new RuntimeException('รูปแบบไฟล์ตั้งค่าไม่ถูกต้อง');
}

$tables = [
    'buildings' => ['id','code','name','is_24_hours','open_time','close_time','active','created_at','updated_at'],
    'branches' => ['id','building_id','code','name','open_time','close_time','closing_buffer_minutes','active','created_at','updated_at'],
    'departments' => ['id','branch_id','code','name','counter_count','efficiency','online_quota_percent','walkin_quota_percent','reserve_quota_percent','daily_capacity','unlimited_capacity','slot_minutes','custom_slot_config','active'],
    'services' => ['id','department_id','code','name','average_minutes','preparation_minutes','slot_minutes','default_capacity','active'],
    'kiosk_templates' => ['id','building_id','code','name','location_label','active','created_at','updated_at'],
    'kiosk_menu_items' => ['id','template_id','parent_id','department_id','service_id','label','subtitle','icon','sort_order','start_time','end_time','schedule_json','active','created_at','updated_at'],
    'calendar_overrides' => ['id','branch_id','override_date','name','is_closed','open_time','close_time','note','active','created_at','updated_at'],
    'weekly_hours' => ['id','scope_type','scope_id','weekday','interval_order','open_time','close_time','active'],
    'weekly_schedule_config' => ['scope_type','scope_id','updated_at'],
    'schedule_overrides' => ['id','scope_type','scope_id','override_date','name','is_closed','open_time','close_time','counter_count','note','active','created_at','updated_at'],
];

foreach ($tables as $table => $_columns) {
    if (!is_array($payload['tables'][$table] ?? null)) {
        throw new RuntimeException("ไม่มีข้อมูลตาราง {$table}");
    }
}

if (!is_file($databasePath)) {
    throw new RuntimeException("ไม่พบฐานข้อมูล local: {$databasePath}");
}

$backupPath = $databasePath . '.before-config-import-' . gmdate('Ymd-His') . '.bak';
if (!copy($databasePath, $backupPath)) {
    throw new RuntimeException('สำรองฐานข้อมูล local ไม่สำเร็จ');
}

$pdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA foreign_keys = ON');
$kioskColumns = array_column($pdo->query('PRAGMA table_info(kiosk_menu_items)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('schedule_json', $kioskColumns, true)) {
    $pdo->exec('ALTER TABLE kiosk_menu_items ADD COLUMN schedule_json TEXT');
}
$serviceColumns = array_column($pdo->query('PRAGMA table_info(services)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('slot_minutes', $serviceColumns, true)) $pdo->exec('ALTER TABLE services ADD COLUMN slot_minutes INTEGER NOT NULL DEFAULT 30');
if (!in_array('default_capacity', $serviceColumns, true)) $pdo->exec('ALTER TABLE services ADD COLUMN default_capacity INTEGER NOT NULL DEFAULT 1');
$departmentColumns = array_column($pdo->query('PRAGMA table_info(departments)')->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('daily_capacity', $departmentColumns, true)) $pdo->exec('ALTER TABLE departments ADD COLUMN daily_capacity INTEGER');
if (!in_array('slot_minutes', $departmentColumns, true)) $pdo->exec('ALTER TABLE departments ADD COLUMN slot_minutes INTEGER');
if (!in_array('custom_slot_config', $departmentColumns, true)) $pdo->exec('ALTER TABLE departments ADD COLUMN custom_slot_config TEXT');
$pdo->beginTransaction();

try {
    foreach (['queue_events','queue_tickets','bookings','slots','kiosk_menu_items','schedule_overrides','weekly_schedule_config','weekly_hours','calendar_overrides','kiosk_templates','services','departments','branches','buildings'] as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }

    foreach ($tables as $table => $columns) {
        $columnSql = implode(',', $columns);
        $placeholderSql = implode(',', array_fill(0, count($columns), '?'));
        $statement = $pdo->prepare("INSERT INTO {$table} ({$columnSql}) VALUES ({$placeholderSql})");
        foreach ($payload['tables'][$table] as $row) {
            $values=array_map(static function(string $column)use($row,$table){if(array_key_exists($column,$row))return $row[$column];if($table==='services'&&$column==='slot_minutes')return 30;if($table==='services'&&$column==='default_capacity')return 1;return null;},$columns);
            $statement->execute($values);
        }
    }

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

$counts = [];
foreach (array_keys($tables) as $table) {
    $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
}

echo json_encode([
    'ok' => true,
    'database' => $databasePath,
    'backup' => $backupPath,
    'exported_at' => $payload['exported_at'] ?? null,
    'counts' => $counts,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
