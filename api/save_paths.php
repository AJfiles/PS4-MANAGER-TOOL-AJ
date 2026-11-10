<?php
declare(strict_types=1);

/** Title IDs accepted by the PS4 savedata and savedata_meta trees. */
function sm_is_valid_save_title_id(string $titleId): bool
{
    return preg_match('/^[A-Z]{4}[0-9]{5}$/iD', $titleId) === 1;
}

/** A permanent delete must be an explicit API action and carry its opt-in flag. */
function sm_is_explicit_permanent_save_delete(string $action, string $flag): bool
{
    return in_array($action, ['delete_permanent', 'cleanup_delete'], true) && $flag === '1';
}

/**
 * Allow deleting only files below a known profile's savedata roots.
 * Reject traversal segments and paths pointing at a whole save directory.
 */
function sm_is_safe_savedata_ftp_path(string $path): bool
{
    if (str_contains($path, "\0") || str_contains($path, '\\')) return false;
    $parts = explode('/', $path);
    if (($parts[0] ?? null) !== '' || ($parts[1] ?? null) !== 'user' || ($parts[2] ?? null) !== 'home') return false;
    if (!preg_match('/^[a-f0-9]{8,16}$/iD', (string)($parts[3] ?? ''))) return false;

    if (($parts[4] ?? null) === 'savedata') {
        $titleIndex = 5;
    } elseif (($parts[4] ?? null) === 'savedata_meta' && ($parts[5] ?? null) === 'user') {
        $titleIndex = 6;
    } else {
        return false;
    }

    if (!sm_is_valid_save_title_id((string)($parts[$titleIndex] ?? ''))) return false;
    $tail = array_slice($parts, $titleIndex + 1);
    if (!$tail) return false;
    foreach ($tail as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/^[A-Za-z0-9._ -]+$/D', $segment) !== 1) return false;
    }
    return true;
}

/** Validate a relative file path stored in a Manager save archive manifest. */
function sm_is_safe_savedata_archive_path(string $path, string $expectedTitleId): bool
{
    if (!sm_is_valid_save_title_id($expectedTitleId) || str_contains($path, "\0") || str_contains($path, '\\')) return false;
    $parts = explode('/', $path);
    if (($parts[0] ?? null) === 'savedata') {
        $titleIndex = 1;
    } elseif (($parts[0] ?? null) === 'savedata_meta') {
        $titleIndex = 1;
    } else {
        return false;
    }
    if (($parts[$titleIndex] ?? null) !== $expectedTitleId || count($parts) <= $titleIndex + 1) return false;
    foreach (array_slice($parts, $titleIndex + 1) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || preg_match('/^[A-Za-z0-9._ -]+$/D', $segment) !== 1) return false;
    }
    return true;
}
