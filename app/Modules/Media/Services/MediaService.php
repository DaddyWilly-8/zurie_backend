<?php

namespace App\Modules\Media\Services;

use App\Modules\Media\Models\Media;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The only place in the system that writes a file to disk. Product and
 * Category call in here (via direct service injection, same pattern as
 * Product -> Inventory) to store an upload, then snapshot the returned
 * `url` onto their own tables instead of keeping a live media_id reference
 * — see zurie-backend-implementation-spec.md's "resolved value snapshot"
 * decision. This module has no knowledge of Product or Category.
 */
class MediaService
{
    /**
     * Defaults to the local 'public' disk (unchanged behavior) — set
     * MEDIA_DISK=s3 in .env to move uploads to object storage with no
     * code change; config/filesystems.php already has a real 's3' disk
     * definition reading the AWS_* vars. A single named disk, not
     * `Storage::disk(config('filesystems.default'))`, so a deploy can
     * change the *default* disk for other purposes without silently
     * moving where product/category images live too.
     */
    private function disk(): string
    {
        return config('filesystems.media_disk', 'public');
    }

    public function store(UploadedFile $file, string $folder, ?int $uploadedBy): Media
    {
        $filename = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();

        $path = $file->storeAs($folder, $filename, $this->disk());

        return Media::create([
            'url' => Storage::disk($this->disk())->url($path),
            'folder' => $folder,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $uploadedBy,
        ]);
    }

    public function paginate(?string $search, int $page, int $pageSize): LengthAwarePaginator
    {
        return Media::query()
            ->when($search, fn ($query) => $query->where('original_filename', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    public function delete(Media $media): void
    {
        $this->deleteFile($media->url);

        $media->delete();
    }

    /**
     * Used by Product/Category when an image is removed or replaced, so the
     * underlying file doesn't sit orphaned in storage (per the decision to
     * have that direction of deletion cascade). Silently no-ops if no
     * matching row is found — best-effort cleanup, not an invariant
     * Product/Category depend on.
     */
    public function deleteByUrl(string $url): void
    {
        $media = Media::query()->where('url', $url)->first();

        if ($media !== null) {
            $this->delete($media);
        }
    }

    private function deleteFile(string $url): void
    {
        $prefix = Storage::disk($this->disk())->url('');
        $relative = Str::after($url, $prefix);

        if ($relative !== $url && Storage::disk($this->disk())->exists($relative)) {
            Storage::disk($this->disk())->delete($relative);
        }
    }
}
