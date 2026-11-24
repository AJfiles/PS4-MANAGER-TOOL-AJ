<?php
require_once __DIR__ . '/request_guard.php';
require_once __DIR__ . '/cache_helpers.php';
require_once __DIR__ . '/ftp_compat.php';
require_once __DIR__ . '/binloader_client.php';
require_once __DIR__ . '/library_listing_helpers.php';

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
$user_id = trim((string)($_POST['user_id'] ?? ''));
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

/** Candidate locations for metadata kept for external-storage titles. */
function ac_title_metadata_paths(string $title_id, string $filename): array {
    $title_id = strtoupper($title_id);
    return [
        "/user/appmeta/{$title_id}/{$filename}",
        "/user/appmeta/external/{$title_id}/{$filename}",
        "/system_data/priv/appmeta/{$title_id}/{$filename}",
        "/system_data/priv/appmeta/external/{$title_id}/{$filename}",
        "/mnt/ext0/user/appmeta/{$title_id}/{$filename}",
        "/mnt/ext1/user/appmeta/{$title_id}/{$filename}",
        "/mnt/ext0/user/appmeta/external/{$title_id}/{$filename}",
        "/mnt/ext1/user/appmeta/external/{$title_id}/{$filename}",
    ];
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

    // Leer metadatos con el adaptador compatible con ext-ftp y cURL de Android.
    if (($meta['name'] === $title_id || !is_file($icon_path) || @filesize($icon_path) === 0) && ac_valid_ip($host_ip)) {
        {
            if (!is_file($sfo_path) || @filesize($sfo_path) === 0) {
                foreach (ac_title_metadata_paths($title_id, 'param.sfo') as $rem_sfo) {
                    $remote_sfo = ghm_ftp_get($host_ip, $port, $rem_sfo, 8);
                    if (is_string($remote_sfo) && $remote_sfo !== '' && @file_put_contents($sfo_path, $remote_sfo) === strlen($remote_sfo)) break;
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
                foreach (ac_title_metadata_paths($title_id, 'icon0.png') as $rem_icon) {
                    $remote_icon = ghm_ftp_get($host_ip, $port, $rem_icon, 15);
                    if (is_string($remote_icon) && $remote_icon !== '' && @file_put_contents($icon_path, $remote_icon) === strlen($remote_icon)) break;
                }
            }
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

function ac_is_title_in_library(string $title_id, string $host = '', int $port = 2121): bool {
    if (!ac_valid_ip($host)) return false;
    $installed = ac_read_installed_title_ids($host, $port);
    return is_array($installed) && isset($installed[strtoupper($title_id)]);
}

/** Return installed title IDs from app directories on internal/active ext mounts. */
function ac_read_title_dir_ids(string $host, int $port, string $path, ?bool &$read_succeeded = null): array {
    if ($read_succeeded !== null) $read_succeeded = false;
    $rows = ghm_ftp_list($host, $port, $path, 10);
    if ($rows === false) return [];
    if ($read_succeeded !== null) $read_succeeded = true;
    $ids = [];
    foreach ($rows as $row) {
        if (empty($row['dir'])) continue;
        $id = strtoupper((string)($row['name'] ?? ''));
        if (ac_valid_title_id($id)) $ids[$id] = rtrim($path, '/');
    }
    return $ids;
}

function ac_read_installed_title_ids(string $host, int $port): ?array {
    $ids = [];
    $read_any = false;

    $internal_read = false;
    $ids += ac_read_title_dir_ids($host, $port, '/user/app', $internal_read);
    $read_any = $read_any || $internal_read;

    $mounts_read = false;
    $mounts = lb_read_directory_names($host, $port, '/mnt', $mounts_read);
    $read_any = $read_any || $mounts_read;
    if ($mounts_read) {
        foreach ($mounts as $mount) {
            if (!preg_match('/^ext[01]$/i', $mount)) continue;
            $external_read = false;
            $ids += ac_read_title_dir_ids($host, $port, '/mnt/' . $mount . '/user/app', $external_read);
            $read_any = $read_any || $external_read;
        }
    }

    return $read_any ? $ids : null;
}

/** Read local PS4 profile directories without claiming they are signed in. */
function ac_ftp_directory_rows(string $host, int $port, string $path): array|false {
    $rows = ghm_ftp_list($host, $port, $path, 10);
    if ($rows !== false || !function_exists('curl_init')) return $rows;
    $url = 'ftp://' . $host . ':' . $port . '/' . ltrim($path, '/') . '/';
    $ch = @curl_init($url);
    if (!$ch) return false;
    @curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_FTP_RESPONSE_TIMEOUT => 8, CURLOPT_FTP_USE_EPSV => false, CURLOPT_DIRLISTONLY => true, CURLOPT_USERPWD => 'anonymous:', CURLOPT_PROXY => '']);
    $listing = @curl_exec($ch);
    $error = curl_errno($ch);
    curl_close($ch);
    if (!is_string($listing) || $error !== 0) return false;
    $rows = [];
    foreach (preg_split('/\r\n|\n|\r/', trim($listing)) as $name) {
        $name = trim((string)$name);
        if ($name !== '' && $name !== '.' && $name !== '..' && !str_contains($name, '/') && !str_contains($name, '\\')) $rows[] = ['name' => $name, 'dir' => true];
    }
    return $rows;
}

function ac_read_home_profiles(string $host, int $port): array|false {
    $rows = ac_ftp_directory_rows($host, $port, '/user/home');
    if ($rows === false) return false;
    $profiles = [];
    foreach ($rows as $row) {
        if (empty($row['dir'])) continue;
        $folder = strtolower((string)($row['name'] ?? ''));
        if (!preg_match('/^[a-f0-9]{1,8}$/', $folder)) continue;
        $decimal = base_convert($folder, 16, 10);
        if (!ctype_digit($decimal) || (float)$decimal <= 0) continue;
        $raw_name = ghm_ftp_get($host, $port, '/user/home/' . $folder . '/username.dat', 8);
        $name = 'Perfil ' . strtoupper(str_pad($folder, 8, '0', STR_PAD_LEFT));
        if (is_string($raw_name)) {
            $raw_name = explode("\0", $raw_name, 2)[0];
            $candidate = trim((string)preg_replace('/[\x00-\x1f\x7f]/', '', $raw_name));
            if ($candidate !== '' && preg_match('//u', $candidate)) $name = function_exists('mb_substr') ? mb_substr($candidate, 0, 64, 'UTF-8') : substr($candidate, 0, 64);
        }
        $profiles[$decimal] = ['id' => $decimal, 'folder' => str_pad($folder, 8, '0', STR_PAD_LEFT), 'name' => $name, 'logged_in' => null, 'active' => false];
    }
    return $profiles;
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
    $content = ghm_ftp_get($host, $port, '/data/GoldHEN/payloads.ini', 8);
    if (!is_string($content)) return false;
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

function ac_ftp_put_content_verified(string $host, int $port, string $remote, string $content): bool {
    $local = tempnam(sys_get_temp_dir(), 'ca_ftp_write_');
    if ($local === false) return false;
    $written = @file_put_contents($local, $content);
    $ok = $written === strlen($content)
        && ghm_ftp_put($host, $port, $remote, $local, false, 45);
    if ($ok) {
        $readback = ghm_ftp_get($host, $port, $remote, 45);
        $ok = is_string($readback) && hash_equals(hash('sha256', $content), hash('sha256', $readback));
    }
    @unlink($local);
    return $ok;
}

/** cURL-only path for Android PHP runtimes without ext-ftp. */
function ac_set_autorun_curl(string $host, int $port, bool $enable): bool {
    $remote_ini = '/data/GoldHEN/payloads.ini';
    $content = ghm_ftp_get($host, $port, $remote_ini, 12);
    $ini_existed = is_string($content);
    if (!$ini_existed) {
        $exists = ghm_ftp_exists($host, $port, $remote_ini);
        if ($exists !== false) return !$enable && $exists === false;
        if (!$enable) return true;
        $content = '';
    }
    $lines = preg_split('/\r\n|\n|\r/', $content);
    $scan_autorun = false;
    $autorun_found = false;
    $contains_entry = false;
    foreach ($lines as $line) {
        if (preg_match('/^\s*\[([^]]+)\]\s*$/', $line, $section)) {
            $scan_autorun = strcasecmp(trim($section[1]), 'AutoRun') === 0;
            $autorun_found = $autorun_found || $scan_autorun;
        } elseif ($scan_autorun && preg_match('~^/?user/data/payloads/app_control\.elf\s*=.*$~i', trim($line))) {
            $contains_entry = true;
        }
    }
    $created_marker = strpos($content, '; GHM_APP_CONTROL_CREATED_INI') !== false;
    if (!$enable && !$contains_entry && !$created_marker) return true;

    if ($enable) {
        $payload = realpath(__DIR__ . '/../payloads/app_control.elf');
        if (!$payload || !is_file($payload) || filesize($payload) < 64 || substr((string)file_get_contents($payload, false, null, 0, 4), 0, 4) !== "\x7fELF") return false;
        if (!ghm_ftp_put($host, $port, '/user/data/payloads/app_control.elf', $payload, false, 45)) return false;
        $remote_payload = ghm_ftp_get($host, $port, '/user/data/payloads/app_control.elf', 45);
        if (!is_string($remote_payload) || !hash_equals(hash_file('sha256', $payload), hash('sha256', $remote_payload))) return false;
    }

    $newline = strpos($content, "\r\n") !== false ? "\r\n" : "\n";
    $out = [];
    $in_autorun = false;
    $entry_written = false;
    foreach ($lines as $line) {
        if (preg_match('/^\s*\[([^]]+)\]\s*$/', $line, $section)) {
            if ($in_autorun && $enable && !$entry_written) {
                $out[] = '; GHM_APP_CONTROL_AUTORUN';
                $out[] = '/user/data/payloads/app_control.elf = 1';
                $entry_written = true;
            }
            $in_autorun = strcasecmp(trim($section[1]), 'AutoRun') === 0;
            $out[] = $line;
            continue;
        }
        if ($in_autorun && preg_match('~^/?user/data/payloads/app_control\.elf\s*=.*$~i', trim($line))) {
            if ($enable && !$entry_written) {
                $out[] = '; GHM_APP_CONTROL_AUTORUN';
                $out[] = '/user/data/payloads/app_control.elf = 1';
                $entry_written = true;
            }
            continue;
        }
        if (!$enable && trim($line) === '; GHM_APP_CONTROL_AUTORUN') continue;
        $out[] = $line;
    }
    if ($enable && !$entry_written) {
        if (!$ini_existed) $out[] = '; GHM_APP_CONTROL_CREATED_INI';
        if (!$autorun_found) $out[] = '[AutoRun]';
        $out[] = '; GHM_APP_CONTROL_AUTORUN';
        $out[] = '/user/data/payloads/app_control.elf = 1';
    }
    $meaningful = array_filter($out, static fn(string $line): bool => trim($line) !== '' && !preg_match('/^\s*\[[^]]+\]\s*$/', $line) && trim($line) !== '; GHM_APP_CONTROL_CREATED_INI');
    if (!$enable && $created_marker && !$meaningful) return ghm_ftp_delete($host, $port, $remote_ini);
    if ($created_marker) $out = array_values(array_filter($out, static fn(string $line): bool => trim($line) !== '; GHM_APP_CONTROL_CREATED_INI'));
    $final = implode($newline, $out);
    if (str_ends_with($content, "\n") || str_ends_with($content, "\r")) $final .= $newline;

    if ($ini_existed) {
        $backup_path = $remote_ini . '.app_control.' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(2)) . '.bak';
        if (!ac_ftp_put_content_verified($host, $port, $backup_path, $content)) return false;
    }
    if (ac_ftp_put_content_verified($host, $port, $remote_ini, $final)) return true;
    if ($ini_existed) ac_ftp_put_content_verified($host, $port, $remote_ini, $content);
    return false;
}

/**
 * Activa o desactiva app_control.elf en [AutoRun] de payloads.ini vía FTP.
 */
function ac_set_autorun(string $host, int $port = 2121, bool $enable = true): bool {
    if (!function_exists('ftp_connect')) return ac_set_autorun_curl($host, $port, $enable);
    $ftp = ghm_ftp_open($host, $port, 8);
    if (!$ftp) return false;
    $temp = tempnam(sys_get_temp_dir(), 'ca_ini_');
    $remote_ini = '/data/GoldHEN/payloads.ini';
    $ini_size = @ftp_size($ftp, $remote_ini);
    $ini_existed = $ini_size !== false && $ini_size >= 0;
    if (!$ini_existed && !$enable) { @unlink($temp); @ftp_close($ftp); return true; }
    if ($ini_existed && !@ftp_get($ftp, $temp, $remote_ini, FTP_BINARY)) { @unlink($temp); @ftp_close($ftp); return false; }
    $content = $ini_existed ? @file_get_contents($temp) : '';
    if (!is_string($content)) { @unlink($temp); @ftp_close($ftp); return false; }

    $target_pattern = '~^\s*/?user/data/payloads/app_control\.elf\s*=.*$~i';
    $contains_entry = false;
    $scan_autorun = false;
    foreach (preg_split('/\r\n|\n|\r/', $content) as $existing_line) {
        if (preg_match('/^\s*\[([^]]+)\]\s*$/', $existing_line, $section)) $scan_autorun = strcasecmp(trim($section[1]), 'AutoRun') === 0;
        elseif ($scan_autorun && preg_match($target_pattern, trim($existing_line))) $contains_entry = true;
    }
    $has_created_marker = strpos($content, '; GHM_APP_CONTROL_CREATED_INI') !== false;
    if (!$enable && !$contains_entry && !$has_created_marker) { @unlink($temp); @ftp_close($ftp); return true; }

    if ($enable) {
        $local_payload = realpath(__DIR__ . '/../payloads/app_control.elf');
        if (!$local_payload || !is_file($local_payload) || @filesize($local_payload) < 64) { @unlink($temp); @ftp_close($ftp); return false; }
        @ftp_mkdir($ftp, '/user/data/payloads');
        if (!@ftp_put($ftp, '/user/data/payloads/app_control.elf', $local_payload, FTP_BINARY)
            || (int)@ftp_size($ftp, '/user/data/payloads/app_control.elf') !== (int)@filesize($local_payload)) {
            @unlink($temp); @ftp_close($ftp); return false;
        }
    }

    $newline = (strpos($content, "\r\n") !== false) ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\n|\r/', $content);
    $out = [];
    $in_autorun = false;
    $autorun_found = false;
    $entry_written = false;
    foreach ($lines as $line) {
        if (preg_match('/^\s*\[([^]]+)\]\s*$/', $line, $section)) {
            if ($in_autorun && $enable && !$entry_written) { $out[] = '; GHM_APP_CONTROL_AUTORUN'; $out[] = '/user/data/payloads/app_control.elf = 1'; $entry_written = true; }
            $in_autorun = strcasecmp(trim($section[1]), 'AutoRun') === 0;
            $autorun_found = $autorun_found || $in_autorun;
            $out[] = $line;
            continue;
        }
        if ($in_autorun && preg_match('~^\s*/?user/data/payloads/app_control\.elf\s*=.*$~i', trim($line))) {
            if ($enable && !$entry_written) { $out[] = '; GHM_APP_CONTROL_AUTORUN'; $out[] = '/user/data/payloads/app_control.elf = 1'; $entry_written = true; }
            continue;
        }
        $out[] = $line;
    }
    if ($enable && !$entry_written) {
        if (!$ini_existed) $out[] = '; GHM_APP_CONTROL_CREATED_INI';
        if (!$autorun_found) $out[] = '[AutoRun]';
        $out[] = '; GHM_APP_CONTROL_AUTORUN';
        $out[] = '/user/data/payloads/app_control.elf = 1';
    }
    if (!$enable) $out = array_values(array_filter($out, static fn(string $line): bool => trim($line) !== '; GHM_APP_CONTROL_AUTORUN'));
    $final_content = implode($newline, $out);
    if (substr($content, -1) === "\n" || substr($content, -1) === "\r") $final_content .= $newline;

    $temp_out = tempnam(sys_get_temp_dir(), 'ca_ini_out_');
    $backup = tempnam(sys_get_temp_dir(), 'ca_ini_bak_');
    if (@file_put_contents($temp_out, $final_content) === false || @file_put_contents($backup, $content) === false) {
        @unlink($temp); @unlink($temp_out); @unlink($backup); @ftp_close($ftp); return false;
    }
    $created_marker = $has_created_marker;
    if (!$enable && $created_marker) {
        $meaningful = array_values(array_filter($out, static fn(string $line): bool => trim($line) !== '' && !preg_match('/^\s*\[[^]]+\]\s*$/', $line) && trim($line) !== '; GHM_APP_CONTROL_CREATED_INI'));
        if (!$meaningful) {
            $deleted = @ftp_delete($ftp, $remote_ini);
            @unlink($temp); @unlink($temp_out); @unlink($backup); @ftp_close($ftp);
            return (bool)$deleted;
        }
        $final_content = implode($newline, array_values(array_filter($out, static fn(string $line): bool => trim($line) !== '; GHM_APP_CONTROL_CREATED_INI')));
        @file_put_contents($temp_out, $final_content);
        @file_put_contents($backup, $content);
    }
    $remote_backup = $remote_ini . '.app_control.' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(2)) . '.bak';
    $saved = !$ini_existed || (@ftp_put($ftp, $remote_backup, $backup, FTP_BINARY)
        && (int)@ftp_size($ftp, $remote_backup) === (int)@filesize($backup));
    if ($saved) $saved = @ftp_put($ftp, $remote_ini, $temp_out, FTP_BINARY);
    if ($saved) {
        $verify = tempnam(sys_get_temp_dir(), 'ca_ini_verify_');
        $saved = @ftp_get($ftp, $verify, $remote_ini, FTP_BINARY)
            && hash_file('sha256', $verify) === hash('sha256', $final_content);
        @unlink($verify);
    }
    if (!$saved && $ini_existed) @ftp_put($ftp, $remote_ini, $backup, FTP_BINARY);
    @unlink($temp); @unlink($temp_out); @unlink($backup); @ftp_close($ftp);
    return (bool)$saved;
}

/**
 * Inspección no invasiva por FTP de los puntos de montaje de sandbox activos (/mnt/sandbox/pfsmnt/).
 */
function ac_query_ftp_sandbox(string $host, int $ftp_port = 2121, int $timeout_sec = 3): array {
    if (!function_exists('ftp_connect')) {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'code' => 'FTP_RUNTIME_UNAVAILABLE', 'message' => 'PHP no incluye ext-ftp ni cURL para leer el FTP de la PS4.'];
        }
        $url = 'ftp://' . $host . ':' . $ftp_port . '/mnt/sandbox/pfsmnt/';
        $ch = @curl_init($url);
        if (!$ch) return ['ok' => false, 'code' => 'FTP_CONNECT_FAILED', 'message' => 'No se pudo preparar la consulta FTP desde PHP.'];
        @curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => $timeout_sec,
            CURLOPT_TIMEOUT => max(5, $timeout_sec + 2), CURLOPT_FTP_RESPONSE_TIMEOUT => max(5, $timeout_sec + 2),
            CURLOPT_FTP_USE_EPSV => false, CURLOPT_DIRLISTONLY => true,
            CURLOPT_USERPWD => 'anonymous:', CURLOPT_PROXY => ''
        ]);
        $listing = @curl_exec($ch);
        $curl_error = curl_errno($ch);
        curl_close($ch);
        if (!is_string($listing) || $curl_error !== 0) {
            return ['ok' => false, 'code' => 'FTP_CONNECT_FAILED', 'message' => 'No se pudo listar /mnt/sandbox/pfsmnt por cURL FTP. Verifica la IP, el puerto y el FTP de GoldHEN.'];
        }
        $detected = null;
        foreach (preg_split('/\r\n|\n|\r/', trim($listing)) as $entry) {
            $base = basename(str_replace('\\', '/', (string)$entry));
            if (preg_match('/^([A-Z0-9]{4}\d{5})-app0$/i', $base, $m) || preg_match('/^([A-Z0-9._-]{4,16})-app0$/i', $base, $m)) {
                $detected = strtoupper($m[1]);
                break;
            }
        }
        return ['ok' => true, 'app_running' => $detected !== null, 'title_id' => $detected, 'raw_mount' => $detected ? $detected . '-app0' : null];
    }
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

        if (!ac_valid_ip($host_ip)) {
            app_control_reply([
                'status' => 'error', 'action' => 'installed_titles',
                'code' => 'INVALID_IP',
                'message' => 'Indica la IP de la consola para verificar las aplicaciones instaladas.',
                'request_id' => $request_id
            ], 400);
        }

        $installed_ids = ac_read_installed_title_ids($host_ip, $port);
        if ($installed_ids === null) {
            app_control_reply([
                'status' => 'error', 'action' => 'installed_titles',
                'code' => 'INSTALLED_STATE_UNAVAILABLE',
                'message' => 'No se pudo verificar por FTP qué aplicaciones siguen instaladas. Actualiza la biblioteca e inténtalo de nuevo.',
                'request_id' => $request_id
            ], 502);
        }

        // appmeta mantiene datos de títulos desinstalados. Se usa solo para
        // enriquecer IDs ya confirmados dentro de directorios de instalación.
        $force_scan = !empty($_POST['force']) || !empty($_GET['force']);
        if (count($titles_map) === 0 || $force_scan) {
            $discovered = [];
            foreach (["/user/appmeta", "/system_data/priv/appmeta"] as $base_path) {
                $read_succeeded = false;
                $found = lb_read_appmeta_directory($host_ip, $port, $base_path, $read_succeeded);
                foreach ($found as $id => $_source) $discovered[strtoupper((string)$id)] = true;
            }
            foreach (array_keys($discovered) as $id) {
                $meta = ac_get_title_metadata($id, $host_ip, $port);
                $titles_map[$id] = [
                    'id' => $id,
                    'name' => $meta['name'],
                    'version' => $meta['version'],
                    'category' => $meta['category'],
                    'icon' => $meta['icon']
                ];
            }
            if (!empty($titles_map)) {
                @file_put_contents($metadata_file, json_encode($titles_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        $titles = [];
        foreach ($installed_ids as $id => $_source) {
            if (!ac_valid_title_id((string)$id)) continue;
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
            'installed_verified' => true,
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
                'cooldown_remaining' => (int)($daemon_data['cooldown_remaining'] ?? 0),
                'cooldown_until' => (int)($daemon_data['cooldown_until'] ?? 0),
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
        if (!ac_is_title_in_library($cusa_id, $host_ip, $port)) {
            app_control_reply([
                'status' => 'error',
                'code' => 'TITLE_NOT_IN_LIBRARY',
                'message' => "El Title ID {$cusa_id} no pertenece al inventario verificado de la Biblioteca. Se rechazan parámetros arbitrarios.",
                'request_id' => $request_id
            ], 403);
        }

        if (!ctype_digit($user_id) || (int)$user_id <= 0) {
            app_control_reply([
                'status' => 'error',
                'code' => 'INVALID_USER_ID',
                'message' => 'Selecciona un perfil con sesión verificada antes de abrir la aplicación. No se usará automáticamente el perfil activo.',
                'request_id' => $request_id
            ], 400);
        }

        $meta = ac_get_title_metadata($cusa_id, $host_ip, $port);
        $helper_status = ac_query_helper_daemon($host_ip, 9091, 1);
        if (!$helper_status || version_compare((string)($helper_status['version'] ?? '0'), '1.2.1', '<')) {
            app_control_reply([
                'status' => 'error',
                'action' => 'launch',
                'code' => 'HELPER_UPDATE_REQUIRED',
                'title_id' => $cusa_id,
                'name' => $meta['name'],
                'message' => 'Se bloqueó el lanzamiento porque el helper activo no valida perfiles o conserva un intervalo anterior (versión requerida 1.2.1). Reinicia la PS4 para cerrar la instancia anterior y luego inicia el payload actualizado. No se envió ninguna orden de apertura.',
                'request_id' => $request_id
            ], 409);
        }

        // No iniciar directamente sobre otra app en primer plano. El cliente
        // debe pedir confirmación explícita, cerrar la app actual y respetar
        // el cooldown antes de volver a solicitar la apertura.
        if (!empty($helper_status['app_running'])) {
            $active_title_id = strtoupper(trim((string)($helper_status['title_id'] ?? '')));
            if (!ac_valid_title_id($active_title_id)) {
                app_control_reply([
                    'status' => 'error',
                    'action' => 'launch',
                    'code' => 'ACTIVE_TITLE_UNVERIFIED',
                    'message' => 'La PS4 informa una app activa, pero no se pudo validar su Title ID. Actualiza el estado; no se enviará la apertura.',
                    'request_id' => $request_id
                ], 409);
            }
            if ($active_title_id === $cusa_id) {
                app_control_reply([
                    'status' => 'success',
                    'action' => 'launch',
                    'title_id' => $cusa_id,
                    'state' => 'already_foreground',
                    'no_action' => true,
                    'cooldown_remaining' => (int)($helper_status['cooldown_remaining'] ?? 0),
                    'message' => "{$meta['name']} ({$cusa_id}) ya está en primer plano. No se envió otra orden.",
                    'request_id' => $request_id
                ]);
            }
            $active_meta = ac_get_title_metadata($active_title_id, $host_ip, $port);
            app_control_reply([
                'status' => 'error',
                'action' => 'launch',
                'code' => 'ACTIVE_APP_CLOSE_REQUIRED',
                'active_app' => [
                    'title_id' => $active_title_id,
                    'name' => (string)($active_meta['name'] ?? $active_title_id),
                    'icon' => (string)($active_meta['icon'] ?? '')
                ],
                'target_app' => ['title_id' => $cusa_id, 'name' => $meta['name']],
                'message' => 'Hay otra aplicación en primer plano. Confirma el cierre y espera el intervalo de seguridad antes de abrir la nueva app.',
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
                    'cooldown_remaining' => (int)($decoded['cooldown_remaining'] ?? 0),
                    'cooldown_until' => (int)($decoded['cooldown_until'] ?? 0),
                    'message' => $decoded['message'] ?? ($confirmed ? "{$meta['name']} ({$cusa_id}) está en primer plano." : "La consola aceptó la solicitud para {$meta['name']} ({$cusa_id}), pero aún no se confirmó en primer plano."),
                    'method' => 'helper_daemon',
                    'request_id' => $request_id
                ], $confirmed ? 200 : 202);
            }
            if (is_array($decoded) && ($decoded['status'] ?? '') === 'error') {
                app_control_reply([
                    'status' => 'error', 'action' => 'launch',
                    'code' => $decoded['code'] ?? 'LAUNCH_REJECTED',
                    'remaining_seconds' => (int)($decoded['remaining_seconds'] ?? 0),
                    'cooldown_until' => (int)($decoded['cooldown_until'] ?? 0),
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

        // El helper 1.2.0 conserva un cooldown distinto. No permitir mutaciones
        // hasta que la consola ejecute la versión que aplica 40 segundos.
        $close_helper = ac_query_helper_daemon($host_ip, 9091, 1);
        if (!$close_helper || ($close_helper['status'] ?? '') !== 'success'
            || version_compare((string)($close_helper['version'] ?? '0'), '1.2.1', '<')) {
            app_control_reply([
                'status' => 'error',
                'action' => 'close_force',
                'code' => 'HELPER_UPDATE_REQUIRED',
                'service_version' => (string)($close_helper['version'] ?? ''),
                'message' => 'No se envió el cierre: Control de Apps requiere el helper 1.2.1 o posterior, que aplica el intervalo de seguridad de 40 segundos. Reinicia la PS4 para detener el helper anterior y luego inicia la versión actualizada.',
                'request_id' => $request_id
            ], 409);
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
                    'cooldown_remaining' => (int)($decoded['cooldown_remaining'] ?? 0),
                    'cooldown_until' => (int)($decoded['cooldown_until'] ?? 0),
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
                    'remaining_seconds' => (int)($decoded['remaining_seconds'] ?? 0),
                    'cooldown_until' => (int)($decoded['cooldown_until'] ?? 0),
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
        $home_profiles = ac_read_home_profiles($host_ip, $port);
        if ($home_profiles === false) {
            app_control_reply([
                'status' => 'error', 'code' => 'PROFILE_DIRECTORY_UNAVAILABLE',
                'message' => 'No se pudieron leer las carpetas de perfiles de /user/home por FTP.',
                'request_id' => $request_id
            ], 502);
        }
        $users_data = null;
        // El daemon 1.2.0 ya tiene la misma consulta UserService que 1.2.1;
        // 1.2.1 solo cambia el intervalo de acciones de 120 a 40 segundos.
        // Permitir esta lectura evita ocultar sesiones reales mientras se
        // espera el reinicio necesario para actualizar el helper.
        if ($profiles_helper && ($profiles_helper['status'] ?? '') === 'success') {
            $sock = @fsockopen($host_ip, 9091, $errno, $errstr, 2);
            if ($sock) {
                stream_set_timeout($sock, 3);
                @fwrite($sock, json_encode(['action' => 'users']) . "\n");
                $raw_users = @fgets($sock, 4096);
                fclose($sock);
                $candidate = $raw_users ? json_decode(trim($raw_users), true) : null;
                if (is_array($candidate) && ($candidate['status'] ?? '') === 'success' && is_array($candidate['users'] ?? null)) $users_data = $candidate;
            }
        }
        $active_id = (int)($users_data['active_user_id'] ?? 0);
        $logged_ids = [];
        foreach (($users_data['users'] ?? []) as $logged_user) {
            if (!is_array($logged_user) || !ctype_digit((string)($logged_user['id'] ?? ''))) continue;
            $id = (string)$logged_user['id'];
            $logged_ids[$id] = true;
            if (!isset($home_profiles[$id])) {
                $home_profiles[$id] = ['id' => $id, 'folder' => '', 'name' => (string)($logged_user['name'] ?? ('Usuario ' . $id)), 'logged_in' => true, 'active' => !empty($logged_user['active']) || $active_id === (int)$id];
            }
        }
        foreach ($home_profiles as $id => &$profile) {
            if ($users_data !== null) $profile['logged_in'] = isset($logged_ids[(string)$id]);
            $profile['active'] = $users_data !== null && $active_id > 0 && $active_id === (int)$id;
        }
        unset($profile);
        usort($home_profiles, static fn(array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));
        app_control_reply([
            'status' => 'success',
            'action' => 'user_profiles',
            'active_user_id' => $active_id,
            'active_user_known' => $users_data !== null && $active_id > 0,
            'login_state_known' => $users_data !== null,
            'users' => array_values($home_profiles),
            'helper_version' => (string)($profiles_helper['version'] ?? ''),
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
            'requires_helper_update' => $is_active && version_compare((string)($daemon_data['version'] ?? '0'), '1.2.1', '<'),
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
            if (version_compare((string)($daemon_data['version'] ?? '0'), '1.2.1', '<')) {
                app_control_reply([
                    'status' => 'error',
                    'action' => 'start_service',
                    'code' => 'HELPER_UPDATE_REQUIRED',
                    'service_active' => true,
                    'service_version' => (string)($daemon_data['version'] ?? 'desconocida'),
                    'message' => 'Hay un helper anterior activo (' . (string)($daemon_data['version'] ?? 'desconocida') . '). Reinicia la PS4 para detener esa instancia; después inicia el payload 1.2.1 para activar el nuevo intervalo de 40 segundos y la selección segura de perfiles.',
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
            'message' => 'Se transmitieron ' . (int)($send_res['bytes'] ?? strlen($bytes)) . ' bytes por PayLoader 9090, pero el helper no confirmó inicio en 9091. La transferencia completa no demuestra que el ELF arrancó. No se reenvió: revisa si apareció la notificación de inicio en PS4; si 9091 continúa cerrado, reactiva PayLoader antes de otro intento.',
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

} catch (Throwable $error) {
    error_log('[app_control_api] ' . get_class($error) . ': ' . $error->getMessage());
    app_control_reply([
        'status' => 'error',
        'code' => 'CONTROL_API_RUNTIME_ERROR',
        'message' => 'La API de Control de Apps encontró un error de PHP. Revisa el registro local del servidor.',
        'request_id' => $request_id
    ], 500);
} finally {
    @flock($lock, LOCK_UN);
    @fclose($lock);
}
