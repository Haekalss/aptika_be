<?php

namespace App\Http\Requests\Smki;

use App\Models\SmkiBackupRestoreReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreBackupRestoreAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! Gate::allows('create', SmkiBackupRestoreReport::class)) {
            return false;
        }
        $report = SmkiBackupRestoreReport::visibleTo($this->user())->findOrFail($this->route('report'));
        $report->assets()->whereHas('entries', fn ($query) => $query->whereKey($this->route('entry')))->firstOrFail();

        return Gate::allows('update', $report);
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ];
    }
}
