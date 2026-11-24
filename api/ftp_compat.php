<?php
declare(strict_types=1);

/** FTP helpers compatible with Android WebView PHP builds where libcurl FTP
 * misreads GoldHEN's control channel as HTTP/0.9. Native ext-ftp is preferred;
 * cURL remains a fallback for runtimes without ext-ftp. */
function ghm_ftp_open(string $host, int $port, int $timeout = 12)
{
    if (!function_exists('ftp_connect')) return false;
    static $connections = [];
    static $shutdownRegistered = false;
    $key = $host . ':' . $port;
    if (isset($connections[$key]) && (is_resource($connections[$key]) || is_object($connections[$key]))) return $connections[$key];
    $ftp = @ftp_connect($host, $port, $timeout);
    if (!$ftp) return false;
    if (!@ftp_login($ftp, 'anonymous', '')) { @ftp_close($ftp); return false; }
    @ftp_set_option($ftp, FTP_TIMEOUT_SEC, max(5, $timeout));
    @ftp_pasv($ftp, true);
    $connections[$key] = $ftp;
    if (!$shutdownRegistered) { register_shutdown_function(static function () use (&$connections): void { foreach ($connections as $connection) @ftp_close($connection); }); $shutdownRegistered = true; }
    return $ftp;
}

function ghm_ftp_rawlist(string $host, int $port, string $path, int $timeout = 12): array|false
{
    $ftp = ghm_ftp_open($host, $port, $timeout);
    if ($ftp) {
        $rows = @ftp_rawlist($ftp, $path);
        if (is_array($rows)) return $rows;
    }
    if (!function_exists('curl_init')) return false;
    $url = 'ftp://'.$host.':'.$port.'/'.implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/')))).'/';
    $ch = @curl_init($url);
    if (!$ch) return false;
    @curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>$timeout, CURLOPT_FTP_RESPONSE_TIMEOUT=>max(8, $timeout), CURLOPT_FTP_USE_EPSV=>false, CURLOPT_PROXY=>'']);
    $listing = @curl_exec($ch);
    $error = curl_errno($ch);
    curl_close($ch);
    if (!is_string($listing) || $error !== 0) return false;
    $rows = preg_split('/\r\n|\n|\r/', trim($listing));
    return $rows === [''] ? [] : $rows;
}

function ghm_ftp_list(string $host, int $port, string $path, int $timeout = 12): array|false
{
    $rows = ghm_ftp_rawlist($host, $port, $path, $timeout);
    if ($rows === false) return false;
    $out = [];
    foreach ($rows as $line) {
        $parts = preg_split('/\s+/', trim((string)$line), 9);
        if (count($parts) < 9) continue;
        $name = trim($parts[8]);
        if ($name === '' || $name === '.' || $name === '..' || str_contains($name, "\0") || str_contains($name, '/') || str_contains($name, '\\')) continue;
        $out[] = ['name'=>$name, 'dir'=>($parts[0][0] ?? '') === 'd', 'size'=>(int)$parts[4], 'date'=>trim(($parts[5] ?? '').' '.($parts[6] ?? '').' '.($parts[7] ?? ''))];
    }
    return $out;
}

function ghm_ftp_get(string $host, int $port, string $path, int $timeout = 60): string|false
{
    $ftp = ghm_ftp_open($host, $port, $timeout);
    if ($ftp) {
        $stream = @fopen('php://temp', 'w+b');
        if (!$stream) return false;
        $ok = @ftp_fget($ftp, $stream, $path, FTP_BINARY, 0);
        if (!$ok) { fclose($stream); return false; }
        rewind($stream); $data = stream_get_contents($stream); fclose($stream);
        return $data === false ? false : $data;
    }
    if (!function_exists('curl_init')) return false;
    $url = 'ftp://'.$host.':'.$port.'/'.implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>$timeout, CURLOPT_FTP_RESPONSE_TIMEOUT=>30, CURLOPT_FTP_USE_EPSV=>false, CURLOPT_PROXY=>'']);
    $data = curl_exec($ch); curl_close($ch); return $data === false ? false : (string)$data;
}

function ghm_ftp_download(string $host, int $port, string $path, string $local, int $timeout = 900): bool
{
    $ftp = ghm_ftp_open($host, $port, min(60, $timeout));
    if ($ftp) { $fp = @fopen($local, 'wb'); if (!$fp) return false; $ok = @ftp_fget($ftp, $fp, $path, FTP_BINARY, 0); fclose($fp); return $ok; }
    $data = ghm_ftp_get($host, $port, $path, $timeout); return $data !== false && file_put_contents($local, $data) === strlen($data);
}

function ghm_ftp_put(string $host, int $port, string $path, string $local, bool $append = false, int $timeout = 900): bool
{
    $ftp = ghm_ftp_open($host, $port, min(60, $timeout));
    if ($ftp) { $fp = @fopen($local, 'rb'); if (!$fp) return false; $ok = $append && function_exists('ftp_append') ? @ftp_append($ftp,$path,$fp,FTP_BINARY) : (!$append && @ftp_fput($ftp,$path,$fp,FTP_BINARY)); fclose($fp); return (bool)$ok; }
    if (!function_exists('curl_init')) return false;
    $url = 'ftp://'.$host.':'.$port.implode('/', array_map('rawurlencode', explode('/', $path)));
    $fp = @fopen($local, 'rb'); if (!$fp) return false;
    $createDirs = defined('CURLFTP_CREATE_DIR_RETRY') ? constant('CURLFTP_CREATE_DIR_RETRY') : (defined('CURLFTP_CREATE_DIR') ? constant('CURLFTP_CREATE_DIR') : 0);
    $ch = curl_init($url); $opts = [CURLOPT_UPLOAD=>true, CURLOPT_INFILE=>$fp, CURLOPT_INFILESIZE=>filesize($local), CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>$timeout, CURLOPT_FTP_RESPONSE_TIMEOUT=>60, CURLOPT_FTP_USE_EPSV=>false, CURLOPT_FTP_CREATE_MISSING_DIRS=>$createDirs, CURLOPT_PROXY=>''];
    if ($append) $opts[CURLOPT_FTPAPPEND] = true;
    curl_setopt_array($ch, $opts); $ok = curl_exec($ch); curl_close($ch); fclose($fp); return $ok !== false;
}

function ghm_ftp_command(string $host, int $port, string $command, int $timeout = 15): array|false
{
    $ftp = ghm_ftp_open($host, $port, $timeout);
    if (!$ftp) return false;
    $response = @ftp_raw($ftp, $command);
    if (!is_array($response) || !$response) return false;
    $first = (string)reset($response); $code = (int)substr($first, 0, 3);
    return $code >= 200 && $code < 400 ? $response : false;
}

function ghm_ftp_quote(string $host, int $port, array $commands, int $timeout = 20): bool
{
    foreach ($commands as $command) if (!is_string($command) || preg_match('/[\r\n\0]/', $command)) return false;
    if (!function_exists('curl_init')) return false;
    $ch = @curl_init('ftp://' . $host . ':' . $port . '/');
    if (!$ch) return false;
    @curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>$timeout, CURLOPT_FTP_RESPONSE_TIMEOUT=>max(8, $timeout), CURLOPT_FTP_USE_EPSV=>false, CURLOPT_PROXY=>'', CURLOPT_QUOTE=>$commands]);
    $result = @curl_exec($ch);
    $ok = $result !== false && curl_errno($ch) === 0;
    curl_close($ch);
    return $ok;
}
function ghm_ftp_rename(string $host,int $port,string $from,string $to):bool { $f=ghm_ftp_open($host,$port,15);if($f)return @ftp_rename($f,$from,$to);return ghm_ftp_quote($host,$port,['RNFR '.$from,'RNTO '.$to]); }
function ghm_ftp_delete(string $host,int $port,string $path):bool { $f=ghm_ftp_open($host,$port,15);if($f)return @ftp_delete($f,$path);return ghm_ftp_quote($host,$port,['DELE '.$path]); }
function ghm_ftp_mkdir(string $host,int $port,string $path):string|false { $f=ghm_ftp_open($host,$port,15);if($f){$r=@ftp_mkdir($f,$path);return $r===false?false:(string)$r;}return ghm_ftp_quote($host,$port,['MKD '.$path])? $path:false; }
function ghm_ftp_rmdir(string $host,int $port,string $path):bool { $f=ghm_ftp_open($host,$port,15);if(!$f)return false;return @ftp_rmdir($f,$path); }
function ghm_ftp_exists(string $host,int $port,string $path):?bool { $rows=ghm_ftp_list($host,$port,dirname($path),10);if($rows===false)return null;foreach($rows as $row)if(strcasecmp($row['name'],basename($path))===0)return true;return false; }
