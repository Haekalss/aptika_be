<?php

namespace App\Http\Requests\Smki;

use App\Models\SmkiBackupRestoreAttachment;
use App\Models\SmkiBackupRestoreReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveBackupRestoreReportRequest extends FormRequest
{
    private ?SmkiBackupRestoreReport $currentReport = null;

    public function report(): ?SmkiBackupRestoreReport
    {
        return $this->route('report') ? ($this->currentReport ??= SmkiBackupRestoreReport::visibleTo($this->user())->findOrFail($this->route('report'))) : null;
    }

    public function authorize(): bool
    {
        if (! Gate::allows('create', SmkiBackupRestoreReport::class)) {
            return false;
        }

        return ! $this->report() || Gate::allows('update', $this->report());
    }

    public function rules(): array
    {
        $final = $this->report()?->status === 'final' || $this->boolean('finalize');
        $required = $final ? 'required' : 'nullable';
        $rules = [
            'version' => [$this->report() ? 'required' : 'prohibited', 'integer', 'min:1'],
            'tahun' => ['required', 'integer', 'between:1900,2100'],
            'nomor_dokumen' => [$required, 'string', 'max:255'],
            'nomor_revisi' => [$required, 'string', 'max:30'],
            'tanggal_terbit' => [$required, 'date_format:Y-m-d'],
            'finalize' => ['sometimes', 'boolean'],
            'refresh_signatories' => [Rule::prohibitedIf($this->report()?->status === 'final'), 'sometimes', 'array', 'list', 'max:2'],
            'refresh_signatories.*' => ['required', Rule::in(['pelaksana', 'penanggung_jawab']), 'distinct'],
            'assets' => ['present', 'array', 'list', 'max:50', $final ? 'min:1' : 'min:0'],
            'assets.*' => ['array:id,client_key,urutan,aset_ti_id,virtual_instance_id,kode_aset,nama_aset,entries'],
            // Ownership is checked against the report's batched children inside the save transaction.
            'assets.*.id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'assets.*.client_key' => ['required', 'uuid', 'distinct'],
            'assets.*.urutan' => ['sometimes', 'integer'],
            'assets.*.aset_ti_id' => ['nullable', 'integer', 'min:1'],
            'assets.*.virtual_instance_id' => ['nullable', 'integer', 'min:1'],
            'assets.*.nama_aset' => [$required, 'string', 'max:255'],
            'assets.*.kode_aset' => ['nullable', 'string', 'max:255'],
            'assets.*.entries' => ['present', 'array', 'list', 'max:24'],
            'assets.*.entries.*' => ['array:id,month,operation,result,performed_on,notes'],
            'assets.*.entries.*.id' => ['nullable', 'integer', 'min:1', 'distinct'],
            'assets.*.entries.*.month' => ['required', 'integer', 'between:1,12'],
            'assets.*.entries.*.operation' => ['required', Rule::in(['backup', 'restore'])],
            'assets.*.entries.*.result' => ['nullable', Rule::in(['berhasil', 'gagal'])],
            'assets.*.entries.*.performed_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Jakarta')->toDateString()],
            'assets.*.entries.*.notes' => ['nullable', 'string', 'max:2000'],
        ];
        foreach (['pelaksana', 'penanggung_jawab'] as $role) {
            $id = $role.'_pegawai_id';
            $historical = $this->report()?->{$role.'_nama'} && $this->input($id) == $this->report()?->{$id};
            $rules[$id] = [$final && ! $historical ? 'required' : 'nullable', 'integer', 'min:1', Rule::exists('pegawai', 'id')];
            foreach (['nama', 'nip', 'jabatan', 'pangkat'] as $field) {
                $rules[$role.'_'.$field] = ['prohibited'];
            }
        }
        foreach (['bidang_id', 'created_by', 'updated_by', 'status', 'unit_kerja_nama', 'finalized_at'] as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $final = $this->report()?->status === 'final' || $this->boolean('finalize');
            $complete = 0;
            $withEvidence = $final && $this->report()
                ? SmkiBackupRestoreAttachment::whereHas('entry.asset', fn ($query) => $query->where('report_id', $this->report()->id))->pluck('entry_id')->flip()
                : collect();
            foreach ($this->input('assets', []) as $a => $asset) {
                $seen = [];
                foreach ($asset['entries'] as $e => $entry) {
                    $key = $entry['month'].':'.$entry['operation'];
                    $prefix = "assets.$a.entries.$e";
                    if (isset($seen[$key])) {
                        $validator->errors()->add($prefix.'.operation', 'Kegiatan bulan ini sudah ada.');
                    }
                    $seen[$key] = true;
                    $date = $entry['performed_on'] ?? null;
                    if ($date && (substr($date, 0, 4) != $this->input('tahun') || (int) substr($date, 5, 2) !== (int) $entry['month'])) {
                        $validator->errors()->add($prefix.'.performed_on', 'Tanggal harus sesuai tahun laporan dan bulan kegiatan.');
                    }
                    if (! empty($entry['result']) && $date) {
                        $complete++;
                    } elseif ($final && (filled($entry['result'] ?? null) || $date || filled($entry['notes'] ?? null) || $withEvidence->has($entry['id'] ?? 0))) {
                        $validator->errors()->add($prefix.'.performed_on', 'Lengkapi hasil dan tanggal kegiatan sebelum Final.');
                    }
                }
            }
            if ($final && $complete === 0) {
                $validator->errors()->add('assets', 'Minimal satu kegiatan lengkap diperlukan untuk Final.');
            }
        }];
    }
}
