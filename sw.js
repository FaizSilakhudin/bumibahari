const CACHE = 'wbb-v1';

// Aset statis yang di-cache saat install
const SHELL = [
    './',
    './login',
    './assets/icons/icon-192.png',
    './assets/icons/icon-512.png',
    './assets/img/wbb.png',
];

self.addEventListener('install', e => {
    e.waitUntil(
        caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', e => {
    const url = new URL(e.request.url);

    // Hanya handle same-origin GET
    if (e.request.method !== 'GET' || url.origin !== location.origin) return;

    // AJAX / API → network only, jangan cache
    const noCache = ['/notifikasi_api', 'export_laporan', 'backup_laporan',
                     'cetak_pdf', '.sql', '.bat'];
    if (noCache.some(p => url.pathname.includes(p))) return;

    // Network first → fallback cache (data selalu fresh)
    e.respondWith(
        fetch(e.request)
            .then(res => {
                // Cache response statis (CSS/JS/gambar/font dari CDN tidak di-cache di sini)
                if (res.ok && url.origin === location.origin) {
                    caches.open(CACHE).then(c => c.put(e.request, res.clone()));
                }
                return res;
            })
            .catch(() => caches.match(e.request).then(cached => {
                if (cached) return cached;
                // Fallback offline page untuk navigasi HTML
                if (e.request.headers.get('Accept')?.includes('text/html')) {
                    return new Response(
                        `<!DOCTYPE html><html lang="id"><head>
                        <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
                        <title>Offline – WBB</title>
                        <style>
                          body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;
                               min-height:100vh;margin:0;background:#f8fafc;text-align:center;}
                          .box{padding:32px;max-width:320px;}
                          img{width:72px;margin-bottom:16px;}
                          h2{color:#1b6b3d;margin:0 0 8px;}
                          p{color:#64748b;font-size:.9rem;}
                          button{margin-top:16px;padding:10px 24px;background:#1b6b3d;color:#fff;
                                 border:none;border-radius:8px;cursor:pointer;font-size:.95rem;}
                        </style></head><body>
                        <div class="box">
                          <img src="assets/img/wbb.png" alt="WBB">
                          <h2>Tidak Ada Koneksi</h2>
                          <p>Periksa koneksi internet Anda, lalu coba lagi.</p>
                          <button onclick="location.reload()">Coba Lagi</button>
                        </div></body></html>`,
                        { headers: { 'Content-Type': 'text/html' } }
                    );
                }
            }))
    );
});
