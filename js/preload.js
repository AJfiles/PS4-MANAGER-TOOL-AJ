(() => {
    const $ = id => document.getElementById(id);
    let running = false;
    const jobs = [
        ['Biblioteca', () => window.bibliotecaPrecargar?.((fraction, detail) => {
            const overall = (fraction / 8) * 100;
            setProgress(overall, detail);
        })],
        ['Partidas', () => window.savesRecargar?.(false)],
        ['Limpieza', () => window.limpiezaCargar?.(false)],
        ['Almacenamiento', () => window.almacenamientoCargar?.(false)],
        ['Capturas', () => window.capturasCargar?.(false)],
        ['Parches de juego', () => window.parchesRecargar?.(false)],
        ['Plugins', () => window.pluginsRecargar?.(false)],
        ['Explorador FTP', () => typeof window.cargarRutaFtp === 'function' ? window.cargarRutaFtp('/', true) : Promise.resolve()]
    ];

    function setProgress(value, detail) {
        const progress = Math.max(0, Math.min(100, Number(value) || 0));
        const bar = $('launcher-preload-progress');
        const percent = $('launcher-preload-percent');
        const wrap = $('launcher-preload-progress-wrap');
        const role = wrap;
        if (bar) bar.style.width = `${progress.toFixed(0)}%`;
        if (percent) percent.textContent = `${progress.toFixed(0)}%`;
        if (role) role.setAttribute('aria-valuenow', String(Math.round(progress)));
        if (detail && $('launcher-preload-detail')) $('launcher-preload-detail').textContent = String(detail);
    }

    window.ghmIniciarPrecarga = async function () {
        if (running) return;
        const ip = localStorage.getItem('sebas_ip_final_libre') || '';
        if (!ip) {
            window.ps5Notification?.('Precarga', 'Conecta la PS4 o ejecuta Radar antes de precargar sus datos.', 'fa-wifi');
            return;
        }
        running = true;
        const button = $('launcher-preload-button');
        const status = $('launcher-preload-status');
        const wrap = $('launcher-preload-progress-wrap');
        if (button) { button.disabled = true; button.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i>Preparando'; }
        if (wrap) wrap.classList.remove('hidden');
        if (status) status.textContent = 'Lecturas manuales de solo consulta; puede tardar según el FTP y la cantidad de archivos.';
        setProgress(0, 'Iniciando');
        const errors = [];
        try {
            for (let index = 0; index < jobs.length; index++) {
                const [name, run] = jobs[index];
                const start = index / jobs.length * 100;
                const end = (index + 1) / jobs.length * 100;
                if (status) status.textContent = `Leyendo ${name} · ${index + 1} de ${jobs.length}`;
                setProgress(start, name);
                try {
                    const result = await run();
                    if (result === false) errors.push(name);
                } catch (error) {
                    errors.push(name);
                    if (window.console?.warn) console.warn(`[Precarga] ${name}:`, error);
                }
                setProgress(end, errors.includes(name) ? `${name}: requiere revisar` : `${name}: lectura solicitada`);
            }
            const message = errors.length
                ? `Precarga finalizada con ${errors.length} módulo(s) que requieren revisar: ${errors.join(', ')}.`
                : 'Se solicitaron las lecturas de Biblioteca, partidas, limpieza, almacenamiento, capturas, parches, plugins y FTP. Revisa los estados en cada módulo.';
            if (status) status.textContent = message;
            window.ps5Notification?.('Precarga', errors.length ? `${errors.length} módulo(s) requieren revisar su estado.` : 'Lecturas solicitadas. Los datos se muestran al abrir cada módulo.', errors.length ? 'fa-triangle-exclamation' : 'fa-circle-check');
        } finally {
            running = false;
            if (button) { button.disabled = false; button.innerHTML = '<i class="fa-solid fa-rotate mr-1"></i>Actualizar lecturas'; }
        }
    };
    window.ghmPreloadSetProgress = setProgress;
})();
