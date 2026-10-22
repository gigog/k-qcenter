<?php
declare(strict_types=1);

$testRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kqcenter-slot-' . bin2hex(random_bytes(5));
mkdir($testRoot, 0700, true);
putenv('KQCENTER_DATA_PATH=' . $testRoot);
putenv('KQCENTER_DB_PATH=' . $testRoot . DIRECTORY_SEPARATOR . 'test.sqlite');
require dirname(__DIR__) . '/api/bootstrap.php';

$pdo = db();
$department = $pdo->query('SELECT id,branch_id FROM departments ORDER BY id LIMIT 1')->fetch();
$pdo->prepare("UPDATE branches SET open_time='07:00',close_time='16:00' WHERE id=?")->execute([$department['branch_id']]);
$config = ['enabled' => true, 'rules' => [[
    'name' => 'Friday weeks 2-4', 'weekdays' => [5], 'weeks' => [2,3,4],
    'periods' => [
        ['start_time' => '07:30', 'end_time' => '08:30', 'capacity' => 40],
        ['start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 20],
    ],
]]];
$pdo->prepare('UPDATE departments SET custom_slot_config=? WHERE id=?')->execute([json_encode($config), $department['id']]);

generateSlots($pdo, '2026-10-09');
$statement = $pdo->prepare('SELECT start_time,end_time,capacity,capacity_mode FROM slots WHERE slot_date=? AND department_id=? AND active=1 ORDER BY start_time');
$statement->execute(['2026-10-09', $department['id']]);
$matching = $statement->fetchAll();
if (count($matching) !== 2 || (int)$matching[0]['capacity'] !== 40 || (int)$matching[1]['capacity'] !== 20 || $matching[0]['capacity_mode'] !== 'CUSTOM_RULE') throw new RuntimeException('Matching rule did not create the expected slots');

generateSlots($pdo, '2026-10-02');
$statement->execute(['2026-10-02', $department['id']]);
if ($statement->fetchAll()) throw new RuntimeException('Non-matching week unexpectedly created slots');

$pdo->prepare("INSERT INTO schedule_overrides(scope_type,scope_id,override_date,name,is_closed,active) VALUES ('BRANCH',?,'2026-10-16','Holiday',1,1)")->execute([$department['branch_id']]);
generateSlots($pdo, '2026-10-16');
$statement->execute(['2026-10-16', $department['id']]);
if ($statement->fetchAll()) throw new RuntimeException('Holiday unexpectedly created slots');

echo "Custom slot smoke test passed\n";
