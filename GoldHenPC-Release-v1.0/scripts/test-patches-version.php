<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/patches_version_helpers.php';
$cases = [
    ['01.00', '1.0', 'compatible'],
    ['01.05', '01.05', 'compatible'],
    ['01.05', '01.08', 'mismatch'],
    ['01.05', 'mask', 'pattern'],
    ['', '01.05', 'unknown'],
    ['01.05', '', 'pattern'],
];
foreach ($cases as [$game, $patch, $expected]) {
    $actual = pa_version_compatibility($game, $patch);
    if ($actual !== $expected) throw new RuntimeException("$game vs $patch: expected $expected, got $actual");
}
echo "PATCH_VERSION_COMPATIBILITY_OK cases=" . count($cases) . "\n";
