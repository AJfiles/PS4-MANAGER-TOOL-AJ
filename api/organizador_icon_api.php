<?php
require_once __DIR__ . '/request_guard.php';
require_once __DIR__ . '/ftp_compat.php';
error_reporting(0);
@set_time_limit(25);
$ip = $_GET['ip'] ?? '';
$port = (int)($_GET['port'] ?? 2121);
$id = strtoupper(trim((string)($_GET['id'] ?? '')));
$meta = trim((string)($_GET['meta'] ?? ''), '/');
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !in_array($port, [2121,2122], true) || !preg_match('/^[A-Z0-9_-]{1,32}$/', $id)) { http_response_code(400); exit; }
$allowedMeta = $meta === '' || preg_match('~^(?:system_ex/app|user/appmeta|system_data/priv/appmeta|user/appmeta/external|system_data/priv/appmeta/external)/' . preg_quote($id, '~') . '(?:/sce_sys)?$~i', $meta);
if (!$allowedMeta) { http_response_code(400); exit; }
$cache = __DIR__ . '/../user/cache/organizador';
if (!is_dir($cache)) @mkdir($cache, 0770, true);
@file_put_contents($cache . '/.nomedia', '');
$file = $cache . '/' . hash('sha256', $ip . ':' . $port . ':' . $id . ':' . $meta) . '.png';
header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600');
if (is_file($file) && filesize($file) > 32) { readfile($file); exit; }
$paths = $meta !== '' ? ['/'. $meta . '/icon0.png', '/'. $meta . '/ICON0.PNG'] : [];
$paths = array_merge($paths, [
    "/system_ex/app/$id/sce_sys/icon0.png", "/system_ex/app/$id/icon0.png",
    "/user/appmeta/$id/icon0.png", "/user/appmeta/$id/sce_sys/icon0.png",
    "/system_data/priv/appmeta/$id/icon0.png", "/system_data/priv/appmeta/$id/sce_sys/icon0.png",
    "/user/appmeta/external/$id/icon0.png", "/system_data/priv/appmeta/external/$id/icon0.png",
    "/user/app/$id/sce_sys/icon0.png"
]);
foreach ($paths as $path) {
    if(function_exists('ftp_connect')){$data=ghm_ftp_get($ip,$port,$path,8);if(is_string($data)&&strlen($data)>32&&substr($data,0,8)==="\x89PNG\r\n\x1a\n"){@file_put_contents($file,$data);echo $data;exit;}continue;}
    $url = 'ftp://' . $ip . ':' . $port . implode('/', array_map('rawurlencode', explode('/', $path)));
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>4, CURLOPT_FTP_RESPONSE_TIMEOUT=>3, CURLOPT_FAILONERROR=>true, CURLOPT_FTP_USE_EPSV=>false]);
    $data = curl_exec($ch);
    curl_close($ch);
    if (is_string($data) && strlen($data) > 32 && substr($data, 0, 8) === "\x89PNG\r\n\x1a\n") {
        @file_put_contents($file, $data);
        echo $data;
        exit;
    }
}
http_response_code(404);
?>
