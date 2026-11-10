<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/save_paths.php';

$validFtp = [
    '/user/home/18a95ddf/savedata/CUSA00419/data0.bin',
    '/user/home/18a95ddf/savedata/SLUS21004/subdir/save.dat',
    '/user/home/18a95ddf/savedata_meta/user/CUSA00419/icon0.png',
    '/user/home/18a95ddf/savedata_meta/user/SLUS21004/subdir/metadata.sfo',
];
$invalidFtp = [
    '/user/home/18a95ddf/savedata/CUSA00419',
    '/user/home/18a95ddf/savedata/CUSA00419/../outside.bin',
    '/user/home/18a95ddf/savedata/CUSA00419/./file.bin',
    '/user/home/18a95ddf/savedata/CUSA00419/name/with?query',
    '/user/home/18a95ddf/savedata/SLUSA1004/file.bin',
    '/user/home/18a95ddf/app/CUSA00419/file.bin',
    '/user/home/not-a-profile/savedata/CUSA00419/file.bin',
    '/user/home/18a95ddf/savedata/CUSA00419\\file.bin',
];
$validArchive = [
    ['savedata/CUSA00419/data0.bin', 'CUSA00419'],
    ['savedata_meta/SLUS21004/subdir/metadata.sfo', 'SLUS21004'],
];
$invalidArchive = [
    ['savedata/CUSA00419', 'CUSA00419'],
    ['savedata/CUSA00419/../outside.bin', 'CUSA00419'],
    ['savedata/CUSA00419/./file.bin', 'CUSA00419'],
    ['savedata/SLUS21004/file.bin', 'CUSA00419'],
    ['/savedata/CUSA00419/file.bin', 'CUSA00419'],
    ['savedata/CUSA00419\\file.bin', 'CUSA00419'],
];

foreach ($validFtp as $path) {
    if (!sm_is_safe_savedata_ftp_path($path)) {
        fwrite(STDERR, "Rejected valid FTP save path: {$path}\n");
        exit(1);
    }
}
foreach ($invalidFtp as $path) {
    if (sm_is_safe_savedata_ftp_path($path)) {
        fwrite(STDERR, "Accepted invalid FTP save path: {$path}\n");
        exit(1);
    }
}
foreach ($validArchive as [$path, $titleId]) {
    if (!sm_is_safe_savedata_archive_path($path, $titleId)) {
        fwrite(STDERR, "Rejected valid archive path: {$path}\n");
        exit(1);
    }
}
foreach ($invalidArchive as [$path, $titleId]) {
    if (sm_is_safe_savedata_archive_path($path, $titleId)) {
        fwrite(STDERR, "Accepted invalid archive path: {$path}\n");
        exit(1);
    }
}

if (!sm_is_explicit_permanent_save_delete('delete_permanent', '1')
    || !sm_is_explicit_permanent_save_delete('cleanup_delete', '1')
    || sm_is_explicit_permanent_save_delete('delete_permanent', '0')
    || sm_is_explicit_permanent_save_delete('delete', '1')) {
    fwrite(STDERR, "Permanent save delete gate accepted a missing/invalid opt-in.\n");
    exit(1);
}

echo "SAVE_PATH_GUARD_OK ftp=" . count($validFtp) . '/' . count($invalidFtp) . ' archive=' . count($validArchive) . '/' . count($invalidArchive) . " permanent-delete-gate=ok\n";
