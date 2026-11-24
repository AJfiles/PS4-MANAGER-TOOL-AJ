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
const launchButtons = [{ disabled: false }];
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
    'modal-confirmar-cierre-forzado', 'ca-modal-target-name', 'ca-modal-target-cusa', 'ca-modal-target-icon',
    'modal-cambio-app-protegido', 'ca-switch-heading', 'ca-switch-current-name', 'ca-switch-current-id',
    'ca-switch-next-name', 'ca-switch-next-id', 'ca-switch-risk-row', 'ca-switch-risk-confirm',
    'ca-switch-wait', 'ca-switch-confirm-button'
];

requiredIds.forEach(id => createElement(id));

const documentMock = {
    getElementById: id => elements.get(id) || null,
    querySelectorAll: selector => selector === '[data-ca-launch-id]' ? launchButtons : [],
    addEventListener() {},
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
    constructor(text, value) { this.text = text; this.value = value; this.disabled = false; this.dataset = {}; }
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
    setInterval: () => 0,
    clearInterval() {},
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
    vm.runInContext('caHelperRequiresUpdate = false', context);
    // 0. No se inventa un perfil activo; solo perfiles confirmados como conectados se habilitan.
    fetchHandler = async () => ({
        ok: true,
        status: 200,
        json: async () => ({
            status: 'success',
            active_user_id: 18,
            login_state_known: true,
            users: [
                { id: 18, name: 'Perfil principal', logged_in: true, active: true },
                { id: 22, name: 'Segundo perfil', logged_in: true, active: false },
                { id: 5, name: 'Perfil de prueba', logged_in: true, active: false },
                { id: 24, name: 'Perfil cerrado', logged_in: false, active: false }
            ]
        })
    });
    await context.window.controlAppsCargarPerfiles();
    assert.equal(elements.get('ca-launch-user').value, '', 'No se debe seleccionar un perfil sin acción explícita.');
    assert.deepEqual(elements.get('ca-launch-user').options.map(o => o.value), ['', '18', '22', '5', ''], 'Debe listar perfiles reales y solo permitir los confirmados conectados.');
    assert.match(elements.get('ca-launch-user').options[1].text, /sesión activa/, 'Debe indicar qué perfil está activo.');
    assert.equal(elements.get('ca-launch-user').options[4].disabled, true, 'Un perfil sin sesión no debe ser seleccionable.');
    elements.get('ca-launch-user').value = '22';
    vm.runInContext('caHelperRequiresUpdate = true; caSetCooldown(0); controlAppsActualizarDisponibilidadLanzamiento()', context);
    assert.equal(launchButtons[0].disabled, true, 'Un helper antiguo debe bloquear la apertura aunque el perfil esté conectado.');
    assert.equal(elements.get('ca-btn-cerrar-app').disabled, true, 'Un helper antiguo debe bloquear las acciones de mutación.');
    vm.runInContext('caHelperRequiresUpdate = false; controlAppsActualizarDisponibilidadLanzamiento()', context);
    await context.window.controlAppsCargarPerfiles();
    assert.equal(elements.get('ca-launch-user').value, '22', 'Actualizar perfiles debe conservar el perfil elegido mientras siga conectado.');

    // Un cambio de app debe exigir confirmación explícita antes del cierre y describir el destino.
    elements.get('ca-launch-user').value = '22';
    fetchHandler = async () => ({
        ok: false,
        status: 409,
        text: async () => JSON.stringify({
            status: 'error',
            code: 'ACTIVE_APP_CLOSE_REQUIRED',
            active_app: { title_id: 'CUSA00419', name: 'Juego activo', icon: '' }
        })
    });
    await context.window.controlAppsAbrirApp('CUSA00133', 'Juego nuevo');
    assert(elements.get('modal-cambio-app-protegido').classList.contains('flex'), 'Debe abrirse la confirmación de cambio si otra app está activa.');
    assert.equal(elements.get('ca-switch-current-id').textContent, '(CUSA00419)');
    assert.equal(elements.get('ca-switch-next-name').textContent, 'Juego nuevo');
    assert.equal(elements.get('ca-switch-confirm-button').disabled, true, 'El cierre debe permanecer bloqueado sin marcar el riesgo.');
    elements.get('ca-switch-risk-confirm').checked = true;
    context.window.controlAppsActualizarConfirmacionCambio();
    assert.equal(elements.get('ca-switch-confirm-button').disabled, false, 'La confirmación explícita habilita el cierre para cambiar.');
    context.window.controlAppsCancelarCambioApp();

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
    vm.runInContext('caHelperRequiresUpdate = true; caSetCooldown(0)', context);
    assert.equal(elements.get('ca-btn-cerrar-app').disabled, true, 'Un helper desactualizado también debe bloquear el cierre forzado.');
    vm.runInContext('caHelperRequiresUpdate = false; caSetCooldown(0)', context);

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
    assert(elements.get('ca-foreground-detail').textContent.includes('502 Bad Gateway'), 'Una respuesta HTML/HTTP no JSON debe incluir un fragmento útil para diagnosticar HTTP 500 en Termux.');

    fetchHandler = async () => ({ ok: true, status: 200, json: async () => ({
        status: 'success', login_state_known: false, users: [{ id: 5, name: 'Perfil local', logged_in: null, active: false }]
    }) });
    await context.window.controlAppsCargarPerfiles();
    assert.equal(elements.get('ca-launch-user').value, '', 'Un perfil sin estado UserService verificado no debe seleccionarse.');
    assert.equal(elements.get('ca-launch-user').options[1].disabled, true, 'UserService no confirmado debe bloquear perfiles.');

    // 5. Prueba: Lanzamiento de aplicación válida
    let launchPayload = null;
    fetchHandler = async () => ({ ok: true, status: 200, json: async () => ({
        status: 'success', active_user_id: 18, login_state_known: true, users: [
            { id: 18, name: 'Perfil principal', logged_in: true, active: true },
            { id: 22, name: 'Segundo perfil', logged_in: true, active: false },
            { id: 5, name: 'Perfil de prueba', logged_in: true, active: false }
        ]
    }) });
    await context.window.controlAppsCargarPerfiles();
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
    vm.runInContext('caSetCooldown(0)', context);

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
