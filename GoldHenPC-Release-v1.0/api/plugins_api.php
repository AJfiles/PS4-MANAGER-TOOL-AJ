<?php
require_once __DIR__ . '/request_guard.php';
require_once __DIR__ . '/ftp_compat.php';
error_reporting(0);
ini_set('display_errors', 0);
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$host = $_POST['host_ip'] ?? $_GET['host_ip'] ?? '';
$port = 2121;
$local_dirs = [__DIR__ . '/../user/plugins', __DIR__ . '/../plugins'];
$remote_dir = '/data/GoldHEN/plugins';
$ini_path = '/data/GoldHEN/plugins.ini';

function responder_plugins($data) { echo json_encode($data); exit; }
function validar_host_plugins($host) { return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4); }
function nombre_prx($name) { $name = basename($name); return preg_match('/^[A-Za-z0-9._-]+\.prx$/i', $name) ? $name : false; }
function seccion_valida($section) { return $section === 'default' || preg_match('/^[A-Z0-9_-]{1,32}$/i', $section); }
function ftp_url_plugins($host, $path) { return 'ftp://' . $host . ':2121' . implode('/', array_map('rawurlencode', explode('/', $path))); }
function ftp_list_plugins($host, $path) {
    if (function_exists('ftp_connect')) { $rows=ghm_ftp_list($host,2121,$path,15); return $rows===false?[]:array_map(static fn($r)=>['name'=>$r['name'],'size'=>$r['size'],'is_dir'=>$r['dir']],$rows); }
    $ch = curl_init(ftp_url_plugins($host, rtrim($path, '/') . '/'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'LIST', CURLOPT_TIMEOUT => 10]);
    $raw = curl_exec($ch); curl_close($ch);
    $out = [];
    foreach (explode("\n", trim((string)$raw)) as $line) {
        $parts = preg_split('/\s+/', trim($line), 9);
        if (count($parts) >= 9 && $parts[8] !== '.' && $parts[8] !== '..') $out[] = ['name' => $parts[8], 'size' => (int)$parts[4], 'is_dir' => $parts[0][0] === 'd'];
    }
    return $out;
}
function ftp_get_plugins($host, $path) {
    if (function_exists('ftp_connect')) return ghm_ftp_get($host,2121,$path,30);
    $ch = curl_init(ftp_url_plugins($host, $path));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $data = curl_exec($ch); curl_close($ch); return $data;
}
function ftp_put_plugins($host, $path, $data) {
    $tmp = tempnam(sys_get_temp_dir(), 'ghm_'); file_put_contents($tmp, $data);
    if (function_exists('ftp_connect')) { $ok=ghm_ftp_put($host,2121,$path,$tmp,false,60); @unlink($tmp); return $ok; }
    $fp = fopen($tmp, 'r'); $ch = curl_init(ftp_url_plugins($host, $path));
    curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fp, CURLOPT_INFILESIZE => filesize($tmp), CURLOPT_FTP_CREATE_MISSING_DIRS => true, CURLOPT_TIMEOUT => 60]);
    $ok = curl_exec($ch); curl_close($ch); fclose($fp); @unlink($tmp); return $ok;
}
function parse_ini_plugins($text) {
    $sections = []; $current = null;
    foreach (preg_split('/\R/', (string)$text) as $line) {
        $line = trim($line);
        if ($line === '' || substr($line, 0, 1) === '#' || substr($line, 0, 1) === ';') continue;
        if (preg_match('/^\[([^\]]+)\]$/', $line, $m)) { $current = strcasecmp($m[1], 'default') === 0 ? 'default' : strtoupper($m[1]); continue; }
        if ($current === null) continue;
        preg_match_all('#/data/GoldHEN/plugins/[A-Za-z0-9._-]+\.prx#i', $line, $matches);
        foreach ($matches[0] as $path) {
            if (!isset($sections[$current])) $sections[$current] = [];
            if (!in_array($path, $sections[$current], true)) $sections[$current][] = $path;
        }
    }
    if (!isset($sections['default'])) $sections = ['default' => []] + $sections;
    return $sections;
}
function diagnosticar_ini_plugins($raw, $remoteNames = false) {
    $issues = []; $addIssue = static function($severity, $code, $message) use (&$issues) { $issues[] = ['severity'=>$severity, 'code'=>$code, 'message'=>$message]; };
    $text = (string)$raw; $invalidUtf8 = preg_match('//u', $text) !== 1;
    if (str_starts_with($text, "\xEF\xBB\xBF")) $text = substr($text, 3);
    if ($invalidUtf8) $addIssue('warning', 'invalid_encoding', 'El archivo contiene bytes que no forman UTF-8 válido; se revisarán las líneas y se excluirá la basura del resultado normalizado.');
    $sections = ['default'=>['name'=>'default','paths'=>[],'pathKeys'=>[],'comments'=>[]]]; $current = null; $occurrences = [];
    $lines = preg_split('/\r\n|\r|\n/', $text); $pathPattern = '#/data/GoldHEN/plugins/[A-Za-z0-9._-]+\.prx#i';
    foreach ($lines as $index => $line) {
        $lineNo = $index + 1; $trimmed = trim($line);
        if ($trimmed === '') continue;
        if ($trimmed[0] === '#' || $trimmed[0] === ';') { if ($current !== null && preg_match('//u',$trimmed)) $sections[$current]['comments'][] = $trimmed; elseif ($current !== null) $addIssue('warning','invalid_comment_bytes',"Se omitió un comentario con bytes no válidos en la línea $lineNo."); continue; }
        $headerLine = $trimmed;
        if (!preg_match('/^\[([A-Za-z0-9_-]{1,32})\]$/', $headerLine, $header)) {
            // Salvage a section header only when invalid/control bytes precede it;
            // never reinterpret ordinary printable text as an INI section.
            if (preg_match('/^([^\[\]\r\n]*)\[([A-Za-z0-9_-]{1,32})\]\s*$/', $line, $embedded) && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\xFF]/', $embedded[1])) {
                $header = [0, $embedded[2]];
                $addIssue('warning', 'header_prefix_bytes', "Se detectó ruido binario antes de la sección de la línea $lineNo; la vista previa recupera el encabezado válido.");
            } else $header = null;
        }
        if ($header !== null) {
            $name = strcasecmp($header[1], 'default') === 0 ? 'default' : strtoupper($header[1]); $key = strtolower($name);
            if (isset($occurrences[$key])) {
                $occurrences[$key]++;
                if ($occurrences[$key] === 2) $addIssue('warning', 'duplicate_section', "La sección [$name] aparece más de una vez; la reparación unirá sus plugins.");
            } else $occurrences[$key] = 1;
            if (!isset($sections[$key])) $sections[$key] = ['name'=>$name,'paths'=>[],'pathKeys'=>[],'comments'=>[]];
            $current = $key; continue;
        }
        if (preg_match_all($pathPattern, $trimmed, $matches) && !empty($matches[0])) {
            $residual = preg_replace($pathPattern, '', $trimmed); $residual = trim((string)$residual);
            if ($current === null) { $addIssue('error', 'path_without_section', "Hay una ruta de plugin fuera de una sección en la línea $lineNo; no se moverá automáticamente."); continue; }
            if ($residual !== '') { $addIssue('warning', 'malformed_line', "La línea $lineNo mezcla una ruta válida con texto no reconocido; el texto sobrante se omitirá en la vista previa."); continue; }
            if (count($matches[0]) > 1) $addIssue('warning', 'multiple_paths_line', "La línea $lineNo contiene varias rutas; la vista previa las separará, una por línea.");
            if ($line !== $trimmed) $addIssue('info', 'whitespace', "Se limpiarán espacios laterales en la línea $lineNo.");
            foreach ($matches[0] as $path) {
                $pathKey = strtolower($path);
                if (isset($sections[$current]['pathKeys'][$pathKey])) { $addIssue('warning', 'duplicate_plugin', "La ruta $path está repetida en [{$sections[$current]['name']}]; se conservará una sola vez."); continue; }
                $sections[$current]['pathKeys'][$pathKey] = true; $sections[$current]['paths'][] = $path;
            }
            continue;
        }
        if (preg_match('/^\[.*\]$/', $trimmed)) $addIssue('error', 'invalid_section', "Encabezado de sección no válido en la línea $lineNo; se omitirá de la vista previa.");
        else $addIssue('warning', 'unrecognized_line', "Contenido no reconocido en la línea $lineNo; se omitirá de la vista previa.");
    }
    foreach ($sections as $key => $section) {
        if ($key !== 'default' && !$section['paths']) $addIssue('info', 'empty_section', "La sección [{$section['name']}] no tiene plugins activos y se quitará de la vista previa.");
    }
    $allPaths = []; $missing = [];
    foreach ($sections as $section) foreach ($section['paths'] as $path) { $allPaths[] = $path; if (is_array($remoteNames) && !in_array(basename($path), $remoteNames, true)) $missing[] = basename($path); }
    if (is_array($remoteNames)) {
        $missing = array_values(array_unique($missing));
        if ($missing) $addIssue('warning', 'missing_plugin_files', 'No se encontraron en la carpeta de plugins: ' . implode(', ', array_slice($missing, 0, 8)) . (count($missing) > 8 ? ' y ' . (count($missing)-8) . ' más.' : '.'));
    } else $addIssue('info', 'plugin_inventory_unavailable', 'No se pudo leer la carpeta de plugins; no se comprobó si las rutas asignadas tienen su archivo .prx.');
    $out = ['[default]'];
    foreach ($sections['default']['comments'] as $comment) $out[] = $comment;
    foreach ($sections['default']['paths'] as $path) $out[] = $path;
    foreach ($sections as $key => $section) {
        if ($key === 'default' || !$section['paths']) continue;
        $out[] = ''; $out[] = '[' . $section['name'] . ']';
        foreach ($section['comments'] as $comment) $out[] = $comment;
        foreach ($section['paths'] as $path) $out[] = $path;
    }
    $preview = implode("\n", $out) . "\n";
    return ['issues'=>$issues,'preview'=>$preview,'preview_changed'=>!hash_equals(hash('sha256',(string)$raw),hash('sha256',$preview)),'sha256'=>hash('sha256',(string)$raw),'invalid_utf8'=>$invalidUtf8,'sections'=>count($sections),'assignments'=>count($allPaths),'missing_files'=>$missing,'remote_plugins'=>is_array($remoteNames)?count($remoteNames):null];
}
function listar_nombres_plugins($host, $path) {
    if (function_exists('ftp_connect')) { $rows=ghm_ftp_list($host,2121,$path,15); if($rows===false)return false; return array_values(array_unique(array_map(static fn($r)=>$r['name'],array_filter($rows,static fn($r)=>!$r['dir']&&nombre_prx($r['name']))))); }
    $ch = curl_init(ftp_url_plugins($host, rtrim($path, '/') . '/'));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>'LIST', CURLOPT_CONNECTTIMEOUT=>6, CURLOPT_TIMEOUT=>12]);
    $raw = curl_exec($ch); curl_close($ch);
    if ($raw === false) return false;
    $names=[]; foreach (explode("\n", trim((string)$raw)) as $line) { $parts=preg_split('/\s+/',trim($line),9); if(count($parts)>=9 && $parts[0][0]!=='d' && nombre_prx($parts[8])) $names[]=$parts[8]; }
    return array_values(array_unique($names));
}
function respaldar_plugins_ini($raw, $prefix='plugins') {
    $dir = __DIR__ . '/../user/backups/plugins';
    if (!is_dir($dir) && !@mkdir($dir,0770,true)) return false;
    $name = $prefix . '_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.ini'; $file = $dir . '/' . $name;
    if (@file_put_contents($file,$raw) !== strlen($raw)) { @unlink($file); return false; }
    $hash = @hash_file('sha256',$file);
    if (!is_string($hash) || !hash_equals(hash('sha256',$raw),$hash)) { @unlink($file); return false; }
    return $name;
}
function subir_plugins_ini_verificado($host, $old, $new) {
    $uploaded = ftp_put_plugins($host,$GLOBALS['ini_path'],$new);
    $readback = $uploaded ? ftp_get_plugins($host,$GLOBALS['ini_path']) : false;
    if ($readback !== false && hash_equals(hash('sha256',$new),hash('sha256',$readback))) return ['ok'=>true,'sha256'=>hash('sha256',$readback)];
    // FTP may truncate the target before reporting an upload error; always try to restore.
    $restorePut = ftp_put_plugins($host,$GLOBALS['ini_path'],$old);
    $restoreRead = $restorePut ? ftp_get_plugins($host,$GLOBALS['ini_path']) : false;
    $restored = $restoreRead !== false && hash_equals(hash('sha256',$old),hash('sha256',$restoreRead));
    return ['ok'=>false,'restored'=>$restored];
}
/* Modifica únicamente la sección y la ruta exactas solicitadas. Así se
   preservan comentarios, secciones desconocidas y asignaciones ajenas. */
function actualizar_ini_plugins($text, $section, $plugin_path, $enabled) {
    $lines = preg_split('/\R/', (string)$text);
    $out = []; $inside = false; $found_section = false; $inserted = false;
    foreach ($lines as $line) {
        if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $m)) {
            if ($inside && $enabled && !$inserted) { $out[] = $plugin_path; $inserted = true; }
            $inside = strcasecmp(trim($m[1]), $section) === 0;
            if ($inside) $found_section = true;
            $out[] = $line;
            continue;
        }
        $trimmed = trim($line);
        if (preg_match('#^(/data/GoldHEN/plugins/[A-Za-z0-9._-]+\.prx)$#i', $trimmed, $pluginMatch)) {
            $canonical = $pluginMatch[1];
            if ($inside && strcasecmp($canonical, $plugin_path) === 0) {
                if (!$enabled || $inserted) continue;
                $inserted = true;
            }
            // Rutas sin espacios laterales; elimina líneas vacías intercaladas entre PRX.
            $line = $canonical;
            if (trim(end($out) ?: '') === '' && count($out) >= 2 && preg_match('#^/data/GoldHEN/plugins/.+\.prx$#i', trim($out[count($out)-2]))) array_pop($out);
        }
        $out[] = $line;
    }
    if ($found_section) {
        if ($enabled && !$inserted) $out[] = $plugin_path;
    } elseif ($enabled) {
        if (count($out) && trim(end($out)) !== '') $out[] = '';
        $out[] = '[' . $section . ']';
        $out[] = $plugin_path;
    }
    return rtrim(implode("\n", $out)) . "\n";
}

if ($action === 'list_local') {
    $files = [];
    foreach ($local_dirs as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        foreach (glob($dir . '/*.prx') ?: [] as $file) $files[basename($file)] = ['name' => basename($file), 'size' => filesize($file), 'origin' => strpos($dir, '/user/') !== false ? 'usuario' : 'proyecto'];
    }
    responder_plugins(['status' => 'success', 'data' => array_values($files)]);
}
if ($action === 'upload_local') {
    if (!isset($_FILES['plugin']) || $_FILES['plugin']['error'] !== UPLOAD_ERR_OK || !($name = nombre_prx($_FILES['plugin']['name']))) {
        responder_plugins(['status' => 'error', 'message' => 'Selecciona un archivo .prx válido.']);
    }
    $target_dir = __DIR__ . '/../user/plugins'; @mkdir($target_dir, 0777, true);
    if (!move_uploaded_file($_FILES['plugin']['tmp_name'], $target_dir . '/' . $name)) responder_plugins(['status' => 'error', 'message' => 'No se pudo guardar el plugin.']);
    responder_plugins(['status' => 'success']);
}
if ($action === 'download_ini') {
    if (!validar_host_plugins($host)) responder_plugins(['status' => 'error', 'message' => 'IP de PS4 inválida.']);
    $ini = ftp_get_plugins($host, $ini_path);
    if ($ini === false) responder_plugins(['status' => 'error', 'message' => 'No se pudo leer plugins.ini.']);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="plugins.ini"');
    echo $ini; exit;
}
if (!validar_host_plugins($host)) responder_plugins(['status' => 'error', 'message' => 'IP de PS4 inválida.']);
if ($action === 'diagnose_ini') {
    $raw = ftp_get_plugins($host, $ini_path);
    if ($raw === false) responder_plugins(['status'=>'error','message'=>'No se pudo leer plugins.ini desde la PS4.']);
    $remoteNames = listar_nombres_plugins($host, $remote_dir);
    $diagnosis = diagnosticar_ini_plugins($raw, $remoteNames);
    responder_plugins(['status'=>'success','diagnosis'=>$diagnosis]);
}
if ($action === 'repair_ini') {
    $expected = strtolower(trim((string)($_POST['expected_sha256'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/', $expected)) responder_plugins(['status'=>'error','message'=>'La vista previa caducó. Ejecuta el diagnóstico otra vez.']);
    $raw = ftp_get_plugins($host, $ini_path);
    if ($raw === false) responder_plugins(['status'=>'error','message'=>'No se pudo releer plugins.ini antes de reparar.']);
    if (!hash_equals($expected, hash('sha256',$raw))) { http_response_code(409); responder_plugins(['status'=>'error','message'=>'plugins.ini cambió después de la vista previa. Vuelve a diagnosticar para revisar el contenido actual.']); }
    $remoteNames = listar_nombres_plugins($host, $remote_dir);
    $diagnosis = diagnosticar_ini_plugins($raw, $remoteNames); $preview = $diagnosis['preview'];
    if (!$diagnosis['preview_changed']) responder_plugins(['status'=>'success','changed'=>false,'message'=>'No se requieren cambios de formato. Las advertencias de archivos .prx ausentes deben revisarse por separado.','sections'=>parse_ini_plugins($raw)]);
    $backupName = respaldar_plugins_ini($raw,'plugins_diagnostico');
    if ($backupName === false) responder_plugins(['status'=>'error','message'=>'No se pudo crear y validar el respaldo local. La PS4 no se modificó.']);
    $write = subir_plugins_ini_verificado($host,$raw,$preview);
    if (!$write['ok']) responder_plugins(['status'=>'error','message'=>'No se pudo confirmar la reparación.' . ($write['restored']?' Se restauró y verificó el archivo anterior.':' No se pudo confirmar la restauración; conserva el respaldo local para recuperarlo.'),'backup'=>$backupName]);
    $readback = $preview;
    responder_plugins(['status'=>'success','changed'=>true,'message'=>'plugins.ini reparado y verificado por SHA-256.','backup'=>$backupName,'sha256'=>hash('sha256',$readback),'sections'=>parse_ini_plugins($readback),'diagnosis'=>diagnosticar_ini_plugins($readback,$remoteNames)]);
}
if ($action === 'import_ini') {
    if (!isset($_FILES['ini']) || $_FILES['ini']['error'] !== UPLOAD_ERR_OK || $_FILES['ini']['size'] > 524288) responder_plugins(['status' => 'error', 'message' => 'Selecciona un plugins.ini de hasta 512 KB.']);
    $uploadedIni = file_get_contents($_FILES['ini']['tmp_name']);
    if ($uploadedIni === false) responder_plugins(['status'=>'error','message'=>'No se pudo leer el archivo seleccionado.']);
    $new = diagnosticar_ini_plugins($uploadedIni,false)['preview'];
    $current = null;
    foreach (preg_split('/\R/', (string)$new) as $line) {
        $trim = trim($line);
        if ($trim === '' || $trim[0] === '#' || $trim[0] === ';') continue;
        if (preg_match('/^\[([^\]]+)\]$/', $trim, $m)) { $current = $m[1]; if (!seccion_valida($current)) responder_plugins(['status' => 'error', 'message' => 'El archivo contiene una sección no permitida.']); continue; }
        if ($current === null || !preg_match('#^/data/GoldHEN/plugins/[A-Za-z0-9._-]+\.prx$#i', $trim)) responder_plugins(['status' => 'error', 'message' => 'El archivo contiene una ruta de plugin no permitida.']);
    }
    $old = ftp_get_plugins($host, $ini_path); if ($old === false) responder_plugins(['status'=>'error','message'=>'No se pudo leer y respaldar el plugins.ini actual; la PS4 no se modificó.']);
    $backup = respaldar_plugins_ini($old,'plugins_antes_importar'); if ($backup === false) responder_plugins(['status'=>'error','message'=>'No se pudo validar un respaldo local; la PS4 no se modificó.']);
    $write = subir_plugins_ini_verificado($host,$old,rtrim($new) . "\n");
    if (!$write['ok']) responder_plugins(['status'=>'error','message'=>'No se pudo verificar el plugins.ini importado.' . ($write['restored']?' Se restauró y verificó el archivo anterior.':' No se pudo confirmar la restauración; conserva el respaldo local.'),'backup'=>$backup]);
    responder_plugins(['status' => 'success', 'sections' => parse_ini_plugins($new)]);
}
if ($action === 'list_remote') responder_plugins(['status' => 'success', 'data' => array_values(array_filter(ftp_list_plugins($host, $remote_dir), fn($f) => !$f['is_dir'] && nombre_prx($f['name'])))]);
if ($action === 'get_ini') responder_plugins(['status' => 'success', 'sections' => parse_ini_plugins(ftp_get_plugins($host, $ini_path) ?: '')]);
if ($action === 'upload_plugin') {
    $name = nombre_prx($_POST['name'] ?? '');
    if (!$name) responder_plugins(['status' => 'error', 'message' => 'Plugin no válido.']);
    $source = null;
    foreach ($local_dirs as $dir) if (is_file($dir . '/' . $name)) { $source = $dir . '/' . $name; break; }
    if (!$source || !ftp_put_plugins($host, "$remote_dir/$name", file_get_contents($source))) responder_plugins(['status' => 'error', 'message' => 'No se pudo subir el plugin.']);
    responder_plugins(['status' => 'success']);
}
if ($action === 'update_ini') {
    $section = strtoupper(trim($_POST['section'] ?? 'default')); if ($section === 'DEFAULT') $section = 'default';
    $name = nombre_prx($_POST['name'] ?? ''); $enabled = ($_POST['enabled'] ?? '') === 'true';
    if (!seccion_valida($section) || !$name) responder_plugins(['status' => 'error', 'message' => 'Asignación no válida.']);
    $old = ftp_get_plugins($host, $ini_path); if ($old === false) responder_plugins(['status'=>'error','message'=>'No se pudo leer y respaldar el plugins.ini actual; la PS4 no se modificó.']);
    $backup = respaldar_plugins_ini($old,'plugins'); if ($backup === false) responder_plugins(['status'=>'error','message'=>'No se pudo validar un respaldo local; la PS4 no se modificó.']);
    $path = "$remote_dir/$name";
    $canonicalOld = diagnosticar_ini_plugins($old,false)['preview'];
    $new = diagnosticar_ini_plugins(actualizar_ini_plugins($canonicalOld, $section, $path, $enabled),false)['preview'];
    $write = subir_plugins_ini_verificado($host,$old,$new);
    if (!$write['ok']) responder_plugins(['status'=>'error','message'=>'No se pudo verificar la asignación.' . ($write['restored']?' Se restauró y verificó el archivo anterior.':' No se pudo confirmar la restauración; conserva el respaldo local.'),'backup'=>$backup]);
    responder_plugins(['status' => 'success', 'sections' => parse_ini_plugins($new), 'backup'=>$backup]);
}
responder_plugins(['status' => 'error', 'message' => 'Acción no válida.']);
?>
