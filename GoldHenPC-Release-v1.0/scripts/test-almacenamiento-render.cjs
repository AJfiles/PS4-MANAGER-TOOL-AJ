const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'almacenamiento.js'), 'utf8');
const elements = new Map();
function element(id) {
    if (!elements.has(id)) elements.set(id, {
        innerHTML: '', textContent: '',
        querySelectorAll: selector => id === 'storage-tabs' && selector === '[data-storage-category]'
            ? [...elements.get(id).innerHTML.matchAll(/data-storage-category="([^"]+)"/g)].map(match => ({ dataset: { storageCategory: match[1] }, addEventListener(event, handler) { if (event === 'click') this.click = handler; } }))
            : []
    });
    return elements.get(id);
}
const context = {
    window: {},
    document: { getElementById: element },
    localStorage: { getItem: key => key === 'sebas_ip_final_libre' ? '192.0.2.39' : '2121' },
    FormData: class { append() {} },
    fetch: async () => ({ ok: true, json: async () => ({ status: 'success', report: {
        categories: {
            'juegos y aplicaciones': { bytes: 100, items: [{ title: 'God of War', title_id: 'CUSA00001', icon: 'icon.png', bytes: 100, files: 2 }] },
            partidas: { bytes: 20, items: [{ title: 'Save', title_id: 'CUSA00001', profile: 'Pepe', icon: '', bytes: 20, files: 1 }] },
            capturas: { bytes: 30, items: [{ title: 'capture.jpg', title_id: '', icon: 'capture.jpg', bytes: 30, files: 1 }] },
            'archivos grandes': { bytes: 999, items: [{ title: 'private.pkg', bytes: 999 }] }
        },
        total_bytes: 150, capacity_bytes: null, free_bytes: null, scan_errors: [], limited: false, lower_bound: false
    } }) })
};
vm.runInNewContext(source, context, { filename: 'js/almacenamiento.js' });

(async () => {
    await context.window.almacenamientoCargar();
    const html = element('storage-items').innerHTML;
    assert.match(html, /God of War/);
    context.window.almacenamientoSeleccionarCategoria('partidas');
    assert.match(element('storage-items').innerHTML, /Pepe/);
    context.window.almacenamientoSeleccionarCategoria('capturas');
    assert.match(element('storage-items').innerHTML, /capture\.jpg/);
    assert.doesNotMatch(element('storage-items').innerHTML, /private\.pkg|archivos grandes/);
    assert.match(element('storage-quick').innerHTML, /150 B/);
    assert.match(element('storage-quick').innerHTML, /no disponible por FTP/);
    assert.equal(element('storage-summary').textContent, 'Inventario leído');
    console.log('STORAGE_THREE_CATEGORIES_RENDER_OK');
})().catch(error => { console.error(error); process.exitCode = 1; });
