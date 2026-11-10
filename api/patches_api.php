<?php
declare(strict_types=1);
require_once __DIR__ . '/request_guard.php';
require_once __DIR__ . '/patches_ini_helpers.php';
require_once __DIR__ . '/patches_version_helpers.php';
require_once __DIR__ . '/ftp_compat.php';
error_reporting(0); @ini_set('display_errors','0'); set_time_limit(180);
header('Content-Type: application/json; charset=utf-8');

function pa_out(array $v,int $code=200):never { http_response_code($code); echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function pa_url(string $ip,int $port,string $path):string { return 'ftp://'.$ip.':'.$port.'/'.implode('/',array_map('rawurlencode',explode('/',ltrim($path,'/')))); }
function pa_ftp(string $ip,int $port,string $path,?string $upload=null,?string $quote=null):array {
    if (function_exists('ftp_connect')) {
        if ($quote !== null) { $r=ghm_ftp_command($ip,$port,$quote); return $r===false?[false,'Comando FTP rechazado']:[implode("\n",$r),null]; }
        if ($upload !== null) return ghm_ftp_put($ip,$port,$path,$upload)?[true,null]:[false,'No se pudo subir por FTP'];
        $r=ghm_ftp_get($ip,$port,$path,60); return $r===false?[false,'No se pudo leer por FTP']:[$r,null];
    }
    $ch=curl_init(pa_url($ip,$port,$path)); $o=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>60,CURLOPT_FTP_RESPONSE_TIMEOUT=>20,CURLOPT_FTP_USE_EPSV=>false];
    if($quote!==null){$o[CURLOPT_CUSTOMREQUEST]='LIST';$o[CURLOPT_QUOTE]=[$quote];}
    if($upload!==null){$fp=@fopen($upload,'rb');if(!$fp)return [false,'No se pudo abrir el temporal'];$o[CURLOPT_UPLOAD]=true;$o[CURLOPT_INFILE]=$fp;$o[CURLOPT_INFILESIZE]=filesize($upload);}
    curl_setopt_array($ch,$o);$r=curl_exec($ch);$e=curl_error($ch);curl_close($ch);if(isset($fp))fclose($fp);return $r===false?[false,$e]:[$r,null];
}
function pa_list(string $ip,int $port,string $path):array|false {
    if (function_exists('ftp_connect')) return ghm_ftp_list($ip,$port,$path,15);
    $ch=curl_init(pa_url($ip,$port,rtrim($path,'/').'/'));curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'LIST',CURLOPT_TIMEOUT=>10,CURLOPT_FTP_USE_EPSV=>false]);$raw=curl_exec($ch);curl_close($ch);if($raw===false)return false;$out=[];
    foreach(preg_split('/\r?\n/',trim((string)$raw)) as $line){$p=preg_split('/\s+/',trim($line),9);if(count($p)<9)continue;$name=trim($p[8]);if($name==='.'||$name==='..'||$name===''||preg_match('/[\/\\\\\x00-\x1f]/',$name))continue;$out[]=['name'=>$name,'dir'=>($p[0][0]??'')==='d'];}return $out;
}
function pa_read(string $ip,int $port,string $path):string|false { if(function_exists('ftp_connect'))return ghm_ftp_get($ip,$port,$path,60);[$r]=pa_ftp($ip,$port,$path);return $r; }
function pa_icon(string $ip,int $port,string $cusa,string $cache):string {
    $file=$cache.'/'.$cusa.'.png';if(is_file($file)&&filesize($file)>512)return 'user/cache/biblioteca/'.$cusa.'.png';if(!is_dir($cache))@mkdir($cache,0770,true);
    foreach(['/user/appmeta/'.$cusa.'/icon0.png','/system_data/priv/appmeta/'.$cusa.'/icon0.png','/user/appmeta/external/'.$cusa.'/icon0.png','/user/app/'.$cusa.'/sce_sys/icon0.png'] as $remote){$tmp=$file.'.tmp';$ok=ghm_ftp_download($ip,$port,$remote,$tmp,20);if($ok&&is_file($tmp)&&filesize($tmp)>512){@rename($tmp,$file);return 'user/cache/biblioteca/'.$cusa.'.png';}@unlink($tmp);}return '';
}
function pa_hash(string $s):string { $w=[0x1505,0,0,0];foreach(unpack('C*',$s)?:[] as $b){$carry=0;for($i=0;$i<4;$i++){$x=$w[$i]*33+$carry;$w[$i]=$x%65536;$carry=intdiv($x,65536);}$w[0]^=$b;}return sprintf('%04x%04x%04x%04x',$w[3],$w[2],$w[1],$w[0]); }
function pa_hash_for(array $a,string $cusa):string { return pa_hash(($a['Title']??'').($a['Name']??'').($a['AppVer']??'').'/data/GoldHEN/patches/xml/'.$cusa.'.xml'.($a['AppElf']??'')); }
function pa_sfo_value(string $b,string $wanted):string { $pos=strpos($b,"\0PSF");if($pos!==false)$b=substr($b,$pos);if(substr($b,0,4)!=="\0PSF"||strlen($b)<20)return '';$v=unpack('Vkey/Vdata/Vcount',substr($b,8,12));$n=min((int)$v['count'],512);for($i=0;$i<$n;$i++){$o=20+$i*16;if($o+16>strlen($b))break;$e=unpack('vkey/vfmt/Vlen/Vmax/Vdata',substr($b,$o,16));$kp=(int)$v['key']+$e['key'];$end=strpos($b,"\0",$kp);if($end===false)continue;if(substr($b,$kp,$end-$kp)===$wanted)return trim(preg_replace('/[\x00-\x1f\x7f]/','',substr($b,(int)$v['data']+$e['data'],$e['len'])));}return ''; }
function pa_game_version(string $ip,int $port,string $cusa):string { foreach(['/user/appmeta/'.$cusa.'/param.sfo','/system_data/priv/appmeta/'.$cusa.'/param.sfo','/user/appmeta/external/'.$cusa.'/param.sfo','/user/app/'.$cusa.'/sce_sys/param.sfo'] as $p){$raw=pa_read($ip,$port,$p);if($raw!==false){$v=pa_sfo_value((string)$raw,'APP_VER');if($v!=='')return $v;}}return ''; }
function pa_patch_xml(string $xml,string $cusa):array {
    $sx=@simplexml_load_string($xml);if(!$sx)return [];$ids=[];foreach($sx->TitleID->ID??[] as $id)$ids[]=strtoupper((string)$id);if(!in_array($cusa,$ids,true))return [];$out=[];
    foreach($sx->Metadata??[] as $node){$a=[];foreach($node->attributes() as $k=>$v)$a[(string)$k]=(string)$v;$out[]=['key'=>pa_hash_for($a,$cusa),'name'=>$a['Name']??'Parche','title'=>$a['Title']??$cusa,'app_version'=>$a['AppVer']??'','patch_version'=>$a['PatchVer']??'','author'=>$a['Author']??'','note'=>$a['Note']??''];}return $out;
}
function pa_profiles_installed(string $ip,int $port):array {
    $ids=[];$rows=pa_list($ip,$port,'/user/app');if($rows!==false)foreach($rows as $r)if($r['dir']&&preg_match('/^[A-Z]{4}\d{5}$/i',$r['name']))$ids[strtoupper($r['name'])]=true;$mnt=pa_list($ip,$port,'/mnt');$mounts=[];if($mnt!==false)foreach($mnt as $r)if($r['dir']&&preg_match('/^ext[01]$/i',$r['name']))$mounts[]='/mnt/'.$r['name'];foreach($mounts as $mount){$rootRows=pa_list($ip,$port,$mount);if($rootRows===false)continue;$hasUser=false;foreach($rootRows as $r)if($r['dir']&&strcasecmp($r['name'],'user')===0)$hasUser=true;if(!$hasUser)continue;$external=pa_list($ip,$port,$mount.'/user/app');if($external!==false)foreach($external as $r)if($r['dir']&&preg_match('/^[A-Z]{4}\d{5}$/i',$r['name']))$ids[strtoupper($r['name'])]=true;}return array_keys($ids);
}
function pa_backup(string $raw):string|false {$d=__DIR__.'/../user/backups/plugins';if(!is_dir($d)&&!@mkdir($d,0770,true))return false;$n='plugins_patchloader_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(3)).'.ini';$p=$d.'/'.$n;return file_put_contents($p,$raw,LOCK_EX)===strlen($raw)&&hash_equals(hash('sha256',$raw),hash_file('sha256',$p))?$n:false;}
function pa_backup_state(string $key,string $raw):string|false {$d=__DIR__.'/../user/backups/patches';if(!is_dir($d)&&!@mkdir($d,0770,true))return false;$n='patch_'.$key.'_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(2)).'.txt';$p=$d.'/'.$n;return file_put_contents($p,$raw,LOCK_EX)===strlen($raw)&&hash_equals(hash('sha256',$raw),hash_file('sha256',$p))?$n:false;}

$action=$_POST['action']??$_GET['action']??'';$ip=$_POST['host_ip']??$_GET['host_ip']??'';$port=(int)($_POST['port']??$_GET['port']??2121);
if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)||!in_array($port,[2121,2122],true))pa_out(['status'=>'error','message'=>'IP o puerto FTP inválido.'],400);
try {
 if($action==='scan'){
    $games=[];$cache=__DIR__.'/../user/cache/biblioteca';$installedIds=pa_profiles_installed($ip,$port);$xmlRows=pa_list($ip,$port,'/data/GoldHEN/patches/xml');$xmlFiles=$xmlRows===false?[]:array_values(array_filter($xmlRows,fn($r)=>!$r['dir']&&preg_match('/^[A-Z]{4}\d{5}\.xml$/i',$r['name'])));$xmlByTitle=[];foreach($xmlFiles as $row)$xmlByTitle[strtoupper(substr($row['name'],0,-4))]=$row['name'];
    foreach($installedIds as $cusa){if(!isset($xmlByTitle[$cusa]))continue;$xml=pa_read($ip,$port,'/data/GoldHEN/patches/xml/'.$xmlByTitle[$cusa]);if($xml===false)continue;$patches=pa_patch_xml((string)$xml,$cusa);if(!$patches)continue;
       $gameVersion=pa_game_version($ip,$port,$cusa);
       foreach($patches as &$p){$state=pa_read($ip,$port,'/data/GoldHEN/patches/settings/0x'.$p['key'].'.txt');$p['enabled']=$state!==false&&trim((string)$state)==='1';$p['state_exists']=$state!==false;$p['compatibility']=pa_version_compatibility($gameVersion,(string)$p['app_version']);}unset($p);
       $icon=pa_icon($ip,$port,$cusa,$cache);$games[]=['cusa'=>$cusa,'title'=>$patches[0]['title']??$cusa,'game_version'=>$gameVersion,'icon'=>$icon,'patches'=>$patches];
    }
    usort($games,fn($a,$b)=>strcasecmp($a['title'],$b['title']));$ini=pa_read($ip,$port,'/data/GoldHEN/plugins.ini');$cfg=pa_read($ip,$port,'/data/GoldHEN/config.ini');
    $line=$ini!==false&&pa_default_has_loader((string)$ini);
    $global=$cfg!==false&&pa_global_game_patch_enabled((string)$cfg);
    pa_out(['status'=>'success','games'=>$games,'installed_count'=>count($installedIds),'xml_readable'=>$xmlRows!==false,'xml_count'=>count($xmlFiles),'plugin_in_ini'=>(bool)$line,'global_enabled'=>(bool)$global,'ini_readable'=>$ini!==false]);
 }
 if($action==='toggle'){
    $cusa=strtoupper((string)($_POST['cusa']??''));$key=(string)($_POST['key']??'');if(!preg_match('/^[A-Z]{4}\d{5}$/',$cusa)||!preg_match('/^[a-f0-9]{16}$/',$key))pa_out(['status'=>'error','message'=>'Parche no válido.']);
    $xml=pa_read($ip,$port,'/data/GoldHEN/patches/xml/'.$cusa.'.xml');if($xml===false)pa_out(['status'=>'error','message'=>'No se pudo leer el XML.']);$found=false;$selectedPatch=null;foreach(pa_patch_xml((string)$xml,$cusa) as $p)if($p['key']===$key){$found=true;$selectedPatch=$p;}if(!$found)pa_out(['status'=>'error','message'=>'El parche cambió; actualiza la lista.']);
    $compatibility=pa_version_compatibility(pa_game_version($ip,$port,$cusa),(string)$selectedPatch['app_version']);
    $path='/data/GoldHEN/patches/settings/0x'.$key.'.txt';$old=pa_read($ip,$port,$path);$enabling=!($old!==false&&trim((string)$old)==='1');
    if($enabling&&$compatibility!=='compatible'&&($_POST['accept_version_risk']??'')!=='1')pa_out(['status'=>'error','message'=>'Confirmación requerida: la versión del juego no coincide exactamente con la del parche ('.$compatibility.').']);
    $new=$enabling?"1\n":"0\n";$backup=$old!==false?pa_backup_state($key,(string)$old):null;if($old!==false&&$backup===false)pa_out(['status'=>'error','message'=>'No se pudo respaldar el estado actual; no se modificó.']);$tmp=tempnam(sys_get_temp_dir(),'ghmp');file_put_contents($tmp,$new);[$put]=pa_ftp($ip,$port,$path,$tmp);@unlink($tmp);$actual=pa_read($ip,$port,$path);if($put===false||trim((string)$actual)!==trim($new)){$restored=false;if($old!==false){$restore=tempnam(sys_get_temp_dir(),'ghmr');file_put_contents($restore,(string)$old);[$r]=pa_ftp($ip,$port,$path,$restore);@unlink($restore);$check=pa_read($ip,$port,$path);$restored=$r!==false&&$check===$old;}pa_out(['status'=>'error','message'=>'No se confirmó la escritura del estado.'.($restored?' Se restauró el valor anterior.':' Comprueba la consola; conserva el respaldo.'),'backup'=>$backup]);}pa_out(['status'=>'success','enabled'=>trim($new)==='1','backup'=>$backup]);
 }
 if($action==='enable_loader'){
    $raw=pa_read($ip,$port,'/data/GoldHEN/plugins.ini');if($raw===false)pa_out(['status'=>'error','message'=>'No se pudo leer plugins.ini; no se modificó.']);$change=pa_ini_enable((string)$raw);if($change['already'])pa_out(['status'=>'success','changed'=>false,'message'=>'game_patch.prx ya está activo en [default].']);$backup=pa_backup((string)$raw);if($backup===false)pa_out(['status'=>'error','message'=>'No se pudo crear/verificar el respaldo local.']);$tmp=tempnam(sys_get_temp_dir(),'ghmi');file_put_contents($tmp,$change['text']);[$put]=pa_ftp($ip,$port,'/data/GoldHEN/plugins.ini',$tmp);@unlink($tmp);$after=pa_read($ip,$port,'/data/GoldHEN/plugins.ini');if($put===false||$after!==$change['text'])pa_out(['status'=>'error','message'=>'No se verificó plugins.ini. Respaldo local: '.$backup,'backup'=>$backup]);pa_out(['status'=>'success','changed'=>true,'message'=>'game_patch.prx activado en [default]. plugins.ini fue verificado. Reinicia el juego para aplicar.','backup'=>$backup]);
 }
 pa_out(['status'=>'error','message'=>'Acción inválida.'],400);
} catch(Throwable $e){pa_out(['status'=>'error','message'=>'Error controlado: '.$e->getMessage()],500);}

