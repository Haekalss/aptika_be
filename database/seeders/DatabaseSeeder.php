<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed Master Data
        $this->call([
            ServiceTypeSeeder::class,
            GeneralOpdSeeder::class,
            GeneralInstitutionCategorySeeder::class,
            DocumentTypeSeeder::class,
            RegencySeeder::class,
            RekeningSeeder::class,
            PegawaiSeeder::class,
        ]);

        // Seed Multi-Bidang & Service
        $this->call([
            BidangSeeder::class,
            ServiceSeeder::class,
            BidangServiceSeeder::class,
        ]);

        // Seed Multi-Bidang Users (1 Admin & 2 User untuk setiap 7 Bidang)
        $this->call([
            MultiBidangUserSeeder::class,
        ]);

        // Seed Project Data
        $this->call([
            ProjectDataSeeder::class,
            BeritaAcaraSeeder::class,
        ]);

        // Seed Aset TI & SMKI
        $this->call([
            AsetTiMasterSeeder::class,
            DaftarAsetTiSeeder::class,
            SmkiSoftwareStandarSeeder::class,
            SmkiFormulirHardeningSeeder::class,
        ]);

        // Seed SMKI Rencana Audit (master: bidang_auditee, lokasi_auditee, auditee, auditor)
        $this->call([
            RencanaAuditMasterSeeder::class,
        ]);

        // Seed SMKI Laporan Audit Internal (FR-006) - data dummy contoh
        $this->call([
            SmkiLaporanAuditSeeder::class,
        ]);

        if (app()->environment('local', 'testing')) {
            $this->call(SmkiTindakanPerbaikanSeeder::class);
        }
    }
}
