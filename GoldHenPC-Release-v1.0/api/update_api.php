<?php
require_once __DIR__ . '/request_guard.php';
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? 'check';
$root = realpath(__DIR__ . '/..');
$versionData = json_decode((string)@file_get_contents(__DIR__ . '/../project-version.json'), true);
$currentVersion = is_array($versionData) ? (string)($versionData['version'] ?? '') : '';
$repository = is_array($versionData) ? (string)($versionData['repository'] ?? '') : '';
if (!$root || !preg_match('/^\d+(?:\.\d+){1,3}$/', $currentVersion) || $repository !== 'AJfiles/PS4-MANAGER-TOOL-AJ') {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'No se pudo leer la versión de PS4 Manager Tools.']);
    exit;
}

function update_json_response(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function update_https_get(string $url): ?string {
    $headers = "User-Agent: PS4-Manager-Tools-AJ\r\nAccept: application/vnd.github+json\r\nX-GitHub-Api-Version: 2022-11-28\r\n";
    $caBundle = __DIR__ . '/certs/cacert.pem';
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'PS4-Manager-Tools-AJ',
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'],
        ];
        if (is_file($caBundle)) $options[CURLOPT_CAINFO] = $caBundle;
        curl_setopt_array($curl, $options);
        $body = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return is_string($body) && $status >= 200 && $status < 300 ? $body : null;
    }
    $context = stream_context_create([
        'http' => ['method' => 'GET', 'timeout' => 10, 'ignore_errors' => true, 'header' => $headers],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'cafile' => is_file($caBundle) ? $caBundle : null],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $match)) $status = (int)$match[1];
    }
    return is_string($body) && $status >= 200 && $status < 300 ? $body : null;
}

function get_pc_release(string $repository, string $currentVersion): array {
    $endpoint = 'https://api.github.com/repos/' . $repository . '/releases?per_page=30';
    $raw = update_https_get($endpoint);
    // La API REST puede estar limitada por cuota. En ese caso se resuelve la
    // release pública más reciente y se valida su sidecar SHA-256 manteniendo TLS.
    if (!is_string($raw) && function_exists('curl_init')) {
        $caBundle = __DIR__ . '/certs/cacert.pem';
        $curlGet = static function (string $url, array $headers = []) use ($caBundle): array {
            $ch = curl_init($url);
            $options = [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
                CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'PS4-Manager-Tools-AJ', CURLOPT_HTTPHEADER => $headers,
            ];
            if (is_file($caBundle)) $options[CURLOPT_CAINFO] = $caBundle;
            curl_setopt_array($ch, $options);
            $body = curl_exec($ch);
            $result = ['body' => is_string($body) ? $body : '', 'status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
                'url' => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)];
            curl_close($ch);
            return $result;
        };
        $latest = $curlGet('https://github.com/' . $repository . '/releases/latest', ['Accept: text/html']);
        $latestParts = parse_url($latest['url']);
        $latestPath = (string)($latestParts['path'] ?? '');
        if ($latest['status'] >= 200 && $latest['status'] < 300
            && strtolower((string)($latestParts['host'] ?? '')) === 'github.com'
            && preg_match('~^/AJfiles/PS4-MANAGER-TOOL-AJ/releases/tag/(v?([0-9]+(?:\.[0-9]+){1,3}))$~i', $latestPath, $tagMatch)) {
            $tag = $tagMatch[1];
            $version = $tagMatch[2];
            $assetName = 'PS4-Manager-Tools-AJ-PC-v' . $version . '.zip';
            $checksumName = 'SHA256SUMS-' . $version . '.txt';
            $downloadBase = 'https://github.com/' . $repository . '/releases/download/' . rawurlencode($tag) . '/';
            $checksum = $curlGet($downloadBase . rawurlencode($checksumName), ['Accept: text/plain']);
            if ($checksum['status'] >= 200 && $checksum['status'] < 300
                && preg_match('/^([a-f0-9]{64})[ \t]+\*?' . preg_quote($assetName, '/') . '\s*$/mi', $checksum['body'], $digestMatch)) {
                $raw = json_encode([[
                    'tag_name' => $tag, 'name' => 'PS4 Manager Tools AJ ' . $version,
                    'html_url' => $latest['url'], 'assets' => [[
                        'name' => $assetName, 'browser_download_url' => $downloadBase . rawurlencode($assetName),
                    ], [
                        'name' => $checksumName, 'browser_download_url' => $downloadBase . rawurlencode($checksumName),
                    ]],
                ]], JSON_UNESCAPED_SLASHES);
            }
        }
    }
    if (!is_string($raw)) return ['status' => 'error', 'message' => 'GitHub no respondió por API ni por la página pública de Releases. Comprueba tu conexión e inténtalo de nuevo.'];
    $releases = json_decode($raw, true);
    if (!is_array($releases)) return ['status' => 'error', 'message' => 'GitHub devolvió una respuesta de versiones no válida.'];

    $candidate = null;
    foreach ($releases as $release) {
        if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) continue;
        $tag = (string)($release['tag_name'] ?? '');
        if (!preg_match('/^v?(\d+(?:\.\d+){1,3})$/i', $tag, $versionMatch)) continue;
        $version = $versionMatch[1];
        $assetName = 'PS4-Manager-Tools-AJ-PC-v' . $version . '.zip';
        $checksumName = 'SHA256SUMS-' . $version . '.txt';
        $assets = is_array($release['assets'] ?? null) ? $release['assets'] : [];
        $assetUrl = '';
        $checksumUrl = '';
        foreach ($assets as $asset) {
            if (($asset['name'] ?? '') === $assetName) $assetUrl = (string)($asset['browser_download_url'] ?? '');
            if (($asset['name'] ?? '') === $checksumName) $checksumUrl = (string)($asset['browser_download_url'] ?? '');
        }
        if (!preg_match('~^https://github\.com/' . preg_quote($repository, '~') . '/releases/download/[^/]+/' . preg_quote($assetName, '~') . '$~', $assetUrl)
            || !preg_match('~^https://github\.com/' . preg_quote($repository, '~') . '/releases/download/[^/]+/' . preg_quote($checksumName, '~') . '$~', $checksumUrl)) continue;
        $checksumText = update_https_get($checksumUrl);
        if (!is_string($checksumText) || !preg_match('/^([a-f0-9]{64})\s+\*?' . preg_quote($assetName, '/') . '\s*$/im', $checksumText, $digestMatch)) continue;
        $entry = ['version' => $version, 'tag' => $tag, 'asset_name' => $assetName, 'asset_url' => $assetUrl, 'sha256' => strtolower($digestMatch[1])];
        if ($candidate === null || version_compare($version, $candidate['version'], '>')) $candidate = $entry;
    }
    if ($candidate === null) return ['status' => 'error', 'message' => 'No hay una versión de PC con ZIP y SHA-256 válidos en Releases.'];
    $candidate['status'] = 'success';
    $candidate['current_version'] = $currentVersion;
    $candidate['update_available'] = version_compare($candidate['version'], $currentVersion, '>');
    return $candidate;
}

$gitDir = $root . '/.git';
if (!is_dir($gitDir)) {
    $release = get_pc_release($repository, $currentVersion);
    if (($release['status'] ?? '') !== 'success') update_json_response($release, 502);
    if ($action === 'check') update_json_response($release);
    if ($action === 'apply') {
        update_json_response([
            'status' => 'success',
            'download_required' => true,
            'asset_name' => $release['asset_name'],
            'asset_url' => $release['asset_url'],
            'sha256' => $release['sha256'],
            'version' => $release['version'],
            'message' => 'Descarga el ZIP, cierra el servidor y extrae su contenido sobre la carpeta de la versión actual. Conserva la carpeta user.',
        ]);
    }
    update_json_response(['status' => 'error', 'message' => 'Acción no válida.'], 400);
}

function ejecutar_git_update(string $root, string $command, ?int &$status = null): string {
    $output = [];
    $status = 1;
    @exec('cd ' . escapeshellarg($root) . ' && ' . $command . ' 2>&1', $output, $status);
    return implode("\n", $output);
}

$fetch = ejecutar_git_update($root, 'git fetch --quiet origin main', $fetchStatus);
if ($fetchStatus !== 0) update_json_response(['status' => 'error', 'message' => 'No se pudo consultar GitHub.'], 502);
$local = trim(ejecutar_git_update($root, 'git rev-parse HEAD', $localStatus));
$remote = trim(ejecutar_git_update($root, 'git rev-parse FETCH_HEAD', $remoteStatus));
if ($localStatus || $remoteStatus) update_json_response(['status' => 'error', 'message' => 'No se pudo leer la versión instalada.'], 500);

if ($action === 'check') update_json_response(['status' => 'success', 'update_available' => $local !== $remote, 'current' => substr($local, 0, 7), 'remote' => substr($remote, 0, 7)]);
if ($action === 'apply') {
    if ($local === $remote) update_json_response(['status' => 'success', 'updated' => false]);
    $changes = trim(ejecutar_git_update($root, 'git status --porcelain', $statusCode));
    if ($statusCode !== 0) update_json_response(['status' => 'error', 'message' => 'No se pudo comprobar los cambios locales.'], 500);
    $hasChanges = $changes !== '';
    if ($hasChanges) {
        ejecutar_git_update($root, 'git stash push --include-untracked -m ps4-manager-tools-autosave', $stashStatus);
        if ($stashStatus !== 0) update_json_response(['status' => 'error', 'message' => 'No se pudieron resguardar los cambios locales.'], 500);
    }
    ejecutar_git_update($root, 'git pull --ff-only origin main', $applyStatus);
    if ($applyStatus !== 0) {
        if ($hasChanges) ejecutar_git_update($root, 'git stash pop', $restoreStatus);
        update_json_response(['status' => 'error', 'message' => 'La actualización no pudo aplicarse; tus cambios locales se conservaron.'], 500);
    }
    if ($hasChanges) {
        ejecutar_git_update($root, 'git stash pop', $restoreStatus);
        if ($restoreStatus !== 0) update_json_response(['status' => 'error', 'message' => 'La aplicación se actualizó, pero Git conservó cambios locales que requieren revisión.'], 500);
    }
    update_json_response(['status' => 'success', 'updated' => true]);
}
update_json_response(['status' => 'error', 'message' => 'Acción no válida.'], 400);
