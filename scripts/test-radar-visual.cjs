const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const app = fs.readFileSync(path.join(root, 'js', 'app.js'), 'utf8');
const page = fs.readFileSync(path.join(root, 'index.php'), 'utf8');
assert.match(app, /function animarAcercamientoRadar\(galaxy\)/);
assert.match(app, /galaxy\.classList\.add\('approach'\)[\s\S]{0,250}galaxy\.classList\.add\('found'\)/);
assert.equal((app.match(/await animarAcercamientoRadar\(galaxy\)/g) || []).length, 2, 'La aproximación debe ejecutarse tanto con IP guardada como con descubrimiento LAN.');
assert.match(page, /\.radar-galaxy\.approach\s*\{/);
assert.match(page, /\.radar-galaxy\.found\s*\{[^}]*scale\(1\.2\)/);
assert.match(page, /\.radar-galaxy\.found \.radar-planet\s*\{[^}]*radar-dock/);
assert.match(page, /@keyframes radar-dock/);
assert.match(page, /prefers-reduced-motion:\s*reduce[^}]*\.radar-galaxy/);
assert.match(page, /id="radar-log-terminal"/, 'La vista debe conservar el log del radar.');
console.log('RADAR_APPROACH_LOG_REDUCED_MOTION_OK');
