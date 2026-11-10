const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'app.js'), 'utf8');
const start = source.indexOf('function alternarVistaLauncher(');
const end = source.indexOf('function cambiarFuenteApp(', start);
assert(start >= 0 && end > start, 'No se encontraron las funciones de personalización del menú.');

const makeGrid = () => {
const classes = new Set();
const grid = {
    offsetWidth: 100,
    querySelectorAll: () => [{ style: {} }, { style: {} }],
    classList: {
        add: (...names) => names.forEach(name => classes.add(name)),
        remove: (...names) => names.forEach(name => classes.delete(name)),
        toggle: (name, enabled) => enabled ? classes.add(name) : classes.delete(name),
        contains: name => classes.has(name)
    }
};
return { grid, classes };
};
const real = makeGrid();
const preview = makeGrid();
const previewPanelClasses = new Set(['hidden']);
const previewPanel = { classList: { contains: name => previewPanelClasses.has(name), toggle: (name, force) => force ? previewPanelClasses.add(name) : previewPanelClasses.delete(name) } };
const previewButton = { attributes: {}, setAttribute: (key, value) => { previewButton.attributes[key] = value; } };
const selectors = {
    'cfg-launcher-shape': { value: 'diamond' },
    'cfg-launcher-icons': { value: 'mono' },
    'cfg-loader-style': { value: 'scan' }
};
const storage = new Map();
const context = {
    window: { ghmLoaderPreview: style => { context.previewedLoader = style; } },
    document: { getElementById: id => id === 'launcher-grid' ? real.grid : id === 'launcher-preview-grid' ? preview.grid : id === 'launcher-preview-panel' ? previewPanel : id === 'launcher-preview-toggle' ? previewButton : selectors[id] || null },
    localStorage: {
        setItem: (key, value) => storage.set(key, String(value)),
        getItem: key => storage.has(key) ? storage.get(key) : null
    }
};

vm.runInNewContext(source.slice(start, end), context, { filename: 'js/app.js#launcher-preferences' });
context.alternarVistaLauncher(true);
assert(real.classes.has('launcher-vertical'));
assert(preview.classes.has('launcher-vertical'));
assert.equal(storage.get('cfg_launcher_vertical'), 'true');
context.alternarVistaLauncher(false);
assert(!real.classes.has('launcher-vertical'));
assert(!preview.classes.has('launcher-vertical'));
assert.equal(storage.get('cfg_launcher_vertical'), 'false');

context.cambiarAnimacionLauncher('pulse');
assert(real.classes.has('motion-pulse'));
assert(preview.classes.has('motion-pulse'));
assert.equal(storage.get('cfg_launcher_motion'), 'pulse');
context.cambiarAnimacionLauncher('tilt');
assert(real.classes.has('motion-tilt'));
assert.equal(storage.get('cfg_launcher_motion'), 'tilt');
context.cambiarAnimacionLauncher('unknown');
assert(real.classes.has('motion-none'));
assert.equal(storage.get('cfg_launcher_motion'), 'none');

context.cambiarTemaLauncher('aurora');
assert(real.classes.has('theme-aurora'));
assert(preview.classes.has('theme-aurora'));
assert.equal(storage.get('cfg_launcher_theme'), 'aurora');
context.cambiarTemaLauncher('unknown');
assert(real.classes.has('theme-glass'));
assert.equal(storage.get('cfg_launcher_theme'), 'glass');

context.cambiarEstiloIconosLauncher();
assert(real.classes.has('shape-diamond'));
assert(real.classes.has('icons-mono'));
assert(preview.classes.has('shape-diamond'));
assert(preview.classes.has('icons-mono'));
assert.equal(storage.get('cfg_launcher_shape'), 'diamond');
assert.equal(storage.get('cfg_launcher_icons'), 'mono');

context.cambiarEstiloLoader('pulse');
assert.equal(storage.get('cfg_loader_style'), 'pulse');
assert.equal(context.previewedLoader, 'pulse');
storage.set('cfg_launcher_motion', 'rise');
context.toggleLauncherPreview();
assert(!previewPanelClasses.has('hidden'));
assert.equal(previewButton.attributes['aria-expanded'], 'true');
assert(preview.classes.has('motion-rise'));
context.toggleLauncherPreview();
assert(previewPanelClasses.has('hidden'));
assert.equal(previewButton.attributes['aria-expanded'], 'false');

console.log('LAUNCHER_PREFERENCES_OK');


