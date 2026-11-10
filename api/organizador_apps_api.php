<?php
require_once __DIR__ . '/request_guard.php';
require_once __DIR__ . '/ftp_compat.php';
error_reporting(0); ini_set('display_errors', '0'); set_time_limit(180);
header('Content-Type: application/json; charset=utf-8');

const APPDB_REMOTE = '/system_data/priv/mms/app.db';
function oa_reply($data, $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function oa_host($value) { return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $value : false; }
function oa_sql($s) { return "'" . str_replace("'", "''", (string)$s) . "'"; }
function oa_table($name) { return preg_match('/^tbl_appbrowse_[0-9]{1,32}$/', $name) ? '"' . $name . '"' : false; }
function oa_sqlite_bin() {
    if (PHP_OS_FAMILY === 'Windows') { foreach ([__DIR__ . '/../runtime/sqlite3.exe', __DIR__ . '/../GoldHenPC-Release-v1.0/runtime/sqlite3.exe'] as $p) if (is_file($p)) return $p; return false; }
    foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
        $path = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sqlite3';
        if (is_file($path) && is_executable($path)) return $path;
    }
    return false;
}
function oa_sqlite($db, $sql) {
    if (class_exists('SQLite3')) {
        try {
            $conn = new SQLite3($db, SQLITE3_OPEN_READWRITE);
            $first = strtoupper((string)strtok(ltrim($sql), " \t\r\n"));
            if (in_array($first, ['SELECT','PRAGMA','WITH','EXPLAIN'], true)) {
                $result = $conn->query($sql); if ($result === false) { $err=$conn->lastErrorMsg();$conn->close();return ['ok'=>false,'error'=>'SQLite rechazó la consulta: '.substr($err,0,500)]; }
                $rows=[]; while($row=$result->fetchArray(SQLITE3_ASSOC))$rows[]=$row;
                $result->finalize();$conn->close();return ['ok'=>true,'data'=>$rows];
            }
            $ok=$conn->exec($sql);$err=$conn->lastErrorMsg();$conn->close();return $ok?['ok'=>true,'data'=>[]]:['ok'=>false,'error'=>'SQLite rechazó la operación: '.substr($err,0,500)];
        } catch (Throwable $e) { return ['ok'=>false,'error'=>'SQLite no pudo abrir app.db: '.substr($e->getMessage(),0,300)]; }
    }
    $bin = oa_sqlite_bin();
    if (!$bin) return ['ok'=>false, 'error'=>'Falta SQLite CLI. En Termux ejecuta el actualizador del Manager para instalar sqlite; en PC usa el paquete portable completo.'];
    $cmd = [$bin, '-batch', '-bail', '-json', $db];
    $pipes = [];
    $proc = @proc_open($cmd, [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes, dirname($db));
    if (!is_resource($proc)) return ['ok'=>false, 'error'=>'No se pudo iniciar SQLite.'];
    fwrite($pipes[0], $sql); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $status = proc_close($proc);
    if ($status !== 0) return ['ok'=>false, 'error'=>'SQLite rechazó la operación: ' . substr(trim($err ?: $out), 0, 500)];
    $decoded = json_decode(trim($out), true);
    if ($decoded === null && trim($out) !== '' && strtolower(trim($out)) !== '[]') return ['ok'=>false, 'error'=>'SQLite devolvió una respuesta no válida.'];
    return ['ok'=>true, 'data'=>is_array($decoded) ? $decoded : []];
}
function oa_temp() { $f = tempnam(sys_get_temp_dir(), 'ghm_appdb_'); return $f ?: false; }
function oa_ftp_url($host, $port, $path) { return 'ftp://' . $host . ':' . (int)$port . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/')))); }
function oa_download($host, $port, $dest) {
    if(function_exists('ftp_connect'))return ghm_ftp_download($host,(int)$port,APPDB_REMOTE,$dest,90)&&is_file($dest)&&filesize($dest)>=16384?true:'No se pudo leer app.db por FTP nativo; comprueba que exista y esté disponible.';
    $fp = @fopen($dest, 'wb'); if (!$fp) return 'No se pudo crear el archivo temporal.';
    $ch = curl_init(oa_ftp_url($host, $port, APPDB_REMOTE));
    curl_setopt_array($ch, [CURLOPT_FILE=>$fp, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>60, CURLOPT_FAILONERROR=>true, CURLOPT_FTP_RESPONSE_TIMEOUT=>20]);
    $ok = curl_exec($ch); $err = curl_error($ch); $size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD); curl_close($ch); fclose($fp);
    if (!$ok || !is_file($dest) || filesize($dest) < 16384 || ($size > 0 && (int)$size !== filesize($dest))) return 'No se pudo leer app.db por FTP: ' . ($err ?: 'archivo incompleto o demasiado pequeño.');
    return true;
}
function oa_upload($host, $port, $src) {
    if(function_exists('ftp_connect'))return ghm_ftp_put($host,(int)$port,APPDB_REMOTE,$src,false,120)?true:'La PS4 rechazó la subida de app.db por FTP nativo.';
    $fp = @fopen($src, 'rb'); if (!$fp) return 'No se pudo abrir la base preparada.';
    $ch = curl_init(oa_ftp_url($host, $port, APPDB_REMOTE));
    curl_setopt_array($ch, [CURLOPT_UPLOAD=>true, CURLOPT_INFILE=>$fp, CURLOPT_INFILESIZE=>filesize($src), CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>120, CURLOPT_FTP_RESPONSE_TIMEOUT=>30]);
    $ok = curl_exec($ch); $err = curl_error($ch); curl_close($ch); fclose($fp);
    return $ok ? true : ('La PS4 rechazó la subida FTP: ' . ($err ?: 'error desconocido.'));
}
function oa_verify_remote($host, $port, $expectedHash) {
    $tmp = oa_temp(); if (!$tmp) return 'No se pudo crear la verificación temporal.';
    $result = oa_download($host, $port, $tmp);
    $hash = $result === true ? hash_file('sha256', $tmp) : '';
    @unlink($tmp);
    return $result === true && hash_equals($expectedHash, $hash) ? true : 'La verificación posterior a la subida no coincidió.';
}
function oa_backup_dir() { $d = __DIR__ . '/../user/backups/appdb'; if (!is_dir($d)) @mkdir($d, 0770, true); return $d; }
function oa_latest_backup() { $files = glob(oa_backup_dir() . '/appdb-[0-9]*.db') ?: []; rsort($files, SORT_STRING); return $files[0] ?? false; }
function oa_profiles($db) {
    $q = oa_sqlite($db, "SELECT name FROM sqlite_master WHERE type='table' AND name GLOB 'tbl_appbrowse_[0-9]*' ORDER BY name;");
    if (!$q['ok']) return $q;
    $out=[]; foreach ($q['data'] as $row) if (oa_table((string)($row['name'] ?? ''))) $out[]=(string)$row['name'];
    return ['ok'=>true,'data'=>$out];
}
function oa_profile_folder($table) {
    if (!preg_match('/^tbl_appbrowse_([0-9]{1,10})$/', (string)$table, $match)) return false;
    $userId = ltrim($match[1], '0');
    if ($userId === '') $userId = '0';
    if (strlen($userId) > 10 || (strlen($userId) === 10 && strcmp($userId, '4294967295') > 0)) return false;
    // app.db guarda el user ID decimal en el sufijo de la tabla; /user/home usa el mismo ID en hexadecimal.
    $folder = strtolower(base_convert($userId, 10, 16));
    if ($folder === '' || strlen($folder) > 8 || !preg_match('/^[a-f0-9]{1,8}$/', $folder)) return false;
    return str_pad($folder, 8, '0', STR_PAD_LEFT);
}
function oa_profile_name($host, $port, $table) {
    $folder = oa_profile_folder($table);
    if ($folder === false) return 'Perfil local sin nombre';
    if(function_exists('ftp_connect')){$raw=ghm_ftp_get($host,(int)$port,'/user/home/'.$folder.'/username.dat',10);if($raw===false)return 'Perfil local sin nombre';$nul=strpos($raw,"\0");if($nul!==false)$raw=substr($raw,0,$nul);$name=trim(preg_replace('/[\x00-\x1f\x7f]/','',$raw)??'');return $name!==''&&preg_match('//u',$name)?(function_exists('mb_substr')?mb_substr($name,0,64,'UTF-8'):substr($name,0,64)):'Perfil local sin nombre';}
    $ch = curl_init(oa_ftp_url($host, $port, '/user/home/' . $folder . '/username.dat'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>8, CURLOPT_FTP_RESPONSE_TIMEOUT=>5, CURLOPT_FTP_USE_EPSV=>false]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false) return 'Perfil local sin nombre';
    $raw = (string)$raw;
    $nul = strpos($raw, "\0");
    if ($nul !== false) $raw = substr($raw, 0, $nul);
    $name = trim(preg_replace('/[\x00-\x1f\x7f]/', '', $raw) ?? '');
    if ($name === '' || !preg_match('//u', $name)) return 'Perfil local sin nombre';
    return function_exists('mb_substr') ? mb_substr($name, 0, 64, 'UTF-8') : substr($name, 0, 64);
}
function oa_check_db($db) {
    $r=oa_sqlite($db, 'PRAGMA integrity_check;');
    return $r['ok'] && isset($r['data'][0]['integrity_check']) && strtolower($r['data'][0]['integrity_check']) === 'ok';
}
function oa_sql_exec($db, $sql) { return oa_sqlite($db, $sql); }

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$host = oa_host($_POST['host_ip'] ?? $_GET['host_ip'] ?? '');
$port = (int)($_POST['host_port'] ?? $_GET['host_port'] ?? 2121);
if (!$host || !in_array($port, [2121,2122], true)) oa_reply(['status'=>'error','message'=>'IP o puerto FTP inválido.'], 400);
if ($action === 'list_backups') {
    $files = glob(oa_backup_dir() . '/appdb-[0-9]*.db') ?: [];
    rsort($files, SORT_STRING);
    $backups = [];
    foreach ($files as $file) {
        if (!preg_match('/^appdb-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.db$/', basename($file))) continue;
        $backups[] = ['name'=>basename($file), 'size'=>filesize($file), 'modified'=>filemtime($file), 'valid'=>oa_check_db($file)];
    }
    oa_reply(['status'=>'success','backups'=>$backups]);
}
if ($action === 'delete_backups') {
    $names = json_decode((string)($_POST['backups'] ?? ''), true);
    if (!is_array($names) || !$names || count($names) > 100) oa_reply(['status'=>'error','message'=>'Selecciona uno o más respaldos para eliminar.'], 400);
    $deleted=[]; $failed=[]; $dir=oa_backup_dir();
    foreach (array_unique($names) as $name) {
        if (!is_string($name) || !preg_match('/^appdb-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.db$/', $name)) { $failed[]=(string)$name; continue; }
        $file=$dir . DIRECTORY_SEPARATOR . $name;
        if (is_file($file) && @unlink($file)) $deleted[]=$name; else $failed[]=$name;
    }
    oa_reply(['status'=>$deleted? 'success':'error','deleted'=>$deleted,'failed'=>$failed,'message'=>$deleted?'Respaldos seleccionados eliminados.':'No se pudo eliminar ningún respaldo.'], $deleted?200:500);
}
$db = oa_temp(); if (!$db) oa_reply(['status'=>'error','message'=>'No se pudo preparar el archivo temporal.'], 500);
$download = oa_download($host, $port, $db);
if ($download !== true) { @unlink($db); oa_reply(['status'=>'error','message'=>$download], 502); }
if (!oa_check_db($db)) { @unlink($db); oa_reply(['status'=>'error','message'=>'La copia de app.db no pasó la comprobación de integridad; no se modificó la consola.'], 422); }
$profiles = oa_profiles($db);
if (!$profiles['ok'] || !$profiles['data']) { @unlink($db); oa_reply(['status'=>'error','message'=>'No se encontraron perfiles compatibles en app.db.'], 422); }

if ($action === 'scan') {
    $profileData=[];
    foreach ($profiles['data'] as $i=>$table) {
        $qt=oa_table($table);
        $apps=oa_sqlite($db, "SELECT titleId, COALESCE(titleName,'') AS titleName, COALESCE(metaDataPath,'') AS metaDataPath, COALESCE(visible,1) AS visible, COALESCE(parentFolderId,'') AS parentFolderId, COALESCE(positionInFolder,0) AS positionInFolder FROM $qt WHERE COALESCE(contentId,'')='' AND COALESCE(metaDataPath,'')<>'' AND COALESCE(folderType,0)<>1 ORDER BY titleName COLLATE NOCASE;");
        $games=oa_sqlite($db, "SELECT titleId, COALESCE(titleName,'') AS titleName, COALESCE(metaDataPath,'') AS metaDataPath, COALESCE(contentId,'') AS contentId, COALESCE(parentFolderId,'') AS parentFolderId, COALESCE(positionInFolder,0) AS positionInFolder, COALESCE(visible,1) AS visible FROM $qt WHERE COALESCE(contentId,'')<>'' AND COALESCE(folderType,0)<>1 ORDER BY titleName COLLATE NOCASE;");
        $folders=oa_sqlite($db, "SELECT titleId, COALESCE(titleName,'') AS titleName FROM $qt WHERE folderType=1 ORDER BY sortPriority,titleName COLLATE NOCASE;");
        if (!$apps['ok'] || !$games['ok'] || !$folders['ok']) { @unlink($db); oa_reply(['status'=>'error','message'=>'No se pudo leer la estructura de un perfil.'], 422); }
        $folder=oa_profile_folder($table);
        $profileData[]=['index'=>$i,'name'=>oa_profile_name($host,$port,$table),'avatarFolder'=>$folder,'system'=>$apps['data'],'games'=>$games['data'],'folders'=>$folders['data']];
    }
    @unlink($db); oa_reply(['status'=>'success','profiles'=>$profileData,'hasBackup'=>(bool)oa_latest_backup()]);
}

if ($action === 'backup') {
    $backup=oa_backup_dir().'/appdb-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.db';
    if (!@copy($db,$backup)) { @unlink($db); oa_reply(['status'=>'error','message'=>'No se pudo guardar el respaldo local.'],500); }
    $hash=hash_file('sha256',$backup); @unlink($db);
    oa_reply(['status'=>'success','message'=>'Respaldo de app.db creado y validado.','backup'=>basename($backup),'sha256'=>$hash]);
}

if ($action === 'restore') {
    $requested=basename((string)($_POST['backup']??''));
    $backup=$requested!=='' && preg_match('/^appdb-[0-9]{8}-[0-9]{6}-[a-f0-9]{6}\.db$/',$requested) ? oa_backup_dir().'/'.$requested : false;
    if (!$backup || !is_file($backup) || !oa_check_db($backup)) { @unlink($db); oa_reply(['status'=>'error','message'=>'Selecciona un respaldo existente e íntegro.'], 404); }
    $before=oa_backup_dir() . '/appdb-before-restore-' . gmdate('Ymd-His') . '.db';
    if (!@copy($db,$before)) { @unlink($db); oa_reply(['status'=>'error','message'=>'No se pudo guardar una copia de la base actual.'], 500); }
    $put=oa_upload($host,$port,$backup);
    if ($put===true) $put=oa_verify_remote($host,$port,hash_file('sha256',$backup));
    @unlink($db);
    oa_reply($put===true?['status'=>'success','message'=>'Respaldo restaurado y verificado en la PS4.','backup'=>basename($backup)]:['status'=>'error','message'=>$put], $put===true?200:502);
}

if ($action !== 'apply') { @unlink($db); oa_reply(['status'=>'error','message'=>'Acción desconocida.'], 400); }
$profileIndex=filter_var($_POST['profile']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>count($profiles['data'])-1]]);
$changes=json_decode((string)($_POST['changes']??''),true);
if ($profileIndex===false || !is_array($changes) || count($changes)>300) { @unlink($db); oa_reply(['status'=>'error','message'=>'Los cambios o perfil no son válidos.'],400); }
$table=oa_table($profiles['data'][$profileIndex]); if (!$table) { @unlink($db); oa_reply(['status'=>'error','message'=>'Perfil inválido.'],400); }
$repairFolders=($changes['repairFolders']??false)===true;
$deleteFolders=$changes['deleteFolders']??[];
if (!is_array($deleteFolders) || count($deleteFolders)>100) { @unlink($db); oa_reply(['status'=>'error','message'=>'La selección de carpetas no es válida.'],400); }
$deletedFolderDetails=[];
foreach (array_unique($deleteFolders) as $folderId) {
    $folderId=(string)$folderId;
    if (!preg_match('/^[0-9]{9}$/',$folderId)) { @unlink($db); oa_reply(['status'=>'error','message'=>'Identificador de carpeta inválido.'],400); }
    $found=oa_sqlite($db,"SELECT titleId,titleName,(SELECT COUNT(*) FROM $table AS child WHERE child.parentFolderId=folder.titleId AND COALESCE(child.folderType,0)<>1) AS movedApps FROM $table AS folder WHERE folder.titleId=".oa_sql($folderId)." AND folderType=1;");
    if (!$found['ok'] || empty($found['data'][0])) { @unlink($db); oa_reply(['status'=>'error','message'=>'Una de las carpetas seleccionadas ya no existe. No se aplicaron cambios.'],404); }
    $deletedFolderDetails[]=['id'=>$folderId,'name'=>(string)($found['data'][0]['titleName']??$folderId),'movedApps'=>(int)($found['data'][0]['movedApps']??0)];
}
$sql="BEGIN IMMEDIATE;\n"; $folderChanged=$repairFolders || count($deletedFolderDetails)>0;
foreach (($changes['visibility']??[]) as $item) {
    if (!is_array($item) || !preg_match('/^[A-Za-z0-9_-]{1,32}$/',(string)($item['id']??'')) || !in_array($item['visible']??null,[0,1],true)) { @unlink($db); oa_reply(['status'=>'error','message'=>'Cambio de visibilidad inválido.'],400); }
    $sql .= "UPDATE $table SET visible=".(int)$item['visible']." WHERE titleId=".oa_sql($item['id'])." AND COALESCE(folderType,0)<>1 AND (COALESCE(contentId,'')<>'' OR COALESCE(metaDataPath,'')<>'');\n";
}
$newFolder=trim((string)($changes['newFolder']??''));
if ($newFolder!=='') {
    if (mb_strlen($newFolder)>24 || preg_match('/[|\x00-\x1F\x7F]/u',$newFolder)) { @unlink($db); oa_reply(['status'=>'error','message'=>'Usa un nombre de hasta 24 caracteres, sin barras verticales ni controles.'],400); }
    $sql .= "INSERT INTO $table (titleId,titleName,folderInfo,visible,sortPriority,lastAccessIndex,folderType,pathInfo,dispLocation,canRemove,contentType,pathInfo2,presentBoxStatus,entitlement) SELECT printf('%09d',COALESCE(MAX(CAST(titleId AS INTEGER)),0)+1),".oa_sql($newFolder).",'{\"titleId\":\"\",\"index\":0}',1,100,COALESCE(MAX(lastAccessIndex),0)+1,1,0,5,1,0,0,0,0 FROM $table WHERE titleId GLOB '[0-9]*';\n";
    $sql .= "SELECT titleId AS created_folder FROM $table WHERE folderType=1 AND titleName=".oa_sql($newFolder)." ORDER BY CAST(titleId AS INTEGER) DESC LIMIT 1;\n";
    $folderChanged=true;
}
foreach ($deletedFolderDetails as $folder) {
    $id=$folder['id'];
    $sql .= "UPDATE $table SET parentFolderId=NULL,positionInFolder=NULL WHERE parentFolderId=".oa_sql($id)." AND COALESCE(folderType,0)<>1;\n";
    $sql .= "DELETE FROM $table WHERE titleId=".oa_sql($id)." AND folderType=1;\n";
}
$assignments=$changes['assignments']??[];
if (!is_array($assignments) || count($assignments)>300) { @unlink($db); oa_reply(['status'=>'error','message'=>'Lista de aplicaciones inválida.'],400); }
foreach ($assignments as $item) {
    $id=(string)($item['id']??''); $folder=(string)($item['folder']??'');
    if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/',$id) || ($folder!=='' && !preg_match('/^[0-9]{9}$/',$folder))) { @unlink($db); oa_reply(['status'=>'error','message'=>'Asignación de carpeta inválida.'],400); }
    $appExists=oa_sqlite($db,"SELECT 1 AS ok FROM $table WHERE titleId=".oa_sql($id)." AND COALESCE(folderType,0)<>1 AND (COALESCE(contentId,'')<>'' OR COALESCE(metaDataPath,'')<>'') LIMIT 1;");
    if (!$appExists['ok'] || empty($appExists['data'][0])) { @unlink($db); oa_reply(['status'=>'error','message'=>'Una app seleccionada ya no existe en app.db. No se aplicaron cambios.'],404); }
    if ($folder!=='') {
        $folderExists=oa_sqlite($db,"SELECT 1 AS ok FROM $table WHERE titleId=".oa_sql($folder)." AND folderType=1 LIMIT 1;");
        if (!$folderExists['ok'] || empty($folderExists['data'][0])) { @unlink($db); oa_reply(['status'=>'error','message'=>'La carpeta seleccionada ya no existe. No se aplicaron cambios.'],404); }
    }
    $sql .= $folder==='' ? "UPDATE $table SET parentFolderId=NULL,positionInFolder=NULL WHERE titleId=".oa_sql($id)." AND COALESCE(folderType,0)<>1 AND (COALESCE(contentId,'')<>'' OR COALESCE(metaDataPath,'')<>'');\n" : "UPDATE $table SET parentFolderId=".oa_sql($folder).",positionInFolder=(SELECT COALESCE(MAX(positionInFolder),0)+1 FROM $table WHERE parentFolderId=".oa_sql($folder).") WHERE titleId=".oa_sql($id)." AND COALESCE(folderType,0)<>1 AND (COALESCE(contentId,'')<>'' OR COALESCE(metaDataPath,'')<>'') AND EXISTS(SELECT 1 FROM $table WHERE titleId=".oa_sql($folder)." AND folderType=1);\n";
    $folderChanged=true;
}
if ($folderChanged) {
    $sql .= "UPDATE $table SET pathInfo=COALESCE(pathInfo,0),dispLocation=COALESCE(dispLocation,5),canRemove=COALESCE(canRemove,1),contentType=COALESCE(contentType,0),pathInfo2=COALESCE(pathInfo2,0),presentBoxStatus=COALESCE(presentBoxStatus,0),entitlement=COALESCE(entitlement,0) WHERE folderType=1 AND (pathInfo IS NULL OR dispLocation IS NULL OR canRemove IS NULL OR contentType IS NULL OR pathInfo2 IS NULL OR presentBoxStatus IS NULL OR entitlement IS NULL);\n";
    $sql .= "UPDATE $table AS folder SET folderInfo=json_set(CASE WHEN json_valid(folderInfo) THEN folderInfo ELSE '{\"titleId\":\"\",\"index\":0}' END,'$.titleId',COALESCE((SELECT titleId FROM $table AS member WHERE member.parentFolderId=folder.titleId ORDER BY member.positionInFolder,member.titleId LIMIT 1),'')) WHERE folderType=1;\n";
}
if ($folderChanged) $sql .= "UPDATE tbl_version SET status=COALESCE(status,0)+1 WHERE category IN ('folder_index','access_index');\n";
$sql .= "COMMIT;\n";
$result=oa_sql_exec($db,$sql);
if (!$result['ok'] || !oa_check_db($db)) { @unlink($db); oa_reply(['status'=>'error','message'=>$result['error']??'La base modificada no pasó la comprobación de integridad.'],422); }
$backup=oa_backup_dir().'/appdb-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.db';
if (!@copy($db,$backup)) { @unlink($db); oa_reply(['status'=>'error','message'=>'No se pudo crear el respaldo. La PS4 no se modificó.'],500); }
$put=oa_upload($host,$port,$db);
if ($put===true) $put=oa_verify_remote($host,$port,hash_file('sha256',$db));
if ($put!==true) {
    $restore=oa_upload($host,$port,$backup);
    if ($restore===true) $restore=oa_verify_remote($host,$port,hash_file('sha256',$backup));
    @unlink($db);
    oa_reply(['status'=>'error','message'=>$put . ($restore===true?' Se restauró automáticamente la copia previa.':' No se pudo confirmar la restauración automática; conserva el respaldo local para recuperarlo.')],502);
}
@unlink($db);
oa_reply(['status'=>'success','message'=>'Cambios aplicados y app.db verificada por FTP.','backup'=>basename($backup),'deletedFolders'=>$deletedFolderDetails]);
