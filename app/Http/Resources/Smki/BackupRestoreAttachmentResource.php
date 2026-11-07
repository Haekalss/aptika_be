<?php

namespace App\Http\Resources\Smki;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BackupRestoreAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->only(['id', 'original_name', 'mime', 'size']);
    }
}
