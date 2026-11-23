<?php
require_once __DIR__ . '/request_guard.php';
require_once __DIR__ . '/binloader_client.php';
error_reporting(0);
ini_set('display_errors', '0');
set_time_limit(60);
header('Content-Type: application/json; charset=utf-8');

function fw_reply(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$host = (string)($_POST['host_ip'] ?? '');
$firmware = (string)($_POST['firmware'] ?? '');
$action = (string)($_POST['action'] ?? '');
$name = basename((string)($_POST['name'] ?? ''));
if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) fw_reply(['status'=>'error','message'=>'Conecta una PS4 con IP válida.'], 400);

$fanNames = [
    'fan_default_universal.bin',
    'fan_threshold_50_universal.bin', 'fan_threshold_55_universal.bin',
    'fan_threshold_60_universal.bin', 'fan_threshold_65_universal.bin',
    'fan_threshold_70_universal.bin', 'fan_threshold_75_universal.bin',
    'fan_threshold_80_universal.bin'
];
$updateNames = ['enable_updates_full.bin', 'disable_updates_full.bin'];
$supportedCatalogFirmwares = ['5.05','6.72','7.02','7.55','9.00','10.00','10.01','10.50','11.00','12.00','12.02','12.50','12.52','13.00','13.02','13.50','13.52','14.00','14.02'];
$coolerFirmwares = $supportedCatalogFirmwares;
$updateFirmwares = $supportedCatalogFirmwares;
$map = [
    'coolers' => array_fill_keys($coolerFirmwares, $fanNames),
    'updates' => array_fill_keys($updateFirmwares, $updateNames)
];
if (!isset($map[$action][$firmware])) fw_reply(['status'=>'error','message'=>'Firmware no incluido en el catálogo de esta sección.'], 400);
if (!in_array($name, $map[$action][$firmware], true)) fw_reply(['status'=>'error','message'=>'Payload no disponible para esta versión.'], 400);

if ($action === 'updates' && $name === 'enable_updates_full.bin') {
    if (($_POST['confirm_enable_warning'] ?? '') !== 'SI'
        || ($_POST['confirm_enable_firmware'] ?? '') !== $firmware
        || ($_POST['confirm_enable'] ?? '') !== 'ACTIVAR ACTUALIZACIONES') {
        fw_reply(['status'=>'error','message'=>'Se requieren las tres confirmaciones para quitar la protección y permitir actualizaciones.'], 403);
    }
}

$expectedHashes = [
    'fan_default_universal.bin' => '1660b849ec15fc529f22382a3ef98facb5c8762b74be6558ff13b3296e01ea1d',
    'fan_threshold_50_universal.bin' => '5df818ab7cc4529a233a31397338e7657952cf94fe2863f8de508aeda71a4540',
    'fan_threshold_55_universal.bin' => 'fd5eb4e897289bf7c2ae76a10de793bbed542aa3d93364940111850b6e23620a',
    'fan_threshold_60_universal.bin' => '72bfc7ae8b93e780f574d3d5f552110e36955eb178b5a50e9e2f4c38fc7146f5',
    'fan_threshold_65_universal.bin' => 'c57b262d0a3254787a2acf674a12b8e64f3a05053d1b82013f4573eabf84ed6e',
    'fan_threshold_70_universal.bin' => 'afcab9ef28942cf9a0af05dc2965074a9d06b1f8ab555b36b53937088b49cd94',
    'fan_threshold_75_universal.bin' => 'a0e23146c8680a0728b21af7b439ee063399bef3dcbcbe24d9cdcc9b2528c7b9',
    'fan_threshold_80_universal.bin' => 'b3ece1b5d05178ea1de87e61afa01c1b3716921fad3ca5aefc4e0e2a024e913d',
    'disable_updates_full.bin' => '93d2285e33840113d268994bc9c8c63da25b758ea73f847d51c0153cf91c1e6d',
    'enable_updates_full.bin' => '530f60e00357bcaafdbc3f4d3be87e645b85bd3a41bdd01cc35a4c8515a5efe0'
];

$base = realpath(__DIR__ . '/../payloads fw');
$folder = $action === 'coolers' ? 'FAN' : 'UPDATES';
$file = $base ? realpath($base . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . $name) : false;
if (!$base || !$file || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
    fw_reply(['status'=>'error','message'=>'No se encontró el payload en el catálogo local.'], 404);
}
$hash = @hash_file('sha256', $file);
if (!is_string($hash) || !hash_equals($expectedHashes[$name], strtolower($hash))) {
    fw_reply(['status'=>'error','message'=>'El SHA-256 del payload no coincide con el catálogo. No se envió nada.'], 409);
}
$size = @filesize($file);
if (!$size || $size > 16 * 1024 * 1024) fw_reply(['status'=>'error','message'=>'Tamaño de payload inválido.'], 400);
$bytes = @file_get_contents($file);
if ($bytes === false || strlen($bytes) !== $size) fw_reply(['status'=>'error','message'=>'No se pudo leer completo el payload desde el almacenamiento.'], 500);

try {
    $result = ghm_binloader_send($host, $bytes, false);
} catch (Throwable $error) {
    error_log('[fw_payload_api] ' . $error);
    fw_reply(['status'=>'error','message'=>'Error controlado durante el envío por BinLoader.','detail'=>$error->getMessage()], 500);
}
if (empty($result['ok'])) fw_reply(['status'=>'error','message'=>$result['message'] ?? 'Falló el envío del payload.'], 502);
fw_reply([
    'status'=>'success',
    'bytes'=>$result['bytes'] ?? $size,
    'sha256'=>$hash,
    'listener_ready'=>null,
    'application_confirmed'=>false,
    'message'=>'Se transmitieron todos los bytes. GoldHEN no confirma si aplicó el payload; verifica el resultado en la consola antes de otro envío.'
]);
