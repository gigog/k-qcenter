<?php
declare(strict_types=1);

// Builds a MariaDB SQL file that pushes configuration from the local SQLite
// database to production. Users, bookings, queue tickets, queue events, slots
// and audit logs are never touched. Referenced tables are upserted by id and
// rows missing locally are deactivated instead of deleted.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
$databasePath = getenv('KQCENTER_DB_PATH') ?: $projectRoot . '/data/kqcenter.sqlite';
$outputPath = $argv[1] ?? $projectRoot . '/data/exports/kqcenter-config-' . gmdate('Ymd-His') . '.sql';

if (!is_file($databasePath)) {
    fwrite(STDERR, "ไม่พบฐานข้อมูล local: {$databasePath}\n");
    exit(1);
}

// Tables referenced by slots/bookings: upsert by id, deactivate missing rows.
$referenced = [
    'buildings' => ['id','code','name','is_24_hours','open_time','close_time','active','created_at','updated_at'],
    'branches' => ['id','building_id','code','name','open_time','close_time','closing_buffer_minutes','active','created_at','updated_at'],
    'departments' => ['id','branch_id','code','name','counter_count','efficiency','online_quota_percent','walkin_quota_percent','reserve_quota_percent','daily_capacity','unlimited_capacity','slot_minutes','custom_slot_config','active'],
    'services' => ['id','department_id','code','name','average_minutes','preparation_minutes','slot_minutes','default_capacity','active'],
    'kiosk_templates' => ['id','building_id','code','name','location_label','active','created_at','updated_at'],
];
// Unique business keys checked before any change, so a code reused under a
// different id aborts the whole sync instead of silently merging rows.
$uniqueKeys = [
    'buildings' => ['code'],
    'branches' => ['code'],
    'departments' => ['branch_id','code'],
    'services' => ['code'],
    'kiosk_templates' => ['code'],
];
// Pure configuration tables: replaced wholesale.
$replaced = [
    'calendar_overrides' => ['id','branch_id','override_date','name','is_closed','open_time','close_time','note','active','created_at','updated_at'],
    'weekly_hours' => ['id','scope_type','scope_id','weekday','interval_order','open_time','close_time','active'],
    'weekly_schedule_config' => ['scope_type','scope_id','updated_at'],
    'schedule_overrides' => ['id','scope_type','scope_id','override_date','name','is_closed','open_time','close_time','counter_count','note','active','created_at','updated_at'],
    'kiosk_menu_items' => ['id','template_id','parent_id','department_id','service_id','label','subtitle','icon','sort_order','start_time','end_time','schedule_json','active','created_at','updated_at'],
];

$pdo = new PDO('sqlite:' . $databasePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function q(mixed $value): string
{
    if ($value === null) return 'NULL';
    return "'" . strtr((string)$value, ["\\" => "\\\\", "'" => "\\'", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "\x1a" => "\\Z"]) . "'";
}

function rows(PDO $pdo, string $table, array $columns): array
{
    $order = $table === 'kiosk_menu_items' ? 'ORDER BY parent_id IS NOT NULL, id' : (in_array('id', $columns, true) ? 'ORDER BY id' : '');
    return $pdo->query('SELECT ' . implode(',', $columns) . " FROM {$table} {$order}")->fetchAll();
}

function insertSql(string $table, array $columns, array $row, bool $upsert): string
{
    $sql = "INSERT INTO `{$table}` (" . implode(',', array_map(fn($c) => "`{$c}`", $columns)) . ') VALUES ('
        . implode(',', array_map(fn($c) => q($row[$c] ?? null), $columns)) . ')';
    if ($upsert) {
        $updates = array_map(fn($c) => "`{$c}`=VALUES(`{$c}`)", array_filter($columns, fn($c) => $c !== 'id'));
        $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates);
    }
    return $sql . ';';
}

$data = [];
foreach ($referenced + $replaced as $table => $columns) $data[$table] = rows($pdo, $table, $columns);

$out = [];
$out[] = '-- K-QCENTER configuration sync generated ' . gmdate('Y-m-d\TH:i:s\Z') . ' from ' . basename($databasePath);
$out[] = '-- Excludes users, bookings, queue tickets, queue events, slots and audit logs.';
$out[] = "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;";
$out[] = "SET time_zone = '+00:00';";
$out[] = '';
$out[] = '-- Preflight: schema must be deployed and no business code may belong to a different id.';
$out[] = 'DELIMITER //';
$checks = [];
foreach ([['departments','daily_capacity'],['departments','slot_minutes'],['departments','custom_slot_config'],['services','default_capacity'],['kiosk_menu_items','schedule_json']] as [$table, $column]) {
    $checks[] = "  IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='{$table}' AND column_name='{$column}') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Schema is outdated: deploy the latest code and open the API once before syncing'; END IF;";
}
foreach ($uniqueKeys as $table => $keys) {
    foreach ($data[$table] as $row) {
        $match = implode(' AND ', array_map(fn($k) => "`{$k}`=" . q($row[$k]), $keys));
        $label = addslashes("{$table} " . implode('/', array_map(fn($k) => (string)$row[$k], $keys)));
        $checks[] = "  IF EXISTS (SELECT 1 FROM `{$table}` WHERE {$match} AND id<>" . (int)$row['id'] . ") THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Code conflict with a different id: {$label}'; END IF;";
    }
}
$out[] = 'BEGIN NOT ATOMIC';
array_push($out, ...$checks);
$out[] = 'END //';
$out[] = 'DELIMITER ;';
$out[] = '';
$out[] = 'START TRANSACTION;';
foreach ($referenced as $table => $columns) {
    $out[] = "-- {$table}";
    foreach ($data[$table] as $row) $out[] = insertSql($table, $columns, $row, true);
    $ids = array_map(fn($row) => (int)$row['id'], $data[$table]);
    $out[] = "UPDATE `{$table}` SET active=0 WHERE " . ($ids ? 'id NOT IN (' . implode(',', $ids) . ')' : '1=1') . ';';
}
foreach (array_reverse(array_keys($replaced)) as $table) $out[] = "DELETE FROM `{$table}`;";
foreach ($replaced as $table => $columns) {
    $out[] = "-- {$table}";
    foreach ($data[$table] as $row) $out[] = insertSql($table, $columns, $row, false);
}
$out[] = 'COMMIT;';
$out[] = '';
$out[] = "SELECT 'K-QCENTER configuration sync committed' AS result;";

if (!is_dir(dirname($outputPath)) && !mkdir(dirname($outputPath), 0770, true)) {
    fwrite(STDERR, "สร้างโฟลเดอร์ปลายทางไม่สำเร็จ\n");
    exit(1);
}
file_put_contents($outputPath, implode("\n", $out) . "\n");

$counts = [];
foreach ($data as $table => $tableRows) $counts[$table] = count($tableRows);
echo json_encode(['ok' => true, 'output' => $outputPath, 'counts' => $counts], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
