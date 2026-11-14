const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'app.js'), 'utf8');
const start = source.indexOf('function programarCierreRadarExitoso()');
const end = source.indexOf('\nasync function lanzarRadarVentanaEmergente()', start);
assert(start >= 0 && end > start, 'No se encontró la lógica del cierre por éxito del radar.');

const timers = [];
const status = {
    textContent: '',
    classList: { remove() {}, add() {} }
};
const scanStatus = { classList: { add() {}, remove() {} } };
let closed = 0;
const context = {
    radarTimeoutId: null,
    radarCountdownIntervalId: null,
    document: { getElementById: id => id === 'radar-success-countdown' ? status : scanStatus },
    setTimeout: (callback, delay) => { timers.push({ type: 'timeout', callback, delay }); return 101; },
    setInterval: (callback, delay) => { timers.push({ type: 'interval', callback, delay }); return 202; },
    clearTimeout() {},
    clearInterval() {},
    abortarYEstabilizarRadar: () => { closed++; }
};
vm.runInNewContext(source.slice(start, end) + '\nglobalThis.schedule = programarCierreRadarExitoso;', context, { filename: 'js/app.js' });
context.schedule();

assert.equal(timers.find(timer => timer.type === 'timeout')?.delay, 5000);
assert.equal(timers.find(timer => timer.type === 'interval')?.delay, 1000);
assert.match(status.textContent, /5 s/);
timers.find(timer => timer.type === 'interval').callback();
assert.match(status.textContent, /4 s/);
timers.find(timer => timer.type === 'timeout').callback();
assert.equal(closed, 1, 'On successful detection, the radar modal closes after the five-second window.');
console.log('RADAR_SUCCESS_AUTO_CLOSE_5S_OK');
