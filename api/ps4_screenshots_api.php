<?php
require_once __DIR__ . '/request_guard.php';
/**
 * ====================================================================
 * PS4 Manager Tool V2.1 🚀 - API: EXCAVADOR DE IMÁGENES MEDIA (SEGURO)
 * Mantenido por AJ · Basado en proyecto original de SeBaS · RUTA: api/ps4_screenshots_api.php
 * ====================================================================
 */
error_reporting(0);
@ini_set('display_errors', 0);
set_time_limit(120);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/cache_helpers.php';
require_once __DIR__ . '/ftp_compat.php';

$action = $_POST['action'] ?? '';
$host_ip = $_POST['host_ip'] ?? '';
$cusa = strtoupper(trim($_POST['cusa_id'] ?? ''));
$port = isset($_POST['port']) ? (int)$_POST['port'] : 2121;
$force = $_POST['force'] ?? '0'; 

if (!filter_var($host_ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { 
    echo json_encode(['status' => 'error', 'message' => 'Faltan datos de consola.']); 
    exit; 
}

$cache_dir = __DIR__ . '/../user/cache/biblioteca';
$capturas_dir = __DIR__ . '/../user/cache/capturas'; 

if (!file_exists($cache_dir)) { @mkdir($cache_dir, 0777, true); }
if (!file_exists($capturas_dir)) { 
    @mkdir($capturas_dir, 0777, true); 
    @file_put_contents($capturas_dir . '/.nomedia', '');
}
limpiar_cache_antigua($cache_dir);
limpiar_cache_antigua($capturas_dir);

$is_global = ($action === 'get_all_caps');
$cache_file = $is_global ? $cache_dir . "/galeria_ALL.json" : $cache_dir . "/galeria_{$cusa}.json";

function capture_remote_file_present($ip, $port, $remote_path): ?bool {
    if(function_exists('ftp_connect')){$rows=ghm_ftp_list($ip,(int)$port,dirname($remote_path),12);if($rows===false)return null;foreach($rows as $row)if(strcasecmp($row['name'],basename($remote_path))===0)return true;return false;}
    $parent = dirname($remote_path);
    $name = basename($remote_path);
    $ch = curl_init("ftp://$ip:$port" . rtrim($parent, '/') . '/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'LIST', CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FTP_USE_EPSV => false]);
    $listing = curl_exec($ch);
    curl_close($ch);
    if ($listing === false) return null;
    foreach (preg_split('/\r?\n/', trim((string)$listing)) as $line) {
        $parts = preg_split('/\s+/', trim($line), 9);
        if (count($parts) >= 9 && strcasecmp(trim($parts[8]), $name) === 0) return true;
    }
    return false;
}

if ($force === '0' && file_exists($cache_file)) {
    $cached = json_decode(@file_get_contents($cache_file), true);
    if ($cached && isset($cached['status']) && $cached['status'] === 'success') {
        if ($action === 'count_only') {
            $count = isset($cached['images']) ? count($cached['images']) : 0;
            echo json_encode(['status' => 'success', 'count' => $count]);
            exit;
        } else if ($action === 'get_caps' || $action === 'get_all_caps') {
            $hasRemotePaths = true;
            foreach (($cached['images'] ?? []) as $image) if (empty($image['remote_path'])) { $hasRemotePaths = false; break; }
            if ($hasRemotePaths || empty($cached['images'])) { echo json_encode($cached); exit; }
        }
    }
}

// ====================================================================
// 🔥 ESCÁNER SEGURO: Conexión cURL 1 a 1 (Evita crashear el GoldHEN)
// ====================================================================
$nativeRows = function_exists('ftp_connect') ? ghm_ftp_rawlist($host_ip,$port,'/user/',8) : null;
$ch_test = $nativeRows === null ? curl_init() : false;
if ($nativeRows !== null) { $test_ftp = $nativeRows === false ? false : implode("\n",$nativeRows); }
else {
curl_setopt($ch_test, CURLOPT_URL, "ftp://$host_ip:$port/user/");
curl_setopt($ch_test, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_test, CURLOPT_CUSTOMREQUEST, "LIST");
curl_setopt($ch_test, CURLOPT_TIMEOUT, 4);
$test_ftp = curl_exec($ch_test);
curl_close($ch_test);
}

if ($test_ftp === false) {
    if (file_exists($cache_file)) {
        $cached = json_decode(@file_get_contents($cache_file), true);
        if ($action === 'count_only') { echo json_encode(['status' => 'success', 'count' => count($cached['images'] ?? [])]); }
        else { echo json_encode($cached); }
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => "Consola apagada o FTP inactivo."]);
    exit;
}

if ($action === 'download_selected') {
    if (!class_exists('ZipArchive')) {
        echo json_encode(['status' => 'error', 'message' => 'La extensión ZIP de PHP no está disponible en este entorno.']); exit;
    }
    $paths = $_POST['remote_paths'] ?? [];
    if (!is_array($paths) || count($paths) < 1 || count($paths) > 100) {
        echo json_encode(['status' => 'error', 'message' => 'Selecciona entre 1 y 100 capturas.']); exit;
    }
    $root = '/user/av_contents/photo/';
    $unique = [];
    foreach ($paths as $path) {
        $path = (string)$path;
        $segments = explode('/', $path);
        if (!str_starts_with($path, $root) || in_array('..', $segments, true) || in_array('.', $segments, true) || str_contains($path, "\\") || preg_match('/[\x00-\x1F\x7F]/', $path) || !preg_match('/\.(jpg|jpeg|png)$/i', $path) || trim(substr($path, strlen($root)), '/') === '') {
            echo json_encode(['status' => 'error', 'message' => 'La selección contiene una ruta de captura no válida.']); exit;
        }
        $unique[$path] = true;
    }
    $tmpDir = sys_get_temp_dir();
    $zipPath = tempnam($tmpDir, 'ps4caps_');
    if ($zipPath === false) { echo json_encode(['status' => 'error', 'message' => 'No se pudo preparar el ZIP temporal.']); exit; }
    @unlink($zipPath);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { @unlink($zipPath); echo json_encode(['status' => 'error', 'message' => 'No se pudo crear el ZIP temporal.']); exit; }
    $temps = [];
    $totalBytes = 0;
    $failure = '';
    register_shutdown_function(static function () use ($zipPath, &$temps): void {
        foreach ($temps as $file) @unlink($file);
        @unlink($zipPath);
    });
    set_time_limit(0);
    foreach (array_keys($unique) as $path) {
        $relative = substr($path, strlen($root));
        $segments = explode('/', $relative);
        if (in_array('', $segments, true)) { $failure = 'La ruta contiene un segmento vacío.'; break; }
        $name = implode('/', $segments);
        $tempFile = tempnam($tmpDir, 'ps4cap_');
        if ($tempFile === false) { $failure = 'No se pudo crear un archivo temporal.'; break; }
        $temps[] = $tempFile;
        $fp = @fopen($tempFile, 'wb');
        if (!$fp) { $failure = 'No se pudo escribir una captura temporal.'; break; }
        if(function_exists('ftp_connect')){$ok=ghm_ftp_download($host_ip,$port,$path,$tempFile,900);$err=$ok?'':'FTP nativo no pudo leer el archivo';}
        else {
        $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
        $ch = curl_init("ftp://$host_ip:$port/$encoded");
        $remaining = max(0, 1073741824 - $totalBytes);
        $progress = static function ($handle, float $downloadTotal, float $downloaded, float $uploadTotal, float $uploaded) use ($remaining): int { return $downloaded > $remaining ? 1 : 0; };
        curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FAILONERROR => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 900, CURLOPT_FTP_RESPONSE_TIMEOUT => 60, CURLOPT_FTP_USE_EPSV => false, CURLOPT_NOPROGRESS => false, CURLOPT_XFERINFOFUNCTION => $progress, CURLOPT_MAXFILESIZE_LARGE => $remaining]);
        $ok = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        }
        fclose($fp);
        $size = @filesize($tempFile);
        if ($ok === false || $size === false || $size === 0) {
            $failure = $size !== false && $size > $remaining ? 'La selección supera el máximo temporal de 1 GB.' : 'No se pudo descargar ' . basename($path) . ($err ? ': ' . $err : '.');
            break;
        }
        $totalBytes += $size;
        if ($totalBytes > 1073741824) { $failure = 'La selección supera el máximo temporal de 1 GB.'; break; }
        if (!$zip->addFile($tempFile, $name)) { $failure = 'No se pudo incluir ' . basename($path) . ' en el ZIP.'; break; }
        $zip->setCompressionName($name, ZipArchive::CM_STORE);
    }
    $closed = $zip->close();
    foreach ($temps as $file) @unlink($file);
    if ($failure !== '' || !$closed || !is_file($zipPath) || filesize($zipPath) === 0) {
        @unlink($zipPath);
        echo json_encode(['status' => 'error', 'message' => $failure ?: 'No se pudo finalizar el ZIP.']); exit;
    }
    header_remove('Content-Type');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="capturas_ps4_' . gmdate('Ymd_His') . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

if ($action === 'delete_capture') {
    $remote_path = (string)($_POST['remote_path'] ?? '');
    $pathParts = explode('/', $remote_path);
    if (strpos($remote_path, '/user/av_contents/photo/') !== 0 || in_array('..', $pathParts, true) || preg_match('/[\x00-\x1F\x7F]/', $remote_path) || !preg_match('/\.(jpg|jpeg|png)$/i', $remote_path)) {
        echo json_encode(['status' => 'error', 'message' => 'Ruta de captura inválida.']); exit;
    }
    // GoldHEN no acepta nombres entre comillas en QUOT/DELE: los interpreta
    // como `DELE"nombre"`. Ejecuta la ruta absoluta desde la raíz FTP, igual
    // que las operaciones de borrado del Explorador.
    if (!preg_match('#^/user/av_contents/photo/[A-Za-z0-9._ /-]+\.(jpg|jpeg|png)$#i', $remote_path)) {
        echo json_encode(['status' => 'error', 'message' => 'El nombre de la captura contiene caracteres no admitidos para FTP.']); exit;
    }
    if(function_exists('ftp_connect')){$deleted=ghm_ftp_command($host_ip,$port,'DELE '.$remote_path,15);$error='FTP DELE rechazado';}
    else{$ch = curl_init("ftp://$host_ip:$port/");curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'LIST', CURLOPT_QUOTE => ['DELE ' . $remote_path], CURLOPT_TIMEOUT => 15]);$deleted = curl_exec($ch);$error = curl_error($ch);curl_close($ch);}
    if ($deleted === false) { echo json_encode(['status' => 'error', 'message' => 'GoldHEN rechazó el borrado FTP (550). Verifica que la ruta exista y que esa versión de FTP permita borrar capturas. Detalle: ' . $error]); exit; }
    $still_present = capture_remote_file_present($host_ip, $port, $remote_path);
    if ($still_present === null) { echo json_encode(['status' => 'error', 'message' => 'GoldHEN aceptó el comando, pero no se pudo confirmar el resultado al releer la carpeta. Actualiza la galería antes de volver a intentarlo.']); exit; }
    if ($still_present) { echo json_encode(['status' => 'error', 'message' => 'GoldHEN respondió al borrado, pero la captura sigue apareciendo en FTP. No se limpió la caché local.']); exit; }
    $ext = strtolower(pathinfo($remote_path, PATHINFO_EXTENSION));
    @unlink($capturas_dir . '/' . md5($remote_path) . '.' . $ext);
    foreach (glob($cache_dir . '/galeria_*.json') ?: [] as $cache) @unlink($cache);
    echo json_encode(['status' => 'success']); exit;
}

function get_ftp_list($ip, $port, $dir) {
    if(function_exists('ftp_connect')){$rows=ghm_ftp_list($ip,(int)$port,$dir,10);if($rows===false)return [];return array_map(static fn($r)=>['name'=>$r['name'],'is_dir'=>$r['dir']],$rows);}
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "ftp://$ip:$port" . rtrim($dir, '/') . '/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "LIST");
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    $res = curl_exec($ch);
    curl_close($ch);
    
    $files = [];
    if ($res) {
        $lines = explode("\n", trim($res));
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            $parts = preg_split('/\s+/', trim($line), 9);
            if (count($parts) >= 9) {
                $name = trim($parts[8]);
                if ($name === '.' || $name === '..') continue;
                $files[] = ['name' => $name, 'is_dir' => (substr($parts[0], 0, 1) === 'd')];
            }
        }
    }
    return $files;
}

function excavar_fotos($ip, $port, $dir, &$capturas, $profundidad = 0, &$auxiliares = null) {
    if ($auxiliares === null) $auxiliares = [];
    if ($profundidad > 4) return;
    $items = get_ftp_list($ip, $port, $dir);
    foreach ($items as $item) {
        $ruta_completa = rtrim($dir, '/') . '/' . $item['name'];
        if ($item['is_dir']) {
            excavar_fotos($ip, $port, $ruta_completa, $capturas, $profundidad + 1, $auxiliares);
        } else {
            $ext = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png'])) { $capturas[] = $ruta_completa; }
            elseif (in_array($ext, ['dat', 'ext'])) { $auxiliares[] = $ruta_completa; }
        }
    }
}

$base_photo = "/user/av_contents/photo";
$rutas_cusa = [];
$items_nivel1 = get_ftp_list($host_ip, $port, $base_photo);

if ($is_global) {
    foreach ($items_nivel1 as $item1) {
        if ($item1['is_dir']) {
            $rutas_cusa[] = "$base_photo/{$item1['name']}";
        }
    }
} else {
    if (!$cusa) { echo json_encode(['status' => 'error', 'message' => 'Falta CUSA.']); exit; }
    foreach ($items_nivel1 as $item1) {
        if ($item1['is_dir']) {
            $ruta1 = "$base_photo/{$item1['name']}";
            if (stripos($item1['name'], $cusa) !== false) {
                $rutas_cusa[] = $ruta1;
            } else {
                $items_nivel2 = get_ftp_list($host_ip, $port, $ruta1);
                foreach ($items_nivel2 as $item2) {
                    if ($item2['is_dir'] && stripos($item2['name'], $cusa) !== false) {
                        $rutas_cusa[] = "$ruta1/{$item2['name']}";
                    }
                }
            }
        }
    }
}

$capturas = [];
$archivos_auxiliares = [];
foreach ($rutas_cusa as $ruta) { excavar_fotos($host_ip, $port, $ruta, $capturas, 0, $archivos_auxiliares); }

if (empty($capturas)) {
    $message = 'No hay imágenes JPG, JPEG o PNG accesibles en la galería de la consola.';
    if ($archivos_auxiliares) $message .= ' Se encontraron ' . count($archivos_auxiliares) . ' archivos auxiliares .dat/.ext, pero no sus imágenes; no se muestran como capturas.';
    $resultado_final = ['status' => 'success', 'images' => [], 'metadata_files_count' => count($archivos_auxiliares), 'message' => $message];
    @file_put_contents($cache_file, json_encode($resultado_final));
    if ($action === 'count_only') { echo json_encode(['status' => 'success', 'count' => 0]); }
    else { echo json_encode($resultado_final); }
    exit;
}

sort($capturas);
$capturas = array_reverse($capturas); 

$images_format = [];
foreach ($capturas as $cap) {
    if ($is_global) {
        $parts = explode('/', $cap);
        $name = (count($parts) > 2) ? "[" . $parts[count($parts)-2] . "] " . end($parts) : end($parts);
    } else {
        $name = basename($cap);
    }
    $game_id = preg_match('/CUSA\d{5}/i', $cap, $gameMatch) ? strtoupper($gameMatch[0]) : '';

    $ext = strtolower(pathinfo($cap, PATHINFO_EXTENSION));
    $hash = md5($cap);
    $ruta_fisica_local = $capturas_dir . '/' . $hash . '.' . $ext;

    // 🔥 URL estática si ya existe, Streaming si es nueva
    if (file_exists($ruta_fisica_local) && filesize($ruta_fisica_local) > 0) {
        $url_final = "user/cache/capturas/" . $hash . "." . $ext;
    } else {
        $url_final = "api/stream_image_api.php?ip=$host_ip&port=$port&path=" . urlencode($cap);
    }

    $images_format[] = [
        'name' => $name,
        'url' => $url_final,
        'remote_path' => $cap,
        'game_id' => $game_id
    ];
}

$resultado_final = ['status' => 'success', 'images' => $images_format, 'metadata_files_count' => count($archivos_auxiliares)];
@file_put_contents($cache_file, json_encode($resultado_final));

if ($action === 'count_only') {
    echo json_encode(['status' => 'success', 'count' => count($images_format)]);
} else {
    echo json_encode($resultado_final);
}
exit;
?>
