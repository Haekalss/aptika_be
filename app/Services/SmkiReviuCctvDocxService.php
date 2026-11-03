<?php

namespace App\Services;

use App\Models\SmkiReviuCctv;
use App\Services\Concerns\FillsDocxTemplate;
use Exception;
use ZipArchive;

/**
 * Ekspor Formulir Reviu Monitoring dan Pemeliharaan CCTV (FR-038/KOM.03.05/SANDIKAMI)
 * ke .docx dengan mengisi template asli "FR-038 Formulir Reviu CCTV.docx".
 * Orientasi kertas (landscape) mengikuti template asli.
 */
class SmkiReviuCctvDocxService
{
    use FillsDocxTemplate;

    /** Jumlah baris minimum pada tabel (sesuai template: 3 baris kosong). */
    private const MIN_ROWS = 3;

    public static function getTemplatePath(): string
    {
        $paths = [
            resource_path('templates/FR-038 Formulir Reviu CCTV.docx'),
            base_path('resources/templates/FR-038 Formulir Reviu CCTV.docx'),
            storage_path('app/templates/FR-038 Formulir Reviu CCTV.docx'),
        ];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new Exception('Template dokumen FR-038 Formulir Reviu CCTV.docx tidak ditemukan di server.');
    }

    /**
     * @return string Path file .docx sementara (hapus setelah dikirim)
     *
     * @param array|null $tteOverride  ['nama' => ..., 'nip' => ...] untuk menimpa
     *                                 penanda tangan saat unduh (dipilih di UI).
     */
    public function generateDocx(SmkiReviuCctv $reviu, ?array $tteOverride = null): string
    {
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $output = $tempDir . '/FR-038_generated_' . uniqid() . '.docx';
        if (!copy(self::getTemplatePath(), $output)) {
            throw new Exception('Gagal menyalin file template FR-038.');
        }

        $zip = new ZipArchive();
        if ($zip->open($output) !== true) {
            throw new Exception('Gagal membuka file DOCX.');
        }

        $dom = $this->loadPart($zip, 'word/document.xml');
        if (!$dom) {
            $zip->close();
            throw new Exception('Format dokumen tidak memiliki word/document.xml.');
        }
        $xp = $this->docxXPath($dom);

        // Seluruh formulir berada dalam satu tabel:
        // 0-2 kop | 3-6 identitas | 7 judul kolom | 8.. baris reviu
        $table = $xp->query('//w:body/w:tbl')->item(0);
        $rows = $table ? $xp->query('w:tr', $table) : null;
        if (!$rows || $rows->length < 9) {
            $zip->close();
            throw new Exception('Struktur tabel dalam template FR-038 tidak valid.');
        }

        $this->fillIdentity($dom, $xp, $rows, $reviu);
        $this->fillItems($dom, $xp, $table, $reviu);
        // Gambar disisipkan SEBELUM fillSignature, karena fillSignature sudah
        // mengganti teks "(…)" menjadi "(Nama)" sehingga paragraf penanda hilang.
        $this->fillTteImage($zip, $dom, $xp, $reviu, $tteOverride);
        $this->fillSignature($dom, $xp, $reviu, $tteOverride);
        $this->fitTablesToWidth($xp, $this->contentWidth($xp), '//w:body//w:tbl');

        $zip->addFromString('word/document.xml', $dom->saveXML());
        $zip->close();

        return $output;
    }

    /** Lebar area isi (twip) = lebar kertas - margin kiri - margin kanan. */
    private function contentWidth($xp): int
    {
        $pgSz = $xp->query('//w:body/w:sectPr/w:pgSz')->item(0);
        $pgMar = $xp->query('//w:body/w:sectPr/w:pgMar')->item(0);
        if (!$pgSz || !$pgMar) {
            return 15820;
        }

        return (int) $pgSz->getAttributeNS(self::W_NS, 'w')
            - (int) $pgMar->getAttributeNS(self::W_NS, 'left')
            - (int) $pgMar->getAttributeNS(self::W_NS, 'right');
    }

    private function fillIdentity($dom, $xp, $rows, SmkiReviuCctv $reviu): void
    {
        $periode = '';
        if ($reviu->periode_mulai && $reviu->periode_selesai) {
            $periode = $this->tanggalIndo($reviu->periode_mulai) . ' s.d. ' . $this->tanggalIndo($reviu->periode_selesai);
        } elseif ($reviu->periode_mulai || $reviu->periode_selesai) {
            $periode = $this->tanggalIndo($reviu->periode_mulai ?: $reviu->periode_selesai);
        }
        $values = [
            3 => $this->tanggalIndo($reviu->tanggal_reviu),
            4 => $periode,
            5 => (string) $reviu->obyek_reviu,
            6 => (string) $reviu->pelaksana_reviu,
        ];
        foreach ($values as $i => $value) {
            if (trim($value) === '') {
                continue;
            }
            $cells = $xp->query('w:tc', $rows->item($i));
            if ($cells->length < 3) {
                continue;
            }
            $label = $xp->query('w:p', $cells->item(0))->item(0);
            $rPr = $label ? $this->paragraphRPr($xp, $label) : null; // Arial 11 seperti label
            $this->setCellText($dom, $xp, $cells->item(2), trim($value), 'left', $rPr);
        }
    }

    private function fillItems($dom, $xp, $table, SmkiReviuCctv $reviu): void
    {
        $items = $reviu->items;
        $rowsData = $this->ensureDataRows($xp, $table, 8, max(self::MIN_ROWS, $items->count()));

        $numP = $xp->query('w:tc[1]/w:p', $rowsData[0])->item(0);
        $rPr = $numP ? $this->paragraphRPr($xp, $numP) : null;
        $aligns = ['center', 'left', 'left', 'center', 'left'];

        foreach ($rowsData as $i => $tr) {
            $this->preventRowSplit($dom, $xp, $tr);
            $item = $items[$i] ?? null;
            $cells = $xp->query('w:tc', $tr);
            $data = [
                (string) ($i + 1),
                $item ? (string) $item->uraian_reviu : '',
                $item ? (string) $item->tindak_lanjut : '',
                $item ? $this->tanggalRingkas($item->target_selesai) : '',
                $item ? (string) $item->keterangan : '',
            ];
            foreach ($data as $c => $text) {
                if ($c < $cells->length) {
                    $this->setCellText($dom, $xp, $cells->item($c), $text, $aligns[$c], $rPr);
                }
            }
        }
    }

    /** "(……………)" -> "(Nama)" dan baris "NIP. ..." di bawahnya. */
    private function fillSignature($dom, $xp, SmkiReviuCctv $reviu, ?array $override = null): void
    {
        $this->keepSignatureTogether($dom, $xp);

        $nama = trim((string) ($override['nama'] ?? $reviu->pengelola_nama));
        $nip = trim((string) ($override['nip'] ?? $reviu->pengelola_nip));
        if ($nama === '' && $nip === '') {
            return;
        }
        foreach ($xp->query('//w:body/w:p') as $p) {
            if (strpos(trim($p->textContent), '(…') !== 0) {
                continue;
            }
            if ($nama !== '') {
                $this->setParagraphText($dom, $xp, $p, '(' . $nama . ')');
            }
            if ($nip !== '') {
                $nipPara = $p->cloneNode(true);
                $this->setParagraphText($dom, $xp, $nipPara, 'NIP. ' . $nip);
                $p->parentNode->insertBefore($nipPara, $p->nextSibling);
            }
            break;
        }
    }

    /** Blok "Pengelola CCTV, ... (nama)" dijaga tetap satu halaman (keepNext). */
    private function keepSignatureTogether($dom, $xp): void
    {
        $start = null;
        foreach ($xp->query('//w:body/w:p') as $p) {
            if (strpos($p->textContent, 'Pengelola CCTV') !== false) {
                $start = $p;
                break;
            }
        }
        for ($p = $start; $p; $p = $p->nextSibling) {
            if ($p->nodeName !== 'w:p') {
                break;
            }
            $text = $p->textContent;
            if (strpos($text, '(…') === 0) {
                break;
            }
            $pPr = $xp->query('w:pPr', $p)->item(0);
            if (!$pPr) {
                $pPr = $dom->createElementNS(self::W_NS, 'w:pPr');
                $p->insertBefore($pPr, $p->firstChild);
            }
            if (!$xp->query('w:keepNext', $pPr)->length) {
                $pPr->insertBefore($dom->createElementNS(self::W_NS, 'w:keepNext'), $pPr->firstChild);
            }
        }
    }

    /**
     * Sisipkan gambar spesimen TTE di antara "Pengelola CCTV," dan baris "(Nama)".
     *
     * Sumber gambar ditentukan lewat TteSpecimenService: master spesimen TTE dulu
     * (dicocokkan NIP/nama), fallback ke master pegawai.
     */
    private function fillTteImage($zip, $dom, $xp, SmkiReviuCctv $reviu, ?array $override = null): void
    {
        $nip = trim((string) ($override['nip'] ?? $reviu->pengelola_nip));
        $nama = trim((string) ($override['nama'] ?? $reviu->pengelola_nama));
        if ($nip === '' && $nama === '') {
            return;
        }

        $path = app(\App\Services\TteResolverService::class)->imagePath($nip, $nama);
        if ($path === null) {
            return;
        }

        // Paragraf "(Nama)" jadi titik sisip; gambar ditaruh tepat sebelum paragraf itu.
        $target = null;
        foreach ($xp->query('//w:body/w:p') as $p) {
            if (strpos(trim($p->textContent), '(…') === 0) {
                $target = $p;
                break;
            }
        }
        if (!$target) {
            return;
        }

        $imgPara = $dom->createElementNS(self::W_NS, 'w:p');
        $pPr = $dom->createElementNS(self::W_NS, 'w:pPr');
        $spacing = $dom->createElementNS(self::W_NS, 'w:spacing');
        $spacing->setAttributeNS(self::W_NS, 'w:before', '120');
        $spacing->setAttributeNS(self::W_NS, 'w:after', '60');
        $pPr->appendChild($spacing);
        $imgPara->appendChild($pPr);

        if ($this->insertImageIntoParagraph($zip, $dom, $xp, $imgPara, $path, 280)) {
            $target->parentNode->insertBefore($imgPara, $target);
        }
    }
}
