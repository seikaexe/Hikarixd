# Hikarixd — PHP 8.4 + FrankenPHP + MongoDB Atlas

Backend tetap **PHP 8.4** (tanpa Node.js). Frontend HTML/CSS/JavaScript asli dipertahankan.
Data aplikasi (statistik dan konfigurasi) disimpan di MongoDB Atlas, sehingga aman untuk hosting stateless seperti Vercel. Tidak ada akun/login: API key server ditampilkan publik di menu **API Key**.

## Struktur proyek

```
index.html            Halaman utama (SPA). Dibaca & dikirim oleh PHP — bukan file statis Vercel.
server/index.php      Front controller PHP (routing /api dan halaman utama).
public/               HANYA aset statis: app.js, styles.css, extra.css, gambar. Jangan taruh .php/.html di sini.
api.php               Endpoint API.
health.php            Pemeriksaan kesehatan (JSON).
app/, config/         Logika aplikasi & konfigurasi (di luar document root).
Caddyfile             Konfigurasi FrankenPHP/Caddy (document root /app/public).
Dockerfile.vercel     Image untuk Vercel Container. Dockerfile = salinan untuk VPS/lokal.
vercel.json           framework: null, outputDirectory: public.
```

Di dalam image Docker, `server/index.php` disalin menjadi `/app/public/index.php` (document root FrankenPHP),
sedangkan `index.html` berada di `/app/index.html` dan dikirim oleh PHP. Folder `app/`, `config/`, `data/`,
`vendor/`, `scripts/` berada di luar document root sehingga tidak bisa diakses lewat HTTP.

### Mengapa `public/` tidak boleh berisi `.php` / `.html`

Di Vercel, isi `public/` dideploy sebagai aset statis oleh CDN. File `.php` yang disajikan sebagai file statis dikirim
dengan tipe `application/octet-stream`, sehingga Chrome menampilkan "Download file again?" dan mengunduh file bernama
`download`. Karena itu front controller PHP disimpan di `server/`, bukan di `public/`.

## Environment variables

Salin `.env.example` sebagai acuan. Jangan commit `.env` atau secret sungguhan.

| Variable | Wajib | Keterangan |
|---|---:|---|
| `MONGODB_URI` | Ya | Connection string MongoDB Atlas |
| `MONGODB_DATABASE` | Tidak | Default `hikarixd` |
| `API_KEY` | Tidak | API key global opsional; bila kosong dibuat dan disimpan di MongoDB |

Di MongoDB Atlas: buat database user, izinkan akses jaringan dari hosting (Vercel memakai IP dinamis, sehingga biasanya
perlu `0.0.0.0/0` dengan password kuat), dan URL-encode karakter khusus pada username/password.

## Jalankan lokal

```bash
composer install
php -S 127.0.0.1:8000 -t public router.php
```

Atur environment variables di shell sebelum menjalankan PHP. Buka `http://127.0.0.1:8000`.

Docker:

```bash
docker build -f Dockerfile.vercel -t hikarixd .
docker run --rm -p 8080:80 --env-file .env hikarixd
curl -sI http://localhost:8080/ | grep -i content-type     # harus text/html
```

## Deploy ke Vercel

1. Push project ke GitHub.
2. Import repository di Vercel. Pastikan project menggunakan **Container** runtime/preset. File `Dockerfile.vercel` dipakai untuk membangun aplikasi PHP + FrankenPHP.
3. `vercel.json` sengaja hanya menetapkan `framework: container`; jangan menambahkan `outputDirectory: public` karena itu mengarahkan deployment ke output statis dan bukan container.
4. Isi Environment Variables (tabel di atas), lalu **Redeploy**.
5. Verifikasi setelah deploy:

```bash
curl -sI https://DOMAIN-ANDA/            | grep -i -E 'HTTP|content-type|content-disposition'   # text/html, bukan octet-stream
curl -sI https://DOMAIN-ANDA/styles.css  | grep -i content-type                                  # text/css
curl -sI https://DOMAIN-ANDA/app.js      | grep -i content-type                                  # javascript
curl -s  https://DOMAIN-ANDA/api/health                                                         # {"status":true,...,"mongodb":true}
curl -s  https://DOMAIN-ANDA/api/endpoints | head -c 200
```

Container Vercel bersifat stateless; MongoDB menyimpan data lintas instance.
Dokumentasi: https://vercel.com/docs/functions/container-images

## Alternatif hosting

Jika container Vercel tidak cocok (mis. request downloader sangat lama), deploy image yang sama ke VPS/host Docker.
Backend tetap PHP 8.4 + FrankenPHP; MongoDB Atlas tetap menjadi database. Jangan gunakan hosting static-only.

## Migrasi JSON lama

1. Pastikan `data/users.json`, `data/stats.json`, `data/config.json` ada bila sebelumnya dipakai.
2. Atur `MONGODB_URI` dan `MONGODB_DATABASE`.
3. Simulasi: `php scripts/migrate-json-to-mongodb.php --dry-run`
4. Impor: `php scripts/migrate-json-to-mongodb.php`

File JSON tidak dihapus; simpan sebagai backup.

## Endpoint

- `GET /api/endpoints`, `/api/stats`, `/api/live`, `/api/key`, `/api/health`
- `GET /api/downloader/{platform}` (butuh API key)
- Halaman SPA: `/`, `/docs`, `/health`, `/apikey` (semuanya dilayani dari `index.html`)

Provider downloader pihak ketiga bergantung pada layanan eksternal dan dapat berubah/menolak request. Lihat `PROVIDER_SUPPORT.md`.

## Keamanan

- Jangan commit `.env`, MongoDB URI, API key, atau kredensial provider.
- `/api/key` bersifat publik (tanpa akun). Siapa pun yang membuka situs bisa memakai API key itu; ganti nilainya lewat env `API_KEY` bila perlu dirotasi.
- Gunakan HTTPS dan MongoDB Atlas dengan autentikasi.
