# ifs24049 - PABWE 2026 Studi Kasus P7: Aplikasi Stock Barang (Laravel 13 + Livewire)

Aplikasi inventaris stok barang dengan arsitektur **Feature-First**: Laravel 13, Livewire, MySQL, PhpSpreadsheet, integrasi OpenAI, rate limit 1000/detik, FORCE_HTTPS, dan coverage `app/` 100%.

## Fitur
- **Guest**: login (username/email) & register; `/` mengarah ke login.
- **Member**: CRUD stok milik sendiri (nama, SKU, deskripsi Markdown, quantity, unit, price, min_stock, status), adjust quantity (+/−) di list dan detail dengan status otomatis (available / low_stock / out_of_stock), cover image, import/export Excel, generate item stok dengan AI (preview → pilih → simpan), profil.
- **Admin**: `/admin/users` (daftar user + jumlah stok) dan `/admin/users/{user}/stocks` (read-only).
- **Keamanan**: middleware `role` + `owns.stock`, `StockPolicy`, `throttle:1000-per-second`, `FORCE_HTTPS`.

## Akun seed
`admin / admin` dan `member / member` (+ 4 contoh stok untuk member).

## Menjalankan
```bash
composer install
cp .env.example .env && php artisan key:generate
# buat DB: CREATE DATABASE pabwe_p7_stock CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate:fresh --seed
php artisan storage:link
npm install --ignore-scripts && npm run build
php artisan serve
```

## Pengujian
```bash
composer test      # PHPUnit + coverage-text
composer audit
```
