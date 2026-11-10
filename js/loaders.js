(() => {
    const allowed = ['orbit', 'pulse', 'scan', 'minimal'];
    const labels = { orbit: 'Órbita', pulse: 'Pulso', scan: 'Escáner', minimal: 'Minimal' };
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[char]);

    function style() {
        const selected = localStorage.getItem('cfg_loader_style') || 'orbit';
        return allowed.includes(selected) ? selected : 'orbit';
    }

    function render(title, detail, accent = 'cyan', selectedStyle = style()) {
        const safeStyle = allowed.includes(selectedStyle) ? selectedStyle : 'orbit';
        const safeAccent = ['cyan', 'rose', 'violet', 'amber', 'emerald'].includes(accent) ? accent : 'cyan';
        return `<div class="ghm-loading ghm-loader-${safeStyle}" data-accent="${safeAccent}" role="status" aria-live="polite">
            <div class="ghm-loader-art" aria-hidden="true"><span class="ghm-loader-ring ghm-loader-ring-a"></span><span class="ghm-loader-ring ghm-loader-ring-b"></span><span class="ghm-loader-ring ghm-loader-ring-c"></span><i class="ghm-loader-symbol fa-solid fa-gamepad"></i><span class="ghm-loader-sweep"></span></div>
            <b class="ghm-loader-title">${escapeHtml(title)}</b><span class="ghm-loader-detail">${escapeHtml(detail)}</span>
            <span class="ghm-loader-track" aria-hidden="true"><i></i></span><span class="ghm-loader-caption">${labels[safeStyle]} · PS4 Manager Tool</span>
        </div>`;
    }

    window.ghmLoaderMarkup = render;
    window.ghmLoaderStyle = style;
    window.ghmLoaderLabels = labels;
    window.ghmLoaderPreview = function (selectedStyle) {
        const box = document.getElementById('loader-preview');
        if (box) box.innerHTML = render('Leyendo la consola', 'La vista previa reproduce la animación elegida.', 'cyan', selectedStyle);
    };
})();
