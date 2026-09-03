<?php

use App\Modules\Media\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

// Lives outside routes/api.php (and its "api/v1" prefix) on purpose — the
// URLs MediaService::store() generates via Storage::disk('public')->url()
// are unprefixed "{APP_URL}/storage/{path}", matching the old symlink
// location exactly, so this route has to match that shape too. See
// MediaController::serve() for why this exists instead of the symlink.
Route::get('storage/{path}', [MediaController::class, 'serve'])->where('path', '.*');
