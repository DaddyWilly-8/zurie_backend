<?php

namespace Tests\Feature\Media;

use App\Modules\Media\Models\Media;
use App\Modules\Media\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File as TestingFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_writes_the_file_to_the_configured_disk_and_records_it(): void
    {
        Storage::fake('public');
        $file = TestingFile::create('logo.png', 10);

        $media = app(MediaService::class)->store($file, 'products', null);

        Storage::disk('public')->assertExists(str_replace(Storage::disk('public')->url(''), '', $media->url));
        $this->assertDatabaseHas('media', ['id' => $media->id, 'folder' => 'products']);
    }

    public function test_store_respects_a_configured_media_disk_override(): void
    {
        config(['filesystems.media_disk' => 'local']);
        Storage::fake('local');
        $file = TestingFile::create('logo.png', 10);

        app(MediaService::class)->store($file, 'products', null);

        $this->assertNotEmpty(Storage::disk('local')->allFiles('products'));
    }

    public function test_delete_removes_both_the_file_and_the_record(): void
    {
        Storage::fake('public');
        $file = TestingFile::create('logo.png', 10);
        $service = app(MediaService::class);
        $media = $service->store($file, 'products', null);
        $path = str_replace(Storage::disk('public')->url(''), '', $media->url);

        $service->delete($media->fresh());

        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_delete_by_url_is_a_silent_no_op_for_an_unknown_url(): void
    {
        app(MediaService::class)->deleteByUrl('https://example.com/nonexistent.png');

        $this->addToAssertionCount(1); // reaching here without an exception is the assertion
    }
}
