<?php
/**
 * Test unitario y de integración para api/app_control_api.php
 */
$php = PHP_BINARY;
$root = realpath(__DIR__ . '/..');
$endpoint = $root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'app_control_api.php';
$apiSource = file_get_contents($endpoint);
$daemonSource = file_get_contents($root . DIRECTORY_SEPARATOR . 'payloads' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'app_control_daemon.c');
assert(is_string($apiSource), 'Debe poder leerse la API para validar sus invariantes.');
assert(is_string($daemonSource), 'Debe poder leerse el daemon para validar el uso seguro de perfiles.');
assert(strpos($apiSource, 'ac_is_binloader_open') === false, 'La API no debe abrir conexiones vacías de sondeo a BinLoader.');
assert(strpos($apiSource, 'fsockopen($host_ip, 9090') === false, 'La API no debe sondear el puerto 9090 con una conexión vacía.');
assert(substr_count($apiSource, 'ghm_binloader_send($host_ip, $bytes, false)') === 1, 'start_service debe efectuar como máximo un envío BinLoader por petición.');
assert(strpos($apiSource, "'code' => 'ACTIVE_TITLE_UNVERIFIED'") !== false, 'El cierre forzado debe cancelarse si FTP no puede verificar la app activa.');
assert(strpos($apiSource, "'action' => 'user_profiles'") !== false, 'La API debe exponer los perfiles conectados por UserService.');
assert(strpos($apiSource, "'user_id' => " . '$user_id') !== false, 'La API debe enviar al helper el perfil elegido para lanzar la app.');
assert(strpos($apiSource, "'LAUNCH_SENT_UNCONFIRMED'") !== false, 'Una apertura enviada sin respuesta no debe reportarse como canal inactivo.');
assert(strpos($apiSource, "'helper_daemon_status_confirmation'") !== false, 'La API debe confirmar una apertura consultando el estado después del envío.');
assert(strpos($daemonSource, 'sceUserServiceGetForegroundUser') !== false, 'El helper debe consultar el usuario activo de la consola.');
assert(strpos($daemonSource, 'param.user_id = (uint32_t)launch_user_id;') !== false, 'La apertura debe usar el perfil validado, no user_id=0.');
assert(strpos($daemonSource, 'param.user_id = 0;') === false, 'El helper no debe lanzar apps con user_id=0 fijo.');

assert(strpos($apiSource, 'BinLoader 9090 se usa para cargar el ELF del servicio') !== false, 'API must distinguish payload loader from the command service.');
assert(strpos($apiSource, 'solo recibe cÃ³digo binario compilado (.bin)') === false, 'API must not claim that compatible ELF payloads are excluded.');

function execute_request(string $endpoint, array $post, array $server = []): array {
    $script = tempnam(sys_get_temp_dir(), 'ca_test_');
    $postExport = var_export($post, true);
    $serverExport = var_export(array_merge([
        'REQUEST_METHOD' => 'POST',
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => '127.0.0.1:8080',
        'HTTP_ORIGIN' => 'http://127.0.0.1:8080'
    ], $server), true);

    $code = <<<PHP
<?php
\$_POST = $postExport;
\$_SERVER = $serverExport;
ob_start();
require '$endpoint';
\$output = ob_get_clean();
echo \$output;
PHP;

    file_put_contents($script, $code);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1';
    exec($cmd, $lines, $exitCode);
    @unlink($script);

    $raw = implode("\n", $lines);
    $data = json_decode($raw, true);
    return [
        'exitCode' => $exitCode,
        'raw' => $raw,
        'json' => $data
    ];
}

// 2. Prueba: Acción no permitida
$r2 = execute_request($endpoint, ['action' => 'arbitrary_exec']);
assert($r2['json'] !== null && $r2['json']['code'] === 'INVALID_ACTION', 'Debe rechazar acciones no permitidas.');

// 3. Prueba: Installed titles devuelve inventario
$r3 = execute_request($endpoint, ['action' => 'installed_titles']);
assert($r3['json'] !== null && $r3['json']['status'] === 'success', 'installed_titles debe responder éxito.');
assert(isset($r3['json']['titles']) && is_array($r3['json']['titles']), 'installed_titles debe devolver array.');

// 4. Prueba: Launch rechaza Title ID no perteneciente al inventario de Biblioteca
$r4 = execute_request($endpoint, [
    'action' => 'launch',
    'host_ip' => '192.168.1.50',
    'title_id' => 'CUSA99999' // No existe en biblioteca
]);
assert($r4['json'] !== null && $r4['json']['code'] === 'TITLE_NOT_IN_LIBRARY', 'Launch debe rechazar CUSA fuera de la biblioteca instalada.');

// 5. Prueba: Close force se rechaza sin bandera confirm_force=1
$r5 = execute_request($endpoint, [
    'action' => 'close_force',
    'host_ip' => '192.168.1.50',
    'title_id' => 'CUSA00419',
    'confirm_force' => '0'
]);
assert($r5['json'] !== null && $r5['json']['code'] === 'CONFIRMATION_REQUIRED', 'Close force debe exigir confirm_force=1.');

// 6. Prueba: Close force protege procesos críticos del sistema
$r6 = execute_request($endpoint, [
    'action' => 'close_force',
    'host_ip' => '192.168.1.50',
    'title_id' => 'SCESHELLUI',
    'confirm_force' => '1'
]);
assert($r6['json'] !== null && $r6['json']['code'] === 'PROTECTED_SYSTEM_PROCESS', 'Close force debe proteger procesos críticos como ShellUI.');

$r6b = execute_request($endpoint, [
    'action' => 'close_force',
    'host_ip' => '192.168.1.50',
    'title_id' => 'BINLOADER',
    'confirm_force' => '1'
]);
assert($r6b['json'] !== null && $r6b['json']['code'] === 'PROTECTED_SYSTEM_PROCESS', 'Close force debe proteger BinLoader.');

// 7. Prueba: Bloqueo de concurrencia (exclusión mutua flock)
$project = realpath(__DIR__ . '/..') ?: __DIR__;
$lockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
    . 'ghm_app_control_' . substr(hash('sha256', $project), 0, 16) . '.lock';
$lock = fopen($lockPath, 'c');
flock($lock, LOCK_EX);

$r7 = execute_request($endpoint, ['action' => 'installed_titles']);
assert($r7['json'] !== null && $r7['json']['status'] === 'busy', 'Operación simultánea debe responder con busy.');
assert($r7['json']['code'] === 'OPERATION_IN_PROGRESS', 'Código debe ser OPERATION_IN_PROGRESS.');

flock($lock, LOCK_UN);
fclose($lock);

echo "CONTROL_APPS_API_TEST_OK\n";
