<?php
/** Same-origin guard for HTTP POST requests handled by the Manager APIs. */
function ps4_manager_host_in_cidr(string $host, string $network, int $prefix): bool {
    $ip = @inet_pton(trim($host, '[]'));
    $net = @inet_pton($network);
    if ($ip === false || $net === false || strlen($ip) !== strlen($net)) return false;
    $fullBytes = intdiv($prefix, 8);
    $remainingBits = $prefix % 8;
    if (substr($ip, 0, $fullBytes) !== substr($net, 0, $fullBytes)) return false;
    if ($remainingBits === 0) return true;
    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($ip[$fullBytes]) & $mask) === (ord($net[$fullBytes]) & $mask);
}

function ps4_manager_is_local_host(string $host): bool {
    $host = trim($host, '[]');
    if (strcasecmp($host, 'localhost') === 0) return true;
    foreach ([
        ['127.0.0.0', 8], ['10.0.0.0', 8], ['172.16.0.0', 12],
        ['192.168.0.0', 16], ['169.254.0.0', 16], ['::1', 128],
        ['fc00::', 7], ['fe80::', 10]
    ] as [$network, $prefix]) {
        if (ps4_manager_host_in_cidr($host, $network, $prefix)) return true;
    }
    return false;
}

if (PHP_SAPI !== 'cli' && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    $origin = parse_url((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $hostHeader = (string)(parse_url('http://' . (string)($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST) ?: '');
    $originHost = is_array($origin) ? (string)($origin['host'] ?? '') : '';
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $originScheme = is_array($origin) ? strtolower((string)($origin['scheme'] ?? '')) : '';
    $serverPort = (int)($_SERVER['SERVER_PORT'] ?? ($scheme === 'https' ? 443 : 80));
    $originPort = is_array($origin) ? (int)($origin['port'] ?? ($originScheme === 'https' ? 443 : 80)) : 0;
    $fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    $sameOrigin = $originHost !== ''
        && strcasecmp(trim($originHost, '[]'), trim($hostHeader, '[]')) === 0
        && $originScheme === $scheme
        && $originPort === $serverPort
        && ($fetchSite === '' || $fetchSite === 'same-origin')
        && ps4_manager_is_local_host($hostHeader);
    if (!$sameOrigin) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Solicitud bloqueada: las operaciones del Manager solo aceptan POST desde su origen local.']);
        exit;
    }
}
