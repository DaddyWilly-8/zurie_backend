<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The only table any uploaded file's storage details live in. Product
     * (product_images.url) and Category (categories.image_url) snapshot the
     * resolved `url` from here rather than holding a media_id FK — see
     * zurie-backend-implementation-spec.md's "resolved value snapshot"
     * decision. `uploaded_by` is a cross-module reference into Auth's
     * users table with no FK constraint, same pattern used elsewhere.
     */
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('url');
            $table->string('folder')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable(); // bytes
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index('url');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
