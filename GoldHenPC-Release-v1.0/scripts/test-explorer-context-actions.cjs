const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'explorador.js'), 'utf8');
const start = source.indexOf('function formatearTamanoBytes');
const end = source.indexOf('\nfunction entrarCarpeta', start);
assert(start >= 0 && end > start, 'No se encontraron las funciones de renderizado del explorador FTP.');

class MockElement {
    constructor() {
        this.children = [];
        this.listeners = {};
        this.attributes = {};
        this.classList = { add() {}, remove() {}, toggle() {} };
    }
    set innerHTML(value) { this.html = value; this.children = []; }
    get innerHTML() { return this.html || ''; }
    addEventListener(name, handler) { this.listeners[name] = handler; }
    appendChild(element) { this.children.push(element); return element; }
    setAttribute(name, value) { this.attributes[name] = value; }
}

const container = new MockElement();
const opened = [];
const context = {
    document: { getElementById: id => id === 'ftp-list-container' ? container : new MockElement(), createElement: () => new MockElement() },
    window: { ps5Notification() {} },
    exploradorRutaActual: '/data',
    multiSelectedPaths: new Map(),
    isMultiSelectMode: false,
    longPressTimer: null,
    abrirContextMenu: (item, isDir, icon) => opened.push({ item, isDir, icon }),
    toggleSeleccion() {},
    normalizePath: value => value.replace(/\/+/g, '/'),
    setTimeout,
    clearTimeout
};
vm.runInNewContext(source.slice(start, end), context, { filename: 'js/explorador.js' });

context.renderizarListaExplorador([{ name: '<pkg>.pkg', size: 42, date: 'now' }], []);
const row = container.children[0];
const actionButton = row.children.at(-1);
assert.equal(actionButton.attributes['aria-label'], 'Acciones para <pkg>.pkg');
assert.match(row.innerHTML, /&lt;pkg&gt;\.pkg/);
assert.doesNotMatch(row.innerHTML, /<pkg>/, 'El nombre remoto debe renderizarse como texto escapado.');

let prevented = false;
let stopped = false;
row.listeners.contextmenu({ preventDefault() { prevented = true; }, stopPropagation() { stopped = true; } });
assert.equal(prevented, true, 'El clic derecho conserva el menú del explorador.');
assert.equal(stopped, true);
assert.equal(opened.length, 1);

stopped = false;
actionButton.listeners.click({ preventDefault() {}, stopPropagation() { stopped = true; } });
assert.equal(stopped, true, 'El botón de acciones no debe abrir la carpeta al mismo tiempo.');
assert.equal(opened.length, 2, 'El botón visible abre el mismo menú que el clic derecho.');
assert.equal(opened[1].item.name, '<pkg>.pkg');
console.log('FTP_CONTEXT_MENU_MOUSE_TOUCH_KEYBOARD_ACTIONS_OK');
