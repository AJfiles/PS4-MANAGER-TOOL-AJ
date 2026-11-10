const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'app.js'), 'utf8');
const names = ['seleccionarFondoCustom', 'seleccionarIntroCustom', 'aplicarPrevisualizacionVisual', 'cancelarPrevisualizacionVisual'];
const functions = names.map(name => {
    const start = source.indexOf(`function ${name}(`);
    assert(start >= 0, `Missing ${name}`);
    const end = source.indexOf('\n}', start);
    assert(end > start, `Could not extract ${name}`);
    return source.slice(start, end + 2);
}).join('\n');

const stored = new Map();
const calls = [];
const classes = new Map();
function node(id) {
    if (!classes.has(id)) classes.set(id, new Set(id.startsWith('modal') ? ['hidden'] : []));
    const classSet = classes.get(id);
    return {
        textContent: '', innerText: '', classList: {
            add: name => classSet.add(name),
            remove: name => classSet.delete(name),
            toggle: (name, force) => force ? classSet.add(name) : classSet.delete(name)
        }
    };
}
const context = {
    pendingVisualPreview: null,
    document: { getElementById: node },
    localStorage: { getItem: key => stored.get(key) || null, setItem: (key, value) => stored.set(key, String(value)) },
    window: { cancelarIntroTemporal: () => calls.push(['cancelIntro']) },
    cerrarSelectorFondo: () => calls.push(['closeWallpaperSelector']),
    cerrarSelectorIntro: () => calls.push(['closeIntroSelector']),
    emitirEfectoSonidoNativo: () => {},
    changeDynamicWallpaper: (...args) => calls.push(['wallpaper', ...args]),
    reproducirIntro: id => calls.push(['introPreview', id]),
    setTimeout: callback => { callback(); return 1; },
    sysNotification: () => {}
};
vm.runInNewContext(functions, context, { filename: 'app.js#custom-previews' });

context.seleccionarFondoCustom('bg-radar', 'Radar táctico');
assert.equal(stored.has('ps4_dynamic_bg'), false, 'Wallpaper preview must not persist before Apply');
assert.deepEqual(calls.find(call => call[0] === 'wallpaper'), ['wallpaper', 'bg-radar', false]);
context.aplicarPrevisualizacionVisual();
assert.equal(stored.get('ps4_dynamic_bg'), 'bg-radar');

stored.delete('ps4_dynamic_bg');
context.seleccionarFondoCustom('bg-minimal', 'Órbita minimal');
context.cancelarPrevisualizacionVisual();
assert.equal(stored.has('ps4_dynamic_bg'), false, 'Cancel must preserve an unset wallpaper preference');
assert.deepEqual(calls.at(-1), ['wallpaper', 'bg-ps4', false]);

context.seleccionarIntroCustom('intro-aurora', 'Aurora AJ');
assert.equal(stored.has('ps4_selected_intro'), false, 'Intro preview must not persist before Apply');
assert.deepEqual(calls.find(call => call[0] === 'introPreview'), ['introPreview', 'intro-aurora']);
context.cancelarPrevisualizacionVisual();
assert(calls.some(call => call[0] === 'cancelIntro'));
context.seleccionarIntroCustom('intro-wave', 'Onda clásica');
context.aplicarPrevisualizacionVisual();
assert.equal(stored.get('ps4_selected_intro'), 'intro-wave');
console.log('CUSTOM_THEME_INTRO_PREVIEWS_OK');
