(() => {
    const API = 'api/saves_api.php';
    let saves = [];
    const $ = id => document.getElementById(id);
    const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[char]);
    const fmt = value => {
        let bytes = Number(value) || 0;
        const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        let i = 0;
        while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
        return (bytes ? (i ? bytes.toFixed(2) : bytes.toFixed(0)) : '0') + ' ' + units[i];
    };
    const conn = () => ({
        host_ip: localStorage.getItem('sebas_ip_final_libre') || '',
        port: localStorage.getItem('sebas_port_libre') || '2121'
    });
    const loadingMarkupFallback = (title, detail) => `
        <div class="mx-auto flex min-h-56 max-w-md flex-col items-center justify-center rounded-3xl border border-cyan-400/15 bg-gradient-to-b from-cyan-400/[.07] to-transparent p-6 text-center" role="status" aria-live="polite">
            <div class="relative mb-5 grid h-20 w-20 place-items-center" aria-hidden="true">
                <span class="absolute inset-0 rounded-full border border-cyan-300/20"></span>
                <span class="absolute inset-1 rounded-full border-2 border-transparent border-t-cyan-300 border-r-violet-400 animate-spin"></span>
                <span class="absolute inset-3 rounded-full border border-dashed border-fuchsia-300/50 animate-[spin_4s_linear_infinite]"></span>
                <i class="fa-solid fa-floppy-disk text-2xl text-cyan-100 drop-shadow-[0_0_12px_rgba(34,211,238,.7)]"></i>
                <span class="absolute right-1 top-2 h-2 w-2 rounded-full bg-violet-300 shadow-[0_0_12px_#a78bfa] animate-pulse"></span>
            </div>
            <b class="text-xs font-black uppercase tracking-[.18em] text-cyan-100">${esc(title)}</b>
            <span class="mt-2 text-[10px] leading-relaxed text-gray-400">${esc(detail)}</span>
            <span class="mt-5 flex gap-1.5" aria-hidden="true"><i class="h-1.5 w-1.5 rounded-full bg-cyan-300 animate-bounce"></i><i class="h-1.5 w-1.5 rounded-full bg-violet-300 animate-bounce [animation-delay:120ms]"></i><i class="h-1.5 w-1.5 rounded-full bg-fuchsia-300 animate-bounce [animation-delay:240ms]"></i></span>
        </div>`;
    const loadingMarkup = (title, detail) => window.ghmLoaderMarkup
        ? window.ghmLoaderMarkup(title, detail, 'cyan')
        : loadingMarkupFallback(title, detail);

    async function req(action, data = {}) {
        const form = new FormData();
        form.append('action', action);
        Object.entries({ ...conn(), ...data }).forEach(([key, value]) => form.append(key, String(value)));
        const response = await fetch(API, { method: 'POST', body: form, cache: 'no-store' });
        const result = await response.json();
        if (!response.ok || result.status !== 'success') throw new Error(result.message || 'Falló la operación FTP.');
        return result;
    }
    async function enrichTitlesFromAppDb(rows) {
        if (!rows.some(save => save.installed && save.title === save.title_id)) return false;
        const current = conn();
        const form = new FormData();
        form.append('action', 'scan');
        form.append('host_ip', current.host_ip);
        form.append('host_port', current.port);
        try {
            const response = await fetch('api/organizador_apps_api.php', { method: 'POST', body: form, cache: 'no-store' });
            const result = await response.json();
            if (!response.ok || result.status !== 'success') return false;
            const titles = new Map();
            (result.profiles || []).forEach(profile => [...(profile.system || []), ...(profile.games || [])].forEach(app => {
                const id = String(app.titleId || '').toUpperCase();
                const title = String(app.titleName || '').trim();
                if (/^[A-Z]{4}\d{5}$/.test(id) && title && title.toUpperCase() !== id && !titles.has(id)) titles.set(id, app);
            }));
            let changed = false;
            rows.forEach(save => {
                const app = titles.get(String(save.title_id || '').toUpperCase());
                if (!app) return;
                if (!save.title || save.title === save.title_id) { save.title = String(app.titleName).trim(); changed = true; }
                if (!save.icon && app.metaDataPath) {
                    save.icon = `api/organizador_icon_api.php?ip=${encodeURIComponent(current.host_ip)}&port=${encodeURIComponent(current.port)}&id=${encodeURIComponent(save.title_id)}&meta=${encodeURIComponent(app.metaDataPath)}`;
                    changed = true;
                }
            });
            return changed;
        } catch { return false; }
    }
    function note(title, message, error = false) {
        window.ps5Notification ? window.ps5Notification(title, message, error ? 'fa-triangle-exclamation' : 'fa-circle-check') : alert(title + ': ' + message);
    }

    window.savesRecargar = async function () {
        const box = $('save-list');
        if (!box) return;
        if (!conn().host_ip) {
            box.innerHTML = '<p class="rounded-xl border border-rose-400/20 p-4 text-xs text-rose-200">Configura IP y puerto FTP para leer las partidas.</p>';
            return;
        }
        box.innerHTML = loadingMarkup('Analizando partidas', 'Leyendo perfiles, nombres de juegos, iconos y tamaños por FTP. No se modifica ningún dato.');
        if ($('save-summary')) $('save-summary').textContent = 'Lectura en curso…';
        try {
            const result = await req('scan');
            saves = result.saves || [];
            await enrichTitlesFromAppDb(saves);
            render();
            await loadArchives();
        } catch (error) {
            box.innerHTML = `<p class="rounded-xl border border-rose-400/20 p-4 text-xs text-rose-200">${esc(error.message)}</p>`;
            if ($('save-summary')) $('save-summary').textContent = 'No se completó la lectura';
            note('Partidas', error.message, true);
        }
    };

    function card(save) {
        const total = (save.bytes || 0) + (save.metadata_bytes || 0);
        const installed = save.installed === true;
        const status = installed
            ? '<span class="rounded-full border border-emerald-400/20 bg-emerald-400/10 px-2 py-1 text-[8px] font-bold text-emerald-200">Juego instalado</span>'
            : '<span class="rounded-full border border-rose-400/30 bg-rose-500/15 px-2 py-1 text-[8px] font-bold text-rose-200">Juego no instalado</span>';
        const title = save.title && save.title !== save.title_id ? save.title : `Nombre no disponible · ${save.title_id}`;
        return `<article class="rounded-2xl border ${installed ? 'border-white/5' : 'border-rose-400/20'} bg-white/[.025] p-3">
            <div class="flex items-start gap-3">
                <div class="grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-xl bg-cyan-500/10 text-cyan-200">${save.icon ? `<img loading="lazy" decoding="async" class="h-full w-full object-cover" alt="Carátula de ${esc(title)}" src="${esc(save.icon)}">` : '<i class="fa-solid fa-floppy-disk"></i>'}</div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2"><h3 class="min-w-0 flex-1 truncate text-xs font-black text-white" title="${esc(title)}">${esc(title)}</h3>${status}</div>
                    <p class="mt-1 text-[9px] font-mono ${installed ? 'text-cyan-300' : 'text-rose-200'}">${esc(save.title_id)} · ${esc(save.profile || 'Perfil local sin nombre')}</p>
                    <p class="mt-1 text-[9px] text-gray-400">${fmt(total)} · ${Number(save.files || 0) + Number(save.metadata_files || 0)} archivos</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button class="save-backup rounded-lg bg-cyan-500/10 px-2.5 py-2 text-[9px] font-bold text-cyan-200" data-profile="${esc(save.profile_key)}" data-title="${esc(save.title_id)}">Respaldar y descargar</button>
                        <button class="save-delete rounded-lg bg-amber-500/10 px-2.5 py-2 text-[9px] font-bold text-amber-100" data-profile="${esc(save.profile_key)}" data-title="${esc(save.title_id)}" data-name="${esc(title)}">Respaldar y eliminar</button>
                        <button class="save-delete-permanent rounded-lg border border-rose-400/25 bg-rose-500/10 px-2.5 py-2 text-[9px] font-bold text-rose-200" data-profile="${esc(save.profile_key)}" data-title="${esc(save.title_id)}" data-name="${esc(title)}">Eliminar sin respaldo</button>
                        <button class="save-backups rounded-lg border border-white/10 px-2.5 py-2 text-[9px] text-gray-300" data-profile="${esc(save.profile_key)}" data-title="${esc(save.title_id)}">Respaldos</button>
                    </div>
                    <div class="save-backup-list mt-2 space-y-1" data-list="${esc(save.profile_key + ':' + save.title_id)}"></div>
                </div>
            </div>
        </article>`;
    }
    function render() {
        const list = $('save-list');
        if (!list) return;
        list.innerHTML = saves.length ? saves.map(card).join('') : '<p class="rounded-xl border border-white/5 p-4 text-xs text-gray-400">No se detectaron partidas.</p>';
        if ($('save-summary')) $('save-summary').textContent = `${saves.length} partidas · ${saves.filter(save => !save.installed).length} de juegos no instalados`;
        document.querySelectorAll('.save-backup').forEach(button => button.onclick = () => backup(button.dataset.profile, button.dataset.title));
        document.querySelectorAll('.save-delete').forEach(button => button.onclick = () => deleteFlow(button.dataset.profile, button.dataset.title, button.dataset.name));
        document.querySelectorAll('.save-delete-permanent').forEach(button => button.onclick = () => deletePermanent(button.dataset.profile, button.dataset.title, button.dataset.name));
        document.querySelectorAll('.save-backups').forEach(button => button.onclick = () => listBackups(button.dataset.profile, button.dataset.title));
    }
    async function backup(profile, title) {
        try {
            const result = await req('backup', { profile_key: profile, title_id: title });
            note('Respaldo verificado', `${title} · ${fmt(result.backup.total_bytes)}`);
            location.href = result.download;
            await listBackups(profile, title);
            await loadArchives();
        } catch (error) { note('No se creó el respaldo', error.message, true); }
    }
    async function deleteFlow(profile, title, name) {
        if (!confirm(`Se creará y verificará un ZIP antes de eliminar ${name} (${title}). ¿Continuar?`)) return;
        try {
            const result = await req('backup', { profile_key: profile, title_id: title });
            if (!confirm(`El ZIP se verificó (${fmt(result.backup.total_bytes)}). ¿Eliminar la partida de la consola?`)) return;
            const deleted = await req('delete', { profile_key: profile, title_id: title, backup_id: result.backup.id });
            note('Partida eliminada', deleted.message);
            await window.savesRecargar();
        } catch (error) { note('Operación detenida', error.message, true); }
    }
    async function deletePermanent(profile, title, name) {
        const accepted = confirm(`Borrado permanente sin respaldo ni descarga. Se eliminarán la partida y sus metadatos de ${name} (${title}). Esta acción no se puede deshacer. ¿Eliminar ahora?`);
        if (!accepted) return;
        try {
            const result = await req('delete_permanent', { profile_key: profile, title_id: title, permanent: '1' });
            note('Borrado permanente', result.message);
            await window.savesRecargar();
        } catch (error) { note('No se eliminó la partida', error.message, true); }
    }
    async function listBackups(profile, title) {
        const target = document.querySelector('[data-list="' + CSS.escape(profile + ':' + title) + '"]');
        if (!target) return;
        try {
            const result = await req('list_backups', { profile_key: profile, title_id: title });
            target.innerHTML = result.backups.length ? result.backups.map(backup => `<div class="flex flex-wrap items-center gap-2 rounded-lg bg-black/20 p-2 text-[9px]"><span class="flex-1 text-gray-300">${esc(new Date(backup.created_at).toLocaleString())} · ${fmt(backup.total_bytes)}</span><a class="rounded bg-cyan-500/10 px-2 py-1.5 text-cyan-200" href="${esc(backup.download)}" download>ZIP</a><button class="save-restore rounded bg-amber-500/10 px-2 py-1.5 text-amber-200" data-backup="${esc(backup.id)}" data-profile="${esc(profile)}" data-title="${esc(title)}">Restaurar</button></div>`).join('') : '<span class="text-[9px] text-gray-500">Sin respaldos.</span>';
            target.querySelectorAll('.save-restore').forEach(button => button.onclick = () => restore(button.dataset.profile, button.dataset.title, button.dataset.backup));
        } catch (error) { target.textContent = error.message; }
    }
    async function loadArchives() {
        const box = $('save-archives-list');
        if (!box || !conn().host_ip) return;
        try {
            const result = await req('list_all_backups');
            box.innerHTML = result.backups.length ? result.backups.map(backup => `<article class="flex flex-wrap items-center gap-2 rounded-xl border border-white/5 p-2"><div class="min-w-[150px] flex-1"><b class="block truncate text-[10px]">${esc(backup.title)}</b><span class="text-[8px] text-gray-500">${esc(backup.title_id)} · ${esc(backup.profile)} · ${esc(new Date(backup.created_at).toLocaleString())}</span></div><span class="text-[9px] text-cyan-200">${fmt(backup.total_bytes)}</span><a class="rounded-lg bg-cyan-500/10 px-2 py-2 text-[9px] text-cyan-200" href="${esc(backup.download)}" download>ZIP</a><button class="archive-restore rounded-lg bg-amber-500/10 px-2 py-2 text-[9px] text-amber-200" data-backup="${esc(backup.id)}" data-profile="${esc(backup.profile_key)}" data-title="${esc(backup.title_id)}">Restaurar</button></article>`).join('') : '<p class="text-[9px] text-gray-500">Aún no hay respaldos locales.</p>';
            box.querySelectorAll('.archive-restore').forEach(button => button.onclick = () => restore(button.dataset.profile, button.dataset.title, button.dataset.backup));
        } catch (error) { box.innerHTML = `<p class="text-[9px] text-rose-300">${esc(error.message)}</p>`; }
    }
    async function restore(profile, title, backupId) {
        if (!confirm('Restaurar puede reemplazar la partida actual. El sistema intentará respaldarla antes. ¿Continuar?')) return;
        try {
            const result = await req('restore', { profile_key: profile, title_id: title, backup_id: backupId });
            note('Restauración', result.message);
            await window.savesRecargar();
        } catch (error) { note('Restauración no confirmada', error.message, true); }
    }
    window.savesInit = () => {};
    document.addEventListener('DOMContentLoaded', window.savesInit);
})();
