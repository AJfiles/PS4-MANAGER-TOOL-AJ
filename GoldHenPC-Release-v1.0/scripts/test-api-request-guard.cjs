const assert = require('node:assert/strict');
const fs = require('node:fs');
const { spawn } = require('node:child_process');
const net = require('node:net');
const path = require('node:path');

const php = process.argv[2];
const root = process.argv[3];
assert.ok(php && root, 'Uso: node test-api-request-guard.cjs <php.exe> <raiz>');

function freePort() {
  return new Promise((resolve, reject) => {
    const probe = net.createServer();
    probe.once('error', reject);
    probe.listen(0, '127.0.0.1', () => {
      const { port } = probe.address();
      probe.close(error => error ? reject(error) : resolve(port));
    });
  });
}

async function waitForServer(url, child) {
  const deadline = Date.now() + 8000;
  while (Date.now() < deadline) {
    if (child.exitCode !== null) throw new Error(`PHP terminó prematuramente (${child.exitCode}).`);
    try {
      const response = await fetch(url);
      if (response.status) return;
    } catch { /* PHP aún inicia el listener. */ }
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  throw new Error('Tiempo agotado esperando al servidor PHP local.');
}

(async () => {
  const port = await freePort();
  const base = `http://127.0.0.1:${port}`;
  const ini = path.join(path.dirname(php), 'php.ini');
  // The portable php.ini uses a package-relative opcache.file_cache path.
  // This fixture runs with cwd=root, so disable only OPcache for its ephemeral server.
  const args = fs.existsSync(ini) ? ['-c', ini, '-d', 'opcache.file_cache=off'] : ['-d', 'opcache.file_cache=off'];
  args.push('-S', `127.0.0.1:${port}`, '-t', path.resolve(root));
  const child = spawn(php, args, {
    cwd: path.resolve(root), windowsHide: true, stdio: 'ignore'
  });

  try {
    await waitForServer(`${base}/index.php`, child);
    const endpoint = `${base}/api/ps4_screenshots_api.php`;
    const crossOrigin = await fetch(endpoint, {
      method: 'POST', headers: { Origin: 'https://attacker.example' },
      body: new URLSearchParams({ action: 'get_all_caps' })
    });
    assert.equal(crossOrigin.status, 403, 'El POST cruzado debe bloquearse antes de consultar FTP.');
    const blocked = await crossOrigin.json();
    assert.equal(blocked.status, 'error');
    assert.match(blocked.message, /origen local/i);

    const sameOrigin = await fetch(endpoint, {
      method: 'POST', headers: { Origin: base },
      body: new URLSearchParams({ action: 'get_all_caps' })
    });
    assert.equal(sameOrigin.status, 200, 'El POST local debe llegar a la validación normal del endpoint.');
    const allowed = await sameOrigin.json();
    assert.equal(allowed.message, 'Faltan datos de consola.');
    process.stdout.write('API_ORIGIN_GUARD_OK\n');
  } finally {
    child.kill();
  }
})().catch(error => {
  process.stderr.write(`${error.stack || error}\n`);
  process.exitCode = 1;
});
