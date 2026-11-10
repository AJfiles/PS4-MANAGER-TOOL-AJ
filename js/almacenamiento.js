(() => {
    const $ = id => document.getElementById(id);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[char]);
    const formatBytes = value => {
        let bytes = Number(value) || 0;
        const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        let unit = 0;
        while (bytes >= 1024 && unit < units.length - 1) { bytes /= 1024; unit++; }
        return (bytes ? (unit ? bytes.toFixed(2) : bytes.toFixed(0)) : '0') + ' ' + units[unit];
    };
    const categories = [
        ['juegos y aplicaciones', 'Juegos / apps', 'fa-gamepad', 'emerald'],
        ['partidas', 'Partidas', 'fa-floppy-disk', 'cyan'],
        ['capturas', 'Capturas', 'fa-image', 'pink']
    ];
    const themeClasses = {
        emerald: { active: 'border-emerald-300/40 bg-emerald-400/10 text-emerald-100', icon: 'bg-emerald-500/10 text-emerald-200' },
        cyan: { active: 'border-cyan-300/40 bg-cyan-400/10 text-cyan-100', icon: 'bg-cyan-500/10 text-cyan-200' },
        pink: { active: 'border-pink-300/40 bg-pink-400/10 text-pink-100', icon: 'bg-pink-500/10 text-pink-200' }
    };
    let currentReport = null;
    let selectedCategory = 'juegos y aplicaciones';
    const loadingMarkupFallback = () => `
        <div class="mx-auto flex min-h-64 max-w-md flex-col items-center justify-center rounded-3xl border border-emerald-400/15 bg-gradient-to-b from-emerald-400/[.07] to-transparent p-6 text-center" role="status" aria-live="polite">
            <div class="relative mb-5 grid h-20 w-20 place-items-center" aria-hidden="true"><span class="absolute inset-0 rounded-full border border-emerald-300/20"></span><span class="absolute inset-1 rounded-full border-2 border-transparent border-t-emerald-300 border-r-cyan-300 animate-spin"></span><span class="absolute inset-3 rounded-full border border-dashed border-lime-200/50 animate-[spin_4s_linear_infinite]"></span><i class="fa-solid fa-hard-drive text-2xl text-emerald-100 drop-shadow-[0_0_12px_rgba(52,211,153,.7)]"></i><span class="absolute right-1 top-2 h-2 w-2 rounded-full bg-cyan-200 shadow-[0_0_12px_#67e8f9] animate-pulse"></span></div>
            <b class="text-xs font-black uppercase tracking-[.18em] text-emerald-100">Midiendo almacenamiento</b><span class="mt-2 text-[10px] leading-relaxed text-gray-400">Contando archivos de juegos, actualizaciones, DLC, partidas y capturas. La lectura es de solo consulta.</span><span class="mt-5 h-1 w-40 overflow-hidden rounded-full bg-white/10"><i class="block h-full w-1/3 rounded-full bg-gradient-to-r from-emerald-300 to-cyan-200 animate-pulse"></i></span>
        </div>`;
    const loadingMarkup = () => window.ghmLoaderMarkup
        ? window.ghmLoaderMarkup('Midiendo almacenamiento', 'Contando archivos de juegos, actualizaciones, DLC, partidas y capturas. La lectura es de solo consulta.', 'emerald')
        : loadingMarkupFallback();

    function renderQuick(report) {
        const quick = $('storage-quick');
        if (!quick) return;
        const usedLabel = report.lower_bound || report.limited ? 'Mínimo medido' : 'Usado medido';
        quick.innerHTML = `<article class="rounded-xl border border-emerald-400/20 bg-emerald-400/[.06] p-3"><small class="block text-[8px] uppercase tracking-widest text-emerald-200">${usedLabel}</small><b class="mt-1 block text-sm text-white">${formatBytes(report.total_bytes)}</b></article>
            ${categories.map(([key, label, icon, color]) => {
                const data = report.categories?.[key] || { bytes: 0, items: [] };
                const accent = color === 'cyan' ? 'text-cyan-200 border-cyan-400/15' : color === 'pink' ? 'text-pink-200 border-pink-400/15' : 'text-emerald-200 border-emerald-400/15';
                return `<article class="rounded-xl border ${accent} bg-white/[.025] p-3"><small class="block truncate text-[8px] uppercase tracking-widest"><i class="fa-solid ${icon} mr-1"></i>${label} · ${data.items?.length || 0}</small><b class="mt-1 block text-sm text-white">${formatBytes(data.bytes)}</b></article>`;
            }).join('')}
            <article class="col-span-2 rounded-xl border border-white/10 bg-white/[.025] p-2 sm:col-span-4"><span class="text-[9px] text-gray-300">Espacio libre: <b class="text-gray-100">no disponible por FTP</b></span><span class="ml-2 text-[8px] text-gray-500">El servidor GoldHEN no publica capacidad del disco.</span></article>`;
    }
    function renderTabs(report) {
        const tabs = $('storage-tabs');
        if (!tabs) return;
        tabs.innerHTML = categories.map(([key, label, icon, color]) => {
            const data = report.categories?.[key] || { bytes: 0, items: [] };
            const active = key === selectedCategory;
            return `<button type="button" role="tab" aria-selected="${active}" data-storage-category="${esc(key)}" class="shrink-0 rounded-xl border px-3 py-2.5 text-left transition ${active ? themeClasses[color].active : 'border-white/10 bg-white/[.025] text-gray-400'}"><span class="block text-[9px] font-black uppercase"><i class="fa-solid ${icon} mr-1"></i>${label}</span><span class="mt-1 block text-[8px]">${data.items?.length || 0} elementos · ${formatBytes(data.bytes)}</span></button>`;
        }).join('');
        tabs.querySelectorAll('[data-storage-category]').forEach(button => button.addEventListener('click', () => window.almacenamientoSeleccionarCategoria(button.dataset.storageCategory)));
    }
    window.almacenamientoSeleccionarCategoria = category => {
        if (!categories.some(([key]) => key === category) || !currentReport) return;
        selectedCategory = category;
        renderTabs(currentReport);
        renderCategory(currentReport);
    };
    function renderCategory(report) {
        const box = $('storage-items');
        if (!box) return;
        const [key, label, icon, color] = categories.find(row => row[0] === selectedCategory) || categories[0];
        const category = report.categories?.[key] || { bytes: 0, items: [] };
        const items = (category.items || []).slice().sort((a, b) => Number(b.bytes) - Number(a.bytes));
        const rows = items.length ? items.map(item => {
            const breakdown = item.base_bytes !== undefined
                ? `<span class="block mt-1 text-[8px] text-gray-500">Juego ${formatBytes(item.base_bytes)}${item.update_bytes ? ` · update ${formatBytes(item.update_bytes)}` : ''}${item.dlc_bytes ? ` · DLC ${formatBytes(item.dlc_bytes)}` : ''}</span>` : '';
            return `<article class="flex items-center gap-3 rounded-2xl border border-white/5 bg-white/[.025] p-3">
                <div class="grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-xl ${themeClasses[color].icon}">${item.icon ? `<img loading="lazy" decoding="async" class="h-full w-full object-cover" alt="Vista previa de ${esc(item.title)}" src="${esc(item.icon)}">` : `<i class="fa-solid ${icon}"></i>`}</div>
                <div class="min-w-0 flex-1"><b class="block truncate text-[10px] text-gray-100" title="${esc(item.title)}">${esc(item.title)}</b><span class="block truncate text-[8px] text-gray-500">${esc(item.title_id || '')}${item.profile ? ` · ${esc(item.profile)}` : ''}${item.files ? ` · ${item.files} archivos` : ''}</span>${breakdown}</div>
                <b class="shrink-0 text-[10px] font-black text-cyan-100">${formatBytes(item.bytes)}</b>
            </article>`;
        }).join('') : '<p class="rounded-xl border border-white/5 p-5 text-center text-[10px] text-gray-400">No hay elementos detectados en esta categoría.</p>';
        const totalNote = report.lower_bound || report.limited
            ? 'El total es un mínimo: alguna ruta no fue legible o alcanzó el límite de recorrido.'
            : 'Tamaños lógicos enumerados por FTP; el espacio físico puede variar por bloques y datos del sistema.';
        box.innerHTML = `<section class="mb-3 flex items-center justify-between rounded-2xl border border-white/5 bg-white/[.025] p-3"><div><b class="text-xs font-black uppercase text-white">${label}</b><p class="mt-1 text-[8px] text-gray-500">${items.length} elementos · lectura FTP</p></div><b class="text-sm text-emerald-100">${formatBytes(category.bytes)}</b></section><div class="space-y-2">${rows}</div><p class="mt-3 rounded-xl border border-white/5 p-3 text-[8px] leading-relaxed text-gray-500">${totalNote}${report.scan_errors?.length ? ` Rutas incompletas: ${report.scan_errors.map(esc).join(', ')}.` : ''}</p>`;
    }

    window.almacenamientoCargar = async function () {
        const box = $('storage-items');
        if (!box) return;
        box.innerHTML = loadingMarkup();
        if ($('storage-summary')) $('storage-summary').textContent = 'Escaneo FTP en curso…';
        const form = new FormData();
        form.append('action', 'storage');
        form.append('host_ip', localStorage.getItem('sebas_ip_final_libre') || '');
        form.append('port', localStorage.getItem('sebas_port_libre') || '2121');
        try {
            const response = await fetch('api/saves_api.php', { method: 'POST', body: form, cache: 'no-store' });
            const result = await response.json();
            if (!response.ok || result.status !== 'success') throw new Error(result.message || 'No se pudo leer el almacenamiento.');
            currentReport = result.report;
            renderQuick(currentReport);
            renderTabs(currentReport);
            renderCategory(currentReport);
            if ($('storage-summary')) $('storage-summary').textContent = currentReport.lower_bound || currentReport.limited ? 'Estimación parcial' : 'Inventario leído';
        } catch (error) {
            currentReport = null;
            if ($('storage-quick')) $('storage-quick').innerHTML = '';
            if ($('storage-tabs')) $('storage-tabs').innerHTML = '';
            box.innerHTML = `<p class="rounded-xl border border-rose-400/20 p-4 text-xs text-rose-200">${esc(error.message)}</p>`;
            if ($('storage-summary')) $('storage-summary').textContent = 'No se completó el escaneo';
        }
    };
})();
