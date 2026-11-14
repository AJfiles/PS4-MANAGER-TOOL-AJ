const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const api = fs.readFileSync(path.join(root, 'api', 'radar_api.php'), 'utf8');
const app = fs.readFileSync(path.join(root, 'js', 'app.js'), 'utf8');

assert.match(api, /stream_socket_client\([\s\S]*?STREAM_CLIENT_CONNECT/,
    'LAN discovery should use the stream connector already proven by manual-IP checks.');
assert.match(api, /\$stopAtFirst[\s\S]*?if \(\$stopAtFirst\) break 2;/,
    'The app radar should stop after the first responsive console instead of waiting for the subnet scan to finish.');
assert.match(api, /min\(\$connectTimeout, \$remaining\)/,
    'Each TCP attempt must remain bounded by the overall radar deadline.');
assert.match(app, /radar_api\.php\?timeout=\$\{timeoutMs\}&port=\$\{radarPort\}&first=1/,
    'The interactive radar should ask the API to return the first detected console promptly.');

console.log('RADAR_ANDROID_STREAM_DISCOVERY_FIRST_MATCH_OK');
