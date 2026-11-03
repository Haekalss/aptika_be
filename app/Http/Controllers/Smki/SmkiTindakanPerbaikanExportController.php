<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Http\Resources\Smki\SmkiTindakanPerbaikanResource;
use App\Models\SmkiTindakanPerbaikan;
use App\Services\Documents\DocxToPdfService;
use App\Services\Smki\SmkiTindakanPerbaikanDocxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SmkiTindakanPerbaikanExportController extends Controller
{
    public function __construct(
        private readonly SmkiTindakanPerbaikanDocxService $docxService,
        private readonly DocxToPdfService $pdfService,
    ) {}

    public function docx(SmkiTindakanPerbaikan $tindakanPerbaikan): BinaryFileResponse
    {
        Gate::authorize('export', $tindakanPerbaikan);
        $tindakanPerbaikan->loadMissing('items');
        $path = $this->docxService->generateDocx($tindakanPerbaikan);

        return response()->download($path, $this->fileName($tindakanPerbaikan, 'docx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }

    public function pdf(SmkiTindakanPerbaikan $tindakanPerbaikan): BinaryFileResponse
    {
        Gate::authorize('export', $tindakanPerbaikan);
        $this->pdfService->assertAvailable();
        $tindakanPerbaikan->loadMissing('items');
        $docxPath = $this->docxService->generateDocx($tindakanPerbaikan);
        $pdfPath = $this->pdfService->convert($docxPath);

        return response()->download($pdfPath, $this->fileName($tindakanPerbaikan, 'pdf'), [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend(true);
    }

    public function pdfData(SmkiTindakanPerbaikan $tindakanPerbaikan): JsonResponse
    {
        Gate::authorize('export', $tindakanPerbaikan);

        return (new SmkiTindakanPerbaikanResource($tindakanPerbaikan->loadMissing('items')))
            ->response()->header('Cache-Control', 'private, no-store');
    }

    private function fileName(SmkiTindakanPerbaikan $report, string $extension): string
    {
        $number = preg_replace('/[^a-zA-Z0-9_-]/', '_', $report->nomor_laporan ?: (string) $report->id);

        return 'FR-009_'.$number.'.'.$extension;
    }
}
