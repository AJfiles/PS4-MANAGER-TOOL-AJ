<?php
declare(strict_types=1);

/** Extrae IDs de respuestas FTP LIST/NLST, con formato Unix o una columna simple. */
function lb_parse_appmeta_listing(string $listing, string $base_path): array {
    $found = [];
    foreach (preg_split('/\r?\n/', $listing) as $line) {
        $line = trim(str_replace("\0", '', $line));
        if ($line === '' || $line === '.' || $line === '..') continue;

        $parts = preg_split('/\s+/', $line, 9);
        $is_unix_list = count($parts) >= 9 && preg_match('/^[bcdlps-][rwxStTs-]{9}$/', $parts[0]);
        $name = $is_unix_list ? trim($parts[8]) : $line;
        if (str_contains($name, ' -> ')) $name = trim(explode(' -> ', $name, 2)[0]);
        $name = basename(rtrim($name, '/'));
        if (preg_match('/^[A-Z]{4}\d{5}$/i', $name) || preg_match('/^(?:LAPY|NPXS)/i', $name)) {
            $found[strtoupper($name)] = $base_path;
        }
    }
    return $found;
}

/** Lee un directorio appmeta con LIST y reintenta como NLST si cURL no devuelve IDs. */
function lb_read_appmeta_directory(string $host, int $port, string $base_path, ?bool &$read_succeeded = null): array {
    if ($read_succeeded !== null) $read_succeeded = false;
    // En Android el cURL embebido anuncia FTP, pero falla al abrir el canal de
    // datos del FTP GoldHEN. La extensión FTP nativa sí funciona y devuelve NLST.
    if (function_exists('ftp_connect') && function_exists('ftp_nlist')) {
        $ftp = @ftp_connect($host, $port, 5);
        if ($ftp) {
            @ftp_set_option($ftp, FTP_TIMEOUT_SEC, 8);
            $logged_in = @ftp_login($ftp, 'anonymous', '');
            if ($logged_in) {
                @ftp_pasv($ftp, true);
                $names = @ftp_nlist($ftp, rtrim($base_path, '/'));
                @ftp_close($ftp);
                if (is_array($names)) {
                    if ($read_succeeded !== null) $read_succeeded = true;
                    $ids = lb_parse_appmeta_listing(implode("\n", $names), $base_path);
                    if ($ids) return $ids;
                }
            } else {
                @ftp_close($ftp);
            }
        }
    }

    $url = "ftp://{$host}:{$port}" . rtrim($base_path, '/') . '/';
    foreach ([false, true] as $names_only) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_FTP_USE_EPSV, false);
        if ($names_only) curl_setopt($ch, CURLOPT_DIRLISTONLY, true);
        else curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'LIST');
        $listing = curl_exec($ch);
        $listing_ok = $listing !== false && curl_errno($ch) === 0;
        curl_close($ch);
        if ($listing_ok && $read_succeeded !== null) $read_succeeded = true;
        if (is_string($listing) && $listing !== '') {
            $ids = lb_parse_appmeta_listing($listing, $base_path);
            if ($ids) return $ids;
        }
    }
    return [];
}

/** Devuelve nombres simples de un directorio FTP y distingue vacío de fallo. */
function lb_read_directory_names(string $host, int $port, string $base_path, ?bool &$read_succeeded = null): array {
    if ($read_succeeded !== null) $read_succeeded = false;
    $listing = false;
    if (function_exists('ftp_connect') && function_exists('ftp_nlist')) {
        $ftp = @ftp_connect($host, $port, 5);
        if ($ftp) {
            @ftp_set_option($ftp, FTP_TIMEOUT_SEC, 8);
            $logged_in = @ftp_login($ftp, 'anonymous', '');
            if ($logged_in) {
                @ftp_pasv($ftp, true);
                $rows = @ftp_nlist($ftp, rtrim($base_path, '/'));
                if (is_array($rows)) {
                    $listing = implode("\n", $rows);
                    if ($read_succeeded !== null) $read_succeeded = true;
                }
            }
            @ftp_close($ftp);
        }
    }
    if ($listing === false) {
        foreach ([false, true] as $names_only) {
            $ch = curl_init("ftp://{$host}:{$port}" . rtrim($base_path, '/') . '/');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_FTP_USE_EPSV, false);
            if ($names_only) curl_setopt($ch, CURLOPT_DIRLISTONLY, true);
            else curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'LIST');
            $raw = curl_exec($ch);
            $ok = $raw !== false && curl_errno($ch) === 0;
            curl_close($ch);
            if ($ok) {
                $listing = (string)$raw;
                if ($read_succeeded !== null) $read_succeeded = true;
                break;
            }
        }
    }
    if (!is_string($listing) || $listing === '') return [];
    $names = [];
    foreach (preg_split('/\r?\n/', $listing) as $line) {
        $line = trim(str_replace("\0", '', $line));
        if ($line === '' || $line === '.' || $line === '..') continue;
        $parts = preg_split('/\s+/', $line, 9);
        $name = count($parts) >= 9 && preg_match('/^[bcdlps-][rwxStTs-]{9}$/', $parts[0])
            ? trim($parts[8])
            : $line;
        if (str_contains($name, ' -> ')) $name = trim(explode(' -> ', $name, 2)[0]);
        $name = basename(rtrim($name, '/'));
        if ($name !== '' && preg_match('/^[A-Za-z0-9._-]{1,128}$/', $name)) $names[$name] = true;
    }
    return array_keys($names);
}

/** Descarga un archivo pequeño FTP; prefiere la extensión FTP sobre cURL embebido. */
function lb_download_ftp_file(string $host, int $port, string $remote_path, string $local_path): bool {
    if (function_exists('ftp_connect') && function_exists('ftp_fget')) {
        $ftp = @ftp_connect($host, $port, 8);
        if ($ftp) {
            @ftp_set_option($ftp, FTP_TIMEOUT_SEC, 20);
            $logged_in = @ftp_login($ftp, 'anonymous', '');
            if ($logged_in) {
                @ftp_pasv($ftp, true);
                $fp = @fopen($local_path, 'wb');
                $ok = $fp && @ftp_fget($ftp, $fp, $remote_path, FTP_BINARY);
                if ($fp) fclose($fp);
                @ftp_close($ftp);
                if ($ok && is_file($local_path) && @filesize($local_path) > 0) return true;
                @unlink($local_path);
            } else {
                @ftp_close($ftp);
            }
        }
    }

    $ch = curl_init("ftp://{$host}:{$port}" . $remote_path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_BINARYTRANSFER => true,
        CURLOPT_FTP_USE_EPSV => false,
    ]);
    $data = curl_exec($ch);
    curl_close($ch);
    if (is_string($data) && $data !== '' && @file_put_contents($local_path, $data, LOCK_EX) === strlen($data)) return true;
    @unlink($local_path);
    return false;
}
