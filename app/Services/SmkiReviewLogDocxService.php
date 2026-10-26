<?php

namespace App\Services;

use App\Models\SmkiReviewLog;
use App\Services\Concerns\FillsDocxTemplate;
use Exception;
use ZipArchive;

/**
 * Ekspor Formulir Review Log (FR-040/KOM.03.05/SANDIKAMI) ke .docx dengan
 * mengisi template asli "FR-040 Formulir Review Log.docx".
 */
class SmkiReviewLogDocxService
{
    use FillsDocxTemplate;

    /** Jumlah baris log minimum pada tabel (sesuai template: 8 baris kosong). */
    private const MIN_ROWS = 8;

    public static function getTemplatePath(): string
    {
        $paths = [
            resource_path('templates/FR-040 Formulir Review Log.docx'),
            base_path('resources/templates/FR-040 Formulir Review Log.docx'),
            storage_path('app/templates/FR-040 Formulir Review Log.docx'),
        ];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new Exception('Template dokumen FR-040 Formulir Review Log.docx tidak ditemukan di server.');
    }

    /**
     * @return string Path file .docx sementara (hapus setelah dikirim)
     */
    public function generateDocx(SmkiReviewLog $log): string
    {
        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $output = $tempDir . '/FR-040_generated_' . uniqid() . '.docx';
        if (!copy(self::getTemplatePath(), $output)) {
            throw new Exception('Gagal menyalin file template FR-040.');
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

        // Seluruh formulir berada dalam satu tabel bingkai:
        // 0-2 kop | 3 identitas | 4 tabel log | 5 tindak lanjut | 6 tempat/tanggal | 7 tanda tangan
        $outer = $xp->query('//w:body/w:tbl')->item(0);
        $rows = $outer ? $xp->query('w:tr', $outer) : null;
        if (!$rows || $rows->length < 8) {
            $zip->close();
            throw new Exception('Struktur tabel dalam template FR-040 tidak valid.');
        }

        $this->fillIdentity($dom, $xp, $rows->item(3), $log);
        $this->fillLogTable($dom, $xp, $rows->item(4), $log);
        $this->fillTindakLanjut($dom, $xp, $rows->item(5), $log);
        // Gambar harus disisipkan SEBELUM fillSignature, karena method itu
        // mengganti teks paragraf "(…)" menjadi "(Nama)".
        $this->fillTteImages($zip, $dom, $xp, $log);
        $this->fillSignature($dom, $xp, $rows->item(6), $rows->item(7), $log);

        $zip->addFromString('word/document.xml', $dom->saveXML());
        $zip->close();

        return $output;
    }

    private function fillIdentity($dom, $xp, $row, SmkiReviewLog $log): void
    {
        $periode = '';
        if ($log->periode_mulai && $log->periode_selesai) {
            $periode = $this->tanggalIndo($log->periode_mulai) . ' s.d. ' . $this->tanggalIndo($log->periode_selesai);
        } elseif ($log->periode_mulai || $log->periode_selesai) {
            $periode = $this->tanggalIndo($log->periode_mulai ?: $log->periode_selesai);
        }
        $values = [
            'Tanggal Review'   => $this->tanggalIndo($log->tanggal_review),
            'Periode Review'   => $periode,
            'Obyek Review'     => (string) $log->obyek_review,
            'Pelaksana Review' => (string) $log->pelaksana_review,
        ];

        foreach ($xp->query('.//w:p', $row) as $p) {
            $text = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $p->textContent));
            foreach ($values as $label => $value) {
                if (preg_match('/^' . preg_quote($label, '/') . ' ?:$/u', $text) && $value !== '') {
                    $this->appendToParagraph($dom, $xp, $p, $value);
                }
            }
        }
    }

    private function fillLogTable($dom, $xp, $row, SmkiReviewLog $log): void
    {
        $table = $xp->query('.//w:tbl', $row)->item(0);
        if (!$table) {
            throw new Exception('Tabel log pada template FR-040 tidak ditemukan.');
        }
        $items = $log->items;
        $rowsData = $this->ensureDataRows($xp, $table, 1, max(self::MIN_ROWS, $items->count()));

        // Format sel data mengikuti sel judul kolom (Arial 10).
        $head = $xp->query('w:tr', $table)->item(0);
        $headCell = $xp->query('w:tc', $head)->item(0);
        $rPr = $this->paragraphRPr($xp, $xp->query('w:p', $headCell)->item(0));
        $aligns = ['center', 'left', 'left', 'left', 'left', 'left'];

        foreach ($items as $i => $item) {
            $tr = $rowsData[$i];
            $this->preventRowSplit($dom, $xp, $tr);
            $cells = $xp->query('w:tc', $tr);
            $data = [
                (string) ($i + 1),
                (string) $item->user_log,
                (string) $item->ip_address,
                $this->tanggalRingkas($item->tanggal),
                $item->waktu ? substr((string) $item->waktu, 0, 5) : '',
                (string) $item->durasi,
            ];
            foreach ($data as $c => $text) {
                if ($c < $cells->length) {
                    $this->setCellText($dom, $xp, $cells->item($c), $text, $aligns[$c], $rPr);
                }
            }
        }
    }

    private function fillTindakLanjut($dom, $xp, $row, SmkiReviewLog $log): void
    {
        $text = trim((string) $log->tindak_lanjut);
        if ($text === '') {
            return;
        }
        $label = null;
        foreach ($xp->query('w:tc/w:p', $row) as $p) {
            if (trim($p->textContent) === 'Tindak lanjut') {
                $label = $p;
            }
        }
        $rPr = $label ? $this->paragraphRPr($xp, $label) : null;

        $box = $xp->query('.//w:tbl/w:tr[1]/w:tc[1]', $row)->item(0);
        if (!$box) {
            throw new Exception('Kotak tindak lanjut pada template FR-040 tidak ditemukan.');
        }
        $this->setCellText($dom, $xp, $box, $text, 'left', $rPr);
    }

    /**
     * Sisipkan gambar spesimen TTE tepat di atas paragraf "(Nama)" pada setiap
     * kolom tanda tangan. Paragraf kosong yang semula disediakan untuk tanda
     * tangan manual dibuang, sehingga tinggi blok tidak membengkak.
     */
    private function fillTteImages($zip, $dom, $xp, SmkiReviewLog $log): void
    {
        $people = [
            [trim((string) $log->dibuat_oleh_nama), trim((string) $log->dibuat_oleh_nip)],
            [trim((string) $log->mengetahui_nama), trim((string) $log->mengetahui_nip)],
        ];

        $targets = [];
        foreach ($xp->query('//w:body//w:p') as $p) {
            if (strpos(trim($p->textContent), '(…') === 0) {
                $targets[] = $p;
            }
        }
        if ($targets === []) {
            return;
        }

        $resolver = app(TteResolverService::class);

        foreach ($targets as $i => $target) {
            [$nama, $nip] = $people[$i] ?? ['', ''];
            $path = $resolver->imagePath($nip, $nama);
            if ($path === null) {
                continue;
            }

            // Buang paragraf kosong di atas "(Nama)" — ruang tanda tangan manual.
            $prev = $target->previousSibling;
            while ($prev instanceof \DOMElement
                && $prev->localName === 'p'
                && trim($prev->textContent) === ''
                && !$this->hasChild($prev, 'drawing')) {
                $node = $prev;
                $prev = $prev->previousSibling;
                $node->parentNode->removeChild($node);
            }

            $imgPara = $this->tteImageParagraph($dom);
            if ($this->insertImageIntoParagraph($zip, $dom, $xp, $imgPara, $path, 150)) {
                $target->parentNode->insertBefore($imgPara, $target);
            }
        }
    }

    /** Paragraf kosong berisi gambar (dipakai sebagai wadah gambar TTE). */
    private function tteImageParagraph($dom, ?string $align = 'center')
    {
        $p = $dom->createElementNS(self::W_NS, 'w:p');
        $pPr = $dom->createElementNS(self::W_NS, 'w:pPr');

        $spacing = $dom->createElementNS(self::W_NS, 'w:spacing');
        $spacing->setAttributeNS(self::W_NS, 'w:before', '0');
        $spacing->setAttributeNS(self::W_NS, 'w:after', '60');
        $pPr->appendChild($spacing);

        if ($align !== null) {
            $jc = $dom->createElementNS(self::W_NS, 'w:jc');
            $jc->setAttributeNS(self::W_NS, 'w:val', $align);
            $pPr->appendChild($jc);
        }

        $p->appendChild($pPr);

        return $p;
    }

    private function fillSignature($dom, $xp, $dateRow, $signRow, SmkiReviewLog $log): void
    {
        // "Bandung, ……………" -> "Kota, tanggal"
        $di = trim((string) $log->ditandatangani_di) ?: 'Bandung';
        if ($log->tanggal_ttd || trim((string) $log->ditandatangani_di) !== '') {
            $tanggal = $log->tanggal_ttd ? $this->tanggalIndo($log->tanggal_ttd) : '……………………';
            $p = $xp->query('.//w:tc[last()]/w:p', $dateRow)->item(0);
            if ($p) {
                $this->setParagraphText($dom, $xp, $p, $di . ', ' . $tanggal);
            }
        }

        $cells = $xp->query('w:tc', $signRow);
        $people = [
            [$log->dibuat_oleh_nama, $log->dibuat_oleh_nip],
            [$log->mengetahui_nama, $log->mengetahui_nip],
        ];
        foreach ($people as $i => [$nama, $nip]) {
            if ($i >= $cells->length) {
                break;
            }
            $paras = $xp->query('w:p', $cells->item($i));
            $namePara = $paras->item($paras->length - 1);
            if (!$namePara) {
                continue;
            }
            $nama = trim((string) $nama);
            $nip = trim((string) $nip);
            if ($nama !== '') {
                $this->setParagraphText($dom, $xp, $namePara, '(' . $nama . ')');
            }
            if ($nip !== '') {
                $nipPara = $namePara->cloneNode(true);
                $this->setParagraphText($dom, $xp, $nipPara, 'NIP. ' . $nip);
                $namePara->parentNode->insertBefore($nipPara, $namePara->nextSibling);
            }
        }
    }
}
