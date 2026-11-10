/**
 * ====================================================================
 * PS4 Manager Tool 🚀 (PS4) - NÚCLEO DE CONTROL CENTRAL (CORE)
 * Mantenido por AJ · Basado en proyecto original de SeBaS
 * ====================================================================
 */
const CREATOR_ATTRIBUTION = "by AJ · basado en el trabajo original de SeBaS";
console.log(`%c PS4 Manager Tool - Developed ${CREATOR_ATTRIBUTION} `, "background: #111827; color: #22d3ee; font-weight: bold; padding: 4px;");

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    });
}

let globalAppConfig = {
    ipConsola: '192.168.1.28',
    portFTP: 2121,
    statusConexion: false,
    alertasActivas: true,
    sonidosActivos: true,
    volumenSfx: 0.5
};

let globalAudioCtx = null;
let ultimoEfectoAudioAt = 0;
let radarTimeoutId = null; // Para controlar el cierre automático del radar
let pwaInstallPrompt = null;
let pendingVisualPreview = null;

function notificacionNavegador(titulo, cuerpo) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    try { new Notification(titulo, { body: cuerpo, icon: 'js/icon-192.png', tag: 'goldhen-manager-aj' }); } catch (_) {}
}
window.solicitarNotificacionesNavegador = function() {
    if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
};
document.addEventListener('pointerdown', () => window.solicitarNotificacionesNavegador(), { once: true, passive: true });

const introNamesMap = {
    'none': 'Sin Intro (Rápido)',
    'intro-ps4': 'PlayStation 4',
    'intro-glitch': 'Glitch Hacker',
    'intro-ps2': 'PlayStation 2 Clásica',
    'intro-hud': 'Sci-Fi HUD',
    'intro-neon': 'Cyberpunk Neon',
    'intro-decrypt': 'Decrypt System',
    'intro-arcade': 'Retro Arcade',
    'intro-matrix-rain': 'Matrix Rain',
    'intro-crt': 'Terminal CRT (Boot)',
    'intro-gb': 'Game Boy Clásica',
    'intro-breach': 'System Breach',
    'intro-aurora': 'Aurora AJ',
    'intro-wave': 'Onda clásica',
    'intro-classic': 'Boot clásico',
    'intro-ai': 'Inicio IA',
    'intro-minimal': 'Pulso minimal'
};

document.addEventListener("DOMContentLoaded", () => {
    cargarConfiguracionesLocales();
    cambiarDisenoDispositivo(localStorage.getItem('cfg_device_layout') || 'auto');
    window.addEventListener('resize', aplicarDisenoDispositivo);
    document.querySelectorAll('.launcher-card').forEach(card => {
        card.setAttribute('role', 'button');
        card.tabIndex = 0;
    });
    aplicarPreferenciasDeAccesibilidad();
    configurarEventosDashboard();
    verificarRadarInicial();
    inicializarValoresInterfazAjustes();

    if (typeof bootSelectedIntro === 'function') {
        bootSelectedIntro();
    } else {
        const wrap = document.getElementById('intro-wrapper');
        if (wrap) wrap.style.display = 'none';
    }
});

function aplicarDisenoDispositivo() {
    const mode = localStorage.getItem('cfg_device_layout') || 'auto';
    const desktop = mode === 'desktop' || (mode === 'auto' && window.matchMedia('(min-width: 900px) and (pointer: fine)').matches);
    const cambio = document.body.classList.contains('layout-desktop') !== desktop;
    document.body.classList.toggle('layout-desktop', desktop);
    if (cambio && !localStorage.getItem('cfg_tamano_texto')) aplicarPreferenciasDeAccesibilidad();
}

function cambiarDisenoDispositivo(mode) {
    const valid = ['auto', 'mobile', 'desktop'].includes(mode) ? mode : 'auto';
    localStorage.setItem('cfg_device_layout', valid);
    aplicarDisenoDispositivo();
    const select = document.getElementById('cfg-device-layout');
    if (select && select.value !== valid) select.value = valid;
}

function objetivosNavegacionTeclado(capa) {
    if (!capa) return [];
    const selector = capa.id === 'layer-launcher'
        ? '.launcher-card'
        : 'button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), [role="button"], [tabindex="0"]';
    return Array.from(capa.querySelectorAll(selector)).filter(element => {
        const rect = element.getBoundingClientRect();
        const style = getComputedStyle(element);
        return rect.width > 0 && rect.height > 0 && style.visibility !== 'hidden' && Number(style.opacity) > 0.05;
    });
}

document.addEventListener('keydown', event => {
    if (event.altKey || event.ctrlKey || event.metaKey) return;
    const target = event.target;
    const typing = target && (target.matches('input, textarea, select') || target.isContentEditable);
    if (typing) return;

    const modal = document.querySelector('.fixed.inset-0:not(.hidden):not(.app-layer):not(#intro-wrapper)');
    if (modal) return;
    const capa = document.querySelector('.app-layer.active') || document.getElementById('layer-launcher');
    if (!capa) return;

    if (capa.id === 'layer-biblioteca' && typeof vistaModo !== 'undefined' && vistaModo === '3d' && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
        event.preventDefault();
        moverCarruselBiblioteca(event.key === 'ArrowRight' ? 1 : -1);
        return;
    }

    if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) {
        const items = objetivosNavegacionTeclado(capa);
        if (!items.length) return;
        const current = items.includes(document.activeElement) ? document.activeElement : items[0];
        const from = current.getBoundingClientRect();
        const cx = from.left + from.width / 2, cy = from.top + from.height / 2;
        const direction = event.key;
        let candidates = items.filter(item => item !== current).map(item => {
            const r = item.getBoundingClientRect(), x = r.left + r.width / 2, y = r.top + r.height / 2;
            const primary = direction === 'ArrowRight' ? x - cx : direction === 'ArrowLeft' ? cx - x : direction === 'ArrowDown' ? y - cy : cy - y;
            const cross = direction === 'ArrowLeft' || direction === 'ArrowRight' ? Math.abs(y - cy) : Math.abs(x - cx);
            return { item, primary, cross };
        }).filter(entry => entry.primary > 4);
        candidates.sort((a, b) => (a.primary + a.cross * 1.8) - (b.primary + b.cross * 1.8));
        event.preventDefault();
        (candidates[0]?.item || items[0]).focus({ preventScroll: true });
        return;
    }

    if ((event.key === 'Enter' || event.key === ' ') && target?.matches('[role="button"]') && !target.matches('button, input')) {
        event.preventDefault();
        target.click();
        return;
    }

    if (event.key === 'Escape' && capa.id !== 'layer-launcher' && typeof window.volverAlLauncher === 'function') {
        event.preventDefault();
        window.volverAlLauncher();
    }
});

function aplicarPreferenciasDeAccesibilidad() {
    const tamanoGuardado = localStorage.getItem('cfg_tamano_texto');
    const tamanoPredeterminado = document.body.classList.contains('layout-desktop') ? '120' : '100';
    const fuente = Math.max(85, Math.min(130, parseInt(tamanoGuardado || tamanoPredeterminado, 10)));
    document.documentElement.dataset.theme = 'dark';
    document.documentElement.style.fontSize = `${fuente}%`;

    const selectorFuente = document.getElementById('cfg-tamano-texto');
    const etiquetaFuente = document.getElementById('lbl-tamano-texto');
    if (selectorFuente) selectorFuente.value = fuente;
    if (etiquetaFuente) etiquetaFuente.innerText = `${fuente}%`;
}

function alternarVistaLauncher(vertical) {
    const grids = [document.getElementById('launcher-grid'), document.getElementById('launcher-preview-grid')].filter(Boolean);
    if (!grids.length) return;
    const enabled = Boolean(vertical);
    grids.forEach(grid => grid.classList.toggle('launcher-vertical', enabled));
    localStorage.setItem('cfg_launcher_vertical', enabled ? 'true' : 'false');
}

function cambiarEstiloLoader(value) {
    const allowed = ['orbit', 'pulse', 'scan', 'minimal'];
    const selected = allowed.includes(value) ? value : 'orbit';
    localStorage.setItem('cfg_loader_style', selected);
    const selector = document.getElementById('cfg-loader-style');
    if (selector && selector.value !== selected) selector.value = selected;
    if (typeof window.ghmLoaderPreview === 'function') window.ghmLoaderPreview(selected);
}

function cambiarAnimacionLauncher(motion) {
    const grids = [document.getElementById('launcher-grid'), document.getElementById('launcher-preview-grid')].filter(Boolean);
    if (!grids.length) return;
    const allowed = ['none', 'rise', 'glide', 'float', 'tilt', 'pulse'];
    const selected = allowed.includes(motion) ? motion : 'none';
    grids.forEach(grid => {
        ['motion-none', 'motion-rise', 'motion-glide', 'motion-float', 'motion-tilt', 'motion-pulse'].forEach(name => grid.classList.remove(name));
        grid.classList.add(`motion-${selected}`);
    });
    localStorage.setItem('cfg_launcher_motion', selected);
}

function cambiarTemaLauncher(theme) {
    const grids = [document.getElementById('launcher-grid'), document.getElementById('launcher-preview-grid')].filter(Boolean);
    if (!grids.length) return;
    const allowed = ['glass', 'solid', 'aurora', 'gradient'];
    const selected = allowed.includes(theme) ? theme : 'glass';
    grids.forEach(grid => {
        ['theme-glass', 'theme-solid', 'theme-aurora', 'theme-gradient'].forEach(name => grid.classList.remove(name));
        grid.classList.add(`theme-${selected}`);
    });
    localStorage.setItem('cfg_launcher_theme', selected);
}

function cambiarEstiloIconosLauncher() {
    const grids = [document.getElementById('launcher-grid'), document.getElementById('launcher-preview-grid')].filter(Boolean);
    if (!grids.length) return;
    const shape = document.getElementById('cfg-launcher-shape')?.value || localStorage.getItem('cfg_launcher_shape') || 'rounded';
    const style = document.getElementById('cfg-launcher-icons')?.value || localStorage.getItem('cfg_launcher_icons') || 'color';
    grids.forEach(grid => {
        ['shape-rounded','shape-circle','shape-square','shape-diamond','shape-pill','shape-squircle','shape-hexagon','icons-color','icons-glow','icons-outline','icons-mono'].forEach(name => grid.classList.remove(name));
        grid.classList.add(`shape-${shape}`, `icons-${style}`);
    });
    localStorage.setItem('cfg_launcher_shape', shape);
    localStorage.setItem('cfg_launcher_icons', style);
}

function toggleLauncherPreview() {
    const panel = document.getElementById('launcher-preview-panel');
    const button = document.getElementById('launcher-preview-toggle');
    if (!panel) return;
    const show = panel.classList.contains('hidden');
    panel.classList.toggle('hidden', !show);
    if (button) {
        button.setAttribute('aria-expanded', show ? 'true' : 'false');
        button.innerHTML = show ? '<i class="fa-solid fa-eye-slash mr-1"></i> Ocultar vista previa' : '<i class="fa-solid fa-eye mr-1"></i> Ver vista previa';
    }
    if (show) {
        const grid = document.getElementById('launcher-preview-grid');
        const motion = localStorage.getItem('cfg_launcher_motion') || 'none';
        if (grid && ['rise', 'glide'].includes(motion)) {
            grid.classList.remove(`motion-${motion}`);
            void grid.offsetWidth;
            grid.classList.add(`motion-${motion}`);
        }
        grid?.querySelectorAll('.launcher-card').forEach((card, index) => {
            card.style.animationDelay = `${index * 55}ms`;
        });
    }
}

function cambiarFuenteApp(font) {
    const fonts = { outfit: "'Outfit', sans-serif", system: "system-ui, -apple-system, sans-serif", arial: "Arial, Helvetica, sans-serif", mono: "'Courier New', monospace" };
    document.documentElement.style.setProperty('--app-font', fonts[font] || fonts.outfit);
    localStorage.setItem('cfg_app_font', fonts[font] ? font : 'outfit');
}

function guardarTema(valor) {
    localStorage.setItem('cfg_tema', valor);
    aplicarPreferenciasDeAccesibilidad();
}

function guardarTamanoTexto(valor) {
    localStorage.setItem('cfg_tamano_texto', valor);
    aplicarPreferenciasDeAccesibilidad();
}

function configurarVibracionMantenida() {
    let temporizador = null;
    const cancelar = () => { if (temporizador) { clearTimeout(temporizador); temporizador = null; } };
    document.addEventListener('pointerdown', (evento) => {
        if (!evento.target.closest('button, .launcher-card, [role="button"]') || !navigator.vibrate) return;
        temporizador = setTimeout(() => navigator.vibrate(18), 500);
    }, { passive: true });
    document.addEventListener('pointerup', cancelar, { passive: true });
    document.addEventListener('pointercancel', cancelar, { passive: true });
    document.addEventListener('pointerleave', cancelar, { passive: true });
}

configurarVibracionMantenida();

function cargarConfiguracionesLocales() {
    const ipGuardada = localStorage.getItem('sebas_ip_final_libre');
    const portGuardado = localStorage.getItem('sebas_port_libre') || '2121';
    
    globalAppConfig.alertasActivas = localStorage.getItem('cfg_sebas_alertas') !== 'false';
    globalAppConfig.sonidosActivos = localStorage.getItem('cfg_sebas_sonidos') !== 'false';
    
    const volGuardado = localStorage.getItem('cfg_sebas_volumen_sfx');
    globalAppConfig.volumenSfx = volGuardado ? parseFloat(volGuardado) : 0.5;
    globalAppConfig.estiloSfx = localStorage.getItem('cfg_sfx_style') || 'soft';

    if (ipGuardada) {
        globalAppConfig.ipConsola = ipGuardada;
        const inputIP = document.getElementById('ps-ip-full-input');
        if (inputIP) inputIP.value = ipGuardada;
    }

    globalAppConfig.portFTP = parseInt(portGuardado, 10);
    const inputPort = document.getElementById('ps-port-input');
    if (inputPort) inputPort.value = String(globalAppConfig.portFTP);

    const bgGuardado = localStorage.getItem('ps4_dynamic_bg') || 'bg-ps4';
    cambiarFuenteApp(localStorage.getItem('cfg_app_font') || 'outfit');
    alternarVistaLauncher(localStorage.getItem('cfg_launcher_vertical') === 'true');
    const shapeSelector = document.getElementById('cfg-launcher-shape');
    if (shapeSelector) shapeSelector.value = localStorage.getItem('cfg_launcher_shape') || 'rounded';
    const iconSelector = document.getElementById('cfg-launcher-icons');
    if (iconSelector) iconSelector.value = localStorage.getItem('cfg_launcher_icons') || 'color';
    cambiarEstiloIconosLauncher();
    const motionSelector = document.getElementById('cfg-launcher-motion');
    if (motionSelector) motionSelector.value = localStorage.getItem('cfg_launcher_motion') || 'none';
    cambiarAnimacionLauncher(motionSelector?.value || localStorage.getItem('cfg_launcher_motion') || 'none');
    const themeSelector = document.getElementById('cfg-launcher-theme');
    if (themeSelector) themeSelector.value = localStorage.getItem('cfg_launcher_theme') || 'glass';
    cambiarTemaLauncher(themeSelector?.value || localStorage.getItem('cfg_launcher_theme') || 'glass');
    const loaderSelector = document.getElementById('cfg-loader-style');
    if (loaderSelector) loaderSelector.value = localStorage.getItem('cfg_loader_style') || 'orbit';
    cambiarEstiloLoader(loaderSelector?.value || localStorage.getItem('cfg_loader_style') || 'orbit');
    if (typeof changeDynamicWallpaper === 'function') {
        setTimeout(() => changeDynamicWallpaper(bgGuardado), 300);
    }
}

function inicializarValoresInterfazAjustes() {
    setTimeout(() => {
        const swAlertas = document.getElementById('cfg-switch-notificaciones');
        if(swAlertas) swAlertas.checked = globalAppConfig.alertasActivas;

        const swSonidos = document.getElementById('cfg-switch-sonidos');
        if(swSonidos) swSonidos.checked = globalAppConfig.sonidosActivos;

        const sliderVol = document.getElementById('cfg-slider-volumen');
        const lblVol = document.getElementById('lbl-volumen-sfx');
        if(sliderVol && lblVol) {
            let valPorcentaje = Math.round(globalAppConfig.volumenSfx * 100);
            sliderVol.value = valPorcentaje;
            lblVol.innerText = valPorcentaje + '%';
        }
        const soundStyle = document.getElementById('cfg-sound-style');
        if (soundStyle) soundStyle.value = globalAppConfig.estiloSfx || 'soft';
        const fontSelect = document.getElementById('cfg-app-font');
        if (fontSelect) fontSelect.value = localStorage.getItem('cfg_app_font') || 'outfit';
        const verticalToggle = document.getElementById('cfg-launcher-vertical');
        if (verticalToggle) verticalToggle.checked = localStorage.getItem('cfg_launcher_vertical') === 'true';
        const launcherShape = document.getElementById('cfg-launcher-shape');
        if (launcherShape) launcherShape.value = localStorage.getItem('cfg_launcher_shape') || 'rounded';
        const launcherIcons = document.getElementById('cfg-launcher-icons');
        if (launcherIcons) launcherIcons.value = localStorage.getItem('cfg_launcher_icons') || 'color';
        const launcherTheme = document.getElementById('cfg-launcher-theme');
        if (launcherTheme) launcherTheme.value = localStorage.getItem('cfg_launcher_theme') || 'glass';
        const deviceLayout = document.getElementById('cfg-device-layout');
        if (deviceLayout) deviceLayout.value = localStorage.getItem('cfg_device_layout') || 'auto';
        const loaderStyle = document.getElementById('cfg-loader-style');
        if (loaderStyle) loaderStyle.value = localStorage.getItem('cfg_loader_style') || 'orbit';
        cambiarEstiloLoader(loaderStyle?.value || 'orbit');

        const selectWall = document.getElementById('custom-select-label');
        if (selectWall) {
            const bgNamesMap = { 'none': 'Apagar Fondos', 'bg-ps5': 'Órbitas PS5', 'bg-ps5-gold': 'Órbitas doradas', 'bg-ps4': 'Olas Líquidas (PS4)', 'bg-ps3': 'Ondas PS3', 'bg-ps2': 'Cubos 3D (PS2)', 'bg-ps1': 'Grid retro (PS1)', 'bg-matrix': 'Lluvia de Código (Matrix)', 'bg-starfield': 'Campo estelar', 'bg-warp': 'Velocidad Warp (Espacio)', 'bg-synth': 'Synthwave', 'bg-radar': 'Radar táctico', 'bg-sonar': 'Sonar', 'bg-plasma': 'Fluido Plasma', 'bg-network': 'Red Neuronal (Network)', 'bg-aurora': 'Aurora polar', 'bg-ocean': 'Océano nocturno', 'bg-minimal': 'Órbita minimal' };
            const bgGuardado = localStorage.getItem('ps4_dynamic_bg') || 'bg-ps4';
            selectWall.innerText = bgNamesMap[bgGuardado] || 'Olas Líquidas (PS4)';
        }

        const lblIntro = document.getElementById('custom-select-intro-label');
        if (lblIntro) {
            const introGuardada = localStorage.getItem('ps4_selected_intro') || 'none';
            lblIntro.innerText = introNamesMap[introGuardada] || 'Sin Intro (Rápido)';
        }
    }, 200);
}

function inicializarContextoAudio() {
    if (!globalAudioCtx) {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        globalAudioCtx = new AudioContext();
    }
    if (globalAudioCtx.state === 'suspended') {
        globalAudioCtx.resume();
    }
}

function emitirEfectoSonidoNativo(tipo, force = false) {
    if (!globalAppConfig.sonidosActivos || globalAppConfig.volumenSfx <= 0) return;
    const now = Date.now();
    if (!force && now - ultimoEfectoAudioAt < 45) return;
    ultimoEfectoAudioAt = now;
    try {
        inicializarContextoAudio();
        const ctx = globalAudioCtx, style = globalAppConfig.estiloSfx || 'soft';
        const presets = {
            soft: { wave:'sine', click:[520,360,.075], open:[660,990,.18] },
            console: { wave:'sine', click:[720,180,.095], open:[540,1480,.24] },
            arcade: { wave:'square', click:[440,880,.065], open:[520,1040,.2] },
            minimal: { wave:'triangle', click:[420,320,.05], open:[700,900,.12] }
        };
        const preset = presets[style] || presets.soft, values = tipo === 'ps-ui' ? preset.open : preset.click;
        const osc = ctx.createOscillator(), gain = ctx.createGain(), start = ctx.currentTime, duration = values[2];
        osc.type = preset.wave; osc.frequency.setValueAtTime(values[0], start); osc.frequency.exponentialRampToValueAtTime(values[1], start + duration);
        gain.gain.setValueAtTime(0.22 * globalAppConfig.volumenSfx, start); gain.gain.exponentialRampToValueAtTime(0.001, start + duration);
        osc.connect(gain); gain.connect(ctx.destination); osc.start(start); osc.stop(start + duration);
    } catch (e) {}
}

function cambiarEstiloSonido(style) { globalAppConfig.estiloSfx = ['soft','console','arcade','minimal'].includes(style) ? style : 'soft'; localStorage.setItem('cfg_sfx_style', globalAppConfig.estiloSfx); emitirEfectoSonidoNativo('ps-ui', true); }
function probarSonidoSeleccionado() { emitirEfectoSonidoNativo('ps-ui', true); }

function cambiarVolumenSFX(valorStr) {
    let porcentaje = parseInt(valorStr, 10);
    globalAppConfig.volumenSfx = porcentaje / 100;
    localStorage.setItem('cfg_sebas_volumen_sfx', globalAppConfig.volumenSfx.toString());
    document.getElementById('lbl-volumen-sfx').innerText = porcentaje + '%';
    emitirEfectoSonidoNativo('click');
}

function guardarAjustesNotificaciones(element) {
    globalAppConfig.alertasActivas = element.checked;
    localStorage.setItem('cfg_sebas_alertas', element.checked ? 'true' : 'false');
    emitirEfectoSonidoNativo('click');
}

function guardarAjustesSonidos(element) {
    globalAppConfig.sonidosActivos = element.checked;
    localStorage.setItem('cfg_sebas_sonidos', element.checked ? 'true' : 'false');
    if(element.checked) emitirEfectoSonidoNativo('click');
}

function cambiarEstadoModalAjuste(id, mostrar) { const modal = document.getElementById(id); if (!modal) return; modal.classList.toggle('hidden', !mostrar); modal.classList.toggle('flex', mostrar); }
function abrirSelectorFondo() { emitirEfectoSonidoNativo('click'); cambiarEstadoModalAjuste('modal-selector-fondo', true); }
function cerrarSelectorFondo() { cambiarEstadoModalAjuste('modal-selector-fondo', false); }
function abrirSelectorIntro() { emitirEfectoSonidoNativo('click'); cambiarEstadoModalAjuste('modal-selector-intro', true); }
function cerrarSelectorIntro() { cambiarEstadoModalAjuste('modal-selector-intro', false); }
/* Compatibilidad con posibles llamadas antiguas. */
function toggleCustomSelect() { abrirSelectorFondo(); }

function seleccionarFondoCustom(idFondo, nombreVisible) {
    if (pendingVisualPreview) cancelarPrevisualizacionVisual();
    pendingVisualPreview = { type: 'wallpaper', id: idFondo, name: nombreVisible };
    cerrarSelectorFondo();
    const layer = document.getElementById('layer-ajustes');
    layer?.classList.add('bg-preview-transparent');
    document.getElementById('visual-preview-actions')?.classList.remove('hidden');
    const label = document.getElementById('visual-preview-description');
    if (label) label.textContent = `Previsualizando “${nombreVisible}”. El fondo guardado no cambiará hasta que pulses Aplicar.`;
    emitirEfectoSonidoNativo('ps-ui');
    if (typeof changeDynamicWallpaper === 'function') {
        changeDynamicWallpaper(idFondo, false);
    }
}

function toggleCustomSelectIntro() { abrirSelectorIntro(); }

function seleccionarIntroCustom(idIntro, nombreVisible) {
    if (pendingVisualPreview) cancelarPrevisualizacionVisual();
    pendingVisualPreview = { type: 'intro', id: idIntro, name: nombreVisible };
    cerrarSelectorIntro();
    document.getElementById('visual-preview-actions')?.classList.remove('hidden');
    const label = document.getElementById('visual-preview-description');
    if (label) label.textContent = idIntro === 'none' ? 'Previsualización: sin animación de inicio.' : `Previsualizando “${nombreVisible}”. La intro guardada no cambiará hasta que pulses Aplicar.`;
    emitirEfectoSonidoNativo('ps-ui');
    if (typeof reproducirIntro === 'function' && idIntro !== 'none') setTimeout(() => reproducirIntro(idIntro), 80);
}

function aplicarPrevisualizacionVisual() {
    if (!pendingVisualPreview) return;
    const pending = pendingVisualPreview;
    if (pending.type === 'wallpaper') {
        localStorage.setItem('ps4_dynamic_bg', pending.id);
        document.getElementById('custom-select-label').innerText = pending.name;
        document.getElementById('layer-ajustes')?.classList.remove('bg-preview-transparent');
    } else if (pending.type === 'intro') {
        localStorage.setItem('ps4_selected_intro', pending.id);
        document.getElementById('custom-select-intro-label').innerText = pending.name;
    }
    pendingVisualPreview = null;
    document.getElementById('visual-preview-actions')?.classList.add('hidden');
    sysNotification('AJUSTES', 'Personalización guardada.', 'fa-circle-check');
}

function cancelarPrevisualizacionVisual() {
    const pending = pendingVisualPreview;
    if (!pending) return;
    if (pending.type === 'wallpaper') {
        const current = localStorage.getItem('ps4_dynamic_bg') || 'bg-ps4';
        if (typeof changeDynamicWallpaper === 'function') changeDynamicWallpaper(current, false);
        document.getElementById('layer-ajustes')?.classList.remove('bg-preview-transparent');
    } else if (pending.type === 'intro') {
        if (typeof window.cancelarIntroTemporal === 'function') window.cancelarIntroTemporal();
    }
    pendingVisualPreview = null;
    document.getElementById('visual-preview-actions')?.classList.add('hidden');
}

function configurarEventosDashboard() {
    const inputIP = document.getElementById('ps-ip-full-input');
    if (inputIP) {
        inputIP.addEventListener('change', (e) => {
            const nuevaIP = e.target.value.trim();
            if (validarEstructuraIP(nuevaIP)) {
                globalAppConfig.ipConsola = nuevaIP;
                localStorage.setItem('sebas_ip_final_libre', nuevaIP);
                notificacionNavegador('PS4 detectada', `Conectada en ${nuevaIP}.`);
                sysNotification("AJUSTES", "Dirección IP actualizada.", "fa-network-wired");
                verificarRadarInicial();
            } else {
                e.target.value = globalAppConfig.ipConsola;
                sysNotification("ERROR", "IP inválida. No se guardó.", "fa-circle-xmark");
            }
        });
    }
    document.querySelectorAll('.launcher-card').forEach(card => {
        card.addEventListener('click', () => { emitirEfectoSonidoNativo('click'); });
    });
    document.addEventListener('click', evento => {
        if (evento.target.closest('button, [role="button"], summary, a') && !evento.target.closest('input, select, textarea, label')) emitirEfectoSonidoNativo('click');
    });
}

function abrirModulo(moduloId) {
    if (typeof window.abrirModuloNativo === 'function') {
        window.abrirModuloNativo(moduloId);
    } else {
        document.querySelectorAll('.app-layer').forEach(layer => {
            layer.classList.remove('active', 'flex');
            layer.classList.add('hidden');
        });
        const target = document.getElementById('layer-' + moduloId);
        if (target) {
            target.classList.remove('hidden');
            setTimeout(() => target.classList.add('active', 'flex'), 10);
        }
    }
}

function volverAlLauncher() {
    emitirEfectoSonidoNativo('click');
    document.querySelectorAll('.app-layer').forEach(layer => {
        layer.classList.remove('active', 'flex');
        layer.classList.add('hidden');
    });
    const main = document.getElementById('layer-launcher');
    if (main) {
        main.classList.remove('hidden');
        main.classList.add('active', 'flex');
    }
}

// ============ MEJORAS: FEEDBACK VISUAL + MANEJO DE ERRORES ============

async function conectarIPManualValidando() {
    emitirEfectoSonidoNativo('click');
    const inputIP = document.getElementById('ps-ip-full-input');
    const inputPort = document.getElementById('ps-port-input');
    const btnConectar = document.querySelector('button[onclick="conectarIPManualValidando()"]');
    
    if (!inputIP) return;

    const ip = inputIP.value.trim();
    const port = inputPort ? inputPort.value.trim() : '2121';

    // Validación de IP
    if (!validarEstructuraIP(ip)) {
        sysNotification("ERROR", "IP inválida. Formato: 192.168.x.x", "fa-circle-xmark");
        return;
    }

    // Feedback visual: deshabilitar y cambiar texto
    if (btnConectar) {
        btnConectar.disabled = true;
        btnConectar.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Conectando...';
    }

    try {
        // Guardar configuración
        localStorage.setItem('sebas_ip_final_libre', ip);
        localStorage.setItem('sebas_port_libre', port);
        globalAppConfig.ipConsola = ip;
        globalAppConfig.portFTP = parseInt(port, 10);

        sysNotification("ENLACE", "Verificando conexión con la PS4...", "fa-rotate");

        // Ejecutar ping real
        await verificarRadarInicial();

        if (globalAppConfig.statusConexion) {
            sysNotification("ÉXITO", "Conexión establecida correctamente.", "fa-check-circle");
        } else {
            sysNotification("FALLO", "No se pudo contactar con la PS4. Verifica IP y puerto.", "fa-triangle-exclamation");
        }
    } catch (error) {
        sysNotification("ERROR", "Error al conectar: " + error.message, "fa-circle-xmark");
        console.error("Error en conectarIPManualValidando:", error);
    } finally {
        // Restaurar botón
        if (btnConectar) {
            btnConectar.disabled = false;
            btnConectar.innerHTML = '<i class="fa-solid fa-link text-[11px]"></i>';
        }
    }
}

// ============ PING REAL CON MANEJO DE ERRORES ============

async function verificarRadarInicial() {
    const lblStatus = document.getElementById('console-status-label');
    const pingIndicator = document.getElementById('connection-ping-indicator');

    try {
        const url = `api/scanner.php?ip=${globalAppConfig.ipConsola}&port=${globalAppConfig.portFTP}`;
        const response = await fetch(url);
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        const json = await response.json();

        if (json && json.status === 'success') {
            globalAppConfig.statusConexion = true;
            if (lblStatus) {
                lblStatus.innerText = "CONECTADO";
                lblStatus.className = "text-[9px] font-mono text-emerald-400 font-bold uppercase";
            }
            if (pingIndicator) {
                pingIndicator.className = "w-2 h-2 bg-emerald-500 rounded-full shadow-[0_0_8px_#10b981]";
            }
        } else {
            throw new Error("Respuesta inválida del servidor");
        }
    } catch (error) {
        globalAppConfig.statusConexion = false;
        if (lblStatus) {
            lblStatus.innerText = "DESCONECTADO";
            lblStatus.className = "text-[9px] font-mono text-red-400 font-bold uppercase";
        }
        if (pingIndicator) {
            pingIndicator.className = "w-2 h-2 bg-red-500 rounded-full shadow-[0_0_8px_#ef4444]";
        }
        console.warn("Error en verificarRadarInicial:", error.message);
    }
}

// ============ VALIDACIÓN DE IP ============

function validarEstructuraIP(ipString) {
    return /^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/.test(ipString);
}

// ============ SISTEMA DE NOTIFICACIONES ============

let notificationCounter = 0;
const MAX_NOTIFICATIONS = 3;

function sysNotification(titulo, mensaje, iconoClase = "fa-info-circle") {
    if (!globalAppConfig.alertasActivas) return;

    let contenedorNotificaciones = document.getElementById('sys-notification-holder');
    if (!contenedorNotificaciones) {
        contenedorNotificaciones = document.createElement('div');
        contenedorNotificaciones.id = 'sys-notification-holder';
        contenedorNotificaciones.className = "fixed top-4 right-4 z-[9999] flex flex-col gap-2 pointer-events-none";
        document.body.appendChild(contenedorNotificaciones);
    }

    // Limitar el número de notificaciones en pantalla
    const existing = contenedorNotificaciones.children;
    if (existing.length >= MAX_NOTIFICATIONS) {
        // Eliminar la más antigua
        existing[0]?.remove();
    }

    const burbuja = document.createElement('div');
    burbuja.className = "sys-toast-pill flex items-center gap-3 p-3 bg-[#0d1321]/95 backdrop-blur-md border border-white/10 rounded-2xl shadow-[0_10px_30px_rgba(0,0,0,0.5)] pointer-events-auto transform translate-x-full transition-all duration-400 cubic-bezier(0.175, 0.885, 0.32, 1.275)";
    burbuja.style.maxHeight = "65px";

    burbuja.innerHTML = `
        <div class="flex items-center justify-center w-8 h-8 rounded-xl bg-gradient-to-br from-cyan-500/20 to-purple-500/20 border border-cyan-500/30 text-cyan-400">
            <i class="fa-solid ${iconoClase} text-xs"></i>
        </div>
        <div class="flex flex-col flex-1 min-w-0">
            <span class="text-[8px] font-black tracking-widest text-cyan-400 uppercase">${titulo}</span>
            <span class="text-[9px] font-bold text-gray-200 truncate mt-0.5">${mensaje}</span>
        </div>
    `;
    contenedorNotificaciones.appendChild(burbuja);
    setTimeout(() => { burbuja.classList.remove('translate-x-full'); }, 50);
    setTimeout(() => {
        burbuja.classList.add('translate-x-full');
        burbuja.style.opacity = "0";
        setTimeout(() => { burbuja.remove(); }, 400);
    }, 4000);
}

// ============ RADAR MEJORADO ============

async function lanzarRadarVentanaEmergente() {
    // Cancelar cualquier cierre automático pendiente
    if (radarTimeoutId) {
        clearTimeout(radarTimeoutId);
        radarTimeoutId = null;
    }

    emitirEfectoSonidoNativo('ps-ui'); 
    const modal = document.getElementById('modal-radar-emergente');
    const caja = document.getElementById('radar-caja');
    const logTerm = document.getElementById('radar-log-terminal');
    const subLabel = document.getElementById('radar-subnet-txt');
    const galaxy = document.getElementById('radar-galaxy');
    if (galaxy) galaxy.classList.remove('found');

    if (!modal || !logTerm) return;

    modal.classList.remove('hidden');
    setTimeout(() => { modal.classList.add('opacity-100'); if (caja) { caja.classList.remove('scale-90'); } }, 10);

    logTerm.innerHTML = `<p class="text-emerald-600">[SYS] Iniciando búsqueda de dispositivos GoldHEN en la LAN...</p>`;
    if (subLabel) subLabel.innerText = `ANALYZING.NETWORK`;

    try {
        logTerm.innerHTML += `<p class="text-gray-500">Verificando IP guardada: ${globalAppConfig.ipConsola}...</p>`;
        logTerm.scrollTop = logTerm.scrollHeight;

        // Primero intentar con la IP guardada (rápido)
        let resCache = await fetch(`api/scanner.php?ip=${globalAppConfig.ipConsola}&port=${globalAppConfig.portFTP}`);
        let dataCache = await resCache.json();

        if (dataCache && dataCache.status === 'success') {
            if (galaxy) galaxy.classList.add('found');
            logTerm.innerHTML += `<p class="text-white font-bold bg-emerald-950/50 px-1 border border-emerald-500/20">🚀 PS4 HALLADA EN CACHÉ: ${globalAppConfig.ipConsola}</p>`;
            if (subLabel) subLabel.innerText = `LINK.ESTABLISHED`;
            await verificarRadarInicial();
            // Cerrar automáticamente después de 1.2 segundos
            radarTimeoutId = setTimeout(() => { abortarYEstabilizarRadar(); }, 1200);
            return;
        }

        logTerm.innerHTML += `<p class="text-yellow-500">Caché offline o cambio de red detectado.</p>`;
        logTerm.innerHTML += `<p class="text-emerald-400">Buscando en las interfaces LAN activas; se prueban FTP y RPI/Nova.</p>`;
        logTerm.scrollTop = logTerm.scrollHeight;

        const timeoutMs = 8000;
        let resRadar = await fetch(`api/radar_api.php?timeout=${timeoutMs}&port=${globalAppConfig.portFTP}`);
        let dataRadar = await resRadar.json();

        if (dataRadar && dataRadar.status === 'success') {
            if (subLabel) subLabel.innerText = `SCANNING.${dataRadar.segmento}`;
            logTerm.innerHTML += `<p class="text-gray-500">Buscando servicios GoldHEN en ${dataRadar.segmento}: FTP y RPI/Nova.</p>`;
            
            // Mostrar IP local detectada por el backend
            if (dataRadar.local_ip) {
                logTerm.innerHTML += `<p class="text-gray-400 text-[8px]">📡 IP Local detectada: ${dataRadar.local_ip}</p>`;
            }
            
            logTerm.scrollTop = logTerm.scrollHeight;

            if (dataRadar.ps4_ips && dataRadar.ps4_ips.length > 0) {
                if (galaxy) galaxy.classList.add('found');
                const orderedDevices = (dataRadar.devices || []).slice().sort((a, b) => {
                    const ftpA = (a.ports || []).some(port => [2121, 2122].includes(Number(port)));
                    const ftpB = (b.ports || []).some(port => [2121, 2122].includes(Number(port)));
                    return Number(ftpB) - Number(ftpA);
                });
                let nuevaIP = orderedDevices[0]?.ip || dataRadar.ps4_ips[0];
                const detectedDevice = orderedDevices.find(device => device.ip === nuevaIP);
                const puertos = detectedDevice?.ports || [];
                const ftpPort = puertos.map(Number).find(port => [2121, 2122].includes(port));
                logTerm.innerHTML += `<p class="text-white font-bold bg-emerald-950/50 px-1 border border-emerald-500/20">🎯 Dispositivo GoldHEN: ${nuevaIP} · puertos ${puertos.join(', ')}</p>`;
                
                globalAppConfig.ipConsola = nuevaIP;
                localStorage.setItem('sebas_ip_final_libre', nuevaIP);
                if (ftpPort) {
                    globalAppConfig.portFTP = ftpPort;
                    localStorage.setItem('sebas_port_libre', String(ftpPort));
                    const inputPort = document.getElementById('ps-port-input');
                    if (inputPort) inputPort.value = String(ftpPort);
                    logTerm.innerHTML += `<p class="text-emerald-300">Puerto FTP detectado y guardado: ${ftpPort}</p>`;
                } else {
                    const rpiPort = puertos.map(Number).find(port => [12800, 12801].includes(port));
                    if (rpiPort) logTerm.innerHTML += `<p class="text-emerald-300">RPI detectado en puerto ${rpiPort}; FTP no figura entre los servicios abiertos.</p>`;
                }
                const inputIP = document.getElementById('ps-ip-full-input');
                if (inputIP) inputIP.value = nuevaIP;

                await verificarRadarInicial();
                // Cerrar automáticamente después de 2 segundos
                radarTimeoutId = setTimeout(() => { abortarYEstabilizarRadar(); }, 2000);
            } else {
                logTerm.innerHTML += `<p class="text-red-400">[ERROR] No respondió ningún servicio conocido en ${dataRadar.segmento}. Revisa que PS4 y teléfono estén en la misma LAN y que GoldHEN/Package Installer esté activo.</p>`;
                if (subLabel) subLabel.innerText = `SCAN.FAILED`;
                // Cerrar automáticamente después de 3 segundos
                radarTimeoutId = setTimeout(() => { abortarYEstabilizarRadar(); }, 3000);
            }
        } else {
            logTerm.innerHTML += `<p class="text-red-500">[ERROR] ${dataRadar.message || 'Fallo de interfaz de red.'}</p>`;
            // Cerrar automáticamente después de 3 segundos
            radarTimeoutId = setTimeout(() => { abortarYEstabilizarRadar(); }, 3000);
        }
    } catch (e) {
        logTerm.innerHTML += `<p class="text-red-500">[ERROR CRÍTICO] Servidor Termux no responde o error de red.</p>`;
        sysNotification("ERROR RADAR", "No se pudo completar el escaneo. Verifica tu conexión.", "fa-circle-xmark");
        console.error("Error en lanzarRadarVentanaEmergente:", e);
        // Cerrar automáticamente después de 3 segundos
        radarTimeoutId = setTimeout(() => { abortarYEstabilizarRadar(); }, 3000);
    }
    logTerm.scrollTop = logTerm.scrollHeight;
}

function abortarYEstabilizarRadar() {
    // Limpiar el timeout programado
    if (radarTimeoutId) {
        clearTimeout(radarTimeoutId);
        radarTimeoutId = null;
    }

    const modal = document.getElementById('modal-radar-emergente');
    const caja = document.getElementById('radar-caja');
    if (modal) {
        if (caja) caja.classList.add('scale-90');
        modal.classList.remove('opacity-100');
        setTimeout(() => { modal.classList.add('hidden'); }, 300);
    }
}

// Exponer funciones globales
window.sysNotification = sysNotification;
window.abrirModulo = abrirModulo;
window.volverAlLauncher = volverAlLauncher;
window.conectarIPManualValidando = conectarIPManualValidando;

// ============ PWA INSTALL ============

let deferredPrompt;
const ghmNativeAndroidShell = /Android/i.test(navigator.userAgent)
    && (/;\s*wv\)/i.test(navigator.userAgent) || window.matchMedia('(display-mode: standalone)').matches);

function ocultarInstalacionPWAEnAPK() {
    if (!ghmNativeAndroidShell) return;
    const hide = () => { document.getElementById('btn-instalar-pwa')?.remove(); document.getElementById('btn-pwa-install')?.remove(); };
    hide();
    new MutationObserver(hide).observe(document.documentElement, { childList: true, subtree: true });
}
ocultarInstalacionPWAEnAPK();

window.addEventListener('beforeinstallprompt', (e) => {
    if (ghmNativeAndroidShell) { e.preventDefault(); return; }
    e.preventDefault();
    deferredPrompt = e;
    pwaInstallPrompt = e;

    if (!document.getElementById('btn-pwa-install') && !sessionStorage.getItem('pwa_install_prompt_seen')) {
        const btn = document.createElement('button');
        btn.id = 'btn-pwa-install';
        
        btn.className = "fixed top-4 left-4 z-[9999] max-w-[280px] bg-[#0d1321]/95 backdrop-blur-md border border-cyan-500/30 text-white text-left px-4 py-3 rounded-2xl shadow-[0_10px_30px_rgba(0,0,0,0.45)] flex items-center gap-3 active:scale-95 transition-all";
        btn.innerHTML = `<i class="fa-solid fa-mobile-screen-button text-cyan-400 text-lg"></i><span><b class="block text-[10px] tracking-widest uppercase">¿Instalar la app?</b><span class="text-[9px] text-gray-400">Acceso rápido desde tu pantalla de inicio</span></span>`;
        
        btn.onclick = async () => {
            btn.style.display = 'none';
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            if (outcome === 'accepted') {
                btn.remove(); 
            } else {
                btn.style.display = 'flex'; 
            }
            deferredPrompt = null;
        };
        setTimeout(() => {
            if (deferredPrompt) {
                sessionStorage.setItem('pwa_install_prompt_seen', 'true');
                document.body.appendChild(btn);
                setTimeout(() => btn.remove(), 9000);
            }
        }, 12000);
    }
});

window.mostrarInstalacionPWA = async function() {
    if (ghmNativeAndroidShell) return;
    if (!pwaInstallPrompt) { sysNotification('INSTALACIÓN', 'El navegador no ofrece instalación en este momento.', 'fa-mobile-screen-button'); return; }
    pwaInstallPrompt.prompt();
    await pwaInstallPrompt.userChoice;
    pwaInstallPrompt = null;
};

window.androidReleaseCandidate = null;
window.comprobarActualizacion = async function() {
    const estado = document.getElementById('update-status');
    const boton = document.getElementById('btn-aplicar-update');
    const tr = value => window.ghmTranslateText ? window.ghmTranslateText(value) : value;
    if (estado) estado.innerText = tr('Consultando GitHub…');
    try {
        if (/Android/i.test(navigator.userAgent)) {
            const fd = new FormData(); fd.append('action', 'check');
            const res = await fetch('api/android_release_api.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.status !== 'success') throw new Error(data.message || 'No se pudo comprobar la APK.');
            window.androidReleaseCandidate = data.apk_available ? data.release : null;
            if (data.update_available && data.release) {
                const release = data.release;
                const hasDigest = /^[a-f0-9]{64}$/i.test(release.sha256 || '');
                if (estado) estado.innerText = hasDigest
                    ? `${tr('APK nueva disponible')}: PS4 Manager Tools AJ ${release.version}. SHA-256: ${release.sha256}. ${tr('Android pedirá confirmar la instalación después de descargarla.')}`
                    : `${tr('Versión disponible, pero falta el SHA-256 publicado por GitHub')}: ${release.version}. ${tr('No se habilitó la descarga directa.')}`;
                if (boton) {
                    boton.disabled = !hasDigest;
                    boton.innerText = hasDigest ? 'Descargar APK' : 'Sin SHA-256';
                    boton.classList.toggle('opacity-40', !hasDigest);
                }
                return;
            }
            if (estado) estado.innerText = tr(data.message || `PS4 Manager Tools AJ ${data.current_version}: no hay actualización Android.`);
            if (boton) { boton.disabled = true; boton.innerText = 'Actualizar'; boton.classList.add('opacity-40'); }
            return;
        }

        const fd = new FormData(); fd.append('action', 'check');
        const res = await fetch('api/update_api.php', { method: 'POST', body: fd }); const data = await res.json();
        if (data.status !== 'success') throw new Error(data.message);
        if (data.update_available) { if (estado) estado.innerText = `Nueva versión disponible (${data.remote}).`; if (boton) { boton.disabled=false; boton.classList.remove('opacity-40'); } }
        else { if (estado) estado.innerText = `Ya tienes la versión más reciente (${data.current}).`; if (boton) { boton.disabled=true; boton.classList.add('opacity-40'); } }
    } catch (error) { if (estado) estado.innerText = error.message || 'No se pudo comprobar la actualización.'; }
};

window.aplicarActualizacion = async function() {
    const estado = document.getElementById('update-status');
    if (/Android/i.test(navigator.userAgent)) {
        const tr = value => window.ghmTranslateText ? window.ghmTranslateText(value) : value;
        const release = window.androidReleaseCandidate;
        if (!release || !/^[a-f0-9]{64}$/i.test(release.sha256 || '')) {
            if (estado) estado.innerText = tr('Vuelve a comprobar Releases; solo se habilitan APK con SHA-256 publicado.');
            return;
        }
        let url;
        try { url = new URL(release.asset_url); } catch (_) { url = null; }
        if (!url || url.protocol !== 'https:' || url.hostname !== 'github.com'
            || !url.pathname.startsWith('/AJfiles/PS4-MANAGER-TOOL-AJ/releases/download/')) {
            if (estado) estado.innerText = tr('El enlace de actualización no pertenece al repositorio oficial.');
            return;
        }
        if (estado) estado.innerText = `${tr('Descargando')} ${release.asset_name}. ${tr('Al terminar, abre la notificación de Android para revisar e instalar la actualización.')}`;
        const link = document.createElement('a');
        link.href = url.href;
        link.download = release.asset_name;
        link.rel = 'noopener';
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        link.remove();
        return;
    }

    if (estado) estado.innerText = 'Aplicando actualización…';
    try {
        const fd = new FormData(); fd.append('action', 'apply');
        const res = await fetch('api/update_api.php', { method: 'POST', body: fd }); const data = await res.json();
        if (data.status !== 'success') throw new Error(data.message);
        if (estado) estado.innerText = data.updated ? 'Actualización aplicada. Recargando…' : 'Ya estabas actualizado.';
        if (data.updated) setTimeout(() => window.location.reload(), 900);
    } catch (error) { if (estado) estado.innerText = error.message || 'No se pudo aplicar la actualización.'; }
};

window.addEventListener('appinstalled', () => {
    const btn = document.getElementById('btn-pwa-install');
    if (btn) btn.remove();
    if (typeof sysNotification === 'function') {
        sysNotification("SISTEMA", "¡Instalación completada! Abre la app desde tu cajón de aplicaciones.", "fa-check");
    }
});

window.ps5Notification = sysNotification;

// ============ BÚSQUEDA GLOBAL ============
let resultadosBusquedaGlobal = [];
const modulosBusquedaGlobal = [
    ['Biblioteca', 'Juegos, iconos y capturas', 'biblioteca'], ['Explorador FTP', 'Archivos de la consola', 'explorador'],
    ['Transferencias', 'Subidas y Remote Package Installer', 'transferir'], ['Modding', 'Portadas y respaldos', 'modding'],
    ['Game Mods', 'Mods compatibles', 'mods'], ['Plugins', 'Asignaciones plugins.ini', 'plugins'],
    ['Payloads', 'BinLoader GoldHEN', 'payloads'], ['Ventilador', 'Control por firmware compatible', 'coolers'], ['Actualizaciones FW', 'Bloquear o permitir', 'actualizaciones-fw'], ['Ajustes', 'Preferencias y actualización', 'ajustes'],
    ['Organizar Apps', 'Ocultar apps del sistema y agrupar aplicaciones', 'organizador-apps'],
    ['SaveGames', 'Partidas por perfil, respaldos y restauración', 'savegames'],
    ['Limpieza', 'Partidas de juegos que no aparecen instalados', 'limpieza'],
    ['Almacenamiento', 'Espacio usado por juegos, capturas y partidas', 'almacenamiento'],
    ['Capturas', 'Ver, descargar o eliminar capturas', 'capturas'],
    ['Parches de Juego', 'Estados y versiones de parches instalados', 'parches-juego'],
    ['Store', 'Catálogo autorizado', 'store']
];
function escaparBusquedaGlobal(texto) { const node = document.createElement('span'); node.textContent = String(texto || ''); return node.innerHTML; }
window.abrirBusquedaGlobal = function() { const modal=document.getElementById('modal-busqueda-global'), input=document.getElementById('global-search-input'); if(!modal||!input)return; modal.classList.remove('hidden'); modal.classList.add('flex'); input.value=''; resultadosBusquedaGlobal=[]; document.getElementById('global-search-results').innerHTML='<p class="p-3 text-center text-[10px] text-gray-500">Escribe al menos dos caracteres.</p>'; setTimeout(()=>input.focus(),30); };
window.cerrarBusquedaGlobal = function() { const modal=document.getElementById('modal-busqueda-global'); if(modal){modal.classList.add('hidden');modal.classList.remove('flex');} };
window.ejecutarBusquedaGlobal = function() {
    const term=(document.getElementById('global-search-input')?.value||'').trim().toLowerCase(), target=document.getElementById('global-search-results'); if(!target)return;
    if(term.length<2){target.innerHTML='<p class="p-3 text-center text-[10px] text-gray-500">Escribe al menos dos caracteres.</p>';return;}
    const groups=[]; const add=(grupo,items)=>{if(items.length)groups.push([grupo,items]);};
    add('Módulos',modulosBusquedaGlobal.filter(x=>(x[0]+' '+x[1]).toLowerCase().includes(term)).map(x=>({tipo:'module',valor:x[2],titulo:x[0],detalle:x[1]})));
    add('Biblioteca',(typeof listadoJuegos!=='undefined'?listadoJuegos:[]).filter(j=>`${j.nombre||''} ${j.id||''}`.toLowerCase().includes(term)).slice(0,30).map(j=>({tipo:'game',valor:j.id,titulo:j.nombre||j.id,detalle:j.id||'Juego'})));
    add('Explorador FTP',(typeof ftpCurrentItems!=='undefined'?ftpCurrentItems:[]).filter(x=>(x.name||'').toLowerCase().includes(term)).slice(0,30).map(x=>({tipo:'file',valor:x.name,titulo:x.name,detalle:'Archivo/carpeta de la ruta actual'})));
    add('Plugins',[...(typeof pluginsRemotos!=='undefined'?pluginsRemotos:[]),...(typeof pluginsLocales!=='undefined'?pluginsLocales:[])].filter(x=>(x.name||'').toLowerCase().includes(term)).slice(0,30).map(x=>({tipo:'module',valor:'plugins',titulo:x.name,detalle:'Plugin PRX'})));
    const payloadList=[...(typeof payloadsLocales!=='undefined'?payloadsLocales:[]),...(typeof payloadsRemotos!=='undefined'?payloadsRemotos:[])];
    add('Payloads',payloadList.filter(x=>(x.name||'').toLowerCase().includes(term)).slice(0,30).map(x=>({tipo:'module',valor:'payloads',titulo:x.name,detalle:'Payload'})));
    resultadosBusquedaGlobal=groups.flatMap(x=>x[1]); let index=0;
    target.innerHTML=groups.length?groups.map(([grupo,items])=>`<section><h3 class="mb-1 text-[9px] font-black uppercase tracking-widest text-violet-300">${grupo}</h3>${items.map(item=>{const i=index++;return `<button onclick="abrirResultadoBusquedaGlobal(${i})" class="mb-1 flex w-full items-center gap-3 rounded-xl bg-white/[.035] p-3 text-left hover:bg-white/[.08]"><i class="fa-solid fa-arrow-right text-[10px] text-violet-300"></i><span class="min-w-0"><b class="block truncate text-[11px] text-white">${escaparBusquedaGlobal(item.titulo)}</b><small class="block truncate text-[9px] text-gray-500">${escaparBusquedaGlobal(item.detalle)}</small></span></button>`;}).join('')}</section>`).join(''):'<p class="p-3 text-center text-[10px] text-gray-500">Sin resultados en los datos cargados.</p>';
};
window.abrirResultadoBusquedaGlobal = function(index) { const item=resultadosBusquedaGlobal[index]; if(!item)return; cerrarBusquedaGlobal(); if(item.tipo==='file'){abrirModulo('explorador');return;} if(item.valor==='store'){window.location.href='store/store.php';return;} if(item.valor==='mods'&&typeof abrirModuloNativo==='function'){abrirModuloNativo('mods');return;} abrirModulo(item.valor); };
