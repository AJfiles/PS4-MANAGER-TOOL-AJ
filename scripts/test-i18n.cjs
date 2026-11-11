const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'i18n.js'), 'utf8');
const text = value => ({ nodeType: 3, nodeValue: value });
const heading = { nodeType: 1, childNodes: [text('Configuración')], attrs: { title: 'Actualizar' }, hasAttribute(name) { return name in this.attrs; }, getAttribute(name) { return this.attrs[name]; }, setAttribute(name, value) { this.attrs[name] = value; } };
const status = { nodeType: 1, childNodes: [text('FTP 192.0.2.39:2121 · 38 juegos · 387 XML')], attrs: {}, hasAttribute() { return false; } };
const confirmation = { nodeType: 1, childNodes: [text('Borrado permanente sin respaldo ni descarga. Se eliminarán la partida y sus metadatos de CUSA12345 (User-2). Esta acción no se puede deshacer. ¿Eliminar ahora?')], attrs: {}, hasAttribute() { return false; } };
const bgft = { nodeType: 1, childNodes: [text('Progreso BGFT: 22% · 500.13 MB de 2.19 GB.')], attrs: {}, hasAttribute() { return false; } };
const organizer = { nodeType: 1, childNodes: [text('📝 “Mis APPS” y el traslado de 2 app(s) están pendientes. Pulsa “Guardar cambios en PS4” para aplicarlos.')], attrs: {}, hasAttribute() { return false; } };
const changing = text('Cargando…');
const selector = { value: '' };
const body = { nodeType: 1, childNodes: [heading, status, confirmation, bgft, organizer, changing], hasAttribute: () => false, getAttribute: () => null, setAttribute() {} };
const document = { body, documentElement: { lang: '' }, getElementById: id => id === 'cfg-language' ? selector : null };
let activeObserver;
class MutationObserver { constructor(callback) { this.callback = callback; activeObserver = this; } observe(_target, options) { this.options = options; } }
const values = new Map();
const dialogCalls = [];
const context = { window: {
    alert: message => dialogCalls.push(['alert', message]),
    confirm: message => { dialogCalls.push(['confirm', message]); return true; },
    prompt: message => { dialogCalls.push(['prompt', message]); return 'ACTIVAR ACTUALIZACIONES'; }
}, document, Node: { TEXT_NODE: 3, ELEMENT_NODE: 1 }, MutationObserver, localStorage: { getItem: key => values.get(key) || null, setItem: (key, value) => values.set(key, value) } };
vm.runInNewContext(source, context, { filename: 'js/i18n.js' });
assert.equal(heading.childNodes[0].nodeValue, 'Configuración');
context.window.ghmSetLanguage('en');
assert.equal(heading.childNodes[0].nodeValue, 'Settings');
assert.equal(status.childNodes[0].nodeValue, 'FTP 192.0.2.39:2121 · 38 games · 387 XML');
assert.equal(confirmation.childNodes[0].nodeValue, 'Permanent deletion without backup or download. The save and its metadata for CUSA12345 (User-2) will be deleted. This cannot be undone. Delete now?');
assert.equal(bgft.childNodes[0].nodeValue, 'BGFT progress: 22% · 500.13 MB of 2.19 GB.');
assert.equal(organizer.childNodes[0].nodeValue, '📝 “Mis APPS” and moving 2 app(s) are pending. Tap “Save changes to PS4” to apply them.');
assert.equal(changing.nodeValue, 'Loading…');
assert.equal(activeObserver.options.characterData, true);
assert.equal(activeObserver.options.attributes, true);
assert.equal(heading.attrs.title, 'Refresh');
activeObserver.callback([{ type: 'attributes', target: heading, attributeName: 'title' }]); // consume the observer record from our own translation
heading.attrs.title = 'Conectar';
activeObserver.callback([{ type: 'attributes', target: heading, attributeName: 'title' }]);
assert.equal(heading.attrs.title, 'Connect');
activeObserver.callback([{ type: 'characterData', target: changing }]); // consume the observer record from our own translation
changing.nodeValue = 'Buscando juegos instalados…';
activeObserver.callback([{ type: 'characterData', target: changing }]);
assert.equal(changing.nodeValue, 'Searching installed games…');
assert.equal(context.window.confirm('¿Enviar Fan 50 para firmware 9.00? Verifica que el firmware de la consola coincida exactamente.'), true);
assert.equal(dialogCalls.at(-1)[1], 'Send Fan 50 for firmware 9.00? Verify that the console firmware matches exactly.');
assert.equal(context.window.prompt('3/3 · Para autorizar el envío, escribe exactamente: ACTIVAR ACTUALIZACIONES'), 'ACTIVAR ACTUALIZACIONES');
assert.equal(dialogCalls.at(-1)[1], '3/3 · To authorize sending, type exactly: ACTIVAR ACTUALIZACIONES');
assert.equal(context.window.ghmTranslateText('FTP 192.0.2.39:2121 · error'), 'FTP 192.0.2.39:2121 · error');
assert.equal(context.window.ghmTranslateText('[ERROR] No respondió ningún servicio conocido en 192.168.20.x. Revisa que PS4 y teléfono estén en la misma LAN y que GoldHEN/Package Installer esté activo.'), '[ERROR] No known service responded on 192.168.20.x. Check that the PS4 and phone are on the same LAN and that GoldHEN/Package Installer is active.');
assert.equal(context.window.ghmTranslateText('Borrado permanente sin respaldo. Se eliminarán 2 partidas seleccionadas (1.4 GB) y sus metadatos. No se podrá deshacer. ¿Eliminar ahora?'), 'Permanent deletion without backup. Delete 2 selected save(s) (1.4 GB) and their metadata? This cannot be undone. Delete now?');
assert.equal(context.window.ghmTranslateText("Categoría 'Favoritos' eliminada."), 'Category “Favoritos” deleted.');
assert.equal(context.window.ghmTranslateText('No se envió ningún byte: 192.0.2.39:9090 rechazó la conexión. Comprueba Enable BinLoader Server en GoldHEN → Settings → Servers. Si aparece activo, recarga GoldHEN y comprueba su versión (2.4b18 incluye una corrección de configuración de servidores); después vuelve a intentar.'), 'No bytes were sent: 192.0.2.39:9090 refused the connection. Check Enable BinLoader Server in GoldHEN → Settings → Servers. If it is enabled, reload GoldHEN and check its version (2.4b18 includes a server configuration fix), then try again.');
assert.equal(context.window.ghmTranslateText('GoldHEN rechazó el borrado FTP (550). Verifica que la ruta exista y que esa versión de FTP permita borrar capturas. Detalle: QUOT string not accepted'), 'GoldHEN rejected the FTP deletion (550). Verify that the path exists and this FTP version allows deleting captures. Details: QUOT string not accepted');
assert.equal(context.window.ghmTranslateText('Confirmación requerida: la versión del juego no coincide exactamente con la del parche (1.00 vs 1.02).'), 'Confirmation required: the game version does not exactly match the patch version (1.00 vs 1.02).');
assert.equal(context.window.ghmTranslateText('Error controlado del gestor de partidas: FTP timeout'), 'Save manager error: FTP timeout');
assert.equal(context.window.ghmTranslateText('Elige o crea la carpeta'), 'Choose or create the folder');
assert.equal(context.window.ghmTranslateText('en el almacenamiento compartido. Las exportaciones se guardarán directamente en la carpeta elegida. Android conserva el permiso para esta APK.'), 'in shared storage. Exports will be saved directly in the chosen folder. Android keeps access for this APK.');
assert.equal(document.documentElement.lang, 'en');
context.window.ghmSetLanguage('es');
assert.equal(heading.childNodes[0].nodeValue, 'Configuración');
assert.equal(status.childNodes[0].nodeValue, 'FTP 192.0.2.39:2121 · 38 juegos · 387 XML');
assert.equal(confirmation.childNodes[0].nodeValue, 'Borrado permanente sin respaldo ni descarga. Se eliminarán la partida y sus metadatos de CUSA12345 (User-2). Esta acción no se puede deshacer. ¿Eliminar ahora?');
assert.equal(bgft.childNodes[0].nodeValue, 'Progreso BGFT: 22% · 500.13 MB de 2.19 GB.');
assert.equal(organizer.childNodes[0].nodeValue, '📝 “Mis APPS” y el traslado de 2 app(s) están pendientes. Pulsa “Guardar cambios en PS4” para aplicarlos.');
assert.equal(changing.nodeValue, 'Buscando juegos instalados…');
assert.equal(heading.attrs.title, 'Conectar');
assert.equal(document.documentElement.lang, 'es');
assert.equal(context.window.confirm('¿Enviar Fan 50 para firmware 9.00? Verifica que el firmware de la consola coincida exactamente.'), true);
assert.equal(dialogCalls.at(-1)[1], '¿Enviar Fan 50 para firmware 9.00? Verifica que el firmware de la consola coincida exactamente.');
assert.equal(context.window.ghmTranslateText("Categoría 'Favoritos' eliminada."), "Categoría 'Favoritos' eliminada.");
console.log('I18N_LANGUAGE_SWITCH_OK');
