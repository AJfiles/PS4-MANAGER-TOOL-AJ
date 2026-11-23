const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

const root = path.resolve(process.argv[2] || process.cwd());
const apiPath = path.join(root, 'api', 'fw_payload_api.php');
const api = fs.readFileSync(apiPath, 'utf8');
const expected = Object.fromEntries(
  [...api.matchAll(/'([^']+\.bin)'\s*=>\s*'([a-f0-9]{64})'/gi)].map(([, name, hash]) => [name, hash.toLowerCase()])
);

const pcCandidates = [
  // A PC source/package can be audited directly; its root is the PC mirror.
  root,
  path.join(root, 'GoldHenPC-Release-v1.0'),
  ...fs.readdirSync(root, { withFileTypes: true })
    .filter(entry => entry.isDirectory() && /^PS4-Manager-Tools-AJ-PC-v/.test(entry.name))
    .map(entry => path.join(root, entry.name)),
];
const pcRoot = pcCandidates.find(candidate => fs.existsSync(path.join(candidate, 'UniversalPayloadsFW_FAN')));
const roots = [
  { path: path.join(root, 'UniversalPayloadsFW_FAN'), required: true },
  { path: path.join(root, 'payloads fw'), required: false },
  { path: pcRoot ? path.join(pcRoot, 'UniversalPayloadsFW_FAN') : '', required: true },
  { path: pcRoot ? path.join(pcRoot, 'payloads fw') : '', required: false },
];
const missing = [];
const mismatched = [];
for (const base of roots.filter(item => item.required && (!item.path || !fs.existsSync(item.path)))) {
  missing.push(base.path || 'PC package UniversalPayloadsFW_FAN');
}
for (const [name, hash] of Object.entries(expected)) {
  const category = name.startsWith('fan_') ? 'FAN' : 'UPDATES';
  for (const { path: base } of roots.filter(item => item.path && fs.existsSync(item.path))) {
    const file = path.join(base, category, name);
    if (!fs.existsSync(file)) {
      missing.push(file);
      continue;
    }
    const actual = crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
    if (actual !== hash) mismatched.push(`${file}: ${actual}`);
  }
}

const payloadRootOrder = api.indexOf("../UniversalPayloadsFW_FAN") < api.indexOf("../payloads fw");
const fan = fs.readFileSync(path.join(root, 'js', 'coolers.js'), 'utf8');
const updates = fs.readFileSync(path.join(root, 'js', 'actualizaciones_fw.js'), 'utf8');
const confirmed = source => [...source.matchAll(/\{ firmware: '([^']+)', status: 'confirmed'/g)].map(([, fw]) => fw);
const expectedFan = ['5.05', '9.00'];
const expectedUpdates = ['5.05', '6.72', '7.02', '7.55', '9.00', '11.00'];
const exact = (actual, wanted) => actual.length === wanted.length && actual.every((item, index) => item === wanted[index]);

if (missing.length || mismatched.length || !payloadRootOrder || !exact(confirmed(fan), expectedFan) || !exact(confirmed(updates), expectedUpdates)) {
  console.error(JSON.stringify({ missing, mismatched, payloadRootOrder, fanConfirmed: confirmed(fan), updateConfirmed: confirmed(updates) }, null, 2));
  process.exit(1);
}
console.log(`FW_PAYLOAD_CATALOGUE_OK ${Object.keys(expected).length} payloads; hashes and source/PC copies match; confirmed labels match policy`);
