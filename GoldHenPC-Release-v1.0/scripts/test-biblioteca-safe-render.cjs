const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const source = fs.readFileSync(path.join(__dirname, '..', 'js', 'biblioteca.js'), 'utf8');
const helperStart = source.indexOf('function bibliotecaEscaparHtml(');
const helperEnd = source.indexOf('let categoriaAEliminar = null;', helperStart);
assert(helperStart >= 0 && helperEnd > helperStart, 'No se encontraron los helpers de seguridad de Biblioteca.');

const cards = [];
const grid = { innerHTML: '', appendChild: card => cards.push(card) };
const document = {
    getElementById: id => id === 'dom-grid-target' ? grid : null,
    createElement: tag => ({ tag, attributes: {}, style: { backgroundImage: '' }, dataset: {}, classList: { add() {}, remove() {} }, children: [], setAttribute(key, value) { this.attributes[key] = value; }, addEventListener() {}, append(...items) { this.children.push(...items); }, appendChild(item) { this.children.push(item); }, replaceChildren(...items) { this.children = items; } })
};
const context = { document, abrirOpcionesJuegoDirecto() {} };
const renderStart = source.indexOf('function renderizarGridDOM(');
const renderEnd = source.indexOf('function construirCoverflowDOMEstatico(', renderStart);
assert(renderStart >= 0 && renderEnd > renderStart, 'No se encontró el render de cuadrícula de Biblioteca.');
vm.runInNewContext(source.slice(helperStart, helperEnd) + source.slice(renderStart, renderEnd) + '\nthis.renderizarGridDOM = renderizarGridDOM; this.bibliotecaResolverImagenCarrusel = bibliotecaResolverImagenCarrusel;', context);

const attackTitle = '<img src=x onerror=alert(1)>';
context.renderizarGridDOM([
    { id: 'CUSA12345', nombre: attackTitle, img: 'javascript:alert(1)' },
    { id: 'CUSA54321', nombre: 'Título seguro', img: 'https://images.example.test/cover.png?v=2' }
]);
assert.equal(cards.length, 2);
assert.equal(cards[0].children[1].textContent, attackTitle, 'El título debe añadirse como texto, nunca como HTML.');
assert.equal(cards[0].children[0].children.length, 0, 'Debe rechazarse javascript: como imagen y mostrar placeholder.');
assert.equal(cards[1].children[0].children[0].src, 'https://images.example.test/cover.png?v=2');
assert.equal(cards[1].children[0].children[0].loading, 'lazy');

const carouselImage = {
    dataset: { fallback: 'user/cache/biblioteca/CUSA12345.png' },
    src: 'https://images.example.test/broken-cover.jpg',
    alt: 'Juego de prueba',
    getAttribute(name) { return name === 'src' ? this.src : null; },
    replaceWith(node) { this.replacement = node; }
};
assert.equal(context.bibliotecaResolverImagenCarrusel(carouselImage), 'fallback', 'El carrusel debe intentar el icono local cuando falla la portada principal.');
assert.equal(carouselImage.src, 'user/cache/biblioteca/CUSA12345.png', 'El fallback elegido debe quedar como fuente para futuras navegaciones.');
assert.equal(context.bibliotecaResolverImagenCarrusel(carouselImage), 'placeholder', 'Un segundo error debe sustituir la imagen rota por un placeholder visible.');
assert.equal(carouselImage.replacement.attributes.role, 'img');
assert.equal(carouselImage.replacement.attributes['aria-label'], 'Juego de prueba');
assert.match(source, /card\.classList\.toggle\('carousel-near', absOffset <= 2\)/, 'El carrusel debe mantener visibles solo las tarjetas vecinas para reducir pintura innecesaria.');
assert.match(source, /card\.classList\.toggle\('carousel-far', absOffset > 2\)/, 'Las tarjetas lejanas deben quedar ocultas durante la animación.');
assert.match(source, /<img \$\{idx === index3DActivo \? `src=/, 'El carrusel debe activar la portada central sin descargar toda la colección.');
assert.match(source, /data-src="\$\{bibliotecaEscaparHtml\(principal\)\}"[^>]*loading="lazy"/, 'Las portadas vecinas deben conservar carga diferida.');
assert.match(source, /const shouldLoad = absOffset <= 1/, 'Solo el título actual y sus vecinos inmediatos deben cargarse al navegar.');
assert.match(source, /text-install-state-3d/);

const escape = context.bibliotecaEscaparHtml || vm.runInNewContext(source.slice(helperStart, helperEnd) + '\nbibliotecaEscaparHtml;');
assert.equal(escape('"<script>&'), '&quot;&lt;script&gt;&amp;');
assert.doesNotMatch(source, /onerror="this\.src='\$\{jg\.img\}'"/);
assert.doesNotMatch(source, /onclick="prepararEliminacionContenido\('\$\{(?:dlc|data\.update)\.path\}/);
assert.match(source, /data-delete-update=/);
assert.match(source, /data-delete-dlc-index=/);

console.log('BIBLIOTECA_DYNAMIC_CONTENT_SAFE_OK');
