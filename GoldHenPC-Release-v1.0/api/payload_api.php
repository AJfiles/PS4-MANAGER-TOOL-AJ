<?php
require_once __DIR__ . '/request_guard.php';
require_once __DIR__ . '/ftp_compat.php';
error_reporting(0); ini_set('display_errors', 0); set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
$action = $_POST['action'] ?? $_GET['action'] ?? ''; $host = $_POST['host_ip'] ?? $_GET['host_ip'] ?? '';
$local_dir = __DIR__ . '/../user/payloads'; $project_dir = __DIR__ . '/../payloads'; $remote_dir = '/data/payloads';
@mkdir($local_dir, 0777, true);
function payload_out($data) { echo json_encode($data); exit; }
require_once __DIR__ . '/binloader_client.php';
function payload_name($name) { $name = basename($name); return preg_match('/^[A-Za-z0-9._-]+\.(bin|payload|elf)$/i', $name) ? $name : false; }
function payload_tipo($name) { if (stripos($name, 'linux-') === 0) return 'linux'; if (stripos($name, 'nanodns-') === 0) return 'dns'; if (stripos($name, 'np-fake-signin-') === 0) return 'signin'; return 'general'; }
function payload_url($host, $path) { return 'ftp://' . $host . ':2121' . implode('/', array_map('rawurlencode', explode('/', $path))); }
function payload_list_ftp($host, $dir) { if(function_exists('ftp_connect')){$rows=ghm_ftp_list($host,2121,$dir,15);if($rows===false)return [];return array_values(array_map(static fn($r)=>['name'=>$r['name'],'size'=>$r['size']],array_filter($rows,static fn($r)=>!$r['dir']&&payload_name($r['name']))));} $ch=curl_init(payload_url($host,$dir.'/')); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'LIST',CURLOPT_TIMEOUT=>10]); $raw=curl_exec($ch); curl_close($ch); $out=[]; foreach(explode("\n",trim((string)$raw)) as $line){$p=preg_split('/\s+/',trim($line),9);if(count($p)>=9&&$p[0][0]!=='d'&&payload_name($p[8]))$out[]=['name'=>$p[8],'size'=>(int)$p[4]];} return $out; }
function payload_get_ftp($host,$path){if(function_exists('ftp_connect'))return ghm_ftp_get($host,2121,$path,60);$ch=curl_init(payload_url($host,$path));curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>60]);$d=curl_exec($ch);curl_close($ch);return $d;}
if ($action === 'list') { $local=[]; foreach([['dir'=>$project_dir,'origin'=>'proyecto'],['dir'=>$local_dir,'origin'=>'usuario']] as $source) foreach(glob($source['dir'].'/*')?:[] as $f) if(payload_name(basename($f)))$local[basename($f)]=['name'=>basename($f),'size'=>filesize($f),'origin'=>$source['origin'],'type'=>payload_tipo(basename($f))]; $remote=filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)?payload_list_ftp($host,$remote_dir):[]; foreach($remote as &$item)$item['type']=payload_tipo($item['name']); payload_out(['status'=>'success','local'=>array_values($local),'remote'=>$remote]); }
if (!filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)) payload_out(['status'=>'error','message'=>'IP de PS4 inválida.']);
if ($action === 'upload') { if(!isset($_FILES['payload'])||$_FILES['payload']['error']!==UPLOAD_ERR_OK||!($name=payload_name($_FILES['payload']['name'])))payload_out(['status'=>'error','message'=>'Payload no válido.']); if(!move_uploaded_file($_FILES['payload']['tmp_name'],$local_dir.'/'.$name))payload_out(['status'=>'error','message'=>'No se pudo guardar el payload.']); payload_out(['status'=>'success']); }
if ($action === 'install') { $name=payload_name($_POST['name']??''); if(!$name)payload_out(['status'=>'error','message'=>'Payload no válido.']); $source=null; foreach([$local_dir,$project_dir] as $dir)if(is_file($dir.'/'.$name)){$source=$dir.'/'.$name;break;} if(!$source||filesize($source)>16*1024*1024)payload_out(['status'=>'error','message'=>'El payload local no existe o supera 16 MB.']); if(function_exists('ftp_connect')){$ok=ghm_ftp_put($host,2121,$remote_dir.'/'.$name,$source,false,60);$err='FTP nativo';}else{$fp=fopen($source,'rb');$ch=curl_init(payload_url($host,$remote_dir.'/'.$name));curl_setopt_array($ch,[CURLOPT_UPLOAD=>true,CURLOPT_INFILE=>$fp,CURLOPT_INFILESIZE=>filesize($source),CURLOPT_FTP_CREATE_MISSING_DIRS=>CURLFTP_CREATE_DIR_RETRY, CURLOPT_TIMEOUT=>60]);$ok=curl_exec($ch);$err=curl_error($ch);curl_close($ch);fclose($fp);}if(!$ok)payload_out(['status'=>'error','message'=>'No se pudo instalar en /data/payloads/: '.$err]);payload_out(['status'=>'success']); }
if ($action === 'send_manual') {
    if (!isset($_FILES['payload'])) payload_out(['status'=>'error','message'=>'Selecciona un archivo. Si era mayor de 16 MB, el servidor pudo rechazarlo; el límite es 16 MB.']);
    $upload = $_FILES['payload'];
    if ($upload['error'] !== UPLOAD_ERR_OK) {
        $reason = $upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'El archivo supera el límite de carga (16 MB).'
            : 'No se pudo leer el archivo seleccionado (código ' . (int)$upload['error'] . ').';
        payload_out(['status'=>'error','message'=>$reason]);
    }
    $name = payload_name($upload['name']);
    $size = (int)$upload['size'];
    if (!$name) payload_out(['status'=>'error','message'=>'Tipo de archivo no admitido. Selecciona un .bin, .payload o .elf.']);
    if ($size < 1 || $size > 16 * 1024 * 1024) payload_out(['status'=>'error','message'=>'El payload debe tener entre 1 byte y 16 MB.']);
    if (!is_uploaded_file($upload['tmp_name'])) payload_out(['status'=>'error','message'=>'La carga temporal no es válida. Vuelve a seleccionar el archivo.']);
    $data = @file_get_contents($upload['tmp_name']);
    if ($data === false || strlen($data) !== $size) payload_out(['status'=>'error','message'=>'No se pudo leer completo el payload seleccionado.']);
    $result = ghm_binloader_send($host, $data, false);
    if (!$result['ok']) payload_out(['status'=>'error','message'=>$result['message']]);
    payload_out(['status'=>'success','bytes'=>$result['bytes'],'name'=>$name,'application_confirmed'=>false]);
}
if ($action === 'send') { $source=$_POST['source']??'local'; $name=payload_name($_POST['name']??''); if(!$name)payload_out(['status'=>'error','message'=>'Payload no válido.']); $data=false; if($source==='remote')$data=payload_get_ftp($host,"$remote_dir/$name"); else foreach([$local_dir,$project_dir] as $dir)if(is_file($dir.'/'.$name)){$data=@file_get_contents($dir.'/'.$name);break;} if($data===false||$data===''||strlen($data)>16*1024*1024)payload_out(['status'=>'error','message'=>'Payload ausente o supera 16 MB.']); $result=ghm_binloader_send($host,$data,false); payload_out($result['ok']?['status'=>'success','bytes'=>$result['bytes']]:['status'=>'error','message'=>$result['message']]); }
payload_out(['status'=>'error','message'=>'Acción no válida.']);
?>
