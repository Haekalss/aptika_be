@php
  if (!function_exists('getRiskBadgeClass')) {
    function getRiskBadgeClass($cat) {
      $c = strtolower(trim((string)$cat));
      if (str_contains($c, 'ekstrim') || str_contains($c, 'extreme')) return 'b-ekstrim';
      if (str_contains($c, 'sangat') || str_contains($c, 'very')) return 'b-sangat';
      if (str_contains($c, 'tinggi') || str_contains($c, 'high')) return 'b-tinggi';
      if (str_contains($c, 'menengah') || str_contains($c, 'medium')) return 'b-menengah';
      return 'b-rendah';
    }
  }
  if (!function_exists('getStatusBadgeClass')) {
    function getStatusBadgeClass($st) {
      $s = strtolower(trim((string)$st));
      if (str_contains($s, 'kontrol') || str_contains($s, 'mitigate')) return 'b-tinggi';
      return 'b-rendah';
    }
  }
  if (!function_exists('getMonitoringBadgeClass')) {
    function getMonitoringBadgeClass($mon) {
      $m = strtolower(trim((string)$mon));
      if (str_contains($m, 'closed')) return 'b-closed';
      if (str_contains($m, 'progress')) return 'b-progress';
      return 'b-open';
    }
  }
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>FR-002 Risk Register {{ $tahun }}</title>
<style>
  @page { size: A3 landscape; margin: 12mm 10mm; }
  * { box-sizing: border-box; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 6.5pt; color: #111; }
  h1 { text-align: center; font-size: 11pt; margin: 0 0 2px; }
  h2 { text-align: center; font-size: 9pt; font-weight: normal; margin: 0 0 8px; }
  .meta { width: 100%; margin-bottom: 6px; font-size: 7pt; }
  .meta td { padding: 1px 4px; }
  table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
  table.grid th, table.grid td { border: 1px solid #333; padding: 2px 3px; word-wrap: break-word; vertical-align: top; }
  table.grid th { background: #D9E1F2; font-weight: bold; text-align: center; }
  tr.cat td { background: #FFF2CC; font-weight: bold; }
  td.num, th.num { text-align: center; }
  td.ctr { text-align: center; }
  .badge { display: inline-block; padding: 1px 4px; border-radius: 3px; color: #fff; font-weight: bold; font-size: 6pt; }
  .b-rendah { background: #16a34a; } 
  .b-menengah { background: #d97706; }
  .b-tinggi { background: #ea580c; } 
  .b-sangat { background: #dc2626; } 
  .b-ekstrim { background: #881337; }
  .b-open { background: #f97316; color: #fff; }
  .b-progress { background: #eab308; color: #000; }
  .b-closed { background: #15803d; color: #fff; }
  .footer { margin-top: 10px; font-size: 7pt; }
  .footer table { border-collapse: collapse; }
  .footer td { padding: 2px 8px 2px 0; vertical-align: top; }
  .sign-img { height: 55px; }
</style>
</head>
<body>
  <h1>DAFTAR RISIKO (RISK REGISTER)</h1>
  <h2>DINAS KOMUNIKASI DAN INFORMATIKA PROVINSI JAWA BARAT &mdash; TAHUN {{ $tahun }}</h2>

  <table class="meta">
    <tr>
      <td style="width:130px"><strong>Ruang lingkup (Scope)</strong></td>
      <td>: {{ $header->ruang_lingkup ?? 'Fisik Data Center' }}</td>
      <td style="width:110px"><strong>Klasifikasi</strong></td>
      <td>: {{ $header->klasifikasi ?? 'Internal' }}</td>
    </tr>
    <tr>
      <td><strong>Revisi</strong></td>
      <td>: {{ $header->no_revisi ?? '4.0' }}</td>
      <td><strong>Tanggal</strong></td>
      <td>: {{ $tanggalDok }}</td>
    </tr>
  </table>

  <table class="grid">
    <thead>
      <tr>
        <th style="width:18px">NO</th>
        <th style="width:70px">ASET/PROSES/<br>LAYANAN</th>
        <th>VULNERABILITY</th>
        <th>THREAT</th>
        <th style="width:26px">CIA</th>
        <th>DAMPAK</th>
        <th style="width:22px">L</th>
        <th style="width:22px">I</th>
        <th style="width:22px">SKOR</th>
        <th style="width:48px">KATEGORI</th>
        <th>EXISTING<br>CONTROL</th>
        <th>REFERENSI</th>
        <th>ANNEX ISO</th>
        <th style="width:22px">L</th>
        <th style="width:22px">I</th>
        <th style="width:22px">SKOR</th>
        <th style="width:48px">KATEGORI</th>
        <th style="width:52px">STATUS</th>
        <th>RTP</th>
        <th style="width:22px">L</th>
        <th style="width:22px">I</th>
        <th style="width:22px">SKOR</th>
        <th style="width:48px">KATEGORI</th>
        <th style="width:52px">STATUS</th>
        <th>URAIAN</th>
        <th style="width:44px">TARGET</th>
        <th>PIC</th>
        <th style="width:40px">PRIORITAS</th>
        <th>OWNERSHIP</th>
        <th style="width:40px">MONITORING</th>
        <th>KET</th>
      </tr>
    </thead>
    <tbody>
      @php $no = 1; @endphp
      @foreach($groups as $g)
        <tr class="cat">
          <td class="ctr"><strong>{{ $g['kategori']->kode_kategori }}</strong></td>
          <td colspan="30"><strong>{{ $g['kategori']->nama_kategori_aset }}</strong></td>
        </tr>
        @foreach($g['items'] as $item)
          @php $rtp = $item->treatmentPlan; @endphp
          <tr>
            <td class="num">{{ $no++ }}</td>
            <td>{{ $item->nama_aset_proses }}</td>
            <td>{{ $item->kerawanan_vulnerability }}</td>
            <td>{{ $item->ancaman_threat }}</td>
            <td class="ctr">{{ $item->kategori_cia }}</td>
            <td>{{ $item->uraian_dampak }}</td>
            <td class="ctr">{{ $item->inherent_likelihood }}</td>
            <td class="ctr">{{ $item->inherent_impact }}</td>
            <td class="ctr">{{ $item->inherent_score }}</td>
            <td class="ctr">{{ $item->inherent_kategori }}</td>
            <td>{{ $item->existing_control }}</td>
            <td>{{ $item->referensi_dokumen }}</td>
            <td>{{ $item->kontrol_annex_iso }}</td>
            <td class="ctr">{{ $item->current_likelihood }}</td>
            <td class="ctr">{{ $item->current_impact }}</td>
            <td class="ctr">{{ $item->current_score }}</td>
            <td class="ctr">{{ $item->current_kategori }}</td>
            <td class="ctr">{{ $item->status_risiko_current }}</td>
            <td>{{ $rtp->opsi_penanggulangan ?? '-' }}</td>
            <td class="ctr">{{ $item->residual_likelihood }}</td>
            <td class="ctr">{{ $item->residual_impact }}</td>
            <td class="ctr">{{ $item->residual_score }}</td>
            <td class="ctr">{{ $item->residual_kategori }}</td>
            <td class="ctr">{{ $item->status_risiko_residual }}</td>
            <td>{{ $rtp->uraian_rencana ?? '-' }}</td>
            <td class="ctr">{{ $rtp && $rtp->target_selesai ? \Carbon\Carbon::parse($rtp->target_selesai)->format('d/m/Y') : '-' }}</td>
            <td>{{ $rtp->pic_penanggung_jawab ?? '-' }}</td>
            <td class="ctr">{{ $rtp->prioritas ?? '-' }}</td>
            <td>{{ $rtp->ownership ?? '-' }}</td>
            <td class="ctr">{{ $rtp->status_monitoring ?? 'Open' }}</td>
            <td>{{ $rtp->keterangan ?? '-' }}</td>
          </tr>
        @endforeach
      @endforeach
    </tbody>
  </table>

  <div class="footer">
    <table>
      <tr><td><strong>Keterangan :</strong></td><td>C = Confidentiality (Kerahasiaan)</td></tr>
      <tr><td></td><td>I = Integrity (Keutuhan)</td></tr>
      <tr><td></td><td>A = Availability (Ketersediaan)</td></tr>
    </table>
    <br>
    <table>
      <tr>
        <td><strong>Ket</strong></td>
        <td><strong>Jabatan</strong></td>
        <td><strong>Nama</strong></td>
        <td><strong>Tandatangan</strong></td>
        <td><strong>Tanggal</strong></td>
      </tr>
      <tr>
        <td>Disiapkan oleh</td>
        <td>{{ $header->jabatan_pengesah ?? 'Kepala Bidang Aplikasi Informatika' }}</td>
        <td>{{ $header->nama_pengesah ?? 'Nama Kepala Bidang' }}</td>
        <td>
          @if($specimen)
            <img class="sign-img" src="data:image/png;base64,{{ $specimen }}">
          @else
            [Tanda Tangan Digital]
          @endif
        </td>
        <td>{{ $tanggalDok }}</td>
      </tr>
    </table>
  </div>
</body>
</html>
