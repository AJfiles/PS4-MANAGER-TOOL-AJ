const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '..');
const app = fs.readFileSync(path.join(root, 'js', 'app.js'), 'utf8');
const page = fs.readFileSync(path.join(root, 'index.php'), 'utf8');
assert.match(app, /function animarAcercamientoRadar\(galaxy\)/);
assert.match(app, /galaxy\.classList\.add\('approach'\)[\s\S]{0,250}galaxy\.classList\.add\('found'\)/);
const radarFlowStart = app.indexOf('async function lanzarRadarVentanaEmergente()');
const radarFlowEnd = app.indexOf('\nfunction abortarYEstabilizarRadar()', radarFlowStart);
const radarFlow = app.slice(radarFlowStart, radarFlowEnd);
assert.equal((radarFlow.match(/confirmarDeteccionRadar\(galaxy, logTerm/g) || []).length, 2, 'La detecciÃ³n directa y el escaneo LAN deben usar el mismo flujo visual.');
assert.doesNotMatch(app, /PS4 HALLADA EN CACHÃ‰/);
assert.match(app, /Validando en vivo la IP guardada/);
assert.match(app, /function confirmarDeteccionRadar\([\s\S]*?programarCierreRadarExitoso\(\)/);
const foundIndex = radarFlow.indexOf('confirmarDeteccionRadar(galaxy, logTerm');
assert.ok(foundIndex >= 0 && radarFlow.indexOf('void verificarRadarInicial()', foundIndex) > foundIndex, 'El cierre debe empezar antes de la actualizaciÃ³n secundaria del estado.');
assert.match(page, /\.radar-galaxy\s*\{[^}]*max-width:100%[^}]*overflow:hidden/);
assert.match(page, /\.radar-galaxy\.approach\s*\{[^}]*filter:/);
assert.match(page, /\.radar-galaxy\.approach \.radar-planet\s*\{[^}]*animation:none;transform:translate\(-50%,-50%\) scale\(1\.45\);opacity:1/,
    'On detection, the orbiting probe must move toward the center and visibly approach the planet.');
assert.match(page, /\.radar-galaxy\.found \.radar-sun\s*\{[^}]*animation:radar-earth-lock/,
    'The planet should settle into a clear visual lock animation after the approach.');
assert.match(page, /\.radar-galaxy\.found \.radar-sun::before\s*\{[^}]*opacity:\.92[^}]*scale\(1\)/,
    'The detected planet should reveal its surface detail.');
assert.doesNotMatch(page, /\.radar-galaxy\.(?:approach|found)\s*\{[^}]*transform:scale\(/, 'La animaciÃ³n no debe ampliar la galaxia fuera de su marco.');
assert.match(page, /@keyframes radar-planet[\s\S]*?transform:translate\(-50%,-50%\) rotate\(360deg\) translateX\(42px\)/);
assert.match(page, /\.radar-galaxy\.found \.radar-planet\s*\{[^}]*scale\(\.18\);opacity:0/);
assert.match(page, /\.radar-galaxy\.found \.radar-sun\s*\{[^}]*#10b981[^}]*box-shadow/);
assert.match(page, /@keyframes radar-signal-pulse/);
assert.match(page, /@keyframes radar-sweep/);
assert.match(page, /@keyframes radar-lock-flash/);
assert.match(page, /id="radar-visual-status"/);
assert.match(page, /id="radar-log-scanline"/);
assert.match(app, /PS4 DETECTADA · ENLACE ACTIVO/);
assert.match(app, /scanline\?\.classList\.add\('hidden'\)/);
assert.match(page, /prefers-reduced-motion:\s*reduce[^}]*\.radar-sun/);
assert.match(page, /id="radar-log-terminal"/, 'La vista debe conservar el log del radar.');
assert.match(page, /id="radar-success-countdown"[^>]*aria-live="polite"/);
assert.match(page, /onclick="abortarYEstabilizarRadar\(\)"/, 'El usuario debe poder cerrar el radar manualmente.');
assert.match(app, /radarTimeoutId = setTimeout\([\s\S]{0,100}5000\)/, 'El radar debe cerrarse cinco segundos despuÃ©s de una detecciÃ³n satisfactoria.');
assert.match(page, /\.radar-sun::after\s*\{[^}]*radar-signal-pulse/);
assert.match(page, /hash_file\('sha256', \$file\)/, 'La URL de cada asset debe cambiar cuando cambia su contenido.');
assert.match(app, /if \(validarEstructuraIP\(candidateIP\)\) \{\s*logTerm\.innerHTML \+= `<p class="text-yellow-500">La IP guardada no respondió en vivo;/, 'El aviso de red offline debe mostrarse solo cuando falla la IP guardada.');
console.log('RADAR_LIVE_VERIFY_NEW_ANIMATION_CACHE_BUST_AND_5S_CLOSE_OK');
