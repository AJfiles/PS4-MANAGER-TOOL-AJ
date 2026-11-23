const fwFanOptions = [
    { firmware: '5.05', status: 'confirmed', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] },
    { firmware: '6.72', status: 'unverified', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] },
    { firmware: '7.02', status: 'unverified', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] },
    { firmware: '7.55', status: 'unverified', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] },
    { firmware: '9.00', status: 'confirmed', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] },
    { firmware: '10.00', status: 'unverified', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] },
    { firmware: '10.01', status: 'unverified', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] },
    { firmware: '10.50', status: 'unverified', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] },
    { firmware: '11.00', status: 'unverified', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] },
    { firmware: '12.00', status: 'unverified', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] },
    { firmware: '12.02', status: 'unverified', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] },
    { firmware: '12.50', status: 'unverified', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] },
    { firmware: '12.52', status: 'unverified', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] },
    { firmware: '13.00', status: 'unverified', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] },
    { firmware: '13.02', status: 'unverified', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] },
    { firmware: '13.50', status: 'unverified', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] },
    { firmware: '13.52', status: 'unverified', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] },
    { firmware: '14.00', status: 'unverified', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] },
    { firmware: '14.02', status: 'unverified', values: ['50', '55', '60', '65', '70', '75', '80', 'default'] }
];

function fanLabel(value) {
    return value === 'default' ? 'Restaurar perfil original' : `Límite térmico ${value} °C`;
}

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('coolers-content');
    if (!root) return;
    root.innerHTML = fwFanOptions.map(({ firmware, status, values }) => {
        const verified = status === 'confirmed';
        const badge = verified
            ? '<span class="rounded-full border border-emerald-400/25 bg-emerald-400/10 px-2 py-1 text-[8px] font-bold text-emerald-200">Compatibilidad reportada · confirmada</span>'
            : '<span class="rounded-full border border-amber-400/25 bg-amber-400/10 px-2 py-1 text-[8px] font-bold text-amber-100">Por verificar</span>';
        const buttons = values.map(value => {
            const name = value === 'default' ? 'fan_default_universal.bin' : `fan_threshold_${value}_universal.bin`;
            return `<button type="button" onclick="fwPayloadSend('coolers','${firmware}','${name}','${status}')" class="rounded-xl border ${verified ? 'border-cyan-400/15 bg-cyan-500/10 text-cyan-100' : 'border-amber-400/15 bg-amber-400/5 text-amber-100'} p-3 text-[9px] font-bold hover:bg-white/10">${fanLabel(value)}</button>`;
        }).join('');
        return `<details class="rounded-2xl border ${verified ? 'border-emerald-400/20' : 'border-amber-400/15'} bg-[#0a0f1a] p-4"><summary class="cursor-pointer text-[11px] font-black text-white">Firmware ${firmware} <span class="ml-2">${verified ? '🟢' : '🟠'}</span></summary><div class="mt-3 mb-3">${badge}</div><div class="grid grid-cols-2 gap-2">${buttons}</div></details>`;
    }).join('');
});

let fwPayloadBusy = false;
window.fwPayloadSend = async function(action, firmware, name, compatibility = 'unverified') {
    if (fwPayloadBusy) {
        ps5Notification('ESPERA', 'El envío anterior todavía está comprobando BinLoader.', 'fa-hourglass-half');
        return;
    }
    const host = localStorage.getItem('sebas_ip_final_libre');
    if (!host) {
        ps5Notification('ERROR', 'Conecta una PS4 primero.', 'fa-wifi');
        return;
    }

    let enableConfirmation = '', enableWarningAccepted = '', enableFirmwareConfirmed = '';
    const activatesUpdates = action === 'updates' && /^enable/i.test(name);
    const compatibilityText = compatibility === 'confirmed' ? 'compatibilidad reportada como confirmada' : 'firmware marcado como pendiente de verificación';
    if (activatesUpdates) {
        if (!confirm(`1/3 · RIESGO ALTO: este payload quita la protección de tres capas y permite actualizaciones. Podrías perder GoldHEN/jailbreak. ¿Continuar para FW ${firmware}?`)) return;
        enableWarningAccepted = 'SI';
        if (!confirm(`2/3 · Confirma que la PS4 usa FW ${firmware}. Compatibilidad: ${compatibilityText}.`)) return;
        enableFirmwareConfirmed = firmware;
        enableConfirmation = prompt('3/3 · Escribe exactamente: ACTIVAR ACTUALIZACIONES');
        if (enableConfirmation !== 'ACTIVAR ACTUALIZACIONES') {
            ps5Notification('CANCELADO', 'No se envió el payload; la frase no coincide.', 'fa-ban');
            return;
        }
    } else if (!confirm(`Enviar ${name} a una PS4 que ejecuta FW ${firmware}? ${compatibilityText}. Verifica el firmware antes de continuar.`)) return;

    fwPayloadBusy = true;
    ps5Notification('ENVIANDO PAYLOAD', 'Enviando una sola vez por BinLoader…', 'fa-paper-plane');
    try {
        const form = new FormData();
        form.append('action', action);
        form.append('firmware', firmware);
        form.append('name', name);
        form.append('host_ip', host);
        if (enableConfirmation) {
            form.append('confirm_enable_warning', enableWarningAccepted);
            form.append('confirm_enable_firmware', enableFirmwareConfirmed);
            form.append('confirm_enable', enableConfirmation);
        }
        const response = await fetch('api/fw_payload_api.php', { method: 'POST', body: form, cache: 'no-store' });
        const raw = await response.text();
        let data;
        try { data = JSON.parse(raw); }
        catch (_) { throw new Error(`Respuesta no JSON (HTTP ${response.status}): ${raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160)}`); }
        if (!response.ok || data.status !== 'success') throw new Error(data.message || `Error HTTP ${response.status}`);
        ps5Notification('BYTES ENVIADOS', `${name}: ${data.bytes} bytes transmitidos. El servicio no confirma aplicación; verifica la consola antes de otro payload.`, 'fa-check');
    } catch (error) {
        ps5Notification('ERROR', error.message, 'fa-triangle-exclamation');
    } finally {
        fwPayloadBusy = false;
    }
};
