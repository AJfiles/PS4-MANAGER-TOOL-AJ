const fwFanOptions = {
    '5.05': ['50', '55', '60', '65', '70', '75', '80'],
    '9.00': ['50', '55', '60', '65', '70', '75', '80', 'Default']
};

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('coolers-content');
    if (!root) return;
    root.innerHTML = Object.entries(fwFanOptions).map(([fw, options]) => `
        <details class="rounded-2xl border border-white/10 bg-[#0a0f1a] p-4">
            <summary class="cursor-pointer text-[11px] font-black text-white">Firmware ${fw}</summary>
            <div class="mt-3 grid grid-cols-2 gap-2">${options.map(value => {
                const name = value === 'Default' ? 'fanDefault.bin' : `fan${value}.bin`;
                const label = value === 'Default' ? 'Perfil predeterminado' : `Límite ${value} °C`;
                return `<button onclick="fwPayloadSend('coolers','${fw}','${name}')" class="rounded-xl bg-cyan-500/10 p-3 text-[9px] font-bold text-cyan-200">${label}</button>`;
            }).join('')}</div>
        </details>`).join('');
});

let fwPayloadBusy = false;
window.fwPayloadSend = async function(action, firmware, name) {
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
    if (activatesUpdates) {
        if (!confirm(`1/3 · ADVERTENCIA: permitir actualizaciones puede hacer que la PS4 instale un firmware reciente y pierda compatibilidad con GoldHEN/jailbreak. ¿Deseas continuar para FW ${firmware}?`)) return;
        enableWarningAccepted = 'SI';
        if (!confirm(`2/3 · Confirma que la consola realmente usa FW ${firmware} y que deseas habilitar sus actualizaciones.`)) return;
        enableFirmwareConfirmed = firmware;
        enableConfirmation = prompt('3/3 · Para autorizar el envío, escribe exactamente: ACTIVAR ACTUALIZACIONES');
        if (enableConfirmation !== 'ACTIVAR ACTUALIZACIONES') {
            ps5Notification('CANCELADO', 'No se envió el payload; la frase no coincide.', 'fa-ban');
            return;
        }
    } else if (!confirm(`Enviar ${name} para firmware ${firmware}? Verifica que el firmware de la consola coincida exactamente.`)) return;

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
        const response = await fetch('api/fw_payload_api.php', { method: 'POST', body: form });
        const data = await response.json();
        if (data.status !== 'success') throw new Error(data.message || 'Falló el envío del payload.');
        ps5Notification('TRANSMISIÓN COMPLETA', `${name}: ${data.bytes} bytes enviados. La PS4 no confirma si aplicó el cambio; compruébalo en pantalla.`, 'fa-check');
    } catch (error) {
        ps5Notification('ERROR', error.message, 'fa-triangle-exclamation');
    } finally {
        fwPayloadBusy = false;
    }
};
