/**
 * ====================================================================
 * PS4 Manager Tools V2.1 🚀 - CONTROLADOR MAESTRO DE BIBLIOTECA
 * Mantenido por AJ · Basado en proyecto original de SeBaS · RUTA: js/biblioteca.js
 * ====================================================================
 */

let listadoJuegos = [];
let categoriasDinamicas = ['TODOS', 'JUEGOS', 'APPS'];
let vistaModo = 'grid'; 
let index3DActivo = 0;
let pillActiva = 'TODOS';
let criterioOrdenGlobal = 'nombre_az'; 
let juegoSeleccionadoLocal = null;

let startX = 0;
let currentX = 0;
let isDragging = false;
let bibliotecaAbortController = null;

function bibliotecaEscaparHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
}
function bibliotecaImagenSegura(value) {
    const url = String(value ?? '').trim();
    if (/^https:\/\/[a-z0-9.-]+(?:\/[a-z0-9._~!$&()*+,;=:@%/-]*)?(?:\?[a-z0-9._~!$&()*+,;=:@%/?-]*)?$/i.test(url)) return url;
    if (/^user\/(?:cache\/biblioteca\/[A-Z0-9._-]+\.png(?:\?v=\d+)?|portadas_custom\/[A-Z0-9._-]+\.(?:jpg|jpeg|png)(?:\?v=\d+)?)$/i.test(url)) return url;
    return '';
}
function bibliotecaClaseEstado(installed) {
    if (installed === true) return 'library-installed';
    if (installed === false) return 'library-not-installed';
    return 'library-unverified';
}
function bibliotecaResolverImagenCarrusel(image) {
    const fallback = String(image?.dataset?.fallback || '').trim();
    const current = String(image?.getAttribute?.('src') || image?.src || '').trim();
    const resolver = value => {
        try { return new URL(value, document.baseURI).href; }
        catch (_) { return value; }
    };

    if (fallback && image.dataset.fallbackAttempted !== 'true' && resolver(fallback) !== resolver(current)) {
        image.dataset.fallbackAttempted = 'true';
        image.dataset.src = fallback;
        image.src = fallback;
        return 'fallback';
    }

    const placeholder = document.createElement('div');
    placeholder.className = 'ps4-img-art library-art-placeholder flex items-center justify-center text-cyan-200/60';
    placeholder.setAttribute('role', 'img');
    placeholder.setAttribute('aria-label', String(image?.alt || ''));
    placeholder.innerHTML = '<i class="fa-solid fa-gamepad text-3xl" aria-hidden="true"></i>';
    if (typeof image?.replaceWith === 'function') image.replaceWith(placeholder);
    return 'placeholder';
}
let categoriaAEliminar = null;
let bibliotecaAutoScanEnCurso = false;
let bibliotecaAutoScanCompletado = Number(localStorage.getItem('ghm_biblioteca_ultimo_escaneo') || 0);
let carruselIgnorarClickHasta = 0;

function initBiblio() {
    const capaBib = document.getElementById('layer-biblioteca');
    if (capaBib) {
        let estabaActiva = capaBib.classList.contains('active');
        const observadorCapa = new MutationObserver(() => {
            const estaActiva = capaBib.classList.contains('active');
            if (estaActiva && !estabaActiva) {
                iniciarCargaInmediataYEscaneoSilencioso(); 
            }
            estabaActiva = estaActiva;
        });
        observadorCapa.observe(capaBib, { attributes: true, attributeFilter: ['class'] });
        if (capaBib.classList.contains('active')) { 
            iniciarCargaInmediataYEscaneoSilencioso(); 
        }
    }

    // ======== MEJORA: BÚSQUEDA AUTOMÁTICA EN TIEMPO REAL ========
    const searchInput = document.getElementById('engine-search');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            index3DActivo = 0;
            recompilarTodo();
        });
    }
    // ======== FIN MEJORA ========
}
if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', initBiblio); } 
else { initBiblio(); }

async function iniciarCargaInmediataYEscaneoSilencioso() {
    await levantarCacheLocalBiblioteca();
    ejecutarSincronizacionFantasmasFondo();
}

async function levantarCacheLocalBiblioteca() {
    try {
        let response = await fetch('api/library_biblioteca.php?action=get_cached_games');
        let json = await response.json();
        
        if (json && json.status === 'success' && json.data.length > 0) {
            listadoJuegos = json.data;
            let deFabrica = ['TODOS', 'JUEGOS', 'APPS'];
            categoriasDinamicas = [...deFabrica];
            listadoJuegos.forEach(j => {
                j.cover3d = bibliotecaImagenSegura(j.cover3d);
                if (j.tipo && !categoriasDinamicas.includes(j.tipo)) { categoriasDinamicas.push(j.tipo); }
            });
            actualizarInterfazFiltros();
        } else {
            listadoJuegos = [];
        }
        recompilarTodo();
    } catch(e) {
        recompilarTodo();
    }
}

async function ejecutarSincronizacionFantasmasFondo() {
    const ahora = Date.now();
    if (bibliotecaAutoScanEnCurso || ahora - bibliotecaAutoScanCompletado < 5 * 60 * 1000) return;
    bibliotecaAutoScanEnCurso = true;
    const estadoSync = document.getElementById('library-sync-status');
    if (estadoSync) estadoSync.innerText = 'Conectando con PS4…';
    if (bibliotecaAbortController) { bibliotecaAbortController.abort(); }
    bibliotecaAbortController = new AbortController();
    const { signal } = bibliotecaAbortController;

    const ip = localStorage.getItem('sebas_ip_final_libre') || '192.168.1.28';
    const port = localStorage.getItem('sebas_port_libre') || '2121';

    try {
        let formDataScan = new FormData();
        formDataScan.append('action', 'scan');
        formDataScan.append('host_ip', ip);
        formDataScan.append('port', port);

        let responseScan = await fetch('api/library_biblioteca.php', { method: 'POST', body: formDataScan, signal: signal });
        let jsonScan = await responseScan.json();
        if (jsonScan.status !== 'success' || !jsonScan.games) {
            if (estadoSync) estadoSync.innerText = 'No se pudo consultar la biblioteca.';
            return;
        }
        bibliotecaAutoScanCompletado = Date.now();
        localStorage.setItem('ghm_biblioteca_ultimo_escaneo', String(bibliotecaAutoScanCompletado));

        const mapJuegosDetectados = jsonScan.games;
        const idsInstalados = jsonScan.installed_ids || {};
        const estadoInstalacion = (id) => id === 'APOLO0004' ? true :
            (jsonScan.installed_verified ? idsInstalados[id] === true : null);
        Object.keys(idsInstalados).forEach(id => {
            if (mapJuegosDetectados[id] === undefined) mapJuegosDetectados[id] = '/user/appmeta';
        });
        let colaTrabajoCUSAs = Object.keys(mapJuegosDetectados);
        const totalEscaneo = colaTrabajoCUSAs.length;
        let procesadosEscaneo = 0;
        let huboCambiosNuevos = false;
        if (estadoSync) estadoSync.innerText = totalEscaneo ? `Cargando títulos: 0/${totalEscaneo}` : 'Biblioteca actualizada.';

        // Conservamos entradas antiguas para que el usuario pueda identificarlas
        // como «No instalado»; solo una lectura no vacía permite afirmar el
        // estado. Así no se confunde una caché local con un título presente.
        if (colaTrabajoCUSAs.length > 0) {
            listadoJuegos.forEach(localG => {
                localG.installed = estadoInstalacion(localG.id);
            });
            colaTrabajoCUSAs.forEach(cusa => {
                const existente = listadoJuegos.find(game => game.id === cusa);
                if (existente) {
                    existente.installed = estadoInstalacion(cusa);
                    return;
                }
                listadoJuegos.push({
                    id: cusa,
                    nombre: cusa,
                    tipo: /^(?:LAPY|NPXS)/i.test(cusa) ? 'APPS' : 'JUEGOS',
                    version: '',
                    installed: estadoInstalacion(cusa),
                    img: '',
                    cover3d: ''
                });
                huboCambiosNuevos = true;
            });
            if (huboCambiosNuevos) recompilarTodo();
        }

        const CONCURRENCIA_MAXIMA = 3;

        while (colaTrabajoCUSAs.length > 0) {
            if (signal.aborted) break;
            let loteActual = colaTrabajoCUSAs.splice(0, CONCURRENCIA_MAXIMA);
            let promesasHilos = [];

            loteActual.forEach(cusa => {
                let fd = new FormData();
                fd.append('action', 'get_game_data');
                fd.append('host_ip', ip);
                fd.append('port', port);
                fd.append('cusa_id', cusa);
                fd.append('base_path', mapJuegosDetectados[cusa]);
                promesasHilos.push(fetch('api/library_biblioteca.php', { method: 'POST', body: fd, signal: signal }).then(r => r.json()).catch(() => null));
            });

            let respuestasLote = await Promise.all(promesasHilos);
            procesadosEscaneo += loteActual.length;

            respuestasLote.forEach(res => {
                if (res && res.status === 'success' && res.game) {
                    const game = res.game;
                    game.cover3d = bibliotecaImagenSegura(game.cover3d);
                    game.installed = estadoInstalacion(game.id);
                    const idx = listadoJuegos.findIndex(item => item.id === game.id);
                    if (idx === -1) {
                        listadoJuegos.push(game);
                        huboCambiosNuevos = true;
                    } else {
                        const previo = listadoJuegos[idx];
                        const cambioVisible = previo.nombre !== game.nombre || previo.img !== game.img || previo.tipo !== game.tipo;
                        Object.assign(previo, game, { installed: estadoInstalacion(game.id) });
                        if (cambioVisible) huboCambiosNuevos = true;
                    }
                }
            });
            if (estadoSync) estadoSync.innerText = `Cargando títulos: ${procesadosEscaneo}/${totalEscaneo}`;
        }

        // La lectura también cambia installed aunque nombre, imagen y tipo
        // permanezcan iguales. Siempre renderizamos al final para refrescar
        // bordes, insignias y conteos de instalación.
        listadoJuegos.forEach(j => { if (j.tipo && !categoriasDinamicas.includes(j.tipo)) { categoriasDinamicas.push(j.tipo); } });
        actualizarInterfazFiltros();
        recompilarTodo();
        if (estadoSync) {
            const instaladosConfirmados = jsonScan.installed_verified
                ? Object.keys(idsInstalados).length
                : null;
            estadoSync.innerText = instaladosConfirmados === null
                ? `Biblioteca actualizada · ${listadoJuegos.length} títulos · estado de instalación sin verificar`
                : `Biblioteca actualizada · ${instaladosConfirmados} instalados · ${listadoJuegos.length} títulos registrados`;
            setTimeout(() => { if (estadoSync.isConnected) estadoSync.innerText = ''; }, 5000);
        }
    } catch (errorGlobal) {
        if (estadoSync) estadoSync.innerText = 'No se pudo completar la sincronización.';
    } finally {
        bibliotecaAutoScanEnCurso = false;
    }
}

async function forzarSincronizacionManual() {
    if (bibliotecaAutoScanEnCurso) {
        window.ps5Notification('BIBLIOTECA', 'La actualización automática ya está consultando la consola. Evité duplicar la sincronización.', 'fa-rotate');
        return;
    }
    const modal = document.getElementById('modal-sincronizacion-progreso');
    const title = document.getElementById('sinc-modal-title');
    const text = document.getElementById('sinc-modal-text');
    const bar = document.getElementById('sinc-modal-bar');
    const pctLabel = document.getElementById('sinc-modal-percentage');
    const bytesLabel = document.getElementById('sinc-modal-bytes');

    if (modal) { modal.classList.remove('hidden'); setTimeout(() => { modal.classList.add('opacity-100'); }, 10); }
    if (title) title.innerText = "Sincronizando";
    if (bar) bar.style.width = "0%";
    if (pctLabel) pctLabel.innerText = "0%";
    if (bytesLabel) bytesLabel.innerText = "Conectando con la consola...";

    if (bibliotecaAbortController) { bibliotecaAbortController.abort(); }
    bibliotecaAbortController = new AbortController();
    const { signal } = bibliotecaAbortController;

    const ip = localStorage.getItem('sebas_ip_final_libre') || '192.168.1.28';
    const port = localStorage.getItem('sebas_port_libre') || '2121';

    try {
        let fdScan = new FormData();
        fdScan.append('action', 'scan');
        fdScan.append('host_ip', ip);
        fdScan.append('port', port);

        let resScan = await fetch('api/library_biblioteca.php', { method: 'POST', body: fdScan, signal: signal });
        let jsonScan = await resScan.json();
        if (jsonScan.status !== 'success' || !jsonScan.games) {
            if (bytesLabel) bytesLabel.innerText = jsonScan.message || 'No se pudo leer la lista FTP; conservé la biblioteca actual.';
            setTimeout(closeSincronizacionModal, 1800);
            return;
        }

        if (bytesLabel) bytesLabel.innerText = "Leyendo lista de juegos y aplicaciones...";

        let mapJuegos = jsonScan.games;
        const idsInstalados = jsonScan.installed_ids || {};
        const estadoInstalacion = (id) => id === 'APOLO0004' ? true :
            (jsonScan.installed_verified ? idsInstalados[id] === true : null);
        Object.keys(idsInstalados).forEach(id => {
            if (mapJuegos[id] === undefined) mapJuegos[id] = '/user/appmeta';
        });
        let colaCUSA = Object.keys(mapJuegos);
        const total = colaCUSA.length;

        if (total === 0) {
            listadoJuegos.forEach(game => { game.installed = estadoInstalacion(game.id); });
            if (bytesLabel) bytesLabel.innerText = 'La consola respondió, pero appmeta no contiene títulos. Se conserva la lista local como no instalada.';
            recompilarTodo();
            setTimeout(closeSincronizacionModal, 1800);
            return;
        }

        let procesados = 0;
        if (colaCUSA.length > 0) {
            listadoJuegos.forEach(lg => {
                lg.installed = estadoInstalacion(lg.id);
            });
            colaCUSA.forEach(cusa => {
                const existente = listadoJuegos.find(game => game.id === cusa);
                if (existente) {
                    existente.installed = estadoInstalacion(cusa);
                    return;
                }
                listadoJuegos.push({
                    id: cusa,
                    nombre: cusa,
                    tipo: /^(?:LAPY|NPXS)/i.test(cusa) ? 'APPS' : 'JUEGOS',
                    version: '',
                    installed: estadoInstalacion(cusa),
                    img: '',
                    cover3d: ''
                });
            });
            recompilarTodo();
        }

        while (colaCUSA.length > 0) {
            if (signal.aborted) break;
            let lote = colaCUSA.splice(0, 3);
            let promesas = [];

            if (text) text.innerText = `Descargando iconos y metadatos: ${lote.join(', ')}`;

            lote.forEach(cusa => {
                let f = new FormData();
                f.append('action', 'get_game_data');
                f.append('host_ip', ip);
                f.append('port', port);
                f.append('cusa_id', cusa);
                f.append('base_path', mapJuegos[cusa]);
                promesas.push(fetch('api/library_biblioteca.php', { method: 'POST', body: f, signal: signal }).then(r => r.json()).catch(() => null));
            });

            procesados += lote.length;
            if (title) title.innerText = `LEYENDO (${procesados}/${total})`;
            if (text) text.innerText = lote[lote.length - 1];

            let calcPct = (procesados / total) * 100;
            if (bar) bar.style.width = calcPct + '%';
            if (pctLabel) pctLabel.innerText = Math.round(calcPct) + '%';
            if (bytesLabel) bytesLabel.innerText = `${procesados} / ${total} títulos procesados — guardando caché local...`;

            let respuestasLote = await Promise.all(promesas);
            respuestasLote.forEach(res => {
                if (res && res.status === 'success' && res.game) {
                    const game = res.game;
                    game.cover3d = bibliotecaImagenSegura(game.cover3d);
                    game.installed = estadoInstalacion(game.id);
                    const idx = listadoJuegos.findIndex(item => item.id === game.id);
                    if (idx === -1) listadoJuegos.push(game);
                    else Object.assign(listadoJuegos[idx], game, { installed: estadoInstalacion(game.id) });
                }
            });
        }

        if (title) title.innerText = "FINALIZANDO";
        if (text) text.innerText = "Actualizando la biblioteca local...";
        if (bytesLabel) bytesLabel.innerText = jsonScan.installed_verified
            ? `Sincronización completada · ${Object.keys(idsInstalados).length} instalados · ${listadoJuegos.length} títulos registrados.`
            : 'Metadatos actualizados; estado de instalación sin verificar.';
        if (bar) bar.style.width = "100%";
        if (!jsonScan.installed_verified && bytesLabel) {
            bytesLabel.innerText = 'Metadatos actualizados; FTP no permitió verificar qué títulos siguen instalados.';
        }
        setTimeout(closeSincronizacionModal, jsonScan.installed_verified ? 1400 : 2200);
        listadoJuegos.forEach(j => { if (j.tipo && !categoriasDinamicas.includes(j.tipo)) { categoriasDinamicas.push(j.tipo); } });
        actualizarInterfazFiltros();
        recompilarTodo();
        bibliotecaAutoScanCompletado = Date.now();
        localStorage.setItem('ghm_biblioteca_ultimo_escaneo', String(bibliotecaAutoScanCompletado));
    } catch (e) {
        closeSincronizacionModal(); 
        levantarCacheLocalBiblioteca();
    }
}

function aborpZELkorMwhkkpShF5PvT8YnqRCupnLTNG() { if (bibliotecaAbortController) { bibliotecaAbortController.abort(); } closeSincronizacionModal(); }
function closeSincronizacionModal() { const modal = document.getElementById('modal-sincronizacion-progreso'); if (modal) { modal.classList.remove('opacity-100'); setTimeout(()=>modal.classList.add('hidden'), 300); } }

window.cerrarFichaJuego = function() {
    const modal = document.getElementById('sheet-detalles-juego');
    const contenido = document.getElementById('sheet-content-card');
    if (!modal) return;
    modal.classList.remove('opacity-100');
    if (contenido) contenido.classList.add('scale-95');
    setTimeout(() => modal.classList.add('hidden'), 300);
};

function aplicarOrdenamientoInterno() {
    if (criterioOrdenGlobal === 'nombre_az') {
        listadoJuegos.sort((a, b) => a.nombre.localeCompare(b.nombre));
    } else if (criterioOrdenGlobal === 'nombre_za') {
        listadoJuegos.sort((a, b) => b.nombre.localeCompare(a.nombre));
    } else if (criterioOrdenGlobal === 'recientes') {
        listadoJuegos.sort((a, b) => b.id.localeCompare(a.id));
    } else if (criterioOrdenGlobal === 'tamano') {
        listadoJuegos.sort((a, b) => {
            let sizeA = a.realSizeBytes || 0;
            let sizeB = b.realSizeBytes || 0;
            if (sizeA === sizeB) return a.nombre.localeCompare(b.nombre);
            return sizeB - sizeA;
        });
    }
}

function recompilarTodo() {
    aplicarOrdenamientoInterno();
    
    const visibles = obtenerVisiblesFiltrados();
    const badgeText = document.getElementById('badge-total-txt');
    if (badgeText) {
        const instalados = visibles.filter(game => game.installed === true).length;
        const noInstalados = visibles.filter(game => game.installed === false).length;
        const sinVerificar = visibles.length - instalados - noInstalados;
        badgeText.innerText = sinVerificar > 0
            ? `${instalados} instalados · ${noInstalados} no instalados · ${sinVerificar} sin verificar (${visibles.length})`
            : `${instalados} instalados · ${noInstalados} no instalados (${visibles.length})`;
        badgeText.title = 'Estado de instalación de los títulos visibles en este filtro';
    }

    const gridBox = document.getElementById('container-view-grid');
    const box3D = document.getElementById('container-view-3d');

    if (vistaModo === 'grid') {
        if(gridBox) gridBox.classList.remove('hidden'); 
        if(box3D) box3D.classList.add('hidden');
        renderizarGridDOM(visibles);
    } else {
        if(gridBox) gridBox.classList.add('hidden'); 
        if(box3D) box3D.classList.remove('hidden');
        construirCoverflowDOMEstatico(visibles);
    }
}

function renderizarGridDOM(visibles) {
    const gridDom = document.getElementById('dom-grid-target');
    if (!gridDom) return;
    gridDom.innerHTML = "";
    
    if (visibles.length === 0) {
        gridDom.innerHTML = `<div class="col-span-full py-12 text-center opacity-40"><p class="text-[9px] uppercase font-black tracking-widest text-gray-400">Sin títulos encontrados</p></div>`;
        return;
    }
    
    visibles.forEach((jg) => {
        const divCard = document.createElement('div');
        const noInstalado = jg.installed === false;
        const sinVerificar = jg.installed !== true && jg.installed !== false;
        divCard.className = `grid-card-modern ${bibliotecaClaseEstado(jg.installed)} rounded-[1.2rem] p-1.5 flex flex-col justify-between cursor-pointer shadow-lg animate-fade-in`;
        divCard.setAttribute('role', 'button');
        divCard.tabIndex = 0;
        divCard.setAttribute('aria-label', `Abrir ${String(jg.nombre || jg.id || '').slice(0, 160)}`);
        divCard.onclick = () => { abrirOpcionesJuegoDirecto(jg.id); };
        const cover = document.createElement('div');
        cover.className = 'w-full h-[84%] rounded-[1rem] overflow-hidden border border-white/5 bg-cover bg-center';
        const imageUrl = bibliotecaImagenSegura(jg.cover3d) || bibliotecaImagenSegura(jg.img);
        const fallbackUrl = bibliotecaImagenSegura(jg.img);
        if (imageUrl) {
            const img = document.createElement('img');
            img.src = imageUrl;
            img.alt = `Carátula de ${String(jg.nombre || jg.id || 'juego')}`;
            img.loading = 'lazy';
            img.decoding = 'async';
            img.className = 'h-full w-full object-cover';
            img.addEventListener('error', () => {
                if (fallbackUrl && img.src !== new URL(fallbackUrl, document.baseURI).href) img.src = fallbackUrl;
                else {
                    cover.replaceChildren();
                    cover.classList.add('flex', 'items-center', 'justify-center', 'bg-slate-900/70');
                    cover.innerHTML = '<i class="fa-solid fa-gamepad text-3xl text-cyan-300/60" aria-hidden="true"></i>';
                }
            }, { once: true });
            cover.appendChild(img);
        } else {
            cover.classList.add('flex', 'items-center', 'justify-center', 'bg-slate-900/70');
            cover.innerHTML = '<i class="fa-solid fa-gamepad text-3xl text-cyan-300/60" aria-hidden="true"></i>';
        }
        const title = document.createElement('p');
        title.className = 'text-[9px] font-bold text-center truncate pt-1 text-gray-300 px-0.5 uppercase tracking-wide';
        title.textContent = String(jg.nombre || jg.id || 'Título sin nombre');
        divCard.append(cover, title);
        if (noInstalado) {
            const badge = document.createElement('span');
            badge.className = 'library-status-badge';
            badge.textContent = 'No instalado';
            divCard.appendChild(badge);
        } else if (sinVerificar) {
            const badge = document.createElement('span');
            badge.className = 'library-status-badge library-status-unverified';
            badge.textContent = 'Sin verificar';
            divCard.appendChild(badge);
        }
        gridDom.appendChild(divCard);
    });
}

function construirCoverflowDOMEstatico(visibles) {
    const target3D = document.getElementById('dom-3d-target');
    if (!target3D) return;

    if (visibles.length === 0) {
        target3D.innerHTML = `
            <div class="text-center mt-10">
                <i class="fa-solid fa-triangle-exclamation text-4xl text-amber-500 mb-3"></i>
                <p class="text-[10px] text-gray-400 font-mono tracking-widest uppercase">SIN TÍTULOS EN ESTE FILTRO.</p>
            </div>`;
        juegoSeleccionadoLocal = null;
        return;
    }

    let html3D = "";
    visibles.forEach((jg, idx) => {
        const principal = bibliotecaImagenSegura(jg.cover3d) || bibliotecaImagenSegura(jg.img);
        const respaldo = bibliotecaImagenSegura(jg.img);
        const imagen = principal
            ? `<img ${idx === index3DActivo ? `src="${bibliotecaEscaparHtml(principal)}"` : ''} data-src="${bibliotecaEscaparHtml(principal)}" data-fallback="${bibliotecaEscaparHtml(respaldo)}" loading="lazy" decoding="async" fetchpriority="${idx === index3DActivo ? 'high' : 'auto'}" class="ps4-img-art" draggable="false" alt="Carátula de ${bibliotecaEscaparHtml(jg.nombre || jg.id)}">`
            : '<div class="ps4-img-art flex items-center justify-center text-cyan-200/60"><i class="fa-solid fa-gamepad text-3xl"></i></div>';
        html3D += `
        <div class="ps4-box-case ${bibliotecaClaseEstado(jg.installed)}" role="button" tabindex="0" aria-label="${bibliotecaEscaparHtml(String(jg.nombre || jg.id || 'Título'))}">
            <div class="ps4-top-ribbon">
                <i class="fa-brands fa-playstation text-white text-[10px]"></i>
                <span class="ps4-ribbon-text">PS4</span>
            </div>
            ${imagen}
            ${jg.installed === false ? '<span class="library-status-badge">No instalado</span>' : ''}
            ${jg.installed !== true && jg.installed !== false ? '<span class="library-status-badge library-status-unverified">Sin verificar</span>' : ''}
        </div>`;
    });
    target3D.innerHTML = html3D;

    target3D.querySelectorAll('img.ps4-img-art').forEach(image => {
        image.addEventListener('error', () => bibliotecaResolverImagenCarrusel(image));
    });

    const tarjetas = target3D.querySelectorAll('.ps4-box-case');
    tarjetas.forEach((card, idx) => {
        card.onclick = () => {
            if (Date.now() < carruselIgnorarClickHasta) return;
            if (idx !== index3DActivo) { index3DActivo = idx; actualizarCoverflow3DStyles(); }
            else { abrirOpcionesDesde3D(); }
        };
    });

    configurarRastreadorSwipe();
    actualizarCoverflow3DStyles();
}

function actualizarCoverflow3DStyles() {
    const target3D = document.getElementById('dom-3d-target');
    const glowBg = document.getElementById('dom-glow-target');
    if (!target3D) return;

    const visibles = obtenerVisiblesFiltrados();
    const totalCards = visibles.length;

    if (totalCards === 0) return;
    if (index3DActivo >= totalCards) index3DActivo = totalCards - 1;
    if (index3DActivo < 0) index3DActivo = 0;

    const tarjetas = target3D.querySelectorAll('.ps4-box-case');
    const desktop = document.body.classList.contains('layout-desktop');
    const pasoCarrusel = desktop ? 220 : 145;
    const pasoLateral = desktop ? 50 : 35;
    
    tarjetas.forEach((card, i) => {
        let offset = i - index3DActivo;
        if (offset < -Math.floor(totalCards / 2)) { offset += totalCards; } 
        else if (offset > Math.floor(totalCards / 2)) { offset -= totalCards; }

        const absOffset = Math.abs(offset);
        card.style.willChange = absOffset === 0 ? 'transform, opacity' : 'auto';
        card.classList.toggle('carousel-near', absOffset <= 2);
        card.classList.toggle('carousel-far', absOffset > 2);
        const cover = card.querySelector('img.ps4-img-art');
        if (cover) {
            const shouldLoad = absOffset <= 1;
            const nextSrc = shouldLoad ? (cover.dataset.src || '') : '';
            if (nextSrc && cover.dataset.loadedSrc !== nextSrc) {
                cover.dataset.loadedSrc = nextSrc;
                cover.src = nextSrc;
            } else if (!nextSrc && cover.hasAttribute('src')) {
                cover.removeAttribute('src');
                cover.dataset.loadedSrc = '';
            }
        }
        card.classList.remove('active');

        if (absOffset === 0) {
            card.classList.add('active');
            card.style.transform = `translateX(0px) translateZ(100px) rotateY(0deg) scale(1.15)`;
            card.style.opacity = '1';
            card.style.zIndex = '100';
            card.style.pointerEvents = 'auto';
            
            const currentJg = visibles[i];
            juegoSeleccionadoLocal = currentJg; 
            
            const tTitle = document.getElementById('text-title-3d');
            const tCusa = document.getElementById('text-cusa-3d');
            const tVersion = document.getElementById('text-version-3d');
            const installState = document.getElementById('text-install-state-3d');

            if (tTitle) tTitle.innerText = currentJg.nombre;
            if (tCusa) tCusa.innerText = currentJg.id;
            if (tVersion) tVersion.innerText = `v${currentJg.version || '1.00'}`;
            if (installState) {
                installState.innerText = currentJg.installed === true ? 'INSTALADO' : currentJg.installed === false ? 'NO INSTALADO' : 'ESTADO SIN VERIFICAR';
                installState.className = currentJg.installed === true
                    ? 'px-2 py-0.5 rounded border border-emerald-400/30 bg-emerald-400/10 text-[8px] font-bold text-emerald-200 tracking-wider'
                    : currentJg.installed === false
                        ? 'px-2 py-0.5 rounded border border-rose-400/30 bg-rose-400/10 text-[8px] font-bold text-rose-200 tracking-wider'
                        : 'px-2 py-0.5 rounded border border-amber-400/30 bg-amber-400/10 text-[8px] font-bold text-amber-200 tracking-wider';
            }
            if (glowBg) glowBg.style.background = `radial-gradient(circle, rgba(0, 162, 255, 0.5) 0%, rgba(0, 114, 230, 0.15) 45%, rgba(0,0,0,0) 70%)`;
        } else if (offset > 0) {
                card.style.transform = `translateX(${pasoCarrusel + (offset - 1) * pasoLateral}px) translateZ(${-30 - (offset - 1) * 30}px) rotateY(-30deg) scale(0.88)`;
            card.style.opacity = absOffset > 2 ? '0' : '0.55';
            card.style.zIndex = `${50 - absOffset}`;
            card.style.pointerEvents = absOffset === 1 ? 'auto' : 'none';
        } else {
            card.style.transform = `translateX(${-pasoCarrusel + (offset + 1) * pasoLateral}px) translateZ(${-30 - (absOffset - 1) * 30}px) rotateY(30deg) scale(0.88)`;
            card.style.opacity = absOffset > 2 ? '0' : '0.55';
            card.style.zIndex = `${50 - absOffset}`;
            card.style.pointerEvents = absOffset === 1 ? 'auto' : 'none';
        }
    });
}

function configurarRastreadorSwipe() {
    const viewPort = document.getElementById('swipe-touch-zone');
    if (!viewPort) return;
    viewPort.replaceWith(viewPort.cloneNode(true));
    const newViewPort = document.getElementById('swipe-touch-zone');

    newViewPort.addEventListener('pointerdown', (event) => {
        if (event.pointerType === 'mouse' && event.button !== 0) return;
        startX = event.clientX;
        currentX = startX;
        isDragging = true;
        if (newViewPort.setPointerCapture) newViewPort.setPointerCapture(event.pointerId);
    });
    newViewPort.addEventListener('pointermove', (event) => {
        if (!isDragging) return;
        currentX = event.clientX;
        const distancia = startX - currentX;
        const pasos = Math.trunc(Math.abs(distancia) / 68);
        if (pasos > 0) {
            moverCarruselBiblioteca((distancia > 0 ? 1 : -1) * Math.min(pasos, 4));
            startX = currentX;
            carruselIgnorarClickHasta = Date.now() + 350;
        }
    });
    const finalizarArrastre = () => { isDragging = false; };
    newViewPort.addEventListener('pointerup', finalizarArrastre);
    newViewPort.addEventListener('pointercancel', finalizarArrastre);
    newViewPort.addEventListener('wheel', (event) => {
        const delta = Math.abs(event.deltaX) > Math.abs(event.deltaY) ? event.deltaX : (event.shiftKey ? event.deltaY : 0);
        if (!delta) return;
        event.preventDefault();
        moverCarruselBiblioteca(delta > 0 ? 1 : -1);
    }, { passive: false });
}

function obtenerVisiblesFiltrados() {
    const searchInput = document.getElementById('engine-search');
    const query = searchInput ? searchInput.value.toLowerCase().trim() : "";
    return listadoJuegos.filter(j => {
        return (j.nombre.toLowerCase().includes(query) || j.id.toLowerCase().includes(query)) && 
               (pillActiva === 'TODOS' || j.tipo === pillActiva);
    });
}

function actualizarInterfazFiltros() {
    const lbl = document.getElementById('label-filtro-actual');
    if (lbl) lbl.innerText = pillActiva;
}

function abrirModalFiltro() {
    const container = document.getElementById('lista-filtros-custom');
    if (!container) return;
    container.innerHTML = "";
    
    categoriasDinamicas.forEach(cat => {
        const isActive = (cat === pillActiva);
        const iconHtml = isActive 
            ? `<i class="fa-solid fa-circle-check text-cyan-400 text-lg"></i>` 
            : `<i class="fa-regular fa-circle text-gray-600 text-lg"></i>`;
            
        const divOption = document.createElement('div');
        divOption.className = `flex items-center justify-between p-3.5 rounded-xl cursor-pointer transition-all active:scale-[0.98] ${isActive ? 'bg-cyan-500/10 border border-cyan-500/30' : 'bg-[#111827] border border-white/5 hover:bg-white/5'}`;
        divOption.onclick = () => { seleccionarFiltro(cat); };
        
        let deleteBtnHtml = '';
        if (!['TODOS', 'JUEGOS', 'APPS'].includes(cat)) {
            deleteBtnHtml = `<button onclick="event.stopPropagation(); confirmarEliminarCategoria('${cat}');" class="w-7 h-7 rounded-full bg-red-500/10 text-red-400 flex items-center justify-center mr-3 hover:bg-red-500/20 active:scale-90 transition-all border border-red-500/20"><i class="fa-solid fa-xmark text-[10px]"></i></button>`;
        }

        divOption.innerHTML = `
            <div class="flex items-center flex-1 overflow-hidden">
                <span class="text-[12px] font-black uppercase tracking-widest ${isActive ? 'text-cyan-400' : 'text-gray-300'} truncate">${cat}</span>
            </div>
            <div class="flex items-center shrink-0">
                ${deleteBtnHtml}
                ${iconHtml}
            </div>
        `;
        container.appendChild(divOption);
    });

    const modal = document.getElementById('modal-filtro-categorias');
    if (modal) {
        modal.classList.remove('hidden');
        setTimeout(() => { 
            modal.classList.add('opacity-100'); 
            document.getElementById('modal-filtro-content').classList.remove('translate-y-full'); 
        }, 10);
    }
}

function cerrarModalFiltro(e) {
    if (e && e.target !== document.getElementById('modal-filtro-categorias')) return;
    document.getElementById('modal-filtro-content').classList.add('translate-y-full');
    const modal = document.getElementById('modal-filtro-categorias');
    if (modal) { modal.classList.remove('opacity-100'); setTimeout(() => modal.classList.add('hidden'), 300); }
}

function seleccionarFiltro(tipo) {
    pillActiva = tipo;
    index3DActivo = 0;
    actualizarInterfazFiltros();
    cerrarModalFiltro();
    recompilarTodo();
}

function abrirModalOrden() {
    const container = document.getElementById('lista-orden-custom');
    if (!container) return;
    container.innerHTML = "";

    const opcionesOrden = [
        { id: 'nombre_az', label: 'Nombre A-Z', icon: 'fa-arrow-down-a-z' },
        { id: 'nombre_za', label: 'Nombre Z-A', icon: 'fa-arrow-up-z-a' },
        { id: 'recientes', label: 'Más Recientes', icon: 'fa-clock-rotate-left' },
        { id: 'tamano', label: 'Tamaño de Juego', icon: 'fa-hard-drive' }
    ];

    opcionesOrden.forEach(opt => {
        const isActive = (opt.id === criterioOrdenGlobal);
        const iconSelect = isActive 
            ? `<i class="fa-solid fa-circle-check text-emerald-400 text-lg"></i>` 
            : `<i class="fa-regular fa-circle text-gray-600 text-lg"></i>`;
            
        const divOption = document.createElement('div');
        divOption.className = `flex items-center justify-between p-4 rounded-xl cursor-pointer transition-all active:scale-[0.98] ${isActive ? 'bg-emerald-500/10 border border-emerald-500/30' : 'bg-[#111827] border border-white/5 hover:bg-white/5'}`;
        divOption.onclick = () => { seleccionarOrden(opt.id, opt.label, opt.icon); };
        
        divOption.innerHTML = `
            <div class="flex items-center gap-3">
                <i class="fa-solid ${opt.icon} ${isActive ? 'text-emerald-400' : 'text-gray-500'}"></i>
                <span class="text-[12px] font-black uppercase tracking-widest ${isActive ? 'text-emerald-400' : 'text-gray-300'}">${opt.label}</span>
            </div>
            ${iconSelect}
        `;
        container.appendChild(divOption);
    });

    const modal = document.getElementById('modal-orden-custom');
    if (modal) {
        modal.classList.remove('hidden');
        setTimeout(() => { 
            modal.classList.add('opacity-100'); 
            document.getElementById('modal-orden-content').classList.remove('translate-y-full'); 
        }, 10);
    }
}

function cerrarModalOrden(e) {
    if (e && e.target !== document.getElementById('modal-orden-custom')) return;
    document.getElementById('modal-orden-content').classList.add('translate-y-full');
    const modal = document.getElementById('modal-orden-custom');
    if (modal) { modal.classList.remove('opacity-100'); setTimeout(() => modal.classList.add('hidden'), 300); }
}

function seleccionarOrden(id, label, iconClass) {
    criterioOrdenGlobal = id;
    document.getElementById('label-orden-actual').innerText = label;
    document.getElementById('icono-orden-actual').className = `fa-solid ${iconClass} text-emerald-400 text-[10px] shrink-0`;
    index3DActivo = 0;
    cerrarModalOrden();
    recompilarTodo();
}

function confirmarEliminarCategoria(nombreCat) {
    categoriaAEliminar = nombreCat;
    const modal = document.getElementById('modal-confirmar-eliminar');
    const content = document.getElementById('modal-confirmar-content');
    const texto = document.getElementById('texto-confirmar-eliminar');
    
    texto.innerHTML = `¿Seguro que deseas eliminar la categoría <br><span class="text-white font-bold text-xs mt-1 inline-block">'${bibliotecaEscaparHtml(nombreCat)}'</span> ?`;
    
    if (modal && content) {
        modal.classList.remove('hidden');
        setTimeout(() => {
            modal.classList.add('opacity-100');
            content.classList.remove('scale-95');
            content.classList.add('scale-100');
        }, 10);
    }
}

function cerrarModalConfirmarEliminar() {
    const modal = document.getElementById('modal-confirmar-eliminar');
    const content = document.getElementById('modal-confirmar-content');
    if (modal && content) {
        modal.classList.remove('opacity-100');
        content.classList.remove('scale-100');
        content.classList.add('scale-95');
        setTimeout(() => { modal.classList.add('hidden'); categoriaAEliminar = null; }, 300);
    }
}

async function ejecutarEliminacionCategoriaPendiente() {
    if (!categoriaAEliminar) return;
    const nombreCat = categoriaAEliminar;
    cerrarModalConfirmarEliminar();

    categoriasDinamicas = categoriasDinamicas.filter(c => c !== nombreCat);
    if (pillActiva === nombreCat) pillActiva = 'TODOS';
    listadoJuegos.forEach(jg => { if (jg.tipo === nombreCat) { jg.tipo = 'JUEGOS'; } });
    
    try {
        let fd = new FormData();
        fd.append('action', 'eliminar_categoria_global');
        fd.append('categoria', nombreCat);
        await fetch('api/library_biblioteca.php', { method: 'POST', body: fd });
    } catch(e) {}
    
    abrirModalFiltro(); 
    actualizarInterfazFiltros(); 
    recompilarTodo();
    window.ps5Notification("SISTEMA", `Categoría '${nombreCat}' eliminada.`, "fa-trash-can");
}

function ejecutarFiltroGlobal() { recompilarTodo(); }

function conmutarModoVista() {
    vistaModo = (vistaModo === 'grid') ? '3d' : 'grid';
    const icono = document.getElementById('icono-vista');
    if (icono) icono.className = vistaModo === '3d' ? 'fa-solid fa-table-cells text-purple-400' : 'fa-solid fa-cube text-cyan-400';
    const button = document.getElementById('btn-toggle-library-view');
    if (button) {
        button.setAttribute('aria-pressed', vistaModo === '3d' ? 'true' : 'false');
        button.setAttribute('aria-label', vistaModo === '3d' ? 'Cambiar a cuadrícula' : 'Cambiar a carrusel');
    }
    recompilarTodo();
}

function moverCarruselBiblioteca(delta) {
    const visibles = obtenerVisiblesFiltrados();
    if (!visibles.length) return;
    index3DActivo = (index3DActivo + delta % visibles.length + visibles.length) % visibles.length;
    actualizarCoverflow3DStyles();
}

async function ejecutarAccionRapidaJuego(accion) {
    if (!juegoSeleccionadoLocal) return;
    
    if (accion === 'editar_portada') {
        window.ps5Notification("MODDING", `Abriendo editor para ${juegoSeleccionadoLocal.nombre}...`, "fa-wand-magic-sparkles");
        localStorage.setItem('modding_cusa_activo', juegoSeleccionadoLocal.id);
        cerrarBottomSheet(null);
        
        setTimeout(() => { 
            if (typeof abrirModuloNativo === 'function') {
                abrirModuloNativo('modding');
            } else {
                abrirModulo('modding'); 
            }
        }, 300);
    }else if (accion === 'dlcs') { 
        cerrarBottomSheet(null);
        abrirModalDLC();
    }
    else if (accion === 'quitar') { 
        if(confirm(`¿Quitar ${juegoSeleccionadoLocal.nombre} de la biblioteca?`)) window.ps5Notification("SISTEMA", `Eliminado.`, "fa-trash-can"); 
        cerrarBottomSheet(null);
    }
}

function abrirOpcionesDesde3D() {
    abrirOpcionesJuegoDirecto(juegoSeleccionadoLocal.id);
}

function abrirOpcionesJuegoDirecto(id) {
    juegoSeleccionadoLocal = listadoJuegos.find(j => j.id === id);
    if (!juegoSeleccionadoLocal) return;
    
    document.getElementById('panel-game-title').innerText = juegoSeleccionadoLocal.nombre;
    document.getElementById('panel-game-cusa').innerText = juegoSeleccionadoLocal.id;
    document.getElementById('panel-game-version').innerText = `v${juegoSeleccionadoLocal.version}`;
    
    const labelCat = document.getElementById('label-categoria-actual');
    if (labelCat) labelCat.innerText = juegoSeleccionadoLocal.tipo;
    
    
    const panelBg = document.getElementById('panel-bg-blur');
    const cover = bibliotecaImagenSegura(juegoSeleccionadoLocal.cover3d) || bibliotecaImagenSegura(juegoSeleccionadoLocal.img);
    if(panelBg) panelBg.style.backgroundImage = cover ? `url("${cover.replace(/["\\]/g, '\\$&')}")` : '';

    const avatarArt = document.getElementById('panel-avatar-art');
    if (avatarArt) avatarArt.style.backgroundImage = cover ? `url("${cover.replace(/["\\]/g, '\\$&')}")` : '';
    const status = document.getElementById('panel-game-install-status');
    if (status) {
        status.textContent = juegoSeleccionadoLocal.installed === true ? 'Instalado en PS4' : juegoSeleccionadoLocal.installed === false ? 'No instalado en PS4' : 'Estado no confirmado por FTP';
        status.className = `text-[9px] font-bold ${juegoSeleccionadoLocal.installed === true ? 'text-emerald-300' : juegoSeleccionadoLocal.installed === false ? 'text-rose-300' : 'text-amber-200'}`;
    }
    const sheet = document.getElementById('sheet-detalles-juego');
    if (sheet) {
        sheet.classList.remove('hidden');
        setTimeout(() => { 
            sheet.classList.add('opacity-100'); 
            const cardContent = document.getElementById('sheet-content-card');
            if (cardContent) cardContent.classList.remove('translate-y-full'); 
        }, 10);
    }
}

function cerrarBottomSheet(e) {
    if (e && e.target !== document.getElementById('sheet-detalles-juego')) return;
    const cardContent = document.getElementById('sheet-content-card');
    if (cardContent) cardContent.classList.add('translate-y-full');
    const sheet = document.getElementById('sheet-detalles-juego');
    if (sheet) { sheet.classList.remove('opacity-100'); setTimeout(() => sheet.classList.add('hidden'), 300); }
}

function abrirModalNuevaCategoria() { 
    cerrarModalFiltro(null); 
    const m = document.getElementById('modal-nueva-categoria'); 
    if (m) { m.classList.remove('hidden'); setTimeout(() => m.classList.add('opacity-100'), 10); } 
}

function cerrarModalNuevaCategoria() { 
    const m = document.getElementById('modal-nueva-categoria'); 
    if (m) { m.classList.remove('opacity-100'); setTimeout(() => m.classList.add('hidden'), 300); } 
}

function crearCategoriaProcesar() {
    const val = document.getElementById('input-nueva-cat').value.trim().toUpperCase();
    if (val && !categoriasDinamicas.includes(val)) {
        categoriasDinamicas.push(val);
        actualizarInterfazFiltros();
        cerrarModalNuevaCategoria();
        document.getElementById('input-nueva-cat').value = "";
    }
}

function abrirSelectorCategoriasCustom() {
    const contenedorLista = document.getElementById('lista-categorias-custom-game');
    if (!contenedorLista) return;
    
    contenedorLista.innerHTML = '';
    
    categoriasDinamicas.forEach(cat => {
        const isActive = juegoSeleccionadoLocal && juegoSeleccionadoLocal.tipo === cat;
        const iconHtml = isActive 
            ? `<i class="fa-solid fa-circle-check text-cyan-400 text-lg"></i>` 
            : `<i class="fa-regular fa-circle text-gray-600 text-lg"></i>`;
            
        const divOption = document.createElement('div');
        divOption.className = `flex items-center justify-between p-4 rounded-xl cursor-pointer transition-all active:scale-[0.98] ${isActive ? 'bg-cyan-500/10 border border-cyan-500/30' : 'bg-[#111827] border border-white/5 hover:bg-white/5'}`;
        divOption.onclick = () => { seleccionarCategoriaCustomGame(cat); };
        divOption.innerHTML = `
            <span class="text-[12px] font-black uppercase tracking-widest ${isActive ? 'text-cyan-400' : 'text-gray-300'}">${cat}</span>
            ${iconHtml}
        `;
        contenedorLista.appendChild(divOption);
    });

    const modal = document.getElementById('modal-selector-categorias-custom');
    if (modal) {
        modal.classList.remove('hidden');
        setTimeout(() => { 
            modal.classList.add('opacity-100'); 
            const modContent = document.getElementById('modal-selector-categorias-content');
            if (modContent) modContent.classList.remove('translate-y-full'); 
        }, 10);
    }
}

function cerrarSelectorCategoriasCustom(e) {
    if (e && e.target !== document.getElementById('modal-selector-categorias-custom')) return;
    const modContent = document.getElementById('modal-selector-categorias-content');
    if (modContent) modContent.classList.add('translate-y-full');
    const modal = document.getElementById('modal-selector-categorias-custom');
    if (modal) { modal.classList.remove('opacity-100'); setTimeout(() => modal.classList.add('hidden'), 300); }
}

async function seleccionarCategoriaCustomGame(nuevaCat) {
    cerrarSelectorCategoriasCustom();
    if (!juegoSeleccionadoLocal || juegoSeleccionadoLocal.tipo === nuevaCat) return;
    
    const labelCat = document.getElementById('label-categoria-actual');
    if (labelCat) labelCat.innerHTML = `<i class="fa-solid fa-spinner fa-spin mr-2"></i>GUARDANDO`;
    
    try {
        let formData = new FormData();
        formData.append('action', 'cambiar_categoria');
        formData.append('cusa_id', juegoSeleccionadoLocal.id);
        formData.append('categoria', nuevaCat);

        let response = await fetch('api/library_biblioteca.php', { method: 'POST', body: formData });
        let resultado = await response.json();
        
        if (resultado && resultado.status === 'success') {
            juegoSeleccionadoLocal.tipo = nuevaCat;
            if (labelCat) labelCat.innerText = nuevaCat;
            window.ps5Notification("CATEGORÍA", `Movido a ${nuevaCat}`, "fa-folder-open");
            await iniciarCargaInmediataYEscaneoSilencioso();
        }
    } catch (err) {
        if (labelCat) labelCat.innerText = juegoSeleccionadoLocal.tipo;
        window.ps5Notification("ERROR", "No se pudo cambiar la categoría", "fa-circle-xmark");
    }
}

let contenidoAEliminarData = null; 

function abrirModalDLC() {
    if (!juegoSeleccionadoLocal) return;
    
    document.getElementById('dlc-game-title').innerText = juegoSeleccionadoLocal.nombre;
    document.getElementById('dlc-game-cusa').innerText = juegoSeleccionadoLocal.id;
    
    const panelBg = document.getElementById('dlc-bg-blur');
    if(panelBg) panelBg.style.backgroundImage = `url('${juegoSeleccionadoLocal.img}')`;

    document.getElementById('dlc-data-container').classList.add('hidden');
    document.getElementById('dlc-loader').classList.remove('hidden');

    const modal = document.getElementById('modal-dlc-update');
    if (modal) {
        modal.classList.remove('hidden');
        setTimeout(() => {
            modal.classList.add('opacity-100');
            const content = document.getElementById('modal-dlc-content');
            if (content) {
                content.classList.remove('scale-95');
                content.classList.add('scale-100');
            }
        }, 10);
    }

    escanearDLCsYUpdates(false);
}

function cerrarModalDLC() {
    const modal = document.getElementById('modal-dlc-update');
    const content = document.getElementById('modal-dlc-content');
    if (modal && content) {
        modal.classList.remove('opacity-100');
        content.classList.remove('scale-100');
        content.classList.add('scale-95');
        setTimeout(() => { modal.classList.add('hidden'); }, 300);
    }
}

function forzarRecargaDLCs() {
    document.getElementById('dlc-data-container').classList.add('hidden');
    document.getElementById('dlc-loader').classList.remove('hidden');
    escanearDLCsYUpdates(true);
}

async function escanearDLCsYUpdates(force = false) {
    const ip = localStorage.getItem('sebas_ip_final_libre') || '192.168.1.28';
    try {
        let fd = new FormData();
        fd.append('action', 'scan');
        fd.append('host_ip', ip);
        fd.append('cusa_id', juegoSeleccionadoLocal.id);
        fd.append('force', force ? '1' : '0');
        
        let res = await fetch('api/dlc_update_biblioteca.php', { method: 'POST', body: fd });
        let data = await res.json();
        
        document.getElementById('dlc-loader').classList.add('hidden');
        document.getElementById('dlc-data-container').classList.remove('hidden');
        
        if (data && data.status === 'success') {
            const updateBox = document.getElementById('dlc-update-box');
            
            if (data.update.installed) {
                const iconLoc = data.update.location.includes('Ampliado') ? '<i class="fa-solid fa-server text-emerald-500"></i>' : '<i class="fa-solid fa-hdd text-gray-400"></i>';
                updateBox.innerHTML = `
                    <div class="flex items-center flex-1 gap-4 overflow-hidden">
                        <div class="w-10 h-10 rounded-xl bg-emerald-500/10 flex items-center justify-center border border-emerald-500/20 shrink-0">
                            <i class="fa-solid fa-check text-emerald-400 text-lg"></i>
                        </div>
                        <div class="flex flex-col flex-1 truncate">
                            <span class="text-[12px] font-black uppercase tracking-widest text-emerald-400">Parche / Update</span>
                            <span class="text-[10px] text-gray-400 mt-0.5 flex items-center gap-1.5">${iconLoc} ${bibliotecaEscaparHtml(data.update.size_label)}</span>
                        </div>
                    </div>
                    <button data-delete-update="1" class="w-10 h-10 rounded-xl bg-red-500/10 flex items-center justify-center text-red-500 border border-red-500/20 active:scale-90 transition-all hover:bg-red-500/20 shrink-0">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                `;
            } else {
                updateBox.innerHTML = `
                    <div class="w-10 h-10 rounded-xl bg-white/5 flex items-center justify-center border border-white/10 shrink-0">
                        <i class="fa-solid fa-minus text-gray-500 text-lg"></i>
                    </div>
                    <div class="flex flex-col flex-1">
                        <span class="text-[12px] font-black uppercase tracking-widest text-gray-400">Sin Update</span>
                        <span class="text-[10px] text-gray-500 mt-0.5">Versión Base (v1.00)</span>
                    </div>
                `;
            }

            if (data.update.installed) {
                updateBox.querySelector('[data-delete-update]')?.addEventListener('click', () => {
                    prepararEliminacionContenido(String(data.update.path || ''), 'Update Parche');
                });
            }

            const dlcBox = document.getElementById('dlc-list-box');
            const badge = document.getElementById('dlc-count-badge');
            badge.innerText = data.dlcs.length;
            
            if (data.dlcs.length > 0) {
                let dlcHtml = '';
                data.dlcs.forEach((dlc, index) => {
                    const iconLoc = dlc.location.includes('Ampliado') ? '<i class="fa-solid fa-server text-blue-400"></i>' : '<i class="fa-solid fa-hdd text-gray-400"></i>';
                    dlcHtml += `
                    <div class="flex items-center gap-3 p-3 rounded-xl bg-[#111827] border border-white/5 hover:border-blue-500/30 transition-colors">
                        <div class="flex items-center flex-1 gap-3 overflow-hidden">
                            <div class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center border border-blue-500/20 shrink-0">
                                <i class="fa-solid fa-puzzle-piece text-blue-400 text-sm"></i>
                            </div>
                            <div class="flex flex-col flex-1 overflow-hidden">
                                <span class="text-[10px] font-black uppercase tracking-widest text-gray-200 truncate">${bibliotecaEscaparHtml(dlc.id)}</span>
                                <span class="text-[8px] text-gray-500 mt-0.5 flex items-center gap-1">${iconLoc} ${bibliotecaEscaparHtml(dlc.size_label)}</span>
                            </div>
                        </div>
                        <button data-delete-dlc-index="${index}" class="w-8 h-8 rounded-lg bg-red-500/10 flex items-center justify-center text-red-500 border border-red-500/20 active:scale-90 transition-all hover:bg-red-500/20 shrink-0">
                            <i class="fa-solid fa-trash-can text-sm"></i>
                        </button>
                    </div>`;
                });
                dlcBox.innerHTML = dlcHtml;
                dlcBox.querySelectorAll('[data-delete-dlc-index]').forEach(button => {
                    button.addEventListener('click', () => {
                        const dlc = data.dlcs[Number(button.dataset.deleteDlcIndex)];
                        if (dlc) prepararEliminacionContenido(String(dlc.path || ''), String(dlc.id || 'DLC'));
                    });
                });
            } else {
                dlcBox.innerHTML = `
                    <div class="p-4 rounded-xl border border-dashed border-white/10 text-center">
                        <span class="text-[10px] font-black tracking-widest text-gray-500 uppercase">Ningún DLC detectado</span>
                    </div>
                `;
            }

        } else {
            window.ps5Notification("ERROR", "Fallo al leer la consola", "fa-triangle-exclamation");
        }
    } catch(e) {
        document.getElementById('dlc-loader').classList.add('hidden');
        window.ps5Notification("ERROR", "No hay conexión con FTP", "fa-wifi");
    }
}

function prepararEliminacionContenido(path, nombreVisual) {
    contenidoAEliminarData = { path: path, nombre: nombreVisual };
    document.getElementById('nombre-contenido-eliminar').innerText = nombreVisual;
    
    const modal = document.getElementById('modal-confirmar-eliminar-contenido');
    const content = document.getElementById('modal-content-eliminar-contenido');
    
    if (modal && content) {
        modal.classList.remove('hidden');
        setTimeout(() => {
            modal.classList.add('opacity-100');
            content.classList.remove('scale-95');
            content.classList.add('scale-100');
        }, 10);
    }
}

function cerrarModalEliminarContenido() {
    const modal = document.getElementById('modal-confirmar-eliminar-contenido');
    const content = document.getElementById('modal-content-eliminar-contenido');
    if (modal && content) {
        modal.classList.remove('opacity-100');
        content.classList.remove('scale-100');
        content.classList.add('scale-95');
        setTimeout(() => { 
            modal.classList.add('hidden'); 
            contenidoAEliminarData = null; 
            const iconBtn = document.getElementById('icono-btn-eliminar');
            if (iconBtn) iconBtn.className = "fa-solid fa-skull";
        }, 300);
    }
}

async function ejecutarEliminacionContenidoFTP() {
    if (!contenidoAEliminarData) return;
    
    const iconBtn = document.getElementById('icono-btn-eliminar');
    if (iconBtn) iconBtn.className = "fa-solid fa-spinner fa-spin";

    const ip = localStorage.getItem('sebas_ip_final_libre') || '192.168.1.28';
    
    try {
        let fd = new FormData();
        fd.append('action', 'delete_content');
        fd.append('host_ip', ip);
        fd.append('cusa_id', juegoSeleccionadoLocal.id);
        fd.append('target_path', contenidoAEliminarData.path);
        
        let res = await fetch('api/dlc_update_biblioteca.php', { method: 'POST', body: fd });
        let data = await res.json();
        
        cerrarModalEliminarContenido();
        
        if (data && data.status === 'success') {
            window.ps5Notification("SISTEMA", `Contenido eliminado con éxito de la consola.`, "fa-trash-can");
            document.getElementById('dlc-data-container').classList.add('hidden');
            document.getElementById('dlc-loader').classList.remove('hidden');
            setTimeout(() => { escanearDLCsYUpdates(true); }, 500); 
        } else {
            window.ps5Notification("ERROR", "No se pudo borrar por permisos de FTP.", "fa-circle-xmark");
        }
    } catch(e) {
        cerrarModalEliminarContenido();
        window.ps5Notification("ERROR", "Se perdió la conexión.", "fa-wifi");
    }
}
