<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/storage_helpers.php';

$capped = ['2147483647', '4294967295', '9223372036854775807', '18446744073709551615', '0002147483647'];
foreach ($capped as $size) {
    if (!sm_size_is_likely_capped($size)) {
        fwrite(STDERR, "Expected possible FTP size cap for {$size}\n");
        exit(1);
    }
}
$exact = ['2147483648', '90562428928', '0', 'not-a-size'];
foreach ($exact as $size) {
    if (sm_size_is_likely_capped($size)) {
        fwrite(STDERR, "Did not expect FTP size cap for {$size}\n");
        exit(1);
    }
}
echo "STORAGE_SIZE_SENTINELS_OK\n";
