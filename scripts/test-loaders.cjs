const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'loaders.js'), 'utf8');
const values = new Map();
const box = { innerHTML: '' };
const context = {
    window: {},
    document: { getElementById: id => id === 'loader-preview' ? box : null },
    localStorage: { getItem: key => values.get(key) || null, setItem: (key, value) => values.set(key, value) }
};
vm.runInNewContext(source, context, { filename: 'js/loaders.js' });

assert.equal(context.window.ghmLoaderStyle(), 'orbit');
for (const style of ['orbit', 'pulse', 'scan', 'minimal']) {
    const markup = context.window.ghmLoaderMarkup('Leyendo', 'Detalle <seguro>', 'rose', style);
    assert.match(markup, new RegExp(`ghm-loader-${style}`));
    assert.match(markup, /data-accent="rose"/);
    assert.match(markup, /Detalle &lt;seguro&gt;/);
}
values.set('cfg_loader_style', 'scan');
assert.equal(context.window.ghmLoaderStyle(), 'scan');
context.window.ghmLoaderPreview('pulse');
assert.match(box.innerHTML, /ghm-loader-pulse/);
console.log('CUSTOM_LOADERS_PREVIEW_OK');
