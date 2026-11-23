const fwUpdateOptions = [
    { firmware: '5.05', status: 'confirmed' },
    { firmware: '6.72', status: 'confirmed' },
    { firmware: '7.02', status: 'confirmed' },
    { firmware: '7.55', status: 'confirmed' },
    { firmware: '9.00', status: 'confirmed' },
    { firmware: '10.00', status: 'unverified' },
    { firmware: '10.01', status: 'unverified' },
    { firmware: '10.50', status: 'unverified' },
    { firmware: '11.00', status: 'confirmed' },
    { firmware: '12.00', status: 'unverified' },
    { firmware: '12.02', status: 'unverified' },
    { firmware: '12.50', status: 'unverified' },
    { firmware: '12.52', status: 'unverified' },
    { firmware: '13.00', status: 'unverified' },
    { firmware: '13.02', status: 'unverified' },
    { firmware: '13.50', status: 'unverified' },
    { firmware: '13.52', status: 'unverified' },
    { firmware: '14.00', status: 'unverified' },
    { firmware: '14.02', status: 'unverified' }
];

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('fw-updates-content');
    if (!root) return;
    root.innerHTML = fwUpdateOptions.map(({ firmware, status }) => {
        const verified = status === 'confirmed';
        const badge = verified
            ? '<span class="rounded-full border border-emerald-400/25 bg-emerald-400/10 px-2 py-1 text-[8px] font-bold text-emerald-200">Compatibilidad reportada · confirmada</span>'
            : '<span class="rounded-full border border-amber-400/25 bg-amber-400/10 px-2 py-1 text-[8px] font-bold text-amber-100">Por verificar</span>';
        const names = ['enable_updates_full.bin', 'disable_updates_full.bin'];
        const send = (name, label, color) => `<button type="button" onclick="fwPayloadSend('updates','${firmware}','${name}','${status}')" class="rounded-xl border ${color} p-3 text-[9px] font-bold hover:bg-white/10">${label}</button>`;
        return `<details class="rounded-2xl border ${verified ? 'border-emerald-400/20' : 'border-amber-400/15'} bg-[#0a0f1a] p-4"><summary class="cursor-pointer text-[11px] font-black text-white">Firmware ${firmware} <span class="ml-2">${verified ? '🟢' : '🟠'}</span></summary><div class="mt-3 mb-3">${badge}</div><p class="mb-3 text-[9px] leading-relaxed text-amber-100">Bloquear aplica la protección de tres capas. Permitir elimina esa protección, vuelve a habilitar descargas y puede exponer la consola a una actualización oficial.</p><div class="grid grid-cols-2 gap-2">${send(names[1], 'Bloquear · triple capa', 'border-emerald-400/15 bg-emerald-500/10 text-emerald-100')}${send(names[0], 'Permitir actualizaciones', 'border-rose-400/20 bg-rose-500/10 text-rose-100')}</div></details>`;
    }).join('');
});
