<?php

namespace Tests\Feature\Security;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Uploads can't smuggle a script onto the public disk: an image uploaded as
 * page.html / logo.svg / x.shtml used to be served back under that
 * extension (stored XSS on the API origin). Accepted files are now stored
 * under the extension of their real content, never the uploader's name.
 */
class UploadHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function upload(string $name)
    {
        Storage::fake('public');
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret123']);
        $admin->roles()->sync([Role::where('name', 'admin')->value('id')]);

        // A real temp file (not UploadedFile::fake()), so the server sniffs
        // the actual bytes the way it would for a browser upload.
        $tmp = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($tmp, base64_decode(self::PNG).'<script>alert(document.cookie)</script>');
        $file = new UploadedFile($tmp, $name, null, null, true);

        return $this->actingAs($admin)->post('/api/v1/media/upload', ['file' => $file, 'folder' => 'general'], ['Accept' => 'application/json']);
    }

    /** @return array<string, array{string}> */
    public static function scriptNames(): array
    {
        return [
            'php' => ['shell.php'], 'PHP' => ['shell.PHP'], 'phtml' => ['x.phtml'], 'html' => ['page.html'],
            'svg' => ['logo.svg'], 'double extension' => ['photo.png.html'], 'shtml' => ['x.shtml'], 'no extension' => ['noext'],
        ];
    }

    #[DataProvider('scriptNames')]
    public function test_an_image_disguised_under_a_script_name_is_never_stored_as_a_script(string $name): void
    {
        $status = $this->upload($name)->status();

        $this->assertContains($status, [201, 422]);
        foreach (Storage::disk('public')->allFiles() as $stored) {
            $this->assertStringEndsWith('.png', $stored);
        }
    }

    public function test_stored_extension_comes_from_the_content(): void
    {
        $this->upload('photo.jpg')->assertCreated();

        $files = Storage::disk('public')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.png', $files[0]);
    }
}
