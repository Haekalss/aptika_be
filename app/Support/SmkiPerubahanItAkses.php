<?php

namespace App\Support;

use App\Models\SmkiPerubahanIt;

/**
 * Aturan hak akses alur Perubahan TI (FR-015).
 *
 * - Pemohon                  : semua pengguna SMKI (hanya pemilik data yang boleh mengubah/menghapus)
 * - Koordinator Agen         : menerima / mengembalikan formulir berstatus "Diajukan"
 * - Penanggung Jawab Perangkat: menyetujui / menolak formulir berstatus "Diterima"
 * - Agen                     : melaksanakan formulir berstatus "Disetujui"
 */
class SmkiPerubahanItAkses
{
    public const PERAN_KOORDINATOR = 'koordinator_agen';
    public const PERAN_PENANGGUNG_JAWAB = 'penanggung_jawab_perangkat';
    public const PERAN_AGEN = 'agen';

    /**
     * Selama false, pengecekan peran/pemilik dilewati (hanya aturan status yang berlaku).
     */
    public static function terapkan(): bool
    {
        return (bool) config('smki_perubahan_it.terapkan_hak_akses', false);
    }

    public static function isAdmin($user): bool
    {
        if (!$user) {
            return false;
        }

        if (($user->role ?? null) === 'admin') {
            return true;
        }

        return method_exists($user, 'isAdminAptika') && (bool) $user->isAdminAptika();
    }

    public static function punyaPeran($user, string $peran): bool
    {
        if (!$user) {
            return false;
        }

        if (!self::terapkan()) {
            return true;
        }

        if (self::isAdmin($user) && config('smki_perubahan_it.admin_semua_peran', true)) {
            return true;
        }

        $email = strtolower((string) ($user->email ?? ''));

        return $email !== '' && in_array($email, (array) config("smki_perubahan_it.{$peran}", []), true);
    }

    public static function pemilik(SmkiPerubahanIt $item, $user): bool
    {
        if (!$user) {
            return false;
        }

        if (!self::terapkan()) {
            return true;
        }

        return self::isAdmin($user)
            || ($item->created_by !== null && (int) $item->created_by === (int) $user->id);
    }

    /**
     * Izin aksi untuk satu formulir bagi pengguna tertentu.
     * Dikirim ke frontend supaya tombol yang tampil sesuai peran dan status.
     */
    public static function izin(SmkiPerubahanIt $item, $user): array
    {
        $status = $item->status;
        $pemilik = self::pemilik($item, $user);
        $dapatDiubah = $pemilik && in_array($status, [
            SmkiPerubahanIt::STATUS_DRAFT,
            SmkiPerubahanIt::STATUS_DIKEMBALIKAN,
        ], true);

        $koordinator = $status === SmkiPerubahanIt::STATUS_DIAJUKAN
            && self::punyaPeran($user, self::PERAN_KOORDINATOR);
        $penanggungJawab = $status === SmkiPerubahanIt::STATUS_DITERIMA
            && self::punyaPeran($user, self::PERAN_PENANGGUNG_JAWAB);
        $agen = $status === SmkiPerubahanIt::STATUS_DISETUJUI
            && self::punyaPeran($user, self::PERAN_AGEN);

        return [
            'can_edit'       => $dapatDiubah,
            'can_ajukan'     => $dapatDiubah,
            'can_delete'     => $pemilik && $status === SmkiPerubahanIt::STATUS_DRAFT,
            'can_terima'     => $koordinator,
            'can_kembalikan' => $koordinator,
            'can_setujui'    => $penanggungJawab,
            'can_tolak'      => $penanggungJawab,
            'can_laksanakan' => $agen,
        ];
    }
}
