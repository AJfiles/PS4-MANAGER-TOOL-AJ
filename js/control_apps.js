/**
 * ====================================================================
 * PS4 MANAGER TOOLS - CONTROLADOR JS CONTROL DE APPS
 * Estado en primer plano, apertura remota y cierre con controles de seguridad
 * RUTA: js/control_apps.js
 * ====================================================================
 */

let caActiveApp = null;
let caInstalledTitles = [];
let caActionLogs = [];
let caIsRefreshing = false;
let caIsActionRunning = false;

// Cargar historial previo de localStorage
try {
    const raw = localStorage.getItem('ghm_app_control_history');
    if (raw) {
        const parsed = JSON.parse(raw);
        if (Array.isArray(parsed)) caActionLogs = parsed.slice(0, 20);
    }
} catch (_) {
    caActionLogs = [];
}

function caEscapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function caSafeImage(url) {
    if (!url || typeof url !== 'string') return '';
    const clean = url.trim();
    if (/^(https?:\/\/|\/|user\/|\.\/)/i.test(clean) && !/[\r\n"']/.test(clean)) {
        return clean;
    }
    return '';
}

function caGetConnection() {
    const ip = (localStorage.getItem('sebas_ip_final_libre') || '').trim();
    const port = Number(localStorage.getItem('sebas_port_libre') || 2121);
    return { ip, port };
}

function caUpdateConnectionBadge() {
    const { ip, port } = caGetConnection();
    const badgeText = document.getElementById('ca-connection-text');
    if (badgeText) {
        badgeText.textContent = ip ? `${ip}:${port}` : 'Sin IP guardada';
    }
}

function caLogAction(type, title, result, detail = '') {
    const entry = {
        time: new Date().toLocaleTimeString(),
        timestamp: Date.now(),
        type: String(type || 'action'),
        title: String(title || '---'),
        result: String(result || 'ok'),
        detail: String(detail || '')
    };
    caActionLogs.unshift(entry);
    if (caActionLogs.length > 20) caActionLogs.pop();

    try {
        localStorage.setItem('ghm_app_control_history', JSON.stringify(caActionLogs));
    } catch (_) {}

    caRenderLog();
}

function caRenderLog() {
    const container = document.getElementById('ca-log-container');
    if (!container) return;

    if (!caActionLogs.length) {
        container.innerHTML = '<p class="text-gray-600 italic">No hay acciones registradas en esta sesión.</p>';
        return;
    }

    const html = caActionLogs.map(log => {
        const isOk = log.result === 'ok' || log.result === 'success' || log.result === 'accepted';
        const color = isOk ? 'text-emerald-400' : 'text-rose-400';
        const badgeBg = isOk ? 'bg-emerald-500/10 border-emerald-500/20' : 'bg-rose-500/10 border-rose-500/20';
        return `
            <div class="p-2 rounded-lg bg-black/30 border border-white/5 flex items-start justify-between gap-2">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="px-1.5 py-0.2 rounded text-[7.5px] uppercase font-bold border ${badgeBg} ${color}">
                            ${caEscapeHtml(log.type)}
                        </span>
                        <b class="text-gray-200 truncate">${caEscapeHtml(log.title)}</b>
                    </div>
                    ${log.detail ? `<p class="text-[7.5px] text-gray-500 truncate mt-0.5">${caEscapeHtml(log.detail)}</p>` : ''}
                </div>
                <span class="text-[7px] text-gray-500 shrink-0 font-mono">${caEscapeHtml(log.time)}</span>
            </div>
        `;
    }).join('');

    container.innerHTML = html;
}

// Inicialización cuando se abre el módulo
async function controlAppsInit() {
    caUpdateConnectionBadge();
    caRenderLog();
    await controlAppsConsultarServicio();
    await controlAppsCargarPerfiles();
    if (!caInstalledTitles.length) {
        await controlAppsRecargarBiblioteca();
    }
}
window.controlAppsInit = controlAppsInit;

async function controlAppsCargarPerfiles() {
    const { ip, port } = caGetConnection();
    const select = document.getElementById('ca-launch-user');
    const status = document.getElementById('ca-launch-user-status');
    if (!ip || !select) return;

    try {
        const form = new FormData();
        form.append('action', 'user_profiles');
        form.append('host_ip', ip);
        form.append('port', String(port));
        form.append('request_id', `ca_users_${Date.now()}`);
        const response = await fetch('api/app_control_api.php', { method: 'POST', body: form, cache: 'no-store' });
        const data = await response.json();
        if (data.status !== 'success' || !Array.isArray(data.users)) {
            if (status) status.textContent = data.message || 'Inicia el servicio para consultar perfiles.';
            return;
        }

        const activeId = String(data.active_user_id || '');
        const previousUser = String(select.value || 'active');
        const entries = data.users.filter(user => user && /^\d+$/.test(String(user.id || '')));
        select.replaceChildren(new Option(window.ghmTranslateText?.('Usuario activo detectado') || 'Usuario activo detectado', 'active'));
        for (const user of entries) {
            const activeSuffix = String(user.id) === activeId ? ` · ${window.ghmTranslateText?.('Usuario activo') || 'activo'}` : '';
            const label = `${String(user.name || `Usuario ${user.id}`)}${activeSuffix}`;
            select.add(new Option(label, String(user.id)));
        }
        select.value = entries.some(user => String(user.id) === previousUser) ? previousUser : 'active';
        const profileMessage = entries.length > 1
            ? `${entries.length} perfiles con sesion iniciada; elige con quien abrir la app.`
            : (entries.length === 1 ? 'Perfil activo detectado en la PS4.' : 'No hay perfiles con sesion iniciada.');
        if (status) status.textContent = window.ghmTranslateText?.(profileMessage) || profileMessage;
    } catch (error) {
        if (status) status.textContent = 'No se pudieron consultar los perfiles.';
    }
}
window.controlAppsCargarPerfiles = controlAppsCargarPerfiles;

async function controlAppsConsultarServicio() {
    const { ip, port } = caGetConnection();
    if (!ip) return;
    const badge = document.getElementById('ca-service-badge');
    const dot = document.getElementById('ca-service-dot');
    const text = document.getElementById('ca-service-text');
    const autorunText = document.getElementById('ca-autorun-text');
    const btnAutorun = document.getElementById('ca-btn-toggle-autorun');

    try {
        const form = new FormData();
        form.append('action', 'service_status');
        form.append('host_ip', ip);
        form.append('port', String(port));
        form.append('request_id', `ca_srv_${Date.now()}`);

        const resp = await fetch('api/app_control_api.php', { method: 'POST', body: form, cache: 'no-store' });
        const data = await resp.json();

        if (data && data.status === 'success') {
            if (badge && text && dot) {
                if (data.service_active) {
                    if (data.requires_helper_update) {
                        badge.className = 'px-2.5 py-1 rounded-lg bg-amber-500/10 border border-amber-500/20 text-[9px] font-mono text-amber-300 flex items-center gap-1.5';
                        dot.className = 'w-1.5 h-1.5 rounded-full bg-amber-300';
                        text.textContent = 'Reinicio para actualizar';
                    } else {
                        badge.className = 'px-2.5 py-1 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-[9px] font-mono text-emerald-400 flex items-center gap-1.5';
                        dot.className = 'w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse';
                        text.textContent = 'Servicio 9091 Activo';
                    }
                } else {
                    badge.className = 'px-2.5 py-1 rounded-lg bg-red-500/10 border border-red-500/20 text-[9px] font-mono text-red-400 flex items-center gap-1.5';
                    dot.className = 'w-1.5 h-1.5 rounded-full bg-red-400';
                    text.textContent = 'Servicio 9091 Inactivo';
                }
            }
            if (autorunText && btnAutorun) {
                if (data.autorun_enabled) {
                    autorunText.textContent = 'AutoRun: Sí';
                    btnAutorun.className = 'h-10 px-3 rounded-xl bg-purple-500/20 border border-purple-400/40 text-purple-300 flex items-center gap-2 text-[9px] font-black uppercase tracking-wider active:scale-95 transition-all hover:bg-purple-500/30';
                } else {
                    autorunText.textContent = 'AutoRun: No';
                    btnAutorun.className = 'h-10 px-3 rounded-xl bg-purple-500/10 border border-purple-500/20 text-purple-400 flex items-center gap-2 text-[9px] font-black uppercase tracking-wider active:scale-95 transition-all hover:bg-purple-500/20';
                }
            }
        }
    } catch (_) {}
}
window.controlAppsConsultarServicio = controlAppsConsultarServicio;

async function controlAppsIniciarServicio() {
    const { ip, port } = caGetConnection();
    if (!ip) {
        window.ps5Notification?.('Control de Apps', 'No hay una IP guardada para la consola.', 'fa-network-wired');
        return;
    }
    const icon = document.getElementById('ca-service-icon');
    const btn = document.getElementById('ca-btn-iniciar-servicio');
    if (icon) icon.className = 'fa-solid fa-spinner fa-spin';
    if (btn) btn.disabled = true;

    try {
        const form = new FormData();
        form.append('action', 'start_service');
        form.append('host_ip', ip);
        form.append('port', String(port));
        form.append('request_id', `ca_start_${Date.now()}`);

        const resp = await fetch('api/app_control_api.php', { method: 'POST', body: form, cache: 'no-store' });
        const data = await resp.json();

        if (data && data.status === 'success') {
            window.ps5Notification?.('Control de Apps', data.message || 'Servicio iniciado en PS4.', 'fa-circle-check');
            caLogAction('servicio', 'Helper 9091', 'ok', data.message || 'Servicio activo en puerto 9091');
            await controlAppsCargarPerfiles();
        } else if (data && data.status === 'sent_unconfirmed') {
            window.ps5Notification?.('Control de Apps', data.message || 'BinLoader recibió el payload, pero 9091 no confirmó el servicio. No se reenvió.', 'fa-triangle-exclamation');
            caLogAction('servicio', 'Helper 9091', 'alerta', data.message || 'Envío realizado; ejecución sin confirmar.');
        } else {
            window.ps5Notification?.('Control de Apps', data?.message || 'Error al iniciar servicio.', 'fa-circle-exclamation');
            caLogAction('servicio', 'Helper 9091', 'error', data?.message || 'Error al iniciar');
        }
    } catch (err) {
        window.ps5Notification?.('Control de Apps', 'No se pudo comunicar con el servidor.', 'fa-circle-xmark');
        caLogAction('servicio', 'Helper 9091', 'error', err?.message || 'Error de red');
    } finally {
        if (icon) icon.className = 'fa-solid fa-play';
        if (btn) btn.disabled = false;
        await controlAppsConsultarServicio();
    }
}
window.controlAppsIniciarServicio = controlAppsIniciarServicio;

async function controlAppsToggleAutorun() {
    const { ip, port } = caGetConnection();
    if (!ip) {
        window.ps5Notification?.('Control de Apps', 'No hay una IP guardada para la consola.', 'fa-network-wired');
        return;
    }

    try {
        const form = new FormData();
        form.append('action', 'toggle_autorun');
        form.append('host_ip', ip);
        form.append('port', String(port));
        form.append('request_id', `ca_autorun_${Date.now()}`);

        const resp = await fetch('api/app_control_api.php', { method: 'POST', body: form, cache: 'no-store' });
        const data = await resp.json();

        if (data && data.status === 'success') {
            window.ps5Notification?.('Control de Apps', data.message, 'fa-power-off');
            caLogAction('ajuste', 'AutoRun payloads.ini', 'ok', data.message);
        } else {
            window.ps5Notification?.('Control de Apps', data?.message || 'Error al actualizar payloads.ini', 'fa-circle-exclamation');
            caLogAction('ajuste', 'AutoRun payloads.ini', 'error', data?.message || 'Fallo');
        }
    } catch (err) {
        window.ps5Notification?.('Control de Apps', 'Error al comunicarse con la consola.', 'fa-circle-xmark');
    } finally {
        await controlAppsConsultarServicio();
    }
}
window.controlAppsToggleAutorun = controlAppsToggleAutorun;

// ====================================================================
// 1. ACTUALIZAR ESTADO (MANUAL)
// ====================================================================
window.controlAppsActualizarEstado = async function (manual = true) {
    if (caIsRefreshing) return;
    const { ip, port } = caGetConnection();

    const btnRefresh = document.getElementById('ca-btn-actualizar-estado');
    const iconRefresh = document.getElementById('ca-refresh-icon');
    const statusBadge = document.getElementById('ca-status-badge');
    const foregroundTitle = document.getElementById('ca-foreground-title');
    const foregroundCusa = document.getElementById('ca-foreground-cusa');
    const foregroundVersion = document.getElementById('ca-foreground-version');
    const foregroundDetail = document.getElementById('ca-foreground-detail');
    const foregroundTime = document.getElementById('ca-foreground-time');
    const foregroundCover = document.getElementById('ca-foreground-cover');
    const btnCerrar = document.getElementById('ca-btn-cerrar-app');

    if (!ip) {
        if (statusBadge) {
            statusBadge.textContent = 'SIN CONEXIÓN';
            statusBadge.className = 'px-2 py-0.5 rounded text-[8px] font-black tracking-wider uppercase bg-amber-500/10 text-amber-300 border border-amber-500/20';
        }
        if (foregroundDetail) {
            foregroundDetail.textContent = 'Configura o descubre la IP de la consola desde el Radar antes de consultar.';
        }
        window.ps5Notification?.('Control de Apps', 'No hay una IP guardada para la consola.', 'fa-network-wired');
        return;
    }

    caIsRefreshing = true;
    if (iconRefresh) iconRefresh.classList.add('fa-spin');
    if (btnRefresh) btnRefresh.disabled = true;
    if (statusBadge) {
        statusBadge.textContent = 'CONSULTANDO...';
        statusBadge.className = 'px-2 py-0.5 rounded text-[8px] font-black tracking-wider uppercase bg-cyan-500/10 text-cyan-300 border border-cyan-500/20 animate-pulse';
    }

    try {
        const form = new FormData();
        form.append('action', 'status');
        form.append('host_ip', ip);
        form.append('port', String(port));
        form.append('request_id', `ca_${Date.now()}`);

        const resp = await fetch('api/app_control_api.php', {
            method: 'POST',
            body: form,
            cache: 'no-store'
        });

        const raw = await resp.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (_) {
            throw new Error(`Respuesta inválida del servidor (HTTP ${resp.status}).`);
        }

        const nowStr = new Date().toLocaleTimeString();

        if (!resp.ok || data.status !== 'success') {
            const errorMsg = data.message || `Error HTTP ${resp.status}`;
            if (statusBadge) {
                statusBadge.textContent = 'ERROR DE LECTURA';
                statusBadge.className = 'px-2 py-0.5 rounded text-[8px] font-black tracking-wider uppercase bg-rose-500/10 text-rose-300 border border-rose-500/20';
            }
            if (foregroundDetail) {
                foregroundDetail.textContent = errorMsg;
            }
            if (foregroundTime) {
                foregroundTime.textContent = `Hora de lectura: ${nowStr} (Fallida)`;
            }
            if (btnCerrar) btnCerrar.disabled = true;
            caActiveApp = null;
            caLogAction('consulta', 'Estado PS4', 'error', errorMsg);
            window.ps5Notification?.('Control de Apps', errorMsg, 'fa-triangle-exclamation');
            return;
        }

        // Caso 1: Aplicación en ejecución detectada
        if (data.app_running && data.title_id) {
            caActiveApp = {
                title_id: data.title_id,
                name: data.name || data.title_id,
                version: data.version || '01.00',
                icon: data.icon || '',
                method: data.method || 'ftp_sandbox',
                read_time: data.read_time || nowStr
            };

            if (statusBadge) {
                statusBadge.textContent = 'EN PRIMER PLANO';
                statusBadge.className = 'px-2 py-0.5 rounded text-[8px] font-black tracking-wider uppercase bg-emerald-500/10 text-emerald-300 border border-emerald-500/30';
            }
            if (foregroundTitle) foregroundTitle.textContent = caActiveApp.name;
            if (foregroundCusa) foregroundCusa.textContent = caActiveApp.title_id;
            if (foregroundVersion) foregroundVersion.textContent = `v${caActiveApp.version}`;
            if (foregroundDetail) {
                foregroundDetail.textContent = `Detectada mediante ${data.method === 'helper_daemon' ? 'servicio interno' : 'sandbox montado'}.`;
            }
            if (foregroundTime) {
                foregroundTime.textContent = `Hora de lectura: ${caActiveApp.read_time} (Manual)`;
            }

            const safeCover = caSafeImage(caActiveApp.icon);
            if (foregroundCover) {
                if (safeCover) {
                    foregroundCover.style.backgroundImage = `url("${safeCover.replace(/["\\]/g, '\\$&')}")`;
                    foregroundCover.style.backgroundSize = 'contain';
                    foregroundCover.style.backgroundRepeat = 'no-repeat';
                    foregroundCover.style.backgroundPosition = 'center';
                    foregroundCover.innerHTML = '';
                } else {
                    foregroundCover.style.backgroundImage = '';
                    foregroundCover.innerHTML = '<i class="fa-solid fa-gamepad text-3xl text-emerald-400"></i>';
                }
            }

            if (btnCerrar) btnCerrar.disabled = false;
            caLogAction('consulta', `${caActiveApp.name} (${caActiveApp.title_id})`, 'ok', 'En ejecución activa');
            if (manual) {
                window.ps5Notification?.('Control de Apps', `${caActiveApp.name} en ejecución`, 'fa-gamepad');
            }
        } else {
            // Caso 2: Menú principal activo (sin app en ejecución)
            caActiveApp = null;
            if (statusBadge) {
                statusBadge.textContent = 'MENÚ PRINCIPAL';
                statusBadge.className = 'px-2 py-0.5 rounded text-[8px] font-black tracking-wider uppercase bg-cyan-500/10 text-cyan-300 border border-cyan-500/20';
            }
            if (foregroundTitle) foregroundTitle.textContent = 'Menú principal (sin app en primer plano)';
            if (foregroundCusa) foregroundCusa.textContent = '---';
            if (foregroundVersion) foregroundVersion.textContent = '---';
            if (foregroundDetail) {
                foregroundDetail.textContent = 'No se detecta ningún juego o aplicación en ejecución.';
            }
            if (foregroundTime) {
                foregroundTime.textContent = `Hora de lectura: ${nowStr} (Manual)`;
            }
            if (foregroundCover) {
                foregroundCover.style.backgroundImage = '';
                foregroundCover.innerHTML = '<i class="fa-solid fa-house text-3xl text-cyan-400"></i>';
            }
            if (btnCerrar) btnCerrar.disabled = true;

            caLogAction('consulta', 'Menú principal', 'ok', 'Sin aplicación en primer plano');
            if (manual) {
                window.ps5Notification?.('Control de Apps', 'Menú principal activo (sin app)', 'fa-house');
            }
        }
    } catch (err) {
        const errorMsg = err.message || 'Error de comunicación con la consola';
        if (statusBadge) {
            statusBadge.textContent = 'ERROR DE CONEXIÓN';
            statusBadge.className = 'px-2 py-0.5 rounded text-[8px] font-black tracking-wider uppercase bg-rose-500/10 text-rose-300 border border-rose-500/20';
        }
        if (foregroundDetail) {
            foregroundDetail.textContent = errorMsg;
        }
        if (foregroundTime) {
            foregroundTime.textContent = `Hora de lectura: ${new Date().toLocaleTimeString()} (Error)`;
        }
        if (btnCerrar) btnCerrar.disabled = true;
        caActiveApp = null;
        caLogAction('consulta', 'Estado PS4', 'error', errorMsg);
        window.ps5Notification?.('Control de Apps', errorMsg, 'fa-circle-xmark');
    } finally {
        caIsRefreshing = false;
        if (iconRefresh) iconRefresh.classList.remove('fa-spin');
        if (btnRefresh) btnRefresh.disabled = false;
        controlAppsConsultarServicio();
    }
};

// ====================================================================
// 2. ABRIR APLICACIÓN EN PS4 (LANZAR)
// ====================================================================
window.controlAppsAbrirApp = async function (titleId, titleName = '') {
    if (caIsActionRunning) return;
    const { ip, port } = caGetConnection();

    if (!ip) {
        window.ps5Notification?.('Control de Apps', 'No hay una IP guardada para la consola.', 'fa-network-wired');
        return;
    }

    const safeTitleId = String(titleId || '').toUpperCase().trim();
    if (!/^[A-Z0-9._-]{4,16}$/.test(safeTitleId)) {
        window.ps5Notification?.('Control de Apps', 'Identificador Title ID no válido.', 'fa-triangle-exclamation');
        return;
    }

    const displayName = titleName || safeTitleId;
    caIsActionRunning = true;
    window.ps5Notification?.('Control de Apps', `Solicitando apertura de ${displayName}...`, 'fa-spinner fa-spin');

    try {
        const form = new FormData();
        form.append('action', 'launch');
        form.append('host_ip', ip);
        form.append('port', String(port));
        form.append('title_id', safeTitleId);
        form.append('user_id', document.getElementById('ca-launch-user')?.value || 'active');
        form.append('request_id', `ca_launch_${Date.now()}`);

        const resp = await fetch('api/app_control_api.php', {
            method: 'POST',
            body: form,
            cache: 'no-store'
        });

        const raw = await resp.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (_) {
            throw new Error(`Respuesta inválida (HTTP ${resp.status}).`);
        }

        if (data.status !== 'success' && data.status !== 'accepted') {
            throw new Error(data.message || `Error al solicitar apertura (${resp.status})`);
        }

        const confirmed = data.status === 'success';
        const stateMsg = confirmed ? 'Confirmada en primer plano' : 'Aceptada; estado aún sin confirmar';
        caLogAction('abrir', `${displayName} (${safeTitleId})`, confirmed ? 'ok' : 'alerta', data.message || stateMsg);
        window.ps5Notification?.('Control de Apps', data.message || `${displayName}: ${stateMsg}`, confirmed ? 'fa-circle-check' : 'fa-triangle-exclamation');

    } catch (err) {
        const errorMsg = err.message || 'No se pudo solicitar la apertura';
        caLogAction('abrir', `${displayName} (${safeTitleId})`, 'error', errorMsg);
        window.ps5Notification?.('Control de Apps', errorMsg, 'fa-circle-xmark');
    } finally {
        caIsActionRunning = false;
    }
};

// ====================================================================
// 3. CIERRE DE APLICACIÓN (NORMAL PRIMERO, FORZADO CON CONFIRMACIÓN)
// ====================================================================
window.controlAppsIniciarCierre = async function () {
    if (!caActiveApp || !caActiveApp.title_id) {
        window.ps5Notification?.('Control de Apps', 'No hay ninguna aplicación activa para cerrar.', 'fa-info-circle');
        return;
    }

    const { ip, port } = caGetConnection();
    if (!ip) {
        window.ps5Notification?.('Control de Apps', 'No hay una IP guardada para la consola.', 'fa-network-wired');
        return;
    }

    // Intentar primero cierre normal (graceful)
    window.ps5Notification?.('Control de Apps', `Verificando cierre normal de ${caActiveApp.name}...`, 'fa-spinner fa-spin');

    try {
        const form = new FormData();
        form.append('action', 'close_graceful');
        form.append('host_ip', ip);
        form.append('port', String(port));
        form.append('title_id', caActiveApp.title_id);
        form.append('request_id', `ca_cg_${Date.now()}`);

        const resp = await fetch('api/app_control_api.php', {
            method: 'POST',
            body: form,
            cache: 'no-store'
        });

        const raw = await resp.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (_) {
            data = null;
        }

        if (resp.ok && data && data.status === 'success') {
            caLogAction('cierre normal', `${caActiveApp.name} (${caActiveApp.title_id})`, 'ok', data.message);
            window.ps5Notification?.('Control de Apps', data.message || 'Aplicación cerrada normalmente.', 'fa-circle-check');
            caActiveApp = null;
            await controlAppsActualizarEstado(false);
            return;
        }
    } catch (_) {
        // Cierre normal no disponible o falló: continuar a flujo de confirmación forzada
    }

    // Si el cierre normal no existe o no responde: abrir modal de confirmación forzada
    controlAppsMostrarModalCierre();
};

function controlAppsMostrarModalCierre() {
    if (!caActiveApp) return;
    const modal = document.getElementById('modal-confirmar-cierre-forzado');
    const nameEl = document.getElementById('ca-modal-target-name');
    const cusaEl = document.getElementById('ca-modal-target-cusa');
    const iconEl = document.getElementById('ca-modal-target-icon');

    if (nameEl) nameEl.textContent = caActiveApp.name;
    if (cusaEl) cusaEl.textContent = caActiveApp.title_id;
    if (iconEl) {
        const safeCover = caSafeImage(caActiveApp.icon);
        if (safeCover) {
            iconEl.style.backgroundImage = `url("${safeCover.replace(/["\\]/g, '\\$&')}")`;
            iconEl.style.backgroundSize = 'contain';
            iconEl.style.backgroundRepeat = 'no-repeat';
            iconEl.style.backgroundPosition = 'center';
            iconEl.innerHTML = '';
        } else {
            iconEl.style.backgroundImage = '';
            iconEl.innerHTML = '<i class="fa-solid fa-gamepad"></i>';
        }
    }

    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}

function controlAppsCerrarModalCierre() {
    const modal = document.getElementById('modal-confirmar-cierre-forzado');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}
window.controlAppsCerrarModalCierre = controlAppsCerrarModalCierre;

window.controlAppsEjecutarCierreForzado = async function () {
    if (!caActiveApp || !caActiveApp.title_id) {
        controlAppsCerrarModalCierre();
        return;
    }

    const targetApp = { ...caActiveApp };
    controlAppsCerrarModalCierre();

    const { ip, port } = caGetConnection();
    if (!ip) return;

    window.ps5Notification?.('Control de Apps', `Enviando orden de cierre forzado para ${targetApp.name}...`, 'fa-spinner fa-spin');

    try {
        const form = new FormData();
        form.append('action', 'close_force');
        form.append('host_ip', ip);
        form.append('port', String(port));
        form.append('title_id', targetApp.title_id);
        form.append('confirm_force', '1'); // Flag explícito obligatorio
        form.append('request_id', `ca_cf_${Date.now()}`);

        const resp = await fetch('api/app_control_api.php', {
            method: 'POST',
            body: form,
            cache: 'no-store'
        });

        const raw = await resp.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (_) {
            throw new Error(`Respuesta inválida (${resp.status})`);
        }

        if (data.status !== 'success' && data.status !== 'accepted') {
            throw new Error(data.message || `Error al cerrar app (${resp.status})`);
        }

        const confirmed = data.status === 'success';
        caLogAction('cierre forzado', `${targetApp.name} (${targetApp.title_id})`, confirmed ? 'ok' : 'alerta', data.message || 'Respuesta recibida');
        window.ps5Notification?.('Control de Apps', data.message || 'Respuesta recibida.', confirmed ? 'fa-circle-check' : 'fa-triangle-exclamation');
        if (confirmed) {
            caActiveApp = null;
            await controlAppsActualizarEstado(false);
        }

    } catch (err) {
        const errorMsg = err.message || 'No se pudo completar el cierre forzado';
        caLogAction('cierre forzado', `${targetApp.name} (${targetApp.title_id})`, 'error', errorMsg);
        window.ps5Notification?.('Control de Apps', errorMsg, 'fa-circle-xmark');
    }
};

// ====================================================================
// 4. CATÁLOGO DE APPS INSTALADAS (BIBLIOTECA)
// ====================================================================
window.controlAppsRecargarBiblioteca = async function (force = false) {
    const listEl = document.getElementById('ca-installed-list');
    const badgeEl = document.getElementById('ca-installed-count-badge');
    if (listEl) {
        listEl.innerHTML = '<div class="p-6 text-center text-gray-500 text-[9px] font-mono uppercase"><i class="fa-solid fa-spinner fa-spin mr-2"></i>Cargando catálogo...</div>';
    }

    const { ip, port } = caGetConnection();

    try {
        const form = new FormData();
        form.append('action', 'installed_titles');
        if (ip) {
            form.append('host_ip', ip);
            form.append('port', String(port));
        }
        if (force) {
            form.append('force', '1');
        }
        form.append('request_id', `ca_cat_${Date.now()}`);

        const resp = await fetch('api/app_control_api.php', {
            method: 'POST',
            body: form,
            cache: 'no-store'
        });

        const raw = await resp.text();
        let data;
        try {
            data = JSON.parse(raw);
        } catch (_) {
            throw new Error(`Error en el formato del catálogo.`);
        }

        if (!resp.ok || data.status !== 'success' || !Array.isArray(data.titles)) {
            throw new Error(data.message || 'No se pudo leer el catálogo');
        }

        let titles = data.titles;

        // Si la lista vino vacía, intentar fallback a la caché de biblioteca existente
        if (!titles.length) {
            try {
                const libResp = await fetch('api/library_biblioteca.php?action=get_cached_games', { cache: 'no-store' });
                const libData = await libResp.json();
                if (libData && Array.isArray(libData.data) && libData.data.length > 0) {
                    titles = libData.data.map(g => ({
                        id: g.id,
                        name: g.nombre || g.name || g.id,
                        version: g.version || '01.00',
                        icon: g.img || '',
                        category: g.tipo || 'game'
                    }));
                }
            } catch (_) {}
        }

        caInstalledTitles = titles;
        if (badgeEl) badgeEl.textContent = `${caInstalledTitles.length} apps`;
        controlAppsRenderizarInstaladas(caInstalledTitles);

    } catch (err) {
        if (listEl) {
            listEl.innerHTML = `<div class="p-4 text-center text-rose-400 text-[9px] font-mono uppercase">${caEscapeHtml(err.message)}</div>`;
        }
    }
};

function controlAppsRenderizarInstaladas(titles) {
    const listEl = document.getElementById('ca-installed-list');
    if (!listEl) return;

    // Delegación de eventos: evita incrustar nombres de títulos dentro de JavaScript
    // inline (por ejemplo, títulos con apóstrofes) y conserva un solo listener.
    listEl.onclick = (event) => {
        const button = event.target?.closest?.('[data-ca-launch-id]');
        if (!button || !listEl.contains(button)) return;
        controlAppsAbrirApp(button.dataset.caLaunchId || '', button.dataset.caLaunchName || '');
    };

    if (!titles.length) {
        listEl.innerHTML = '<div class="p-6 text-center text-gray-500 text-[9px] font-mono uppercase">Sin aplicaciones en el inventario.</div>';
        return;
    }

    const html = titles.map(t => {
        const safeCover = caSafeImage(t.icon);
        const cover = safeCover
            ? `<img class="ca-title-icon absolute inset-0 w-full h-full object-contain p-0.5" src="${caEscapeHtml(safeCover)}" alt="Icono de ${caEscapeHtml(t.name || t.id)}" loading="lazy">`
            : '';

        return `
            <article class="min-w-0 p-2.5 rounded-xl bg-black/40 border border-white/5 hover:border-emerald-500/30 flex items-center justify-between gap-3 transition-colors shadow-sm">
                <div class="flex items-center gap-3 min-w-0 flex-1">
                    <div class="relative w-12 h-12 aspect-square rounded-xl bg-[#080d1a] border border-white/10 shrink-0 flex items-center justify-center text-sm overflow-hidden">
                        <i class="ca-title-icon-fallback fa-solid fa-gamepad text-emerald-400 text-sm"></i>
                        ${cover}
                    </div>
                    <div class="min-w-0 flex-1">
                        <b class="block text-[11px] font-bold text-white leading-snug break-words">${caEscapeHtml(t.name || t.id)}</b>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="px-1.5 py-0.5 rounded bg-black/60 border border-cyan-500/30 text-[8px] font-mono font-bold text-cyan-300 break-all">${caEscapeHtml(t.id)}</span>
                            <span class="text-[8px] font-mono text-gray-400 shrink-0">v${caEscapeHtml(t.version || '01.00')}</span>
                        </div>
                    </div>
                </div>
                <button type="button" data-ca-launch-id="${caEscapeHtml(t.id)}" data-ca-launch-name="${caEscapeHtml(t.name || t.id)}" class="px-3.5 py-2 rounded-xl bg-emerald-500/10 border border-emerald-500/25 text-emerald-300 font-black text-[8.5px] uppercase tracking-wider flex items-center gap-1.5 hover:bg-emerald-500/20 active:scale-95 transition-all shrink-0 shadow-sm">
                    <i class="fa-solid fa-play text-[8px]"></i> Abrir
                </button>
            </article>
        `;
    }).join('');

    listEl.innerHTML = html;
    if (typeof listEl.querySelectorAll !== 'function') return;
    listEl.querySelectorAll('img.ca-title-icon').forEach((image) => {
        image.addEventListener('error', () => {
            image.remove();
        }, { once: true });
        image.addEventListener('load', () => {
            const fallback = image.previousElementSibling;
            if (fallback?.classList.contains('ca-title-icon-fallback')) fallback.classList.add('hidden');
        }, { once: true });
    });
}

window.controlAppsFiltrarInstaladas = function () {
    const input = document.getElementById('ca-installed-search');
    if (!input) return;
    const q = input.value.trim().toLowerCase();

    if (!q) {
        controlAppsRenderizarInstaladas(caInstalledTitles);
        return;
    }

    const filtered = caInstalledTitles.filter(t => {
        return (t.name && t.name.toLowerCase().includes(q))
            || (t.id && t.id.toLowerCase().includes(q));
    });

    controlAppsRenderizarInstaladas(filtered);
};

// ====================================================================
// 5. REGISTRO TÉCNICO Y UTILIDADES
// ====================================================================
window.controlAppsCopiarDetalleTecnico = function () {
    const payload = {
        app_control_report: {
            generated_at: new Date().toISOString(),
            active_app: caActiveApp,
            recent_logs: caActionLogs
        }
    };

    const text = JSON.stringify(payload, null, 2);
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            window.ps5Notification?.('Control de Apps', 'Detalle técnico copiado al portapapeles.', 'fa-copy');
        }).catch(() => {
            caFallbackCopy(text);
        });
    } else {
        caFallbackCopy(text);
    }
};

function caFallbackCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand('copy');
        window.ps5Notification?.('Control de Apps', 'Detalle técnico copiado al portapapeles.', 'fa-copy');
    } catch (_) {
        window.ps5Notification?.('Control de Apps', 'No se pudo copiar automáticamente.', 'fa-circle-xmark');
    }
    document.body.removeChild(ta);
}

window.controlAppsLimpiarRegistro = function () {
    caActionLogs = [];
    try {
        localStorage.removeItem('ghm_app_control_history');
    } catch (_) {}
    caRenderLog();
    window.ps5Notification?.('Control de Apps', 'Registro de acciones limpiado.', 'fa-trash');
};

// ====================================================================
// 6. INTEGRACIÓN CON BIBLIOTECA
// ====================================================================
window.abrirControlAppsParaJuego = function (juego) {
    if (!juego || !juego.id) return;
    window.cerrarFichaJuego?.();
    window.abrirModuloNativo?.('control-apps');

    // Tras abrir la capa, solicitar abrir el juego
    setTimeout(() => {
        controlAppsAbrirApp(juego.id, juego.nombre || juego.id);
    }, 200);
};
