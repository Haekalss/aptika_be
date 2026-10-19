<?php

/**
 * Pengaturan Perubahan TI (FR-015).
 *
 * HAK AKSES per aktor (Koordinator Agen, Penanggung Jawab Perangkat, Agen, pemilik data)
 * SEMENTARA DIMATIKAN: selama 'terapkan_hak_akses' = false, semua pengguna SMKI dapat
 * menjalankan setiap tahap alur selama status formulirnya sesuai. Urutan status tetap
 * dijaga (mis. "Setujui" hanya bisa pada status Diterima).
 *
 * Bila aturan peran sudah diputuskan, aktifkan lewat .env:
 *   SMKI_PERUBAHAN_IT_TERAPKAN_HAK_AKSES=true
 *   SMKI_PERUBAHAN_IT_KOORDINATOR_AGEN="koordinator@contoh.go.id"
 *   SMKI_PERUBAHAN_IT_PENANGGUNG_JAWAB="pj1@contoh.go.id,pj2@contoh.go.id"
 *   SMKI_PERUBAHAN_IT_AGEN="agen1@contoh.go.id,agen2@contoh.go.id"
 * lalu: php artisan config:clear
 */

$daftar = static fn (string $key, string $sep = ','): array => array_values(array_filter(array_map(
    static fn ($v) => trim($v),
    explode($sep, (string) env($key, ''))
)));

$email = static fn (string $key): array => array_map('strtolower', $daftar($key));

return [
    'terapkan_hak_akses' => (bool) env('SMKI_PERUBAHAN_IT_TERAPKAN_HAK_AKSES', false),

    // Hanya berlaku bila terapkan_hak_akses = true
    'admin_semua_peran' => (bool) env('SMKI_PERUBAHAN_IT_ADMIN_SEMUA_PERAN', true),

    'koordinator_agen'           => $email('SMKI_PERUBAHAN_IT_KOORDINATOR_AGEN'),
    'penanggung_jawab_perangkat' => $email('SMKI_PERUBAHAN_IT_PENANGGUNG_JAWAB'),
    'agen'                       => $email('SMKI_PERUBAHAN_IT_AGEN'),

    // Pilihan dropdown "Perangkat Daerah" (pisahkan dengan titik koma di .env bila lebih dari satu)
    'perangkat_daerah' => $daftar('SMKI_PERUBAHAN_IT_PERANGKAT_DAERAH', ';') ?: [
        'Dinas Komunikasi dan Informatika Provinsi Jawa Barat',
    ],

    // Lampiran
    'lampiran_maks_berkas' => 5,
    'lampiran_maks_kb'     => 10240,
];
