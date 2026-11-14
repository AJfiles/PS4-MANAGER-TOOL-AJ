const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const api = fs.readFileSync(path.join(__dirname, '..', 'api', 'library_biblioteca.php'), 'utf8');
assert.match(api, /\$montajes_externos_activos\s*=\s*array_values\(array_filter\(/);
assert.match(api, /static fn\(string \$montaje\): bool => preg_match\('\/\^ext\[01\]\$\/i', \$montaje\) === 1/);
assert.match(api, /foreach\s*\(\$montajes_externos_activos\s+as\s+\$montaje\)/);
assert.doesNotMatch(api, /foreach\s*\(\$montajes\s+as\s+\$montaje\)/);
assert.match(api, /'installed_verified'\s*=>\s*\$lectura_apps_completada/);
console.log('LIBRARY_ONLY_ACTIVE_EXTERNAL_MOUNTS_AFFECT_VERIFICATION_OK');
