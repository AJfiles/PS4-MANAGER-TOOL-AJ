<?php
/** Cliente común y serializado para el BinLoader TCP de GoldHEN.
 * No realiza conexiones de sondeo: 9090 espera el flujo binario del payload.
 */
function ghm_binloader_send(string $host, string $bytes, bool $verifyAfter = false): array {
    $project = realpath(__DIR__ . '/..') ?: __DIR__;
    $lockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'ghm_binloader_' . substr(hash('sha256', $project), 0, 16) . '.lock';
    $lock = @fopen($lockPath, 'c');
    if (!$lock) return ['ok' => false, 'message' => 'No se pudo coordinar el envío del payload.'];

    $locked = false;
    $lockDeadline = microtime(true) + 20;
    do {
        $locked = @flock($lock, LOCK_EX | LOCK_NB);
        if ($locked) break;
        usleep(200000);
    } while (microtime(true) < $lockDeadline);
    if (!$locked) {
        fclose($lock);
        return ['ok' => false, 'message' => 'Hay otro payload en curso. Espera unos segundos y vuelve a intentarlo.'];
    }

    try {
        // Conectar y enviar en la misma sesión evita abrir 9090 y cerrarlo sin
        // datos. Un connect/close vacío puede consumir una sesión del BinLoader.
        $errno = 0;
        $error = '';
        $socket = @fsockopen($host, 9090, $errno, $error, 2);
        if (!$socket) {
            return ['ok' => false, 'message' => "No se envió ningún byte: {$host}:9090 rechazó la conexión. Comprueba Enable BinLoader Server en GoldHEN → Settings → Servers. Si aparece activo, recarga GoldHEN y comprueba su versión (2.4b18 incluye una corrección de configuración de servidores); después vuelve a intentar. " . ($error ?: '')];
        }
        stream_set_timeout($socket, 30);
        $written = 0;
        $length = strlen($bytes);
        while ($written < $length) {
            $chunk = substr($bytes, $written, 65536);
            $sent = @fwrite($socket, $chunk);
            if ($sent === false || $sent === 0) break;
            $written += $sent;
        }
        fclose($socket);
        if ($written !== $length) {
            return ['ok' => false, 'message' => "El envío quedó incompleto ($written de $length bytes). No se reenvió automáticamente para evitar ejecutar dos veces el payload."];
        }

        // No sondear el listener después del envío: el servidor puede estar
        // reiniciándose y el protocolo no ofrece un health-check inocuo.
        usleep(1200000);
        return ['ok' => true, 'bytes' => $written, 'listener_ready' => null, 'application_confirmed' => false];
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}
