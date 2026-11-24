<?php
require_once __DIR__ . '/request_guard.php';
require_once __DIR__ . '/cache_helpers.php';
require_once __DIR__ . '/ftp_compat.php';
require_once __DIR__ . '/binloader_client.php';

/**
 * ====================================================================
 * PS4 Manager Tools - API DE CONTROL DE APPS (ESTADO, ABRIR Y CERRAR)
 * Control seguro de aplicaciones en ejecución y lanzamiento desde Biblioteca
 * RUTA: api/app_control_api.php
 * ====================================================================
 */
error_reporting(0);
@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

$firma = chr(83) . chr(101) . chr(66) . chr(97) . chr(83);
header('X-Author: ' . $firma);

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');
$host_ip = trim($_POST['host_ip'] ?? $_GET['host_ip'] ?? '');
$port = isset($_POST['port']) ? (int)$_POST['port'] : 2121;
$cusa_id = strtoupper(trim($_POST['cusa_id'] ?? $_POST['title_id'] ?? $_GET['cusa_id'] ?? $_GET['title_id'] ?? ''));
$user_id = trim((string)($_POST['user_id'] ?? 'active'));
$confirm_force = trim($_POST['confirm_force'] ?? '');
$request_id = trim($_POST['request_id'] ?? '');

function app_control_reply(array $data, int $httpCode = 200): void {
    if ($httpCode !== 200 && !headers_sent()) {
        http_response_code($httpCode);
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Validar acción permitida
$allowed_actions = ['status', 'launch', 'close_graceful', 'close_force', 'installed_titles', 'service_status', 'user_profiles', 'start_service', 'toggle_autorun'];
if (!in_array($action, $allowed_actions, true)) {
    app_control_reply([
        'status' => 'error',
        'code' => 'INVALID_ACTION',
        'message' => 'Acción no permitida o no especificada.',
        'request_id' => $request_id
    ], 400);
}

// 2. Control de concurrencia: exclusión mutua mediante cerrojo local
$project = realpath(__DIR__ . '/..') ?: __DIR__;
$lockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
    . 'ghm_app_control_' . substr(hash('sha256', $project), 0, 16) . '.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock) {
    app_control_reply([
        'status' => 'error',
        'code' => 'LOCK_FAILED',
        'message' => 'No se pudo coordinar el bloqueo de control de aplicaciones.',
        'request_id' => $request_id
    ], 500);
}

$locked = @flock($lock, LOCK_EX | LOCK_NB);
if (!$locked) {
    fclose($lock);
    app_control_reply([
        'status' => 'busy',
        'code' => 'OPERATION_IN_PROGRESS',
        'message' => 'Hay otra operación de control de apps en curso. Espera unos segundos.',
        'request_id' => $request_id
    ], 409);
}

// Funciones auxiliares
function ac_valid_ip(string $ip): bool {
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
}

function ac_valid_title_id(string $id): bool {
    return (bool)preg_match('/^[A-Z]{4}\d{5}$/', $id)
        || (bool)preg_match('/^[A-Z0-9._-]{4,16}$/', $id);
}

/**
 * Parser binario de archivos PARAM.SFO de PS4
 */
function ac_parse_sfo(string $filepath): ?array {
    if (!is_file($filepath) || @filesize($filepath) === 0) return null;
    $buffer = @file_get_contents($filepath);
    if (!$buffer) return null;
    $sfo_pos = strpos($buffer, "\0PSF");
    if ($sfo_pos === false) return null;
    $sfo_data = substr($buffer, $sfo_pos, 65536);
    if (substr($sfo_data, 0, 4) !== "\0PSF") return null;

    $key_table_offset = unpack('V', substr($sfo_data, 8, 4))[1];
    $data_table_offset = unpack('V', substr($sfo_data, 12, 4))[1];
    $entries = unpack('V', substr($sfo_data, 16, 4))[1];
    $meta = [];

    for ($i = 0; $i < $entries; $i++) {
        $entry_offset = 20 + ($i * 16);
        if ($entry_offset + 16 > strlen($sfo_data)) break;
        $key_offset = unpack('v', substr($sfo_data, $entry_offset, 2))[1];
        $data_len = unpack('V', substr($sfo_data, $entry_offset + 4, 4))[1];
        $data_offset = unpack('V', substr($sfo_data, $entry_offset + 12, 4))[1];

        $key = '';
        $pos = $key_table_offset + $key_offset;
        while ($pos < strlen($sfo_data) && $sfo_data[$pos] !== "\0") {
            $key .= $sfo_data[$pos++];
        }

        if (in_array($key, ['TITLE', 'APP_TITLE', 'APP_VER', 'CATEGORY'], true)) {
            $val = substr($sfo_data, $data_table_offset + $data_offset, $data_len);
            $val_clean = trim(str_replace("\0", '', $val));
            $meta[$key] = preg_replace('/[\x00-\x1F\x7F]/u', '', $val_clean);
        }
    }
    return $meta;
}

/**
 * Obtiene o enriquece metadatos e iconos de un título (desde caché o directamente vía FTP)
 */
function ac_get_title_metadata(string $title_id, string $host_ip = '', int $port = 2121): array {
    $cache_dir = __DIR__ . '/../user/cache/biblioteca';
    if (!is_dir($cache_dir)) @mkdir($cache_dir, 0777, true);
    $metadata_file = $cache_dir . '/titles.json';

    $meta = [
        'title_id' => $title_id,
        'name' => $title_id,
        'version' => '01.00',
        'icon' => '',
        'category' => 'game',
        'installed' => true
    ];

    $all_titles = [];
    if (is_file($metadata_file)) {
        $raw = @file_get_contents($metadata_file);
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) {
            $all_titles = $decoded;
            if (isset($all_titles[$title_id])) {
                $entry = $all_titles[$title_id];
                $meta['name'] = $entry['name'] ?? $entry['nombre'] ?? $title_id;
                $meta['version'] = $entry['version'] ?? '01.00';
                $meta['category'] = $entry['category'] ?? $entry['tipo'] ?? 'game';
            }
        }
    }

    $icon_path = $cache_dir . '/' . $title_id . '.png';
    $sfo_path = $cache_dir . '/' . $title_id . '.sfo';

    // Si aún no tenemos el nombre real o el icono, y existe SFO local, parsearlo
    if (($meta['name'] === $title_id || empty($meta['name'])) && is_file($sfo_path)) {
        $parsed = ac_parse_sfo($sfo_path);
        if ($parsed) {
            $parsed_name = $parsed['TITLE'] ?? $parsed['APP_TITLE'] ?? '';
            if ($parsed_name && !preg_match('/^\d+$/', trim($parsed_name))) {
                $meta['name'] = trim(preg_replace('/\s+/', ' ', $parsed_name));
            }
            if (!empty($parsed['APP_VER'])) $meta['version'] = $parsed['APP_VER'];
            if (!empty($parsed['CATEGORY'])) $meta['category'] = $parsed['CATEGORY'];
        }
    }

    // Si todavía falta nombre real o icono y tenemos IP activa, descargarlos por FTP desde la PS4
    if (($meta['name'] === $title_id || !is_file($icon_path) || @filesize($icon_path) === 0) && ac_valid_ip($host_ip)) {
        $conn = @ftp_connect($host_ip, $port, 2);
        if ($conn && @ftp_login($conn, 'anonymous', 'anonymous')) {
            @ftp_pasv($conn, true);
            if (!is_file($sfo_path) || @filesize($sfo_path) === 0) {
                foreach (["/user/appmeta/{$title_id}/param.sfo", "/system_data/priv/appmeta/{$title_id}/param.sfo"] as $rem_sfo) {
                    if (@ftp_get($conn, $sfo_path, $rem_sfo, FTP_BINARY)) break;
                }
                if (is_file($sfo_path)) {
                    $parsed = ac_parse_sfo($sfo_path);
                    if ($parsed) {
                        $parsed_name = $parsed['TITLE'] ?? $parsed['APP_TITLE'] ?? '';
                        if ($parsed_name && !preg_match('/^\d+$/', trim($parsed_name))) {
                            $meta['name'] = trim(preg_replace('/\s+/', ' ', $parsed_name));
                        }
                        if (!empty($parsed['APP_VER'])) $meta['version'] = $parsed['APP_VER'];
                        if (!empty($parsed['CATEGORY'])) $meta['category'] = $parsed['CATEGORY'];
                    }
                }
            }
            if (!is_file($icon_path) || @filesize($icon_path) === 0) {
                foreach (["/user/appmeta/{$title_id}/icon0.png", "/system_data/priv/appmeta/{$title_id}/icon0.png"] as $rem_icon) {
                    if (@ftp_get($conn, $icon_path, $rem_icon, FTP_BINARY)) break;
                }
            }
            @ftp_close($conn);

            // Actualizar titles.json con la nueva metadata aprendida
            $all_titles[$title_id] = [
                'id' => $title_id,
                'name' => $meta['name'],
                'version' => $meta['version'],
                'category' => $meta['category'],
                'icon' => is_file($icon_path) ? "user/cache/biblioteca/{$title_id}.png" : ''
            ];
            @file_put_contents($metadata_file, json_encode($all_titles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    if (is_file($icon_path) && @filesize($icon_path) > 0) {
        $meta['icon'] = 'user/cache/biblioteca/' . $title_id . '.png?v=' . (string)@filemtime($icon_path);
    } else {
        foreach (['jpg', 'jpeg', 'png'] as $ext) {
            $custom_cover = __DIR__ . '/../user/portadas_custom/' . $title_id . '.' . $ext;
            if (is_file($custom_cover) && @filesize($custom_cover) > 0) {
                $meta['icon'] = 'user/portadas_custom/' . $title_id . '.' . $ext . '?v=' . (string)@filemtime($custom_cover);
                break;
            }
        }
    }

    return $meta;
}

function ac_is_title_in_library(string $title_id): bool {
    $metadata_file = __DIR__ . '/../user/cache/biblioteca/titles.json';
    if (is_file($metadata_file)) {
        $raw = @file_get_contents($metadata_file);
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded) && isset($decoded[$title_id])) return true;
    }
    $cache_dir = __DIR__ . '/../user/cache/biblioteca';
    if (is_file($cache_dir . '/' . $title_id . '.sfo') || is_file($cache_dir . '/' . $title_id . '.png')) {
        return true;
    }
    return false;
}

/**
 * Consulta el estado mediante socket al helper/daemon si existe en el puerto 9091.
 */
function ac_query_helper_daemon(string $host, int $daemon_port = 9091, int $timeout_sec = 1): ?array {
    $errno = 0;
    $errstr = '';
    $sock = @fsockopen($host, $daemon_port, $errno, $errstr, $timeout_sec);
    if (!$sock) return null;

    stream_set_timeout($sock, $timeout_sec);
    $req = json_encode(['action' => 'status']) . "\n";
    @fwrite($sock, $req);
    $resp = @fgets($sock, 4096);
    fclose($sock);

    if (!$resp) return null;
    $decoded = json_decode(trim($resp), true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Comprueba si el puerto 9090 (BinLoader) de la consola responde a conexión.
 */
/**
 * Lee payloads.ini vía FTP y verifica si app_control.elf está configurado en [AutoRun].
 */
function ac_check_autorun_enabled(string $host, int $port = 2121): bool {
    $ftp = ghm_ftp_open($host, $port, 5);
    if (!$ftp) return false;
    $temp = tempnam(sys_get_temp_dir(), 'ca_ini_');
    $ok = @ftp_get($ftp, $temp, '/data/GoldHEN/payloads.ini', FTP_BINARY);
    if (!$ok) {
        @unlink($temp);
        return false;
    }
    $content = @file_get_contents($temp);
    @unlink($temp);
    if ($content === false) return false;
    $in_autorun = false;
    foreach (preg_split('/\r\n|\n|\r/', $content) as $line) {
        $line = trim($line);
        if (preg_match('/^\[([^]]+)\]$/', $line, $section)) {
            $in_autorun = strcasecmp(trim($section[1]), 'AutoRun') === 0;
            continue;
        }
        if ($in_autorun && preg_match('~^/?user/data/payloads/app_control\.elf\s*=\s*1\s*$~i', $line)) return true;
    }
    return false;
}

/**
 * Activa o desactiva app_control.elf en [AutoRun] de payloads.ini vía FTP.
 */
function ac_set_autorun(string $host, int $port = 2121, bool $enable = true): bool {
    $ftp = ghm_ftp_open($host, $port, 8);
    if (!$ftp) return false;
    $temp = tempnam(sys_get_temp_dir(), 'ca_ini_');
    $remote_ini = '/data/GoldHEN/payloads.ini';
    if (!@ftp_get($ftp, $temp, $remote_ini, FTP_BINARY)) {
        @unlink($temp);
        @ftp_close($ftp);
        return false;
    }
    $content = @file_get_contents($temp);
    if (!is_string($content)) { @unlink($temp); @ftp_close($ftp); return false; }

    // Asegurar que el destino AutoRun existe antes de escribir una referencia persistente.
    $local_payload = realpath(__DIR__ . '/../payloads/app_control.elf');
    if (!$local_payload || !is_file($local_payload) || @filesize($local_payload) < 64) {
        @unlink($temp); @ftp_close($ftp); return false;
    }
    @ftp_mkdir($ftp, '/user/data/payloads');
    if (!@ftp_put($ftp, '/user/data/payloads/app_control.elf', $local_payload, FTP_BINARY)
        || (int)@ftp_size($ftp, '/user/data/payloads/app_control.elf') !== (int)@filesize($local_payload)) {
        @unlink($temp); @ftp_close($ftp); return false;
    }

    $newline = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\n|\r/', $content);
    $out = [];
    $in_autorun = false;
    $autorun_found = false;
    $entry_written = false;
    foreach ($lines as $line) {
        if (preg_match('/^\s*\[([^]]+)\]\s*$/', $line, $section)) {
            if ($in_autorun && $enable && !$entry_written) { $out[] = '/user/data/payloads/app_control.elf = 1'; $entry_written = true; }
            $in_autorun = strcasecmp(trim($section[1]), 'AutoRun') === 0;
            $autorun_found = $autorun_found || $in_autorun;
            $out[] = $line;
            continue;
        }
        if ($in_autorun && preg_match('~^\s*/?user/data/payloads/app_control\.elf\s*=.*$~i', trim($line))) {
            if ($enable && !$entry_written) { $out[] = '/user/data/payloads/app_control.elf = 1'; $entry_written = true; }
            continue;
        }
        $out[] = $line;
    }
    if ($enable && !$entry_written) {
        if (!$autorun_found) $out[] = '[AutoRun]';
        $out[] = '/user/data/payloads/app_control.elf = 1';
    }
    $final_content = implode($newline, $out);
    if (substr($content, -1) === "\n" || substr($content, -1) === "\r") $final_content .= $newline;

    $temp_out = tempnam(sys_get_temp_dir(), 'ca_ini_out_');
    $backup = tempnam(sys_get_temp_dir(), 'ca_ini_bak_');
    if (@file_put_contents($temp_out, $final_content) === false || @file_put_contents($backup, $content) === false) {
        @unlink($temp); @unlink($temp_out); @unlink($backup); @ftp_close($ftp); return false;
    }
    $remote_backup = $remote_ini . '.app_control.' . gmdate('Ymd_His') . '.bak';
    $saved = @ftp_put($ftp, $remote_backup, $backup, FTP_BINARY)
        && (int)@ftp_size($ftp, $remote_backup) === (int)@filesize($backup);
    if ($saved) $saved = @ftp_put($ftp, $remote_ini, $temp_out, FTP_BINARY);
    if ($saved) {
        $verify = tempnam(sys_get_temp_dir(), 'ca_ini_verify_');
        $saved = @ftp_get($ftp, $verify, $remote_ini, FTP_BINARY)
            && hash_file('sha256', $verify) === hash('sha256', $final_content);
        @unlink($verify);
    }
    if (!$saved) @ftp_put($ftp, $remote_ini, $backup, FTP_BINARY);
    @unlink($temp); @unlink($temp_out); @unlink($backup); @ftp_close($ftp);
    return (bool)$saved;
}

/**
 * Inspección no invasiva por FTP de los puntos de montaje de sandbox activos (/mnt/sandbox/pfsmnt/).
 */
function ac_query_ftp_sandbox(string $host, int $ftp_port = 2121, int $timeout_sec = 3): array {
    $errno = 0;
    $errstr = '';
    $conn = @ftp_connect($host, $ftp_port, $timeout_sec);
    if (!$conn) {
        return [
            'ok' => false,
            'code' => 'FTP_CONNECT_FAILED',
            'message' => "No se pudo conectar a la PS4 por FTP ({$host}:{$ftp_port}). Comprueba la IP y que el servidor FTP de GoldHEN esté activo."
        ];
    }

    if (!@ftp_login($conn, 'anonymous', 'anonymous')) {
        @ftp_close($conn);
        return [
            'ok' => false,
            'code' => 'FTP_AUTH_FAILED',
            'message' => "La autenticación FTP en {$host}:{$ftp_port} fue rechazada."
        ];
    }

    @ftp_pasv($conn, true);
    $target_dir = '/mnt/sandbox/pfsmnt';
    $entries = @ftp_nlist($conn, $target_dir);

    @ftp_close($conn);

    if ($entries === false) {
        return [
            'ok' => true,
            'app_running' => false,
            'title_id' => null,
            'raw_mount' => null
        ];
    }

    // Buscar carpetas con formato CUSAXXXXX-app0 o identificadores válidos
    $detected_cusa = null;
    $detected_mount = null;
    foreach ($entries as $entry) {
        $basename = basename(str_replace('\\', '/', $entry));
        if (preg_match('/^([A-Z0-9]{4}\d{5})-app0$/i', $basename, $m)) {
            $detected_cusa = strtoupper($m[1]);
            $detected_mount = $basename;
            break;
        } elseif (preg_match('/^([A-Z0-9._-]{4,16})-app0$/i', $basename, $m)) {
            $detected_cusa = strtoupper($m[1]);
            $detected_mount = $basename;
            break;
        }
    }

    return [
        'ok' => true,
        'app_running' => ($detected_cusa !== null),
        'title_id' => $detected_cusa,
        'raw_mount' => $detected_mount
    ];
}

try {
    // ----------------------------------------------------
    // ACCIÓN: INSTALLED_TITLES (Catálogo de apps instaladas)
    // ----------------------------------------------------
    if ($action === 'installed_titles') {
        $cache_dir = __DIR__ . '/../user/cache/biblioteca';
        if (!is_dir($cache_dir)) @mkdir($cache_dir, 0777, true);
        $metadata_file = $cache_dir . '/titles.json';
        $titles_map = [];

        if (is_file($metadata_file)) {
            $raw = @file_get_contents($metadata_file);
            $decoded = json_decode((string)$raw, true);
            if (is_array($decoded)) {
                $titles_map = $decoded;
            }
        }

        // Si el inventario está vacío o el cliente solicita refresco forzado con IP disponible
        $force_scan = !empty($_POST['force']) || !empty($_GET['force']);
        if ((count($titles_map) === 0 || $force_scan) && ac_valid_ip($host_ip)) {
            $conn = @ftp_connect($host_ip, $port, 3);
            if ($conn && @ftp_login($conn, 'anonymous', 'anonymous')) {
                @ftp_pasv($conn, true);
                $rutas = ["/user/appmeta", "/system_data/priv/appmeta"];
                $discovered_cusa = [];
                foreach ($rutas as $base_path) {
                    $list = @ftp_nlist($conn, $base_path);
                    if (is_array($list)) {
                        foreach ($list as $entry) {
                            $item = strtoupper(basename(str_replace('\\', '/', $entry)));
                            if (ac_valid_title_id($item)) {
                                $discovered_cusa[$item] = true;
                            }
                        }
                    }
                }

                foreach (array_keys($discovered_cusa) as $cusa) {
                    $sfo_local = $cache_dir . '/' . $cusa . '.sfo';
                    $icon_local = $cache_dir . '/' . $cusa . '.png';

                    if (!is_file($sfo_local) || @filesize($sfo_local) === 0) {
                        foreach (["/user/appmeta/{$cusa}/param.sfo", "/system_data/priv/appmeta/{$cusa}/param.sfo"] as $rem) {
                            if (@ftp_get($conn, $sfo_local, $rem, FTP_BINARY)) break;
                        }
                    }

                    if (!is_file($icon_local) || @filesize($icon_local) === 0) {
                        foreach (["/user/appmeta/{$cusa}/icon0.png", "/system_data/priv/appmeta/{$cusa}/icon0.png"] as $rem) {
                            if (@ftp_get($conn, $icon_local, $rem, FTP_BINARY)) break;
                        }
                    }

                    $title_name = $cusa;
                    $ver = '01.00';
                    $cat = 'game';
                    if (is_file($sfo_local)) {
                        $parsed = ac_parse_sfo($sfo_local);
                        if ($parsed) {
                            $raw_t = $parsed['TITLE'] ?? $parsed['APP_TITLE'] ?? '';
                            if ($raw_t && !preg_match('/^\d+$/', trim($raw_t))) {
                                $title_name = trim(preg_replace('/\s+/', ' ', $raw_t));
                            }
                            if (!empty($parsed['APP_VER'])) $ver = $parsed['APP_VER'];
                            if (!empty($parsed['CATEGORY'])) $cat = $parsed['CATEGORY'];
                        }
                    }

                    $titles_map[$cusa] = [
                        'id' => $cusa,
                        'name' => $title_name,
                        'version' => $ver,
                        'category' => $cat,
                        'icon' => is_file($icon_local) ? "user/cache/biblioteca/{$cusa}.png" : ''
                    ];
                }
                @ftp_close($conn);

                if (!empty($titles_map)) {
                    @file_put_contents($metadata_file, json_encode($titles_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }
            }
        }

        $titles = [];
        foreach ($titles_map as $id => $data) {
            if (!is_array($data) || !ac_valid_title_id((string)$id)) continue;
            $id_clean = strtoupper((string)$id);
            $meta = ac_get_title_metadata($id_clean, $host_ip, $port);
            $titles[] = [
                'id' => $id_clean,
                'name' => $meta['name'],
                'version' => $meta['version'],
                'icon' => $meta['icon'],
                'category' => $meta['category']
            ];
        }

        usort($titles, function($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });

        app_control_reply([
            'status' => 'success',
            'action' => 'installed_titles',
            'count' => count($titles),
            'titles' => $titles,
            'request_id' => $request_id
        ]);
    }

    // Para acciones que interactúan con la consola, validar IP
    if (!ac_valid_ip($host_ip)) {
        app_control_reply([
            'status' => 'error',
            'code' => 'INVALID_IP',
            'message' => 'Dirección IP de la consola no válida o vacía.',
            'request_id' => $request_id
        ], 400);
    }

    // ----------------------------------------------------
    // ACCIÓN: STATUS (Consultar estado en primer plano)
    // ----------------------------------------------------
    if ($action === 'status') {
        $now_str = date('Y-m-d H:i:s');
        $now_ts = time();

        // 1. Probar primero si hay un helper daemon residente
        $daemon_data = ac_query_helper_daemon($host_ip, 9091, 1);
        if ($daemon_data && isset($daemon_data['status']) && $daemon_data['status'] === 'success') {
            $t_id = !empty($daemon_data['title_id']) ? strtoupper($daemon_data['title_id']) : null;
            if (!$t_id && !empty($daemon_data['app_running'])) {
                $ftp_res = ac_query_ftp_sandbox($host_ip, $port, 3);
                if ($ftp_res['ok'] && $ftp_res['app_running'] && !empty($ftp_res['title_id'])) {
                    $t_id = strtoupper($ftp_res['title_id']);
                }
            }
            $meta = $t_id ? ac_get_title_metadata($t_id, $host_ip, $port) : null;
            app_control_reply([
                'status' => 'success',
                'action' => 'status',
                'app_running' => (bool)$daemon_data['app_running'],
                'title_id' => $t_id,
                'name' => $meta ? $meta['name'] : ($daemon_data['name'] ?? null),
                'icon' => $meta ? $meta['icon'] : '',
                'version' => $meta ? $meta['version'] : '01.00',
                'state' => $daemon_data['state'] ?? ($daemon_data['app_running'] ? 'running' : 'idle_home'),
                'read_time' => $now_str,
                'timestamp' => $now_ts,
                'method' => 'helper_daemon',
                'request_id' => $request_id
            ]);
        }

        // 2. Si no hay daemon, consultar el estado por inspección FTP no invasiva del sandbox
        $ftp_res = ac_query_ftp_sandbox($host_ip, $port, 3);
        if (!$ftp_res['ok']) {
            app_control_reply([
                'status' => 'error',
                'action' => 'status',
                'code' => $ftp_res['code'],
                'message' => $ftp_res['message'],
                'read_time' => $now_str,
                'timestamp' => $now_ts,
                'request_id' => $request_id
            ], 502);
        }

        if ($ftp_res['app_running'] && !empty($ftp_res['title_id'])) {
            $meta = ac_get_title_metadata($ftp_res['title_id'], $host_ip, $port);
            app_control_reply([
                'status' => 'success',
                'action' => 'status',
                'app_running' => true,
                'title_id' => $ftp_res['title_id'],
                'name' => $meta['name'],
                'icon' => $meta['icon'],
                'version' => $meta['version'],
                'state' => 'running',
                'detail' => "Montaje activo: {$ftp_res['raw_mount']}",
                'read_time' => $now_str,
                'timestamp' => $now_ts,
                'method' => 'ftp_sandbox',
                'request_id' => $request_id
            ]);
        } else {
            app_control_reply([
                'status' => 'success',
                'action' => 'status',
                'app_running' => false,
                'title_id' => null,
                'name' => null,
                'icon' => '',
                'version' => null,
                'state' => 'idle_home',
                'message' => 'Menú principal de PS4 activo (sin aplicación en primer plano)',
                'read_time' => $now_str,
                'timestamp' => $now_ts,
                'method' => 'ftp_sandbox',
                'request_id' => $request_id
            ]);
        }
    }

    // ----------------------------------------------------
    // ACCIÓN: LAUNCH (Abrir aplicación instalada)
    // ----------------------------------------------------
    if ($action === 'launch') {
        if (!ac_valid_title_id($cusa_id)) {
            app_control_reply([
                'status' => 'error',
                'code' => 'INVALID_TITLE_ID',
                'message' => 'Identificador Title ID (CUSA) no válido.',
                'request_id' => $request_id
            ], 400);
        }

        // Validación de seguridad obligatoria: debe pertenecer a la biblioteca instalada
        if (!ac_is_title_in_library($cusa_id)) {
            app_control_reply([
                'status' => 'error',
                'code' => 'TITLE_NOT_IN_LIBRARY',
                'message' => "El Title ID {$cusa_id} no pertenece al inventario verificado de la Biblioteca. Se rechazan parámetros arbitrarios.",
                'request_id' => $request_id
            ], 403);
        }

        if ($user_id !== 'active' && (!ctype_digit($user_id) || (int)$user_id <= 0)) {
            app_control_reply([
                'status' => 'error',
                'code' => 'INVALID_USER_ID',
                'message' => 'El perfil seleccionado no tiene un identificador válido.',
                'request_id' => $request_id
            ], 400);
        }

        $meta = ac_get_title_metadata($cusa_id, $host_ip, $port);
        $helper_status = ac_query_helper_daemon($host_ip, 9091, 1);
        if (!$helper_status || version_compare((string)($helper_status['version'] ?? '0'), '1.1.0', '<')) {
            app_control_reply([
                'status' => 'error',
                'action' => 'launch',
                'code' => 'HELPER_UPDATE_REQUIRED',
                'title_id' => $cusa_id,
                'name' => $meta['name'],
                'message' => 'Se bloqueó el lanzamiento porque el helper activo no confirma el perfil de usuario (versión requerida 1.1.0). Reinicia la PS4 para cerrar la instancia anterior y luego inicia el payload actualizado. No se envió ninguna orden de apertura.',
                'request_id' => $request_id
            ], 409);
        }

        // Probar si hay helper daemon en 9091
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($host_ip, 9091, $errno, $errstr, 2);
        if ($sock) {
            stream_set_timeout($sock, 4);
            $msg = json_encode([
                'action' => 'launch',
                'title_id' => $cusa_id,
                'user_id' => $user_id,
                'request_id' => $request_id
            ]) . "\n";
            $written = @fwrite($sock, $msg);
            $resp = @fgets($sock, 4096);
            fclose($sock);
            $decoded = $resp ? json_decode(trim($resp), true) : null;
            if (is_array($decoded) && in_array(($decoded['status'] ?? ''), ['success', 'accepted'], true)) {
                $confirmed = ($decoded['status'] === 'success');
                app_control_reply([
                    'status' => $confirmed ? 'success' : 'accepted',
                    'action' => 'launch',
                    'title_id' => $cusa_id,
                    'name' => $meta['name'],
                    'state' => $confirmed ? 'foreground' : 'accepted',
                    'message' => $decoded['message'] ?? ($confirmed ? "{$meta['name']} ({$cusa_id}) está en primer plano." : "La consola aceptó la solicitud para {$meta['name']} ({$cusa_id}), pero aún no se confirmó en primer plano."),
                    'method' => 'helper_daemon',
                    'request_id' => $request_id
                ], $confirmed ? 200 : 202);
            }
            if (is_array($decoded) && ($decoded['status'] ?? '') === 'error') {
                app_control_reply([
                    'status' => 'error', 'action' => 'launch',
                    'code' => $decoded['code'] ?? 'LAUNCH_REJECTED',
                    'title_id' => $cusa_id, 'name' => $meta['name'],
                    'message' => $decoded['message'] ?? 'El servicio de la consola rechazó la apertura.',
                    'request_id' => $request_id
                ], 409);
            }

            // El helper puede ejecutar la apertura y cerrar/atrasar su respuesta TCP.
            // Si el comando completo salió, verifica el estado sin volver a enviarlo.
            // Esto evita informar un canal inactivo cuando la PS4 ya abrió la app.
            if ($written === strlen($msg)) {
                $verified = null;
                $verify_deadline = microtime(true) + 3.0;
                do {
                    $verified = ac_query_helper_daemon($host_ip, 9091, 1);
                    if ($verified && !empty($verified['app_running'])
                        && strtoupper((string)($verified['title_id'] ?? '')) === $cusa_id) {
                        app_control_reply([
                            'status' => 'success',
                            'action' => 'launch',
                            'title_id' => $cusa_id,
                            'name' => $meta['name'],
                            'state' => 'foreground',
                            'message' => "{$meta['name']} ({$cusa_id}) está en primer plano; confirmado al consultar el estado del helper.",
                            'method' => 'helper_daemon_status_confirmation',
                            'request_id' => $request_id
                        ]);
                    }
                    if ($verified && !empty($verified['app_running'])) break;
                    usleep(250000);
                } while (microtime(true) < $verify_deadline);

                app_control_reply([
                    'status' => 'accepted',
                    'action' => 'launch',
                    'code' => 'LAUNCH_SENT_UNCONFIRMED',
                    'title_id' => $cusa_id,
                    'name' => $meta['name'],
                    'state' => 'accepted',
                    'message' => "La orden para abrir {$meta['name']} ({$cusa_id}) se envió a la PS4, pero no se pudo confirmar que quedara en primer plano. Actualiza el estado para comprobarlo; no se reenvió.",
                    'method' => 'helper_daemon',
                    'request_id' => $request_id
                ], 202);
            }
        }

        // Si no hay helper daemon en 9091 ni canal activo, reportar error honesto sin engañar al usuario
        app_control_reply([
            'status' => 'error',
            'action' => 'launch',
            'code' => 'CONTROL_CHANNEL_UNAVAILABLE',
            'title_id' => $cusa_id,
            'name' => $meta['name'],
            'message' => "No se pudo abrir {$meta['name']} ({$cusa_id}): el servicio de Control de Apps no está activo en 9091. BinLoader 9090 se usa para cargar el ELF del servicio; no procesa órdenes JSON de lanzamiento. Pulsa Iniciar Servicio y espera la confirmación en 9091.",
            'request_id' => $request_id
        ], 503);
    }

    // ----------------------------------------------------
    // ACCIÓN: CLOSE_GRACEFUL (Cierre normal del sistema)
    // ----------------------------------------------------
    if ($action === 'close_graceful') {
        if (!ac_valid_title_id($cusa_id)) {
            app_control_reply([
                'status' => 'error',
                'code' => 'INVALID_TITLE_ID',
                'message' => 'Identificador Title ID (CUSA) no válido.',
                'request_id' => $request_id
            ], 400);
        }

        // Probar si el daemon expone cierre normal
        $errno = 0;
        $errstr = '';
        $sock = @fsockopen($host_ip, 9091, $errno, $errstr, 2);
        if ($sock) {
            stream_set_timeout($sock, 4);
            $msg = json_encode([
                'action' => 'close_graceful',
                'title_id' => $cusa_id,
                'request_id' => $request_id
            ]) . "\n";
            @fwrite($sock, $msg);
            $resp = @fgets($sock, 4096);
            fclose($sock);
            $decoded = $resp ? json_decode(trim($resp), true) : null;
            if (is_array($decoded) && ($decoded['status'] ?? '') === 'success') {
                app_control_reply([
                    'status' => 'success',
                    'action' => 'close_graceful',
                    'title_id' => $cusa_id,
                    'state' => 'closed',
                    'message' => "La aplicación {$cusa_id} fue cerrada ordenadamente.",
                    'method' => 'helper_daemon',
                    'request_id' => $request_id
                ]);
            }
        }

        // Si no hay interfaz de cierre normal confirmada
        app_control_reply([
            'status' => 'not_supported',
            'action' => 'close_graceful',
            'code' => 'GRACEFUL_NOT_AVAILABLE',
            'message' => 'No hay una interfaz de cierre normal del sistema confirmada en este entorno. Debe usar Cierre Forzado con confirmación explícita.',
            'title_id' => $cusa_id,
            'request_id' => $request_id
        ], 422);
    }

    // ----------------------------------------------------
    // ACCIÓN: CLOSE_FORCE (Cierre forzado con confirmación)
    // ----------------------------------------------------
    if ($action === 'close_force') {
        // Validación 1: Bandera explícita de confirmación obligatoria
        if ($confirm_force !== '1') {
            app_control_reply([
                'status' => 'error',
                'code' => 'CONFIRMATION_REQUIRED',
                'message' => 'El cierre forzado requiere confirmación explícita inmediata (confirm_force=1).',
                'request_id' => $request_id
            ], 400);
        }

        if (!ac_valid_title_id($cusa_id)) {
            app_control_reply([
                'status' => 'error',
                'code' => 'INVALID_TITLE_ID',
                'message' => 'Identificador Title ID (CUSA) no válido.',
                'request_id' => $request_id
            ], 400);
        }

        // Validación 2: Proteger procesos y servicios críticos del sistema
        $critical_targets = ['NPXS20001', 'NPXS20002', 'CUSA00000', 'SCESHELLUI', 'SCESHELLCORE', 'BINLOADER'];
        if (in_array(strtoupper($cusa_id), $critical_targets, true) || strpos(strtoupper($cusa_id), 'NPXS') === 0) {
            app_control_reply([
                'status' => 'error',
                'code' => 'PROTECTED_SYSTEM_PROCESS',
                'message' => "Operación cancelada: {$cusa_id} es un servicio o componente protegido del sistema y no puede cerrarse.",
                'request_id' => $request_id
            ], 403);
        }

        // Validación 3: Comprobar que el proceso o app activa no cambió entre la confirmación y la ejecución
        $check_sandbox = ac_query_ftp_sandbox($host_ip, $port, 2);
        if (empty($check_sandbox['ok'])) {
            app_control_reply([
                'status' => 'error',
                'code' => 'ACTIVE_TITLE_UNVERIFIED',
                'message' => 'No se pudo verificar por FTP qué aplicación está activa; se cancela el cierre forzado por seguridad. Actualice el estado y vuelva a intentarlo.',
                'request_id' => $request_id
            ], 502);
        }
        if (!$check_sandbox['app_running'] || $check_sandbox['title_id'] !== $cusa_id) {
                app_control_reply([
                    'status' => 'error',
                    'code' => 'TARGET_MISMATCH',
                    'message' => "El proceso activo cambió o ya no está en ejecución (actual: " . ($check_sandbox['title_id'] ?? 'menú principal') . "). Actualice el estado antes de intentar cerrar.",
                    'request_id' => $request_id
                ], 409);
        }

        // Enviar orden de cierre forzado al helper si existe en puerto 9091
        $sock = @fsockopen($host_ip, 9091, $errno, $errstr, 2);
        if ($sock) {
            stream_set_timeout($sock, 4);
            $msg = json_encode([
                'action' => 'close_force',
                'title_id' => $cusa_id,
                'confirm_force' => '1',
                'request_id' => $request_id
            ]) . "\n";
            @fwrite($sock, $msg);
            $resp = @fgets($sock, 4096);
            fclose($sock);
            $decoded = $resp ? json_decode(trim($resp), true) : null;
            if (is_array($decoded) && in_array(($decoded['status'] ?? ''), ['success', 'accepted'], true)) {
                $confirmed = ($decoded['status'] === 'success');
                app_control_reply([
                    'status' => $confirmed ? 'success' : 'accepted',
                    'action' => 'close_force',
                    'title_id' => $cusa_id,
                    'state' => $confirmed ? 'no_longer_foreground' : 'accepted',
                    'message' => $decoded['message'] ?? ($confirmed ? "La aplicación {$cusa_id} ya no está en primer plano." : "La consola aceptó el cierre de {$cusa_id}, pero sigue en primer plano; actualice el estado antes de repetirlo."),
                    'warning' => 'Cualquier progreso no guardado en la aplicación puede haberse perdido.',
                    'method' => 'helper_daemon',
                    'request_id' => $request_id
                ], $confirmed ? 200 : 202);
            }
            if (is_array($decoded) && ($decoded['status'] ?? '') === 'error') {
                app_control_reply([
                    'status' => 'error', 'action' => 'close_force',
                    'code' => $decoded['code'] ?? 'CLOSE_REJECTED',
                    'title_id' => $cusa_id,
                    'message' => $decoded['message'] ?? 'La consola rechazó el cierre forzado.',
                    'request_id' => $request_id
                ], 409);
            }
        }

        // Si no hay helper daemon en 9091 ni canal activo, reportar error honesto sin engañar al usuario
        app_control_reply([
            'status' => 'error',
            'action' => 'close_force',
            'code' => 'CONTROL_CHANNEL_UNAVAILABLE',
            'title_id' => $cusa_id,
            'message' => "No se pudo cerrar {$cusa_id}: el servicio de Control de Apps no está activo en 9091. BinLoader 9090 se usa para cargar el ELF del servicio; no procesa órdenes JSON de cierre. Pulsa Iniciar Servicio y espera la confirmación en 9091.",
            'request_id' => $request_id
        ], 503);
    }

    // ----------------------------------------------------
    // ACCIÓN: SERVICE_STATUS (Comprobar estado del daemon y BinLoader)
    // ----------------------------------------------------
    if ($action === 'user_profiles') {
        $profiles_helper = ac_query_helper_daemon($host_ip, 9091, 1);
        if (!$profiles_helper || version_compare((string)($profiles_helper['version'] ?? '0'), '1.1.0', '<')) {
            app_control_reply([
                'status' => 'error',
                'code' => 'HELPER_UPDATE_REQUIRED',
                'message' => 'El helper activo no puede consultar perfiles. Reinicia la PS4 para cerrar la instancia anterior y luego inicia el payload actualizado.',
                'request_id' => $request_id
            ], 409);
        }
        $sock = @fsockopen($host_ip, 9091, $errno, $errstr, 2);
        if (!$sock) {
            app_control_reply([
                'status' => 'error',
                'code' => 'CONTROL_CHANNEL_UNAVAILABLE',
                'message' => 'Inicia el servicio de Control de Apps para consultar los perfiles conectados.',
                'request_id' => $request_id
            ], 503);
        }
        stream_set_timeout($sock, 3);
        @fwrite($sock, json_encode(['action' => 'users']) . "\n");
        $raw_users = @fgets($sock, 4096);
        fclose($sock);
        $users_data = $raw_users ? json_decode(trim($raw_users), true) : null;
        if (!is_array($users_data) || ($users_data['status'] ?? '') !== 'success' || !is_array($users_data['users'] ?? null)) {
            app_control_reply([
                'status' => 'error',
                'code' => 'USER_PROFILES_UNAVAILABLE',
                'message' => is_array($users_data) ? ($users_data['message'] ?? 'La consola no devolvió perfiles válidos.') : 'La consola no respondió al consultar los perfiles.',
                'request_id' => $request_id
            ], 502);
        }
        app_control_reply([
            'status' => 'success',
            'action' => 'user_profiles',
            'active_user_id' => (int)($users_data['active_user_id'] ?? 0),
            'users' => $users_data['users'],
            'request_id' => $request_id
        ]);
    }

    if ($action === 'service_status') {
        $daemon_data = ac_query_helper_daemon($host_ip, 9091, 1);
        $is_active = ($daemon_data && ($daemon_data['status'] ?? '') === 'success');
        $autorun_enabled = ac_check_autorun_enabled($host_ip, $port);

        app_control_reply([
            'status' => 'success',
            'action' => 'service_status',
            'service_active' => $is_active,
            'port' => 9091,
            'service_version' => $is_active ? (string)($daemon_data['version'] ?? '') : '',
            'requires_helper_update' => $is_active && version_compare((string)($daemon_data['version'] ?? '0'), '1.1.0', '<'),
            'autorun_enabled' => $autorun_enabled,
            'daemon_info' => $daemon_data,
            'request_id' => $request_id
        ]);
    }

    // ----------------------------------------------------
    // ACCIÓN: START_SERVICE (Iniciar servicio / enviar payload)
    // ----------------------------------------------------
    if ($action === 'start_service') {
        $daemon_data = ac_query_helper_daemon($host_ip, 9091, 1);
        if ($daemon_data && ($daemon_data['status'] ?? '') === 'success') {
            if (version_compare((string)($daemon_data['version'] ?? '0'), '1.1.0', '<')) {
                app_control_reply([
                    'status' => 'error',
                    'action' => 'start_service',
                    'code' => 'HELPER_UPDATE_REQUIRED',
                    'service_active' => true,
                    'service_version' => (string)($daemon_data['version'] ?? 'desconocida'),
                    'message' => 'Hay un helper anterior activo que fija user_id=0 y no debe lanzar apps. Reinicia la PS4 para cerrar esa instancia; después vuelve a iniciar el payload actualizado con selección segura de perfil.',
                    'request_id' => $request_id
                ], 409);
            }
            $autorun_enabled = ac_check_autorun_enabled($host_ip, $port);
            app_control_reply([
                'status' => 'success',
                'action' => 'start_service',
                'already_running' => true,
                'service_active' => true,
                'autorun_enabled' => $autorun_enabled,
                'message' => 'El servicio ya está activo y respondiendo en el puerto 9091.',
                'daemon_info' => $daemon_data,
                'request_id' => $request_id
            ]);
        }

        // Enviar en una sola conexión real: nunca hacer un connect/close vacío a 9090.
        $local_payload = realpath(__DIR__ . '/../payloads/app_control.elf');
        if (!$local_payload || !is_file($local_payload)) {
            app_control_reply([
                'status' => 'error',
                'action' => 'start_service',
                'code' => 'PAYLOAD_MISSING',
                'message' => 'No se encontró payloads/app_control.elf en esta distribución.',
                'request_id' => $request_id
            ], 500);
        }

        $bytes = @file_get_contents($local_payload);
        if (!is_string($bytes) || strlen($bytes) < 64 || substr($bytes, 0, 4) !== "\x7fELF") {
            app_control_reply([
                'status' => 'error',
                'action' => 'start_service',
                'code' => 'PAYLOAD_INVALID',
                'message' => 'El payload local no es un ELF legible; no se envió nada a la consola.',
                'request_id' => $request_id
            ], 500);
        }

        $send_res = ghm_binloader_send($host_ip, $bytes, false);
        if (empty($send_res['ok'])) {
            app_control_reply([
                'status' => 'error',
                'action' => 'start_service',
                'code' => 'BINLOADER_SEND_FAILED',
                'sent' => false,
                'service_active' => false,
                'message' => (string)($send_res['message'] ?? 'BinLoader rechazó la conexión antes de completar el envío.'),
                'request_id' => $request_id
            ], 503);
        }

        // Espera acotada. Solo se consulta 9091; la escritura TCP no confirma ejecución.
        $daemon_data = null;
        $deadline = microtime(true) + 6.0;
        do {
            usleep(500000);
            $daemon_data = ac_query_helper_daemon($host_ip, 9091, 1);
            if ($daemon_data && ($daemon_data['status'] ?? '') === 'success') break;
        } while (microtime(true) < $deadline);

        if ($daemon_data && ($daemon_data['status'] ?? '') === 'success') {
            app_control_reply([
                'status' => 'success',
                'action' => 'start_service',
                'sent' => true,
                'service_active' => true,
                'port' => 9091,
                'bytes_sent' => (int)($send_res['bytes'] ?? strlen($bytes)),
                'message' => 'El payload se envió y el servicio respondió en el puerto 9091.',
                'daemon_info' => $daemon_data,
                'request_id' => $request_id
            ]);
        }

        app_control_reply([
            'status' => 'sent_unconfirmed',
            'action' => 'start_service',
            'code' => 'SERVICE_START_UNCONFIRMED',
            'sent' => true,
            'service_active' => false,
            'port' => 9091,
            'bytes_sent' => (int)($send_res['bytes'] ?? strlen($bytes)),
            'message' => 'BinLoader recibió todos los bytes, pero el servicio no respondió en 9091 dentro de 6 segundos. No se reenviará automáticamente. Comprueba la consola y la compatibilidad de este ELF.',
            'request_id' => $request_id
        ], 202);
    }

    // ----------------------------------------------------
    // ACCIÓN: TOGGLE_AUTORUN (Configurar payloads.ini en GoldHEN)
    // ----------------------------------------------------
    if ($action === 'toggle_autorun') {
        $current = ac_check_autorun_enabled($host_ip, $port);
        $target = !$current;
        $ok = ac_set_autorun($host_ip, $port, $target);
        if (!$ok) {
            app_control_reply([
                'status' => 'error',
                'action' => 'toggle_autorun',
                'code' => 'AUTORUN_UPDATE_FAILED',
                'message' => 'No se pudo actualizar payloads.ini en la consola mediante FTP.',
                'request_id' => $request_id
            ], 500);
        }
        app_control_reply([
            'status' => 'success',
            'action' => 'toggle_autorun',
            'autorun_enabled' => $target,
            'message' => $target
                ? 'Inicio automático activado: app_control.elf se cargará automáticamente cada vez que inicies GoldHEN.'
                : 'Inicio automático desactivado en payloads.ini.',
            'request_id' => $request_id
        ]);
    }

} finally {
    @flock($lock, LOCK_UN);
    @fclose($lock);
}
