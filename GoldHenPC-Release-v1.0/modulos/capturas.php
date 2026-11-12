<div id="layer-capturas" class="app-layer hidden flex flex-col p-4 h-screen w-full overflow-hidden bg-[#060913]"><header class="flex items-center justify-between border-b border-white/5 pb-3 shrink-0"><div><p class="text-[9px] uppercase tracking-[.3em] text-pink-300">GALERÍA DE CONSOLA</p><h2 class="text-2xl font-black uppercase">Capturas <span id="galeria-count" class="ml-2 text-xs font-mono text-emerald-300">0</span></h2></div><button onclick="volverAlLauncher()" class="w-10 h-10 rounded-xl border border-white/10 text-gray-300"><i class="fa-solid fa-house"></i></button></header>
    <div class="flex items-center justify-between px-3 pt-2"><span id="capture-filter-status" class="text-[9px] text-pink-200"></span><button id="capture-filter-clear" onclick="capturasQuitarFiltroJuego()" class="hidden rounded-lg border border-white/10 px-2 py-1 text-[9px] text-gray-300">Ver todas</button></div>
    <div id="galeria-grid-container" class="flex-1 min-h-0 w-full overflow-y-auto p-3 pb-24 custom-scrollbar" style="-webkit-overflow-scrolling:touch">
        <p id="captures-summary" class="mb-3 text-[10px] text-gray-400">La galería se carga bajo demanda desde la PS4. Puedes descargar o eliminar elementos desde cada captura.</p>
        <div class="mb-3 flex flex-wrap gap-2"><button onclick="capturasCargar(true)" class="rounded-lg border border-pink-400/20 px-3 py-2 text-[9px] font-bold text-pink-200"><i class="fa-solid fa-rotate mr-1"></i> Actualizar</button><button id="capture-select-all" onclick="seleccionarTodasCapturas()" class="rounded-lg border border-white/10 px-3 py-2 text-[9px] text-gray-300">Seleccionar todas</button><button onclick="descargarSeleccionCapturas()" class="rounded-lg border border-cyan-400/20 bg-cyan-500/10 px-3 py-2 text-[9px] font-bold text-cyan-200"><i class="fa-solid fa-download mr-1"></i> Descargar seleccionadas (ZIP)</button><button onclick="eliminarSeleccionCapturas()" class="rounded-lg border border-rose-400/20 bg-rose-500/10 px-3 py-2 text-[9px] font-bold text-rose-200"><i class="fa-solid fa-trash mr-1"></i> Eliminar seleccionadas</button><span id="capture-selection-count" class="self-center text-[9px] text-gray-500"></span></div>
        <div id="galeria-loader" class="w-full h-full flex flex-col items-center justify-center text-emerald-500 opacity-60">
            <i class="fa-solid fa-satellite-dish animate-pulse text-4xl mb-3"></i>
            <p class="text-[10px] font-mono tracking-widest uppercase">Descargando Caché...</p>
        </div>
        <div id="galeria-grid" class="grid grid-cols-2 sm:grid-cols-3 gap-2 hidden"></div>
    </div>
</div>

<div id="lightbox-visor" onclick="cerrarLightbox()" class="fixed inset-0 z-[900] bg-black/95 backdrop-blur-xl hidden flex items-center justify-center opacity-0 transition-opacity duration-300">
    <div class="absolute top-6 right-6 z-50 flex items-center gap-3">
        <button onclick="event.stopPropagation(); compartirImagenLightbox();" class="w-12 h-12 rounded-full bg-cyan-500/20 flex items-center justify-center text-cyan-400 backdrop-blur-md active:scale-90 transition-all border border-cyan-500/30 shadow-lg"><i class="fa-solid fa-share-nodes text-lg"></i></button>
        <button onclick="cerrarLightbox()" class="w-12 h-12 rounded-full bg-white/10 flex items-center justify-center text-white backdrop-blur-md active:scale-90 transition-all border border-white/20 shadow-lg"><i class="fa-solid fa-xmark text-lg"></i></button>
    </div>
    <div class="w-full h-full flex items-center justify-center p-2">
        <img id="lightbox-img" src="" class="max-w-full max-h-full object-contain rounded-lg shadow-[0_0_50px_rgba(0,0,0,0.8)] transform scale-95 transition-transform duration-300" onclick="event.stopPropagation()">
    </div>
    <div class="absolute bottom-6 w-full text-center px-4 pointer-events-none">
        <p id="lightbox-name" class="text-[10px] font-mono text-gray-400 tracking-widest bg-black/50 inline-block px-3 py-1 rounded-md backdrop-blur-sm border border-white/5"></p>
    </div>
</div>

