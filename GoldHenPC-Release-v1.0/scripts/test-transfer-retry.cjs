const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(process.argv[2] || process.cwd());
const controller = fs.readFileSync(path.join(root, 'js', 'transferir.js'), 'utf8');
const page = fs.readFileSync(path.join(root, 'modulos', 'transferir.php'), 'utf8');
const retryStart = controller.indexOf('function reintentarTransferenciaFTP()');
const retryEnd = controller.indexOf('function abortarTransferenciaActiva()', retryStart);
const retryFunction = retryStart >= 0 && retryEnd > retryStart ? controller.slice(retryStart, retryEnd) : '';

const checks = {
  button: /id="btn-reintentar-transferencia"[^>]*onclick="reintentarTransferenciaFTP\(\)"/.test(page),
  blocksConcurrentRetry: retryFunction.includes('!archivoActualBlob || isTransferring'),
  hidesButtonUntilNextFailure: retryFunction.includes("retry?.classList.add('hidden')"),
  restartsFromByteZero: retryFunction.includes('ejecutarSubidaUnica();') && retryFunction.includes('desde el byte cero'),
  failedHttpShowsRetry: controller.includes("if (xhr.status === 200)") && controller.includes("retry.classList.remove('hidden')"),
  defaultGoldHenPath: page.includes('value="/data/pkg/"')
    && controller.includes("|| '/data/pkg/'")
    && fs.readFileSync(path.join(root, 'api', 'transferir_api.php'), 'utf8').includes("?? '/data/pkg/'"),
};

const failed = Object.entries(checks).filter(([, passed]) => !passed).map(([name]) => name);
if (failed.length) {
  console.error(`FTP retry regression: ${failed.join(', ')}`);
  process.exit(1);
}
console.log('FTP_MANUAL_RETRY_FLOW_OK');
