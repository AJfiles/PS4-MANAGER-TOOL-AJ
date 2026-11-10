<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/patches_ini_helpers.php';

$raw = "; mantener comentario\r\n[default]\r\n/data/GoldHEN/plugins/game_patch.prx=false\r\n/data/GoldHEN/plugins/other.prx\r\n\r\n[CUSA00419]\r\n/data/GoldHEN/plugins/game_patch.prx=false\r\n";
if (pa_default_has_loader($raw)) {
    fwrite(STDERR, "Disabled default plugin was incorrectly reported active.\n");
    exit(1);
}
$enabled = pa_ini_enable($raw);
$expected = "; mantener comentario\r\n[default]\r\n/data/GoldHEN/plugins/game_patch.prx=true\r\n/data/GoldHEN/plugins/other.prx\r\n\r\n[CUSA00419]\r\n/data/GoldHEN/plugins/game_patch.prx=false\r\n";
if ($enabled['already'] || $enabled['text'] !== $expected || !pa_default_has_loader($enabled['text'])) {
    fwrite(STDERR, "Enabling the loader did not change only the default entry.\n");
    exit(1);
}
$already = pa_ini_enable($enabled['text']);
if (!$already['already'] || $already['text'] !== $enabled['text']) {
    fwrite(STDERR, "An already enabled default plugin was not recognized.\n");
    exit(1);
}
$perGameOnly = "[CUSA00419]\n/data/GoldHEN/plugins/game_patch.prx\n";
if (pa_default_has_loader($perGameOnly) || !str_contains(pa_ini_enable($perGameOnly)['text'], "[default]\n/data/GoldHEN/plugins/game_patch.prx\n")) {
    fwrite(STDERR, "A per-game entry was mistaken for a default entry.\n");
    exit(1);
}
if (!pa_global_game_patch_enabled("Game_Patch_Enabled = yes\n") || pa_global_game_patch_enabled("Game_Patch_Enabled = false\n")) {
    fwrite(STDERR, "Global Game Patch setting parsing failed.\n");
    exit(1);
}

echo "PATCHES_INI_STATE_OK\n";
