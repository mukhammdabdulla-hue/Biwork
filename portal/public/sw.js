/* Biwork PWA: ilova sifatida o'rnatish uchun minimal xizmat ishchisi.
   Sahifalar har doim tarmoqdan olinadi (litsenziya va sessiya ma'lumotlari keshlanmaydi); faqat internet yo'q bo'lsa tushunarli xabar chiqadi. */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', function (e) {
  if (e.request.mode !== 'navigate') return;
  e.respondWith(fetch(e.request).catch(function () {
    return new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Biwork</title>' +
      '<body style="font:16px system-ui;display:grid;place-items:center;min-height:100vh;margin:0;background:#041C31;color:#fff;text-align:center"><div><h1>Internet yo\'q</h1><p>Biwork portali ishlashi uchun internet kerak. Ulanishni tekshirib qayta urinib ko\'ring.</p></div>',
      { headers: { 'Content-Type': 'text/html; charset=utf-8' }, status: 503 });
  }));
});
