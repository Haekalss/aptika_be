<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Http\Requests\Smki\PemantauanKonfigurasi\AjukanRequest;
use App\Http\Requests\Smki\PemantauanKonfigurasi\IdentifikasiRequest;
use App\Http\Requests\Smki\PemantauanKonfigurasi\KonfigurasiRequest;
use App\Http\Requests\Smki\PemantauanKonfigurasi\KembalikanRequest;
use App\Models\SmkiJenisKonfigurasi;
use App\Models\SmkiObjek;
use App\Models\SmkiPemantauan;
use App\Models\User;
use App\Services\Smki\SmkiPemantauanKonfigurasiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-048 — Pemantauan Manajemen Konfigurasi.
 * Phase 1+2: endpoint lookup & (via master) data master.
 * Phase 3: summary, list, detail, createDraft, identifikasi, konfigurasi, periksa.
 * Phase 4: ajukan / selesaikan / kembalikan / destroy menyusul.
 */
class SmkiPemantauanKonfigurasiController extends Controller
{
    // ───────────────────────────── Summary & List ─────────────────────────────
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $base = SmkiPemantauan::visibleTo($user);

        return response()->json([
            'success' => true,
            'data' => [
                'total' => (clone $base)->count(),
                'draft' => (clone $base)->where('status', 'draft')->count(),
                'diajukan' => (clone $base)->where('status', 'diajukan')->count(),
                'revisi' => (clone $base)->where('status', 'revisi')->count(),
                'selesai' => (clone $base)->where('status', 'selesai')->count(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $page = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(1, (int) $request->input('perPage', 10)));

        $query = SmkiPemantauan::visibleTo($user)
            ->with(['jenis:id,nama', 'pelaksana:id,name', 'admin:id,name', 'objeks']);

        $q = trim((string) $request->input('q', ''));
        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('no_formulir', 'like', "%{$q}%")
                    ->orWhereHas('objeks', fn ($o) => $o->where('objek_nama', 'like', "%{$q}%"));
            });
        }
        if ($request->filled('periode')) {
            $query->where('periode', (int) $request->input('periode'));
        }
        if ($request->filled('jenisId')) {
            $query->where('jenis_konfigurasi_id', (int) $request->input('jenisId'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $query->orderByDesc('updated_at')->orderByDesc('id');
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => collect($paginator->items())->map(fn ($p) => SmkiPemantauanKonfigurasiService::listItemResource($p))->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, int|string $id): JsonResponse
    {
        $p = $this->visibleOrFail($id, $request->user());

        return response()->json([
            'success' => true,
            'data' => SmkiPemantauanKonfigurasiService::detailResource($p),
        ]);
    }

    // ───────────────────────────── Create & Wizard ─────────────────────────────
    public function store(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'user') {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Pelaksana (user) yang dapat membuat pemantauan.',
            ], 403);
        }

        $p = SmkiPemantauanKonfigurasiService::createDraft($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Draft pemantauan dibuat.',
            'data' => SmkiPemantauanKonfigurasiService::detailResource($p),
        ], 201);
    }

    public function updateIdentifikasi(IdentifikasiRequest $request, int|string $id): JsonResponse
    {
        $p = $this->visibleOrFail($id, $request->user());

        if ($p->status !== 'draft') {
            return $this->conflict('Identifikasi hanya dapat diubah selama status Draft.');
        }
        if ($p->pelaksana_id !== $request->user()->id) {
            return $this->conflict('Anda bukan Pelaksana pemantauan ini.');
        }

        SmkiPemantauanKonfigurasiService::updateIdentifikasi($p, $request->validated());

        return $this->respondDetail($p->fresh(), 'Identifikasi tersimpan.');
    }

    public function saveKonfigurasi(KonfigurasiRequest $request, int|string $id): JsonResponse
    {
        $p = $this->visibleOrFail($id, $request->user());

        if (!in_array($p->status, ['draft', 'revisi'], true)) {
            return $this->conflict('Daftar konfigurasi hanya dapat diubah pada status Draft / Revisi.');
        }
        if ($p->pelaksana_id !== $request->user()->id) {
            return $this->conflict('Anda bukan Pelaksana pemantauan ini.');
        }

        foreach ($request->input('items', []) as $item) {
            if (!$p->objeks()->where('objek_id', (int) $item['objekId'])->exists()) {
                return response()->json([
                    'message' => 'Baris konfigurasi mengacu ke objek yang tidak terdaftar pada pemantauan ini.',
                    'errors' => ['items' => ['Objek tidak termasuk dalam pemantauan ini.']],
                ], 422);
            }
        }

        SmkiPemantauanKonfigurasiService::saveKonfigurasi($p, $request->input('items', []));

        return $this->respondDetail($p->fresh(), 'Daftar konfigurasi tersimpan.');
    }

    public function periksaKesesuaian(Request $request, int|string $id): JsonResponse
    {
        $p = $this->visibleOrFail($id, $request->user());

        if (!in_array($p->status, ['draft', 'revisi'], true)) {
            return $this->conflict('Pemeriksaan hanya dapat dilakukan pada status Draft / Revisi.');
        }
        if ($p->pelaksana_id !== $request->user()->id) {
            return $this->conflict('Anda bukan Pelaksana pemantauan ini.');
        }

        SmkiPemantauanKonfigurasiService::periksa($p);

        return $this->respondDetail($p->fresh(), 'Pemeriksaan kesesuaian selesai.');
    }

    public function ajukan(AjukanRequest $request, int|string $id): JsonResponse
    {
        $p = $this->visibleOrFail($id, $request->user());

        SmkiPemantauanKonfigurasiService::ajukan($p, $request->validated(), $request->user());

        return $this->respondDetail($p->fresh(), 'Pemantauan diajukan dan menunggu peninjauan Admin.');
    }

    public function selesaikan(Request $request, int|string $id): JsonResponse
    {
        $p = $this->visibleOrFail($id, $request->user());

        SmkiPemantauanKonfigurasiService::selesaikan($p, $request->user());

        return $this->respondDetail($p->fresh(), 'Pemantauan disetujui dan ditandatangani Admin.');
    }

    public function kembalikan(KembalikanRequest $request, int|string $id): JsonResponse
    {
        $p = $this->visibleOrFail($id, $request->user());

        SmkiPemantauanKonfigurasiService::kembalikan($p, $request->validated()['catatan'], $request->user());

        return $this->respondDetail($p->fresh(), 'Pemantauan dikembalikan untuk revisi.');
    }

    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $p = $this->visibleOrFail($id, $request->user());

        SmkiPemantauanKonfigurasiService::hapusDraft($p, $request->user());

        return response()->json(['success' => true, 'message' => 'Draft pemantauan dihapus.']);
    }

    // ───────────────────────────── Lookup ─────────────────────────────
    public function lookupJenis(): JsonResponse
    {
        $data = SmkiJenisKonfigurasi::where('is_active', true)
            ->orderBy('nama')
            ->get(['id', 'nama'])
            ->map(fn ($j) => ['id' => $j->id, 'nama' => $j->nama]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function lookupObjek(Request $request): JsonResponse
    {
        $request->validate(['jenis_id' => ['required', 'integer', 'exists:smki_jenis_konfigurasis,id']], [
            'jenis_id.required' => 'Parameter jenis_id wajib diisi.',
            'jenis_id.exists' => 'Jenis Konfigurasi tidak ditemukan.',
        ]);

        $data = SmkiObjek::where('is_active', true)
            ->where('jenis_konfigurasi_id', $request->integer('jenis_id'))
            ->orderBy('nama')
            ->get(['id', 'nama'])
            ->map(fn ($o) => ['id' => $o->id, 'nama' => $o->nama]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function lookupAdmins(): JsonResponse
    {
        $data = User::where('role', 'admin')
            ->where('is_active', 1)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'position'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'position' => $u->position,
            ]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ───────────────────────────── Guards & Helpers ─────────────────────────────
    private function visibleOrFail(int|string $id, User $user): SmkiPemantauan
    {
        $p = SmkiPemantauan::with(['jenis', 'pelaksana', 'admin'])
            ->visibleTo($user)
            ->findOrFail($id);

        return $p;
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 409);
    }

    private function respondDetail(SmkiPemantauan $p, string $message): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => SmkiPemantauanKonfigurasiService::detailResource($p),
        ]);
    }
}
