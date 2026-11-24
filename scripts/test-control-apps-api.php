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
assert(strpos($apiSource, "\$_POST['user_id'] ?? ''") !== false, 'El lanzamiento no debe asumir el perfil activo si no se eligió uno.');
assert(strpos($apiSource, "'code' => 'ACTIVE_APP_CLOSE_REQUIRED'") !== false && strpos($apiSource, "'no_action' => true") !== false, 'La API debe impedir aperturas superpuestas y evitar relanzar una app que ya está en primer plano.');
assert(strpos($apiSource, 'function ac_read_home_profiles') !== false && strpos($apiSource, "'logged_in' => null") !== false, 'La API debe listar perfiles locales sin inventar su estado de sesión.');
assert(strpos($apiSource, 'function ac_read_installed_title_ids') !== false && strpos($apiSource, "lb_read_directory_names(\$host, \$port, '/mnt'") !== false, 'El catálogo debe verificar títulos instalados en unidades internas y externas.');
assert(strpos($apiSource, "if (\$profiles_helper && (\$profiles_helper['status'] ?? '') === 'success')") !== false, 'La lectura UserService debe aprovechar un helper 1.2.0, aunque el lanzamiento requiera 1.2.1.');
assert(strpos($apiSource, 'function ac_read_title_dir_ids') !== false && strpos($apiSource, "'/mnt/' . \$mount . '/user/app'") !== false, 'El inventario debe verificar app folders internos y de unidades externas montadas.');
assert(strpos($apiSource, "'installed_verified' => true") !== false, 'El resultado del inventario solo se marca verificado tras leer las ubicaciones instaladas.');
assert(strpos($apiSource, '/user/appmeta/external/') !== false && strpos($apiSource, '/mnt/ext0/user/appmeta/') !== false, 'El enriquecimiento de metadatos debe contemplar títulos externos.');
assert(strpos($apiSource, "if (\$profiles_helper && (\$profiles_helper['status'] ?? '') === 'success')") !== false, 'UserService debe aprovechar el helper 1.2.0 para lecturas, aunque las acciones requieran 1.2.1.');
assert(strpos($apiSource, "version_compare((string)(\$daemon_data['version'] ?? '0'), '1.2.1', '<')") !== false, 'El servicio 1.2.0 debe marcarse desactualizado y no ejecutar acciones mutables.');
assert(strpos($apiSource, "'action' => 'close_force',\n                'code' => 'HELPER_UPDATE_REQUIRED'") !== false, 'El backend debe rechazar cierre forzado si el helper está ausente, es inválido o anterior a 1.2.1.');
assert(strpos($apiSource, "version_compare((string)(\$close_helper['version'] ?? '0'), '1.2.1', '<')") !== false, 'El cierre también debe exigir el helper que aplica 40 segundos.');
assert(strpos($apiSource, 'CURLOPT_DIRLISTONLY') !== false, 'Los endpoints FTP deben usar la constante cURL portable para listar nombres en PHP sin ext-ftp.');
assert(strpos($apiSource, 'catch (Throwable $error)') !== false, 'Los fallos PHP deben regresar un error JSON diagnosticable en vez de HTML vacío.');
preg_match('/function ac_get_title_metadata\(.*?function ac_is_title_in_library\(/s', $apiSource, $metadataBlock);
assert(isset($metadataBlock[0]) && strpos($metadataBlock[0], 'ghm_ftp_get($host_ip, $port, $rem_sfo') !== false && strpos($metadataBlock[0], 'ftp_connect(') === false, 'La consulta de metadatos no debe llamar ext-ftp directamente en Android/Termux.');
assert(strpos($apiSource, 'ghm_ftp_get($host, $port, \'/data/GoldHEN/payloads.ini\'') !== false, 'service_status debe leer payloads.ini por el adaptador FTP portable.');
assert(strpos($apiSource, 'function ac_set_autorun_curl') !== false && strpos($apiSource, 'ac_ftp_put_content_verified') !== false, 'AutoRun debe tener ruta cURL con respaldo y verificación de lectura posterior.');
assert(strpos($apiSource, "'user_id' => " . '$user_id') !== false, 'La API debe enviar al helper el perfil elegido para lanzar la app.');
assert(strpos($apiSource, "'LAUNCH_SENT_UNCONFIRMED'") !== false, 'Una apertura enviada sin respuesta no debe reportarse como canal inactivo.');
assert(strpos($apiSource, "'helper_daemon_status_confirmation'") !== false, 'La API debe confirmar una apertura consultando el estado después del envío.');
assert(strpos($daemonSource, 'sceUserServiceGetForegroundUser') !== false, 'El helper debe consultar el usuario activo de la consola.');
assert(strpos($daemonSource, 'sceKernelLoadStartModule(') !== false && strpos($daemonSource, '/system/common/lib/libSceUserService.sprx') !== false, 'El helper debe cargar UserService al ejecutarse como ELF independiente.');
assert(strpos($daemonSource, 'sceUserServiceInitialize(&params)') !== false && strpos($daemonSource, '.priority = 0x2BC') !== false, 'UserService debe recibir parámetros de inicialización con prioridad válida.');
assert(strpos($daemonSource, 'sceUserServiceGetInitialUser(&selected)') !== false, 'El helper debe tener un fallback documentado si falla la lectura del usuario en primer plano.');
assert(strpos($daemonSource, 'module_result') !== false && strpos($daemonSource, 'foreground_result') !== false, 'El helper debe reportar los errores concretos de UserService para diagnóstico.');
assert(strpos($daemonSource, '#define DAEMON_VERSION "1.2.1"') !== false, 'El daemon debe exponer una versión nueva para aplicar el intervalo reducido en instalaciones activas.');
assert(strpos($daemonSource, '#define ACTION_COOLDOWN_SECONDS 40') !== false, 'El daemon debe imponer 40 segundos entre acciones mutables en la PS4.');
assert(strpos($daemonSource, 'ACTION_COOLDOWN_PATH "/user/data/payloads/app_control.cooldown"') !== false && strpos($daemonSource, 'load_action_cooldown();') !== false && strpos($daemonSource, 'persist_action_cooldown(now)') !== false, 'El intervalo debe sobrevivir al reinicio del helper y bloquear lanzamientos repetidos.');
assert(strpos($daemonSource, 'param.user_id = (uint32_t)launch_user_id;') !== false, 'La apertura debe usar el perfil validado, no user_id=0.');
assert(strpos($daemonSource, 'param.user_id = 0;') === false, 'El helper no debe lanzar apps con user_id=0 fijo.');
assert(strpos($daemonSource, 'Control de Apps: ELF iniciado; preparando servicio 9091...') !== false, 'El ELF debe confirmar que alcanzó main antes de inicializar la red.');
assert(strpos($daemonSource, 'int32_t netctl_result = sceNetCtlInit();') !== false, 'El payload debe inicializar la capa de red de control como el build previamente probado.');
assert(strpos($daemonSource, 'if (net_result != 0)') === false, 'Un código de red no debe terminar el payload antes de probar el socket.');
assert(strpos($daemonSource, 'if (net_pool < 0)') === false, 'Un pool no disponible no debe impedir probar bind/listen del servicio.');

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

// 3. Prueba: Installed titles exige host para validar instalaciones reales
$r3 = execute_request($endpoint, ['action' => 'installed_titles']);
assert($r3['json'] !== null && ($r3['json']['code'] ?? '') === 'INVALID_IP', 'installed_titles debe exigir la IP antes de confirmar instalaciones.');

// 4. Prueba: Launch rechaza Title ID no perteneciente al inventario de Biblioteca
$r4 = execute_request($endpoint, [
    'action' => 'launch',
    'host_ip' => '127.0.0.1',
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
