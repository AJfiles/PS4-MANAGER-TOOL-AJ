/**
 * User-selected destination for exports in the Android APK.
 * The WebToApp SAF bridge is optional; browsers, Termux and Windows keep their
 * native download behavior and never call this code path.
 */
(() => {
    'use strict';

    const STREAM_CHUNK_BYTES = 192 * 1024;

    function apkShell() {
        return typeof ghmNativeAndroidApk !== 'undefined' && ghmNativeAndroidApk;
    }

    function directoryApi() {
        return apkShell() && window.WtaDirectory
            && typeof window.WtaDirectory.info === 'function'
            && typeof window.WtaDirectory.pick === 'function'
            && typeof window.WtaDirectory.list === 'function'
            && window.NativeBridge
            && typeof window.NativeBridge.writeDirectoryFile === 'function';
    }

    function notifyStorage(title, message, icon = 'fa-folder-open') {
        if (typeof window.sysNotification === 'function') window.sysNotification(title, message, icon);
        else if (typeof window.ps5Notification === 'function') window.ps5Notification(title, message, icon);
    }

    function storageStatus(text) {
        const status = document.getElementById('android-storage-status');
        if (status) status.textContent = text;
    }

    function safeFilename(value) {
        const name = String(value || 'archivo').split(/[\\/]/).pop()
            .replace(/[\u0000-\u001f<>:"|?*]/g, '_').trim();
        return name && name !== '.' && name !== '..' ? name.slice(0, 180) : 'archivo';
    }

    function bytesToBase64(bytes) {
        let binary = '';
        const sliceSize = 32768;
        for (let offset = 0; offset < bytes.byteLength; offset += sliceSize) {
            binary += String.fromCharCode(...bytes.subarray(offset, Math.min(offset + sliceSize, bytes.byteLength)));
        }
        return btoa(binary);
    }

    function writeSafChunk(path, bytes, append) {
        const raw = window.NativeBridge.writeDirectoryFile(path, bytesToBase64(bytes), append);
        const result = typeof raw === 'string' ? JSON.parse(raw) : raw;
        if (!result || !result.ok) throw new Error(result && (result.message || result.error) || 'Android no pudo escribir el archivo.');
        return result;
    }

    function chooseNonClobberingFilename(requested, listing) {
        const base = safeFilename(requested);
        const names = Array.isArray(listing && listing.entries)
            ? new Set(listing.entries.map(entry => String(entry && entry.name || ''))) : null;
        if (!names) {
            const dot = base.lastIndexOf('.');
            const stem = dot > 0 ? base.slice(0, dot) : base;
            const ext = dot > 0 ? base.slice(dot) : '';
            return `${stem}_${Date.now()}${ext}`;
        }
        if (!names.has(base)) return base;
        const dot = base.lastIndexOf('.');
        const stem = dot > 0 ? base.slice(0, dot) : base;
        const ext = dot > 0 ? base.slice(dot) : '';
        for (let i = 2; i < 10000; i++) {
            const candidate = `${stem} (${i})${ext}`;
            if (!names.has(candidate)) return candidate;
        }
        return `${stem}_${Date.now()}${ext}`;
    }

    function setBridgeVisibility() {
        const section = document.getElementById('android-storage-card');
        if (!section) return;
        section.classList.toggle('hidden', !directoryApi());
        if (directoryApi()) refreshAndroidStorageInfo();
    }

    async function refreshAndroidStorageInfo() {
        const button = document.getElementById('android-storage-select');
        const clear = document.getElementById('android-storage-clear');
        if (!directoryApi()) {
            storageStatus('Esta función solo está disponible en la APK Android compatible.');
            return { granted: false };
        }
        try {
            const info = await Promise.resolve(window.WtaDirectory.info());
            const granted = !!(info && info.ok && info.granted && info.writable !== false);
            if (button) button.textContent = granted ? 'Cambiar carpeta' : 'Elegir carpeta';
            if (clear) clear.classList.toggle('hidden', !granted);
            storageStatus(granted
                ? `Destino: ${info.name || 'carpeta seleccionada'}/`
                : 'No hay carpeta seleccionada. Las descargas seguirán usando el selector normal de Android.');
            return { ...info, granted };
        } catch (error) {
            storageStatus(`No se pudo consultar la carpeta: ${error.message || error}`);
            return { granted: false };
        }
    }

    async function chooseAndroidStorageFolder() {
        if (!directoryApi()) return;
        storageStatus('Abriendo el selector seguro de carpetas de Android…');
        try {
            const result = await window.WtaDirectory.pick();
            if (!result || !result.ok || !result.granted) {
                storageStatus(result && /cancel/i.test(String(result.error || result.message || ''))
                    ? 'Selección cancelada; se conserva el destino anterior.'
                    : `No se concedió acceso a la carpeta${result && (result.message || result.error) ? `: ${result.message || result.error}` : '.'}`);
                return;
            }
            await refreshAndroidStorageInfo();
            notifyStorage('CARPETA GUARDADA', `Las exportaciones se guardarán directamente en ${result.name || 'la carpeta elegida'}.`, 'fa-folder-check');
        } catch (error) {
            storageStatus(`No se pudo seleccionar la carpeta: ${error.message || error}`);
        }
    }

    async function writeDownloadStream(response, relativePath, fileName) {
        const total = Number(response.headers.get('content-length') || 0);
        let written = 0, started = false;
        const write = async bytes => {
            let offset = 0;
            while (offset < bytes.byteLength) {
                const end = Math.min(bytes.byteLength, offset + STREAM_CHUNK_BYTES);
                const chunk = bytes.subarray(offset, end);
                writeSafChunk(relativePath, chunk, started || written > 0);
                offset = end;
                written += chunk.byteLength;
                started = true;
            }
            const progress = total > 0 ? ` · ${Math.min(99, Math.floor(written * 100 / total))}%` : '';
            storageStatus(`Guardando ${fileName}${progress} · ${(written / 1048576).toFixed(1)} MB`);
        };
        if (!response.body || typeof response.body.getReader !== 'function') {
            const all = new Uint8Array(await response.arrayBuffer());
            for (let offset = 0; offset < all.length; offset += STREAM_CHUNK_BYTES) {
                await write(all.subarray(offset, Math.min(all.length, offset + STREAM_CHUNK_BYTES)));
            }
        } else {
            const reader = response.body.getReader();
            let pending = new Uint8Array(STREAM_CHUNK_BYTES), used = 0;
            try {
                for (;;) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    let cursor = 0;
                    while (cursor < value.byteLength) {
                        const count = Math.min(STREAM_CHUNK_BYTES - used, value.byteLength - cursor);
                        pending.set(value.subarray(cursor, cursor + count), used);
                        cursor += count;
                        used += count;
                        if (used === STREAM_CHUNK_BYTES) {
                            await write(pending);
                            pending = new Uint8Array(STREAM_CHUNK_BYTES);
                            used = 0;
                        }
                    }
                }
            } finally {
                reader.releaseLock();
            }
            if (used) await write(pending.subarray(0, used));
        }
        if (!started) writeSafChunk(relativePath, new Uint8Array(0), false);
        return written;
    }

    async function saveSelectedDownload(anchor) {
        const href = anchor.href;
        const fileName = safeFilename(anchor.download || new URL(href, location.href).pathname.split('/').pop());
        const url = new URL(href, location.href);
        const isBlob = url.protocol === 'blob:';
        if (!isBlob && (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol))) return false;

        const info = await refreshAndroidStorageInfo();
        if (!info.granted) return false;
        const destinationName = chooseNonClobberingFilename(fileName,
            (() => { try { return window.WtaDirectory.list(); } catch (_) { return null; } })());
        const relativePath = destinationName;

        storageStatus(`Descargando ${fileName}…`);
        try {
            const response = await fetch(href, { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            if (!isBlob) {
                const finalUrl = new URL(response.url || href, location.href);
                if (finalUrl.origin !== location.origin) throw new Error('El servidor redirigió fuera de la aplicación.');
            }
            const bytesWritten = await writeDownloadStream(response, relativePath, fileName);
            storageStatus(`Guardado en ${info.name || 'la carpeta elegida'}: ${relativePath} · ${(bytesWritten / 1048576).toFixed(1)} MB`);
            notifyStorage('DESCARGA GUARDADA', `${destinationName} se guardó en ${info.name || 'la carpeta elegida'}.`, 'fa-circle-check');
            return true;
        } catch (error) {
            try { window.WtaDirectory.remove?.(relativePath); } catch (_) {}
            storageStatus(`${error.message || error} Android continuará con su selector de descarga.`);
            return false;
        }
    }

    document.addEventListener('click', event => {
        const anchor = event.target && event.target.closest ? event.target.closest('a[download]') : null;
        if (!anchor || anchor.dataset.androidStorageBypass === 'true' || !directoryApi()) return;
        let url;
        try { url = new URL(anchor.href, location.href); } catch (_) { return; }
        if (url.protocol !== 'blob:' && url.origin !== location.origin) return;
        let info;
        try { info = window.WtaDirectory.info(); } catch (_) { return; }
        if (!info || !info.ok || !info.granted || info.writable === false) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        saveSelectedDownload(anchor).then(saved => {
          if (!saved) {
            anchor.dataset.androidStorageBypass = 'true';
            const wasConnected = anchor.isConnected;
            if (!wasConnected) document.body.appendChild(anchor);
            anchor.click();
            if (!wasConnected) anchor.remove();
            delete anchor.dataset.androidStorageBypass;
          }
        }).catch(() => {});
    }, true);

    window.ghmElegirCarpetaDescargasAndroid = chooseAndroidStorageFolder;
    window.ghmGuardarDescargaAndroid = async function(href, fileName) {
        if (!directoryApi()) return false;
        const anchor = document.createElement('a');
        anchor.href = href;
        anchor.download = safeFilename(fileName);
        return saveSelectedDownload(anchor);
    };
    window.ghmQuitarCarpetaDescargasAndroid = async function() {
        if (!directoryApi()) return;
        try {
            const result = window.WtaDirectory.release();
            if (result && result.ok === false) throw new Error(result.message || result.error || 'No se pudo quitar el acceso.');
            await refreshAndroidStorageInfo();
            notifyStorage('DESTINO QUITADO', 'Las descargas volverán al selector normal de Android.', 'fa-folder-minus');
        } catch (error) { storageStatus(error.message || String(error)); }
    };
    window.ghmActualizarDestinoAndroid = refreshAndroidStorageInfo;
    document.addEventListener('DOMContentLoaded', setBridgeVisibility, { once: true });
    window.addEventListener('WebToAppBridgeReady', setBridgeVisibility);
})();
