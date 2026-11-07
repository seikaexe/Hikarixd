# Perbaikan deployment Vercel Hikarixd

## Perubahan

- `vercel.json` sekarang memilih `framework: container` secara eksplisit.
- Menghapus `outputDirectory: public`. Aplikasi ini bukan static-site deployment: request harus masuk ke FrankenPHP melalui `Dockerfile.vercel`.
- Struktur aplikasi, `index.html`, CSS, JavaScript, API PHP, konfigurasi MongoDB, dan tampilan tidak dirombak.
- Arsip tidak menyertakan `.git`, `.vercel`, `vendor`, `node_modules`, atau file `.env`/secret. `.env.example` tetap disertakan.

## Langkah deploy

1. Upload isi ZIP ke repository GitHub project Hikarixd (jangan upload folder `.git` lama).
2. Di Vercel, pastikan project memakai Container runtime.
3. Isi Environment Variables yang diperlukan, terutama `MONGODB_URI`; jangan masukkan secret ke source code.
4. Jalankan redeploy dari commit terbaru.
5. Setelah deployment selesai, buka URL production dan periksa `/`, `/api/health`, `/styles.css`, serta `/app.js`.

## Catatan verifikasi

Konfigurasi dan struktur ZIP sudah diperiksa. Build Docker/FrankenPHP dan deployment langsung ke akun Vercel tidak dapat dijalankan dari lingkungan ini, jadi ZIP ini tidak diklaim sudah live atau bebas seluruh error runtime.
