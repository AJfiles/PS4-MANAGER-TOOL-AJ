const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('js/app.js', 'utf8');
const settings = fs.readFileSync('modulos/ajustes.php', 'utf8');
const start = source.indexOf('function inicializarContextoAudio()');
const end = source.indexOf('function cambiarVolumenSFX', start);
assert.ok(start >= 0 && end > start, 'audio engine functions must exist');

function createHarness(initialState = 'running') {
    const state = { oscillators: 0, resumes: 0, text: '', peak: 0, stopAt: 0, wave: '', startFrequency: 0, endFrequency: 0, events: {} };
    class FakeAudioContext {
        constructor() { this.state = initialState; this.currentTime = 1; this.destination = {}; }
        resume() { state.resumes++; this.state = 'running'; return Promise.resolve(); }
        createOscillator() {
            state.oscillators++;
            const oscillator = { frequency: { setValueAtTime(value) { state.startFrequency = value; }, exponentialRampToValueAtTime(value) { state.endFrequency = value; } }, connect() {}, start() {}, stop(at) { state.stopAt = at; } };
            Object.defineProperty(oscillator, 'type', { set(value) { state.wave = value; } });
            return oscillator;
        }
        createGain() { return { gain: { setValueAtTime(value) { state.peak = value; }, exponentialRampToValueAtTime() {} }, connect() {} }; }
    }
    const sandbox = {
        Date, Promise, console: { warn() {} },
        window: { AudioContext: FakeAudioContext },
        document: {
            addEventListener(name, callback) { (state.events[name] ||= []).push(callback); },
            getElementById() { return { set textContent(value) { state.text = value; } }; },
        },
        globalAppConfig: { sonidosActivos: true, volumenSfx: 0.5, estiloSfx: 'soft' },
        localStorage: { setItem() {} },
        globalAudioCtx: null, globalAudioUnlock: Promise.resolve(true), ultimoEfectoAudioAt: 0,
    };
    vm.runInNewContext(`${source.slice(start, end)}\nglobalThis.audioApi = { emitirEfectoSonidoNativo, probarSonidoSeleccionado, cambiarEstiloSonido };`, sandbox);
    return { api: sandbox.audioApi, config: sandbox.globalAppConfig, state };
}

(async () => {
    const running = createHarness();
    assert.equal(running.api.emitirEfectoSonidoNativo('click'), true);
    assert.equal(running.state.oscillators, 1, 'running context emits immediately');
    assert.ok(running.state.peak >= 0.39, 'navigation click sound is audible at 50% SFX');
    assert.ok(running.state.stopAt - 1 >= 0.1, 'navigation click has a perceptible duration');

    for (const [style, wave, firstFrequency] of [['synthwave', 'sawtooth', 330], ['crystal', 'sine', 880], ['pulse', 'triangle', 180]]) {
        const tones = createHarness();
        tones.api.cambiarEstiloSonido(style);
        assert.equal(tones.config.estiloSfx, style, `${style} must be selectable and persisted in the active config.`);
        assert.equal(tones.state.wave, wave, `${style} must use its own timbre.`);
        assert.equal(tones.state.startFrequency, firstFrequency, `${style} must use its own opening note.`);
    }

    for (const [style, wave, firstFrequency] of [['chime', 'sine', 784], ['radar', 'triangle', 420], ['mech', 'square', 220], ['breeze', 'sine', 480]]) {
        const tones = createHarness();
        tones.api.cambiarEstiloSonido(style);
        assert.equal(tones.config.estiloSfx, style);
        assert.equal(tones.state.wave, wave);
        assert.equal(tones.state.startFrequency, firstFrequency);
        assert.match(settings, new RegExp(`option value="${style}"`));
    }

    const touch = createHarness();
    const button = { closest: selector => selector.includes('button') ? { closest: () => null } : null };
    touch.state.events.pointerdown[0]({ target: button });
    assert.equal(touch.state.oscillators, 1, 'touch feedback is emitted during the user activation gesture');

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
