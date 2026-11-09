<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Models\SmkiFr002Header;
use App\Models\SmkiFr002KategoriAset;
use App\Models\SmkiFr002KontrolAnnexIso;
use App\Models\SmkiFr002Risiko;
use App\Models\SmkiFr002RiskTreatmentPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

class SmkiFr002RiskRegisterController extends Controller
{
    /**
     * Get Lookup Data: Kategoris, Annex controls, Header, and Master Pegawai
     */
    public function lookup()
    {
        $kategoris = SmkiFr002KategoriAset::orderBy('urutan')->get();
        $annexes = SmkiFr002KontrolAnnexIso::orderBy('kode_annex')->get();
        $header = SmkiFr002Header::latest()->first();

        // Ambil data pegawai (master) untuk opsi PIC & pengesah — tanpa batas 100
        $pegawais = [];
        if (\Illuminate\Support\Facades\Schema::hasTable('pegawai')) {
            $pegawais = DB::table('pegawai')
                ->select('id', 'nama', 'nip', 'jabatan')
                ->orderBy('nama')
                ->get();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'kategoris' => $kategoris,
                'annexes' => $annexes,
                'header' => $header,
                'pegawais' => $pegawais,
            ],
        ]);
    }

    /**
     * Get List Data with Metrics, Tabs Filter, and Search
     */
    public function index(Request $request)
    {
        $query = SmkiFr002Risiko::with(['kategoriAset', 'treatmentPlan'])
            ->where('is_deleted', false);

        // Filter Kategori (A, B, C, D, E)
        if ($request->filled('kategori') && $request->kategori !== 'ALL') {
            $query->where('kode_kategori', $request->kategori);
        }

        // Search text
        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('nama_aset_proses', 'like', $s)
                    ->orWhere('ancaman_threat', 'like', $s)
                    ->orWhere('kerawanan_vulnerability', 'like', $s)
                    ->orWhere('kontrol_annex_iso', 'like', $s)
                    ->orWhere('existing_control', 'like', $s);
            });
        }

        $items = $query->orderBy('kode_kategori')->orderBy('id')->get();

        // Calculate KPI Metrics
        $allActive = SmkiFr002Risiko::where('is_deleted', false)->get();
        $totalRisiko = $allActive->count();
        $risikoTinggiEkstrim = $allActive->whereIn('inherent_kategori', ['Tinggi', 'Sangat Tinggi', 'Ekstrim'])->count();
        $risikoDapatDiterima = $allActive->where('status_risiko_current', 'Dapat Diterima')->count();
        $dataDihapus = SmkiFr002Risiko::where('is_deleted', true)->count();

        // Metrics per category
        $countPerKategori = [
            'A' => $allActive->where('kode_kategori', 'A')->count(),
            'B' => $allActive->where('kode_kategori', 'B')->count(),
            'C' => $allActive->where('kode_kategori', 'C')->count(),
            'D' => $allActive->where('kode_kategori', 'D')->count(),
            'E' => $allActive->where('kode_kategori', 'E')->count(),
        ];

        return response()->json([
            'success' => true,
            'metrics' => [
                'total_risiko' => $totalRisiko,
                'risiko_tinggi_ekstrim' => $risikoTinggiEkstrim,
                'risiko_dapat_diterima' => $risikoDapatDiterima,
                'data_dihapus' => $dataDihapus,
                'count_per_kategori' => $countPerKategori,
            ],
            'data' => $items,
        ]);
    }

    /**
     * Store new Risk Register item
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'kode_kategori' => 'required|string|exists:smki_fr002_kategori_asets,kode_kategori',
            'nama_aset_proses' => 'required|string|max:255',
            'kerawanan_vulnerability' => 'nullable|string',
            'ancaman_threat' => 'nullable|string',
            'kategori_cia' => 'nullable|string',
            'uraian_dampak' => 'nullable|string',

            // Inherent Risk
            'inherent_likelihood' => 'required|integer|min:1|max:5',
            'inherent_impact' => 'required|integer|min:1|max:5',

            // Existing Control
            'existing_control' => 'nullable|string',
            'referensi_dokumen' => 'nullable|string',
            'kode_annex' => 'nullable|string',
            'kontrol_annex_iso' => 'nullable|string',
            'current_likelihood' => 'required|integer|min:1|max:5',
            'current_impact' => 'required|integer|min:1|max:5',

            // Residual & RTP
            'residual_likelihood' => 'nullable|integer|min:1|max:5',
            'residual_impact' => 'nullable|integer|min:1|max:5',
            'opsi_penanggulangan' => 'nullable|string',
            'uraian_rencana' => 'nullable|string',
            'target_selesai' => 'nullable|date',
            'pic_penanggung_jawab' => 'nullable|string',
            'prioritas' => 'nullable|string',
            'ownership' => 'nullable|string',
            'status_monitoring' => 'nullable|string|in:Open,On Progress,Closed',
            'keterangan' => 'nullable|string',
        ]);

        $header = SmkiFr002Header::latest()->first();

        // Calculate auto scores
        $inh = SmkiFr002Risiko::kalkulasiMatriks($validated['inherent_likelihood'], $validated['inherent_impact']);
        $cur = SmkiFr002Risiko::kalkulasiMatriks($validated['current_likelihood'], $validated['current_impact']);

        $resL = $validated['residual_likelihood'] ?? 1;
        $resI = $validated['residual_impact'] ?? 1;
        $res = SmkiFr002Risiko::kalkulasiMatriks($resL, $resI);

        DB::beginTransaction();
        try {
            $risiko = SmkiFr002Risiko::create([
                'header_id' => $header ? $header->id : null,
                'kode_kategori' => $validated['kode_kategori'],
                'kode_annex' => $validated['kode_annex'] ?? null,
                'nama_aset_proses' => $validated['nama_aset_proses'],
                'kerawanan_vulnerability' => $validated['kerawanan_vulnerability'] ?? null,
                'ancaman_threat' => $validated['ancaman_threat'] ?? null,
                'kategori_cia' => $validated['kategori_cia'] ?? 'C,I,A',
                'uraian_dampak' => $validated['uraian_dampak'] ?? null,

                'inherent_likelihood' => $inh['likelihood'],
                'inherent_impact' => $inh['impact'],
                'inherent_score' => $inh['score'],
                'inherent_kategori' => $inh['kategori'],

                'existing_control' => $validated['existing_control'] ?? null,
                'referensi_dokumen' => $validated['referensi_dokumen'] ?? null,
                'kontrol_annex_iso' => $validated['kontrol_annex_iso'] ?? null,
                'current_likelihood' => $cur['likelihood'],
                'current_impact' => $cur['impact'],
                'current_score' => $cur['score'],
                'current_kategori' => $cur['kategori'],
                'status_risiko_current' => $cur['status'],

                'residual_likelihood' => $res['likelihood'],
                'residual_impact' => $res['impact'],
                'residual_score' => $res['score'],
                'residual_kategori' => $res['kategori'],
                'status_risiko_residual' => $res['status'],

                'is_deleted' => false,
                'created_by' => auth()->id(),
            ]);

            SmkiFr002RiskTreatmentPlan::create([
                'risiko_id' => $risiko->id,
                'opsi_penanggulangan' => $validated['opsi_penanggulangan'] ?? null,
                'uraian_rencana' => $validated['uraian_rencana'] ?? null,
                'target_selesai' => $validated['target_selesai'] ?? null,
                'pic_penanggung_jawab' => $validated['pic_penanggung_jawab'] ?? null,
                'prioritas' => $validated['prioritas'] ?? 'Menengah',
                'ownership' => $validated['ownership'] ?? 'Bidang Aplikasi Informatika',
                'status_monitoring' => $validated['status_monitoring'] ?? 'Open',
                'keterangan' => $validated['keterangan'] ?? null,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Data risiko berhasil disimpan',
                'data' => $risiko->load(['kategoriAset', 'treatmentPlan']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan data risiko: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Show single Risk item
     */
    public function show($id)
    {
        $risiko = SmkiFr002Risiko::with(['kategoriAset', 'treatmentPlan', 'header'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $risiko,
        ]);
    }

    /**
     * Update existing Risk Register item
     */
    public function update(Request $request, $id)
    {
        $risiko = SmkiFr002Risiko::findOrFail($id);

        $validated = $request->validate([
            'kode_kategori' => 'required|string|exists:smki_fr002_kategori_asets,kode_kategori',
            'nama_aset_proses' => 'required|string|max:255',
            'kerawanan_vulnerability' => 'nullable|string',
            'ancaman_threat' => 'nullable|string',
            'kategori_cia' => 'nullable|string',
            'uraian_dampak' => 'nullable|string',

            'inherent_likelihood' => 'required|integer|min:1|max:5',
            'inherent_impact' => 'required|integer|min:1|max:5',

            'existing_control' => 'nullable|string',
            'referensi_dokumen' => 'nullable|string',
            'kode_annex' => 'nullable|string',
            'kontrol_annex_iso' => 'nullable|string',
            'current_likelihood' => 'required|integer|min:1|max:5',
            'current_impact' => 'required|integer|min:1|max:5',

            'residual_likelihood' => 'nullable|integer|min:1|max:5',
            'residual_impact' => 'nullable|integer|min:1|max:5',
            'opsi_penanggulangan' => 'nullable|string',
            'uraian_rencana' => 'nullable|string',
            'target_selesai' => 'nullable|date',
            'pic_penanggung_jawab' => 'nullable|string',
            'prioritas' => 'nullable|string',
            'ownership' => 'nullable|string',
            'status_monitoring' => 'nullable|string|in:Open,On Progress,Closed',
            'keterangan' => 'nullable|string',
        ]);

        $inh = SmkiFr002Risiko::kalkulasiMatriks($validated['inherent_likelihood'], $validated['inherent_impact']);
        $cur = SmkiFr002Risiko::kalkulasiMatriks($validated['current_likelihood'], $validated['current_impact']);
        $resL = $validated['residual_likelihood'] ?? $risiko->residual_likelihood;
        $resI = $validated['residual_impact'] ?? $risiko->residual_impact;
        $res = SmkiFr002Risiko::kalkulasiMatriks($resL, $resI);

        DB::beginTransaction();
        try {
            $risiko->update([
                'kode_kategori' => $validated['kode_kategori'],
                'kode_annex' => $validated['kode_annex'] ?? $risiko->kode_annex,
                'nama_aset_proses' => $validated['nama_aset_proses'],
                'kerawanan_vulnerability' => $validated['kerawanan_vulnerability'] ?? null,
                'ancaman_threat' => $validated['ancaman_threat'] ?? null,
                'kategori_cia' => $validated['kategori_cia'] ?? $risiko->kategori_cia,
                'uraian_dampak' => $validated['uraian_dampak'] ?? null,

                'inherent_likelihood' => $inh['likelihood'],
                'inherent_impact' => $inh['impact'],
                'inherent_score' => $inh['score'],
                'inherent_kategori' => $inh['kategori'],

                'existing_control' => $validated['existing_control'] ?? null,
                'referensi_dokumen' => $validated['referensi_dokumen'] ?? null,
                'kontrol_annex_iso' => $validated['kontrol_annex_iso'] ?? null,
                'current_likelihood' => $cur['likelihood'],
                'current_impact' => $cur['impact'],
                'current_score' => $cur['score'],
                'current_kategori' => $cur['kategori'],
                'status_risiko_current' => $cur['status'],

                'residual_likelihood' => $res['likelihood'],
                'residual_impact' => $res['impact'],
                'residual_score' => $res['score'],
                'residual_kategori' => $res['kategori'],
                'status_risiko_residual' => $res['status'],
            ]);

            SmkiFr002RiskTreatmentPlan::updateOrCreate(
                ['risiko_id' => $risiko->id],
                [
                    'opsi_penanggulangan' => $validated['opsi_penanggulangan'] ?? null,
                    'uraian_rencana' => $validated['uraian_rencana'] ?? null,
                    'target_selesai' => $validated['target_selesai'] ?? null,
                    'pic_penanggung_jawab' => $validated['pic_penanggung_jawab'] ?? null,
                    'prioritas' => $validated['prioritas'] ?? 'Menengah',
                    'ownership' => $validated['ownership'] ?? 'Bidang Aplikasi Informatika',
                    'status_monitoring' => $validated['status_monitoring'] ?? 'Open',
                    'keterangan' => $validated['keterangan'] ?? null,
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Data risiko berhasil diperbarui',
                'data' => $risiko->load(['kategoriAset', 'treatmentPlan']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui data risiko: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Soft delete a Risk item
     */
    public function destroy($id)
    {
        $risiko = SmkiFr002Risiko::findOrFail($id);
        $risiko->update(['is_deleted' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Data risiko berhasil dipindahkan ke tempat sampah (soft delete)',
        ]);
    }

    /**
     * Update Document Header (Scope, Revisi, Tanggal, Pengesah)
     */
    public function updateHeader(Request $request)
    {
        $validated = $request->validate([
            'tahun_periode' => 'required|integer',
            'ruang_lingkup' => 'required|string|max:150',
            'no_revisi' => 'required|string|max:50',
            'tanggal_dokumen' => 'nullable|date',
            'klasifikasi' => 'required|string|max:50',
            'jabatan_pengesah' => 'required|string|max:100',
            'nama_pengesah' => 'required|string|max:150',
            'nip_pengesah' => 'nullable|string|max:50',
        ]);

        $header = SmkiFr002Header::latest()->first();
        if ($header) {
            $header->update($validated);
        } else {
            $header = SmkiFr002Header::create($validated);
        }

        return response()->json([
            'success' => true,
            'message' => 'Header dokumen FR-002 berhasil diperbarui',
            'data' => $header,
        ]);
    }

    /**
     * Export to Excel (.xlsx) matching exact FR-002 format
     */
    public function exportExcel()
    {
        $header = SmkiFr002Header::latest()->first();
        $risikos = SmkiFr002Risiko::with(['kategoriAset', 'treatmentPlan'])
            ->where('is_deleted', false)
            ->orderBy('kode_kategori')
            ->orderBy('id')
            ->get();

        $templatePath = resource_path('templates/FR-002 Risk Register (FORMAT RISK REGISTER 2023).xlsx');
        if (!is_file($templatePath)) {
            $templatePath = base_path('../FR-002 Risk Register (FORMAT RISK REGISTER 2023).xlsx');
            if (!is_file($templatePath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Template resmi FR-002 tidak ditemukan di resources/templates.',
                ], 404);
            }
        }

        $spreadsheet = IOFactory::createReader('Xlsx')->load($templatePath);
        $sheet = $spreadsheet->getSheetByName('Final Risk Register 2023')
            ?? $spreadsheet->getActiveSheet();

        // 1. Metadata dokumen (judul C1 + Y1:Z4)
        $tahun = $header ? $header->tahun_periode : date('Y');
        $sheet->setCellValue('C1', "DAFTAR RISIKO (RISK REGISTER) \nDINAS KOMUNIKASI DAN INFORMATIKA PROVINSI JAWA BARAT \nTAHUN " . $tahun);
        $sheet->getStyle('C1')->getFont()->setName('Arial')->setSize(18)->setBold(true);
        $sheet->getStyle('C1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

        $sheet->setCellValue('Z1', ': ' . ($header->ruang_lingkup ?? 'Fisik Data Center'));
        $sheet->setCellValue('Z2', ': ' . ($header->no_revisi ?? '4.0'));
        $tanggalDok = ($header && $header->tanggal_dokumen)
            ? ($header->tanggal_dokumen instanceof \DateTimeInterface ? $header->tanggal_dokumen->format('d/m/Y') : date('d/m/Y', strtotime((string)$header->tanggal_dokumen)))
            : date('d/m/Y');
        $sheet->setCellValue('Z3', ': ' . $tanggalDok);
        $sheet->setCellValue('Z4', ': ' . ($header->klasifikasi ?? 'Internal'));

        // 2. Unmerge any existing merged cells from row 10 and below (so previous sample rows don't interfere)
        $merges = $sheet->getMergeCells();
        foreach ($merges as $m) {
            if (preg_match('/[A-Z]+(\d+):[A-Z]+(\d+)/', $m, $matches)) {
                $topRow = (int)$matches[1];
                if ($topRow >= 10) {
                    $sheet->unmergeCells($m);
                }
            }
        }

        // 3. Clear content of old sample rows from row 10 up to highest row
        $initialHighest = max($sheet->getHighestRow(), 60);
        for ($r = 10; $r <= $initialHighest; $r++) {
            for ($c = 1; $c <= 32; $c++) {
                $col = Coordinate::stringFromColumnIndex($c);
                $sheet->setCellValue($col . $r, null);
                $sheet->getStyle($col . $r)->getFill()->setFillType(Fill::FILL_NONE);
            }
            $sheet->getRowDimension($r)->setRowHeight(-1);
        }

        // 4. Categories list
        $kategoris = SmkiFr002KategoriAset::orderBy('urutan')->get();
        if ($kategoris->isEmpty()) {
            $kategoris = collect([
                (object)['kode_kategori' => 'A', 'nama_kategori_aset' => 'INFORMASI  ( Cetak/Elektronik )'],
                (object)['kode_kategori' => 'B', 'nama_kategori_aset' => 'SOFTWARE (Aplikasi, S/W sistem, development tools)'],
                (object)['kode_kategori' => 'C', 'nama_kategori_aset' => 'INFRASTRUKTUR HARDWARE DAN NETWORK'],
                (object)['kode_kategori' => 'D', 'nama_kategori_aset' => 'FASILITAS DAN SARANA PENDUKUNG (UTILITIES)'],
                (object)['kode_kategori' => 'E', 'nama_kategori_aset' => 'SUMBER DAYA MANUSIA DAN PIHAK KETIGA (PERSONNEL)'],
            ]);
        }

        $grouped = $risikos->groupBy('kode_kategori');
        $currentRow = 10;
        $itemNo = 1;

        // Styling helper closures
        $applyRiskStyling = function ($sheet, $scoreCell, $catCell, $score) {
            if ($score >= 25) {
                $fill = '8B0504';
                $fontColor = 'FFFFFF';
            } elseif ($score >= 16) {
                $fill = 'C00000';
                $fontColor = 'FFFFFF';
            } elseif ($score >= 12) {
                $fill = 'FF6600';
                $fontColor = 'FFFFFF';
            } elseif ($score >= 5) {
                $fill = 'FFFF00';
                $fontColor = '000000';
            } else {
                $fill = '92D050';
                $fontColor = '000000';
            }

            foreach ([$scoreCell, $catCell] as $cell) {
                $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
                $sheet->getStyle($cell)->getFont()->getColor()->setRGB($fontColor);
                $sheet->getStyle($cell)->getFont()->setBold(true);
                $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            }
        };

        $applyStatusStyling = function ($sheet, $cell, $score) {
            if ($score >= 25) {
                $fill = '8B0504';
                $fontColor = 'FFFFFF';
            } elseif ($score >= 16) {
                $fill = 'C00000';
                $fontColor = 'FFFFFF';
            } elseif ($score >= 12) {
                $fill = 'FF6600';
                $fontColor = 'FFFFFF';
            } elseif ($score >= 5) {
                $fill = 'FFFF00';
                $fontColor = '000000';
            } else {
                $fill = '92D050';
                $fontColor = '000000';
            }

            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
            $sheet->getStyle($cell)->getFont()->getColor()->setRGB($fontColor);
            $sheet->getStyle($cell)->getFont()->setBold(true);
            $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        };

        $applyMonitoringStyling = function ($sheet, $cell, $status) {
            if ($status === 'Closed') {
                $fill = 'E2EFDA';
                $fontColor = '375623';
            } elseif ($status === 'On Progress') {
                $fill = 'FFF2CC';
                $fontColor = 'B25900';
            } else {
                $fill = 'FCE4D6';
                $fontColor = 'C65911';
            }

            $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($fill);
            $sheet->getStyle($cell)->getFont()->getColor()->setRGB($fontColor);
            $sheet->getStyle($cell)->getFont()->setBold(true);
            $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        };

        $likelihoodValidation = function ($sheet, $cell) {
            $dv = $sheet->getCell($cell)->getDataValidation();
            $dv->setType(DataValidation::TYPE_LIST);
            $dv->setErrorStyle(DataValidation::STYLE_STOP);
            $dv->setAllowBlank(true);
            $dv->setShowInputMessage(false);
            $dv->setShowErrorMessage(true);
            $dv->setShowDropDown(false);
            $dv->setErrorTitle('Nilai tidak valid');
            $dv->setError('Pilih nilai 1 sampai 5.');
            $dv->setFormula1('"1,2,3,4,5"');
        };

        // Data Population: Categories and Rows
        foreach ($kategoris as $kat) {
            $itemsInCat = $grouped->get($kat->kode_kategori, collect());

            // Category Header Row
            $sheet->getRowDimension($currentRow)->setRowHeight(22);
            $sheet->setCellValue('A' . $currentRow, $kat->kode_kategori);
            $sheet->setCellValue('B' . $currentRow, $kat->nama_kategori_aset);

            $sheet->getStyle('A' . $currentRow . ':AE' . $currentRow)->getFont()->setName('Arial')->setSize(10)->setBold(true);
            $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle('B' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle('A' . $currentRow . ':AE' . $currentRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EDE7F6');
            $sheet->getStyle('A' . $currentRow . ':AE' . $currentRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $currentRow++;

            // Data rows under this category
            foreach ($itemsInCat as $item) {
                $rtp = $item->treatmentPlan;

                $sheet->getRowDimension($currentRow)->setRowHeight(-1);

                $sheet->setCellValue('A' . $currentRow, $itemNo++);
                $sheet->setCellValue('B' . $currentRow, $item->nama_aset_proses);
                $sheet->setCellValue('C' . $currentRow, $item->kerawanan_vulnerability);
                $sheet->setCellValue('D' . $currentRow, $item->ancaman_threat);
                $sheet->setCellValue('E' . $currentRow, $item->kategori_cia);
                $sheet->setCellValue('F' . $currentRow, $item->uraian_dampak);

                // Inherent Risk
                $iL = (int)($item->inherent_likelihood ?: 1);
                $iI = (int)($item->inherent_impact ?: 1);
                $iScore = $iL * $iI;
                $sheet->setCellValue('G' . $currentRow, $iL);
                $sheet->setCellValue('H' . $currentRow, $iI);
                $sheet->setCellValue('I' . $currentRow, "=(G{$currentRow}*H{$currentRow})");
                $sheet->setCellValue('J' . $currentRow, "=IF(I{$currentRow}>=25,\"Ekstrim\",IF(I{$currentRow}>=16,\"Sangat Tinggi\",IF(I{$currentRow}>=12,\"Tinggi\",IF(I{$currentRow}>=5,\"Menengah\",\"Rendah\"))))");
                $applyRiskStyling($sheet, 'I' . $currentRow, 'J' . $currentRow, $iScore);

                // Existing Control
                $sheet->setCellValue('K' . $currentRow, $item->existing_control);
                $sheet->setCellValue('L' . $currentRow, $item->referensi_dokumen);
                $sheet->setCellValue('M' . $currentRow, $item->kontrol_annex_iso);

                // Current Risk
                $cL = (int)($item->current_likelihood ?: 1);
                $cI = (int)($item->current_impact ?: 1);
                $cScore = $cL * $cI;
                $sheet->setCellValue('N' . $currentRow, $cL);
                $sheet->setCellValue('O' . $currentRow, $cI);
                $sheet->setCellValue('P' . $currentRow, "=(N{$currentRow}*O{$currentRow})");
                $sheet->setCellValue('Q' . $currentRow, "=IF(P{$currentRow}>=25,\"Ekstrim\",IF(P{$currentRow}>=16,\"Sangat Tinggi\",IF(P{$currentRow}>=12,\"Tinggi\",IF(P{$currentRow}>=5,\"Menengah\",\"Rendah\"))))");
                $sheet->setCellValue('R' . $currentRow, "=IF(P{$currentRow}>=25,\"Kontrol/Transfer/Void\",IF(P{$currentRow}>=16,\"Kontrol/Transfer/Void\",IF(P{$currentRow}>=12,\"Kontrol/Transfer/Void\",IF(P{$currentRow}>=5,\"Kontrol/Transfer/Void\",IF(P{$currentRow}>=1,\"Dapat Diterima\")))))");
                $applyRiskStyling($sheet, 'P' . $currentRow, 'Q' . $currentRow, $cScore);
                $applyStatusStyling($sheet, 'R' . $currentRow, $cScore);

                // Risk Treatment Plan
                $sheet->setCellValue('S' . $currentRow, $rtp ? $rtp->opsi_penanggulangan : '-');

                // Residual Risk
                $rL = (int)($item->residual_likelihood ?: 1);
                $rI = (int)($item->residual_impact ?: 1);
                $rScore = $rL * $rI;
                $sheet->setCellValue('T' . $currentRow, $rL);
                $sheet->setCellValue('U' . $currentRow, $rI);
                $sheet->setCellValue('V' . $currentRow, "=(T{$currentRow}*U{$currentRow})");
                $sheet->setCellValue('W' . $currentRow, "=IF(V{$currentRow}>=25,\"Ekstrim\",IF(V{$currentRow}>=16,\"Sangat Tinggi\",IF(V{$currentRow}>=12,\"Tinggi\",IF(V{$currentRow}>=5,\"Menengah\",\"Rendah\"))))");
                $sheet->setCellValue('X' . $currentRow, "=IF(V{$currentRow}>=25,\"Kontrol/Transfer/Void\",IF(V{$currentRow}>=16,\"Kontrol/Transfer/Void\",IF(V{$currentRow}>=12,\"Kontrol/Transfer/Void\",IF(V{$currentRow}>=5,\"Kontrol/Transfer/Void\",IF(V{$currentRow}>=1,\"Dapat Diterima\")))))");
                $applyRiskStyling($sheet, 'V' . $currentRow, 'W' . $currentRow, $rScore);
                $applyStatusStyling($sheet, 'X' . $currentRow, $rScore);

                // RTP Details
                $sheet->setCellValue('Y' . $currentRow, $rtp ? $rtp->uraian_rencana : '-');
                $targetStr = '-';
                if ($rtp && $rtp->target_selesai) {
                    $targetStr = $rtp->target_selesai instanceof \DateTimeInterface
                        ? $rtp->target_selesai->format('d/m/Y')
                        : date('d/m/Y', strtotime((string)$rtp->target_selesai));
                }
                $sheet->setCellValue('Z' . $currentRow, $targetStr);
                $sheet->setCellValue('AA' . $currentRow, $rtp ? $rtp->pic_penanggung_jawab : '-');
                $sheet->setCellValue('AB' . $currentRow, $rtp ? $rtp->prioritas : '-');
                $sheet->setCellValue('AC' . $currentRow, $rtp ? $rtp->ownership : '-');

                $monStatus = $rtp ? ($rtp->status_monitoring ?: 'Open') : 'Open';
                $sheet->setCellValue('AD' . $currentRow, $monStatus);
                $applyMonitoringStyling($sheet, 'AD' . $currentRow, $monStatus);

                $sheet->setCellValue('AE' . $currentRow, $rtp ? $rtp->keterangan : '-');

                // Font & Borders
                $sheet->getStyle('A' . $currentRow . ':AE' . $currentRow)->getFont()->setName('Arial')->setSize(10);
                $sheet->getStyle('A' . $currentRow . ':AE' . $currentRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                $sheet->getStyle('A' . $currentRow . ':AE' . $currentRow)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

                // Alignments
                $sheet->getStyle('A' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('E' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('G' . $currentRow . ':H' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('N' . $currentRow . ':O' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('S' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('T' . $currentRow . ':U' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('Z' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('AB' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

                foreach (['G', 'H', 'N', 'O', 'T', 'U'] as $vc) {
                    $likelihoodValidation($sheet, $vc . $currentRow);
                }

                $currentRow++;
            }
        }

        // 5. Footer: Keterangan CIA
        $currentRow += 1;
        $sheet->setCellValue('B' . $currentRow, 'Keterangan :');
        $sheet->getStyle('B' . $currentRow)->getFont()->setName('Arial')->setSize(10)->setBold(true);

        $sheet->setCellValue('C' . $currentRow, 'C= Confidentiality (Kerahasiaan)');
        $sheet->mergeCells("C{$currentRow}:D{$currentRow}");
        $sheet->getStyle('C' . $currentRow)->getFont()->setName('Arial')->setSize(10);
        $currentRow++;

        $sheet->setCellValue('C' . $currentRow, 'I=Integrity (Keutuhan)');
        $sheet->getStyle('C' . $currentRow)->getFont()->setName('Arial')->setSize(10);
        $currentRow++;

        $sheet->setCellValue('C' . $currentRow, 'A=Availability (Ketersediaan)');
        $sheet->getStyle('C' . $currentRow)->getFont()->setName('Arial')->setSize(10);
        $currentRow += 2;

        // 6. Footer: Blok Tanda Tangan Pengesahan (Persis Format Template Asli)
        $signHeaderRow = $currentRow;
        $sheet->getRowDimension($signHeaderRow)->setRowHeight(24);

        $sheet->setCellValue('B' . $signHeaderRow, 'Ket');
        $sheet->setCellValue('C' . $signHeaderRow, 'Jabatan');
        $sheet->setCellValue('D' . $signHeaderRow, 'Nama');
        $sheet->mergeCells("D{$signHeaderRow}:E{$signHeaderRow}");
        $sheet->setCellValue('F' . $signHeaderRow, 'Tandatangan');
        $sheet->mergeCells("F{$signHeaderRow}:G{$signHeaderRow}");
        $sheet->setCellValue('H' . $signHeaderRow, 'Tanggal');

        $sheet->getStyle("B{$signHeaderRow}:H{$signHeaderRow}")->getFont()->setName('Arial')->setSize(10)->setBold(true);
        $sheet->getStyle("B{$signHeaderRow}:H{$signHeaderRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("B{$signHeaderRow}:H{$signHeaderRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("B{$signHeaderRow}:H{$signHeaderRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');

        $signDataRow = $signHeaderRow + 1;
        $sheet->getRowDimension($signDataRow)->setRowHeight(88.5);

        $sheet->setCellValue('B' . $signDataRow, 'Disiapkan oleh');
        $sheet->setCellValue('C' . $signDataRow, $header->jabatan_pengesah ?? 'Kepala Bidang Aplikasi Informatika');
        $sheet->setCellValue('D' . $signDataRow, $header->nama_pengesah ?? 'Nama Kepala Bidang');
        $sheet->mergeCells("D{$signDataRow}:E{$signDataRow}");
        $sheet->mergeCells("F{$signDataRow}:G{$signDataRow}");
        $sheet->setCellValue('H' . $signDataRow, $tanggalDok);

        $sheet->getStyle("B{$signDataRow}:H{$signDataRow}")->getFont()->setName('Arial')->setSize(10)->setBold(true);
        $sheet->getStyle("B{$signDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("C{$signDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle("D{$signDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle("H{$signDataRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("B{$signDataRow}:H{$signDataRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Spesimen TTE pengesah di kolom Tandatangan
        try {
            $ttePath = app(\App\Services\TteResolverService::class)
                ->imagePath((string) ($header->nip_pengesah ?? ''), (string) ($header->nama_pengesah ?? ''), false);
            if ($ttePath && is_file($ttePath)) {
                $drawing = new Drawing();
                $drawing->setPath($ttePath);
                $drawing->setHeight(65);
                $drawing->setCoordinates('F' . $signDataRow);
                $drawing->setOffsetX(15);
                $drawing->setOffsetY(10);
                $drawing->setWorksheet($sheet);
            }
        } catch (\Throwable $e) {
            // Biarkan sel tanda tangan apa adanya bila spesimen gagal dibuat
        }

        // 7. Bersihkan sisa baris kosong di bawah tanda tangan
        $finalHighest = $sheet->getHighestRow();
        if ($finalHighest > $signDataRow + 1) {
            $sheet->removeRow($signDataRow + 2, $finalHighest - ($signDataRow + 1));
        }

        $fileName = 'FR-002_Risk_Register_' . date('Ymd_His') . '.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Export to PDF (.pdf) matching FR-002 layout, A3 landscape.
     */
    public function exportPdf()
    {
        $header = SmkiFr002Header::latest()->first();
        $risikos = SmkiFr002Risiko::with(['kategoriAset', 'treatmentPlan'])
            ->where('is_deleted', false)
            ->orderBy('kode_kategori')
            ->orderBy('id')
            ->get();

        $grouped = $risikos->groupBy('kode_kategori');
        $kategoris = SmkiFr002KategoriAset::orderBy('urutan')->get();
        $groups = [];
        foreach ($kategoris as $kat) {
            $items = $grouped->get($kat->kode_kategori, collect());
            if ($items->isNotEmpty()) {
                $groups[] = ['kategori' => $kat, 'items' => $items];
            }
        }

        // Spesimen TTE pengesah (tanpa QR, seperti FR-019), embed base64
        $specimen = null;
        try {
            $ttePath = app(\App\Services\TteResolverService::class)
                ->imagePath((string) ($header->nip_pengesah ?? ''), (string) ($header->nama_pengesah ?? ''), false);
            if ($ttePath && is_file($ttePath)) {
                $specimen = base64_encode((string) file_get_contents($ttePath));
            }
        } catch (\Throwable $e) {
            $specimen = null;
        }

        $tahun = $header ? $header->tahun_periode : date('Y');
        $tanggalDok = $header && $header->tanggal_dokumen
            ? $header->tanggal_dokumen->format('d/m/Y')
            : date('d/m/Y');

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('smki.fr002-pdf', [
            'header' => $header,
            'groups' => $groups,
            'tahun' => $tahun,
            'tanggalDok' => $tanggalDok,
            'specimen' => $specimen,
        ])->setPaper('a3', 'landscape');

        return $pdf->download('FR-002_Risk_Register_' . $tahun . '_' . date('Ymd_His') . '.pdf');
    }
}
