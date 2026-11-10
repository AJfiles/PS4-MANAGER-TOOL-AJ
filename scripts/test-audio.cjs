const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('js/app.js', 'utf8');
const start = source.indexOf('function inicializarContextoAudio()');
const end = source.indexOf('function cambiarVolumenSFX', start);
assert.ok(start >= 0 && end > start, 'audio engine functions must exist');

function createHarness(initialState = 'running') {
    const state = { oscillators: 0, resumes: 0, text: '' };
    class FakeAudioContext {
        constructor() { this.state = initialState; this.currentTime = 1; this.destination = {}; }
        resume() { state.resumes++; this.state = 'running'; return Promise.resolve(); }
        createOscillator() {
            state.oscillators++;
            return { frequency: { setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() {}, start() {}, stop() {} };
        }
        createGain() { return { gain: { setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() {} }; }
    }
    const sandbox = {
        Date, Promise, console: { warn() {} },
        window: { AudioContext: FakeAudioContext },
        document: {
            addEventListener() {},
            getElementById() { return { set textContent(value) { state.text = value; } }; },
        },
        globalAppConfig: { sonidosActivos: true, volumenSfx: 0.5, estiloSfx: 'soft' },
        globalAudioCtx: null, globalAudioUnlock: Promise.resolve(true), ultimoEfectoAudioAt: 0,
    };
    vm.runInNewContext(`${source.slice(start, end)}\nglobalThis.audioApi = { emitirEfectoSonidoNativo, probarSonidoSeleccionado };`, sandbox);
    return { api: sandbox.audioApi, config: sandbox.globalAppConfig, state };
}

(async () => {
    const running = createHarness();
    assert.equal(running.api.emitirEfectoSonidoNativo('click'), true);
    assert.equal(running.state.oscillators, 1, 'running context emits immediately');

    const suspended = createHarness('suspended');
    assert.equal(await suspended.api.emitirEfectoSonidoNativo('ps-ui', true), true);
    assert.equal(suspended.state.resumes, 1, 'suspended context is resumed');
    assert.equal(suspended.state.oscillators, 1, 'tone is scheduled after unlock');

    const disabled = createHarness();
    disabled.config.sonidosActivos = false;
    assert.equal(disabled.api.emitirEfectoSonidoNativo('click'), false);
    assert.equal(disabled.state.oscillators, 0, 'disabled SFX stays silent');

    const status = createHarness();
    status.api.probarSonidoSeleccionado();
    await Promise.resolve();
    await Promise.resolve();
    assert.match(status.state.text, /Prueba reproducida/);
    console.log('AUDIO_SFX_OK');
})().catch(error => { console.error(error); process.exitCode = 1; });
