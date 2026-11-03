<?php

namespace App\Http\Controllers\Smki;

use App\Models\SmkiFormulirAsetInformasi;
use App\Models\SmkiAsetInformasiUnitKerja;
use App\Models\SmkiAsetInformasiIntangible;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;

class SmkiAsetInformasiController extends Controller
{
    /**
     * Menampilkan daftar dokumen formulir Aset Informasi (FR-028)
     */
    public function index(Request $request)
    {
        $search = trim($request->input('search', ''));
        $perPage = (int) $request->input('per_page', 10);

        $query = SmkiFormulirAsetInformasi::with([
            'user:id,name,email',
        ])
        ->withCount(['unitKerjaDetails', 'intangibleDetails'])
        ->orderBy('id', 'desc');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('no_formulir', 'like', "%{$search}%")
                  ->orWhere('bidang_nama', 'like', "%{$search}%")
                  ->orWhere('klasifikasi_dokumen', 'like', "%{$search}%")
                  ->orWhereHas('unitKerjaDetails', function ($sub) use ($search) {
                      $sub->where('nama_aset', 'like', "%{$search}%")
                          ->orWhere('kode_aset', 'like', "%{$search}%");
                  })
                  ->orWhereHas('intangibleDetails', function ($sub) use ($search) {
                      $sub->where('nama_aset', 'like', "%{$search}%")
                          ->orWhere('layanan', 'like', "%{$search}%");
                  });
            });
        }

        $formulirs = $query->paginate($perPage);

        // Statistik ringkas untuk Card KPI (hanya hitung dari formulir yang aktif / belum dihapus)
        $stats = [
            'total_dokumen' => SmkiFormulirAsetInformasi::count(),
            'total_unit_kerja' => SmkiAsetInformasiUnitKerja::whereHas('formulir')->count(),
            'total_intangible' => SmkiAsetInformasiIntangible::whereHas('formulir')->count(),
            'total_rahasia' => SmkiAsetInformasiUnitKerja::whereHas('formulir')->where('klasifikasi', 'Rahasia')->count(),
        ];

        return response()->json([
            'status' => 'success',
            'data' => $formulirs->items(),
            'meta' => [
                'current_page' => $formulirs->currentPage(),
                'last_page' => $formulirs->lastPage(),
                'per_page' => $formulirs->perPage(),
                'total' => $formulirs->total(),
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * Menyimpan dokumen baru beserta detail Tab 1 & Tab 2
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'no_formulir' => 'required|string|max:100',
            'revisi' => 'required|string|max:20',
            'tanggal' => 'required|date',
            'bidang_nama' => 'nullable|string|max:100',
            'klasifikasi_dokumen' => 'nullable|string|max:100',
            'unit_kerja_details' => 'nullable|array',
            'unit_kerja_details.*.nama_aset' => 'required_with:unit_kerja_details|string',
            'intangible_details' => 'nullable|array',
            'intangible_details.*.nama_aset' => 'required_with:intangible_details|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $user = auth()->user();

            $formulir = SmkiFormulirAsetInformasi::create([
                'no_formulir' => $request->input('no_formulir', 'FR-028-SMKI'),
                'revisi' => $request->input('revisi', '0'),
                'tanggal' => $request->input('tanggal'),
                'bidang_nama' => $request->input('bidang_nama', 'Bidang APTIKA'),
                'klasifikasi_dokumen' => $request->input('klasifikasi_dokumen', 'Dokumen Terbatas'),
                'bidang_id' => $user?->bidang_id ?? 3,
                'user_id' => $user?->id,
                'catatan' => $request->input('catatan'),
            ]);

            // Simpan Tab 1: Unit Kerja Details
            if ($request->has('unit_kerja_details')) {
                foreach ($request->input('unit_kerja_details') as $index => $row) {
                    if (empty(trim($row['nama_aset'] ?? ''))) continue;

                    SmkiAsetInformasiUnitKerja::create([
                        'formulir_id' => $formulir->id,
                        'no_urut' => $index + 1,
                        'kode_aset' => $row['kode_aset'] ?? null,
                        'nama_aset' => $row['nama_aset'],
                        'klasifikasi' => $row['klasifikasi'] ?? 'Internal',
                        'tahun_pembuatan' => $row['tahun_pembuatan'] ?? null,
                        'tahun_update' => $row['tahun_update'] ?? null,
                        'deskripsi' => $row['deskripsi'] ?? null,
                        'format_penyimpanan' => $row['format_penyimpanan'] ?? 'Softcopy',
                        'masa_simpan' => $row['masa_simpan'] ?? null,
                        'lokasi_penyimpanan' => $row['lokasi_penyimpanan'] ?? null,
                        'pemilik_aset' => $row['pemilik_aset'] ?? null,
                        'keterangan' => $row['keterangan'] ?? null,
                    ]);
                }
            }

            // Simpan Tab 2: Intangible Details
            if ($request->has('intangible_details')) {
                foreach ($request->input('intangible_details') as $index => $row) {
                    if (empty(trim($row['nama_aset'] ?? ''))) continue;

                    $c = (int) ($row['kerahasiaan'] ?? 1);
                    $i = (int) ($row['integritas'] ?? 1);
                    $a = (int) ($row['ketersediaan'] ?? 1);
                    $nilai = max($c, $i, $a);
                    $keterangan = $nilai >= 3 ? 'Tinggi' : ($nilai === 2 ? 'Sedang' : 'Rendah');

                    SmkiAsetInformasiIntangible::create([
                        'formulir_id' => $formulir->id,
                        'no_urut' => $index + 1,
                        'kode_aset' => $row['kode_aset'] ?? ('INT-' . str_pad($index + 1, 3, '0', STR_PAD_LEFT)),
                        'layanan' => $row['layanan'] ?? null,
                        'nama_aset' => $row['nama_aset'],
                        'sub_klasifikasi' => $row['sub_klasifikasi'] ?? null,
                        'pemilik_aset' => $row['pemilik_aset'] ?? null,
                        'kerahasiaan' => $c,
                        'integritas' => $i,
                        'ketersediaan' => $a,
                        'nilai' => $nilai,
                        'keterangan' => $row['keterangan'] ?? $keterangan,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Dokumen Formulir Aset Informasi FR-028 berhasil disimpan',
                'data' => $formulir->load(['unitKerjaDetails', 'intangibleDetails']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal menyimpan dokumen: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Menampilkan detail dokumen beserta seluruh rincian Tab 1 & Tab 2
     */
    public function show($id)
    {
        $formulir = SmkiFormulirAsetInformasi::with([
            'user:id,name,email',
            'unitKerjaDetails',
            'intangibleDetails',
        ])->find($id);

        if (!$formulir) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dokumen tidak ditemukan',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $formulir,
        ]);
    }

    /**
     * Memperbarui dokumen formulir aset
     */
    public function update(Request $request, $id)
    {
        $formulir = SmkiFormulirAsetInformasi::find($id);

        if (!$formulir) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dokumen tidak ditemukan',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'no_formulir' => 'required|string|max:100',
            'revisi' => 'required|string|max:20',
            'tanggal' => 'required|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            $formulir->update([
                'no_formulir' => $request->input('no_formulir', $formulir->no_formulir),
                'revisi' => $request->input('revisi', $formulir->revisi),
                'tanggal' => $request->input('tanggal', $formulir->tanggal),
                'bidang_nama' => $request->input('bidang_nama', $formulir->bidang_nama),
                'klasifikasi_dokumen' => $request->input('klasifikasi_dokumen', $formulir->klasifikasi_dokumen),
                'catatan' => $request->input('catatan', $formulir->catatan),
            ]);

            // Re-sync Tab 1 jika dikirim
            if ($request->has('unit_kerja_details')) {
                SmkiAsetInformasiUnitKerja::where('formulir_id', $formulir->id)->delete();
                foreach ($request->input('unit_kerja_details') as $index => $row) {
                    if (empty(trim($row['nama_aset'] ?? ''))) continue;

                    SmkiAsetInformasiUnitKerja::create([
                        'formulir_id' => $formulir->id,
                        'no_urut' => $index + 1,
                        'kode_aset' => $row['kode_aset'] ?? null,
                        'nama_aset' => $row['nama_aset'],
                        'klasifikasi' => $row['klasifikasi'] ?? 'Internal',
                        'tahun_pembuatan' => $row['tahun_pembuatan'] ?? null,
                        'tahun_update' => $row['tahun_update'] ?? null,
                        'deskripsi' => $row['deskripsi'] ?? null,
                        'format_penyimpanan' => $row['format_penyimpanan'] ?? 'Softcopy',
                        'masa_simpan' => $row['masa_simpan'] ?? null,
                        'lokasi_penyimpanan' => $row['lokasi_penyimpanan'] ?? null,
                        'pemilik_aset' => $row['pemilik_aset'] ?? null,
                        'keterangan' => $row['keterangan'] ?? null,
                    ]);
                }
            }

            // Re-sync Tab 2 jika dikirim
            if ($request->has('intangible_details')) {
                SmkiAsetInformasiIntangible::where('formulir_id', $formulir->id)->delete();
                foreach ($request->input('intangible_details') as $index => $row) {
                    if (empty(trim($row['nama_aset'] ?? ''))) continue;

                    $c = (int) ($row['kerahasiaan'] ?? 1);
                    $i = (int) ($row['integritas'] ?? 1);
                    $a = (int) ($row['ketersediaan'] ?? 1);
                    $nilai = max($c, $i, $a);
                    $keterangan = $nilai >= 3 ? 'Tinggi' : ($nilai === 2 ? 'Sedang' : 'Rendah');

                    SmkiAsetInformasiIntangible::create([
                        'formulir_id' => $formulir->id,
                        'no_urut' => $index + 1,
                        'kode_aset' => $row['kode_aset'] ?? ('INT-' . str_pad($index + 1, 3, '0', STR_PAD_LEFT)),
                        'layanan' => $row['layanan'] ?? null,
                        'nama_aset' => $row['nama_aset'],
                        'sub_klasifikasi' => $row['sub_klasifikasi'] ?? null,
                        'pemilik_aset' => $row['pemilik_aset'] ?? null,
                        'kerahasiaan' => $c,
                        'integritas' => $i,
                        'ketersediaan' => $a,
                        'nilai' => $nilai,
                        'keterangan' => $row['keterangan'] ?? $keterangan,
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Dokumen berhasil diperbarui',
                'data' => $formulir->load(['unitKerjaDetails', 'intangibleDetails']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal memperbarui dokumen: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Menghapus dokumen beserta detailnya
     */
    public function destroy($id)
    {
        $formulir = SmkiFormulirAsetInformasi::find($id);

        if (!$formulir) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dokumen tidak ditemukan',
            ], 404);
        }

        // Hapus detail aset terkait
        SmkiAsetInformasiUnitKerja::where('formulir_id', $formulir->id)->delete();
        SmkiAsetInformasiIntangible::where('formulir_id', $formulir->id)->delete();
        $formulir->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Dokumen berhasil dihapus',
        ]);
    }

    /**
     * Ekspor Dokumen FR-028 ke Excel (.xlsx) dengan 2 Sheet
     */
    public function exportExcel($id)
    {
        $formulir = SmkiFormulirAsetInformasi::with([
            'unitKerjaDetails',
            'intangibleDetails',
        ])->find($id);

        if (!$formulir) {
            return response()->json([
                'status' => 'error',
                'message' => 'Dokumen tidak ditemukan',
            ], 404);
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // -------------------------------------------------------------
        // SHEET 1: Unit Kerja (Aset Informasi)
        // -------------------------------------------------------------
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Unit Kerja');

        // Pastikan garis grid Excel tetap terlihat
        $sheet1->setShowGridlines(true);

        // Header Dokumen: Center & Merge sepanjang tabel (A1:L1 dan A2:L2)
        $sheet1->mergeCells('A1:L1');
        $sheet1->mergeCells('A2:L2');
        $sheet1->setCellValue('A1', 'DAFTAR ASET INFORMASI');
        $namaBidang = strtoupper($formulir->bidang_nama ?? 'APTIKA');
        $sheet1->setCellValue('A2', 'BIDANG ' . $namaBidang);

        $sheet1->getStyle('A1:L2')->getFont()->setName('Calibri')->setBold(true)->setSize(11);
        $sheet1->getStyle('A1:L2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet1->getStyle('A1:L2')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet1->getRowDimension(1)->setRowHeight(22);
        $sheet1->getRowDimension(2)->setRowHeight(22);
        $sheet1->getRowDimension(3)->setRowHeight(10);
        $sheet1->getRowDimension(4)->setRowHeight(10);

        // Header Kolom Baris 5 (Sesuai Template FR-028)
        $headers1 = [
            'A5' => 'No',
            'B5' => 'Kode',
            'C5' => "Nama Aset\nInformasi",
            'D5' => 'Klasifikasi',
            'E5' => "Tahun\nPembuatan",
            'F5' => "Tahun Update\nTerakhir",
            'G5' => 'Deskripsi',
            'H5' => "Format\nPenyimpanan",
            'I5' => "Masa\nSimpan",
            'J5' => "Lokasi\nPenyimpanan",
            'K5' => "Pemilik Asset\nInformasi",
            'L5' => 'Keterangan',
        ];

        foreach ($headers1 as $cell => $textHeader) {
            $sheet1->setCellValue($cell, $textHeader);
        }

        // Styling Header Baris 5: Font Bold, Center Horizontal & Vertical, Wrap Text, Background Silver/Grey khas template
        $sheet1->getRowDimension(5)->setRowHeight(36);
        $headerStyle = $sheet1->getStyle('A5:L5');
        $headerStyle->getFont()->setName('Calibri')->setBold(true)->setSize(10);
        $headerStyle->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $headerStyle->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $headerStyle->getAlignment()->setWrapText(true);
        $headerStyle->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFD6DCE4');

        $rowIdx = 6;
        $unitKerjaList = $formulir->unitKerjaDetails;
        if ($unitKerjaList->isEmpty()) {
            $sheet1->setCellValue("A{$rowIdx}", '1');
            $sheet1->setCellValue("B{$rowIdx}", '-');
            $sheet1->setCellValue("C{$rowIdx}", 'NIHIL');
            $sheet1->setCellValue("D{$rowIdx}", '-');
            $sheet1->setCellValue("E{$rowIdx}", '-');
            $sheet1->setCellValue("F{$rowIdx}", '-');
            $sheet1->setCellValue("G{$rowIdx}", '-');
            $sheet1->setCellValue("H{$rowIdx}", '-');
            $sheet1->setCellValue("I{$rowIdx}", '-');
            $sheet1->setCellValue("J{$rowIdx}", '-');
            $sheet1->setCellValue("K{$rowIdx}", '-');
            $sheet1->setCellValue("L{$rowIdx}", '-');
            $sheet1->getRowDimension($rowIdx)->setRowHeight(24);
            $rowIdx++;
        } else {
            foreach ($unitKerjaList as $index => $item) {
                $sheet1->setCellValue("A{$rowIdx}", $index + 1);
                $sheet1->setCellValue("B{$rowIdx}", $item->kode_aset ?? '-');
                $sheet1->setCellValue("C{$rowIdx}", $item->nama_aset);
                $sheet1->setCellValue("D{$rowIdx}", $item->klasifikasi);
                $sheet1->setCellValue("E{$rowIdx}", $item->tahun_pembuatan ?? '-');
                $sheet1->setCellValue("F{$rowIdx}", $item->tahun_update ?? '-');
                $sheet1->setCellValue("G{$rowIdx}", $item->deskripsi ?? '-');
                $sheet1->setCellValue("H{$rowIdx}", $item->format_penyimpanan);
                $sheet1->setCellValue("I{$rowIdx}", $item->masa_simpan ?? '-');
                $sheet1->setCellValue("J{$rowIdx}", $item->lokasi_penyimpanan ?? '-');
                $sheet1->setCellValue("K{$rowIdx}", $item->pemilik_aset ?? '-');
                $sheet1->setCellValue("L{$rowIdx}", $item->keterangan ?? '-');

                // Hitung tinggi baris dinamis jika deskripsi atau nama aset panjang
                $descLen = strlen($item->deskripsi ?? '');
                $namaLen = strlen($item->nama_aset ?? '');
                if ($descLen > 100 || $namaLen > 50) {
                    $sheet1->getRowDimension($rowIdx)->setRowHeight(48);
                } elseif ($descLen > 50 || $namaLen > 30) {
                    $sheet1->getRowDimension($rowIdx)->setRowHeight(34);
                } else {
                    $sheet1->getRowDimension($rowIdx)->setRowHeight(24);
                }

                $rowIdx++;
            }
        }

        $lastDataRow = $rowIdx - 1;

        // Styling Border Lengkap (Thin) untuk seluruh tabel dari header (A5) sampai baris data terakhir
        $tableRange = "A5:L{$lastDataRow}";
        $sheet1->getStyle($tableRange)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        // Alignment Data Per Kolom
        $dataRange = "A6:L{$lastDataRow}";
        $sheet1->getStyle($dataRange)->getFont()->setName('Calibri')->setSize(10);
        $sheet1->getStyle($dataRange)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        // Perataan spesifik: Center untuk No, Kode, Klasifikasi, Tahun, Format, Masa Simpan, Keterangan
        $sheet1->getStyle("A6:B{$lastDataRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet1->getStyle("D6:F{$lastDataRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet1->getStyle("H6:I{$lastDataRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet1->getStyle("L6:L{$lastDataRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // Perataan Left + WrapText untuk kolom tekstual panjang (Nama Aset, Deskripsi, Lokasi, Pemilik)
        $sheet1->getStyle("C6:C{$lastDataRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT)->setWrapText(true);
        $sheet1->getStyle("G6:G{$lastDataRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT)->setWrapText(true);
        $sheet1->getStyle("J6:K{$lastDataRow}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT)->setWrapText(true);

        // Lebar Kolom Tetap & Proporsional Sesuai Template (Deskripsi dibuat 35 agar lapang & wrap rapi)
        $sheet1->getColumnDimension('A')->setWidth(6);   // No
        $sheet1->getColumnDimension('B')->setWidth(14);  // Kode
        $sheet1->getColumnDimension('C')->setWidth(28);  // Nama Aset Informasi
        $sheet1->getColumnDimension('D')->setWidth(14);  // Klasifikasi
        $sheet1->getColumnDimension('E')->setWidth(13);  // Tahun Pembuatan
        $sheet1->getColumnDimension('F')->setWidth(14);  // Tahun Update Terakhir
        $sheet1->getColumnDimension('G')->setWidth(35);  // Deskripsi
        $sheet1->getColumnDimension('H')->setWidth(15);  // Format Penyimpanan
        $sheet1->getColumnDimension('I')->setWidth(13);  // Masa Simpan
        $sheet1->getColumnDimension('J')->setWidth(24);  // Lokasi Penyimpanan
        $sheet1->getColumnDimension('K')->setWidth(24);  // Pemilik Asset Informasi
        $sheet1->getColumnDimension('L')->setWidth(14);  // Keterangan

        // -------------------------------------------------------------
        // SHEET 2: Intangible
        // -------------------------------------------------------------
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Intangible');

        // Pastikan garis grid Excel tetap terlihat
        $sheet2->setShowGridlines(true);

        // Baris 1: Kosong (Spasi)
        $sheet2->getRowDimension(1)->setRowHeight(12);

        // Kotak Informasi Formulir di Kiri Atas (A2:B5)
        $sheet2->setCellValue('A2', 'No Formulir');
        $sheet2->setCellValue('B2', $formulir->no_formulir ?? 'FR-028-SMKI');
        $sheet2->setCellValue('A3', 'Rev');
        $sheet2->setCellValue('B3', $formulir->revisi ?? '0');
        $sheet2->setCellValue('A4', 'Tanggal');
        $sheet2->setCellValue('B4', $formulir->tanggal ? $formulir->tanggal->format('d-M-y') : date('d-M-y'));
        $sheet2->setCellValue('A5', 'Klasifikasi');
        $sheet2->setCellValue('B5', 'Intangible');

        // Styling Kotak Info (A2:B5): Border thin di setiap sel, font Calibri 10/11
        $sheet2->getStyle('A2:B5')->getFont()->setName('Calibri')->setSize(10);
        $sheet2->getStyle('A2:A5')->getFont()->setBold(false);
        $sheet2->getStyle('B5')->getFont()->setBold(true)->setSize(11); // Teks "Intangible" bold
        $sheet2->getStyle('A2:B5')->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        $sheet2->getStyle('A2:A5')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
        $sheet2->getStyle('B2:B4')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
        $sheet2->getStyle('B5')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

        // Baris 6: Keterangan Dokumen Terbatas (Cetak Miring / Italic di bawah kotak info)
        $sheet2->mergeCells('B6:C6');
        $sheet2->setCellValue('B6', 'Dokumen Terbatas');
        $sheet2->getStyle('B6')->getFont()->setName('Calibri')->setItalic(true)->setSize(10);
        $sheet2->getStyle('B6')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // Judul Utama Dokumen: "DAFTAR ASET" di Tengah (Center) Merge C4:J5
        $sheet2->mergeCells('C4:J5');
        $sheet2->setCellValue('C4', 'DAFTAR ASET');
        $titleStyle2 = $sheet2->getStyle('C4:J5');
        $titleStyle2->getFont()->setName('Calibri')->setBold(true)->setSize(16);
        $titleStyle2->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $titleStyle2->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        // Header Kolom Tabel Baris 7
        $headers2 = [
            'A7' => 'No',
            'B7' => 'Layanan',
            'C7' => 'Nama Aset',
            'D7' => "Sub Klasifikasi",
            'E7' => 'Pemilik Aset',
            'F7' => 'Kerahasiaan',
            'G7' => 'Integritas',
            'H7' => 'Ketersediaan',
            'I7' => 'Nilai',
            'J7' => 'Keterangan',
        ];

        foreach ($headers2 as $cell => $textHeader) {
            $sheet2->setCellValue($cell, $textHeader);
        }

        // Styling Header Baris 7: Font Bold, Center Horizontal & Vertical, Wrap Text, Background Silver/Grey (#D6DCE4)
        $sheet2->getRowDimension(7)->setRowHeight(30);
        $headerStyle2 = $sheet2->getStyle('A7:J7');
        $headerStyle2->getFont()->setName('Calibri')->setBold(true)->setSize(10);
        $headerStyle2->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $headerStyle2->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $headerStyle2->getAlignment()->setWrapText(true);
        $headerStyle2->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFD6DCE4');

        $rowIdx2 = 8;
        $intangibleList = $formulir->intangibleDetails;
        if ($intangibleList->isEmpty()) {
            $sheet2->setCellValue("A{$rowIdx2}", 'INT-001');
            $sheet2->setCellValue("B{$rowIdx2}", '-');
            $sheet2->setCellValue("C{$rowIdx2}", 'NIHIL');
            $sheet2->setCellValue("D{$rowIdx2}", '-');
            $sheet2->setCellValue("E{$rowIdx2}", '-');
            $sheet2->setCellValue("F{$rowIdx2}", '-');
            $sheet2->setCellValue("G{$rowIdx2}", '-');
            $sheet2->setCellValue("H{$rowIdx2}", '-');
            $sheet2->setCellValue("I{$rowIdx2}", '-');
            $sheet2->setCellValue("J{$rowIdx2}", '-');
            $sheet2->getRowDimension($rowIdx2)->setRowHeight(24);
            $rowIdx2++;
        } else {
            foreach ($intangibleList as $index => $item) {
                $sheet2->setCellValue("A{$rowIdx2}", $item->kode_aset ?? ('INT-' . str_pad($index + 1, 3, '0', STR_PAD_LEFT)));
                $sheet2->setCellValue("B{$rowIdx2}", $item->layanan ?? '-');
                $sheet2->setCellValue("C{$rowIdx2}", $item->nama_aset);
                $sheet2->setCellValue("D{$rowIdx2}", $item->sub_klasifikasi ?? '-');
                $sheet2->setCellValue("E{$rowIdx2}", $item->pemilik_aset ?? '-');
                $sheet2->setCellValue("F{$rowIdx2}", $item->kerahasiaan);
                $sheet2->setCellValue("G{$rowIdx2}", $item->integritas);
                $sheet2->setCellValue("H{$rowIdx2}", $item->ketersediaan);
                $sheet2->setCellValue("I{$rowIdx2}", $item->nilai);
                $sheet2->setCellValue("J{$rowIdx2}", $item->keterangan);

                // Hitung tinggi baris dinamis jika nama aset / sub klasifikasi panjang
                $namaLen = strlen($item->nama_aset ?? '');
                $subLen = strlen($item->sub_klasifikasi ?? '');
                $layananLen = strlen($item->layanan ?? '');
                if ($namaLen > 60 || $subLen > 40 || $layananLen > 40) {
                    $sheet2->getRowDimension($rowIdx2)->setRowHeight(45);
                } elseif ($namaLen > 30 || $subLen > 20 || $layananLen > 25) {
                    $sheet2->getRowDimension($rowIdx2)->setRowHeight(32);
                } else {
                    $sheet2->getRowDimension($rowIdx2)->setRowHeight(24);
                }

                $rowIdx2++;
            }
        }

        $lastDataRow2 = $rowIdx2 - 1;

        // Styling Border Lengkap (Thin) untuk seluruh tabel dari header (A7) sampai baris data terakhir
        $tableRange2 = "A7:J{$lastDataRow2}";
        $sheet2->getStyle($tableRange2)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        // Alignment Data Sheet 2
        $dataRange2 = "A8:J{$lastDataRow2}";
        $sheet2->getStyle($dataRange2)->getFont()->setName('Calibri')->setSize(10);
        $sheet2->getStyle($dataRange2)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        // Perataan Center: No (Kode INT), Kerahasiaan (C), Integritas (I), Ketersediaan (A), Nilai, Keterangan
        $sheet2->getStyle("A8:A{$lastDataRow2}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet2->getStyle("F8:J{$lastDataRow2}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        // Perataan Left + WrapText: Layanan, Nama Aset, Sub Klasifikasi, Pemilik Aset
        $sheet2->getStyle("B8:E{$lastDataRow2}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT)->setWrapText(true);

        // Lebar Kolom Tetap & Proporsional Sesuai Template Resmi FR-028
        $sheet2->getColumnDimension('A')->setWidth(12);  // No (INT-001)
        $sheet2->getColumnDimension('B')->setWidth(26);  // Layanan
        $sheet2->getColumnDimension('C')->setWidth(30);  // Nama Aset
        $sheet2->getColumnDimension('D')->setWidth(22);  // Sub Klasifikasi
        $sheet2->getColumnDimension('E')->setWidth(20);  // Pemilik Aset
        $sheet2->getColumnDimension('F')->setWidth(14);  // Kerahasiaan
        $sheet2->getColumnDimension('G')->setWidth(13);  // Integritas
        $sheet2->getColumnDimension('H')->setWidth(14);  // Ketersediaan
        $sheet2->getColumnDimension('I')->setWidth(10);  // Nilai
        $sheet2->getColumnDimension('J')->setWidth(14);  // Keterangan

                $spreadsheet->setActiveSheetIndex(0);

        $cleanNomor = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $formulir->no_formulir ?? 'FR-028');
        $filename = "FR-028_{$cleanNomor}.xlsx";

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
