<?php

namespace App\Modules\Media\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'folder' => $this->folder,
            'originalFilename' => $this->original_filename,
            'mimeType' => $this->mime_type,
            'size' => $this->size,
            'uploadedBy' => $this->uploaded_by,
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
