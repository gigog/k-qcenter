<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--confirm-all-queue-data') {
    fwrite(STDERR, "Usage: php tools/reset-queue-data.php --confirm-all-queue-data\n");
    exit(2);
}
require __DIR__ . '/../api/bootstrap.php';
$pdo=db();
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    fwrite(STDERR, "Refusing reset: production MariaDB connection is not active.\n");
    exit(3);
}
$tables=['hosxp_visit_links','queue_events','queue_tickets','bookings'];
$before=[];
foreach($tables as $table)$before[$table]=(int)$pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
$pdo->beginTransaction();
try {
    foreach($tables as $table)$pdo->exec("DELETE FROM $table");
    $pdo->exec('UPDATE slots SET booked_count=0');
    $pdo->commit();
} catch(Throwable $e) {
    $pdo->rollBack();
    throw $e;
}
echo json_encode(['ok'=>true,'deleted'=>$before,'slot_booked_count_reset'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;
