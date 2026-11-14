const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'app.js'), 'utf8');
const start = source.indexOf('function configurarEventosDashboard()');
const end = source.indexOf('\nfunction abrirModulo(', start);
assert(start >= 0 && end > start, 'No se encontró el registro de eventos de conexión.');

const events = {};
const stored = new Map([['sebas_ip_final_libre', '192.168.20.39']]);
const input = {
    value: '',
    addEventListener: (name, callback) => { events[name] = callback; }
};
const status = { innerText: '', className: '' };
const indicator = { className: '' };
const notices = [];
const elements = new Map([
    ['ps-ip-full-input', input],
    ['console-status-label', status],
    ['connection-ping-indicator', indicator]
]);
const context = {
    globalAppConfig: { ipConsola: '192.168.20.39', statusConexion: true },
    localStorage: {
        setItem: (key, value) => stored.set(key, value),
        removeItem: key => stored.delete(key)
    },
    document: {
        getElementById: id => elements.get(id) || null,
        querySelectorAll: () => [],
        addEventListener() {}
    },
    validarEstructuraIP: value => /^(?:\d{1,3}\.){3}\d{1,3}$/.test(value),
    sysNotification: (...args) => notices.push(args),
    notificacionNavegador() {},
    verificarRadarInicial() {},
    emitirEfectoSonidoNativo() {}
};
vm.runInNewContext(source.slice(start, end), context, { filename: 'js/app.js' });
context.configurarEventosDashboard();
events.change({ target: input });

assert.equal(context.globalAppConfig.ipConsola, '', 'Blank input must remove the stale fallback address.');
assert.equal(context.globalAppConfig.statusConexion, false, 'A cleared address must no longer be displayed as connected.');
assert.equal(stored.has('sebas_ip_final_libre'), false, 'Blank input must clear the persisted IP.');
assert.match(status.innerText, /IP SIN CONFIGURAR/);
assert.match(indicator.className, /amber/);
assert.match(notices[0][1], /Radar de red/);
console.log('RADAR_EMPTY_IP_CLEARS_CACHE_AND_ALLOWS_LAN_DISCOVERY_OK');
