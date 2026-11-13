(() => {
    let orphanRows = [];
    let selected = new Set();
    let operationRunning = false;

    const $ = id => document.getElementById(id);
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[char]);
    const formatBytes = value => {
        let bytes = Number(value) || 0;
        const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        let unit = 0;
        while (bytes >= 1024 && unit < units.length - 1) { bytes /= 1024; unit++; }
        return (bytes ? (unit ? bytes.toFixed(2) : bytes.toFixed(0)) : '0') + ' ' + units[unit];
    };
    const loadingMarkupFallback = (title, detail) => `
        <div class="mx-auto flex min-h-56 max-w-md flex-col items-center justify-center rounded-3xl border border-rose-400/15 bg-gradient-to-b from-rose-400/[.07] to-transparent p-6 text-center" role="status" aria-live="polite">
            <div class="relative mb-5 grid h-20 w-20 place-items-center" aria-hidden="true"><span class="absolute inset-0 rounded-full border border-rose-300/20"></span><span class="absolute inset-1 rounded-full border-2 border-transparent border-t-rose-300 border-r-amber-300 animate-spin"></span><span class="absolute inset-3 rounded-full border border-dashed border-orange-200/50 animate-[spin_4s_linear_infinite]"></span><i class="fa-solid fa-shield-halved text-2xl text-rose-100 drop-shadow-[0_0_12px_rgba(251,113,133,.7)]"></i><span class="absolute right-1 top-2 h-2 w-2 rounded-full bg-amber-200 shadow-[0_0_12px_#fcd34d] animate-pulse"></span></div>
            <b class="text-xs font-black uppercase tracking-[.18em] text-rose-100">${escapeHtml(title)}</b><span class="mt-2 text-[10px] leading-relaxed text-gray-400">${escapeHtml(detail)}</span><span class="mt-5 h-1 w-40 overflow-hidden rounded-full bg-white/10"><i class="block h-full w-1/3 rounded-full bg-gradient-to-r from-rose-300 to-amber-200 animate-pulse"></i></span>
        </div>`;
    const loadingMarkup = (title, detail) => window.ghmLoaderMarkup
        ? window.ghmLoaderMarkup(title, detail, 'rose')
        : loadingMarkupFallback(title, detail);
    const connection = () => ({
        host_ip: localStorage.getItem('sebas_ip_final_libre') || '',
        port: localStorage.getItem('sebas_port_libre') || '2121'
    });

    async function request(action, data = {}) {
        const form = new FormData();
        form.append('action', action);
        Object.entries({ ...connection(), ...data }).forEach(([key, value]) => form.append(key, String(value)));
        const response = await fetch('api/saves_api.php', { method: 'POST', body: form });
        const result = await response.json();
        if (!response.ok || result.status !== 'success') throw new Error(result.message || 'Error en la operación FTP.');
        return result;
    }

    function notify(title, message, isError = false) {
        if (window.ps5Notification) {
            window.ps5Notification(title, message, isError ? 'fa-triangle-exclamation' : 'fa-circle-check');
        } else {
            alert(title + ': ' + message);
        }
    }

    function syncSelection() {
        const count = selected.size;
        const actions = $('cleanup-actions');
        const selectAll = $('cleanup-select-all');
        if (actions) actions.classList.toggle('hidden', count === 0);
        const selectionSummary = $('cleanup-selected-summary');
        if (selectionSummary) {
            const bytes = [...selected].reduce((total, index) => {
                const row = orphanRows[index];
                return total + (row ? row.bytes + row.metadata_bytes : 0);
            }, 0);
            selectionSummary.textContent = count + ' partida(s) seleccionada(s) · ' + formatBytes(bytes);
        }
        if (selectAll) {
            selectAll.textContent = count > 0 && count === orphanRows.length ? 'Deseleccionar todas' : 'Seleccionar todas';
            selectAll.disabled = orphanRows.length === 0 || operationRunning;
        }
    }

    function setOperationRunning(isRunning) {
        operationRunning = isRunning;
        document.querySelectorAll('#cleanup-actions button, #orphan-list input[type="checkbox"]').forEach(control => {
            control.disabled = isRunning;
        });
        const refresh = $('cleanup-refresh');
        if (refresh) refresh.disabled = isRunning;
        syncSelection();
    }

    window.limpiezaCargar = async function () {
        if (operationRunning) return;
        const list = $('orphan-list');
        orphanRows = [];
        selected.clear();
        if (list) list.innerHTML = loadingMarkup('Revisando partidas', 'Comparando perfiles y títulos instalados en el disco interno y el almacenamiento extendido.');
        if ($('cleanup-backup-results')) $('cleanup-backup-results').innerHTML = '';
        syncSelection();

        try {
            const result = await request('scan');
            orphanRows = (result.saves || []).filter(save => !save.installed);
            const totalBytes = orphanRows.reduce((total, save) => total + save.bytes + save.metadata_bytes, 0);
            if ($('orphan-summary')) {
                $('orphan-summary').textContent = orphanRows.length + ' partidas de juegos no instalados · ' + formatBytes(totalBytes);
            }
            if (list) {
                list.innerHTML = orphanRows.length ? orphanRows.map((save, index) => `
                    <label class="flex items-center gap-3 rounded-2xl border border-rose-400/20 bg-white/[.025] p-3">
                        <input class="accent-rose-400" type="checkbox" data-index="${index}" onchange="limpiezaToggle(${index},this.checked)">
                        <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-rose-500/10 text-rose-200">
                            ${save.icon ? `<img loading="lazy" class="h-full w-full object-cover" src="${escapeHtml(save.icon)}">` : '<i class="fa-solid fa-floppy-disk"></i>'}
                        </div>
                        <span class="min-w-0 flex-1">
                            <b class="block truncate text-xs text-white">${escapeHtml(save.title)}</b>
                            <span class="text-[9px] font-mono text-rose-200">${escapeHtml(save.title_id)} · ${escapeHtml(save.profile)}</span>
                            <span class="block mt-1 text-[9px] text-gray-400">${formatBytes(save.bytes + save.metadata_bytes)} · ${save.files + save.metadata_files} archivos</span>
                        </span>
                    </label>`).join('') : '<p class="rounded-xl border border-white/5 p-4 text-xs text-gray-400">No hay partidas candidatas.</p>';
            }
            syncSelection();
        } catch (error) {
            orphanRows = [];
            selected.clear();
            if (list) list.innerHTML = '<p class="text-xs text-rose-300">' + escapeHtml(error.message) + '</p>';
            if ($('orphan-summary')) $('orphan-summary').textContent = 'No se pudo verificar la lista de partidas.';
            syncSelection();
            notify('Limpieza bloqueada', error.message, true);
        }
    };

    window.limpiezaToggle = (index, isChecked) => {
        if (operationRunning || !orphanRows[index]) return;
        isChecked ? selected.add(index) : selected.delete(index);
        syncSelection();
    };

    window.limpiezaSeleccionarTodo = () => {
        if (!orphanRows.length || operationRunning) return;
        if (selected.size === orphanRows.length) selected.clear();
        else orphanRows.forEach((_, index) => selected.add(index));
        document.querySelectorAll('#orphan-list input[type="checkbox"]').forEach((checkbox, index) => {
            checkbox.checked = selected.has(index);
        });
        syncSelection();
    };

    window.limpiezaRespaldarDescargar = async function () {
        if (operationRunning) return;
        const rows = [...selected].map(index => orphanRows[index]).filter(Boolean);
        if (!rows.length) return;
        if (!confirm('Se crearán respaldos ZIP verificados para ' + rows.length + ' partidas. No se borrará nada de la PS4. ¿Continuar?')) return;

        const resultBox = $('cleanup-backup-results');
        if (resultBox) resultBox.innerHTML = '<p class="text-xs text-cyan-200 animate-pulse">Creando respaldos verificados…</p>';
        setOperationRunning(true);
        const results = [];
        for (const save of rows) {
            try {
                const backup = await request('backup', { profile_key: save.profile_key, title_id: save.title_id });
                results.push({
                    title: save.title, title_id: save.title_id, profile: save.profile,
                    download: backup.download, bytes: backup.backup.total_bytes
                });
            } catch (error) {
                results.push({ title: save.title, profile: save.profile, error: error.message });
            }
        }
        if (resultBox) {
            resultBox.innerHTML = results.map(item => item.error
                ? '<p class="rounded-lg border border-rose-400/20 p-2 text-[9px] text-rose-200">' + escapeHtml(item.title) + ' · ' + escapeHtml(item.profile) + ': ' + escapeHtml(item.error) + '</p>'
                : '<a href="' + escapeHtml(item.download) + '" download class="flex items-center justify-between gap-2 rounded-lg border border-cyan-400/20 bg-cyan-400/5 p-2 text-[9px] text-cyan-100"><span class="truncate">' + escapeHtml(item.title) + ' · ' + escapeHtml(item.title_id) + ' · ' + escapeHtml(item.profile) + ' · ' + formatBytes(item.bytes) + '</span><b class="shrink-0">Descargar ZIP</b></a>'
            ).join('');
        }
        setOperationRunning(false);
        notify('Respaldos listos', results.filter(item => !item.error).length + ' ZIP disponibles; no se eliminó ninguna partida.');
    };

    window.limpiezaBorrarSeleccion = async function (withBackup) {
        if (operationRunning) return;
        const rows = [...selected].map(index => orphanRows[index]).filter(Boolean);
        if (!rows.length) return;
        const totalBytes = rows.reduce((total, save) => total + save.bytes + save.metadata_bytes, 0);
        if (withBackup) {
            if (!confirm('Se crearán ZIP verificados de ' + rows.length + ' partidas (' + formatBytes(totalBytes) + ') antes de borrarlas. ¿Seguir?')) return;
        } else {
            if (!confirm('Borrado permanente sin respaldo. Se eliminarán ' + rows.length + ' partidas seleccionadas (' + formatBytes(totalBytes) + ') y sus metadatos. No se podrá deshacer. ¿Eliminar ahora?')) return;
        }

        setOperationRunning(true);
        const completed = [];
        let removedBytes = 0;
        let removedBytesExact = true;
        let currentSave = null;
        let currentBackupCreated = false;
        try {
            for (const save of rows) {
                currentSave = save;
                currentBackupCreated = false;
                let backupId = '';
                if (withBackup) {
                    const backup = await request('backup', { profile_key: save.profile_key, title_id: save.title_id });
                    backupId = backup.backup.id;
                    currentBackupCreated = true;
                }
                const deletion = await request('cleanup_delete', {
                    profile_key: save.profile_key,
                    title_id: save.title_id,
                    backup_id: backupId,
                    permanent: withBackup ? '0' : '1'
                });
                completed.push(save.title + ' (' + save.title_id + ', ' + save.profile + ')');
                removedBytes += Number(deletion.removed_bytes || 0);
                removedBytesExact = removedBytesExact && deletion.removed_bytes_exact !== false;
                currentSave = null;
                currentBackupCreated = false;
            }
            const bytesLabel = removedBytesExact ? 'Tamaño de archivos eliminados' : 'Tamaño mínimo informado por FTP';
            const resultBox = $('cleanup-result');
            if (resultBox) {
                resultBox.classList.remove('hidden');
                resultBox.innerHTML = `<b class="block text-xs font-black uppercase tracking-wider text-emerald-200">Limpieza completada</b><span class="mt-1 block text-[10px] text-gray-200">${completed.length} partidas eliminadas y ausencia verificada.</span><span class="mt-1 block text-[11px] font-mono font-bold text-emerald-100">${bytesLabel}: ${formatBytes(removedBytes)}</span><span class="mt-1 block text-[8px] text-gray-400">Suma actualizada de tamaños de archivo FTP; no es una lectura de bloques libres del disco.</span>${withBackup ? '<span class="mt-1 block text-[8px] text-gray-400">El ZIP de respaldo quedó guardado.</span>' : ''}`;
            }
            notify('Limpieza completada', completed.length + ' partidas eliminadas y verificadas · ' + bytesLabel.toLowerCase() + ': ' + formatBytes(removedBytes) + (withBackup ? ' · respaldo ZIP creado.' : '.'));
        } catch (error) {
            let message = completed.length
                ? 'Eliminadas y confirmadas: ' + completed.join('; ') + '. '
                : '';
            if (currentSave) {
                message += 'No se confirmó la operación para ' + currentSave.title + ' (' + currentSave.title_id + ', ' + currentSave.profile + '). Puede haber un borrado parcial; revisa la lista antes de reintentar. ';
                if (currentBackupCreated) message += 'El respaldo ZIP local se conserva. ';
            }
            if (completed.length) message += 'Tamaño de archivos eliminados y confirmados: ' + formatBytes(removedBytes) + '. ';
            message += error.message;
            notify('Limpieza parcial', message, true);
        }
        setOperationRunning(false);
        await window.limpiezaCargar();
    };
})();
