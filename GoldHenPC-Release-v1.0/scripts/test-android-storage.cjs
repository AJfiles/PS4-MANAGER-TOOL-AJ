const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('js/android_storage.js', 'utf8');
const events = {};
const status = { textContent: '' };
const section = { classList: { values: new Set(['hidden']), toggle(name, enabled) { enabled ? this.values.add(name) : this.values.delete(name); } } };
const button = { textContent: '' };
const clear = { classList: { values: new Set(['hidden']), toggle(name, enabled) { enabled ? this.values.add(name) : this.values.delete(name); } } };
const writes = [];
let selected = false;
const webToAppDirectory = {
    info: () => ({ ok: true, granted: selected, writable: true, name: selected ? 'Documents' : '' }),
    pick: async () => { selected = true; return { ok: true, granted: true, name: 'Documents' }; },
    release: () => { selected = false; return { ok: true }; },
    write: async (path, bytes) => { writes.push({ path, bytes: Array.from(bytes) }); return { ok: true }; },
    remove: () => ({ ok: true }),
};
const document = {
    body: { appendChild() {} },
    addEventListener(name, callback) { events[name] = callback; },
    getElementById(id) { return ({ 'android-storage-card': section, 'android-storage-status': status, 'android-storage-select': button, 'android-storage-clear': clear })[id] || null; },
};
const window = { WtaDirectory: webToAppDirectory, sysNotification() {}, addEventListener() {} };
const sandbox = {
    window, document, URL, Uint8Array, Promise, location: { href: 'http://127.0.0.1:18500/index.php', origin: 'http://127.0.0.1:18500', hostname: '127.0.0.1', port: '18500' },
    ghmNativeAndroidApk: true,
    fetch: async () => new Response(new Uint8Array([1, 2, 3, 4]), { headers: { 'content-length': '4' } }),
};
vm.runInNewContext(source, sandbox);

(async () => {
    events.DOMContentLoaded();
    assert.equal(section.classList.values.has('hidden'), false, 'storage option is shown in supported APK');
    await window.ghmElegirCarpetaDescargasAndroid();
    assert.match(status.textContent, /Documents/);

    const anchor = {
        href: 'http://127.0.0.1:18500/api/config_api.php?action=export',
        download: 'respaldo.zip', dataset: {}, isConnected: true,
        click() { throw new Error('fallback should not be used on success'); },
    };
    const event = { target: { closest: () => anchor }, prevented: false, stopped: false, preventDefault() { this.prevented = true; }, stopImmediatePropagation() { this.stopped = true; } };
    events.click(event);
    await new Promise(resolve => setTimeout(resolve, 10));
    assert.equal(event.prevented, true, 'same-origin downloads use the selected SAF folder');
    assert.equal(event.stopped, true, 'native download does not also run after the custom save');
    assert.equal(writes.length, 1);
    assert.equal(writes[0].path, 'PS4 Manager Tools AJ/Descargas/respaldo.zip');
    assert.deepEqual(writes[0].bytes, [1, 2, 3, 4]);

    writes.length = 0;
    const largeBytes = new Uint8Array(2 * 1024 * 1024 + 17).fill(7);
    sandbox.fetch = async () => new Response(largeBytes, { headers: { 'content-length': String(largeBytes.byteLength) } });
    const largeAnchor = { href: anchor.href, download: 'large-backup.zip', dataset: {}, isConnected: true, click() {} };
    events.click({ target: { closest: () => largeAnchor }, preventDefault() {}, stopImmediatePropagation() {} });
    await new Promise(resolve => setTimeout(resolve, 30));
    assert.equal(writes.length, 3, 'large exports are streamed in bounded chunks');
    assert.equal(writes.map(entry => entry.bytes.length).reduce((a, b) => a + b, 0), largeBytes.byteLength);
    assert.ok(writes.every(entry => entry.bytes.length <= 1024 * 1024));

    let externalPrevented = false;
    events.click({ target: { closest: () => ({ href: 'https://github.com/file.apk', download: 'file.apk', dataset: {} }) }, preventDefault() { externalPrevented = true; }, stopImmediatePropagation() {} });
    assert.equal(externalPrevented, false, 'cross-origin updates stay with the Android download handler');
    console.log('ANDROID_STORAGE_BRIDGE_OK');
})().catch(error => { console.error(error); process.exitCode = 1; });
