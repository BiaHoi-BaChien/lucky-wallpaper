<?php

namespace Tests\Unit;

use App\Services\ImageService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageServiceTest extends TestCase
{
    public function test_generated_image_is_stored_as_1440_by_2560_jpeg(): void
    {
        Storage::fake('local');
        config([
            'lucky.image.disk' => 'local',
            'lucky.image.width' => 1440,
            'lucky.image.height' => 2560,
        ]);

        $source = imagecreatetruecolor(20, 30);
        ob_start();
        imagepng($source);
        $bytes = ob_get_clean();
        imagedestroy($source);

        $stored = app(ImageService::class)->normalizeAndStore($bytes);
        $saved = Storage::disk('local')->get($stored['path']);
        $size = getimagesizefromstring($saved);

        $this->assertSame('image/jpeg', $stored['mime']);
        $this->assertSame(1440, $size[0]);
        $this->assertSame(2560, $size[1]);
        $this->assertSame(strlen($saved), $stored['bytes']);
    }
}
