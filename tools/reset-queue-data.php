<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--confirm-all-queue-data') {
    fwrite(STDERR, "Usage: php tools/reset-queue-data.php --confirm-all-queue-data [--allow-local-sqlite]\n");
    exit(2);
}
require __DIR__ . '/../api/bootstrap.php';
$pdo=db();
$driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
if ($driver !== 'mysql' && ($argv[2] ?? '') !== '--allow-local-sqlite') {
    fwrite(STDERR, "Refusing reset: add --allow-local-sqlite to explicitly reset the local SQLite database.\n");
    exit(3);
}
if($driver==='sqlite'){
    $source=KQ_DATA.'/kqcenter.sqlite';$backupDir=KQ_DATA.'/backups';
    if(!is_dir($backupDir)&&!mkdir($backupDir,0770,true)&&!is_dir($backupDir))throw new RuntimeException('Cannot create backup directory');
    $backup=$backupDir.'/kqcenter-before-queue-reset-'.gmdate('Ymd-His').'.sqlite';
    if(!is_file($source)||!copy($source,$backup))throw new RuntimeException('Cannot back up the local database');
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
echo json_encode(['ok'=>true,'database'=>$driver,'deleted'=>$before,'slot_booked_count_reset'=>true,'backup'=>$backup??null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;
