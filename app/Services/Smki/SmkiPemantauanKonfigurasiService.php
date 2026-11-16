<?php

namespace App\Services\Smki;

use App\Models\SmkiKonfigurasiItem;
use App\Models\SmkiNilaiStandar;
use App\Models\SmkiObjek;
use App\Models\SmkiPemantauan;
use App\Models\SmkiPemantauanObjek;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Logic bisnis FR-048 — Pemantauan Manajemen Konfigurasi. */
class SmkiPemantauanKonfigurasiService
{
    // ───────────────────── Serializers ─────────────────────
    public static function detailResource(SmkiPemantauan $p): array
    {
        $p->loadMissing(['jenis:id,nama', 'pelaksana:id,name', 'admin:id,name', 'objeks.items', 'riwayat' => fn ($q) => $q->orderBy('id')]);

        return [
            'id' => $p->id,
            'noFormulir' => $p->no_formulir ?? '',
            'noRevisi' => $p->no_revisi,
            'tanggalBerlaku' => optional($p->tanggal_berlaku)->format('Y-m-d'),
            'tanggalPemantauan' => optional($p->tanggal_pemantauan)->format('Y-m-d'),
            'periode' => $p->periode,
            'jenisKonfigurasiId' => $p->jenis_konfigurasi_id ?? 0,
            'jenisKonfigurasiNama' => $p->jenis?->nama ?? '',
            'status' => $p->status,
            'hasil' => $p->hasil,
            'jumlahSesuai' => (int) $p->jumlah_sesuai,
            'jumlahTidakSesuai' => (int) $p->jumlah_tidak_sesuai,
            'catatanTindakLanjut' => $p->catatan_tindak_lanjut ?? '',
            'catatanAdmin' => $p->catatan_admin,
            'pelaksanaId' => $p->pelaksana_id,
            'pelaksanaNama' => $p->pelaksana?->name ?? '',
            'adminId' => $p->admin_id,
            'adminNama' => $p->admin?->name,
            'tanggalTtdPelaksana' => $p->tanggal_ttd_pelaksana?->toISOString(),
            'tanggalTtdAdmin' => $p->tanggal_ttd_admin?->toISOString(),
            'ttdPelaksanaNama' => $p->ttd_pelaksana_nama,
            'ttdPelaksanaJabatan' => $p->ttd_pelaksana_jabatan,
            'ttdAdminNama' => $p->ttd_admin_nama,
            'ttdAdminJabatan' => $p->ttd_admin_jabatan,
            'siklus' => $p->siklus,
            'objekList' => $p->objeks->map(fn (SmkiPemantauanObjek $o) => [
                'id' => $o->id,
                'objekId' => $o->objek_id,
                'objekNama' => $o->objek_nama,
                'items' => $o->items->map(fn (SmkiKonfigurasiItem $i) => [
                    'id' => $i->id,
                    'objekId' => $o->objek_id,
                    'namaKonfigurasi' => $i->nama_konfigurasi,
                    'nilaiAktual' => $i->nilai_aktual ?? '',
                    'nilaiStandar' => $i->nilai_standar_salinan,
                    'nilaiStandarId' => $i->nilai_standar_id,
                    'kesesuaian' => $i->kesesuaian,
                    'isStandar' => (bool) $i->is_standar,
                    'keterangan' => $i->keterangan ?? '',
                ])->values()->all(),
            ])->values()->all(),
            'riwayat' => $p->riwayat->map(fn ($r) => [
                'id' => $r->id,
                'aksi' => $r->aksi,
                'pelaku' => $r->pelaku_nama,
                'siklus' => $r->siklus,
                'waktu' => $r->created_at?->toISOString(),
                'catatan' => $r->catatan,
                'hasil' => $r->hasil,
            ])->values()->all(),
            'createdAt' => $p->created_at?->toISOString(),
            'updatedAt' => $p->updated_at?->toISOString(),
        ];
    }

    public static function listItemResource(SmkiPemantauan $p): array
    {
        $p->loadMissing(['jenis:id,nama', 'pelaksana:id,name', 'admin:id,name', 'objeks']);

        return [
            'id' => $p->id,
            'noFormulir' => $p->no_formulir ?? '',
            'objekRingkas' => $p->objeks->pluck('objek_nama')->implode(', '),
            'tanggalPemantauan' => optional($p->tanggal_pemantauan)->format('Y-m-d'),
            'periode' => $p->periode,
            'jenisKonfigurasiNama' => $p->jenis?->nama ?? '',
            'status' => $p->status,
            'hasil' => $p->hasil,
            'siklus' => $p->siklus,
            'pelaksanaNama' => $p->pelaksana?->name ?? '',
            'adminNama' => $p->admin?->name,
            'updatedAt' => $p->updated_at?->toISOString(),
        ];
    }

    public static function snapshot(SmkiPemantauan $p): array
    {
        $resource = self::detailResource($p);
        return [
            'objekList' => $resource['objekList'],
            'jumlahSesuai' => $resource['jumlahSesuai'],
            'jumlahTidakSesuai' => $resource['jumlahTidakSesuai'],
            'hasil' => $resource['hasil'],
        ];
    }

    // ───────────────────── Actions ─────────────────────
    public static function createDraft(User $user): SmkiPemantauan
    {
        return DB::transaction(function () use ($user) {
            $p = SmkiPemantauan::create([
                'tanggal_pemantauan' => now()->toDateString(),
                'periode' => (int) ceil((int) now()->month / 3),
                'jenis_konfigurasi_id' => null,
                'status' => 'draft',
                'pelaksana_id' => $user->id,
            ]);
            $p->riwayat()->create([
                'pelaku_id' => $user->id,
                'pelaku_nama' => $user->name,
                'aksi' => 'dibuat',
                'siklus' => 1,
                'hasil' => null,
                'catatan' => null,
                'snapshot' => null,
            ]);

            return $p;
        });
    }

    public static function updateIdentifikasi(SmkiPemantauan $p, array $data): SmkiPemantauan
    {
        return DB::transaction(function () use ($p, $data) {
            $p->update([
                'no_formulir' => trim($data['noFormulir']),
                'no_revisi' => trim($data['noRevisi']),
                'tanggal_berlaku' => $data['tanggalBerlaku'],
                'tanggal_pemantauan' => $data['tanggalPemantauan'],
                'periode' => (int) $data['periode'],
                'jenis_konfigurasi_id' => (int) $data['jenisKonfigurasiId'],
                'version' => $p->version + 1,
            ]);

            $existing = $p->objeks()->pluck('objek_id')->all();
            $selected = $data['objekIds'];

            // Hapus objek yang dilepas dari pilihan (hanya relevan di status draft)
            $removed = array_diff($existing, $selected);
            if ($removed) {
                $p->objeks()->whereIn('objek_id', $removed)->delete();
            }

            // Tambah objek baru + auto-fill baris standar
            $added = array_diff($selected, $existing);
            foreach ($added as $objekId) {
                $objek = SmkiObjek::with(['nilaiStandars' => fn ($q) => $q->where('is_active', true)->orderBy('nama_konfigurasi')])
                    ->findOrFail($objekId);
                $po = $p->objeks()->create([
                    'objek_id' => $objek->id,
                    'objek_nama' => $objek->nama,
                ]);
                foreach ($objek->nilaiStandars as $standar) {
                    $po->items()->create([
                        'nilai_standar_id' => $standar->id,
                        'nama_konfigurasi' => $standar->nama_konfigurasi,
                        'nilai_standar_salinan' => $standar->nilai_standar,
                        'nilai_aktual' => null,
                        'kesesuaian' => null,
                        'is_standar' => true,
                    ]);
                }
            }

            return $p->fresh();
        });
    }

    public static function saveKonfigurasi(SmkiPemantauan $p, array $rows): SmkiPemantauan
    {
        return DB::transaction(function () use ($p, $rows) {
            $objeks = $p->objeks()->with('items')->get()->keyBy('objek_id');

            foreach ($objeks as $objekId => $po) {
                /** @var SmkiPemantauanObjek $po */
                $rowsForObjek = array_values(array_filter($rows, fn ($r) => (int) $r['objekId'] === (int) $po->objek_id));
                $existing = $po->items->keyBy('id');
                $keptIds = [];

                foreach ($rowsForObjek as $row) {
                    $rowId = isset($row['id']) ? (int) $row['id'] : null;
                    $payload = [
                        'nama_konfigurasi' => trim($row['namaKonfigurasi']),
                        'nilai_standar_salinan' => $row['nilaiStandar'] ?? null,
                        'nilai_standar_id' => isset($row['nilaiStandarId']) ? (int) $row['nilaiStandarId'] : null,
                        'nilai_aktual' => trim($row['nilaiAktual']),
                        'kesesuaian' => $row['kesesuaian'] ?? null,
                        'is_standar' => (bool) ($row['isStandar'] ?? false),
                        'keterangan' => $row['keterangan'] ?? null,
                    ];

                    $existingItem = $rowId ? $existing->get($rowId) : null;

                    if ($payload['is_standar']) {
                        if (!$existingItem || !$existingItem->is_standar) {
                            throw ValidationException::withMessages([
                                'items' => 'Baris standar tidak bisa dibuat/dihapus manual.',
                            ]);
                        }
                        // Baris standar: nama & nilai_standar dipaksa dari snapshot, bukan dari kiriman tampilan
                        $payload['nama_konfigurasi'] = $existingItem->nama_konfigurasi;
                        $payload['nilai_standar_salinan'] = $existingItem->nilai_standar_salinan;
                        $payload['nilai_standar_id'] = $existingItem->nilai_standar_id;
                    }

                    if ($existingItem) {
                        $existingItem->update($payload);
                        $keptIds[] = $existingItem->id;
                    } else {
                        if ($payload['is_standar']) {
                            throw ValidationException::withMessages([
                                'items' => 'Baris standar baru tidak bisa ditambahkan manual.',
                            ]);
                        }
                        $created = $po->items()->create($payload);
                        $keptIds[] = $created->id;
                    }
                }

                $po->items()->where('is_standar', false)->whereNotIn('id', $keptIds)->delete();
            }

            self::rekap($p);

            return $p->fresh();
        });
    }

    public static function periksa(SmkiPemantauan $p): SmkiPemantauan
    {
        return DB::transaction(function () use ($p) {
            $items = $p->objeks()->with('items')->get()->flatMap(fn ($po) => $po->items);

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Belum ada baris konfigurasi.',
                ]);
            }

            foreach ($items as $item) {
                if (trim((string) $item->nama_konfigurasi) === '' || trim((string) $item->nilai_aktual) === '') {
                    throw ValidationException::withMessages([
                        'items' => 'Semua baris wajib memiliki Nama Konfigurasi dan Nilai aktual.',
                    ]);
                }
                if (!$item->is_standar && !$item->kesesuaian) {
                    throw ValidationException::withMessages([
                        'items' => 'Tandai Kesesuaian (Sesuai / Tidak Sesuai) pada semua baris tanpa nilai standar.',
                    ]);
                }
                if ($item->is_standar) {
                    $item->kesesuaian = strcasecmp(trim((string) $item->nilai_aktual), trim((string) $item->nilai_standar_salinan)) === 0
                        ? 'sesuai'
                        : 'tidak_sesuai';
                    $item->save();
                }
            }

            self::rekap($p);

            return $p->fresh();
        });
    }

    // ───────────────────── Phase 4 Actions ─────────────────────
    public static function ajukan(SmkiPemantauan $p, array $data, User $actor): SmkiPemantauan
    {
        return DB::transaction(function () use ($p, $data, $actor) {
            $p = SmkiPemantauan::whereKey($p->id)->lockForUpdate()->firstOrFail();

            if (!in_array($p->status, ['draft', 'revisi'], true)) {
                throw ValidationException::withMessages(['status' => 'Pemantauan hanya dapat diajukan pada status Draft / Revisi.']);
            }
            if ($p->pelaksana_id !== $actor->id) {
                throw ValidationException::withMessages(['pelaksana' => 'Anda bukan Pelaksana pemantauan ini.']);
            }

            $admin = User::where('id', $data['adminId'])->where('role', 'admin')->where('is_active', 1)->firstOrFail();
            if ($admin->id === $actor->id) {
                throw ValidationException::withMessages(['adminId' => 'Admin yang dipilih tidak boleh sama dengan Pelaksana.']);
            }

            $items = $p->objeks()->with('items')->get()->flatMap(fn ($po) => $po->items);
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'Belum ada baris konfigurasi.']);
            }
            if ($items->whereNull('kesesuaian')->isNotEmpty()) {
                throw ValidationException::withMessages(['periksa' => 'Periksa kesesuaian terlebih dahulu.']);
            }

            foreach ($items as $item) {
                if ($item->kesesuaian === 'tidak_sesuai' && trim((string) $item->keterangan) === '') {
                    throw ValidationException::withMessages(['keterangan' => 'Keterangan wajib diisi pada baris Tidak Sesuai: ' . $item->nama_konfigurasi]);
                }
            }

            $adaTidakSesuai = $items->where('kesesuaian', 'tidak_sesuai')->isNotEmpty();
            $catatan = trim((string) ($data['catatanTindakLanjut'] ?? ''));
            if ($adaTidakSesuai && $catatan === '') {
                throw ValidationException::withMessages(['catatanTindakLanjut' => 'Catatan Tindak Lanjut wajib diisi karena terdapat baris Tidak Sesuai.']);
            }

            $isResubmit = $p->status === 'revisi';
            if ($isResubmit) {
                $p->siklus += 1;
            }

            $now = now();
            $p->fill([
                'admin_id' => $admin->id,
                'catatan_tindak_lanjut' => $catatan,
                'catatan_admin' => null,
                'tanggal_ttd_pelaksana' => $now,
                'tanggal_ttd_admin' => null,
                'ttd_pelaksana_nama' => $actor->name,
                'ttd_pelaksana_jabatan' => $actor->position ?: 'Pengelola Sistem Informasi / SMKI',
                // TTD Admin dicatat saat admin menandatangani (selesaikan)
                'ttd_admin_nama' => null,
                'ttd_admin_jabatan' => null,
                'status' => 'diajukan',
                'version' => $p->version + 1,
            ])->save();

            $p->riwayat()->create([
                'pelaku_id' => $actor->id,
                'pelaku_nama' => $actor->name,
                'aksi' => $isResubmit ? 'diajukan_ulang' : 'diajukan',
                'siklus' => $p->siklus,
                'hasil' => $p->hasil,
                'catatan' => $catatan !== '' ? $catatan : null,
                'snapshot' => self::snapshot($p),
            ]);

            return $p->fresh();
        });
    }

    public static function selesaikan(SmkiPemantauan $p, User $actor): SmkiPemantauan
    {
        return DB::transaction(function () use ($p, $actor) {
            $p = SmkiPemantauan::whereKey($p->id)->lockForUpdate()->firstOrFail();

            if ($p->status !== 'diajukan') {
                throw ValidationException::withMessages(['status' => 'Pemantauan hanya dapat diselesaikan saat status Menunggu Persetujuan.']);
            }
            if ($p->admin_id !== $actor->id) {
                throw ValidationException::withMessages(['adminId' => 'Hanya Admin yang dipilih yang dapat menyelesaikan pemantauan ini.']);
            }

            $now = now();
            $p->update([
                'status' => 'selesai',
                'tanggal_ttd_admin' => $now,
                'ttd_admin_nama' => $actor->name,
                'ttd_admin_jabatan' => $actor->position ?: 'Pengelola Sistem Informasi / SMKI',
                'version' => $p->version + 1,
            ]);

            $p->riwayat()->create([
                'pelaku_id' => $actor->id,
                'pelaku_nama' => $actor->name,
                'aksi' => 'disetujui',
                'siklus' => $p->siklus,
                'hasil' => $p->hasil,
                'catatan' => null,
                'snapshot' => self::snapshot($p),
            ]);

            return $p->fresh();
        });
    }

    public static function kembalikan(SmkiPemantauan $p, string $catatan, User $actor): SmkiPemantauan
    {
        return DB::transaction(function () use ($p, $catatan, $actor) {
            $p = SmkiPemantauan::whereKey($p->id)->lockForUpdate()->firstOrFail();

            if ($p->status !== 'diajukan') {
                throw ValidationException::withMessages(['status' => 'Pemantauan hanya dapat dikembalikan saat status Menunggu Persetujuan.']);
            }
            if ($p->admin_id !== $actor->id) {
                throw ValidationException::withMessages(['adminId' => 'Hanya Admin yang dipilih yang dapat mengembalikan pemantauan ini.']);
            }

            $p->update([
                'status' => 'revisi',
                'catatan_admin' => $catatan,
                'version' => $p->version + 1,
            ]);

            $p->riwayat()->create([
                'pelaku_id' => $actor->id,
                'pelaku_nama' => $actor->name,
                'aksi' => 'dikembalikan',
                'siklus' => $p->siklus,
                'hasil' => $p->hasil,
                'catatan' => $catatan,
                'snapshot' => self::snapshot($p),
            ]);

            return $p->fresh();
        });
    }

    public static function hapusDraft(SmkiPemantauan $p, User $actor): void
    {
        DB::transaction(function () use ($p, $actor) {
            $p = SmkiPemantauan::whereKey($p->id)->lockForUpdate()->firstOrFail();

            if ($p->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Hanya pemantauan berstatus Draft yang dapat dihapus.']);
            }
            if ($p->pelaksana_id !== $actor->id) {
                throw ValidationException::withMessages(['pelaksana' => 'Anda bukan Pelaksana pemantauan ini.']);
            }

            $p->delete();
        });
    }

    /** Hitung ulang jumlah_sesuai/tidak_sesuai + hasil dari kesesuaian per baris. */
    private static function rekap(SmkiPemantauan $p): void
    {
        $items = SmkiKonfigurasiItem::whereIn('pemantauan_objek_id', $p->objeks()->select('id'))->get();
        $sesuai = $items->where('kesesuaian', 'sesuai')->count();
        $tidak = $items->where('kesesuaian', 'tidak_sesuai')->count();
        $hasil = $items->isEmpty() ? null : ($tidak === 0 ? 'sesuai_seluruhnya' : 'ada_ketidaksesuaian');

        $p->update([
            'jumlah_sesuai' => $sesuai,
            'jumlah_tidak_sesuai' => $tidak,
            'hasil' => $hasil,
            'version' => $p->version + 1,
        ]);
    }
}
