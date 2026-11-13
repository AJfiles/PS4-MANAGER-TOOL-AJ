const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'app.js'), 'utf8');
const start = source.indexOf('function abrirModulo(moduloId)');
const end = source.indexOf('\nfunction volverAlLauncher()', start);
assert(start >= 0 && end > start, 'No se encontraron las acciones de herramientas de juego.');

const values = new Map();
const opened = [];
const notices = [];
const window = {
    abrirModuloNativo: id => opened.push(id),
    ps5Notification: (...args) => notices.push(args)
};
const context = {
    window,
    localStorage: { setItem: (key, value) => values.set(key, value) }
};
vm.runInNewContext(source.slice(start, end), context, { filename: 'js/app.js' });

for (const [moduleId, key] of [
    ['parches-juego', 'ghm_filter_patch_cusa'],
    ['savegames', 'ghm_filter_save_cusa'],
    ['capturas', 'ghm_filter_capture_cusa']
]) {
    window.abrirHerramientaJuego(moduleId, { id: 'CUSA12345' });
    assert.equal(values.get(key), 'CUSA12345', `${moduleId} debe recibir el CUSA activo como filtro.`);
    assert.equal(opened.at(-1), moduleId, `${moduleId} debe abrir su pantalla independiente.`);
}

const openedCount = opened.length;
window.abrirHerramientaJuego('savegames', { id: 'NOT-A-TITLE-ID' });
assert.equal(opened.length, openedCount, 'Un identificador con formato inválido no debe abrir un filtro de guardado incorrecto.');
assert.equal(notices.length, 1, 'Un ID no compatible debe informar al usuario.');
console.log('BIBLIOTECA_GAME_ACTIONS_FILTER_BY_CUSA_OK');
