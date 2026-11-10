(() => {
    const API = 'api/ps4_screenshots_api.php';
    const $ = id => document.getElementById(id);
    let images = [];
    let selected = new Set();
    let lightboxUrl = '';
    let lightboxName = '';

    const connection = () => ({
        host_ip: localStorage.getItem('sebas_ip_final_libre') || '',
        port: localStorage.getItem('sebas_port_libre') || '2121'
    });
    const safeImageUrl = value => {
        const url = String(value || '').trim();
        if (/[\u0000-\u001f\u007f]/.test(url)) return '';
        if (/^user\/cache\/capturas\/[a-f0-9]+\.(?:jpe?g|png)$/i.test(url)) return url;
        if (/^api\/stream_image_api\.php\?[a-z0-9_=&%./-]+$/i.test(url)) return url;
        return '';
    };
    const notify = (title, message, icon = 'fa-circle-info') => {
        if (window.ps5Notification) window.ps5Notification(title, message, icon);
        else console.info(`${title}: ${message}`);
    };
    async function request(action, extra = {}) {
        const form = new FormData();
        form.append('action', action);
        Object.entries({ ...connection(), ...extra }).forEach(([key, value]) => {
            if (Array.isArray(value)) value.forEach(item => form.append(`${key}[]`, String(item)));
            else form.append(key, String(value));
        });
        const response = await fetch(API, { method: 'POST', body: form });
        const contentType = response.headers.get('content-type') || '';
        if (!response.ok) {
            let message = `HTTP ${response.status}`;
            if (contentType.includes('application/json')) {
                try { message = (await response.json()).message || message; } catch (_) {}
            }
            throw new Error(message);
        }
        return { response, contentType };
    }
    function setSelectionUi() {
        const count = $('capture-selection-count');
        const button = $('capture-select-all');
        if (count) count.textContent = `${selected.size} seleccionadas`;
        if (button) button.textContent = images.length && selected.size === images.length ? 'Deseleccionar todas' : 'Seleccionar todas';
    }
    function renderGallery(message = '') {
        const grid = $('galeria-grid');
        const loader = $('galeria-loader');
        if (!grid || !loader) return;
        loader.classList.add('hidden');
        grid.replaceChildren();
        if (!images.length) {
            const empty = document.createElement('p');
            empty.className = 'col-span-full py-16 text-center text-gray-500 text-[10px]';
            empty.textContent = message || 'No hay capturas disponibles.';
            grid.appendChild(empty);
            grid.classList.remove('hidden');
            setSelectionUi();
            return;
        }
        images.forEach((image, index) => {
            const card = document.createElement('article');
            card.className = 'relative aspect-video overflow-hidden rounded-xl border border-white/5 bg-[#111621] group';
            const img = document.createElement('img');
            img.src = safeImageUrl(image.url);
            img.alt = String(image.name || 'Captura de PS4');
            img.loading = 'lazy';
            img.className = 'h-full w-full object-cover opacity-80 group-hover:opacity-100 transition-opacity cursor-pointer';
            img.addEventListener('click', () => window.abrirLightbox(index));
            const label = document.createElement('span');
            label.className = 'absolute bottom-2 left-2 right-10 truncate text-[8px] font-mono text-white pointer-events-none';
            label.textContent = `${image.game_id ? `[${image.game_id}] ` : ''}${image.name || 'Captura'}`;
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = selected.has(index);
            checkbox.className = 'capture-select absolute left-2 top-2 h-5 w-5 accent-pink-400';
            checkbox.setAttribute('aria-label', `Seleccionar ${image.name || 'captura'}`);
            checkbox.addEventListener('change', () => window.seleccionarCaptura(index, checkbox.checked));
            const download = document.createElement('a');
            download.href = safeImageUrl(image.url);
            download.download = String(image.name || 'captura').replace(/[\\/:*?"<>|]/g, '_');
            download.className = 'absolute right-12 top-2 flex h-8 w-8 items-center justify-center rounded-lg border border-cyan-400/30 bg-black/70 text-cyan-200';
            download.setAttribute('aria-label', 'Descargar captura');
            download.innerHTML = '<i class="fa-solid fa-download text-[10px]"></i>';
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'absolute right-2 top-2 flex h-8 w-8 items-center justify-center rounded-lg border border-red-400/30 bg-black/70 text-red-300';
            remove.setAttribute('aria-label', 'Eliminar captura');
            remove.innerHTML = '<i class="fa-solid fa-trash text-[10px]"></i>';
            remove.addEventListener('click', () => window.eliminarCapturaGaleria(index));
            card.append(img, label, checkbox, download, remove);
            grid.appendChild(card);
        });
        grid.classList.remove('hidden');
        setSelectionUi();
    }
    async function loadGallery(force = false) {
        const { host_ip, port } = connection();
        if (!host_ip) throw new Error('Configura la IP y el puerto FTP de la consola.');
        const count = $('galeria-count');
        const grid = $('galeria-grid');
        const loader = $('galeria-loader');
        if (count) count.textContent = 'Conectando…';
        if (grid) { grid.replaceChildren(); grid.classList.add('hidden'); }
        if (loader) loader.classList.remove('hidden');
        const { response } = await request('get_all_caps', { force: force ? '1' : '0' });
        const result = await response.json();
        if (result.status !== 'success') throw new Error(result.message || 'La PS4 no devolvió la galería.');
        images = Array.isArray(result.images) ? result.images : [];
        selected.clear();
        if (count) count.textContent = `${images.length} capturas`;
        renderGallery(result.message || 'No se encontraron imágenes JPG o PNG.');
        const summary = $('captures-summary');
        if (summary) summary.textContent = images.length
            ? `${images.length} capturas detectadas. Puedes seleccionar, descargar o eliminar.`
            : (result.message || 'No se encontraron capturas visibles por FTP.');
    }

    window.capturasCargar = async force => {
        try { await window.abrirGaleriaGlobal(Boolean(force)); }
        catch (error) { const summary = $('captures-summary'); if (summary) summary.textContent = error.message; notify('Capturas', error.message, 'fa-triangle-exclamation'); }
    };
    window.abrirGaleriaGlobal = async force => {
        try { await loadGallery(Boolean(force)); }
        catch (error) {
            if ($('galeria-loader')) $('galeria-loader').classList.add('hidden');
            if ($('galeria-count')) $('galeria-count').textContent = 'Error';
            renderGallery(error.message);
            notify('Capturas', error.message, 'fa-triangle-exclamation');
        }
    };
    window.cerrarGaleriaJuego = () => {
        if (typeof window.volverAlLauncher === 'function') window.volverAlLauncher();
    };
    window.forzarRecargaGaleria = () => window.abrirGaleriaGlobal(true);
    window.seleccionarCaptura = (index, checked) => {
        checked ? selected.add(index) : selected.delete(index);
        setSelectionUi();
    };
    window.seleccionarTodasCapturas = () => {
        selected = selected.size === images.length && images.length ? new Set() : new Set(images.map((_, index) => index));
        document.querySelectorAll('.capture-select').forEach((checkbox, index) => { checkbox.checked = selected.has(index); });
        setSelectionUi();
    };
    window.descargarSeleccionCapturas = async () => {
        const paths = [...selected].sort((a, b) => a - b).map(index => images[index]?.remote_path);
        if (!paths.length || paths.some(path => !path)) return notify('Capturas', 'Selecciona imágenes con una ruta FTP verificada.', 'fa-triangle-exclamation');
        try {
            const { response, contentType } = await request('download_selected', { 'remote_paths': paths });
            if (!contentType.includes('application/zip')) throw new Error('La respuesta no contiene un ZIP.');
            const url = URL.createObjectURL(await response.blob());
            const link = document.createElement('a');
            link.href = url;
            link.download = `capturas_ps4_${new Date().toISOString().replace(/[:.]/g, '-')}.zip`;
            document.body.appendChild(link); link.click(); link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 60000);
            notify('Capturas', `${paths.length} imágenes incluidas en el ZIP.`, 'fa-download');
        } catch (error) { notify('Error al descargar', error.message, 'fa-triangle-exclamation'); }
    };
    async function deleteImage(index) {
        const image = images[index];
        if (!image?.remote_path) throw new Error('Actualiza la galería para verificar la ruta FTP.');
        const { response } = await request('delete_capture', { remote_path: image.remote_path });
        const result = await response.json();
        if (result.status !== 'success') throw new Error(result.message || 'No se confirmó el borrado FTP.');
    }
    window.eliminarCapturaGaleria = async index => {
        const image = images[index];
        if (!image || !confirm(`¿Eliminar esta captura de la PS4?\n${image.game_id ? image.game_id + ' · ' : ''}${image.name || ''}`)) return;
        try { await deleteImage(index); notify('Capturas', 'Eliminada y verificada en la consola.', 'fa-trash'); await loadGallery(true); }
        catch (error) { notify('No se eliminó', error.message, 'fa-triangle-exclamation'); }
    };
    window.eliminarSeleccionCapturas = async () => {
        const indices = [...selected].sort((a, b) => b - a);
        if (!indices.length) return notify('Capturas', 'Selecciona una o más imágenes.', 'fa-circle-info');
        if (!confirm(`Se eliminarán ${indices.length} capturas de forma permanente de la PS4. ¿Continuar?`)) return;
        let removed = 0; let firstError = '';
        for (const index of indices) {
            try { await deleteImage(index); removed++; }
            catch (error) { firstError ||= error.message; }
        }
        notify(removed === indices.length ? 'Capturas eliminadas' : 'Borrado parcial', `${removed} de ${indices.length} eliminadas.${firstError ? ' ' + firstError : ''}`, removed === indices.length ? 'fa-trash' : 'fa-triangle-exclamation');
        selected.clear();
        await loadGallery(true);
    };
    window.abrirLightbox = index => {
        const image = images[index];
        const url = safeImageUrl(image?.url);
        if (!image || !url) return;
        lightboxUrl = url; lightboxName = String(image.name || 'Captura PS4');
        const viewer = $('lightbox-visor'); const img = $('lightbox-img'); const label = $('lightbox-name');
        if (!viewer || !img || !label) return;
        img.src = url; img.alt = lightboxName; label.textContent = lightboxName;
        viewer.classList.remove('hidden');
        setTimeout(() => { viewer.classList.add('opacity-100'); img.classList.remove('scale-95'); img.classList.add('scale-100'); }, 10);
    };
    window.cerrarLightbox = () => {
        const viewer = $('lightbox-visor'); const img = $('lightbox-img');
        if (!viewer || !img) return;
        viewer.classList.remove('opacity-100'); img.classList.remove('scale-100'); img.classList.add('scale-95');
        setTimeout(() => { viewer.classList.add('hidden'); img.src = ''; }, 300);
    };
    window.compartirImagenLightbox = async () => {
        if (!lightboxUrl) return;
        try {
            const response = await fetch(lightboxUrl); const blob = await response.blob();
            const file = new File([blob], lightboxName, { type: blob.type });
            if (navigator.share && navigator.canShare?.({ files: [file] })) {
                await navigator.share({ title: lightboxName, text: 'Captura de PS4', files: [file] });
                return;
            }
            const url = URL.createObjectURL(blob); const link = document.createElement('a');
            link.href = url; link.download = lightboxName; document.body.appendChild(link); link.click(); link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 60000);
        } catch (error) { notify('No se pudo compartir', error.message || 'El navegador bloqueó la operación.', 'fa-triangle-exclamation'); }
    };
})();
