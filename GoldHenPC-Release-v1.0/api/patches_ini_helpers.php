<?php
declare(strict_types=1);

/** Return true only when [default] has an active Game Patch loader line. */
function pa_default_has_loader(string $raw): bool
{
    $insideDefault = false;
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        if (preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $section)) {
            $insideDefault = strcasecmp(trim($section[1]), 'default') === 0;
            continue;
        }
        if (!$insideDefault || !preg_match('#^\s*/data/GoldHEN/plugins/game_patch\.prx(?:\s*=\s*(true|false))?\s*$#i', $line, $match)) continue;
        return !isset($match[1]) || strtolower($match[1]) === 'true';
    }
    return false;
}

/** Enable only the default game_patch.prx entry, preserving all other INI text. */
function pa_ini_enable(string $raw): array
{
    $eol = str_contains($raw, "\r\n") ? "\r\n" : "\n";
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $start = null;
    $end = count($lines);
    foreach ($lines as $i => $line) {
        if (!preg_match('/^\s*\[([^\]]+)\]\s*$/', $line, $match)) continue;
        if ($start !== null) { $end = $i; break; }
        if (strcasecmp(trim($match[1]), 'default') === 0) $start = $i;
    }
    if ($start !== null) {
        for ($i = $start + 1; $i < $end; $i++) {
            if (!preg_match('#^\s*/data/GoldHEN/plugins/game_patch\.prx(?:\s*=\s*(true|false))?\s*$#i', $lines[$i], $match)) continue;
            if (!isset($match[1]) || strtolower($match[1]) === 'true') return ['text' => $raw, 'already' => true];
            $lines[$i] = '/data/GoldHEN/plugins/game_patch.prx=true';
            return ['text' => implode($eol, $lines), 'already' => false];
        }
        array_splice($lines, $end, 0, ['/data/GoldHEN/plugins/game_patch.prx']);
        return ['text' => implode($eol, $lines), 'already' => false];
    }
    $prefix = $raw;
    if ($prefix !== '' && !str_ends_with($prefix, "\n") && !str_ends_with($prefix, "\r")) $prefix .= $eol;
    if (trim($prefix) !== '') $prefix .= $eol;
    return ['text' => $prefix . '[default]' . $eol . '/data/GoldHEN/plugins/game_patch.prx' . $eol, 'already' => false];
}

/** Read the global Game Patch toggle in config.ini without assuming formatting. */
function pa_global_game_patch_enabled(string $raw): bool
{
    return preg_match('/^\s*Game_Patch_Enabled\s*=\s*(?:1|true|yes)\s*$/im', $raw) === 1;
}
