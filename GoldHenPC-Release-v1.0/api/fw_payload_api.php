<?php
require_once __DIR__ . '/request_guard.php';
error_reporting(0); ini_set('display_errors', 0); set_time_limit(60);
header('Content-Type: application/json; charset=utf-8');
function fw_reply($data) { echo json_encode($data); exit; }
require_once __DIR__ . '/binloader_client.php';
$host = $_POST['host_ip'] ?? '';
$fw = $_POST['firmware'] ?? '';
$action = $_POST['action'] ?? '';
if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) fw_reply(['status'=>'error','message'=>'Conecta una PS4 con IP válida.']);
$base = realpath(__DIR__ . '/../payloads fw');
$map = [
  'updates' => ['5.05'=>['enableupdates.bin','disableupdates.bin'],'6.72'=>['enableupdates.bin','disableupdates.bin'],'7.02'=>['enableupdates.bin','disableupdates.bin'],'7.55'=>['enableupdates.bin','disableupdates.bin'],'9.00'=>['enableupdate.bin','disableupdates.bin'],'11.00'=>['enable-updates.bin','disable-updates.bin']],
  'coolers' => ['5.05'=>['fan50.bin','fan55.bin','fan60.bin','fan65.bin','fan70.bin','fan75.bin','fan80.bin'],'9.00'=>['fan50.bin','fan55.bin','fan60.bin','fan65.bin','fan70.bin','fan75.bin','fan80.bin','fanDefault.bin']]
];
if (!isset($map[$action][$fw])) fw_reply(['status'=>'error','message'=>'Firmware no compatible con esta sección.']);
$name = $_POST['name'] ?? '';
if (!in_array($name, $map[$action][$fw], true)) fw_reply(['status'=>'error','message'=>'Payload no disponible para esta versión.']);
if ($action === 'updates' && stripos($name, 'enable') === 0 && (($_POST['confirm_enable_warning'] ?? '') !== 'SI' || ($_POST['confirm_enable_firmware'] ?? '') !== $fw || ($_POST['confirm_enable'] ?? '') !== 'ACTIVAR ACTUALIZACIONES')) fw_reply(['status'=>'error','message'=>'Se requiere completar las tres confirmaciones para permitir actualizaciones.']);
$sub = $action === 'coolers' ? '/Fan/' : '/';
$file = realpath($base . '/' . $fw . $sub . $name);
if (!$base || !$file || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) fw_reply(['status'=>'error','message'=>'No se encontró el payload.']);
$size = filesize($file);
if ($size < 1 || $size > 16*1024*1024) fw_reply(['status'=>'error','message'=>'Tamaño de payload inválido.']);
$bytes = @file_get_contents($file);
if ($bytes === false || strlen($bytes) !== $size) fw_reply(['status'=>'error','message'=>'No se pudo leer completo el payload desde el almacenamiento.']);
$result = ghm_binloader_send($host, $bytes, false);
if (!$result['ok']) fw_reply(['status'=>'error','message'=>$result['message']]);
fw_reply([
  'status'=>'success', 'bytes'=>$result['bytes'], 'listener_ready'=>null,
  'application_confirmed'=>false,
  'message'=>'Se transmitieron todos los bytes. GoldHEN no confirma si aplicó el payload ni ofrece aquí una comprobación segura del listener; verifica el resultado en la consola antes de otro envío.'
]);
?>
