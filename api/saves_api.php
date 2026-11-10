<?php
declare(strict_types=1);
require_once __DIR__ . '/request_guard.php';
require_once __DIR__ . '/save_paths.php';
require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/ftp_compat.php';
error_reporting(0); @ini_set('display_errors','0'); @ini_set('memory_limit','768M'); set_time_limit(0);
header('Content-Type: application/json; charset=utf-8');

function sm_out(array $data,int $code=200): never { http_response_code($code); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function sm_host($v): string|false { return filter_var((string)$v,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4); }
function sm_url(string $ip,int $port,string $path): string { return 'ftp://'.$ip.':'.$port.'/'.implode('/',array_map('rawurlencode',explode('/',ltrim($path,'/')))); }
function sm_size_value(string $raw): int|float {
    if (!ctype_digit($raw)) return 0;
    $normalized = ltrim($raw, '0');
    $normalized = $normalized === '' ? '0' : $normalized;
    $max = (string)PHP_INT_MAX;
    if (strlen($normalized) < strlen($max) || (strlen($normalized) === strlen($max) && strcmp($normalized, $max) <= 0)) return (int)$normalized;
    return (float)$normalized;
}
function sm_sum_sizes(array $rows, string $key = 'size'): float {
    $total = 0.0;
    foreach ($rows as $row) $total += (float)($row[$key] ?? 0);
    return $total;
}
function sm_curl(string $ip,int $port,string $path,string $method='GET',?string $upload=null,?string $quote=null): array {
    if (function_exists('ftp_connect')) {
        if ($quote !== null) { $r = ghm_ftp_command($ip,$port,$quote); return $r === false ? [false,'Comando FTP rechazado'] : [implode("\n",$r),null]; }
        if ($method === 'LIST') { $rows=ghm_ftp_rawlist($ip,$port,$path,30); return $rows===false ? [false,'No se pudo listar el directorio FTP'] : [implode("\n",$rows),null]; }
        if ($upload !== null) return ghm_ftp_put($ip,$port,$path,$upload) ? [true,null] : [false,'No se pudo subir el archivo por FTP'];
        $body=ghm_ftp_get($ip,$port,$path,180); return $body===false ? [false,'No se pudo leer el archivo por FTP'] : [$body,null];
    }
    $ch=curl_init(sm_url($ip,$port,$path)); $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>180,CURLOPT_FTP_RESPONSE_TIMEOUT=>30,CURLOPT_FTP_USE_EPSV=>false];
    if($method==='LIST') $opts[CURLOPT_CUSTOMREQUEST]='LIST';
    if($quote!==null){$opts[CURLOPT_CUSTOMREQUEST]='LIST';$opts[CURLOPT_QUOTE]=[$quote];}
    if($upload!==null){$fp=@fopen($upload,'rb'); if(!$fp)return [false,'No se pudo abrir el archivo local.'];$opts[CURLOPT_UPLOAD]=true;$opts[CURLOPT_INFILE]=$fp;$opts[CURLOPT_INFILESIZE]=filesize($upload);$opts[CURLOPT_FTP_CREATE_MISSING_DIRS]=CURLFTP_CREATE_DIR_RETRY;}
    curl_setopt_array($ch,$opts);$body=curl_exec($ch);$err=curl_error($ch);$http=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);if(isset($fp)&&is_resource($fp))fclose($fp);
    return $body===false?[false,$err?:'Error FTP '.$http]:[$body,null];
}
function sm_list(string $ip,int $port,string $path): array|false {
    [$raw,$err]=sm_curl($ip,$port,rtrim($path,'/').'/','LIST');if($raw===false)return false;$out=[];
    foreach(preg_split('/\r?\n/',trim((string)$raw)) as $line){$p=preg_split('/\s+/',trim($line),9);if(count($p)<9)continue;$name=trim($p[8]);if($name===''||$name==='.'||$name==='..'||str_contains($name,"\0")||str_contains($name,'/')||str_contains($name,'\\'))continue;$out[]=['name'=>$name,'dir'=>($p[0][0]??'')==='d','size'=>sm_size_value($p[4]),'size_capped'=>sm_size_is_likely_capped($p[4]),'date'=>trim(($p[5]??'').' '.($p[6]??'').' '.($p[7]??''))];}return $out;
}
function sm_capture_list(string $ip,int $port,string $path): array|false {
    if (function_exists('ftp_connect')) return sm_list($ip,$port,$path);
    // La galería usa EPSV y funciona con rutas donde el FTP de GoldHEN rechaza PASV.
    $ch=curl_init('ftp://'.$ip.':'.$port.rtrim($path,'/').'/');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'LIST',CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12]);
    $raw=curl_exec($ch);curl_close($ch);if($raw===false)return false;$out=[];
    foreach(preg_split('/\r?\n/',trim((string)$raw)) as $line){$p=preg_split('/\s+/',trim($line),9);if(count($p)<9)continue;$name=trim($p[8]);if($name===''||$name==='.'||$name==='..'||str_contains($name,"\0")||str_contains($name,'/')||str_contains($name,'\\'))continue;$out[]=['name'=>$name,'dir'=>($p[0][0]??'')==='d','size'=>sm_size_value($p[4]),'size_capped'=>sm_size_is_likely_capped($p[4]),'date'=>trim(($p[5]??'').' '.($p[6]??'').' '.($p[7]??''))];}return $out;
}
function sm_capture_walk(string $ip,int $port,string $root,int $depth=0,array &$out=[],int &$count=0): bool {
    if($depth>4||$count>5000)return $count<=5000;$items=sm_capture_list($ip,$port,$root);if($items===false)return false;
    foreach($items as $it){$count++;if($count>5000)return false;$path=$root.'/'.$it['name'];if($it['dir']){if(!sm_capture_walk($ip,$port,$path,$depth+1,$out,$count))return false;}else $out[]=['path'=>$path,'name'=>$it['name'],'size'=>$it['size'],'size_capped'=>$it['size_capped']??false,'date'=>$it['date']];}return true;
}
function sm_walk(string $ip,int $port,string $root,int $depth=0,array &$out=[],int &$count=0): bool {
    if($depth>5||$count>5000)return $count<=5000;$items=sm_list($ip,$port,$root);if($items===false)return false;
    foreach($items as $it){$p=$root.'/'.$it['name'];$count++;if($it['dir']){if(!sm_walk($ip,$port,$p,$depth+1,$out,$count))return false;}else $out[]=['path'=>$p,'name'=>$it['name'],'size'=>$it['size'],'size_capped'=>$it['size_capped']??false,'date'=>$it['date']];}return true;
}
function sm_tree_stats(string $ip,int $port,string $root,int $depth,int|float &$bytes,int &$files,int &$entries,bool &$capped): bool {
    if($depth>24)return false;
    if($entries>=50000)return true;
    $items=sm_list($ip,$port,$root);if($items===false)return false;
    foreach($items as $it){if($entries>=50000)return true;$entries++;$path=$root.'/'.$it['name'];if($it['dir']){if(!sm_tree_stats($ip,$port,$path,$depth+1,$bytes,$files,$entries,$capped))return false;}else{$bytes+=(float)$it['size'];$files++;if(!empty($it['size_capped']))$capped=true;}}return true;
}
function sm_optional_title_ids(string $ip,int $port,string $relativePath,array $mounts): array|false {
    $ids=[];$readable=false;
    foreach(array_merge(['/user/'.$relativePath],array_map(fn($mount)=>$mount.'/user/'.$relativePath,$mounts)) as $root){
        $items=sm_list($ip,$port,$root);if($items===false)continue;$readable=true;
        foreach($items as $item)if($item['dir']&&preg_match('/^[A-Z]{4}\d{5}$/i',$item['name']))$ids[strtoupper($item['name'])]=true;
    }
    return $readable?$ids:false;
}
function sm_title_map(string $ip,int $port): array|false {
    $cache=__DIR__.'/../user/cache/biblioteca';$ids=[];
    foreach(['/user/app','/user/appmeta','/user/appmeta/external','/system_data/priv/appmeta','/system_data/priv/appmeta/external'] as $base){$items=sm_list($ip,$port,$base);if($items===false)continue;foreach($items as $it)if($it['dir']&&preg_match('/^[A-Z]{4}\d{5}$/i',$it['name']))$ids[strtoupper($it['name'])]=true;}
    if(!$ids)return false;$map=[];
    foreach(array_keys($ids) as $id){$title=$id;$file=$cache.'/'.$id.'.sfo';if(is_file($file)){$raw=@file_get_contents($file);if($raw!==false&&preg_match('/\0PSF/s',$raw,$m,PREG_OFFSET_CAPTURE))$title=sm_sfo_title(substr($raw,$m[0][1]));}
        if($title===$id){foreach(['/user/appmeta/'.$id.'/param.sfo','/system_data/priv/appmeta/'.$id.'/param.sfo','/user/appmeta/external/'.$id.'/param.sfo','/user/app/'.$id.'/sce_sys/param.sfo'] as $remote){[$raw]=sm_curl($ip,$port,$remote);if($raw!==false&&strlen((string)$raw)>20){$title=sm_sfo_title((string)$raw);if($title!==$id)break;}}}
        $known=['CUSA00419'=>'Grand Theft Auto V','CUSA12824'=>'Hogwarts Legacy'];if($title===$id&&isset($known[$id]))$title=$known[$id];$map[$id]=$title;
    }return $map;
}
function sm_installed_ids(string $ip,int $port): array|false {$ids=[];$items=sm_list($ip,$port,'/user/app');if($items===false)return false;foreach($items as $it)if($it['dir']&&preg_match('/^[A-Z]{4}\d{5}$/i',$it['name']))$ids[strtoupper($it['name'])]=true;$mnt=sm_list($ip,$port,'/mnt');if($mnt===false)return false;$mounts=[];foreach($mnt as $row)if($row['dir']&&preg_match('/^ext[01]$/i',$row['name']))$mounts[]='/mnt/'.$row['name'];foreach($mounts as $mount){$rootRows=sm_list($ip,$port,$mount);if($rootRows===false)return false;$hasUser=false;foreach($rootRows as $row)if($row['dir']&&strcasecmp($row['name'],'user')===0)$hasUser=true;if(!$hasUser)continue;$external=sm_list($ip,$port,$mount.'/user/app');if($external===false)return false;foreach($external as $it)if($it['dir']&&preg_match('/^[A-Z]{4}\d{5}$/i',$it['name']))$ids[strtoupper($it['name'])]=true;}return $ids;}
function sm_sfo_fields(string $b): array {
    $pos=strpos($b,"\0PSF");if($pos!==false)$b=substr($b,$pos);if(substr($b,0,4)!=="\0PSF"||strlen($b)<20)return [];
    $v=unpack('Vkey/Vdata/Vcount',substr($b,8,12));$ko=(int)$v['key'];$do=(int)$v['data'];$n=min((int)$v['count'],512);$out=[];$length=strlen($b);
    for($i=0;$i<$n;$i++){$off=20+$i*16;if($off+16>$length)break;$e=unpack('vkey/vfmt/Vlen/Vmax/Vdata',substr($b,$off,16));$kpos=$ko+(int)$e['key'];if($kpos<0||$kpos>=$length)continue;$end=strpos($b,"\0",$kpos);if($end===false)continue;$key=substr($b,$kpos,$end-$kpos);$dpos=$do+(int)$e['data'];$dlen=min((int)$e['len'],4096);if($dpos<0||$dpos>$length||$dlen<1||$dpos+$dlen>$length)continue;$value=rtrim(substr($b,$dpos,$dlen),"\0");$value=trim(preg_replace('/[\x00-\x1f\x7f]/u','',$value)??'');if($value!==''&&preg_match('//u',$value))$out[$key]=$value;}
    return $out;
}
function sm_sfo_title(string $b): string {$fields=sm_sfo_fields($b);return (string)($fields['TITLE']??$fields['APP_TITLE']??'');}
function sm_save_metadata_title(string $ip,int $port,array $metaFiles): string {
    foreach($metaFiles as $file){if(!preg_match('/\.sfo$/i',(string)$file['name']))continue;[$raw]=sm_curl($ip,$port,(string)$file['path']);if($raw===false||strlen((string)$raw)>1048576)continue;$fields=sm_sfo_fields((string)$raw);
        foreach(['MAINTITLE','TITLE','APP_TITLE'] as $key){$title=trim((string)($fields[$key]??''));if($title===''||preg_match('/^(?:[A-Z]{4}[A-Z0-9]{5}|datos guardados|saved data|saved game|game save|ajustes|settings|configuración|configuration)$/iu',$title))continue;return mb_substr($title,0,160,'UTF-8');}
    }return '';
}
function sm_cache_savedata_icon(string $ip,int $port,array $metaFiles,string $id): string {
    if(!preg_match('/^[A-Z]{4}\d{5}$/',$id))return '';$dir=__DIR__.'/../user/cache/biblioteca';$relative='user/cache/biblioteca/'.$id.'-save.png';$file=dirname(__DIR__).'/user/cache/biblioteca/'.$id.'-save.png';
    if(is_file($file)&&filesize($file)>512)return $relative;if(!is_dir($dir)&&!@mkdir($dir,0770,true))return '';
    foreach($metaFiles as $entry){if(!preg_match('/_icon0\.png$/i',(string)$entry['name']))continue;[$data]=sm_curl($ip,$port,(string)$entry['path']);if($data===false||strlen((string)$data)<512||strlen((string)$data)>4*1024*1024||substr((string)$data,0,8)!="\x89PNG\r\n\x1a\n")continue;$tmp=$file.'.tmp';if(@file_put_contents($tmp,$data,LOCK_EX)===strlen((string)$data)&&@rename($tmp,$file))return $relative;@unlink($tmp);}
    return '';
}
function sm_cache_icon(string $ip,int $port,string $id): string {
    if(!preg_match('/^[A-Z]{4}\d{5}$/',$id))return '';$dir=__DIR__.'/../user/cache/biblioteca';$file=$dir.'/'.$id.'.png';if(is_file($file)&&filesize($file)>512)return 'user/cache/biblioteca/'.$id.'.png';if(!is_dir($dir)&&!@mkdir($dir,0770,true))return '';
    $paths=['/user/appmeta/'.$id.'/icon0.png','/system_data/priv/appmeta/'.$id.'/icon0.png','/user/appmeta/external/'.$id.'/icon0.png','/system_data/priv/appmeta/external/'.$id.'/icon0.png','/user/app/'.$id.'/sce_sys/icon0.png','/mnt/ext0/user/app/'.$id.'/sce_sys/icon0.png','/mnt/ext1/user/app/'.$id.'/sce_sys/icon0.png'];
    foreach($paths as $path){[$data]=sm_curl($ip,$port,$path);if($data===false||strlen((string)$data)<512||substr((string)$data,0,8)!="\x89PNG\r\n\x1a\n"||strlen((string)$data)>4*1024*1024)continue;$tmp=$file.'.tmp';if(@file_put_contents($tmp,$data,LOCK_EX)===strlen((string)$data)&&@rename($tmp,$file))return 'user/cache/biblioteca/'.$id.'.png';@unlink($tmp);}return '';
}
function sm_catalog_title_info(string $id): array {
    static $catalog = null;
    if (!preg_match('/^[A-Z]{4}\d{5}$/', $id)) return [];
    if ($catalog === null) {
        $path = __DIR__ . '/../store/data/catalogo.json';
        $raw = is_file($path) ? @file_get_contents($path) : false;
        $rows = $raw !== false ? json_decode($raw, true) : null;
        $catalog = [];
        if (is_array($rows)) foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $key = strtoupper((string)($row['id'] ?? ''));
            if (!preg_match('/^[A-Z]{4}\d{5}$/', $key)) continue;
            $cover = (string)($row['cover'] ?? '');
            if (!preg_match('#^/store/covers/[A-Za-z0-9_.-]+\.(?:png|jpe?g|webp)$#iD', $cover)) $cover = '';
            $catalog[$key] = ['title'=>trim((string)($row['titulo'] ?? $row['title'] ?? '')), 'cover'=>$cover];
        }
    }
    return $catalog[$id] ?? [];
}
function sm_profiles(string $ip,int $port): array|false { $rows=sm_list($ip,$port,'/user/home');if($rows===false)return false;$names=[];foreach($rows as $r)if($r['dir']&&preg_match('/^[a-f0-9]{8,16}$/i',$r['name']))$names[]=$r['name'];sort($names,SORT_STRING);return $names; }
function sm_profile_name(string $ip,int $port,string $folder,int $index=0): string {[$raw]=sm_curl($ip,$port,'/user/home/'.$folder.'/username.dat');if($raw!==false){$raw=(string)$raw;$nul=strpos($raw,"\0");if($nul!==false)$raw=substr($raw,0,$nul);$name=trim(preg_replace('/[\x00-\x1f\x7f]/u','',$raw)??'');if($name!==''&&preg_match('//u',$name))return mb_substr($name,0,64,'UTF-8');}return 'Usuario PS4 sin nombre';}
function sm_save_rows(string $ip,int $port,?bool &$sizeCapped=null): array|false {
    if($sizeCapped===null)$sizeCapped=false;
    $profiles=sm_profiles($ip,$port);if($profiles===false)return false;$titles=sm_title_map($ip,$port);$installed=sm_installed_ids($ip,$port);if($installed===false)return false;$out=[];
    foreach($profiles as $idx=>$profile){$profileLabel=sm_profile_name($ip,$port,$profile,$idx);$root='/user/home/'.$profile.'/savedata';$items=sm_list($ip,$port,$root);if($items===false)continue;
        foreach($items as $it){$id=strtoupper($it['name']);if(!$it['dir']||!preg_match('/^[A-Z]{4}\d{5}$/',$id))continue;$files=[];$n=0;$ok=sm_walk($ip,$port,$root.'/'.$id,0,$files,$n);if(!$ok)continue;$bytes=sm_sum_sizes($files);$meta='/user/home/'.$profile.'/savedata_meta/user/'.$id;$metaFiles=[];$mn=0;$metaOK=sm_walk($ip,$port,$meta,0,$metaFiles,$mn);if(!$metaOK)$metaFiles=[];foreach(array_merge($files,$metaFiles) as $file)if(!empty($file['size_capped']))$sizeCapped=true;
            $latest='';foreach($files as $ff)if(strcmp($ff['date'],$latest)>0)$latest=$ff['date'];$known=['CUSA00419'=>'Grand Theft Auto V','CUSA12824'=>'Hogwarts Legacy'];$catalog=sm_catalog_title_info($id);$title=$titles[$id]??$known[$id]??($catalog['title']??'')?:$id;if($title===$id){$title=sm_save_metadata_title($ip,$port,$metaFiles)?:$id;}$icon=__DIR__.'/../user/cache/biblioteca/'.$id.'.png';$iconUrl=is_file($icon)?'user/cache/biblioteca/'.$id.'.png':sm_cache_savedata_icon($ip,$port,$metaFiles,$id);if($iconUrl==='')$iconUrl=sm_cache_icon($ip,$port,$id);if($iconUrl===''&&!empty($catalog['cover'])&&is_file(dirname(__DIR__).$catalog['cover']))$iconUrl=$catalog['cover'];$out[]=['profile'=>$profileLabel,'profile_key'=>hash('sha256','ghm-save-profile:'.$profile),'title_id'=>$id,'title'=>$title,'icon'=>$iconUrl,'installed'=>isset($installed[$id]),'bytes'=>$bytes,'files'=>count($files),'metadata_bytes'=>sm_sum_sizes($metaFiles),'metadata_files'=>count($metaFiles),'modified'=>$latest];
        }
    }usort($out,fn($a,$b)=>strcasecmp($a['title'],$b['title'])?:strcmp($a['profile_key'],$b['profile_key']));return $out;
}
function sm_lookup(string $ip,int $port,string $profileKey,string $id): array|false {
    if(!preg_match('/^[A-Z]{4}\d{5}$/',$id)||!preg_match('/^[a-f0-9]{64}$/',$profileKey))return false;$profiles=sm_profiles($ip,$port);if($profiles===false)return false;
    foreach($profiles as $i=>$p)if(hash_equals(hash('sha256','ghm-save-profile:'.$p),$profileKey))return ['profile'=>$p,'alias'=>sm_profile_name($ip,$port,$p,$i),'profile_key'=>$profileKey,'id'=>$id];return false;
}
function sm_backup_dir(): string { $d=__DIR__.'/../user/backups/saves';if(!is_dir($d))@mkdir($d,0770,true);return $d; }
function sm_manifest_path(string $id): string { return sm_backup_dir().'/'.$id.'.json'; }
function sm_download_backup(string $ip,int $port,array $target): array|false {
    $profile=$target['profile'];$id=$target['id'];$remoteSave='/user/home/'.$profile.'/savedata/'.$id;$remoteMeta='/user/home/'.$profile.'/savedata_meta/user/'.$id;$files=[];$n=0;
    if(!sm_walk($ip,$port,$remoteSave,0,$files,$n)||!$files)return false;$meta=[];$n=0;if(!sm_walk($ip,$port,$remoteMeta,0,$meta,$n))$meta=[];
    $backupId='save_'.$id.'_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(3));$stage=sm_backup_dir().'/'.$backupId.'_stage';@mkdir($stage,0770,true);$zipPath=sm_backup_dir().'/'.$backupId.'.zip';$manifest=['id'=>$backupId,'title_id'=>$id,'profile'=>$target['alias'],'profile_key'=>$target['profile_key'],'created_at'=>gmdate('c'),'savedata_bytes'=>0,'metadata_bytes'=>0,'files'=>[]];
    foreach([['savedata',$files],['savedata_meta',$meta]] as [$kind,$list])foreach($list as $entry){$rel=$kind.'/'.$id.'/'.substr($entry['path'],strlen(($kind==='savedata'?$remoteSave:$remoteMeta).'/'));$local=$stage.'/'.$rel;@mkdir(dirname($local),0770,true);$ok=ghm_ftp_download($ip,$port,$entry['path'],$local,900);if(!$ok||filesize($local)!==$entry['size']){$ok=false;break 2;}$hash=hash_file('sha256',$local);$manifest['files'][]=['path'=>$rel,'size'=>filesize($local),'sha256'=>$hash];$bucket=$kind==='savedata'?'savedata_bytes':'metadata_bytes';$manifest[$bucket]+=filesize($local);}
    if(!($ok??true)||!class_exists('ZipArchive')){$manifest['error']=!class_exists('ZipArchive')?'PHP ZipArchive no disponible':'No se pudo descargar completo el guardado.';sm_remove_tree($stage);return false;}
    $manifest['total_bytes']=$manifest['savedata_bytes']+$manifest['metadata_bytes'];file_put_contents($stage.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));$zip=new ZipArchive();if($zip->open($zipPath,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true){sm_remove_tree($stage);return false;}
    foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage,FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile())$zip->addFile($file->getPathname(),substr(str_replace('\\','/',$file->getPathname()),strlen(str_replace('\\','/',$stage))+1));$zip->close();$check=new ZipArchive();if($check->open($zipPath)!==true){sm_remove_tree($stage);@unlink($zipPath);return false;}foreach($manifest['files'] as $entry){$stream=$check->getStream($entry['path']);if(!$stream){$check->close();sm_remove_tree($stage);@unlink($zipPath);return false;}$ctx=hash_init('sha256');$bytes=0;while(!feof($stream)){$chunk=fread($stream,1048576);if($chunk===false)break;$bytes+=strlen($chunk);hash_update($ctx,$chunk);}fclose($stream);if($bytes!==$entry['size']||!hash_equals($entry['sha256'],hash_final($ctx))){$check->close();sm_remove_tree($stage);@unlink($zipPath);return false;}}$check->close();sm_remove_tree($stage);$manifest['zip_bytes']=filesize($zipPath);$manifest['verified']=true;file_put_contents(sm_manifest_path($backupId),json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));return $manifest;
}
function sm_remove_tree(string $dir): void {if(!is_dir($dir))return;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $f)$f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname());@rmdir($dir);}
function sm_backup_valid(string $id,string $title,string $profileKey): array|false {
    if(!preg_match('/^save_[A-Z]{4}\d{5}_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/',$id)||!sm_is_valid_save_title_id($title))return false;$mfile=sm_manifest_path($id);$zipfile=sm_backup_dir().'/'.$id.'.zip';if(!is_file($mfile)||!is_file($zipfile))return false;$m=json_decode((string)file_get_contents($mfile),true);if(!is_array($m)||($m['title_id']??'')!==$title||!hash_equals((string)($m['profile_key']??''),$profileKey)||empty($m['verified'])||!is_array($m['files']??null))return false;$z=new ZipArchive();if($z->open($zipfile)!==true)return false;foreach($m['files'] as $entry){$path=(string)($entry['path']??'');if(!sm_is_safe_savedata_archive_path($path,$title)||!isset($entry['size'],$entry['sha256'])||!preg_match('/^[a-f0-9]{64}$/',(string)$entry['sha256'])){$z->close();return false;}$stream=$z->getStream($path);if(!$stream){$z->close();return false;}$ctx=hash_init('sha256');$bytes=0;while(!feof($stream)){$chunk=fread($stream,1048576);if($chunk===false)break;$bytes+=strlen($chunk);hash_update($ctx,$chunk);}fclose($stream);if($bytes!==(int)$entry['size']||!hash_equals($entry['sha256'],hash_final($ctx))){$z->close();return false;}}$z->close();return $m;
}
function sm_delete_path(string $ip,int $port,string $path): bool {if(!sm_is_safe_savedata_ftp_path($path))return false;[$r]=sm_curl($ip,$port,'/','LIST',null,'DELE '.$path);return $r!==false;}
function sm_upload_path(string $ip,int $port,string $remote,string $local): bool {if(function_exists('ftp_connect'))return ghm_ftp_put($ip,$port,$remote,$local,false,900);[$r]=sm_curl($ip,$port,$remote,'GET',$local);return $r!==false;}
function sm_remote_matches_backup(string $ip,int $port,array $target,array $manifest): bool {
    $save='/user/home/'.$target['profile'].'/savedata/'.$target['id'];$meta='/user/home/'.$target['profile'].'/savedata_meta/user/'.$target['id'];$all=[];$n=0;if(!sm_walk($ip,$port,$save,0,$all,$n))return false;$n=0;if(sm_walk($ip,$port,$meta,0,$all,$n)===false)return false;$remote=[];foreach($all as $f){$base=str_starts_with($f['path'],$save.'/')?$save:$meta;$kind=$base===$save?'savedata':'savedata_meta';$remote[]=['path'=>$kind.'/'.$target['id'].'/'.substr($f['path'],strlen($base)+1),'remote'=>$f['path'],'size'=>$f['size']];}
    // Se comparan las rutas relativas completas, tamaños y hashes; el borrado se bloquea si algo cambió desde el respaldo.
    $expected=[];foreach($manifest['files'] as $f)$expected[$f['path']]=$f;$actual=[];foreach($remote as $f){$rel=$f['path'];$prefix='savedata/'.$target['id'].'/';$metaPrefix='savedata_meta/'.$target['id'].'/';if(str_starts_with($rel,$prefix))$rel=$prefix.substr($rel,strlen($prefix));elseif(str_starts_with($rel,$metaPrefix))$rel=$metaPrefix.substr($rel,strlen($metaPrefix));$actual[$rel]=$f;}
    if(count($expected)!==count($actual))return false;foreach($expected as $rel=>$exp){if(!isset($actual[$rel])||$actual[$rel]['size']!==$exp['size'])return false;$tmp=tempnam(sys_get_temp_dir(),'ghm_verify_');$ok=ghm_ftp_download($ip,$port,$actual[$rel]['remote'],$tmp,900);$hash=$ok?hash_file('sha256',$tmp):false;@unlink($tmp);if(!$hash||!hash_equals($exp['sha256'],$hash))return false;}return true;
}
function sm_storage_report(string $ip,int $port): array {
    $titles=sm_title_map($ip,$port);$installed=sm_installed_ids($ip,$port);
    if($titles===false||$installed===false)throw new RuntimeException('No se pudo leer de forma fiable la lista de juegos.');
    $categories=[];$errors=[];$limited=false;$lowerBound=false;
    $mntRows=sm_list($ip,$port,'/mnt');if($mntRows===false)throw new RuntimeException('No se pudo verificar el almacenamiento extendido.');
    $mounts=[];foreach($mntRows as $row)if($row['dir']&&preg_match('/^ext[01]$/i',$row['name']))$mounts[]='/mnt/'.$row['name'];
    $patchIds=sm_optional_title_ids($ip,$port,'patch',$mounts);$addcontIds=sm_optional_title_ids($ip,$port,'addcont',$mounts);
    if($patchIds===false){$patchIds=[];$errors[]='user/patch';$lowerBound=true;}
    if($addcontIds===false){$addcontIds=[];$errors[]='user/addcont';$lowerBound=true;}
    foreach($installed as $id=>$_){
        $title=$titles[$id]??$id;$baseBytes=0;$baseFiles=0;$entries=0;$capped=false;$found=false;
        foreach(['/user/app/'.$id,'/mnt/ext0/user/app/'.$id,'/mnt/ext1/user/app/'.$id] as $root){
            $baseBytes=0;$baseFiles=0;$entries=0;$capped=false;
            if(sm_tree_stats($ip,$port,$root,0,$baseBytes,$baseFiles,$entries,$capped)){$found=true;break;}
        }
        if(!$found){$errors[]='app/'.$id;continue;}
        $updateBytes=0;$updateFiles=0;$dlcBytes=0;$dlcFiles=0;$contentEntries=$entries;$contentCapped=$capped;
        if(isset($patchIds[$id]))foreach(['/user/patch/'.$id,'/mnt/ext0/user/patch/'.$id,'/mnt/ext1/user/patch/'.$id] as $root){$b=0;$f=0;$n=0;$cap=false;if(sm_tree_stats($ip,$port,$root,0,$b,$f,$n,$cap)){$updateBytes=$b;$updateFiles=$f;$contentEntries+=$n;$contentCapped=$contentCapped||$cap;break;}}
        if(isset($addcontIds[$id]))foreach(['/user/addcont/'.$id,'/mnt/ext0/user/addcont/'.$id,'/mnt/ext1/user/addcont/'.$id] as $root){$b=0;$f=0;$n=0;$cap=false;if(sm_tree_stats($ip,$port,$root,0,$b,$f,$n,$cap)){$dlcBytes=$b;$dlcFiles=$f;$contentEntries+=$n;$contentCapped=$contentCapped||$cap;break;}}
        $bytes=$baseBytes+$updateBytes+$dlcBytes;$files=$baseFiles+$updateFiles+$dlcFiles;
        $categories['juegos y aplicaciones']['bytes']=($categories['juegos y aplicaciones']['bytes']??0)+$bytes;
        $categories['juegos y aplicaciones']['items'][]=['title'=>$title,'title_id'=>$id,'icon'=>sm_cache_icon($ip,$port,$id),'bytes'=>$bytes,'base_bytes'=>$baseBytes,'update_bytes'=>$updateBytes,'dlc_bytes'=>$dlcBytes,'files'=>$files];
        $lowerBound=$lowerBound||$contentCapped;$limited=$limited||$contentEntries>=50000;
    }
    $saveSizeCapped=false;$rows=sm_save_rows($ip,$port,$saveSizeCapped);if($rows===false)throw new RuntimeException('No se pudieron leer las partidas.');$lowerBound=$lowerBound||$saveSizeCapped;
    $categories['partidas']=['bytes'=>0,'items'=>[]];
    foreach($rows as $save){$sum=(float)$save['bytes']+(float)$save['metadata_bytes'];$categories['partidas']['bytes']=(float)($categories['partidas']['bytes']??0)+$sum;$categories['partidas']['items'][]=['title'=>$save['title'],'title_id'=>$save['title_id'],'profile'=>$save['profile'],'icon'=>$save['icon'],'bytes'=>$sum,'files'=>$save['files']+$save['metadata_files']];}
    $capRoot='/user/av_contents/photo';$caps=[];$count=0;
    if(sm_capture_walk($ip,$port,$capRoot,0,$caps,$count)){
        $images=array_values(array_filter($caps,fn($f)=>in_array(strtolower(pathinfo($f['name'],PATHINFO_EXTENSION)),['jpg','jpeg','png'],true)));
        $categories['capturas']=['bytes'=>sm_sum_sizes($images),'items'=>array_map(function($f)use($ip,$port){$id='';if(preg_match('/(CUSA[A-Z0-9]{5})/i',$f['path'],$m))$id=strtoupper($m[1]);$ext=strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));$thumb=__DIR__.'/../user/cache/capturas/'.md5($f['path']).'.'.$ext;$url=is_file($thumb)?'user/cache/capturas/'.md5($f['path']).'.'.$ext:'api/stream_image_api.php?ip='.rawurlencode($ip).'&port='.$port.'&path='.rawurlencode($f['path']);return ['title'=>$f['name'],'title_id'=>$id,'profile'=>'','icon'=>$url,'bytes'=>$f['size'],'files'=>1];},$images)];
        foreach($images as $image)if(!empty($image['size_capped']))$lowerBound=true;
    }else{$categories['capturas']=['bytes'=>0,'items'=>[]];$errors[]='av_contents/photo';}
    $total=0.0;foreach($categories as &$cat){if(!isset($cat['items']))$cat['items']=[];$cat['bytes']=(float)$cat['bytes'];$total+=(float)$cat['bytes'];}unset($cat);
    return ['categories'=>$categories,'total_bytes'=>$total,'capacity_bytes'=>null,'free_bytes'=>null,'capacity_source'=>'unsupported_by_goldhen_ftp','scan_errors'=>$errors,'limited'=>$limited||$count>5000,'lower_bound'=>$lowerBound||count($errors)>0];
}

$action=$_POST['action']??$_GET['action']??'';
if($action==='download'){$id=(string)($_GET['backup_id']??'');if(!preg_match('/^save_[A-Z]{4}\d{5}_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/',$id))sm_out(['status'=>'error','message'=>'Respaldo no válido.'],400);$path=sm_backup_dir().'/'.$id.'.zip';$manifest=sm_manifest_path($id);if(!is_file($path)||!is_file($manifest))sm_out(['status'=>'error','message'=>'Respaldo no encontrado.'],404);header_remove('Content-Type');header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.$id.'.zip"');header('Content-Length: '.filesize($path));readfile($path);exit;}
$ip=sm_host($_POST['host_ip']??$_GET['host_ip']??'');$port=(int)($_POST['port']??$_GET['port']??2121);if(!$ip||!in_array($port,[2121,2122],true))sm_out(['status'=>'error','message'=>'IP o puerto FTP inválido.'],400);
try {
 if($action==='scan'){$saves=sm_save_rows($ip,$port);if($saves===false)sm_out(['status'=>'error','message'=>'No se pudo completar la lectura FTP de perfiles y títulos.']);$orphans=array_values(array_filter($saves,fn($s)=>!$s['installed']));sm_out(['status'=>'success','saves'=>$saves,'orphan_bytes'=>array_sum(array_map(fn($s)=>$s['bytes']+$s['metadata_bytes'],$orphans)),'orphan_count'=>count($orphans)]);}
 if($action==='storage')sm_out(['status'=>'success','report'=>sm_storage_report($ip,$port)]);
 if($action==='list_backups'){$key=(string)($_POST['profile_key']??'');$id=strtoupper((string)($_POST['title_id']??''));$target=sm_lookup($ip,$port,$key,$id);if(!$target)sm_out(['status'=>'error','message'=>'Perfil o ID de juego inválido.']);$items=[];foreach(glob(sm_backup_dir().'/save_'.$id.'_*.json')?:[] as $file){$m=json_decode((string)file_get_contents($file),true);if(is_array($m)&&hash_equals((string)($m['profile_key']??''),$key)&&!empty($m['verified']))$items[]=['id'=>$m['id'],'created_at'=>$m['created_at']??'','total_bytes'=>$m['total_bytes']??0,'zip_bytes'=>$m['zip_bytes']??0,'download'=>'api/saves_api.php?action=download&backup_id='.$m['id']];}usort($items,fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));sm_out(['status'=>'success','backups'=>$items]);}
 if($action==='list_all_backups'){$items=[];$profileLabels=[];$profiles=sm_profiles($ip,$port);if($profiles!==false)foreach($profiles as $i=>$folder)$profileLabels[hash('sha256','ghm-save-profile:'.$folder)]=sm_profile_name($ip,$port,$folder,$i);foreach(glob(sm_backup_dir().'/save_*.json')?:[] as $file){$m=json_decode((string)file_get_contents($file),true);if(!is_array($m)||empty($m['verified'])||!preg_match('/^save_[A-Z]{4}\d{5}_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/',(string)($m['id']??''))||!is_file(sm_backup_dir().'/'.$m['id'].'.zip'))continue;$profileKey=(string)($m['profile_key']??'');$items[]=['id'=>$m['id'],'title_id'=>$m['title_id']??'','title'=>(['CUSA00419'=>'Grand Theft Auto V','CUSA12824'=>'Hogwarts Legacy'][$m['title_id']??'']??($m['title_id']??'Partida')),'profile'=>$profileLabels[$profileKey]??($m['profile']??'Usuario PS4 sin nombre'),'profile_key'=>$profileKey,'created_at'=>$m['created_at']??'','total_bytes'=>$m['total_bytes']??0,'zip_bytes'=>$m['zip_bytes']??0,'download'=>'api/saves_api.php?action=download&backup_id='.$m['id']];}usort($items,fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));sm_out(['status'=>'success','backups'=>$items]);}
 if($action==='backup'){$key=(string)($_POST['profile_key']??'');$id=strtoupper((string)($_POST['title_id']??''));$target=sm_lookup($ip,$port,$key,$id);if(!$target)sm_out(['status'=>'error','message'=>'Perfil o ID de juego no válido.']);$m=sm_download_backup($ip,$port,$target);if(!$m)sm_out(['status'=>'error','message'=>'No se pudo crear y verificar el ZIP con partida y metadatos.']);sm_out(['status'=>'success','backup'=>$m,'download'=>'api/saves_api.php?action=download&backup_id='.$m['id']]);}
 if(in_array($action,['delete','delete_permanent','cleanup_delete'],true)){
    $key=(string)($_POST['profile_key']??'');$id=strtoupper((string)($_POST['title_id']??''));$backup=(string)($_POST['backup_id']??'');$target=sm_lookup($ip,$port,$key,$id);$m=$target&&$backup!==''?sm_backup_valid($backup,$id,$key):false;
    $cleanup=$action==='cleanup_delete';$permanent=sm_is_explicit_permanent_save_delete($action,(string)($_POST['permanent']??'0'));
    if(!$target)sm_out(['status'=>'error','message'=>'Borrado bloqueado: perfil o ID de juego inválido.']);
    if($action==='delete_permanent'&&!$permanent)sm_out(['status'=>'error','message'=>'Borrado bloqueado: falta la confirmación explícita de borrado permanente.']);
    if($cleanup){$installed=sm_installed_ids($ip,$port);if($installed===false)sm_out(['status'=>'error','message'=>'Limpieza bloqueada: no se pudo verificar la lista de juegos instalados.']);if(isset($installed[$id]))sm_out(['status'=>'error','message'=>'Limpieza bloqueada: este ID de juego aparece instalado. Actualiza la lista antes de continuar.']);}
    if(!$permanent&&!$m)sm_out(['status'=>'error','message'=>'Borrado bloqueado: falta un respaldo íntegro y coincidente de este perfil o ID de juego.']);
    if($m&&!sm_remote_matches_backup($ip,$port,$target,$m))sm_out(['status'=>'error','message'=>'La partida cambió después del respaldo o no coincide con él. Actualiza, crea un respaldo nuevo y vuelve a revisar.']);
    $saveRoot='/user/home/'.$target['profile'].'/savedata/'.$id;$metaParent='/user/home/'.$target['profile'].'/savedata_meta/user';$metaRoot=$metaParent.'/'.$id;$rows=[];$count=0;if(!sm_walk($ip,$port,$saveRoot,0,$rows,$count))sm_out(['status'=>'error','message'=>'Borrado cancelado: no se pudo enumerar por completo la partida.']);$meta=[];$count=0;$metaDirs=sm_list($ip,$port,$metaParent);if($metaDirs===false)sm_out(['status'=>'error','message'=>'Borrado cancelado: no se pudo verificar la carpeta de metadatos.']);$metaExists=false;foreach($metaDirs as $entry)if($entry['dir']&&strcasecmp($entry['name'],$id)===0)$metaExists=true;if($metaExists&&!sm_walk($ip,$port,$metaRoot,0,$meta,$count))sm_out(['status'=>'error','message'=>'Borrado cancelado: no se pudieron enumerar por completo los metadatos.']);$errors=[];
    foreach(array_merge($rows,$meta) as $f)if(!sm_is_safe_savedata_ftp_path((string)$f['path']))sm_out(['status'=>'error','message'=>'Borrado bloqueado: la ruta contiene un nombre FTP no compatible con el borrado seguro. No se eliminó ningún archivo.']);
    foreach(array_merge($rows,$meta) as $f)if(!sm_delete_path($ip,$port,$f['path']))$errors[]=$f['path'];
    $dirs=[];foreach([$saveRoot,$metaRoot] as $base)foreach(array_merge($rows,$meta) as $f)if(str_starts_with($f['path'],$base.'/')){$parent=dirname($f['path']);while($parent!==$base&&str_starts_with($parent,$base.'/')){$dirs[$parent]=true;$parent=dirname($parent);}$dirs[$base]=true;}
    $dirList=array_keys($dirs);usort($dirList,fn($a,$b)=>strlen($b)<=>strlen($a));foreach($dirList as $d){[$r]=sm_curl($ip,$port,'/','LIST',null,'RMD '.$d);}
    $parents=['/user/home/'.$target['profile'].'/savedata','/user/home/'.$target['profile'].'/savedata_meta/user'];$still=[];$unverified=[];foreach($parents as $p){$items=sm_list($ip,$port,$p);if($items===false){$unverified[]=$p;continue;}foreach($items as $it)if(strtoupper($it['name'])===$id)$still[]=$p.'/'.$it['name'];}
    if($still||$errors||$unverified)sm_out(['status'=>'error','message'=>'Borrado parcial o no confirmado. '.($unverified?'No se pudo verificar la ausencia en todas las carpetas FTP. ':'').($m?'El respaldo íntegro permanece disponible para restaurar.':'No se creó respaldo.'),'backup_id'=>$backup,'failed_files'=>$errors,'remaining'=>$still,'unverified_parents'=>$unverified]);
    sm_out(['status'=>'success','message'=>'Partida y metadatos eliminados'.($m?'; respaldo verificado disponible.':'.'),'backup_id'=>$backup]);
 }
 if($action==='restore'){
    $backup=(string)($_POST['backup_id']??'');$id=strtoupper((string)($_POST['title_id']??''));$key=(string)($_POST['profile_key']??'');$target=sm_lookup($ip,$port,$key,$id);$m=$target?sm_backup_valid($backup,$id,$key):false;
    if(!$target||!$m)sm_out(['status'=>'error','message'=>'Respaldo no válido o no corresponde al perfil o ID de juego.']);
    $saveRoot='/user/home/'.$target['profile'].'/savedata/'.$id;$current=[];$n=0;$safeBackup=null;if(sm_walk($ip,$port,$saveRoot,0,$current,$n)&&$current){$safeBackup=sm_download_backup($ip,$port,$target);if(!$safeBackup)sm_out(['status'=>'error','message'=>'No se pudo respaldar el estado actual; se canceló la restauración.']);}
    $zipPath=sm_backup_dir().'/'.$backup.'.zip';$z=new ZipArchive();if($z->open($zipPath)!==true)sm_out(['status'=>'error','message'=>'No se pudo abrir el respaldo.']);$errors=[];
    foreach($m['files'] as $entry){$stream=$z->getStream($entry['path']);$tmp=tempnam(sys_get_temp_dir(),'ghm_save_');$out=@fopen($tmp,'wb');if(!$stream||!$out){if($stream)fclose($stream);if($out)fclose($out);$errors[]=$entry['path'];@unlink($tmp);continue;}$copied=stream_copy_to_stream($stream,$out);fclose($stream);fclose($out);if($copied!==$entry['size']||!hash_equals($entry['sha256'],hash_file('sha256',$tmp))){$errors[]=$entry['path'];@unlink($tmp);continue;}
        $kind=str_starts_with($entry['path'],'savedata_meta/')?'savedata_meta':'savedata';$base=$kind==='savedata'?$saveRoot:'/user/home/'.$target['profile'].'/savedata_meta/user/'.$id;$rel=substr($entry['path'],strlen($kind.'/'.$id));$remote=$base.$rel;$ok=sm_upload_path($ip,$port,$remote,$tmp);@unlink($tmp);if(!$ok)$errors[]=$entry['path'];
    }$z->close();
    if(!$errors){foreach($m['files'] as $entry){$kind=str_starts_with($entry['path'],'savedata_meta/')?'savedata_meta':'savedata';$base=$kind==='savedata'?$saveRoot:'/user/home/'.$target['profile'].'/savedata_meta/user/'.$id;$rel=substr($entry['path'],strlen($kind.'/'.$id));$remote=$base.$rel;$tmp=tempnam(sys_get_temp_dir(),'ghm_check_');$ok=ghm_ftp_download($ip,$port,$remote,$tmp,900);$verified=$ok&&filesize($tmp)===$entry['size']&&hash_equals($entry['sha256'],hash_file('sha256',$tmp));@unlink($tmp);if(!$verified)$errors[]=$entry['path'];}}
    if($errors)sm_out(['status'=>'error','message'=>'Restauración incompleta o no verificada; se conserva el ZIP para reintentar.','failed_files'=>$errors,'safety_backup'=>$safeBackup['id']??null]);sm_out(['status'=>'success','message'=>'Partida y metadatos restaurados y verificados.','safety_backup'=>$safeBackup['id']??null]);
 }
 sm_out(['status'=>'error','message'=>'Acción no válida.'],400);
}catch(Throwable $e){sm_out(['status'=>'error','message'=>'Error controlado del gestor de partidas: '.$e->getMessage()],500);}
