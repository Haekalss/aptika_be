# Raptika Backend API

API Backend untuk aplikasi **Raptika (Sistem Pendataan 6 Aplikasi di bawah Dinas Komunikasi dan Informatika Jawa Barat)**.

Project ini dibangun menggunakan **Laravel 13** dan **PHP 8.4** serta menyediakan API untuk mengelola berbagai data aplikasi, di antaranya:

- Data integrasi perangkat daerah (OPD)
- Replikasi aplikasi
- Performa mentoring
- Data interoperabilitas (Intop)
- Statistik dokumen Sidebar Jabar
- Kerentanan aplikasi (vulnerabilities)
- Manajemen pengguna dan autentikasi

Untuk production **Vercel → Wasmer → TiDB Cloud**, ikuti [panduan deployment](DEPLOYMENT.md) dan gunakan [.env.wasmer.example](.env.wasmer.example) sebagai referensi variabel Wasmer.

## Prasyarat

Sebelum menjalankan project, pastikan software berikut telah tersedia di laptop.

### Opsi 1 - Menggunakan Docker

- **Docker Desktop**
- **Git**

Pastikan Docker Desktop dalam kondisi **Running**.

### Opsi 2 - Tanpa Docker

- **Git**
- **PHP 8.4**
- **Composer**
- **MySQL / MariaDB**
- **Ekstensi PHP** yang dibutuhkan Laravel

## Clone Repository

Clone repository terlebih dahulu:

```bash
git clone https://github.com/fazhrydzalaryaa/be_aptika.git
cd be_aptika
```

Ganti `<URL_REPOSITORY>` dengan URL repository GitHub backend yang digunakan oleh tim.

## Konfigurasi Environment

Pastikan file `.env` tersedia di root project.

Jika file `.env` belum tersedia, salin dari `.env.example`.

### Windows PowerShell

```powershell
Copy-Item .env.example .env
```

### Linux / macOS

```bash
cp .env.example .env
```

Setelah itu, sesuaikan konfigurasi database pada file `.env`.

### Konfigurasi Database Tanpa Docker

Jika menggunakan MySQL atau MariaDB yang terpasang langsung pada laptop, gunakan konfigurasi berikut:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=railway
DB_USERNAME=root
DB_PASSWORD=
```

> Konfigurasi database tanpa Docker menggunakan port `3306`.

## Menjalankan dengan Docker

Docker direkomendasikan untuk memudahkan seluruh anggota tim menggunakan environment yang konsisten.

Docker Compose menyediakan tiga service utama:

- **backend** - Laravel dengan PHP-FPM
- **db** - MySQL 8.0
- **nginx** - Web server untuk meneruskan request ke PHP-FPM

### 1. Build dan Jalankan Container

Jalankan perintah berikut dari root project:

```bash
docker compose up -d --build
```

Untuk memastikan seluruh container berjalan:

```bash
docker compose ps
```

### 2. Generate Application Key

Jika `APP_KEY` belum tersedia pada `.env`, jalankan:

```bash
docker compose exec backend php artisan key:generate
```

Jika `APP_KEY` sudah tersedia dan aplikasi dapat berjalan normal, langkah ini tidak perlu dilakukan kembali.

### 3. Jalankan Migrasi Database

Untuk menjalankan migrasi:

```bash
docker compose exec backend php artisan migrate
```

Jika ingin membuat ulang database sekaligus menjalankan seeder:

```bash
docker compose exec backend php artisan migrate:fresh --seed
```

> Gunakan `migrate:fresh --seed` dengan hati-hati karena perintah tersebut akan menghapus tabel dan data yang sudah ada kemudian membuatnya kembali.

### 4. Akses Backend

Setelah seluruh container berhasil berjalan, backend dapat diakses melalui:

**Base URL API**

```text
http://localhost:8000/api
```

**Dokumentasi Swagger API**

```text
http://localhost:8000/api/documentation
```

## Menjalankan Tanpa Docker

Project juga dapat dijalankan secara langsung menggunakan PHP, Composer, dan MySQL/MariaDB yang terpasang di laptop.

### 1. Install Dependency

Dari root project:

```bash
composer install
```

### 2. Konfigurasi Environment

Jika file `.env` belum tersedia:

#### Windows PowerShell

```powershell
Copy-Item .env.example .env
```

#### Linux / macOS

```bash
cp .env.example .env
```

Kemudian pastikan konfigurasi database sesuai dengan MySQL/MariaDB lokal:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=railway
DB_USERNAME=root
DB_PASSWORD=
```

Pastikan database `railway` sudah tersedia pada MySQL/MariaDB lokal.

### 3. Generate Application Key

```bash
php artisan key:generate
```

### 4. Jalankan Migrasi

Untuk menjalankan migrasi:

```bash
php artisan migrate
```

Atau untuk membuat ulang database sekaligus menjalankan seeder:

```bash
php artisan migrate:fresh --seed
```

> Gunakan `migrate:fresh --seed` dengan hati-hati karena perintah tersebut akan menghapus tabel dan data yang sudah ada kemudian membuatnya kembali.

### 5. Jalankan Server Laravel

```bash
php artisan serve
```

Backend kemudian dapat diakses melalui:

```text
http://127.0.0.1:8000
```

**Base URL API**

```text
http://127.0.0.1:8000/api
```

**Dokumentasi Swagger**

```text
http://127.0.0.1:8000/api/documentation
```

## Konfigurasi Database MySQL

### Docker

Ketika menggunakan Docker, backend Laravel terhubung ke service MySQL menggunakan nama service `db`.

Konfigurasi database di dalam network Docker:

```env
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=railway
DB_USERNAME=root
DB_PASSWORD=
```

MySQL Docker menggunakan database:

```text
railway
```

Port MySQL di dalam container:

```text
3306
```

Port MySQL yang diekspos ke laptop:

```text
3307
```

Dengan demikian, jika ingin mengakses MySQL Docker dari aplikasi database pada laptop, gunakan:

| Konfigurasi | Nilai |
|---|---|
| Host | `127.0.0.1` |
| Port | `3307` |
| Username | `root` |
| Password | Kosong |
| Database | `railway` |

Aplikasi database yang dapat digunakan antara lain:

- DBeaver
- TablePlus
- HeidiSQL
- MySQL Workbench
- phpMyAdmin

### Mengakses MySQL Docker melalui Terminal

Untuk masuk ke MySQL yang berjalan di dalam container:

```bash
docker compose exec db mysql -u root railway
```

## Perintah Docker yang Sering Digunakan

### Menjalankan Container

```bash
docker compose up -d
```

### Build Ulang dan Menjalankan Container

```bash
docker compose up -d --build
```

### Melihat Status Container

```bash
docker compose ps
```

### Melihat Log Semua Service

```bash
docker compose logs -f
```

### Melihat Log Backend

```bash
docker compose logs -f backend
```

### Melihat Log Database

```bash
docker compose logs -f db
```

### Melihat Log Nginx

```bash
docker compose logs -f nginx
```

### Masuk ke Container Backend

```bash
docker compose exec backend bash
```

### Menghentikan Container

```bash
docker compose down
```

### Menghentikan Container dan Menghapus Volume

```bash
docker compose down -v
```

> Gunakan `down -v` dengan hati-hati karena volume database Docker juga akan dihapus.

## Perintah Laravel yang Sering Digunakan

Perintah Laravel dapat dijalankan langsung jika menggunakan metode tanpa Docker.

Jika menggunakan Docker, jalankan perintah melalui:

```bash
docker compose exec backend php artisan <perintah>
```

### Melihat Daftar Route

Tanpa Docker:

```bash
php artisan route:list
```

Dengan Docker:

```bash
docker compose exec backend php artisan route:list
```

### Membersihkan Cache

Tanpa Docker:

```bash
php artisan optimize:clear
```

Dengan Docker:

```bash
docker compose exec backend php artisan optimize:clear
```

### Menjalankan Migrasi

Tanpa Docker:

```bash
php artisan migrate
```

Dengan Docker:

```bash
docker compose exec backend php artisan migrate
```

### Menjalankan Seeder

Tanpa Docker:

```bash
php artisan db:seed
```

Dengan Docker:

```bash
docker compose exec backend php artisan db:seed
```

### Membuat Migrasi

```bash
php artisan make:migration nama_migrasi
```

### Membuat Controller

```bash
php artisan make:controller NamaController
```

### Membuat Model

```bash
php artisan make:model NamaModel
```

## Dokumentasi API dengan Swagger

Project menggunakan **L5-Swagger** untuk menghasilkan dokumentasi API.

### Generate Swagger Tanpa Docker

```bash
php artisan l5-swagger:generate
```

### Generate Swagger dengan Docker

```bash
docker compose exec backend php artisan l5-swagger:generate
```

Dokumentasi Swagger dapat dibuka melalui:

```text
http://localhost:8000/api/documentation
```

File dokumentasi API yang dihasilkan tersimpan pada:

```text
storage/api-docs/api-docs.json
```

> Direktori `storage/api-docs/` diabaikan oleh Git karena merupakan hasil generate dokumentasi.

## Pengujian API

API dapat diuji menggunakan beberapa tools, seperti:

- Swagger UI
- Postman
- Insomnia
- REST Client
- Frontend Raptika

Pastikan backend dan database sudah berjalan sebelum melakukan pengujian.

## Struktur Project

Struktur utama backend:

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
|-- storage/
|   `-- api-docs/
|-- tests/
|-- .env.example
|-- artisan
|-- composer.json
|-- docker-compose.yml
|-- Dockerfile
`-- README.md
```

## Catatan untuk Tim Pengembang

Project dapat dijalankan menggunakan **Docker maupun tanpa Docker**.

### Jika Menggunakan Docker

Pastikan:

1. Docker Desktop sudah terinstall.
2. Docker Desktop sedang Running.
3. Jalankan:

```bash
docker compose up -d --build
```

4. Pastikan container berjalan:

```bash
docker compose ps
```

5. Jalankan migrasi:

```bash
docker compose exec backend php artisan migrate
```

6. Akses API melalui:

```text
http://localhost:8000/api
```

7. Akses dokumentasi Swagger melalui:

```text
http://localhost:8000/api/documentation
```

### Jika Tidak Menggunakan Docker

Pastikan:

1. PHP 8.4 sudah terinstall.
2. Composer sudah terinstall.
3. MySQL/MariaDB sudah berjalan.
4. Database `railway` sudah tersedia.
5. Konfigurasi `.env` sudah sesuai.
6. Jalankan:

```bash
composer install
php artisan key:generate
php artisan migrate
php artisan serve
```

Kemudian akses API melalui:

```text
http://127.0.0.1:8000/api
```

## Troubleshooting Dasar

### Container Tidak Berjalan

Periksa status container:

```bash
docker compose ps
```

Kemudian periksa log:

```bash
docker compose logs -f
```

Jika hanya ingin melihat log backend:

```bash
docker compose logs -f backend
```

### Database Tidak Dapat Terhubung

#### Jika Menggunakan Docker

Pastikan konfigurasi koneksi database pada service backend menggunakan:

```env
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=railway
DB_USERNAME=root
DB_PASSWORD=
```

Pastikan service database berjalan:

```bash
docker compose ps
```

Kemudian bersihkan cache konfigurasi:

```bash
docker compose exec backend php artisan optimize:clear
```

#### Jika Tanpa Docker

Pastikan MySQL/MariaDB berjalan pada laptop dan konfigurasi `.env` menggunakan:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=railway
DB_USERNAME=root
DB_PASSWORD=
```

Kemudian bersihkan cache konfigurasi:

```bash
php artisan optimize:clear
```

### Tidak Dapat Mengakses Database Docker dari Laptop

Jika menggunakan aplikasi seperti DBeaver, TablePlus, HeidiSQL, MySQL Workbench, atau phpMyAdmin, gunakan:

| Konfigurasi | Nilai |
|---|---|
| Host | `127.0.0.1` |
| Port | `3307` |
| Username | `root` |
| Password | Kosong |
| Database | `railway` |

Jangan menggunakan port `3306` dari laptop untuk koneksi ke MySQL Docker karena port tersebut dipetakan ke `3307` pada konfigurasi Docker Compose.

### Swagger Tidak Ter-update

Generate kembali dokumentasi Swagger.

Tanpa Docker:

```bash
php artisan l5-swagger:generate
```

Dengan Docker:

```bash
docker compose exec backend php artisan l5-swagger:generate
```

Kemudian buka:

```text
http://localhost:8000/api/documentation
```

### Application Key Belum Tersedia

Tanpa Docker:

```bash
php artisan key:generate
```

Dengan Docker:

```bash
docker compose exec backend php artisan key:generate
```

## Status Project

Backend **Raptika / APTIKA TOOLS** telah dikonfigurasi untuk dapat dikembangkan dan dijalankan oleh tim menggunakan dua metode:

- **Docker**
- **Tanpa Docker**

Docker menggunakan:

- PHP 8.4 FPM
- MySQL 8.0
- Nginx
- Laravel 13

Konfigurasi database Docker:

```text
Database : railway
Host     : db
Port     : 3306
```

Akses MySQL Docker dari laptop:

```text
Host : 127.0.0.1
Port : 3307
```

Backend API melalui:

```text
http://localhost:8000/api
```

Dokumentasi Swagger melalui:

```text
http://localhost:8000/api/documentation
```

## Lisensi

Project ini merupakan project internal untuk kebutuhan **Dinas Komunikasi dan Informatika Provinsi Jawa Barat** dan pengembangan aplikasi Raptika.
