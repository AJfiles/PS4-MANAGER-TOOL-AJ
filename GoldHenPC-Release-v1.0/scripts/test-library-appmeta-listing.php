<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/library_listing_helpers.php';

$base = '/user/appmeta';
$fixtures = [
    ["drwxr-xr-x 2 root root 4096 Jan 01 12:00 CUSA00001\n" .
     "drwxr-xr-x 2 root root 4096 Jan 01 12:00 CUSA11461\n", ['CUSA00001', 'CUSA11461']],
    ["CUSA00001\r\nCUSA11461/\r\nNPXS29005\r\n", ['CUSA00001', 'CUSA11461', 'NPXS29005']],
    ["lrwxrwxrwx 1 root root 12 Jan 01 12:00 CUSA99999 -> CUSA00001\n", ['CUSA99999']],
    ["total 12\nnot-a-title-id\n", []],
];

foreach ($fixtures as [$listing, $expected]) {
    $actual = lb_parse_appmeta_listing($listing, $base);
    $actualIds = array_keys($actual);
    sort($actualIds);
    sort($expected);
    if ($actualIds !== $expected) {
        throw new RuntimeException('FTP appmeta parser mismatch: ' . json_encode($actualIds));
    }
    foreach ($actual as $path) {
        if ($path !== $base) throw new RuntimeException('Wrong source directory mapping');
    }
}
echo 'LIBRARY_APPMETA_LISTING_OK fixtures=' . count($fixtures) . "\n";
