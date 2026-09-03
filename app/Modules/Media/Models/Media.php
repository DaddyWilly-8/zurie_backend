<?php

namespace App\Modules\Media\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['url', 'folder', 'original_filename', 'mime_type', 'size', 'uploaded_by'])]
class Media extends Model
{
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }
}
