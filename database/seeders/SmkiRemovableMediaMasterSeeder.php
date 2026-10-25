<?php

namespace Database\Seeders;

use App\Models\AsetTiNama;
use Illuminate\Database\Seeder;

class SmkiRemovableMediaMasterSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Flashdisk', 'Hard disk eksternal', 'SSD eksternal', 'Kartu memori', 'CD', 'DVD'] as $name) {
            AsetTiNama::firstOrCreate(['nama_aset' => $name]);
        }
    }
}
