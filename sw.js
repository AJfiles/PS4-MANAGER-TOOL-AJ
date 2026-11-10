// Dejar que las cabeceras HTTP y las URL versionadas controlen la actualización.
// No cachear HTML, scripts ni respuestas de API: podrían conservar vistas antiguas.
self.addEventListener('install', (e) => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(clients.claim()));
self.addEventListener('fetch', (e) => e.respondWith(fetch(e.request)));
