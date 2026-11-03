<?php

namespace App\Http\Controllers\Smki;

use App\Http\Controllers\Controller;
use App\Http\Requests\Smki\PenyediaJasa\StorePenyediaJasaRequest;
use App\Models\SmkiEvaluasiPenyedia;
use App\Models\SmkiPenyediaJasa;
use App\Services\Smki\EvaluasiPenyediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SmkiPenyediaJasaController extends Controller
{
    public function __construct(private EvaluasiPenyediaService $service) {}

    public function lookup(Request $request)
    {
        Gate::authorize('viewAny', SmkiEvaluasiPenyedia::class);
        $query = trim((string) $request->query('q', ''));
        $items = $this->service->lookupPenyedia($query === '' ? null : $query);

        return response()->json(['data' => $items->map(fn (SmkiPenyediaJasa $item) => [
            'id' => (int) $item->id,
            'nama_penyedia' => $item->nama_penyedia,
            'contact_person' => $item->contact_person,
            'email' => $item->email,
        ])->values()]);
    }

    public function store(StorePenyediaJasaRequest $request)
    {
        Gate::authorize('viewAny', SmkiEvaluasiPenyedia::class);
        $item = $this->service->createPenyedia($request->validated());

        return response()->json(['data' => [
            'id' => (int) $item->id,
            'nama_penyedia' => $item->nama_penyedia,
            'contact_person' => $item->contact_person,
            'email' => $item->email,
        ]], 201);
    }
}
