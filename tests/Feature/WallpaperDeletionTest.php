<?php

namespace Tests\Feature;

use App\Models\AnalysisSnapshot;
use App\Models\ApiRun;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\Wallpaper;
use App\Services\NotionClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WallpaperDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_local_image_can_be_deleted_while_history_is_retained(): void
    {
        config(['lucky.notion.token' => 'test']);
        Storage::fake('local');
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'notion_page_id' => 'notion-page-id',
            'image_disk' => 'local',
            'image_path' => 'wallpapers/image-only.jpg',
            'image_mime' => 'image/jpeg',
            'image_bytes' => 11,
            'image_sha256' => str_repeat('a', 64),
        ]);
        Storage::disk('local')->put('wallpapers/image-only.jpg', 'image-bytes');

        $this->actingAs($user)
            ->from("/wallpapers/{$wallpaper->id}")
            ->delete("/wallpapers/{$wallpaper->id}/image")
            ->assertRedirect("/wallpapers/{$wallpaper->id}")
            ->assertSessionHas('status', '画像ファイルを削除しました。履歴データは保持されています。');

        Storage::disk('local')->assertMissing('wallpapers/image-only.jpg');
        $this->assertDatabaseHas('wallpapers', [
            'id' => $wallpaper->id,
            'notion_page_id' => 'notion-page-id',
            'title' => $wallpaper->title,
            'image_disk' => null,
            'image_path' => null,
            'image_mime' => null,
            'image_bytes' => null,
            'image_sha256' => null,
        ]);
        Http::assertNothingSent();
    }

    public function test_missing_local_image_metadata_is_cleared_without_deleting_history(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'image_disk' => 'local',
            'image_path' => 'wallpapers/already-missing.jpg',
            'image_mime' => 'image/jpeg',
        ]);

        $this->actingAs($user)
            ->from("/wallpapers/{$wallpaper->id}")
            ->delete("/wallpapers/{$wallpaper->id}/image")
            ->assertRedirect("/wallpapers/{$wallpaper->id}")
            ->assertSessionHas('status', '画像ファイルは既に存在しません。履歴データは保持されています。');

        $this->assertDatabaseHas('wallpapers', [
            'id' => $wallpaper->id,
            'image_disk' => null,
            'image_path' => null,
            'image_mime' => null,
        ]);
    }

    public function test_local_image_cannot_be_deleted_while_a_process_is_active(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'image_disk' => 'local',
            'image_path' => 'wallpapers/keep-while-processing.jpg',
        ]);
        Storage::disk('local')->put('wallpapers/keep-while-processing.jpg', 'image-bytes');
        ApiRun::query()->create([
            'type' => 'image_generation',
            'model' => 'test-model',
            'prompt_version' => 'v1',
            'input_hash' => str_repeat('b', 64),
            'status' => 'running',
            'subject_type' => $wallpaper->getMorphClass(),
            'subject_id' => $wallpaper->id,
        ]);

        $this->actingAs($user)
            ->from("/wallpapers/{$wallpaper->id}")
            ->delete("/wallpapers/{$wallpaper->id}/image")
            ->assertRedirect("/wallpapers/{$wallpaper->id}")
            ->assertSessionHasErrors([
                'deleteImage' => '処理中の履歴は削除できません。処理完了後に再試行してください。',
            ]);

        Storage::disk('local')->assertExists('wallpapers/keep-while-processing.jpg');
        $this->assertDatabaseHas('wallpapers', [
            'id' => $wallpaper->id,
            'image_path' => 'wallpapers/keep-while-processing.jpg',
        ]);
    }

    public function test_wallpaper_history_deletes_database_records_image_and_notion_page(): void
    {
        config(['lucky.notion.token' => 'test']);
        Storage::fake('local');
        Http::fake([
            'api.notion.com/v1/pages/notion-page-id' => Http::response(['id' => 'notion-page-id']),
        ]);
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'notion_page_id' => 'notion-page-id',
            'image_disk' => 'local',
            'image_path' => 'wallpapers/delete-me.jpg',
        ]);
        Storage::disk('local')->put('wallpapers/delete-me.jpg', 'image-bytes');
        $proposal = $wallpaper->proposals()->create([
            'sequence' => 1,
            'status' => 'approved',
            'title' => '削除対象',
            'art_style' => '実写写真',
            'conclusion' => '結論',
            'overview' => '概要',
            'composition' => '配置',
            'color_wu_xing' => '色彩',
            'symbolism' => '象徴',
            'input_hash' => str_repeat('a', 64),
        ]);
        $apiRun = ApiRun::query()->create([
            'type' => 'image_generation',
            'model' => 'test-model',
            'prompt_version' => 'v1',
            'input_hash' => str_repeat('b', 64),
            'status' => 'succeeded',
            'subject_type' => $wallpaper->getMorphClass(),
            'subject_id' => $wallpaper->id,
        ]);
        $syncRun = SyncRun::query()->create([
            'wallpaper_id' => $wallpaper->id,
            'type' => 'notion_result',
            'status' => 'succeeded',
        ]);
        $snapshot = AnalysisSnapshot::query()->create([
            'data_hash' => str_repeat('c', 64),
            'prompt_version' => 'v1',
            'model' => 'test-model',
            'summary' => '削除前の分析',
            'status' => 'succeeded',
        ]);

        $this->actingAs($user)
            ->delete("/wallpapers/{$wallpaper->id}")
            ->assertRedirect('/wallpapers')
            ->assertSessionHas('status', '履歴を削除しました。');

        $this->assertDatabaseMissing('wallpapers', ['id' => $wallpaper->id]);
        $this->assertDatabaseMissing('composition_proposals', ['id' => $proposal->id]);
        $this->assertDatabaseMissing('api_runs', ['id' => $apiRun->id]);
        $this->assertDatabaseMissing('sync_runs', ['id' => $syncRun->id]);
        $this->assertDatabaseHas('analysis_snapshots', [
            'id' => $snapshot->id,
            'status' => 'invalidated',
        ]);
        Storage::disk('local')->assertMissing('wallpapers/delete-me.jpg');
        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PATCH'
                && $request->url() === 'https://api.notion.com/v1/pages/notion-page-id'
                && $request->data() === ['in_trash' => true];
        });
        Http::assertSentCount(1);
    }

    public function test_notion_failure_keeps_database_record_and_image(): void
    {
        config(['lucky.notion.token' => 'test']);
        Storage::fake('local');
        Http::fake([
            'api.notion.com/v1/pages/notion-page-id' => Http::response([], 403),
        ]);
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'notion_page_id' => 'notion-page-id',
            'image_disk' => 'local',
            'image_path' => 'wallpapers/keep-me.jpg',
        ]);
        Storage::disk('local')->put('wallpapers/keep-me.jpg', 'image-bytes');

        $this->actingAs($user)
            ->from('/wallpapers')
            ->delete("/wallpapers/{$wallpaper->id}")
            ->assertRedirect('/wallpapers')
            ->assertSessionHasErrors('delete');

        $this->assertDatabaseHas('wallpapers', ['id' => $wallpaper->id]);
        Storage::disk('local')->assertExists('wallpapers/keep-me.jpg');
        Http::assertSentCount(1);
    }

    public function test_wallpaper_with_active_process_cannot_be_deleted(): void
    {
        config(['lucky.notion.token' => 'test']);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'notion_page_id' => 'notion-page-id',
        ]);
        ApiRun::query()->create([
            'type' => 'image_generation',
            'model' => 'test-model',
            'prompt_version' => 'v1',
            'input_hash' => str_repeat('d', 64),
            'status' => 'running',
            'subject_type' => $wallpaper->getMorphClass(),
            'subject_id' => $wallpaper->id,
        ]);

        $this->actingAs($user)
            ->from('/wallpapers')
            ->delete("/wallpapers/{$wallpaper->id}")
            ->assertRedirect('/wallpapers')
            ->assertSessionHasErrors([
                'delete' => '処理中の履歴は削除できません。処理完了後に再試行してください。',
            ]);

        $this->assertDatabaseHas('wallpapers', ['id' => $wallpaper->id]);
        Http::assertNothingSent();
    }

    public function test_notion_page_can_be_restored_from_trash(): void
    {
        config(['lucky.notion.token' => 'test']);
        Http::fake([
            'api.notion.com/v1/pages/page-id' => Http::response(['id' => 'page-id']),
        ]);

        app(NotionClient::class)->restorePage('page-id');

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PATCH'
                && $request->data() === ['in_trash' => false]
                && $request->header('Notion-Version') === ['2026-03-11'];
        });
    }
}
