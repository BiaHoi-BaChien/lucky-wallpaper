<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallpaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WallpaperImageRestoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_image_is_restored_from_notion_to_local_storage(): void
    {
        config([
            'lucky.notion.token' => 'test',
            'lucky.image.disk' => 'local',
            'lucky.image.width' => 20,
            'lucky.image.height' => 30,
        ]);
        Storage::fake('local');
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'state' => 'archived',
            'notion_page_id' => 'notion-page-id',
            'image_disk' => null,
            'image_path' => null,
        ]);
        $fileUrl = 'https://files.example.com/notion-wallpaper.png';
        Http::fake([
            'api.notion.com/v1/pages/notion-page-id' => Http::response($this->notionPage($fileUrl)),
            $fileUrl => Http::response($this->pngBytes(), 200, ['Content-Type' => 'image/png']),
        ]);

        $this->actingAs($user)
            ->from("/wallpapers/{$wallpaper->id}")
            ->post("/wallpapers/{$wallpaper->id}/restore-image")
            ->assertRedirect("/wallpapers/{$wallpaper->id}")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Notionから画像ファイルを復元しました。');

        $wallpaper->refresh();
        $this->assertSame('archived', $wallpaper->state);
        $this->assertSame('local', $wallpaper->image_disk);
        $this->assertNotNull($wallpaper->image_path);
        $this->assertSame('image/jpeg', $wallpaper->image_mime);
        Storage::disk('local')->assertExists($wallpaper->image_path);
        $size = getimagesizefromstring(Storage::disk('local')->get($wallpaper->image_path));
        $this->assertIsArray($size);
        $this->assertSame([20, 30], array_slice($size, 0, 2));
        Http::assertSentCount(2);
    }

    public function test_restore_returns_error_when_notion_page_has_no_image_file(): void
    {
        config(['lucky.notion.token' => 'test']);
        Storage::fake('local');
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'notion_page_id' => 'notion-page-id',
            'image_disk' => null,
            'image_path' => null,
        ]);
        Http::fake([
            'api.notion.com/v1/pages/notion-page-id' => Http::response($this->notionPage()),
        ]);

        $this->actingAs($user)
            ->from("/wallpapers/{$wallpaper->id}")
            ->post("/wallpapers/{$wallpaper->id}/restore-image")
            ->assertRedirect("/wallpapers/{$wallpaper->id}")
            ->assertSessionHasErrors([
                'restoreImage' => 'Notionバックアップに画像ファイルがありません。',
            ]);

        $this->assertNull($wallpaper->refresh()->image_path);
        Http::assertSentCount(1);
    }

    public function test_restore_returns_error_when_notion_image_url_is_missing(): void
    {
        config(['lucky.notion.token' => 'test']);
        Storage::fake('local');
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'notion_page_id' => 'notion-page-id',
            'image_disk' => null,
            'image_path' => null,
        ]);
        $fileUrl = 'https://files.example.com/missing-wallpaper.jpg';
        Http::fake([
            'api.notion.com/v1/pages/notion-page-id' => Http::response($this->notionPage($fileUrl)),
            $fileUrl => Http::response([], 404),
        ]);

        $this->actingAs($user)
            ->from("/wallpapers/{$wallpaper->id}")
            ->post("/wallpapers/{$wallpaper->id}/restore-image")
            ->assertRedirect("/wallpapers/{$wallpaper->id}")
            ->assertSessionHasErrors([
                'restoreImage' => 'Notionバックアップに画像ファイルがありません。',
            ]);

        $this->assertNull($wallpaper->refresh()->image_path);
        Http::assertSentCount(2);
    }

    public function test_restore_returns_error_when_notion_backup_is_not_linked(): void
    {
        config(['lucky.notion.token' => 'test']);
        Storage::fake('local');
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'notion_page_id' => null,
            'image_disk' => null,
            'image_path' => null,
        ]);

        $this->actingAs($user)
            ->from("/wallpapers/{$wallpaper->id}")
            ->post("/wallpapers/{$wallpaper->id}/restore-image")
            ->assertRedirect("/wallpapers/{$wallpaper->id}")
            ->assertSessionHasErrors([
                'restoreImage' => 'Notionバックアップに画像ファイルがありません。',
            ]);

        Http::assertNothingSent();
    }

    /** @return array<string, mixed> */
    private function notionPage(?string $fileUrl = null): array
    {
        $files = $fileUrl === null
            ? []
            : [[
                'type' => 'file',
                'name' => 'wallpaper.jpg',
                'file' => ['url' => $fileUrl],
            ]];

        return [
            'id' => 'notion-page-id',
            'properties' => [
                config('lucky.notion.property_wallpaper') => [
                    'files' => $files,
                ],
            ],
        ];
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(10, 15);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        $this->assertIsString($bytes);

        return $bytes;
    }
}
