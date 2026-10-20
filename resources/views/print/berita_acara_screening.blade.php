<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Berita Acara Screening Karyawan Outsourcing - {{ $screening->nama_karyawan }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm 15mm 12mm 15mm;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9pt;
            line-height: 1.3;
            color: #000;
            background: #fff;
            margin: 0;
            padding: 0;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
            border: 1.2px solid #000;
        }
        .header-table td {
            border: 1px solid #000;
            padding: 4px 6px;
            vertical-align: middle;
        }
        .header-logo {
            width: 17%;
            text-align: center;
        }
        .header-logo img {
            max-width: 52px;
            max-height: 52px;
            height: auto;
            display: inline-block;
        }
        .header-title {
            width: 43%;
            text-align: center;
            font-size: 11pt;
            font-weight: bold;
            line-height: 1.25;
            text-transform: uppercase;
        }
        .header-title small {
            display: block;
            font-size: 8.5pt;
            margin-top: 3px;
        }
        .header-meta {
            width: 40%;
            font-size: 7.5pt;
            padding: 0 !important;
        }
        .header-meta table {
            width: 100%;
            border-collapse: collapse;
        }
        .header-meta table td {
            border: none;
            border-bottom: 1px solid #000;
            padding: 3.5px 6px;
            font-size: 7.5pt;
        }
        .header-meta table tr:last-child td {
            border-bottom: none;
        }
        .header-meta .meta-label {
            width: 35%;
            border-right: 1px solid #000;
        }
        .header-meta .meta-val {
            width: 65%;
            font-weight: bold;
            white-space: nowrap;
        }
        .section-title {
            font-size: 9pt;
            font-weight: bold;
            margin-top: 10px;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.2px;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        .info-table td {
            padding: 2.5px 4px;
            font-size: 8.5pt;
            vertical-align: top;
        }
        .info-table .label {
            width: 25%;
            font-weight: normal;
        }
        .info-table .colon {
            width: 2%;
            text-align: center;
        }
        .info-table .value {
            width: 73%;
            font-weight: bold;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
            margin-bottom: 10px;
        }
        .data-table th, .data-table td {
            border: 1px solid #000;
            padding: 4px 6px;
            font-size: 8pt;
        }
        .data-table th {
            background-color: #f2f2f2;
            text-align: center;
            font-weight: bold;
        }
        .text-center {
            text-align: center;
        }
        .checkmark-icon {
            color: #15803d;
            font-weight: bold;
            font-size: 11pt;
            line-height: 1;
        }
        .dash-icon {
            color: #888;
            font-size: 10pt;
        }
        .box-conclusion {
            border: 1px solid #000;
            padding: 6px 10px;
            margin-bottom: 10px;
            background: #fff;
        }
        .checkbox-item {
            margin: 3px 0;
            font-size: 8.5pt;
            display: flex;
            align-items: center;
        }
        .square-box {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 1.2px solid #000;
            margin-right: 6px;
            text-align: center;
            line-height: 10px;
            font-weight: bold;
            font-size: 9pt;
            color: #15803d;
        }
        .notes-box {
            border: 1px solid #000;
            min-height: 34px;
            padding: 5px 8px;
            font-size: 8pt;
            margin-bottom: 12px;
            background: #fff;
        }
        .signature-section {
            width: 100%;
            margin-top: 10px;
            page-break-inside: avoid;
        }
        .signature-right {
            width: 45%;
            float: right;
            text-align: center;
            font-size: 8.5pt;
        }
        .signature-space {
            height: 48px;
        }
        .print-actions {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #047857;
            color: #fff;
            padding: 10px 18px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.15);
            cursor: pointer;
            font-family: sans-serif;
            font-weight: bold;
            font-size: 13px;
            border: none;
            z-index: 9999;
        }
        @media print {
            .print-actions {
                display: none;
            }
            body {
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <button class="print-actions" onclick="window.print()">🖨️ Cetak Dokumen</button>

    <!-- HEADER RESMI FR-012/KOM.03.05/SANDIKAMI -->
    <table class="header-table">
        <tr>
            <td class="header-logo">
                <img src="/logo-jabar.png" alt="Logo Jawa Barat" onerror="this.src='/logo-diskominfo-jabar.png'; this.onerror=null;">
            </td>
            <td class="header-title">
                BERITA ACARA
                <small>SCREENING KARYAWAN OUTSOURCING</small>
            </td>
            <td class="header-meta">
                <table>
                    <tr>
                        <td class="meta-label">No. Dokumen</td>
                        <td class="meta-val">FR-012/KOM.03.05/SANDIKAMI/</td>
                    </tr>
                    <tr>
                        <td class="meta-label">No. Revisi</td>
                        <td class="meta-val">1.1</td>
                    </tr>
                    <tr>
                        <td class="meta-label">Tanggal Berlaku</td>
                        <td class="meta-val">20 Januari 2022</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- DATA KARYAWAN -->
    <div class="section-title">DATA KARYAWAN OUTSOURCING</div>
    <table class="info-table">
        <tr>
            <td class="label">Periode Tahun</td>
            <td class="colon">:</td>
            <td class="value">{{ $screening->tahun }}</td>
        </tr>
        <tr>
            <td class="label">Nama Karyawan</td>
            <td class="colon">:</td>
            <td class="value">{{ $screening->nama_karyawan }}</td>
        </tr>
        <tr>
            <td class="label">Jabatan</td>
            <td class="colon">:</td>
            <td class="value">{{ $screening->jabatan->nama_jabatan ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Penempatan di Bidang</td>
            <td class="colon">:</td>
            <td class="value">{{ $screening->bidang->nama_bidang ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Tanggal Screening</td>
            <td class="colon">:</td>
            <td class="value">
                @php
                    $bulanIndo = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                    $tgl = \Carbon\Carbon::parse($screening->tanggal_screening);
                    $formatTgl = $tgl->format('d') . ' ' . $bulanIndo[(int)$tgl->format('m')] . ' ' . $tgl->format('Y');
                @endphp
                {{ $formatTgl }}
            </td>
        </tr>
    </table>

    <!-- HASIL PEMERIKSAAN CHECKLIST -->
    <div class="section-title">HASIL PEMERIKSAAN DOKUMEN SCREENING</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 6%;">No</th>
                <th style="width: 44%;">Item Pemeriksaan</th>
                <th style="width: 10%;">Ada</th>
                <th style="width: 10%;">Tidak</th>
                <th style="width: 30%;">Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @php
                $defaultItems = [
                    1 => 'Data Identitas (KTP, NPWP, KK)',
                    2 => 'Tingkat Pendidikan (Ijazah & Sertifikat)',
                    3 => 'Pemberi Referensi',
                    4 => 'Pengalaman Kerja Sebelumnya',
                    5 => 'Catatan lain yang diperlukan',
                ];
            @endphp
            @foreach($defaultItems as $itemNum => $itemName)
                @php
                    $detail = $screening->details->firstWhere('id_item', $itemNum);
                    $isAda = $detail && $detail->status === 'Ada';
                    $isTidak = $detail && $detail->status === 'Tidak';
                    $ket = $detail && $detail->keterangan && $detail->keterangan !== '-' ? $detail->keterangan : '-';
                @endphp
                <tr>
                    <td class="text-center">{{ $itemNum }}</td>
                    <td>{{ $itemName }}</td>
                    <td class="text-center">
                        @if($isAda)
                            <span class="checkmark-icon">✓</span>
                        @else
                            <span class="dash-icon">-</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if($isTidak)
                            <span class="checkmark-icon" style="color: #dc2626;">✓</span>
                        @else
                            <span class="dash-icon">-</span>
                        @endif
                    </td>
                    <td>{{ $ket }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <!-- KESIMPULAN AKHIR -->
    <div class="section-title">KESIMPULAN AKHIR</div>
    <div class="box-conclusion">
        <div class="checkbox-item">
            <span class="square-box">{{ $screening->kesimpulan === 'Perlu melengkapi administrasi' ? '✓' : '' }}</span>
            <span style="{{ $screening->kesimpulan === 'Perlu melengkapi administrasi' ? 'font-weight: bold;' : '' }}">Perlu melengkapi administrasi</span>
        </div>
        <div class="checkbox-item">
            <span class="square-box">{{ $screening->kesimpulan === 'Administrasi Lengkap' ? '✓' : '' }}</span>
            <span style="{{ $screening->kesimpulan === 'Administrasi Lengkap' ? 'font-weight: bold;' : '' }}">Administrasi Lengkap</span>
        </div>
    </div>

    <!-- CATATAN TAMBAHAN -->
    <div class="section-title">CATATAN TAMBAHAN</div>
    <div class="notes-box">
        {{ $screening->catatan ?: 'Karyawan memenuhi semua kriteria dokumen yang dipersyaratkan. Referensi telah diverifikasi secara internal.' }}
    </div>

    <!-- TANDA TANGAN -->
    <div class="signature-section">
        <div class="signature-right">
            <div>Bandung, {{ $formatTgl }}</div>
            <div style="font-weight: bold; margin-top: 3px;">Petugas Verifikator Screening</div>
            <div class="signature-space"></div>
            <div style="font-weight: bold;">( .................................................. )</div>
            <div style="font-size: 8pt; margin-top: 2px;">Pemerintah Provinsi Jawa Barat</div>
        </div>
        <div style="clear: both;"></div>
    </div>
</body>
</html>
