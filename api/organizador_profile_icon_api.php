<?php
require_once __DIR__ . '/request_guard.php';
require_once __DIR__ . '/ftp_compat.php';
error_reporting(0);
@set_time_limit(15);
$ip = (string)($_GET['ip'] ?? '');
$port = (int)($_GET['port'] ?? 2121);
$folder = strtolower(trim((string)($_GET['folder'] ?? '')));
if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !in_array($port, [2121,2122], true) || !preg_match('/^[a-f0-9]{8}$/', $folder)) { http_response_code(400); exit; }
$cache = __DIR__ . '/../user/cache/organizador';
if (!is_dir($cache)) @mkdir($cache, 0770, true);
@file_put_contents($cache . '/.nomedia', '');
$file = $cache . '/profile-' . hash('sha256', $ip . ':' . $port . ':' . $folder) . '.png';
header('Content-Type: image/png');
header('Cache-Control: public, max-age=900');
if (is_file($file) && filesize($file) > 32 && filesize($file) <= 4194304) { readfile($file); exit; }
if (is_file($file)) @unlink($file);
$path = '/system_data/priv/cache/profile/0x' . strtoupper($folder) . '/avatar.png';
$url = 'ftp://' . $ip . ':' . $port . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
if(function_exists('ftp_connect')){$data=ghm_ftp_get($ip,$port,$path,8);if(!is_string($data)||strlen($data)<32||strlen($data)>4194304||substr($data,0,8)!=="\x89PNG\r\n\x1a\n"){http_response_code(404);exit;}if(@file_put_contents($file,$data,LOCK_EX)===false){http_response_code(500);exit;}echo $data;exit;}
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>5, CURLOPT_FTP_RESPONSE_TIMEOUT=>4, CURLOPT_FAILONERROR=>true, CURLOPT_FTP_USE_EPSV=>false, CURLOPT_MAXFILESIZE=>4194304]);
$data = curl_exec($ch);
$size = (int)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
curl_close($ch);
if (!is_string($data) || strlen($data) < 32 || strlen($data) > 4194304 || ($size > 0 && $size !== strlen($data)) || substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") { http_response_code(404); exit; }
if (@file_put_contents($file, $data, LOCK_EX) === false) { http_response_code(500); exit; }
echo $data;
?>
