# Aptika Backend API

API Backend untuk aplikasi **A****ptika (Sistem Pendataan 6 Aplikasi di bawah Dinas Komunikasi dan Informatika Jawa Barat)**.

Project ini dibangun menggunakan **Laravel 11** dan **PHP 8.5** serta menyediakan API untuk mengelola berbagai data aplikasi, di antaranya:

- Data integrasi perangkat daerah (OPD)
- Replikasi aplikasi
- Performa mentoring
- Data interoperabilitas (Intop)
- Statistik dokumen Sidebar Jabar
- Kerentanan aplikasi (vulnerabilities)
- Manajemen pengguna dan autentikasi

Untuk production **Vercel → Wasmer → TiDB Cloud**, ikuti [panduan deployment](DEPLOYMENT.md) dan gunakan [.env.wasmer.example](.env.wasmer.example) sebagai referensi variabel Wasmer.

---

## Prasyarat Sistem

Sebelum menjalankan project di laptop/komputer lokal, pastikan software berikut telah terpasang:

1. **Git** (versi terbaru)
2. **XAMPP v8.5** (sudah mencakup **PHP 8.5** dan **MySQL/MariaDB**)
3. **Composer v2.10**
4. **Ekstensi PHP** (pastikan ekstensi berikut diaktifkan pada file `php.ini` di XAMPP):
   - `pdo_mysql`
   - `mbstring`
   - `openssl`
   - `curl`
   - `fileinfo`
   - `gd`
   - `zip`

> **Catatan Keamanan:** Penggunaan PHP 8.5 dan XAMPP versi terbaru sangat disarankan untuk memastikan sistem terhindar dari kerentanan keamanan CGI argument injection pada lingkungan Windows.

---

## Langkah Instalasi & Menjalankan Project

### 1. Clone Repository

Buka terminal (Git Bash / PowerShell / Command Prompt) dan clone repository:

```bash
git clone https://github.com/fazhrydzalaryaa/be_aptika.git
cd be_aptika
```

### 2. Konfigurasi Environment (`.env`)

Salin file `.env.example` menjadi `.env`:

**Windows PowerShell:**

```powershell
Copy-Item .env.example .env
```

**Linux / macOS:**

```bash
cp .env.example .env
```

### 3. Persiapan Database MySQL (XAMPP)

1. Buka **XAMPP Control Panel**.
2. Klik **Start** pada modul **Apache** dan **MySQL**.
3. Buka browser dan akses phpMyAdmin di `http://localhost/phpmyadmin` (atau gunakan database client seperti DBeaver/TablePlus).
4. Buat database baru bernama `railway` (atau sesuaikan dengan kebutuhan).
5. Sesuaikan konfigurasi database pada file `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=railway
DB_USERNAME=root
DB_PASSWORD=
```

### 4. Install Dependensi Project

Jalankan Composer v2.10 untuk mengunduh package PHP yang dibutuhkan:

```bash
composer install
```

### 5. Generate Application Key

Buat application key Laravel:

```bash
php artisan key:generate
```

### 6. Jalankan Migrasi Database & Seeder

Jalankan migrasi tabel database:

```bash
php artisan migrate
```

Jika ingin membuat ulang tabel sekaligus mengisi data awal (seeder):

```bash
php artisan migrate:fresh --seed
```

> **Peringatan:** Perintah `migrate:fresh --seed` akan menghapus seluruh data yang ada di database dan membuatnya kembali dari awal.

### 7. Menjalankan Server Laravel

Jalankan web server bawaan Laravel:

```bash
php artisan serve
```

Aplikasi backend akan berjalan dan dapat diakses melalui:

- **Base URL:** `http://127.0.0.1:8000`
- **Base URL API:** `http://127.0.0.1:8000/api`
- **Dokumentasi Swagger API:** `http://127.0.0.1:8000/api/documentation`

---

## Dokumentasi API (Swagger / OpenAPI)

Project menggunakan **L5-Swagger** untuk mengelola dokumentasi API.

### Memperbarui / Generate Swagger

Jika terdapat penambahan atau perubahan anotasi endpoint Swagger di controller:

```bash
php artisan l5-swagger:generate
```

Dokumentasi dapat diakses secara interaktif di browser:

```text
http://127.0.0.1:8000/api/documentation
```

File OpenAPI schema yang dihasilkan otomatis disimpan pada `storage/api-docs/api-docs.json`.

---

## Perintah Artisan yang Sering Digunakan

Berikut beberapa perintah Laravel Artisan yang sering digunakan selama masa pengembangan:

### Melihat Daftar Endpoint Route

```bash
php artisan route:list
```

### Membersihkan Cache Aplikasi dan Konfigurasi

```bash
php artisan optimize:clear
```

### Menjalankan Seeder Saja

```bash
php artisan db:seed
```

### Membuat File Baru

- **Migration:**
  ```bash
  php artisan make:migration create_nama_tabel_table
  ```
- **Controller:**
  ```bash
  php artisan make:controller NamaController
  ```
- **Model:**
  ```bash
  php artisan make:model NamaModel
  ```

---

## Pengujian API

Endpoint backend API dapat diuji menggunakan:

- **Swagger UI** (`http://127.0.0.1:8000/api/documentation`)
- **Postman / Insomnia**
- **Frontend Raptika** (`aptika_fe`)

---

## Struktur Direktori Utama

```text
be_aptika/
|-- app/
|   |-- Http/
|   |   |-- Controllers/
|   |   `-- Middleware/
|   |-- Models/
|   `-- OpenApi/
|-- bootstrap/
|-- config/
|-- database/
|   |-- migrations/
|   `-- seeders/
|-- public/
|-- resources/
|-- routes/
|   |-- api.php
|   `-- web.php
|-- storage/
|-- tests/
|-- .env.example
|-- artisan
|-- composer.json
`-- README.md
```

---

## Troubleshooting

### 1. Database Connection Refused / Error

- Pastikan modul **MySQL** pada XAMPP Control Panel berstatus **Running**.
- Pastikan port default `3306` tidak bertabrakan dengan instance MySQL lainnya.
- Periksa kembali variabel `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, dan `DB_PASSWORD` di file `.env`.
- Jalankan `php artisan optimize:clear` untuk membersihkan konfigurasi cache.

### 2. Ekstensi PHP Belum Aktif

- Jika muncul error seperti `Call to undefined function mb_detect_encoding()` atau driver database PDO tidak ditemukan, buka file `php.ini` di folder XAMPP (biasanya `C:\xampp\php\php.ini`).
- Hapus tanda titik koma (`;`) di depan baris ekstensi yang dibutuhkan:
  ```ini
  extension=pdo_mysql
  extension=mbstring
  extension=openssl
  extension=fileinfo
  extension=gd
  extension=zip
  ```
- Simpan file `php.ini` lalu restart modul Apache di XAMPP Control Panel.

### 3. Application Key Belum Tersedia (No application encryption key has been specified)

Jalankan perintah generate key:

```bash
php artisan key:generate
```

### 4. Swagger UI Tidak Berubah Setelah Edit Controller

Jalankan perintah generate ulang Swagger:

```bash
php artisan l5-swagger:generate
```

Lalu refresh browser (Ctrl + F5).

---

## Lisensi

Project ini merupakan project internal untuk kebutuhan **Dinas Komunikasi dan Informatika Provinsi Jawa Barat** dalam pengembangan sistem Raptika.
