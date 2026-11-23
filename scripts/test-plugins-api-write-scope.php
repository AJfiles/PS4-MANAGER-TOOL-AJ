<?php
$source = file_get_contents(__DIR__ . '/../api/plugins_api.php');
if (!is_string($source)) { fwrite(STDERR, "Cannot read plugins_api.php\n"); exit(1); }
$function = '';
if (!preg_match('/function subir_plugins_ini_verificado\(\$host, \$path, \$old, \$new\)\s*\{([\s\S]*?)\n\}/', $source, $match)) {
    fwrite(STDERR, "Explicit plugins.ini path parameter is missing\n"); exit(1);
}
$function = $match[1];
$checks = [
    str_contains($function, 'ftp_put_plugins($host,$path,$new)'),
    str_contains($function, 'ftp_get_plugins($host,$path)'),
    str_contains($function, 'ftp_put_plugins($host,$path,$old)'),
    str_contains($function, 'ftp_get_plugins($host,$path)'),
    !str_contains($function, "\$GLOBALS["),
    preg_match_all('/subir_plugins_ini_verificado\(\$host,\$ini_path,/', $source) === 3,
];
if (in_array(false, $checks, true)) { fwrite(STDERR, "plugins.ini writes must pass the target path through upload, readback and rollback\n"); exit(1); }
echo "PLUGINS_API_WRITE_PATH_SCOPE_OK\n";
