const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'saves.js'), 'utf8');
const elements = new Map();
const requests = [];
function element(id) {
    if (!elements.has(id)) elements.set(id, {
        innerHTML: '', textContent: '',
        querySelectorAll: () => []
    });
    return elements.get(id);
}
const saves = [
    { title: 'APOL00004', title_id: 'APOL00004', installed: true, profile: 'Profile A', profile_key: 'a'.repeat(64), files: 2, metadata_files: 1, bytes: 1000, metadata_bytes: 50, icon: '' },
    { title: 'CUSA00265', title_id: 'CUSA00265', installed: false, profile: 'Profile A', profile_key: 'a'.repeat(64), files: 2, metadata_files: 1, bytes: 2000, metadata_bytes: 50, icon: '' }
];
const context = {
    window: { ps5Notification() {} },
    document: { getElementById: element, querySelectorAll: () => [], addEventListener() {} },
    localStorage: { getItem: key => key.includes('ip') ? '192.168.20.39' : '2121' },
    FormData: class { constructor() { this.fields = {}; } append(key, value) { this.fields[key] = value; } },
    fetch: async (url, options) => {
        const fields = options.body.fields;
        requests.push({ url, fields: { ...fields } });
        if (url === 'api/saves_api.php' && fields.action === 'scan') return { ok: true, json: async () => ({ status: 'success', saves }) };
        if (url === 'api/organizador_apps_api.php' && fields.action === 'scan') return { ok: true, json: async () => ({ status: 'success', profiles: [{ system: [{ titleId: 'APOL00004', titleName: 'Apollo Save Tool', metaDataPath: '/user/appmeta/APOL00004' }], games: [] }] }) };
        if (url === 'api/saves_api.php' && fields.action === 'list_all_backups') return { ok: true, json: async () => ({ status: 'success', backups: [] }) };
        throw new Error(`Unexpected request: ${url} ${fields.action}`);
    }
};

vm.runInNewContext(source, context, { filename: 'js/saves.js' });
(async () => {
    await context.window.savesRecargar();
    const html = element('save-list').innerHTML;
    assert.match(html, /Apollo Save Tool/);
    assert.doesNotMatch(html, /Nombre no disponible · APOL00004/);
    assert.match(html, /organizador_icon_api\.php/);
    assert.match(html, /CUSA00265/);
    assert.match(html, /Juego no instalado/);
    assert.equal(requests[1].fields.host_port, '2121');
    assert.equal(requests[1].fields.host_ip, '192.168.20.39');
    console.log('SAVES_APPDB_TITLE_ENRICHMENT_OK');
})().catch(error => { console.error(error); process.exitCode = 1; });
