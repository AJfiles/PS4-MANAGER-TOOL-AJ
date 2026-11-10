<?php
declare(strict_types=1);

function pa_version_compatibility(string $gameVersion, string $patchVersion): string
{
    $gameVersion = trim($gameVersion);
    $patchVersion = trim($patchVersion);
    if ($patchVersion === '' || strcasecmp($patchVersion, 'mask') === 0) return 'pattern';
    if ($gameVersion === '' || !preg_match('/^\d+(?:\.\d+)*$/', $gameVersion) || !preg_match('/^\d+(?:\.\d+)*$/', $patchVersion)) return 'unknown';
    $normalize = static function (string $version): string {
        $parts = array_map(static fn($part) => ltrim($part, '0') ?: '0', explode('.', $version));
        while (count($parts) > 1 && end($parts) === '0') array_pop($parts);
        return implode('.', $parts);
    };
    return $normalize($gameVersion) === $normalize($patchVersion) ? 'compatible' : 'mismatch';
}
