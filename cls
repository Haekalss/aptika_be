[33m954fa6e[m[33m ([m[1;36mHEAD[m[33m -> [m[1;32mSMKI/screening[m[33m, [m[1;31morigin/main[m[33m, [m[1;31morigin/HEAD[m[33m, [m[1;32mmain[m[33m)[m Fix clone command in README
[33mec1742f[m[33m ([m[1;31morigin/feat/daftar-penyedia-backend[m[33m)[m git merge origin/mainMerge remote-tracking branch 'origin/main' into feat/daftar-penyedia-backend
[33mdc8f964[m feat: tambah daftar penyedia barang jasa
[33m9c4d7fd[m perbaikan: tambah fallback bidang pada unit kerja lookup laporan audit
[33m715e09a[m penambahan seeder untuk database seeder
[33ma137405[m Menambah fitur formulir rencana audit di smki
[33m1c8822e[m gabung: merge branch feature/evaluasi-smki ke main
[33mcd92c8b[m Merge remote-tracking branch 'origin/fitur-backend-spd'
[33m02a581b[m gabung: merge branch feature/hak-akses-ti-fr018 ke main
[33m0e9f531[m gabung: merge branch feat/smki-FRA ke main
[33m4f8f3f5[m fix: add route names prefix for master and spd groups to resolve route cache collision
[33mddbf985[m[33m ([m[1;31morigin/feat/data-master[m[33m)[m penambahan fitur data master
[33mb83d8a2[m[33m ([m[1;31morigin/feature/hak-akses-ti-fr018[m[33m)[m feat(smki): modul Hak Akses TI FR-018 (backend)
[33m7f39b3d[m[33m ([m[1;31morigin/feature/evaluasi-smki[m[33m)[m feat(smki): implementasi backend modul Hak Akses TI FR-018
[33m736c0c5[m[33m ([m[1;31morigin/fitur-backend-spd[m[33m)[m feat: tambah kolom nomor_sp, nomor_visum, dan ppk_id pada detail_perjalanan
[33m4b1c0a7[m feat: penyesuaian SPD hapus uang harian dan tambah relasi bidang
[33m880b54f[m feat(smki): implementasi modul laporan audit internal FR-006
[33m2abd96a[m Penambahan fitur data master
[33mc417ba5[m[33m ([m[1;31morigin/feat/smki-FRA[m[33m)[m penambahan fitur fformulir rencana audit di SMKI
[33m3f291b9[m[33m ([m[1;31morigin/feat/spd-update[m[33m)[m fix(spd): perbaiki urutan nomor peserta SPD agar selalu sequential
[33mcbc2831[m feat(spd): tambah API alat angkutan (tabel, model, controller, route) untuk CRUD alat angkutan
[33mdc1abe7[m fix(spd): sync peserta pada store spd-peserta untuk cegah duplicate entry 500 saat edit
[33m9f7ae68[m feat(smki): merge Formulir Daftar Rekaman FR-003 ke main - CRUD rekaman, export DOCX, master lookup, seeder produksi, sanity check
[33m2ce4fbb[m[33m ([m[1;31morigin/feat/smki-formulir-daftar-rekaman[m[33m)[m chore(smki): tambah ProductionDaftarRekamanSeeder dan sanity_check untuk deployment Railway
[33mfdaa327[m merge: integrasikan perubahan remote ke branch lokal
[33me47e0e3[m feat(smki): izinkan semua user authenticated menambahkan rekaman, edit/delete tetap admin-only
[33m980364d[m merge: sinkronisasi main (termasuk BCAPM) ke feat/smki-formulir-daftar-rekaman
[33meb790a1[m feat(smki): implementasi fitur formulir daftar rekaman
[33ma6a1dd1[m chore: siapkan ProductionBcapmSeeder, sanity_check_bcapm, dan update Dockerfile untuk deployment Railway
[33m45f0bf1[m merge: sinkronisasi branch main ke feat/smki-BCAPM
[33m4581cc2[m feat(smki): sinkronisasi backend formulir hardening dari main ke branch formulir daftar rekaman
[33md477b69[m feat(smki): implementasi fitur formulir daftar rekaman
[33m3090df1[m[33m ([m[1;31morigin/feat/smki-BCAPM[m[33m)[m feat(smki): implementasi berita acara dan media pemusnahan (BCAPM) serta export docx
[33m856fb1a[m feat: Merge formulir hardening FR-047 ke main - UI emerald, CRUD, generate DOCX/PDF, migrasi tabel, dan deployment assets
[33m4ffeb86[m[33m ([m[1;31morigin/feat/smki-hardening-fr047[m[33m)[m chore: Tambah ProductionHardeningSeeder, skrip sanity check migrasi, dan update Dockerfile CMD untuk deployment Railway
[33m0586225[m feat: Penambahan fitur Formulir Hardening FR-047 - controller, model, migration, seeder, service generate docx, dan penyesuaian routes API
[33me28ceb2[m perbaikan: inisialisasi master data lookup dropdown dan 3 aset resmi produksi
[33m2e004ae[m merge(be): gabungkan fitur daftar aset TI ke main dan perbaiki konflik nama route
[33m2bd2084[m[33m ([m[1;31morigin/feature/daftar-aset-ti[m[33m)[m perbaikan(be): hapus konflik nama route daftar-aset-ti yang menyebabkan crash di Railway saat route:cache
[33m4320f3a[m feat: tambahkan ProductionDaftarAsetTiSeeder untuk inisialisasi 3 data awal resmi di produksi
[33m3842740[m feat: implementasi fitur daftar aset TI dan ekspor excel template resmi
[33mdbc9994[m perbaikan(smki): perbaiki error unterminated entity reference saat ekspor docx dengan karakter & pada nama software/tipe
[33mc194ad2[m perbaikan(smki): hapus freeware dari kategori, ubah validasi kategori_id wajib diisi, tambah migrasi hapus freeware
[33mc36db71[m[33m ([m[1;31morigin/feat/dashboard-smki-card[m[33m)[m fix(smki): sertakan file template FR-017 ke repo backend dan tangani ekspor dokumen kosong
[33mae5babb[m feat(smki): sediakan master lookup kategori dan tipe software serta dukung input kustom baru
[33m788b070[m merge: gabungkan fitur smki (sub-layanan dan inventaris software standar) ke branch main
[33me6ad784[m refactor(database): hapus migrasi seeder user dummy untuk keamanan database produksi
[33m8adf829[m feat(smki): add software standar inventory module with FR-017 docx export
[33m09d97cd[m Merge branch 'main' into feat/dashboard-smki-card
[33mfc3e82f[m feat(task-management): tambah endpoint attachment dan izin edit/hapus tugas bagi user aktif
[33mac86236[m feat(auth): tambah seeder akun 1 admin dan 2 user untuk seluruh bidang
[33md5b50d1[m feat(service): daftarkan master layanan smki untuk semua bidang
[33m42775ca[m feat(system): tambahkan sinkronisasi otomatis tagging seluruh data eksisting ke Bidang APTIKA (id: 3)
[33mcf14998[m fix(migration): populate 7 bidangs sebelum insert bidang_services di migration 000006
[33mf6902c3[m fix(migration): perbaiki zero-date MySQL 9 strict mode dan auto-populate master bidang & service di production
[33m064bde4[m merge: feat/multi-bidang ke main — implementasi arsitektur multi-bidang
[33ma1bed82[m[33m ([m[1;31morigin/feat/multi-bidang[m[33m)[m fix(production): siapkan skema data untuk deployment Railway Production
[33md0271db[m feat(task-management): tambah kontrol hak pembuatan tugas per anggota dan endpoint update permission
[33m612b1d6[m perbaiki: hapus duplikasi blok kode di BoardController destroy() yang menyebabkan error 404 pada penghapusan proyek
[33m97c224c[m feat(tugas): implementasi relasi bidang dan otorisasi crud proyek
[33m670cb12[m feat(admin): implement super admin controllers, audit logging, and active sessions
[33md558d92[m fix(sinkronisasi): perbarui endpoint /api/me untuk evaluasi status hirarki service dan parent permission
[33mf0b104b[m feat(layanan): tambah sub-layanan jenis surat, data dummy 7 bidang, dan endpoint publik bidang
[33m8405db5[m fix(admin): perbarui UserController dan BidangSeeder untuk auto assign bidang_id ke user eksisting
[33mc76a566[m feat(multi-bidang): implementasi arsitektur multi-bidang pada backend
[33madfc50d[m fix: jalankan laravel server untuk railway
[33m1513974[m build: dukung setup backend dengan dan tanpa docker
[33maaebf99[m fix(docker): otomatis buat symbolic link storage
[33m7f38f46[m fix(task-management): perbaiki relasi notifikasi
[33m07119d1[m feat(seeder): tambahkan data project untuk testing
[33m38139d3[m fix(database): cegah duplikasi kolom avatar
[33mceea502[m fix(database): cegah duplikasi kolom role dan is_active
[33m4e5c578[m docs: perbarui dokumentasi API Swagger
[33mde1b974[m chore(api): tambahkan konfigurasi dokumentasi Swagger
[33m257aae4[m feat: initialize API routes with modular controller groupings for application modules
[33me400c71[m feat: restrict nda_file validation to PDF format only
[33m1b79c2f[m Merge branch 'main' of https://github.com/vanesarz/be_aptika
[33me542286[m feat: add nda_file field to magangs table and implement file management in controller
[33md6401cb[m feat: add password update feature and fix user creation migration issue
[33m114de03[m fix: route file serving through /api/storage endpoint to bypass webserver static file 404
[33md1ba58c