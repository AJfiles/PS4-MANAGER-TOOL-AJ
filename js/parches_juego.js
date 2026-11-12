(() => {
    const API = 'api/patches_api.php';
    let games = [];
    const $ = id => document.getElementById(id);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[char]);
    const conn = () => ({
        host_ip: localStorage.getItem('sebas_ip_final_libre') || '',
        port: localStorage.getItem('sebas_port_libre') || '2121'
    });
    const validIpv4 = value => {
        const parts = String(value).split('.');
        return parts.length === 4 && parts.every(part => /^\d{1,3}$/.test(part) && Number(part) <= 255);
    };
    const compatibilityLabel = status => ({
        compatible: '<span class="rounded-md bg-emerald-400/10 px-2 py-1 text-emerald-200">Compatible exacto</span>',
        mismatch: '<span class="rounded-md bg-rose-400/10 px-2 py-1 text-rose-200">Versión distinta · riesgo</span>',
        pattern: '<span class="rounded-md bg-amber-400/10 px-2 py-1 text-amber-100">Búsqueda por patrón · validar</span>',
        unknown: '<span class="rounded-md bg-amber-400/10 px-2 py-1 text-amber-100">Compatibilidad desconocida</span>'
    })[status] || '<span class="rounded-md bg-amber-400/10 px-2 py-1 text-amber-100">Compatibilidad desconocida</span>';

    async function req(action, data = {}) {
        const form = new FormData();
        form.append('action', action);
        Object.entries({ ...conn(), ...data }).forEach(([key, value]) => form.append(key, String(value)));
        const response = await fetch(API, { method: 'POST', body: form, cache: 'no-store' });
        let result;
        try { result = await response.json(); }
        catch { throw new Error(`Respuesta inválida del servidor (HTTP ${response.status}). Revisa que PHP siga ejecutándose.`); }
        if (!response.ok || result.status !== 'success') throw new Error(result.message || `Error HTTP ${response.status}`);
        return result;
    }

    function note(title, message, error = false) {
        if (window.ps5Notification) window.ps5Notification(title, message, error ? 'fa-triangle-exclamation' : 'fa-circle-check');
        else alert(`${title}: ${message}`);
    }

    const savedConnection = conn();
    if ($('patch-ip')) $('patch-ip').value = savedConnection.host_ip;
    if ($('patch-port')) $('patch-port').value = savedConnection.port;

    window.parchesGuardarConexion = async function () {
        const host = $('patch-ip').value.trim();
        const port = $('patch-port').value;
        if (!validIpv4(host)) {
            note('IP no válida', 'Escribe una dirección IPv4 válida, por ejemplo 192.168.1.40.', true);
            return;
        }
        if (!['2121', '2122'].includes(port)) {
            note('Puerto no válido', 'Selecciona el puerto FTP 2121 o 2122.', true);
            return;
        }
        localStorage.setItem('sebas_ip_final_libre', host);
        localStorage.setItem('sebas_port_libre', port);
        $('patch-connection').textContent = `FTP ${host}:${port} · conectando`;
        await window.parchesRecargar();
    };

    window.parchesRecargar = async function () {
        const box = $('patch-game-list');
        const counter = $('patch-count');
        const connection = $('patch-connection');
        const current = conn();
        if (!current.host_ip) {
            games = [];
            counter.textContent = 'Sin analizar';
            connection.textContent = 'Sin IP guardada · conecta desde Radar o Transferencias';
            box.innerHTML = '<p class="text-amber-200 text-xs">No hay una IP guardada. Conecta la consola desde el Radar o Transferencias y vuelve a actualizar.</p>';
            $('patch-plugin-status').innerHTML = '';
            $('patch-no-games').classList.add('hidden');
            return;
        }

        counter.textContent = 'Buscando parches…';
        connection.textContent = `FTP ${current.host_ip}:${current.port} · consultando`;
        box.innerHTML = window.ghmLoaderMarkup
            ? window.ghmLoaderMarkup('Buscando parches', 'Leyendo juegos instalados y XML de GoldHEN por FTP.', 'violet')
            : '<p class="text-cyan-200 text-xs animate-pulse">Buscando juegos instalados y XML de parches…</p>';
        $('patch-no-games').classList.add('hidden');
        try {
            const result = await req('scan');
            games = Array.isArray(result.games) ? result.games : [];
            const requestedFilter = (localStorage.getItem('ghm_filter_patch_cusa') || '').toUpperCase();
            const filter = /^[A-Z]{4}\d{5}$/.test(requestedFilter) ? requestedFilter : '';
            $('patch-filter-clear')?.classList?.toggle('hidden', !filter);
            if (filter) games = games.filter(game => String(game.cusa || '').toUpperCase() === filter);
            counter.textContent = `${games.length} ${games.length === 1 ? 'juego' : 'juegos'} con parches disponibles`;
            connection.textContent = `FTP ${current.host_ip}:${current.port} · ${result.installed_count || 0} juegos · ${result.xml_count || 0} XML`;
            renderStatus(result);
            renderGames();
            if (!games.length) {
                const empty = $('patch-no-games');
                empty.innerHTML = '<b>No se encontraron parches disponibles para los juegos instalados.</b><br>Diagnóstico: '
                    + `${result.installed_count || 0} juegos visibles · `
                    + `${result.xml_readable ? 'directorio XML legible' : 'directorio XML inaccesible'} · `
                    + `${result.xml_count || 0} XML disponibles.<br>`
                    + 'En GoldHEN → Settings → Plugin Settings configura <b>Patch Source: Net Github</b>, '
                    + '<b>Patch Selection: All Patches</b> y <b>Plugin Source: Net Github</b>. '
                    + 'Ejecuta <b>Update patch archive</b> y <b>Update plugin archive</b>, y vuelve a Actualizar.';
                empty.classList.remove('hidden');
            }
        } catch (error) {
            games = [];
            counter.textContent = 'Error al cargar';
            connection.textContent = `FTP ${current.host_ip}:${current.port} · error`;
            $('patch-plugin-status').innerHTML = '';
            $('patch-no-games').classList.add('hidden');
            box.innerHTML = `<div class="rounded-xl border border-rose-400/20 bg-rose-400/5 p-3 text-xs text-rose-200"><b>No se pudo cargar Parches.</b><p class="mt-1">${esc(error.message)}</p><button onclick="parchesRecargar()" class="mt-2 rounded-lg bg-white/10 px-3 py-2 text-[10px] font-bold">Reintentar</button></div>`;
            note('Parches', error.message, true);
        }
    };

    window.parchesQuitarFiltroJuego = () => { localStorage.removeItem('ghm_filter_patch_cusa'); window.parchesRecargar(); };
    function renderStatus(result) {
        const status = $('patch-plugin-status');
        const messages = [];
        if (!result.ini_readable) {
            messages.push('<div class="rounded-xl border border-rose-400/20 bg-rose-400/5 p-2 text-[9px] text-rose-200">No se pudo leer plugins.ini. Los estados pueden cambiar, pero game_patch.prx no está confirmado.</div>');
        } else if (!result.plugin_in_ini) {
            messages.push('<div class="flex items-center gap-2 rounded-xl border border-amber-400/20 bg-amber-400/5 p-2 text-[9px] text-amber-100"><span class="flex-1">game_patch.prx no figura en [default] de plugins.ini.</span><button onclick="parchesActivarPlugin()" class="rounded-lg bg-amber-400 px-2 py-1.5 font-black text-black">Añadir con respaldo</button></div>');
        } else {
            messages.push('<div class="rounded-xl border border-emerald-400/20 bg-emerald-400/5 p-2 text-[9px] text-emerald-200">game_patch.prx aparece en plugins.ini.</div>');
        }
        if (!result.global_enabled) messages.push('<div class="rounded-xl border border-amber-400/20 bg-amber-400/5 p-2 text-[9px] text-amber-100">Game Patch global no aparece activo en config.ini. Verifica Plugin Settings en GoldHEN.</div>');
        status.innerHTML = messages.join('');
    }

    function renderGames() {
        $('patch-no-games').classList.toggle('hidden', games.length !== 0);
        $('patch-game-list').innerHTML = games.map(game => `<button class="patch-game w-full flex items-center gap-3 rounded-2xl border border-white/5 bg-white/[.025] p-3 text-left" data-cusa="${esc(game.cusa)}"><div class="w-12 h-12 shrink-0 overflow-hidden rounded-xl bg-violet-500/10">${game.icon ? `<img loading="lazy" class="h-full w-full object-cover" src="${esc(game.icon)}">` : '<span class="flex h-full items-center justify-center text-violet-200"><i class="fa-solid fa-gamepad"></i></span>'}</div><div class="min-w-0 flex-1"><h3 class="truncate text-xs font-black text-white">${esc(game.title)}</h3><p class="font-mono text-[9px] text-violet-300">${esc(game.cusa)} · Juego v${esc(game.game_version || 'desconocida')} · ${game.patches.length} ${game.patches.length === 1 ? 'parche' : 'parches'}</p></div><i class="fa-solid fa-chevron-right text-gray-500"></i></button>`).join('');
        document.querySelectorAll('.patch-game').forEach(button => button.onclick = () => window.parchesAbrirJuego(button.dataset.cusa));
    }

    window.parchesAbrirJuego = function (cusa) {
        const game = games.find(item => item.cusa === cusa);
        if (!game) return;
        $('patch-modal-cusa').textContent = game.cusa;
        $('patch-modal-title').textContent = game.title;
        $('patch-modal-list').innerHTML = game.patches.map(patch => `<article class="rounded-xl border border-white/5 bg-white/[.025] p-3"><div class="flex items-start gap-3"><div class="flex-1"><h4 class="text-[11px] font-bold text-white">${esc(patch.name)}</h4><p class="mt-1 text-[8px] text-gray-500">Juego v${esc(game.game_version || 'desconocida')} · objetivo ${esc(patch.app_version || 'sin versión')} · revisión ${esc(patch.patch_version)} · ${esc(patch.author)}</p><div class="mt-2 text-[8px] font-bold">${compatibilityLabel(patch.compatibility)}</div>${patch.note ? `<p class="mt-2 text-[9px] leading-relaxed text-amber-200">${esc(patch.note)}</p>` : ''}</div><button class="patch-toggle rounded-lg px-3 py-2 text-[9px] font-black ${patch.enabled ? 'bg-emerald-400/15 text-emerald-200' : 'bg-white/5 text-gray-300'}" data-key="${esc(patch.key)}">${patch.enabled ? 'ACTIVO' : 'INACTIVO'}</button></div>${patch.state_exists ? '' : '<p class="mt-2 text-[8px] text-gray-500">Estado no creado: el parche se considera apagado hasta que lo actives.</p>'}</article>`).join('');
        $('patch-modal').classList.remove('hidden');
        $('patch-modal').classList.add('flex');
        $('patch-modal-list').querySelectorAll('.patch-toggle').forEach(button => button.onclick = async () => {
            button.disabled = true;
            try {
                const selected = game.patches.find(item => item.key === button.dataset.key);
                const enabling = !selected?.enabled;
                let acceptVersionRisk = '0';
                if (enabling && selected?.compatibility !== 'compatible') {
                    const label = selected?.compatibility === 'mismatch' ? 'La versión del juego no coincide con la del parche.' : 'No se pudo confirmar una coincidencia exacta de versiones.';
                    if (!confirm(`${label}\n\nJuego: ${game.game_version || 'desconocida'}\nParche objetivo: ${selected?.app_version || 'sin versión'}\n\nPuede causar fallos o cierres del juego. ¿Activar bajo tu responsabilidad?`)) return;
                    acceptVersionRisk = '1';
                }
                const result = await req('toggle', { cusa: game.cusa, key: button.dataset.key, accept_version_risk: acceptVersionRisk });
                note('Estado de parche', result.enabled ? 'Activado' : 'Desactivado');
                await window.parchesRecargar();
                window.parchesAbrirJuego(cusa);
            } catch (error) { note('No se cambió el parche', error.message, true); }
            finally { button.disabled = false; }
        });
    };

    window.parchesCerrarJuego = function () {
        $('patch-modal').classList.add('hidden');
        $('patch-modal').classList.remove('flex');
    };

    window.parchesActivarPlugin = async function () {
        if (!confirm('Se hará una copia local de plugins.ini y se añadirá solo game_patch.prx a [default], conservando las demás líneas. Puede requerir reiniciar el juego. ¿Continuar?')) return;
        try {
            const result = await req('enable_loader');
            note('Plugin de parches', result.message);
            await window.parchesRecargar();
        } catch (error) { note('No se modificó plugins.ini', error.message, true); }
    };
})();
