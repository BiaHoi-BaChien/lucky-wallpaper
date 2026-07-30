<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallpaper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NotionOptionalModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_history_is_deleted_without_calling_notion_when_token_is_not_configured(): void
    {
        config(['lucky.notion.token' => null]);
        Storage::fake('local');
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'notion_page_id' => 'notion-page-id',
            'image_disk' => 'local',
            'image_path' => 'wallpapers/delete-local.jpg',
        ]);
        Storage::disk('local')->put('wallpapers/delete-local.jpg', 'image-bytes');

        $this->actingAs($user)
            ->delete("/wallpapers/{$wallpaper->id}")
            ->assertRedirect('/wallpapers')
            ->assertSessionHas('status', '履歴を削除しました。');

        $this->assertDatabaseMissing('wallpapers', ['id' => $wallpaper->id]);
        Storage::disk('local')->assertMissing('wallpapers/delete-local.jpg');
        Http::assertNothingSent();
    }

    public function test_local_image_can_be_downloaded_without_notion_token(): void
    {
        config(['lucky.notion.token' => null]);
        Storage::fake('local');
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'state' => 'generated',
            'image_disk' => 'local',
            'image_path' => 'wallpapers/local.jpg',
        ]);
        Storage::disk('local')->put('wallpapers/local.jpg', 'image-bytes');

        $this->actingAs($user)
            ->get("/wallpapers/{$wallpaper->id}/download")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg');

        Http::assertNothingSent();
    }

    public function test_notion_only_image_returns_service_unavailable_without_token(): void
    {
        config(['lucky.notion.token' => null]);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'state' => 'archived',
            'image_disk' => null,
            'image_path' => null,
            'notion_page_id' => 'notion-page-id',
        ]);

        $this->actingAs($user)
            ->get("/wallpapers/{$wallpaper->id}/download")
            ->assertStatus(503);

        Http::assertNothingSent();
    }
}
