<?php
declare(strict_types=1);

function hosxpEnabled(): bool
{
    return trim((string)getenv('KQCENTER_HOSXP_DSN')) !== '';
}

function hosxpDb(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;
    $dsn=trim((string)getenv('KQCENTER_HOSXP_DSN'));
    if ($dsn==='') throw new RuntimeException('ยังไม่ได้ตั้งค่าการเชื่อมต่อ HOSxP');
    $pdo=new PDO($dsn,(string)getenv('KQCENTER_HOSXP_USER'),(string)getenv('KQCENTER_HOSXP_PASSWORD'),[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_TIMEOUT=>3,
    ]);
    $pdo->exec("SET NAMES utf8mb4");
    return $pdo;
}

function hosxpSecret(): string
{
    $secret=(string)getenv('KQCENTER_IDENTITY_KEY');
    if(strlen($secret)<32)throw new RuntimeException('KQCENTER_IDENTITY_KEY must contain at least 32 characters');
    return hash('sha256',$secret,true);
}

function hosxpEncrypt(string $value): string
{
    if($value==='')return '';$iv=random_bytes(12);$tag='';
    $cipher=openssl_encrypt($value,'aes-256-gcm',hosxpSecret(),OPENSSL_RAW_DATA,$iv,$tag);
    if($cipher===false)throw new RuntimeException('ไม่สามารถเข้ารหัสข้อมูลอ้างอิง HOSxP');
    return base64_encode($iv.$tag.$cipher);
}

function hosxpDecrypt(?string $value): string
{
    if(!$value)return '';$raw=base64_decode($value,true);if($raw===false||strlen($raw)<29)return '';
    $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',hosxpSecret(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
    return $plain===false?'':$plain;
}

function hosxpPatientByCitizen(string $citizenId): ?array
{
    $cid=preg_replace('/\D/','',$citizenId)??'';if(strlen($cid)!==13)return null;
    $stmt=hosxpDb()->prepare('SELECT hn FROM patient WHERE REPLACE(cid,\'-\',\'\')=? LIMIT 1');$stmt->execute([$cid]);
    $patient=$stmt->fetch();if(!$patient)return null;
    return ['hn'=>(string)$patient['hn']];
}

function hosxpVisitByHn(string $hn,string $date): ?array
{
    $sql="SELECT o.vn,o.vstdate,o.vsttime,o.cur_dep,o.main_dep,o.ovstost,COALESCE(k.department,'') department_name FROM ovst o LEFT JOIN kskdepartment k ON k.depcode=COALESCE(NULLIF(o.cur_dep,''),o.main_dep) WHERE o.hn=? AND o.vstdate=? ORDER BY o.vsttime DESC LIMIT 1";
    $stmt=hosxpDb()->prepare($sql);$stmt->execute([$hn,$date]);$row=$stmt->fetch();return $row?:null;
}

function hosxpSaveLink(PDO $pdo,int $ticketId,string $hn,?array $visit,string $syncStatus,?string $error=null): void
{
    $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$hnHash=hash_hmac('sha256',$hn,hosxpSecret());$hnCipher=hosxpEncrypt($hn);$vnCipher=$visit?hosxpEncrypt((string)$visit['vn']):null;
    $values=[$ticketId,$hnHash,$hnCipher,$vnCipher,$visit['vstdate']??null,$visit['vsttime']??null,$visit['department_name']??null,$visit['cur_dep']??null,$visit['ovstost']??null,$syncStatus,$error];
    if($driver==='mysql')$sql='INSERT INTO hosxp_visit_links(queue_ticket_id,hn_hash,hn_ciphertext,vn_ciphertext,visit_date,visit_time,current_department,current_department_code,raw_status,sync_status,last_error,last_synced_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE hn_hash=VALUES(hn_hash),hn_ciphertext=VALUES(hn_ciphertext),vn_ciphertext=VALUES(vn_ciphertext),visit_date=VALUES(visit_date),visit_time=VALUES(visit_time),current_department=VALUES(current_department),current_department_code=VALUES(current_department_code),raw_status=VALUES(raw_status),sync_status=VALUES(sync_status),last_error=VALUES(last_error),last_synced_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP';
    else $sql='INSERT INTO hosxp_visit_links(queue_ticket_id,hn_hash,hn_ciphertext,vn_ciphertext,visit_date,visit_time,current_department,current_department_code,raw_status,sync_status,last_error,last_synced_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP) ON CONFLICT(queue_ticket_id) DO UPDATE SET hn_hash=excluded.hn_hash,hn_ciphertext=excluded.hn_ciphertext,vn_ciphertext=excluded.vn_ciphertext,visit_date=excluded.visit_date,visit_time=excluded.visit_time,current_department=excluded.current_department,current_department_code=excluded.current_department_code,raw_status=excluded.raw_status,sync_status=excluded.sync_status,last_error=excluded.last_error,last_synced_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP';
    $pdo->prepare($sql)->execute($values);
}

function hosxpLinkTicketByCitizen(PDO $pdo,int $ticketId,string $citizenId): array
{
    if(!hosxpEnabled())return ['enabled'=>false,'sync_status'=>'DISABLED'];
    try{$patient=hosxpPatientByCitizen($citizenId);if(!$patient)return ['enabled'=>true,'sync_status'=>'PATIENT_NOT_FOUND'];$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');$visit=hosxpVisitByHn($patient['hn'],$today);$status=$visit?'VISIT_FOUND':'WAITING_FOR_VISIT';hosxpSaveLink($pdo,$ticketId,$patient['hn'],$visit,$status);return ['enabled'=>true,'sync_status'=>$status];}
    catch(Throwable $e){error_log('HOSxP link error: '.$e->getMessage());return ['enabled'=>true,'sync_status'=>'ERROR'];}
}

function hosxpStatusForTicket(PDO $pdo,int $ticketId,bool $refresh=true): array
{
    if(!hosxpEnabled())return ['enabled'=>false,'sync_status'=>'DISABLED'];
    $stmt=$pdo->prepare('SELECT * FROM hosxp_visit_links WHERE queue_ticket_id=?');$stmt->execute([$ticketId]);$link=$stmt->fetch();
    if(!$link)return ['enabled'=>true,'sync_status'=>'NOT_LINKED','visit_found'=>false];
    $recent=!empty($link['last_synced_at'])&&strtotime((string)$link['last_synced_at'])>time()-15;
    if($refresh&&!$recent){try{$hn=hosxpDecrypt($link['hn_ciphertext']??'');if($hn!==''){$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');$visit=hosxpVisitByHn($hn,$today);$status=$visit?'VISIT_FOUND':'WAITING_FOR_VISIT';hosxpSaveLink($pdo,$ticketId,$hn,$visit,$status);$stmt->execute([$ticketId]);$link=$stmt->fetch();}}catch(Throwable $e){error_log('HOSxP sync error: '.$e->getMessage());$pdo->prepare("UPDATE hosxp_visit_links SET sync_status='ERROR',last_error=?,last_synced_at=CURRENT_TIMESTAMP WHERE queue_ticket_id=?")->execute(['เชื่อมต่อ HOSxP ไม่สำเร็จ',$ticketId]);$link['sync_status']='ERROR';}}
    $found=($link['sync_status']??'')==='VISIT_FOUND';return ['enabled'=>true,'sync_status'=>$link['sync_status'],'visit_found'=>$found,'visit_date'=>$found?$link['visit_date']:null,'visit_time'=>$found?$link['visit_time']:null,'current_department'=>$found?($link['current_department']?:'ลงทะเบียนแล้ว'):null,'last_synced_at'=>$link['last_synced_at']??null];
}
