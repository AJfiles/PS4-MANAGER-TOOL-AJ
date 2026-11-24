const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('js/preload.js', 'utf8');
const calls = [];
const elements = new Map();
const element = id => {
    if (!elements.has(id)) elements.set(id, {
        disabled: false, innerHTML: '', textContent: '', style: {}, attrs: {},
        classList: { remove() {}, add() {} },
        setAttribute(key, value) { this.attrs[key] = value; }
    });
    return elements.get(id);
};
const localStorage = { getItem: key => key === 'sebas_ip_final_libre' ? '192.168.20.39' : null };
const window = {
    ps5Notification(...args) { calls.push(['notify', ...args]); },
    bibliotecaPrecargar: async callback => { calls.push('Biblioteca'); callback(1, 'Biblioteca: 8/8 títulos'); },
    savesRecargar: async () => calls.push('Partidas'),
    limpiezaCargar: async () => calls.push('Limpieza'),
    almacenamientoCargar: async () => calls.push('Almacenamiento'),
    capturasCargar: async () => calls.push('Capturas'),
    parchesRecargar: async () => calls.push('Parches de juego'),
    pluginsRecargar: async () => calls.push('Plugins'),
    cargarRutaFtp: async () => calls.push('Explorador FTP')
};
const sandbox = { window, localStorage, console, document: { getElementById: element }, Promise, Number, Math, String };
vm.runInNewContext(source, sandbox, { filename: 'preload.js' });

(async () => {
    await window.ghmIniciarPrecarga();
    assert.deepEqual(calls.filter(item => typeof item === 'string'), [
        'Biblioteca', 'Partidas', 'Limpieza', 'Almacenamiento', 'Capturas',
        'Parches de juego', 'Plugins', 'Explorador FTP'
    ]);
    assert.equal(element('launcher-preload-progress').style.width, '100%');
    assert.equal(element('launcher-preload-progress-wrap').attrs['aria-valuenow'], '100');
    assert.equal(element('launcher-preload-button').disabled, false);
    assert.match(element('launcher-preload-status').textContent, /Se solicitaron las lecturas/);
    console.log('PRELOAD_READONLY_SEQUENCE_AND_PROGRESS_OK');
})().catch(error => { console.error(error); process.exitCode = 1; });
