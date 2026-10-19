# Raptika JSON API Documentation

Dokumentasi ini menjelaskan seluruh endpoint yang tersedia pada aplikasi, cara autentikasi, dan struktur payload yang dibutuhkan.

---

## 1. Authentication (Sanctum)

Semua endpoint yang dilindungi (selain login dan register) membutuhkan Header HTTP berikut:
`Authorization: Bearer <access_token>`

### Register
Mendaftarkan pengguna baru dan menghasilkan access token.
- **Endpoint**: `POST /api/register`
- **Body (JSON)**:
  ```json
  {
      "name": "John Doe",
      "email": "john@example.com",
      "password": "password123",
      "password_confirmation": "password123"
  }
  ```
- **Response (201 Created)**: Mengembalikan object user dan `access_token`.

### Login
Autentikasi pengguna dan mendapatkan access token.
- **Endpoint**: `POST /api/login`
- **Body (JSON)**:
  ```json
  {
      "email": "john@example.com",
      "password": "password123"
  }
  ```
- **Response (200 OK)**: Mengembalikan object user dan `access_token`.

### Logout
Menghapus (revoke) token pengguna yang sedang aktif.
- **Endpoint**: `POST /api/logout`
- **Headers**: `Authorization: Bearer <token>`
- **Response (200 OK)**: Pesan berhasil logout.

---

## 2. User & Profile
**[Membutuhkan Bearer Token]**

### Get Current User
- **Endpoint**: `GET /api/user`
- **Response**: Menampilkan data user yang sedang login.

### Update Profile
- **Endpoint**: `PATCH /api/profile`
- **Body**: `name`, `email`

### Delete Profile
- **Endpoint**: `DELETE /api/profile`
- **Body**: 
  ```json
  { "password": "current_password" }
  ```

---

## 3. Smart Jabar
**[Membutuhkan Bearer Token]**

Grup endpoint untuk mengelola aplikasi Smart Jabar dan statistiknya. Prefix: `/api/smartjabar`

### Joined Apps (Aplikasi Tergabung)
Mengelola data aplikasi yang tergabung.
- `GET /api/smartjabar/joined-apps` : Mendapatkan list data.
- `POST /api/smartjabar/joined-apps` : Menambahkan data baru.
  - **Body**:
    ```json
    {
      "year": 2024,
      "month": 5,
      "total_apps": 10
    }
    ```
- `GET /api/smartjabar/joined-apps/{id}/edit` : Mengambil data tunggal untuk diedit.
- `PUT /api/smartjabar/joined-apps/{id}` : Memperbarui data. (Body sama dengan POST).
- `DELETE /api/smartjabar/joined-apps/{id}` : Menghapus data.

### Usage Stats (Statistik Penggunaan)
Mengelola statistik penggunaan OPD di Smart Jabar.
- `POST /api/smartjabar/stats` : Menambahkan statistik (Bulk input array OPD).
  - **Body**: Membutuhkan `month`, `year`, dan array `stats` yang berisi map OPD -> `total_asn`, `active_users`.
- `GET /api/smartjabar/stats/{id}/edit` : Data spesifik.
- `PUT /api/smartjabar/stats/{id}` : Memperbarui data spesifik.
  - **Body**: `opd_id`, `month`, `year`, `total_asn`, `active_users`.
- `DELETE /api/smartjabar/stats/{id}`

---

## 4. Sada Jabar
**[Membutuhkan Bearer Token]**

Prefix: `/api/sadajabar`

- `GET /api/sadajabar/` : Index utama Sada Jabar.

### App Integration (Integrasi Aplikasi)
- `POST /api/sadajabar/integrasi` : Membuat data integrasi.
- `PUT /api/sadajabar/integrasi/{id}` : Update data.
- `DELETE /api/sadajabar/integrasi/{id}` : Hapus data.

### Encryption Stats (Statistik Enkripsi)
- `POST /api/sadajabar/enkripsi`
- `PUT /api/sadajabar/enkripsi/{id}`
- `DELETE /api/sadajabar/enkripsi/{id}`

---

## 5. Rekayasa (Engineering)
**[Membutuhkan Bearer Token]**

Prefix: `/api/rekayasa`

### Application Replications (Replikasi Aplikasi)
- `GET /api/rekayasa/application-replications`
- `POST /api/rekayasa/application-replications`
- `PUT /api/rekayasa/application-replications/{id}`
- `DELETE /api/rekayasa/application-replications/{id}`

### Mentoring Performances (Performa Mentoring)
- `GET /api/rekayasa/mentoring-performances`
- `POST /api/rekayasa/mentoring-performances`
- `PUT /api/rekayasa/mentoring-performances/{id}`
- `DELETE /api/rekayasa/mentoring-performances/{id}`

---

## 6. Intop (Interoperability)
**[Membutuhkan Bearer Token]**

Prefix: `/api/intop`

### Integration Summaries
- `GET /api/intop/integration-summaries`
- `POST /api/intop/integration-summaries`
- `PUT /api/intop/integration-summaries/{id}`
- `DELETE /api/intop/integration-summaries/{id}`

### Service Catalogs
- `GET /api/intop/service-catalogs`
- `POST /api/intop/service-catalogs`
- `PUT /api/intop/service-catalogs/{id}`
- `DELETE /api/intop/service-catalogs/{id}`

---

## 7. Sidebar
**[Membutuhkan Bearer Token]**

Prefix: `/api/sidebar`

### Document Stats
- `GET /api/sidebar/document-stats`
- `POST /api/sidebar/document-stats`
- `PUT /api/sidebar/document-stats/{id}`
- `DELETE /api/sidebar/document-stats/{id}`

### Metrics
- `GET /api/sidebar/metrics`
- `POST /api/sidebar/metrics`
- `PUT /api/sidebar/metrics/{id}`
- `DELETE /api/sidebar/metrics/{id}`

### OPD Usages
- `GET /api/sidebar/opd-usages`
- `POST /api/sidebar/opd-usages`
- `PUT /api/sidebar/opd-usages/{id}`
- `DELETE /api/sidebar/opd-usages/{id}`

---

## 8. Task Management Notifications
**[Membutuhkan Bearer Token]**

Prefix: `/api/task-management/notifications`

- `GET /api/task-management/notifications` — ambil daftar notifikasi terbaru untuk user login.
- `GET /api/task-management/notifications/unread-count` — ambil jumlah notifikasi belum dibaca.
- `PATCH /api/task-management/notifications/{id}/read` — tandai satu notifikasi sebagai dibaca.
- `PATCH /api/task-management/notifications/read-all` — tandai semua notifikasi sebagai dibaca.
- `DELETE /api/task-management/notifications/{id}` — hapus notifikasi.

Contoh response:
```json
{
  "success": true,
  "data": [
    {
      "id": 10,
      "type": "TASK_ASSIGNED",
      "title": "Tugas baru ditugaskan",
      "message": "John menugaskan Anda untuk tugas API Integration.",
      "is_read": false,
      "created_at": "2026-07-14T00:00:00.000000Z",
      "board": { "id": 2, "name": "Board" },
      "task": { "id": 15, "title": "API Integration", "status": "todo" },
      "created_by": { "id": 4, "name": "John" }
    }
  ]
}
```

```

---

## 9. SMKI - Berita Acara Penghancuran Media (FR014-SMKI)
**[Membutuhkan Bearer Token]**

Prefix: `/api/smki/berita-acara`

Standar formulir FR014-SMKI untuk pencatatan resmi pemusnahan media penyimpanan, harddisk, flashdisk, server, dan aset perangkat keras lainnya.

### Endpoints
- `GET /api/smki/berita-acara` : Mendapatkan daftar berita acara dengan paginasi, live search, dan ringkasan KPI (Total Data, Data Hari Ini, Data Dihapus).
  - Query parameters:
    - `search`: string (cari nomor dokumen, pelaksana, pihak diketahui, alasan, atau perangkat media)
    - `date_from`: string (filter tanggal mulai `YYYY-MM-DD`)
    - `date_to`: string (filter tanggal selesai `YYYY-MM-DD`)
    - `page`: int (halaman saat ini)
    - `per_page`: int (jumlah per halaman, default: 10)
- `GET /api/smki/berita-acara/lookup` : Master data untuk dropdown pengguna/pegawai dan rekomendasi nomor dokumen otomatis (misal: `BA-001/SMKI/2026`).
- `POST /api/smki/berita-acara` : Menyimpan dokumen berita acara baru beserta array rincian media perangkat (`detail_media`).
  - **Body (JSON)**:
    ```json
    {
      "nomor_dokumen": "BA-001/SMKI/2025",
      "tanggal_pelaksanaan": "2025-09-12",
      "alasan_penghancuran": "Media/perangkat mengalami kerusakan dan data tidak diperlukan.",
      "id_pelaksana": 1,
      "id_diketahui": 2,
      "nama_pelaksana": "Ahmad Fauzi",
      "nama_diketahui": "Budi Santoso",
      "detail_media": [
        {
          "nama_perangkat": "Hard Disk Internal",
          "spesifikasi": "Seagate Barracuda 1 TB",
          "jenis_media": "Storage",
          "serial_number": "SG-2021-001",
          "jumlah": 2,
          "satuan": "Unit",
          "keterangan": "Rusak dan tidak dapat digunakan"
        }
      ]
    }
    ```
- `GET /api/smki/berita-acara/{id}` : Mengambil data rincian berita acara spesifik beserta list media.
- `PUT /api/smki/berita-acara/{id}` : Memperbarui data berita acara dan menyinkronkan array rincian media.
- `DELETE /api/smki/berita-acara/{id}` : Menghapus berita acara (soft-delete dengan cascading child media).
- `GET /api/smki/berita-acara/export-docx` atau `GET /api/smki/berita-acara/{id}/export-docx` : Menghasilkan file dokumen Word (.docx) resmi yang telah terformat sesuai template standar DISKOMINFO PROVINSI JAWA BARAT "FR014-SMKI".
  - Query parameters opsional:
    - `no_dokumen`: string
    - `no_revisi`: string (default: 1.0)
    - `tanggal_berlaku`: string

---

## 10. SMKI - Formulir Review Log (FR-040)
**[Membutuhkan Bearer Token]**

Prefix: `/api/smki/review-log` (middleware `service.enabled:SMKI`, data diisolasi per bidang)

Standar formulir FR-040/KOM.03.05/SANDIKAMI (Rev 1.0, berlaku 07-Jul-2022) untuk pencatatan hasil review log akses/aktivitas sistem: identitas review, tabel log (User, IP, Tanggal, Waktu, Durasi), tindak lanjut, dan blok tanda tangan (Dibuat Oleh / Mengetahui).

### Endpoints
- `GET /api/smki/review-log` : Daftar review log dengan paginasi + ringkasan KPI (`total_review`, `total_log`, `review_bulan_ini`, `ada_tindak_lanjut`).
  - Query parameters: `search` (obyek, pelaksana, tindak lanjut, user/IP pada log), `tanggal_dari`, `tanggal_sampai` (`YYYY-MM-DD`), `page`, `per_page` (default 10, `-1` = semua).
- `POST /api/smki/review-log` : Menyimpan formulir baru beserta array rincian log (`items`).
  - **Body (JSON)**:
    ```json
    {
      "tanggal_review": "2026-10-01",
      "periode_mulai": "2026-09-01",
      "periode_selesai": "2026-09-30",
      "obyek_review": "Server Aplikasi SIMPEG",
      "pelaksana_review": "Tim Keamanan Informasi",
      "tindak_lanjut": "Menonaktifkan akun yang tidak dikenal.",
      "dibuat_oleh_nama": "Budi Santoso",
      "dibuat_oleh_nip": "199001012015011001",
      "mengetahui_nama": "Kepala Bidang",
      "mengetahui_nip": "198001012010011001",
      "ditandatangani_di": "Bandung",
      "tanggal_ttd": "2026-10-02",
      "items": [
        { "user_log": "admin01", "ip_address": "10.10.1.5", "tanggal": "2026-09-10", "waktu": "08:15", "durasi": "45 menit" }
      ]
    }
    ```
  - Wajib: `tanggal_review`, `obyek_review`, `pelaksana_review`; tiap baris `items` wajib `user_log`, `ip_address`, `tanggal`, `waktu` (`HH:MM`), `durasi`.
- `GET /api/smki/review-log/{id}` : Detail formulir beserta `items`.
- `PUT /api/smki/review-log/{id}` : Memperbarui formulir dan menyinkronkan ulang `items`.
- `DELETE /api/smki/review-log/{id}` : Menghapus formulir (soft-delete, rincian log ikut terhapus).
- `GET /api/smki/review-log/{id}/export-docx` : Unduh formulir FR-040 (.docx) hasil pengisian template asli `resources/templates/FR-040 Formulir Review Log.docx`.

---

## 11. SMKI - Formulir BYOD / Penggunaan Perangkat Pribadi (FR-045)
**[Membutuhkan Bearer Token]**

Prefix: `/api/smki/perangkat-pribadi` (middleware `service.enabled:SMKI`, data diisolasi per bidang)

Standar formulir FR-045/KOM.03/05/SANDIKAMI (Rev 0.0, berlaku 13 Juli 2023) berupa surat pernyataan Kepala Unit Kerja mengenai penggunaan perangkat pribadi (Bring Your Own Device) di lingkungan unit kerja, lengkap dengan daftar perangkat.

### Endpoints
- `GET /api/smki/perangkat-pribadi` : Daftar formulir dengan paginasi + ringkasan KPI (`total_formulir`, `total_perangkat`, `laptop`, `pc`, `tab`).
  - Query parameters: `search` (nama, NIP, unit kerja, atau data perangkat), `jenis_perangkat` (`Laptop|PC|Tab`), `page`, `per_page` (default 10, `-1` = semua).
- `POST /api/smki/perangkat-pribadi` : Menyimpan formulir baru beserta array `perangkat`.
  - **Body (JSON)**:
    ```json
    {
      "nama": "Budi Santoso",
      "nip": "198001012010011001",
      "jabatan": "Kepala Bidang Aptika",
      "unit_kerja": "Bidang Aptika",
      "ditandatangani_di": "Bandung",
      "tanggal_ttd": "2026-10-17",
      "perangkat": [
        { "nama": "Dina Pratiwi", "nip": "199202022018021001", "jenis_perangkat": "Laptop", "merek_tipe": "Lenovo ThinkPad E14", "nomor_seri": "PF3ABC12" }
      ]
    }
    ```
  - Wajib: `nama`, `jabatan`, `unit_kerja`, minimal 1 `perangkat`; tiap perangkat wajib `nama`, `jenis_perangkat` (`Laptop`/`PC`/`Tab`), `merek_tipe`, `nomor_seri`.
- `GET /api/smki/perangkat-pribadi/{id}` : Detail formulir beserta `perangkat`.
- `PUT /api/smki/perangkat-pribadi/{id}` : Memperbarui formulir dan menyinkronkan ulang `perangkat`.
- `DELETE /api/smki/perangkat-pribadi/{id}` : Menghapus formulir (soft-delete, rincian perangkat ikut terhapus).
- `GET /api/smki/perangkat-pribadi/{id}/export-docx` : Unduh formulir FR-045 (.docx) hasil pengisian template asli `resources/templates/FR-045 Formulir BYOD.docx`.

---

## 12. SMKI - Formulir Reviu Monitoring dan Pemeliharaan CCTV (FR-038)
**[Membutuhkan Bearer Token]**

Prefix: `/api/smki/reviu-cctv` (middleware `service.enabled:SMKI`, data diisolasi per bidang)

Standar formulir FR-038/KOM.03.05/SANDIKAMI (Rev 0.0, berlaku 07 Juli 2022) untuk mencatat hasil reviu monitoring dan pemeliharaan CCTV: identitas reviu (tanggal, periode, obyek, pelaksana), tabel uraian reviu (Uraian Reviu, Tindak Lanjut, Target Selesai, Keterangan), dan tanda tangan Pengelola CCTV.

### Endpoints
- `GET /api/smki/reviu-cctv` : Daftar reviu dengan paginasi + ringkasan KPI (`total_reviu`, `total_uraian`, `reviu_bulan_ini`, `tindak_lanjut_aktif` = uraian dengan target selesai belum lewat).
  - Query parameters: `search` (obyek, pelaksana, pengelola, atau isi uraian/tindak lanjut/keterangan), `tanggal_dari`, `tanggal_sampai` (`YYYY-MM-DD`), `page`, `per_page` (default 10, `-1` = semua).
- `POST /api/smki/reviu-cctv` : Menyimpan formulir baru beserta array `items`.
  - **Body (JSON)**:
    ```json
    {
      "tanggal_reviu": "2026-10-17",
      "periode_mulai": "2026-09-01",
      "periode_selesai": "2026-09-30",
      "obyek_reviu": "CCTV Ruang Server Lantai 2",
      "pelaksana_reviu": "Tim Keamanan Fisik",
      "pengelola_nama": "Budi Santoso",
      "pengelola_nip": "198001012010011001",
      "items": [
        { "uraian_reviu": "Kamera 3 tidak merekam malam hari", "tindak_lanjut": "Ganti kamera", "target_selesai": "2026-11-15", "keterangan": "Prioritas" }
      ]
    }
    ```
  - Wajib: `tanggal_reviu`, `obyek_reviu`, `pelaksana_reviu`, minimal 1 baris `items` dengan `uraian_reviu`. Lainnya opsional.
- `GET /api/smki/reviu-cctv/{id}` : Detail formulir beserta `items`.
- `PUT /api/smki/reviu-cctv/{id}` : Memperbarui formulir dan menyinkronkan ulang `items`.
- `DELETE /api/smki/reviu-cctv/{id}` : Menghapus formulir (soft-delete, rincian ikut terhapus).
- `GET /api/smki/reviu-cctv/{id}/export-docx` : Unduh formulir FR-038 (.docx, landscape seperti aslinya) hasil pengisian template asli `resources/templates/FR-038 Formulir Reviu CCTV.docx`.

---

> [!NOTE]
> Semua route yang tidak menggunakan `GET` (seperti `POST`, `PUT`, `DELETE`) harus mengirimkan data dalam format `application/json` (Gunakan Header `Content-Type: application/json` dan `Accept: application/json`).

