<?php
require_once __DIR__ . '/request_guard.php';
/**
 * ====================================================================
 * PS4 Manager Tool V2.1 🚀 - API: STREAMING Y CACHÉ DE CAPTURAS
 * Mantenido por AJ · Basado en proyecto original de SeBaS · RUTA: api/stream_image_api.php
 * ====================================================================
 */
error_reporting(0);
@set_time_limit(120);
require_once __DIR__ . '/cache_helpers.php';
require_once __DIR__ . '/ftp_compat.php';

$ip = $_GET['ip'] ?? '';
$path = $_GET['path'] ?? '';
$port = (int)($_GET['port'] ?? 2121);

if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4) || !in_array($port,[2121,2122],true) || !str_starts_with($path,'/user/av_contents/photo/') || preg_match('#(?:^|/)\.\.(?:/|$)|[\x00-\x1f\x7f]#',$path) || !preg_match('/\.(jpg|jpeg|png)$/i',$path)) { http_response_code(400); exit; }

$cache_dir = __DIR__ . '/../user/cache/capturas';
if (!file_exists($cache_dir)) { @mkdir($cache_dir, 0777, true); }
limpiar_cache_antigua($cache_dir);

// 🔥 FIX: Blindaje para que Android ignore las capturas de la Play
@file_put_contents($cache_dir . '/.nomedia', ''); 

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$hash = md5($path);
$cache_file = $cache_dir . '/' . $hash . '.' . $ext;

header('Content-Type: image/' . ($ext === 'png' ? 'png' : 'jpeg'));
header('Cache-Control: max-age=31536000');

if (file_exists($cache_file) && filesize($cache_file) > 0) {
    readfile($cache_file);
    exit;
}

$ch = function_exists('ftp_connect') ? false : curl_init();
$data = function_exists('ftp_connect') ? ghm_ftp_get($ip,$port,$path,35) : false;
if(function_exists('ftp_connect')){}
else {
$ftp_url = "ftp://$ip:$port" . implode('/', array_map('rawurlencode', explode('/', $path)));
curl_setopt($ch, CURLOPT_URL, $ftp_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($ch, CURLOPT_FTP_USE_EPSV, false);
$data = curl_exec($ch);
curl_close($ch);
}

if ($data && strlen($data) > 0) {
    file_put_contents($cache_file, $data);
    echo $data;
} else {
    http_response_code(404);
}
?>
