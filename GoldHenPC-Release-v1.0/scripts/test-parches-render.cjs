const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'parches_juego.js'), 'utf8');
const elements = new Map();
const savedSettings = { sebas_ip_final_libre: '192.0.2.39', sebas_port_libre: '2121' };
let fetchCount = 0;
function element(id) {
    if (!elements.has(id)) elements.set(id, {
        innerHTML: '', textContent: '', querySelectorAll: () => [],
        classList: {
            toggles: [], add(...names) { this.toggles.push(...names.map(name => [name, true])); },
            remove(...names) { this.toggles.push(...names.map(name => [name, false])); },
            toggle(name, enabled) { this.toggles.push([name, enabled]); }
        }
    });
    return elements.get(id);
}
const context = {
    window: { ps5Notification: () => {} },
    document: { getElementById: element, querySelectorAll: () => [] },
    localStorage: { getItem: key => savedSettings[key] || null, setItem: (key, value) => { savedSettings[key] = String(value); } },
    FormData: class { append() {} },
    fetch: async () => { fetchCount++; return ({ ok: true, json: async () => ({
        status: 'success', installed_count: 38, xml_readable: true, xml_count: 387,
        ini_readable: true, plugin_in_ini: true, global_enabled: true,
        games: [{
            cusa: 'CUSA12824', title: 'Hogwarts Legacy', game_version: '01.05', icon: '',
            patches: [{ key: '0123456789abcdef', name: '60 FPS', app_version: '01.05', patch_version: '1.0', author: 'Test', note: '', enabled: false, state_exists: true, compatibility: 'compatible' }]
        }]
    }) }); }
};
vm.runInNewContext(source, context, { filename: 'js/parches_juego.js' });

(async () => {
    await context.window.parchesRecargar();
    assert.equal(element('patch-count').textContent, '1 juego con parches disponibles');
    assert.match(element('patch-connection').textContent, /FTP 192\.0\.2\.39:2121/);
    assert.match(element('patch-game-list').innerHTML, /Hogwarts Legacy/);
    assert.match(element('patch-game-list').innerHTML, /CUSA12824/);
    assert.match(element('patch-game-list').innerHTML, /1 parche/);
    assert.equal(element('patch-plugin-status').innerHTML.includes('game_patch.prx'), true);
    assert.equal(element('patch-ip').value, '192.0.2.39');
    assert.equal(element('patch-port').value, '2121');
    context.window.parchesAbrirJuego('CUSA12824');
    assert.match(element('patch-modal-list').innerHTML, /Compatible exacto/);
    element('patch-ip').value = '999.168.20.39';
    await context.window.parchesGuardarConexion();
    assert.equal(fetchCount, 1);
    assert.equal(savedSettings.sebas_ip_final_libre, '192.0.2.39');
    element('patch-ip').value = '192.168.20.41';
    element('patch-port').value = '2122';
    await context.window.parchesGuardarConexion();
    assert.equal(savedSettings.sebas_ip_final_libre, '192.168.20.41');
    assert.equal(savedSettings.sebas_port_libre, '2122');
    assert.equal(fetchCount, 2);
    assert.match(element('patch-connection').textContent, /192\.168\.20\.41:2122/);
    savedSettings.sebas_ip_final_libre = '';
    await context.window.parchesRecargar();
    assert.match(element('patch-connection').textContent, /Sin IP guardada/);
    assert.match(element('patch-game-list').innerHTML, /Conecta la consola/);
    savedSettings.sebas_ip_final_libre = '192.0.2.39';
    context.fetch = async () => ({ ok: true, json: async () => { throw new SyntaxError('Unexpected token <'); } });
    await context.window.parchesRecargar();
    assert.match(element('patch-connection').textContent, /error/);
    assert.match(element('patch-game-list').innerHTML, /respuesta inválida del servidor/i);
    console.log('PATCHES_RENDER_OK');
})().catch(error => { console.error(error); process.exitCode = 1; });
