const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const root = path.join(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'js', 'capturas.js'), 'utf8');
const library = fs.readFileSync(path.join(root, 'js', 'biblioteca.js'), 'utf8');
assert.doesNotMatch(library, /captura|screenshot|galeria|gallery/i, 'Biblioteca no debe cargar ni exponer la galería de Capturas.');
assert.match(source, /function renderGallery/);
assert.match(source, /safeImageUrl/);

class MockElement {
    constructor(id = '') {
        this.id = id; this.children = []; this.dataset = {}; this.className = ''; this.textContent = '';
        this.classList = { add() {}, remove() {}, toggle() {} };
    }
    append(...items) { this.children.push(...items); }
    appendChild(item) { this.children.push(item); return item; }
    replaceChildren(...items) { this.children = [...items]; }
    addEventListener() {}
    setAttribute() {}
    click() { this.wasClicked = true; }
    remove() { this.wasRemoved = true; }
}

const elements = new Map();
const requests = [];
const notifications = [];
const document = {
    getElementById(id) { if (!elements.has(id)) elements.set(id, new MockElement(id)); return elements.get(id); },
    createElement() { return new MockElement(); },
    querySelectorAll() { return []; },
    body: new MockElement('body')
};
let fetchCount = 0;
const context = {
    document,
    localStorage: { getItem: key => key.includes('ip') ? '192.168.20.39' : '2121' },
    FormData: class { constructor() { this.entries = []; } append(...args) { this.entries.push(args); } },
    fetch: async (_url, options) => {
        requests.push(options);
        fetchCount++;
        if (fetchCount === 1) return { ok: true, headers: { get: () => 'application/json' }, json: async () => ({ status: 'success', images: [
            { name: 'uno.jpg', url: 'user/cache/capturas/a1.jpg', remote_path: '/user/av_contents/photo/CUSA00001/uno.jpg' },
            { name: 'dos.png', url: 'api/stream_image_api.php?path=x.png', remote_path: '/user/av_contents/photo/CUSA00002/dos.png' },
            { name: 'maliciosa.png', url: 'javascript:alert(1)', remote_path: '/user/av_contents/photo/CUSA00003/maliciosa.png' }
        ] }) };
        return { ok: true, headers: { get: () => 'application/zip' }, blob: async () => ({ type: 'application/zip' }) };
    },
    URL: { createObjectURL: () => 'blob:test', revokeObjectURL() {} },
    setTimeout: () => 1,
    Date,
    confirm: () => true,
    navigator: {},
    window: { ps5Notification: (...args) => notifications.push(args) },
    console
};
vm.runInNewContext(source, context, { filename: 'js/capturas.js' });

(async () => {
    await context.window.capturasCargar(true);
    assert.equal(elements.get('galeria-count').textContent, '3 capturas');
    assert.equal(elements.get('galeria-grid').children.length, 3);
    const firstImage = elements.get('galeria-grid').children[0].children[0];
    assert.equal(firstImage.src, 'user/cache/capturas/a1.jpg');
    const rejectedImage = elements.get('galeria-grid').children[2].children[0];
    assert.equal(rejectedImage.src, '', 'Se debe rechazar una URL javascript incluso con metadatos de API.');
    context.window.seleccionarCaptura(1, true);
    context.window.seleccionarCaptura(0, true);
    await context.window.descargarSeleccionCapturas();
    assert.equal(requests[1].method, 'POST');
    assert.deepEqual(requests[1].body.entries.filter(([key]) => key === 'action'), [['action', 'download_selected']]);
    assert.deepEqual(requests[1].body.entries.filter(([key]) => key === 'remote_paths[]').map(([, value]) => value), [
        '/user/av_contents/photo/CUSA00001/uno.jpg',
        '/user/av_contents/photo/CUSA00002/dos.png'
    ]);
    assert.equal(document.body.children[0].wasClicked, true);
    console.log('CAPTURE_ZIP_DOWNLOAD_OK');
})().catch(error => { console.error(error); process.exitCode = 1; });
