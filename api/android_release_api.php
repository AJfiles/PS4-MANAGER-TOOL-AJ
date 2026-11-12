<?php
require_once __DIR__ . '/request_guard.php';
header('Content-Type: application/json; charset=utf-8');

$versionFile = __DIR__ . '/../project-version.json';
$versionData = json_decode((string)@file_get_contents($versionFile), true);
$currentVersion = is_array($versionData) ? (string)($versionData['version'] ?? '') : '';
$repository = is_array($versionData) ? (string)($versionData['repository'] ?? '') : '';
if (!preg_match('/^\d+(?:\.\d+){1,3}$/', $currentVersion)
    || $repository !== 'AJfiles/PS4-MANAGER-TOOL-AJ') {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Falta una versión o repositorio válido en project-version.json.']);
    exit;
}

$endpoint = 'https://api.github.com/repos/' . $repository . '/releases?per_page=30';
$response = false;
$transportDetail = '';
$caBundle = __DIR__ . '/certs/cacert.pem';
// cURL suele responder mucho antes que el wrapper HTTPS en PHP Android, que
// puede agotar su timeout aun cuando cURL funciona en ese mismo runtime.
// Mantén la verificación TLS y usa el wrapper como fallback si cURL no existe
// o falla por una causa recuperable.
if (function_exists('curl_init')) {
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 9,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CAINFO => is_file($caBundle) ? $caBundle : null,
        CURLOPT_USERAGENT => 'PS4-Manager-Tools-AJ',
        CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    if (is_string($body) && $status >= 200 && $status < 300) $response = $body;
    else $transportDetail = trim('cURL HTTP ' . $status . ' ' . $curlError);
}
if (!is_string($response) || $response === '') {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 8,
            'ignore_errors' => true,
            'header' => "User-Agent: PS4-Manager-Tools-AJ\r\nAccept: application/vnd.github+json\r\nX-GitHub-Api-Version: 2022-11-28\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $streamResponse = @file_get_contents($endpoint, false, $context);
    $streamStatus = 0;
    foreach (($http_response_header ?? []) as $headerLine) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $headerLine, $statusMatch)) $streamStatus = (int)$statusMatch[1];
    }
    if ($streamStatus >= 400) {
        $transportDetail = 'GitHub REST HTTP ' . $streamStatus;
    } elseif (is_string($streamResponse) && $streamResponse !== '') {
        $response = $streamResponse;
    } else {
        $lastError = error_get_last();
        if (is_array($lastError) && isset($lastError['message'])) $transportDetail = (string)$lastError['message'];
    }
}
// GitHub limits unauthenticated REST API requests. If that quota is exhausted,
// resolve the public latest-release page and its checksum sidecar instead.
if ((!is_string($response) || $response === '') && function_exists('curl_init')) {
    $caBundle = __DIR__ . '/certs/cacert.pem';
    $curlGet = static function (string $url, array $headers = []) use ($caBundle): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO => is_file($caBundle) ? $caBundle : null,
            CURLOPT_USERAGENT => 'PS4-Manager-Tools-AJ',
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $result = [
            'body' => is_string($body) ? $body : '',
            'status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'url' => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
            'error' => (string)curl_error($ch),
        ];
        curl_close($ch);
        return $result;
    };
    $latest = $curlGet('https://github.com/' . $repository . '/releases/latest', ['Accept: text/html']);
    $latestParts = parse_url($latest['url']);
    $latestPath = (string)($latestParts['path'] ?? '');
    if ($latest['status'] >= 200 && $latest['status'] < 300
        && strtolower((string)($latestParts['host'] ?? '')) === 'github.com'
        && preg_match('~^/AJfiles/PS4-MANAGER-TOOL-AJ/releases/tag/(v?(\d+(?:\.\d+){1,3}))$~i', $latestPath, $tagMatch)) {
        $tag = $tagMatch[1];
        $version = $tagMatch[2];
        $assetName = 'PS4-Manager-Tools-AJ-v' . $version . '.apk';
        $checksumName = 'SHA256SUMS-' . $version . '.txt';
        $downloadBase = 'https://github.com/' . $repository . '/releases/download/' . rawurlencode($tag) . '/';
        $checksum = $curlGet($downloadBase . rawurlencode($checksumName), ['Accept: text/plain']);
        if ($checksum['status'] >= 200 && $checksum['status'] < 300
            && preg_match('/^([a-f0-9]{64})[ \t]+\*?' . preg_quote($assetName, '/') . '\s*$/mi', $checksum['body'], $digestMatch)) {
            $response = json_encode([[
                'tag_name' => $tag,
                'name' => 'PS4 Manager Tools AJ ' . $version,
                'html_url' => $latest['url'],
                'assets' => [[
                    'name' => $assetName,
                    'browser_download_url' => $downloadBase . rawurlencode($assetName),
                    'size' => 0,
                    'digest' => 'sha256:' . strtolower($digestMatch[1]),
                ]],
            ]], JSON_UNESCAPED_SLASHES);
        } else {
            $transportDetail .= '; latest-release checksum sidecar unavailable or invalid';
        }
    } else {
        $transportDetail .= '; latest-release page did not resolve to a supported version tag';
        if ($latest['error'] !== '') $transportDetail .= ': ' . $latest['error'];
    }
}
if (!is_string($response) || $response === '') {
    http_response_code(502);
    $message = 'GitHub no respondió. Comprueba la conexión a Internet e inténtalo de nuevo.';
    if ($transportDetail !== '') $message .= ' Detalle: ' . substr(preg_replace('/[\r\n\x00-\x1f]/', ' ', $transportDetail), 0, 220);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}
$releases = json_decode($response, true);
if (!is_array($releases)) {
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'GitHub devolvió una respuesta de Releases inválida.']);
    exit;
}

$candidate = null;
foreach ($releases as $release) {
    if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) continue;
    $tag = (string)($release['tag_name'] ?? '');
    if (!preg_match('/^v?(\d+(?:\.\d+){1,3})$/i', $tag, $versionMatch)) continue;
    $version = $versionMatch[1];
    $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];
    foreach ($assets as $asset) {
        if (!is_array($asset) || !preg_match('/\.apk$/i', (string)($asset['name'] ?? ''))) continue;
        $url = (string)($asset['browser_download_url'] ?? '');
        $urlParts = parse_url($url);
        $expectedPrefix = '/AJfiles/PS4-MANAGER-TOOL-AJ/releases/download/';
        if (!is_array($urlParts) || strtolower((string)($urlParts['scheme'] ?? '')) !== 'https'
            || strtolower((string)($urlParts['host'] ?? '')) !== 'github.com'
            || !str_starts_with((string)($urlParts['path'] ?? ''), $expectedPrefix)) continue;

        if ($candidate === null || version_compare($version, $candidate['version'], '>')) {
            $digest = (string)($asset['digest'] ?? '');
            $sha256 = preg_match('/^sha256:([a-f0-9]{64})$/i', $digest, $digestMatch)
                ? strtolower($digestMatch[1]) : '';
            $releaseUrl = (string)($release['html_url'] ?? '');
            $releaseParts = parse_url($releaseUrl);
            if (!is_array($releaseParts) || strtolower((string)($releaseParts['host'] ?? '')) !== 'github.com'
                || !str_starts_with((string)($releaseParts['path'] ?? ''), '/AJfiles/PS4-MANAGER-TOOL-AJ/releases/tag/')) {
                $releaseUrl = '';
            }
            $candidate = [
                'version' => $version,
                'name' => (string)($release['name'] ?? ('PS4 Manager Tools AJ ' . $version)),
                'release_url' => $releaseUrl,
                'asset_name' => (string)$asset['name'],
                'asset_url' => $url,
                'asset_size' => (int)($asset['size'] ?? 0),
                'sha256' => $sha256,
            ];
        }
    }
}

if ($candidate === null) {
    echo json_encode([
        'status' => 'success',
        'current_version' => $currentVersion,
        'update_available' => false,
        'apk_available' => false,
        'message' => 'No hay una APK de PS4 Manager Tools AJ publicada en GitHub Releases todavía.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$isNewer = version_compare($candidate['version'], $currentVersion, '>');
echo json_encode([
    'status' => 'success',
    'current_version' => $currentVersion,
    'latest_version' => $candidate['version'],
    'update_available' => $isNewer,
    'apk_available' => true,
    'release' => $candidate,
    'message' => $isNewer ? 'Hay una nueva versión Android.' : 'La APK de GitHub no es más reciente que esta versión.',
], JSON_UNESCAPED_SLASHES);
