const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'loaders.js'), 'utf8');
const adjustments = fs.readFileSync(path.join(__dirname, '..', 'modulos', 'ajustes.php'), 'utf8');
const appSource = fs.readFileSync(path.join(__dirname, '..', 'js', 'app.js'), 'utf8');
const stylesSource = fs.readFileSync(path.join(__dirname, '..', 'index.php'), 'utf8');
const values = new Map();
const box = { innerHTML: '' };
const context = {
    window: {},
    document: { getElementById: id => id === 'loader-preview' ? box : null },
    localStorage: { getItem: key => values.get(key) || null, setItem: (key, value) => values.set(key, value) }
};
vm.runInNewContext(source, context, { filename: 'js/loaders.js' });

assert.equal(context.window.ghmLoaderStyle(), 'orbit');
for (const style of Object.keys(context.window.ghmLoaderLabels)) {
    const markup = context.window.ghmLoaderMarkup('Leyendo', 'Detalle <seguro>', 'rose', style);
    assert.match(markup, new RegExp(`ghm-loader-${style}`));
    assert.match(markup, /data-accent="rose"/);
    assert.match(markup, /Detalle &lt;seguro&gt;/);
    assert.match(adjustments, new RegExp(`option value="${style}"`), `Falta el control visible para ${style}.`);
    if (style !== 'orbit') assert.ok(stylesSource.includes(`.ghm-loader-${style}`), `Falta el CSS animado para ${style}.`);
}
values.set('cfg_loader_style', 'scan');
assert.equal(context.window.ghmLoaderStyle(), 'scan');
context.window.ghmLoaderPreview('pulse');
assert.match(box.innerHTML, /ghm-loader-pulse/);
context.window.ghmLoaderPreview('vortex', 'emerald');
assert.match(box.innerHTML, /ghm-loader-vortex/);
assert.match(box.innerHTML, /data-accent="emerald"/);
assert.match(adjustments, /id="cfg-loader-accent"/);
assert.equal(Object.keys(context.window.ghmLoaderLabels).length, 16);
for (const style of ['quantum', 'helix', 'portal']) assert.match(adjustments, new RegExp(`option value="${style}"`));
assert.match(adjustments, /id="launcher-preview-panel" class="rounded-2xl/);
assert.doesNotMatch(adjustments, /launcher-preview-panel" class="hidden/);
for (const style of ['chime', 'radar', 'mech', 'breeze']) {
    assert.match(adjustments, new RegExp(`option value="${style}"`));
    assert.match(appSource, new RegExp(`${style}: \\{`));
}
assert.match(stylesSource, /\.launcher-section-label \{ grid-column:1 \/ -1; margin:1\.5rem/);
console.log('CUSTOM_LOADERS_16_STYLES_ACCENTS_ALWAYS_VISIBLE_PREVIEW_OK');
