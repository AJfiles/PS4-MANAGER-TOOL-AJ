<?php
$source = (string)file_get_contents(__DIR__ . '/../api/android_release_api.php');
$curlStart = strpos($source, "if (function_exists('curl_init')) {");
$streamStart = strpos($source, '$context = stream_context_create([');
$firstCurlBlock = $curlStart === false || $streamStart === false
    ? '' : substr($source, $curlStart, $streamStart - $curlStart);
$checks = [
    $curlStart !== false && $streamStart !== false && $curlStart < $streamStart,
    str_contains($firstCurlBlock, 'CURLOPT_CONNECTTIMEOUT => 4'),
    str_contains($firstCurlBlock, 'CURLOPT_TIMEOUT => 9'),
    str_contains($firstCurlBlock, 'CURLOPT_SSL_VERIFYPEER => true'),
    str_contains($firstCurlBlock, 'CURLOPT_SSL_VERIFYHOST => 2'),
    str_contains($source, "'timeout' => 8"),
];
if (in_array(false, $checks, true)) {
    fwrite(STDERR, "ANDROID_RELEASE_TRANSPORT_FASTPATH_FAILED\n");
    exit(1);
}
echo "ANDROID_RELEASE_TRANSPORT_FASTPATH_OK\n";
