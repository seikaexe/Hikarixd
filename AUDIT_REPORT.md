# Audit report — masalah "Download file again?" di Vercel

## Gejala
Membuka website di Vercel membuat Chrome menampilkan "Download file again?" dan mengunduh file bernama `download`,
bukan menampilkan halaman.

## Temuan audit

| # | Temuan | Dampak | Status |
|---|---|---|---|
| 1 | `public/index.php` (front controller PHP) berada di folder `public/`. Vercel men-deploy isi `public/` sebagai aset statis CDN; file `.php` statis dikirim sebagai `application/octet-stream`. | **Penyebab paling mungkin** dari unduhan file bernama `download` | Diperbaiki: dipindah ke `server/index.php`, disalin ke `/app/public/index.php` hanya di dalam image |
| 2 | `vercel.json` hanya `{"framework": null}`: tidak ada output directory eksplisit, sehingga file di root repo (`index.php`, `api.php`, ...) bisa ikut dianggap aset statis. | Risiko file PHP tersaji sebagai unduhan | Diperbaiki: `outputDirectory: "public"` |
| 3 | `Dockerfile.vercel` menyalin `index.html` ke `public/index.html` (document root). | Halaman utama menjadi file statis di container; tidak sesuai permintaan "root index.html dilayani PHP" | Diperbaiki: `/app/index.html`, dikirim oleh PHP |
| 4 | `Caddyfile` memakai `encode zstd gzip` di origin. | Vercel sudah mengompresi di edge; kompresi ganda/zstd di origin menambah risiko respons rusak | Dihapus (tindakan pencegahan) |
| 5 | `Caddyfile` memakai `bind 0.0.0.0` (hanya IPv4). | Bisa gagal bila platform memeriksa lewat IPv6/localhost | Dihapus |
| 6 | Tidak ada header MIME eksplisit pada halaman HTML. | Browser bisa menebak tipe | Front controller kini mengirim `Content-Type: text/html; charset=utf-8`, `Content-Disposition: inline`, `X-Content-Type-Options: nosniff` |
| 7 | Route `/health` diarahkan ke `health.php` (JSON), padahal frontend punya halaman SPA `/health`. Refresh/buka langsung `/health` menampilkan JSON (bahkan 500 bila MongoDB mati). | Halaman Health tidak bisa dibuka langsung | Diperbaiki: `/health` = halaman SPA; JSON tetap di `/api/health` dan `/health.php` |
| 8 | Path aset yang tidak ada (mis. `/favicon.ico`) dibalas HTML 200. | Membingungkan browser/cache | Kini 404 teks biasa |
| 9 | `api.php` memanggil MongoDB sebelum routing sehingga `/api/health` ikut gagal (blank 500) saat MongoDB bermasalah; error tak tertangani menghasilkan halaman kosong. | Sulit mendiagnosis deployment | `/api/health` tidak lagi butuh MongoDB; ditambah exception handler JSON 503/500 dan redirect `?auth_error=failed` untuk `/auth/*` |
| 10 | `README.md` berisi penanda merge conflict (`<<<<<<< HEAD`, `=======`, `>>>>>>>`). | Dokumentasi rusak | Ditulis ulang |
| 11 | `.htaccess` tidak memblokir folder baru `server/` dan tidak memetakan `/styles.css` ke `public/` untuk Apache. | Hanya untuk hosting Apache | Diperbarui |
| 12 | Tidak ada `.dockerignore`; tidak ada guard yang mencegah `.php/.html` kembali masuk `public/`. | Regresi | Ditambah `.dockerignore` dan langkah guard di GitHub Actions |

Dependency: `composer.json` (php ^8.4, ext-mongodb ^2.1, mongodb/mongodb ^2.1) konsisten dengan image
`dunglas/frankenphp:1-php8.4-alpine` + `install-php-extensions mongodb`. Tidak ada `composer.lock`
(tidak dapat dibuat tanpa Composer + jaringan); versi di-resolve saat build.

## Yang benar-benar diuji (di sandbox tanpa PHP/Docker/jaringan)

- Simulasi isi image dari `COPY` di `Dockerfile.vercel`: semua sumber ada, document root hanya berisi `app.js`, `extra.css`,
  `styles.css`, `index.php`; semua `require` PHP dan semua URL aset di `index.html` terselesaikan.
- `public/` di repo tidak berisi `.php`/`.html`; `public/index.html` tidak ada; `index.html` root ada.
- `vercel.json` dan `composer.json` JSON valid; Caddyfile braces seimbang; workflow YAML valid.
- Semua file PHP: watermark ada, lexer Pygments tanpa error, tanda kurung seimbang (**ini bukan `php -l`**).
- Chromium (Playwright) terhadap server tiruan yang meniru aturan routing `server/index.php`: `/`, `/docs`, `/health`, `/settings`
  dirender (bukan diunduh), CSS/JS dimuat dengan MIME benar, fetch `/api/*` dijalankan oleh `app.js`, aset hilang → 404.

## Yang TIDAK diuji

- `php -l` / eksekusi PHP 8.4 sungguhan, FrankenPHP/Caddy, build Docker, dan deploy Vercel: tidak tersedia di lingkungan ini.
- Koneksi MongoDB Atlas, login/registrasi Google OAuth, dan endpoint downloader: tidak ada kredensial.
- Penyebab pasti di Vercel tidak bisa dikonfirmasi tanpa akses ke deployment; temuan #1–#2 adalah diagnosis paling konsisten
  dengan gejala. Setelah redeploy, jalankan perintah `curl -I` di README untuk memastikan `Content-Type: text/html`.
