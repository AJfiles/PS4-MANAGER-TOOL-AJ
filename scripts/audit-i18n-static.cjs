const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'js', 'i18n.js'), 'utf8');
const object = source.match(/const translations = (\{[\s\S]*?\n    \});/);
if (!object) throw new Error('Translation dictionary not found.');
const dictionary = vm.runInNewContext(`(${object[1]})`);
const extensions = [...source.matchAll(/Object\.assign\(translations,\s*(\{[\s\S]*?\n\s*\})\s*\);/g)];
for (const extension of extensions) Object.assign(dictionary, vm.runInNewContext(`(${extension[1]})`));
const phpFiles = directory => fs.readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
    const fullPath = path.join(directory, entry.name);
    if (entry.isDirectory()) return phpFiles(fullPath);
    return entry.isFile() && entry.name.endsWith('.php') ? [fullPath] : [];
});
const files = [path.join(root, 'index.php'), ...phpFiles(path.join(root, 'modulos')), ...phpFiles(path.join(root, 'api'))];
const candidates = new Map();
const spanish = /[áéíóúñ¿¡]|\b(?:conecta|conexión|consola|juego|juegos|partida|partidas|respaldo|respaldos|carpeta|carpetas|ajustes|actualizar|actualización|seleccionar|eliminar|descargar|guardar|buscando|cargando|error|éxito|falló|fallo|perfil|perfiles|instalado|instalada|ocultas|capturas|almacenamiento|ventilador|parches|plugin|payloads|categoría|categorías|enviar|cancelar|aceptar|volver|crear|restaurar|borrar|estado|tamaño|archivos|red|puerto|biblioteca)\b/i;
function addPhrase(raw) {
    const phrase = raw.replace(/\s+/g, ' ').trim();
    if (phrase.length < 3 || !spanish.test(phrase) || dictionary[phrase]) return;
    candidates.set(phrase, (candidates.get(phrase) || 0) + 1);
}
for (const file of files) {
    if (file.split(path.sep).includes('api')) {
        const php = fs.readFileSync(file, 'utf8');
        const responsePatterns = [
            /(?:'message'|"message")\s*=>\s*(['"])((?:\\.|(?!\1)[\s\S])*?)\1/g,
            /new\s+RuntimeException\(\s*(['"])((?:\\.|(?!\1)[\s\S])*?)\1/g,
            /return\s+(['"])((?:\\.|(?!\1)[\s\S])*?)\1\s*;/g
        ];
        for (const pattern of responsePatterns) {
            for (const match of php.matchAll(pattern)) {
                const phrase = match[2].replace(/\\n/g, '\n').replace(/\$[A-Za-z_][A-Za-z0-9_]*(?:\[[^\]]+\])?/g, 'VARIABLE').replace(/\{\$([^}]+)\}/g, 'VARIABLE');
                addPhrase(phrase);
            }
        }
        continue;
    }
    let html = fs.readFileSync(file, 'utf8')
        .replace(/<\?(?:php|=)[\s\S]*?\?>/gi, ' ')
        .replace(/<!--[\s\S]*?-->/g, ' ')
        .replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, ' ')
        .replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, ' ')
        .replace(/<[^>]*>/g, '\n')
        .replace(/&nbsp;/gi, ' ')
        .replace(/&amp;/gi, '&').replace(/&quot;/gi, '"').replace(/&#39;|&apos;/gi, "'")
        .replace(/&lt;/gi, '<').replace(/&gt;/gi, '>');
    for (const raw of html.split(/\s*\n\s*/)) {
        const phrase = raw.replace(/\s+/g, ' ').trim();
        addPhrase(phrase);
    }
}
const jsRoot = path.join(root, 'js');
for (const entry of fs.readdirSync(jsRoot, { withFileTypes: true }).filter(entry => entry.isFile() && entry.name.endsWith('.js'))) {
    const js = fs.readFileSync(path.join(jsRoot, entry.name), 'utf8');
    for (const line of js.split(/\r?\n/)) {
        if (!/(?:innerHTML|textContent|ps5Notification|sysNotification|alert\(|confirm\(|prompt\(|placeholder|aria-label)/.test(line) || /^\s*(?:\/\/|\*|\/\*)/.test(line)) continue;
        for (const match of line.matchAll(/`([^`]*?)`/g)) {
            const rendered = match[1].replace(/\$\{[^}]*\}/g, 'VARIABLE').replace(/<[^>]*>/g, '\n').replace(/&nbsp;/gi, ' ').replace(/&amp;/gi, '&');
            for (const phrase of rendered.split(/\s*\n\s*/)) addPhrase(phrase);
        }
        for (const match of line.matchAll(/(['"])((?:\\.|(?!\1)[^\\])*?)\1/g)) {
            const rendered = match[2].replace(/<[^>]*>/g, '\n').replace(/&nbsp;/gi, ' ').replace(/&amp;/gi, '&');
            for (const phrase of rendered.split(/\s*\n\s*/)) addPhrase(phrase);
        }
    }
}
// Check candidates against the exact same dictionary/pattern logic used by the UI.
const text = value => ({ nodeType: 3, nodeValue: value });
const body = { nodeType: 1, childNodes: [], hasAttribute: () => false, getAttribute: () => null, setAttribute() {} };
const document = { body, documentElement: { lang: '' }, getElementById: () => null };
class MutationObserver { constructor() {} observe() {} }
const values = new Map([['ghm_language', 'en']]);
const context = {
    window: { alert() {}, confirm() { return true; }, prompt() { return ''; } }, document,
    Node: { TEXT_NODE: 3, ELEMENT_NODE: 1 }, MutationObserver,
    localStorage: { getItem: key => values.get(key) || null, setItem: (key, value) => values.set(key, value) }
};
vm.runInNewContext(source, context, { filename: 'js/i18n.js' });
const unmatched = new Map();
function isNonUiFragment(phrase) {
    // The source scan also sees partial PHP concatenation literals and CSS values; the browser never renders these as complete text.
    if (/^\)/.test(phrase) || /[:(]$/.test(phrase)) return true;
    if (/^(?:patch-plugin-status|text-red-|ml-1 |w-6 |w-full )/.test(phrase)) return true;
    if (phrase.includes('${bibliotecaEscaparHtml(') || phrase.includes('${oaAttr(')) return true;
    if (phrase.includes('${filter ?') || phrase.includes('No se detectaron partidas.') && phrase.endsWith('}') || phrase.startsWith(": ''} · VARIABLE de juegos no instalados")) return true;
    if (['Borrado permanente sin respaldo. Se eliminarán', 'Se crearán respaldos ZIP verificados para', 'Se crearán ZIP verificados de'].includes(phrase)) return true;
    // These are either language-neutral labels or application-controlled runtime details, not translatable source copy.
    if (phrase === 'error' || /^FTP .+ · error$/.test(phrase) || /^\[ERROR\] VARIABLE$/.test(phrase)) return true;
    return false;
}
for (const [phrase, count] of candidates) {
    const sample = phrase.replace(/\\n/g, '\n')
        .replace(/^VARIABLE VARIABLE con parches disponibles$/, '2 juegos con parches disponibles')
        .replace(/VARIABLE/g, '2');
    if (context.window.ghmTranslateText(sample) === sample && !isNonUiFragment(phrase)) unmatched.set(phrase, count);
}
const rows = [...unmatched].sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]));
console.log(`STATIC_UNTRANSLATED_CANDIDATES=${rows.length}`);
for (const [phrase, count] of rows) console.log(`${count}\t${phrase}`);
