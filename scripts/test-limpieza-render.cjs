const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'limpieza.js'), 'utf8');
const elements = new Map();
const requests = [];
let confirmations = 0;
const saves = [
    { title: 'Juego A', title_id: 'CUSA00419', profile: 'Pepe', profile_key: 'a'.repeat(64), installed: false, bytes: 1000, metadata_bytes: 50, files: 2, metadata_files: 1, icon: '' },
    { title: 'Juego B', title_id: 'SLUS21004', profile: 'Luna', profile_key: 'b'.repeat(64), installed: false, bytes: 2000, metadata_bytes: 100, files: 3, metadata_files: 1, icon: '' },
    { title: 'Instalado', title_id: 'CUSA00001', profile: 'Pepe', profile_key: 'a'.repeat(64), installed: true, bytes: 3000, metadata_bytes: 0, files: 1, metadata_files: 0, icon: '' }
];
function element(id) {
    if (!elements.has(id)) elements.set(id, {
        innerHTML: '', textContent: '', disabled: false,
        classList: { toggle() {} }
    });
    return elements.get(id);
}

const context = {
    window: { ps5Notification() {} },
    document: { getElementById: element, querySelectorAll: () => [] },
    localStorage: { getItem: key => key.includes('ip') ? '192.168.1.40' : '2121' },
    FormData: class { constructor() { this.fields = {}; } append(key, value) { this.fields[key] = value; } },
    confirm: () => { confirmations++; return true; },
    fetch: async (_url, options) => {
        const fields = options.body.fields;
        requests.push({ ...fields });
        if (fields.action === 'scan') return { ok: true, json: async () => ({ status: 'success', saves }) };
        if (fields.action === 'backup') return { ok: true, json: async () => ({
            status: 'success', backup: { id: `fixture-${fields.title_id}`, total_bytes: 1234 },
            download: `api/saves_api.php?action=download&backup_id=fixture-${fields.title_id}`
        }) };
        if (fields.action === 'cleanup_delete') return { ok: true, json: async () => ({ status: 'success', message: 'fixture deleted' }) };
        throw new Error(`Unexpected fixture action: ${fields.action}`);
    }
};

vm.runInNewContext(source, context, { filename: 'js/limpieza.js' });
(async () => {
    await context.window.limpiezaCargar();
    assert.match(element('orphan-list').innerHTML, /Pepe/);
    assert.match(element('orphan-list').innerHTML, /Luna/);
    assert.match(element('orphan-list').innerHTML, /SLUS21004/);
    assert.doesNotMatch(element('orphan-list').innerHTML, /Perfil\s*[12]/);
    assert.match(element('orphan-summary').textContent, /2 partidas de juegos no instalados/);
    context.window.limpiezaSeleccionarTodo();
    assert.match(element('cleanup-selected-summary').textContent, /2 partida\(s\) seleccionada\(s\)/);

    await context.window.limpiezaRespaldarDescargar();
    assert.equal((element('cleanup-backup-results').innerHTML.match(/Descargar ZIP/g) || []).length, 2);

    await context.window.limpiezaBorrarSeleccion(true);
    const backedDeletes = requests.filter(request => request.action === 'cleanup_delete' && request.permanent === '0');
    assert.equal(backedDeletes.length, 2);
    assert.ok(backedDeletes.every(request => request.backup_id.startsWith('fixture-')));

    context.window.limpiezaSeleccionarTodo();
    await context.window.limpiezaBorrarSeleccion(false);
    const permanentDeletes = requests.filter(request => request.action === 'cleanup_delete' && request.permanent === '1');
    assert.equal(permanentDeletes.length, 2);
    assert.ok(permanentDeletes.every(request => request.backup_id === ''));
    assert.equal(confirmations, 3, 'Cada acción de borrado/respaldo debe pedir confirmación simple.');
    assert.doesNotMatch(source, /prompt\s*\(/, 'La limpieza ya no debe pedir escribir una frase para borrar.');
    console.log('CLEANUP_PROFILE_AND_DELETE_FLOWS_OK');
})().catch(error => { console.error(error); process.exitCode = 1; });
