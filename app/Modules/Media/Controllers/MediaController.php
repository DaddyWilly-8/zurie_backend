<?php

namespace App\Modules\Media\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Media\Models\Media;
use App\Modules\Media\Requests\StoreMediaRequest;
use App\Modules\Media\Resources\MediaResource;
use App\Modules\Media\Services\MediaService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly MediaService $mediaService) {}

    /**
     * Serves files on the "public" disk directly through Laravel instead of
     * relying on the public/storage symlink + the web server's own static
     * file handling. Added because both Apache (shared-hosting symlink
     * protection / FollowSymLinks restrictions) and PHP's built-in dev
     * server (which refuses to follow symlinks pointing outside its
     * docroot) were independently returning 403 on the symlinked path.
     *
     * Deliberately unauthenticated — these are the same files the symlink
     * already served publicly, just served by Laravel now instead of the
     * web server. `Storage::disk('public')->exists()`/`->path()` resolve
     * relative to the disk's own root (Flysystem normalizes and refuses to
     * escape it), so no manual `../` sanitization is needed here.
     */
    public function serve(string $path): BinaryFileResponse
    {
        if (! Storage::disk('public')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::disk('public')->path($path));
    }

    public function store(StoreMediaRequest $request)
    {
        $media = $this->mediaService->store(
            $request->file('file'),
            $request->validated()['folder'],
            $request->user()?->id,
        );

        return $this->created(new MediaResource($media));
    }

    public function index(Request $request)
    {
        $page = max(1, (int) $request->query('page', 1));
        $pageSize = max(1, (int) $request->query('pageSize', 20));

        $media = $this->mediaService->paginate($request->query('search'), $page, $pageSize);

        return $this->paginated(
            MediaResource::collection($media->items()),
            [
                'count' => $media->total(),
                'page' => $media->currentPage(),
                'pageSize' => $media->perPage(),
            ]
        );
    }

    public function destroy(Media $media)
    {
        $this->mediaService->delete($media);

        return $this->ok();
    }
}
