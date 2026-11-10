<?php
declare(strict_types=1);

/**
 * LIST sizes at these exact integer boundaries may be saturated by a 32/64 bit
 * FTP implementation. A larger size alone is not evidence of truncation.
 */
function sm_size_is_likely_capped(string $raw): bool {
    if (!ctype_digit($raw)) return false;
    $normalized = ltrim($raw, '0');
    if ($normalized === '') $normalized = '0';
    return in_array($normalized, [
        '2147483647',
        '4294967295',
        '9223372036854775807',
        '18446744073709551615',
    ], true);
}
