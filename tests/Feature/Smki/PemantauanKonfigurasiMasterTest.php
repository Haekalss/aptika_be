<?php

namespace Tests\Feature\Smki;

use App\Models\SmkiJenisKonfigurasi;
use App\Models\SmkiObjek;
use App\Models\SmkiNilaiStandar;
use App\Models\SmkiPemantauan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class PemantauanKonfigurasiMasterTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Tes FR-048 hanya boleh memakai SQLite :memory:.');
        }
    }

    protected function migrateFreshUsing(): array
    {
        return ['--path' => [
            'database/migrations/0001_01_01_000000_create_users_table.php',
            'database/migrations/2026_07_03_075728_create_pegawai_table.php',
            'database/migrations/2026_08_20_000001_create_bidangs_table.php',
            'database/migrations/2026_08_20_000002_create_services_table.php',
            'database/migrations/2026_08_20_000003_create_bidang_services_table.php',
            'database/migrations/2026_08_20_000004_add_bidang_id_to_users_table.php',
            'database/migrations/2026_08_20_000006_add_parent_id_to_services_table.php',
            'database/migrations/2026_11_16_000001_create_smki_jenis_konfigurasis_table.php',
            'database/migrations/2026_11_16_000002_create_smki_objeks_table.php',
            'database/migrations/2026_11_16_000003_create_smki_nilai_standars_table.php',
            'database/migrations/2026_11_16_000004_create_smki_pemantauans_table.php',
            'database/migrations/2026_11_16_000005_create_smki_pemantauan_objeks_table.php',
            'database/migrations/2026_11_16_000006_create_smki_konfigurasi_items_table.php',
            'database/migrations/2026_11_16_000007_create_smki_riwayat_pemantauans_table.php',
            'database/migrations/2026_11_16_000008_seed_smki_pemantauan_konfigurasi_masters.php',
            'database/migrations/2026_11_16_000009_make_jenis_konfigurasi_id_nullable_on_smki_pemantauans_table.php',
        ]];
    }

    public function test_seed_memuat_master_resmi(): void
    {
        $this->assertDatabaseCount('smki_jenis_konfigurasis', 4);
        $this->assertDatabaseCount('smki_objeks', 6);
        $this->assertDatabaseCount('smki_nilai_standars', 27);

        $srv = SmkiObjek::where('nama', 'SRV-PROD-01')->firstOrFail();
        $this->assertSame('Server', $srv->jenis->nama);
        $this->assertSame(7, $srv->nilaiStandars()->count());
        $this->assertTrue($srv->is_active);
    }

    public function test_relasi_master_satu_ke_banyak(): void
    {
        $jenis = SmkiJenisKonfigurasi::where('nama', 'Server')->firstOrFail();
        $this->assertCount(2, $jenis->objeks);

        $objek = SmkiObjek::where('nama', 'SRV-DB-01')->with('nilaiStandars')->firstOrFail();
        $this->assertCount(4, $objek->nilaiStandars);
        $this->assertContains('DBMS', $objek->nilaiStandars->pluck('nama_konfigurasi'));

        foreach ($objek->nilaiStandars as $standar) {
            $this->assertSame($objek->id, $standar->objek_id);
        }
    }

    public function test_scope_visible_to_pelaksana_dan_admin(): void
    {        $pelaksana = User::create([
            'name' => 'Pelaksana Uji', 'email' => 'pelaksana@test.local',
            'password' => bcrypt('password'), 'role' => 'user', 'is_active' => 1,
            'bidang_id' => 3,
        ]);
        $admin = User::create([
            'name' => 'Admin Uji', 'email' => 'admin@test.local',
            'password' => bcrypt('password'), 'role' => 'admin', 'is_active' => 1,
            'bidang_id' => 3,
        ]);

        // Pelaksana: hanya miliknya → query mengandung where pelaksanaan_id
        $userSql = SmkiPemantauan::visibleTo($pelaksana)->toSql();
        $this->assertStringContainsString('pelaksana_id', $userSql);

        // Admin: seluruh pemantauan (tidak ada filter pelaksana)
        $adminSql = SmkiPemantauan::visibleTo($admin)->toSql();
        $this->assertStringNotContainsString('pelaksana_id', $adminSql);
    }

    private function login(string $role = 'user', bool $active = true): User
    {
        $user = User::factory()->create([
            'bidang_id' => 3,
            'role' => $role,
            'is_active' => $active ? 1 : 0,
        ]);
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_lookup_jenis_dan_objek_terbuka_untuk_user(): void
    {
        $this->login('user');

        $res = $this->getJson('/api/smki/pemantauan-konfigurasi/lookup/jenis');
        $res->assertOk();
        $this->assertSame(4, $res->json('data') ? count($res->json('data')) : 0);
        $this->assertContains('Server', array_column($res->json('data'), 'nama'));

        $server = SmkiJenisKonfigurasi::where('nama', 'Server')->firstOrFail();
        $resObjek = $this->getJson('/api/smki/pemantauan-konfigurasi/lookup/objek?jenis_id=' . $server->id);
        $resObjek->assertOk();
        $this->assertSame(2, count($resObjek->json('data')));

        $invalid = $this->getJson('/api/smki/pemantauan-konfigurasi/lookup/objek');
        $invalid->assertStatus(422);
    }

    public function test_lookup_admins_hanya_admin_aktif(): void
    {
        User::factory()->create(['bidang_id' => 3, 'role' => 'user', 'is_active' => 1]);
        User::factory()->create(['bidang_id' => 3, 'role' => 'admin', 'is_active' => 0]);
        $adminAktif = User::factory()->create(['bidang_id' => 3, 'role' => 'admin', 'is_active' => 1, 'name' => 'Admin Tes A', 'email' => 'admina@test.local']);
        $this->login('user');

        $res = $this->getJson('/api/smki/pemantauan-konfigurasi/lookup/admins');
        $res->assertOk();
        $data = $res->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Admin Tes A', $data[0]['name']);
        $this->assertSame('admina@test.local', $data[0]['email']);
        $this->assertArrayHasKey('position', $data[0]);
    }

    public function test_master_ditolak_untuk_user_biasa(): void
    {
        $this->login('user');
        $this->getJson('/api/smki/pemantauan-konfigurasi/master/jenis')->assertForbidden();
        $this->postJson('/api/smki/pemantauan-konfigurasi/master/jenis', ['nama' => 'Baru'])->assertForbidden();
    }

    public function test_admin_crud_jenis_dan_toggle_aktif(): void
    {
        $this->login('admin');

        // Nama yang sudah ada (case-insensitive) → 422
        $res = $this->postJson('/api/smki/pemantauan-konfigurasi/master/jenis', ['nama' => 'Server']);
        $res->assertStatus(422);

        $res = $this->postJson('/api/smki/pemantauan-konfigurasi/master/jenis', ['nama' => 'Database', 'keterangan' => 'Sistem database']);
        $res->assertCreated();
        $id = $res->json('data.id');

        // Toggle nonaktif → hilang dari lookup, tampil di master dengan isActive=false
        $this->putJson('/api/smki/pemantauan-konfigurasi/master/jenis/' . $id, ['nama' => 'Database', 'isActive' => false])->assertOk();

        $lookup = $this->getJson('/api/smki/pemantauan-konfigurasi/lookup/jenis');
        $this->assertNotContains('Database', array_column($lookup->json('data'), 'nama'));

        $master = $this->getJson('/api/smki/pemantauan-konfigurasi/master/jenis');
        $this->assertContains('Database', array_column($master->json('data'), 'nama'));
    }

    public function test_admin_crud_objek_dengan_jenis(): void
    {
        $this->login('admin');
        $jenis = SmkiJenisKonfigurasi::where('nama', 'Server')->firstOrFail();

        // Objek duplikat (case-insensitive)
        $res = $this->postJson('/api/smki/pemantauan-konfigurasi/master/objek', ['nama' => 'SRV-PROD-01', 'jenisKonfigurasiId' => $jenis->id]);
        $res->assertStatus(422);

        // Jenis tidak valid / nonaktif
        $res = $this->postJson('/api/smki/pemantauan-konfigurasi/master/objek', ['nama' => 'SRV-NEW-01', 'jenisKonfigurasiId' => 99999]);
        $res->assertStatus(422);

        // Sukses
        $res = $this->postJson('/api/smki/pemantauan-konfigurasi/master/objek', ['nama' => 'SRV-NEW-01', 'jenisKonfigurasiId' => $jenis->id]);
        $res->assertCreated();
        $this->assertSame('Server', $res->json('data.jenisKonfigurasiNama'));
    }

    public function test_nilai_standar_replace_dan_duplikat(): void
    {
        $this->login('admin');
        $objek = SmkiObjek::where('nama', 'SRV-PROD-01')->firstOrFail();
        $before = SmkiNilaiStandar::where('objek_id', $objek->id)->where('is_active', true)->count();
        $this->assertSame(7, $before);

        // Duplikat nama konfigurasi di payload → 422
        $res = $this->putJson('/api/smki/pemantauan-konfigurasi/master/nilai-standar/' . $objek->id, [
            'rows' => [
                ['namaKonfigurasi' => 'SSH Root Login', 'nilaiStandar' => 'disabled'],
                ['namaKonfigurasi' => 'ssh root login', 'nilaiStandar' => 'enabled'],
            ],
        ]);
        $res->assertStatus(422);

        // Replace: baris lama dinonaktifkan, 2 baris baru aktif
        $res = $this->putJson('/api/smki/pemantauan-konfigurasi/master/nilai-standar/' . $objek->id, [
            'rows' => [
                ['namaKonfigurasi' => 'Sistem Operasi', 'nilaiStandar' => 'Ubuntu 24.04 LTS'],
                ['namaKonfigurasi' => 'SSH Root Login', 'nilaiStandar' => 'disabled'],
            ],
        ]);
        $res->assertOk();
        $this->assertSame(2, count($res->json('data')));
        $this->assertSame(0, SmkiNilaiStandar::where('objek_id', $objek->id)->where('is_active', true)->where('nilai_standar', 'Ubuntu 22.04 LTS')->count());
        $this->assertSame(1, SmkiNilaiStandar::where('objek_id', $objek->id)->where('is_active', true)->where('nilai_standar', 'Ubuntu 24.04 LTS')->count());
    }

    public function test_tanpa_autentikasi_lookup_401(): void
    {
        $this->getJson('/api/smki/pemantauan-konfigurasi/lookup/jenis')->assertUnauthorized();
        $this->getJson('/api/smki/pemantauan-konfigurasi/master/jenis')->assertUnauthorized();
    }

    // ───────────────────────────── Phase 3 ─────────────────────────────

    private function seedKosong(array $overrides = []): array
    {
        $defaults = [
            'noFormulir' => 'FR-048/TEST/01',
            'noRevisi' => '0.0',
            'tanggalBerlaku' => '2026-01-01',
            'tanggalPemantauan' => '2026-11-16',
            'periode' => 4,
            'jenisKonfigurasiId' => SmkiJenisKonfigurasi::where('nama', 'Server')->first()->id,
            'objekIds' => [SmkiObjek::where('nama', 'SRV-PROD-01')->first()->id],
        ];

        return array_merge($defaults, $overrides);
    }

    public function test_create_draft_hanya_untuk_user(): void
    {
        $this->login('admin');
        $this->postJson('/api/smki/pemantauan-konfigurasi', [])->assertForbidden();

        $user = $this->login('user');
        $res = $this->postJson('/api/smki/pemantauan-konfigurasi', []);
        $res->assertCreated();
        $this->assertSame('draft', $res->json('data.status'));
        $this->assertSame($user->id, $res->json('data.pelaksanaId'));
    }

    public function test_identifikasi_auto_fill_baris_standar(): void
    {
        $this->login('user');
        $id = $this->postJson('/api/smki/pemantauan-konfigurasi', [])->json('data.id');

        $res = $this->putJson("/api/smki/pemantauan-konfigurasi/$id/identifikasi", $this->seedKosong());
        $res->assertOk();
        $list = $res->json('data.objekList');
        $this->assertCount(1, $list);
        $this->assertSame('SRV-PROD-01', $list[0]['objekNama']);
        $this->assertCount(7, $list[0]['items']); // 7 standar resmi
        $this->assertTrue($list[0]['items'][0]['isStandar']);
        $this->assertContains('Sistem Operasi', array_column($list[0]['items'], 'namaKonfigurasi'));
    }

    public function test_identifikasi_objek_beda_jenis_ditolak(): void
    {
        $this->login('user');
        $id = $this->postJson('/api/smki/pemantauan-konfigurasi', [])->json('data.id');
        $jaringan = SmkiJenisKonfigurasi::where('nama', 'Jaringan')->first()->id;

        $res = $this->putJson("/api/smki/pemantauan-konfigurasi/$id/identifikasi", $this->seedKosong([
            'jenisKonfigurasiId' => $jaringan,
            'objekIds' => [SmkiObjek::where('nama', 'SRV-PROD-01')->first()->id], // Server, bukan Jaringan
        ]));
        $res->assertStatus(422);
        $this->assertStringContainsString('objekIds', json_encode($res->json('errors')));
    }

    public function test_konfigurasi_kunci_baris_standar(): void
    {
        $this->login('user');
        $id = $this->postJson('/api/smki/pemantauan-konfigurasi', [])->json('data.id');
        $detail = $this->putJson("/api/smki/pemantauan-konfigurasi/$id/identifikasi", $this->seedKosong())->json('data');

        $items = $detail['objekList'][0]['items'];
        foreach ($items as &$item) {
            $item['nilaiAktual'] = $item['nilaiStandar'];
        }
        unset($item);
        // Kirim baris standar dengan nama yang diubah — server harus memaksanya kembali
        $soIndex = array_search('Sistem Operasi', array_column($items, 'namaKonfigurasi'));
        $this->assertNotFalse($soIndex);
        $items[$soIndex]['namaKonfigurasi'] = 'NGACO-HARUS-DIABAIKAN';
        // Tambah baris manual
        $items[] = [
            'objekId' => $detail['objekList'][0]['objekId'],
            'namaKonfigurasi' => 'Agent Monitoring',
            'nilaiAktual' => 'terpasang',
            'nilaiStandar' => null,
            'nilaiStandarId' => null,
            'kesesuaian' => 'sesuai',
            'isStandar' => false,
            'keterangan' => '',
        ];

        $res = $this->putJson("/api/smki/pemantauan-konfigurasi/$id/konfigurasi", ['items' => $items]);
        $res->assertOk();
        $returned = $res->json('data.objekList')[0]['items'];
        $this->assertContains('Sistem Operasi', array_column($returned, 'namaKonfigurasi')); // nama standar dikunci
        $this->assertNotContains('NGACO-HARUS-DIABAIKAN', array_column($returned, 'namaKonfigurasi'));
        $this->assertContains('Agent Monitoring', array_column($returned, 'namaKonfigurasi'));
    }

    public function test_konfigurasi_validasi_baris_wajib_isi(): void
    {
        $this->login('user');
        $id = $this->postJson('/api/smki/pemantauan-konfigurasi', [])->json('data.id');
        $detail = $this->putJson("/api/smki/pemantauan-konfigurasi/$id/identifikasi", $this->seedKosong())->json('data');
        $items = $detail['objekList'][0]['items'];
        $items[0]['nilaiAktual'] = ''; // kosong — harus ditolak

        $res = $this->putJson("/api/smki/pemantauan-konfigurasi/$id/konfigurasi", ['items' => $items]);
        $res->assertStatus(422);
    }

    public function test_periksa_hitung_kesesuaian_dan_hasil(): void
    {
        $this->login('user');
        $id = $this->postJson('/api/smki/pemantauan-konfigurasi', [])->json('data.id');
        $detail = $this->putJson("/api/smki/pemantauan-konfigurasi/$id/identifikasi", $this->seedKosong())->json('data');
        $items = $detail['objekList'][0]['items'];

        // Isi nilai aktual: 6 standar cocok + 1 tidak cocok (SSH Root Login diisi 'enabled')
        foreach ($items as &$item) {
            $item['nilaiAktual'] = $item['namaKonfigurasi'] === 'SSH Root Login' ? 'enabled' : $item['nilaiStandar'];
        }
        unset($item);

        // Periksa tanpa tanda manual (baris non-standar belum ada) — harus OK
        $res = $this->putJson("/api/smki/pemantauan-konfigurasi/$id/konfigurasi", ['items' => $items]);
        $res->assertOk();

        $res = $this->postJson("/api/smki/pemantauan-konfigurasi/$id/periksa");
        $res->assertOk();
        $data = $res->json('data');
        $this->assertSame(6, $data['jumlahSesuai']);
        $this->assertSame(1, $data['jumlahTidakSesuai']);
        $this->assertSame('ada_ketidaksesuaian', $data['hasil']);

        // Baris standar SSH Root Login kini tidak_sesuai
        $rows = $data['objekList'][0]['items'];
        $ssh = array_values(array_filter($rows, fn ($i) => $i['namaKonfigurasi'] === 'SSH Root Login'))[0];
        $this->assertSame('tidak_sesuai', $ssh['kesesuaian']);
    }

    public function test_visibility_draft_pelaksana_vs_admin(): void
    {
        $a = $this->login('user');
        $id = $this->postJson('/api/smki/pemantauan-konfigurasi', [])->json('data.id');

        // Pelaksana B tidak melihat draft A
        $b = $this->login('user');
        $res = $this->getJson('/api/smki/pemantauan-konfigurasi/' . $id);
        $res->assertNotFound();
        $list = $this->getJson('/api/smki/pemantauan-konfigurasi?status=draft')->json('data');
        $this->assertSame([], $list);

        // Admin melihat semua
        $this->login('admin');
        $res = $this->getJson('/api/smki/pemantauan-konfigurasi/' . $id);
        $res->assertOk();
        $list = $this->getJson('/api/smki/pemantauan-konfigurasi?status=draft')->json('data');
        $this->assertSame(1, count($list));
    }

    public function test_list_ringkasan_summary(): void
    {
        $this->login('user');
        $this->postJson('/api/smki/pemantauan-konfigurasi', []);
        $res = $this->getJson('/api/smki/pemantauan-konfigurasi/summary');
        $res->assertOk();
        $this->assertSame(1, $res->json('data.total'));
        $this->assertSame(1, $res->json('data.draft'));
        $this->assertSame(0, $res->json('data.selesai'));
    }

    // ───────────────────────────── Phase 4 ─────────────────────────────

    /** Buat pemantauan sampai siap diajukan: 1 baris Tidak Sesuai (SSH Root Login = 'enabled'). */
    private function buatSiapAjukan(string $keterangan = 'Belum diperbaiki'): array
    {
        $user = $this->login('user');
        $id = $this->postJson('/api/smki/pemantauan-konfigurasi', [])->json('data.id');
        $detail = $this->putJson("/api/smki/pemantauan-konfigurasi/$id/identifikasi", $this->seedKosong())->json('data');
        $items = $detail['objekList'][0]['items'];
        foreach ($items as &$item) {
            if ($item['namaKonfigurasi'] === 'SSH Root Login') {
                $item['nilaiAktual'] = 'enabled';
                $item['keterangan'] = $keterangan;
            } else {
                $item['nilaiAktual'] = $item['nilaiStandar'];
            }
        }
        unset($item);
        $this->putJson("/api/smki/pemantauan-konfigurasi/$id/konfigurasi", ['items' => $items])->assertOk();
        $this->postJson("/api/smki/pemantauan-konfigurasi/$id/periksa")->assertOk();

        return [$id, $user];
    }

    private function buatAdmin(string $name = 'Admin Tes'): User
    {
        return User::factory()->create([
            'name' => $name . ' ' . strtoupper(substr(md5(uniqid()), 0, 4)),
            'role' => 'admin',
            'is_active' => 1,
            'bidang_id' => 3,
        ]);
    }

    public function test_ajukan_berhasil_snapshot_ttd_pelaksana(): void
    {
        [$id, $user] = $this->buatSiapAjukan();
        $admin = $this->buatAdmin('Rina Puspita');

        $res = $this->postJson("/api/smki/pemantauan-konfigurasi/$id/ajukan", [
            'adminId' => $admin->id,
            'catatanTindakLanjut' => 'Perbaiki SSH Root Login sebelum 30 Nov.',
        ]);
        $res->assertOk();
        $data = $res->json('data');
        $this->assertSame('diajukan', $data['status']);
        $this->assertSame($admin->id, $data['adminId']);
        $this->assertSame($admin->name, $data['adminNama']);
        $this->assertNotNull($data['tanggalTtdPelaksana']);
        $this->assertNull($data['tanggalTtdAdmin']); // belum ada admin TTD
        $this->assertSame($user->name, $data['pelaksanaNama']);
        $this->assertSame(2, count($data['riwayat']));
        $this->assertSame('diajukan', $data['riwayat'][1]['aksi']);
        $this->assertSame('Perbaiki SSH Root Login sebelum 30 Nov.', $data['riwayat'][1]['catatan']);
        $this->assertSame('ada_ketidaksesuaian', $data['riwayat'][1]['hasil']);
    }

    public function test_ajukan_tanpa_periksa_ditolak(): void
    {
        $user = $this->login('user');
        $id = $this->postJson('/api/smki/pemantauan-konfigurasi', [])->json('data.id');
        $detail = $this->putJson("/api/smki/pemantauan-konfigurasi/$id/identifikasi", $this->seedKosong())->json('data');
        $items = $detail['objekList'][0]['items'];
        foreach ($items as &$item) {
            $item['nilaiAktual'] = $item['nilaiStandar'];
        }
        unset($item);
        $this->putJson("/api/smki/pemantauan-konfigurasi/$id/konfigurasi", ['items' => $items])->assertOk();
        // belum periksa → ajukan harus 422
        $res = $this->postJson("/api/smki/pemantauan-konfigurasi/$id/ajukan", [
            'adminId' => $this->buatAdmin()->id,
            'catatanTindakLanjut' => '',
        ]);
        $res->assertStatus(422);
        $this->assertStringContainsString('periksa', json_encode($res->json('errors')));
    }

    public function test_ajukan_tolak_admin_invalid_dan_wajib_catatan(): void
    {
        [$id] = $this->buatSiapAjukan();

        // adminId wajib
        $res = $this->postJson("/api/smki/pemantauan-konfigurasi/$id/ajukan", ['catatanTindakLanjut' => 'x']);
        $res->assertStatus(422);

        // adminId bukan admin (user biasa)
        $userBiasa = User::factory()->create(['bidang_id' => 3, 'role' => 'user', 'is_active' => 1]);
        $res = $this->postJson("/api/smki/pemantauan-konfigurasi/$id/ajukan", ['adminId' => $userBiasa->id]);
        $res->assertStatus(422);

        // Ada tidak_sesuai + catatan kosong → 422
        $res = $this->postJson("/api/smki/pemantauan-konfigurasi/$id/ajukan", [
            'adminId' => $this->buatAdmin()->id,
            'catatanTindakLanjut' => '',
        ]);
        $res->assertStatus(422);

        // Keterangan tidak sesuai kosong → 422 setelah semua siap
        [$id2] = $this->buatSiapAjukan(''); // keterangan kosong
        $res = $this->postJson("/api/smki/pemantauan-konfigurasi/$id2/ajukan", [
            'adminId' => $this->buatAdmin()->id,
            'catatanTindakLanjut' => 'Sudah ditanggulangi.',
        ]);
        $res->assertStatus(422);
    }

    public function test_selesaikan_hanya_oleh_admin_dipilih(): void
    {
        [$id] = $this->buatSiapAjukan();
        $admin1 = $this->buatAdmin('Admin Pertama');
        $admin2 = $this->buatAdmin('Admin Kedua');

        $this->postJson("/api/smki/pemantauan-konfigurasi/$id/ajukan", [
            'adminId' => $admin1->id,
            'catatanTindakLanjut' => 'Dikerjakan segera.',
        ])->assertOk();

        // Admin tidak dipilih → 422 (bukan 401/403 — service menolak)
        $this->login('admin'); // actor = user factory baru, bukan admin1
        $res = $this->postJson("/api/smki/pemantauan-konfigurasi/$id/selesaikan");
        $res->assertStatus(422);

        // Admin dipilih → 200
        $this->actingAs($admin1, 'sanctum');
        $res = $this->postJson("/api/smki/pemantauan-konfigurasi/$id/selesaikan");
        $res->assertOk();
        $data = $res->json('data');
        $this->assertSame('selesai', $data['status']);
        $this->assertNotNull($data['tanggalTtdAdmin']);
        $this->assertSame($admin1->name, $data['adminNama']); // snapshot via ttv_admin? adminNama tetap kolom relasi
        $this->assertSame('disetujui', $data['riwayat'][2]['aksi']); // riwayat ditambahkan ulang
    }

    public function test_kembalikan_wajib_catatan_dan_siklus_ajukan_ulang(): void
    {
        [$id, $user] = $this->buatSiapAjukan();
        $admin = $this->buatAdmin('Admin Tinjauan');

        $this->postJson("/api/smki/pemantauan-konfigurasi/$id/ajukan", [
            'adminId' => $admin->id,
            'catatanTindakLanjut' => 'Dikerjakan segera.',
        ])->assertOk();

        $this->actingAs($admin, 'sanctum');

        // Catatan kosong → 422
        $res = $this->postJson("/api/smki/pemantauan-konfigurasi/$id/kembalikan", ['catatan' => '']);
        $res->assertStatus(422);

        // Kembalikan dengan catatan
        $res = $this->postJson("/api/smki/pemantauan-konfigurasi/$id/kembalikan", ['catatan' => 'SSH Root Login masih enabled.']);
        $res->assertOk();
        $this->assertSame('revisi', $res->json('data.status'));
        $this->assertSame('SSH Root Login masih enabled.', $res->json('data.catatanAdmin'));
        $this->assertSame('dikembalikan', $res->json('data.riwayat')[2]['aksi']);

        // Perbaiki nilai & ajukan ulang → siklus naik 1
        $this->actingAs($user, 'sanctum'); // kembali ke Pelaksana
        $detail = $this->getJson("/api/smki/pemantauan-konfigurasi/$id")->json('data');
        $items = $detail['objekList'][0]['items'];
        foreach ($items as &$item) {
            if ($item['namaKonfigurasi'] === 'SSH Root Login') {
                $item['nilaiAktual'] = $item['nilaiStandar']; // sekarang sesuai
            }
        }
        unset($item);
        $this->putJson("/api/smki/pemantauan-konfigurasi/$id/konfigurasi", ['items' => $items])->assertOk();
        $this->postJson("/api/smki/pemantauan-konfigurasi/$id/periksa")->assertOk();
        $res = $this->postJson("/api/smki/pemantauan-konfigurasi/$id/ajukan", [
            'adminId' => $admin->id,
            'catatanTindakLanjut' => '', // tidak ada tidak_sesuai → tidak wajib
        ]);
        $res->assertOk();
        $this->assertSame('diajukan', $res->json('data.status'));
        $this->assertSame(2, $res->json('data.siklus'));
        $this->assertSame('diajukan_ulang', $res->json('data.riwayat')[3]['aksi']);
    }

    public function test_hapus_draft_hanya_pemilik_dan_hanya_draft(): void
    {
        // draft milik A
        $a = $this->login('user');
        $id = $this->postJson('/api/smki/pemantauan-konfigurasi', [])->json('data.id');

        // B tidak bisa hapus (bukan miliknya) → 404 (bukan desain untuk pelaksana lain)
        $this->login('user');
        $this->deleteJson("/api/smki/pemantauan-konfigurasi/$id")->assertNotFound();

        // A bisa hapus draft miliknya
        $this->actingAs($a, 'sanctum');
        $this->deleteJson("/api/smki/pemantauan-konfigurasi/$id")->assertOk();
        $this->getJson("/api/smki/pemantauan-konfigurasi/$id")->assertNotFound();

        // Diajukan → tidak bisa dihapus
        [$id2] = $this->buatSiapAjukan();
        $res = $this->postJson("/api/smki/pemantauan-konfigurasi/$id2/ajukan", [
            'adminId' => $this->buatAdmin()->id,
            'catatanTindakLanjut' => 'Catatan.',
        ]);
        $res->assertOk();
        $this->deleteJson("/api/smki/pemantauan-konfigurasi/$id2")->assertStatus(422);
    }
}
