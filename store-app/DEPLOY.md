# Deploy — store.avhanapp.my.id

Panduan lengkap pemasangan di VPS Biznet (`103.127.97.249`, Ubuntu 22.04).
Semua langkah di bawah sudah dieksekusi; dokumen ini untuk reproduksi dan
perawatan.

---

## 1. Ringkasan server

| Komponen | Versi / lokasi |
|---|---|
| OS | Ubuntu 22.04.5 LTS |
| Web server | nginx 1.18 (`/etc/nginx/sites-available/store.avhanapp.my.id`) |
| PHP | 8.3.35 FPM (repo `packages.sury.org/php`, socket `/run/php/php8.3-fpm.sock`) |
| Database | MySQL 8.0.46, db `digitalhubstore`, user `digitalhub` |
| Composer | 2.10.3 (`/usr/local/bin/composer`) |
| Node | v22.23.3 (hanya untuk build aset, dijalankan di lokal) |
| Swap | 2 GB `/swapfile` (RAM VPS hanya 957 MB) |
| Kode | `/var/www/digitalhubstore` (clone dari GitHub, deploy key read-only) |
| Aplikasi | `/var/www/digitalhubstore/store-app` (docroot: `.../store-app/public`) |

> **Catatan PPA:** `add-apt-repository ppa:ondrej/php` gagal karena VPS tidak
> bisa menjangkau `launchpad.net`. Repo Sury dipasang manual:
>
> ```bash
> echo "deb https://packages.sury.org/php/ jammy main" | sudo tee /etc/apt/sources.list.d/sury-php.list
> curl -fsSL https://packages.sury.org/php/apt.gpg | sudo gpg --dearmor --yes -o /etc/apt/trusted.gpg.d/sury-php.gpg
> sudo apt-get update
> ```

---

## 2. Paket yang dipasang

```bash
sudo apt-get install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath \
  mysql-server unzip git
```

Batas upload dinaikkan di `/etc/php/8.3/fpm/php.ini`:

```ini
upload_max_filesize = 8M
post_max_size = 12M
memory_limit = 256M
max_execution_time = 60
```

---

## 3. Database

```bash
sudo mysql -e "CREATE DATABASE digitalhubstore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'digitalhub'@'127.0.0.1' IDENTIFIED WITH mysql_native_password BY '<password>';"
sudo mysql -e "GRANT ALL PRIVILEGES ON digitalhubstore.* TO 'digitalhub'@'127.0.0.1';"
```

Password disimpan hanya di `store-app/.env` (mode `640`, owner
`avhanvps:www-data`). **Jangan commit `.env`.**

---

## 4. Deploy key GitHub

Kunci khusus server (read-only), dipasang sebagai *deploy key* di repo:

```bash
ssh-keygen -t ed25519 -N "" -C "deploy@vps-digitalhubstore" -f ~/.ssh/deploy_digitalhubstore
gh repo deploy-key add ~/.ssh/deploy_digitalhubstore.pub --repo Avhan4373/digitalhubstore --title "VPS Biznet"
```

`deploy.sh` memakai `GIT_SSH_COMMAND` dengan kunci ini.

---

## 5. `.env` produksi (poin penting)

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://store.avhanapp.my.id
LOG_STACK=daily
LOG_LEVEL=warning
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database
CATALOG_SEED_DIR=../data/seed
```

`APP_KEY` dibuat dengan `php artisan key:generate --force`. **Kunci ini
mengenkripsi isi pesanan (akun) di `order_deliveries` dan `stock_items`** —
kalau hilang, isi lama tidak bisa dibuka. Backup `.env` bersama database.

---

## 6. Nginx

```nginx
server {
    listen 80;
    server_name store.avhanapp.my.id;
    root /var/www/digitalhubstore/store-app/public;
    index index.php;
    client_max_body_size 12m;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_hide_header X-Powered-By;
    }

    location ~* ^/build/   { expires 1y; add_header Cache-Control "public, immutable"; access_log off; }
    location ~* ^/storage/ { expires 30d; add_header Cache-Control "public"; try_files $uri =404; }
    location ~ /\.(?!well-known).* { deny all; }
}
```

Header keamanan (CSP, `X-Frame-Options`, HSTS) **tidak** dipasang di nginx —
sudah ditangani `App\Http\Middleware\SecurityHeaders`. Memasangnya di dua
tempat menghasilkan dua nilai berbeda untuk header yang sama.

---

## 7. Build aset

VPS hanya punya 1 GB RAM, jadi aset dibangun di **lokal** lalu dikirim:

```bash
# di mesin lokal
cd store-app && npm run build
tar czf /tmp/build.tgz -C public build
scp /tmp/build.tgz avhanvps@103.127.97.249:/tmp/
ssh avhanvps@103.127.97.249 'cd /var/www/digitalhubstore/store-app && rm -rf public/build && tar xzf /tmp/build.tgz -C public'
```

`public/build` sengaja masuk `.gitignore` (hasil build, bukan sumber).

---

## 8. Deploy / update

```bash
ssh -i ~/.ssh/biznetgio avhanvps@103.127.97.249 'bash /var/www/digitalhubstore/deploy.sh'
```

Isi `deploy.sh`: `git reset --hard origin/main` → `composer install --no-dev`
→ `php artisan migrate --force` → rebuild cache (`config`, `route`, `view`) →
perbaiki izin → reload `php8.3-fpm` + `nginx`.

**Setelah pull yang mengubah view/CSS/JS, kirim ulang `public/build` dari
lokal** (langkah 7), karena VPS tidak menjalankan `npm run build`.

---

## 9. Scheduler

```cron
* * * * * cd /var/www/digitalhubstore/store-app && php artisan schedule:run >> /dev/null 2>&1
```

Menjalankan `expireStaleOrders()` tiap 10 menit: pesanan yang lewat batas bayar
ditandai `expired` dan stok reservasinya dikembalikan.

---

## 10. Mengosongkan data sebelum go-live

```bash
cd /var/www/digitalhubstore/store-app
php artisan pesanan:bersihkan --force
```

Menghapus pesanan (termasuk yang soft-deleted), item, isi pengiriman, log
aktivitas, ulasan yang menempel ke pesanan, dan stok. **Katalog, ulasan hasil
scrape, pengaturan, dan akun admin tetap utuh.**

---

## 11. Akun admin

| | |
|---|---|
| URL | `https://store.avhanapp.my.id/login` |
| Email | `admin@digitalhubstore.test` |
| Password | lihat `ADMIN_SEED_PASSWORD` di `.env` server |

Ganti email/password lewat panel admin setelah login pertama. Password seed
dibuat acak saat instalasi dan **tidak** ditaruh di repo.

---

## 12. HTTPS

```bash
sudo certbot --nginx -d store.avhanapp.my.id
```

Prasyarat: A record `store` → `103.127.97.249` sudah aktif di Cloudflare.
Kalau proxy Cloudflare aktif (awan oranye), certbot harus memakai
`--webroot`/`--nginx` dengan mode **Full (strict)** di Cloudflare, atau
matikan proxy sementara saat penerbitan sertifikat.

---

## 13. Backup

Yang wajib di-backup:

1. **Database** — `mysqldump digitalhubstore` (berisi pesanan + isi akun terenkripsi).
2. **`.env`** — berisi `APP_KEY`; tanpa ini isi pesanan terenkripsi tidak terbaca.
3. `storage/app/public` — logo & gambar produk yang diunggah admin.

Contoh:

```bash
mysqldump -u digitalhub -p digitalhubstore | gzip > ~/backup/db-$(date +%F).sql.gz
tar czf ~/backup/env-$(date +%F).tgz /var/www/digitalhubstore/store-app/.env
```
