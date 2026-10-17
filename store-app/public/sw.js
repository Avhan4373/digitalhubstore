// ===========================================================================
// Kill-switch service worker.
//
// Origin ini (store.avhanapp.my.id) sebelumnya dipakai aplikasi lain (Aero)
// yang memasang service worker di `/sw.js`. Service worker itu menyimpan app
// shell lama di CacheStorage dan menjalankan `location.replace("login.html")`
// (relatif), sehingga pengunjung yang pernah membuka origin ini saat masih
// menyajikan aplikasi lama terus diarahkan ke `https://<origin>/login.html`.
//
// Skrip ini menggantikan service worker lama: menghapus semua cache milik
// origin, melepas registrasinya sendiri, lalu memuat ulang tab yang terbuka.
// Browser memeriksa `/sw.js` pada setiap navigasi (bypass fetch handler lama),
// jadi pengunjung lama akan pulih otomatis tanpa perlu membersihkan data situs
// secara manual.
//
// File ini sengaja statis (bukan route Laravel) supaya tetap tersaji walau
// aplikasi sedang maintenance.
// ===========================================================================

self.addEventListener('install', () => {
    // Jangan tunggu tab lama ditutup — langsung aktifkan versi kill-switch.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            // 1. Buang seluruh cache origin (app shell Aero, aset, manifest).
            try {
                const keys = await caches.keys();
                await Promise.all(keys.map((key) => caches.delete(key)));
            } catch (e) {
                /* CacheStorage tidak tersedia — tidak masalah */
            }

            // 2. Ambil alih tab yang masih dikendalikan SW lama.
            try {
                await self.clients.claim();
            } catch (e) {
                /* abaikan */
            }

            // 3. Lepas registrasi SW ini sendiri; toko tidak memakai SW.
            try {
                await self.registration.unregister();
            } catch (e) {
                /* abaikan */
            }

            // 4. Muat ulang tab supaya langsung memakai HTML dari server.
            try {
                const clients = await self.clients.matchAll({ type: 'window' });
                clients.forEach((client) => {
                    try {
                        client.navigate(client.url);
                    } catch (e) {
                        /* abaikan */
                    }
                });
            } catch (e) {
                /* abaikan */
            }
        })(),
    );
});

// Tidak menangani fetch sama sekali: semua permintaan langsung ke jaringan.
