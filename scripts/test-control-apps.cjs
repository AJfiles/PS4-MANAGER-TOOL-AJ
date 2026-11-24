/**
 * Test unitario y funcional simulado para Control de Apps (js/control_apps.js)
 */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const controllerSource = fs.readFileSync(path.join(root, 'js', 'control_apps.js'), 'utf8');

// Fixture DOM simulado
const elements = new Map();
function createElement(id, initialText = '') {
    const el = {
        id,
        textContent: initialText,
        innerHTML: initialText,
        value: initialText,
        disabled: false,
        className: '',
        style: {},
        options: [],
        add(option) { this.options.push(option); },
        replaceChildren(...children) { this.options = children; this.value = children[0]?.value || ''; },
        classList: {
            classes: new Set(),
            add(c) { this.classes.add(c); },
            remove(c) { this.classes.delete(c); },
            contains(c) { return this.classes.has(c); }
        }
    };
    elements.set(id, el);
    return el;
}

const requiredIds = [
    'ca-connection-text', 'ca-connection-badge', 'ca-btn-actualizar-estado', 'ca-refresh-icon',
    'ca-status-badge', 'ca-foreground-title', 'ca-foreground-cusa', 'ca-foreground-version',
    'ca-foreground-detail', 'ca-foreground-time', 'ca-foreground-cover', 'ca-btn-cerrar-app',
    'ca-installed-list', 'ca-installed-count-badge', 'ca-installed-search', 'ca-log-container',
    'ca-launch-user', 'ca-launch-user-status',
    'modal-confirmar-cierre-forzado', 'ca-modal-target-name', 'ca-modal-target-cusa', 'ca-modal-target-icon'
];

requiredIds.forEach(id => createElement(id));

const documentMock = {
    getElementById: id => elements.get(id) || null,
    body: {
        appendChild() {},
        removeChild() {}
    },
    createElement(tag) {
        return {
            tag,
            value: '',
            style: {},
            select() {}
        };
    },
    execCommand() { return true; }
};

class OptionMock {
    constructor(text, value) { this.text = text; this.value = value; }
}

const localStorageStore = new Map([
    ['sebas_ip_final_libre', '192.168.1.100'],
    ['sebas_port_libre', '2121']
]);

const localStorageMock = {
    getItem: k => localStorageStore.get(k) || null,
    setItem: (k, v) => localStorageStore.set(k, String(v)),
    removeItem: k => localStorageStore.delete(k)
};

const notifications = [];
let fetchHandler = null;

const context = {
    document: documentMock,
    window: {
        ps5Notification: (title, msg, icon) => notifications.push({ title, msg, icon })
    },
    localStorage: localStorageMock,
    fetch: (...args) => fetchHandler(...args),
    navigator: {
        clipboard: {
            writeText: async () => true
        }
    },
    setTimeout: (fn) => fn(),
    FormData,
    Option: OptionMock,
    Date,
    Array,
    JSON,
    String,
    Number,
    Boolean,
    RegExp,
    console
};

vm.runInNewContext(controllerSource, context, { filename: 'js/control_apps.js' });

async function runTests() {
    // 0. La lista de perfiles muestra los usuarios con sesión iniciada y deja
    // por defecto la opción dinámica del usuario que esté activo al lanzar.
    fetchHandler = async () => ({
        ok: true,
        status: 200,
        json: async () => ({
            status: 'success',
            active_user_id: 18,
            users: [
                { id: 18, name: 'Perfil principal' },
                { id: 22, name: 'Segundo perfil' }
            ]
        })
    });
    await context.window.controlAppsCargarPerfiles();
    assert.equal(elements.get('ca-launch-user').value, 'active', 'El usuario activo actual debe ser la opción inicial.');
    assert.deepEqual(elements.get('ca-launch-user').options.map(o => o.value), ['active', '18', '22'], 'Debe mostrar cada perfil con sesión iniciada para elegirlo.');
    assert.match(elements.get('ca-launch-user').options[1].text, /activo/, 'Debe marcar el perfil principal activo.');
    assert.match(elements.get('ca-launch-user-status').textContent, /2 perfiles/, 'Debe indicar cuántos perfiles están disponibles.');
    elements.get('ca-launch-user').value = '22';
    await context.window.controlAppsCargarPerfiles();
    assert.equal(elements.get('ca-launch-user').value, '22', 'Actualizar perfiles debe conservar el perfil elegido mientras siga conectado.');

    // 1. Prueba: Consulta de estado con app activa (ej. Grand Theft Auto V)
    fetchHandler = async () => ({
        ok: true,
        status: 200,
        text: async () => JSON.stringify({
            status: 'success',
            app_running: true,
            title_id: 'CUSA00419',
            name: 'Grand Theft Auto V',
            version: '01.00',
            icon: 'user/cache/biblioteca/CUSA00419.png',
            method: 'ftp_sandbox',
            read_time: '22:30:00'
        })
    });

    await context.window.controlAppsActualizarEstado(true);
    assert.equal(elements.get('ca-status-badge').textContent, 'EN PRIMER PLANO', 'Debe marcar estado en primer plano.');
    assert.equal(elements.get('ca-foreground-title').textContent, 'Grand Theft Auto V', 'Debe mostrar el nombre de la app activa.');
    assert.equal(elements.get('ca-foreground-cusa').textContent, 'CUSA00419', 'Debe mostrar el CUSA activo.');
    assert.equal(elements.get('ca-btn-cerrar-app').disabled, false, 'El botón cerrar debe estar habilitado.');

    // 2. Prueba: Consulta de estado en Menú Principal (sin app activa)
    fetchHandler = async () => ({
        ok: true,
        status: 200,
        text: async () => JSON.stringify({
            status: 'success',
            app_running: false,
            title_id: null,
            name: null,
            state: 'idle_home',
            read_time: '22:31:00'
        })
    });

    await context.window.controlAppsActualizarEstado(true);
    assert.equal(elements.get('ca-status-badge').textContent, 'MENÚ PRINCIPAL', 'Debe marcar menú principal.');
    assert.equal(elements.get('ca-foreground-cusa').textContent, '---', 'CUSA debe estar vacío.');
    assert.equal(elements.get('ca-btn-cerrar-app').disabled, true, 'El botón cerrar debe estar deshabilitado.');

    // 3. Prueba: Timeout o error de red NUNCA debe inventar que "no hay app abierta"
    fetchHandler = async () => ({
        ok: false,
        status: 504,
        text: async () => JSON.stringify({
            status: 'error',
            code: 'GATEWAY_TIMEOUT',
            message: 'Tiempo de espera agotado al conectar a la consola.'
        })
    });

    await context.window.controlAppsActualizarEstado(true);
    assert.equal(elements.get('ca-status-badge').textContent, 'ERROR DE LECTURA', 'Timeout debe reportar error de lectura.');
    assert(elements.get('ca-foreground-detail').textContent.includes('Tiempo de espera'), 'Debe mostrar el mensaje de error explícito.');
    assert.notEqual(elements.get('ca-status-badge').textContent, 'MENÚ PRINCIPAL', 'Timeout NUNCA debe convertirse en menú principal o sin app.');

    // 4. Prueba: Respuesta inválida o corrupta del servidor
    fetchHandler = async () => ({
        ok: true,
        status: 200,
        text: async () => '<html><body>502 Bad Gateway</body></html>'
    });

    await context.window.controlAppsActualizarEstado(true);
    assert.equal(elements.get('ca-status-badge').textContent, 'ERROR DE CONEXIÓN', 'Respuesta corrupta debe marcar error.');

    // 5. Prueba: Lanzamiento de aplicación válida
    let launchPayload = null;
    elements.get('ca-launch-user').value = '5';
    fetchHandler = async (url, opts) => {
        launchPayload = opts.body;
        return {
            ok: true,
            status: 200,
            text: async () => JSON.stringify({
                status: 'success',
                action: 'launch',
                title_id: 'CUSA00419',
                state: 'accepted',
                message: 'Orden de apertura aceptada.'
            })
        };
    };

    await context.window.controlAppsAbrirApp('CUSA00419', 'Grand Theft Auto V');
    assert.equal(launchPayload.get('title_id'), 'CUSA00419', 'Debe enviar el Title ID solicitado.');
    assert.equal(launchPayload.get('action'), 'launch', 'Debe enviar action launch.');
    assert.equal(launchPayload.get('user_id'), '5', 'Debe enviar el perfil seleccionado para lanzar.');

    // 6. Prueba: Rechazo de Title ID inválido en frontend
    const noticeCountBefore = notifications.length;
    await context.window.controlAppsAbrirApp('INVALID;DROP TABLE;');
    assert(notifications.length > noticeCountBefore, 'Debe alertar de identificador inválido.');

    // 7. Prueba: Modal de Cierre Forzado y protección de progreso
    // Simular que CUSA00419 está activa
    fetchHandler = async () => ({
        ok: true,
        status: 200,
        text: async () => JSON.stringify({
            status: 'success',
            app_running: true,
            title_id: 'CUSA00419',
            name: 'Grand Theft Auto V',
            version: '01.00',
            icon: '',
            method: 'ftp_sandbox'
        })
    });
    await context.window.controlAppsActualizarEstado(false);

    // Intentar cierre: debe pasar por close_graceful o modal
    fetchHandler = async (url, opts) => {
        const act = opts.body.get('action');
        if (act === 'close_graceful') {
            return {
                ok: false,
                status: 422,
                text: async () => JSON.stringify({
                    status: 'not_supported',
                    code: 'GRACEFUL_NOT_AVAILABLE',
                    message: 'Cierre normal no disponible.'
                })
            };
        }
        return {
            ok: true,
            status: 200,
            text: async () => JSON.stringify({ status: 'success' })
        };
    };

    await context.window.controlAppsIniciarCierre();
    assert(elements.get('modal-confirmar-cierre-forzado').classList.contains('flex'), 'El modal de confirmación de cierre forzado debe abrirse.');
    assert.equal(elements.get('ca-modal-target-cusa').textContent, 'CUSA00419', 'El modal debe mostrar el CUSA objetivo.');

    // Ejecutar cierre forzado con confirmación
    let closeForceBody = null;
    fetchHandler = async (url, opts) => {
        closeForceBody = opts.body;
        return {
            ok: true,
            status: 200,
            text: async () => JSON.stringify({
                status: 'success',
                action: 'close_force',
                title_id: 'CUSA00419',
                state: 'closed',
                message: 'Aplicación cerrada forzosamente.'
            })
        };
    };

    await context.window.controlAppsEjecutarCierreForzado();
    assert.equal(closeForceBody.get('action'), 'close_force', 'Debe enviar acción close_force.');
    assert.equal(closeForceBody.get('confirm_force'), '1', 'Debe incluir confirm_force=1 explícito.');

    // 8. Prueba: Catálogo de instaladas
    fetchHandler = async () => ({
        ok: true,
        status: 200,
        text: async () => JSON.stringify({
            status: 'success',
            action: 'installed_titles',
            count: 2,
            titles: [
                { id: 'CUSA00419', name: "Grand Theft Auto V's", version: '01.00', icon: 'user/cache/biblioteca/CUSA00419.png', category: 'game' },
                { id: 'CUSA00133', name: 'The Last of Us Remastered', version: '01.00', icon: '', category: 'game' }
            ]
        })
    });

    await context.window.controlAppsRecargarBiblioteca();
    assert.equal(elements.get('ca-installed-count-badge').textContent, '2 apps', 'Debe reflejar el conteo de instaladas.');
    assert.match(elements.get('ca-installed-list').innerHTML, /data-ca-launch-id="CUSA00419"/, 'Cada tarjeta debe exponer el Title ID para abrir desde el manejador delegado.');
    assert.doesNotMatch(elements.get('ca-installed-list').innerHTML, /onclick=/i, 'Las tarjetas no deben construir JavaScript inline con títulos de juegos.');
    assert.match(elements.get('ca-installed-list').innerHTML, /<img class="ca-title-icon/, 'Las carátulas disponibles deben renderizarse como imágenes reales.');

    // 9. Prueba: Copiar detalle técnico
    let copyCalled = false;
    context.navigator.clipboard.writeText = async (text) => {
        copyCalled = true;
        const parsed = JSON.parse(text);
        assert(parsed.app_control_report, 'El informe debe contener app_control_report.');
        return true;
    };
    context.window.controlAppsCopiarDetalleTecnico();
    assert.equal(copyCalled, true, 'Debe llamar al portapapeles con el reporte técnico.');

    console.log('CONTROL_APPS_CONTROLLER_TEST_OK');
}

runTests().catch(err => {
    console.error('Test fallido:', err);
    process.exit(1);
});
