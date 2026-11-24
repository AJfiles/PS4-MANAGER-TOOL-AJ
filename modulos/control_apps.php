<?php
/**
 * ====================================================================
 * PS4 MANAGER TOOLS - MÓDULO CONTROL DE APPS
 * Estado en primer plano, apertura remota y cierre con controles de seguridad
 * RUTA: modulos/control_apps.php
 * ====================================================================
 */
?>
<div id="layer-control-apps" class="app-layer hidden flex min-h-0 flex-col p-3 sm:p-4 h-dvh w-full overflow-hidden bg-[#060913]">
    <!-- Encabezado del módulo -->
    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 shrink-0 mb-3">
        <div class="flex items-center gap-3">
            <button onclick="volverAlLauncher()" aria-label="Volver al menú" class="w-10 h-10 rounded-xl bg-white/5 border border-white/10 text-gray-300 flex items-center justify-center active:scale-95 transition-all hover:bg-white/10">
                <i class="fa-solid fa-arrow-left"></i>
            </button>
            <div>
                <h2 class="text-[17px] font-black uppercase text-white tracking-wide">Control de Apps</h2>
                <p class="text-[8px] text-emerald-400 font-mono tracking-widest uppercase">ESTADO, ABRIR Y CERRAR EN PS4</p>
            </div>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2 max-w-full">
            <span id="ca-connection-badge" class="px-2.5 py-1 rounded-lg bg-black/40 border border-white/10 text-[9px] font-mono text-gray-400">
                <i class="fa-solid fa-network-wired text-cyan-400 mr-1.5"></i><span id="ca-connection-text">---</span>
            </span>
            <span id="ca-service-badge" class="px-2.5 py-1 rounded-lg bg-red-500/10 border border-red-500/20 text-[9px] font-mono text-red-400 flex items-center gap-1.5" title="Estado del helper en puerto 9091">
                <span id="ca-service-dot" class="w-1.5 h-1.5 rounded-full bg-red-400"></span>
                <span id="ca-service-text">Servicio 9091</span>
            </span>
            <button id="ca-btn-iniciar-servicio" onclick="controlAppsIniciarServicio()" aria-label="Iniciar servicio de control en PS4" title="Iniciar servicio en la consola" class="h-10 px-3 rounded-xl bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 flex items-center gap-2 text-[9px] font-black uppercase tracking-wider active:scale-95 transition-all hover:bg-cyan-500/20">
                <i class="fa-solid fa-play" id="ca-service-icon"></i>
                <span class="hidden sm:inline">Iniciar Servicio</span>
            </button>
            <button id="ca-btn-toggle-autorun" onclick="controlAppsToggleAutorun()" aria-label="Configurar inicio con la consola" title="Iniciar con la consola (payloads.ini)" class="h-10 px-3 rounded-xl bg-purple-500/10 border border-purple-500/20 text-purple-400 flex items-center gap-2 text-[9px] font-black uppercase tracking-wider active:scale-95 transition-all hover:bg-purple-500/20">
                <i class="fa-solid fa-power-off" id="ca-autorun-icon"></i>
                <span id="ca-autorun-text" class="hidden sm:inline">AutoRun</span>
            </button>
            <button id="ca-btn-actualizar-estado" onclick="controlAppsActualizarEstado(true)" aria-label="Actualizar estado de la consola" class="h-10 px-3 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center gap-2 text-[9px] font-black uppercase tracking-wider active:scale-95 transition-all hover:bg-emerald-500/20">
                <i class="fa-solid fa-rotate-right" id="ca-refresh-icon"></i>
                <span class="hidden sm:inline">Actualizar estado</span>
            </button>
        </div>
    </div>

    <!-- Contenido desplazable con scrollbar visible y espacio inferior para navegación -->
    <div class="flex-1 min-h-0 min-w-0 overflow-y-auto custom-scrollbar flex flex-col gap-4 pb-28">

        <!-- Tarjeta de Estado en Primer Plano -->
        <div class="w-full min-w-0 bg-[#0a0f1a] border border-white/10 rounded-2xl p-4 sm:p-5 shadow-2xl relative overflow-hidden shrink-0">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-4 border-b border-white/5 pb-2.5">
                <span class="text-[10px] uppercase font-black tracking-widest text-emerald-400 flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-pulse"></span>
                    Aplicación en primer plano
                </span>
                <span id="ca-status-badge" class="px-2.5 py-1 rounded-lg text-[9px] font-black tracking-wider uppercase bg-white/5 text-gray-400 border border-white/10">
                    SIN CONSULTAR
                </span>
            </div>

            <div class="flex min-w-0 items-center gap-4 sm:gap-5">
                <!-- Icono de la app en cuadrado 1:1 de 80x80 / 96x96 -->
                <div id="ca-foreground-cover" class="w-20 h-20 sm:w-24 sm:h-24 aspect-square rounded-2xl bg-[#070b14] border border-white/10 overflow-hidden flex items-center justify-center shrink-0 shadow-lg bg-cover bg-center text-emerald-400 transition-all">
                    <i class="fa-solid fa-gamepad text-3xl"></i>
                </div>

                <div class="flex flex-col flex-1 min-w-0 overflow-hidden">
                    <!-- Nombre de la app grande y destacado -->
                    <h3 id="ca-foreground-title" class="text-base sm:text-lg font-black text-white uppercase truncate leading-tight drop-shadow tracking-wide">
                        Menú principal (sin app)
                    </h3>

                    <!-- CUSA y Versión ordenados abajo -->
                    <div class="flex flex-wrap items-center gap-2 mt-2">
                        <span id="ca-foreground-cusa" class="px-2.5 py-1 rounded-lg bg-black/60 border border-cyan-500/30 text-[10px] font-mono font-bold text-cyan-300 tracking-wider">
                            ---
                        </span>
                        <span id="ca-foreground-version" class="px-2.5 py-1 rounded-lg bg-black/60 border border-white/10 text-[9px] font-mono text-gray-300">
                            ---
                        </span>
                    </div>

                    <p id="ca-foreground-detail" class="text-[9px] text-gray-400 mt-2 truncate leading-relaxed">
                        Pulsa «Actualizar estado» para consultar la consola manualmente.
                    </p>
                    <p id="ca-foreground-time" class="text-[8px] font-mono text-gray-500 mt-1">
                        Hora de lectura: No realizada
                    </p>
                </div>
            </div>

            <!-- Botones de acción sobre la app en primer plano -->
            <div class="mt-4 pt-3 border-t border-white/5 flex flex-wrap gap-2 justify-end">
                <button id="ca-btn-cerrar-app" type="button" onclick="controlAppsIniciarCierre()" disabled class="px-4 py-2.5 rounded-xl border border-red-500/30 bg-red-500/10 text-red-300 font-black text-[9px] uppercase tracking-wider flex items-center gap-2 active:scale-95 transition-all disabled:opacity-40 disabled:pointer-events-none hover:bg-red-500/20 shadow-md">
                    <i class="fa-solid fa-power-off"></i> Cerrar aplicación
                </button>
            </div>
        </div>

        <!-- Catálogo de Apps Instaladas para Apertura Directa -->
        <div class="w-full min-w-0 bg-[#0a0f1a] border border-white/10 rounded-2xl p-4 sm:p-5 shadow-2xl flex flex-col gap-3 shrink-0">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-white/5 pb-2.5">
                <div>
                    <span class="text-[10px] uppercase font-black tracking-widest text-white block">
                        Lanzar app desde Biblioteca
                    </span>
                    <p class="text-[8.5px] text-gray-400 mt-0.5">Selecciona una aplicación instalada para solicitar su apertura en la PS4.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="controlAppsRecargarBiblioteca(true)" class="px-3 py-1.5 rounded-xl bg-white/5 border border-white/10 text-gray-300 text-[8.5px] font-black uppercase tracking-wider flex items-center gap-1.5 hover:bg-white/10 active:scale-95 transition-all">
                        <i class="fa-solid fa-rotate"></i> Actualizar Biblioteca
                    </button>
                    <span id="ca-installed-count-badge" class="px-2.5 py-1 rounded-lg bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[8.5px] font-mono font-bold">
                        0 apps
                    </span>
                </div>
            </div>

            <!-- Barra de búsqueda local -->
            <div class="flex flex-wrap items-center gap-2 rounded-xl border border-cyan-500/15 bg-cyan-500/5 px-3 py-2">
                <label for="ca-launch-user" class="text-[8.5px] font-black uppercase tracking-wider text-cyan-200">Abrir como perfil</label>
                <select id="ca-launch-user" class="min-w-48 flex-1 rounded-lg border border-white/10 bg-[#080d18] px-3 py-2 text-[10px] font-bold text-white outline-none focus:border-cyan-400/50">
                    <option value="active">Usuario activo detectado</option>
                </select>
                <button type="button" onclick="controlAppsCargarPerfiles()" class="shrink-0 rounded-lg border border-cyan-500/20 bg-cyan-500/10 px-3 py-2 text-[8px] font-black uppercase tracking-wider text-cyan-200 hover:bg-cyan-500/20 active:scale-95 transition-all">
                    <i class="fa-solid fa-rotate mr-1"></i><span data-i18n="Actualizar perfiles">Actualizar perfiles</span>
                </button>
                <span id="ca-launch-user-status" class="text-[8px] text-gray-400">Se consultará al iniciar el servicio.</span>
            </div>

            <div class="relative w-full h-9 bg-black/40 border border-white/10 rounded-xl overflow-hidden focus-within:border-emerald-500/40 shadow-inner flex items-center px-3">
                <i class="fa-solid fa-magnifying-glass text-gray-500 text-[10px] mr-2"></i>
                <input type="text" id="ca-installed-search" oninput="controlAppsFiltrarInstaladas()" placeholder="Buscar app por título o CUSA..." class="w-full h-full bg-transparent text-[11px] font-bold text-white outline-none">
            </div>

            <!-- Lista de apps instaladas -->
            <div id="ca-installed-list" class="grid grid-cols-1 xl:grid-cols-2 gap-2 max-h-[min(55vh,620px)] overflow-y-auto custom-scrollbar pr-1">
                <div class="p-6 text-center text-gray-500 text-[9px] font-mono uppercase">
                    Cargando aplicaciones instaladas...
                </div>
            </div>
        </div>

        <!-- Registro Local de Auditoría y Acciones Técnicas -->
        <div class="w-full min-w-0 bg-[#0a0f1a] border border-white/10 rounded-2xl p-4 sm:p-5 shadow-2xl flex flex-col gap-3 shrink-0">
            <div class="flex items-center justify-between border-b border-white/5 pb-2.5">
                <div>
                    <span class="text-[10px] uppercase font-black tracking-widest text-white">Registro de acciones</span>
                    <p class="text-[8.5px] text-gray-400 mt-0.5">Historial local de consultas, aperturas y cierres (sin datos personales).</p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="controlAppsCopiarDetalleTecnico()" class="px-2.5 py-1.5 rounded-xl bg-cyan-500/10 border border-cyan-500/20 text-cyan-300 text-[8.5px] font-black uppercase tracking-wider flex items-center gap-1 active:scale-95 transition-all hover:bg-cyan-500/20">
                        <i class="fa-solid fa-copy"></i> Copiar detalle técnico
                    </button>
                    <button type="button" onclick="controlAppsLimpiarRegistro()" class="px-2.5 py-1.5 rounded-xl bg-white/5 border border-white/10 text-gray-400 text-[8.5px] font-black uppercase tracking-wider active:scale-95 transition-all hover:bg-white/10">
                        Limpiar
                    </button>
                </div>
            </div>

            <div id="ca-log-container" class="space-y-1.5 max-h-[200px] overflow-y-auto custom-scrollbar pr-1 font-mono text-[8.5px]">
                <p class="text-gray-600 italic">No hay acciones registradas en esta sesión.</p>
            </div>
        </div>

    </div>
</div>

<!-- Modal de Confirmación de Cierre Forzado -->
<div id="modal-confirmar-cierre-forzado" class="fixed inset-0 z-[10070] hidden items-center justify-center bg-black/80 p-4 backdrop-blur-md" onclick="if(event.target===this)controlAppsCerrarModalCierre()">
    <section class="glass-premium w-full max-w-sm rounded-[2rem] p-6 shadow-2xl flex flex-col bg-[#070b14]/95 border border-red-500/30 transform scale-95 transition-transform duration-300">
        <div class="relative w-14 h-14 rounded-full bg-red-500/10 flex items-center justify-center text-red-500 text-2xl mx-auto mb-3 border border-red-500/30 shadow-[0_0_20px_rgba(239,68,68,0.2)]">
            <div class="absolute inset-0 rounded-full border border-red-500/50 animate-ping opacity-25"></div>
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>

        <h3 class="text-xs font-black tracking-widest text-white uppercase text-center mb-1">
            Confirmar cierre de aplicación
        </h3>
        <p class="text-[9px] text-red-400 font-mono tracking-widest uppercase text-center mb-4">
            Advertencia de progreso no guardado
        </p>

        <div class="rounded-xl border border-white/10 bg-black/40 p-3 mb-4 flex items-center gap-3">
            <div id="ca-modal-target-icon" class="w-12 h-12 rounded-xl bg-red-950/30 border border-red-500/20 flex items-center justify-center text-red-400 shrink-0 bg-cover bg-center overflow-hidden">
                <i class="fa-solid fa-gamepad"></i>
            </div>
            <div class="min-w-0 flex-1">
                <b id="ca-modal-target-name" class="block text-[11px] text-white uppercase truncate">---</b>
                <span id="ca-modal-target-cusa" class="block text-[9px] font-mono text-red-300 tracking-wider">---</span>
            </div>
        </div>

        <p class="text-[9px] text-gray-300 text-center leading-relaxed mb-6 px-1">
            Si la aplicación tiene partidas o datos no guardados, <b class="text-red-400 font-black">se perderán definitivamente</b>. Esta acción detendrá el proceso activo en la consola.
        </p>

        <div class="flex gap-2 w-full">
            <button type="button" onclick="controlAppsCerrarModalCierre()" class="flex-1 py-3 rounded-xl border border-white/10 bg-white/5 text-[10px] font-black tracking-widest uppercase text-gray-300 active:scale-95 transition-all hover:bg-white/10">
                Cancelar
            </button>
            <button id="ca-btn-confirmar-cierre-forzado" type="button" onclick="controlAppsEjecutarCierreForzado()" class="flex-1 py-3 rounded-xl bg-gradient-to-r from-red-600 to-red-800 border border-red-500/50 text-[10px] font-black tracking-widest uppercase text-white active:scale-95 transition-all shadow-[0_0_20px_rgba(220,38,38,0.5)] flex items-center justify-center gap-1.5">
                <i class="fa-solid fa-power-off"></i> Confirmar cierre
            </button>
        </div>
    </section>
</div>
