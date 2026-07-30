<?php

namespace Tests\Feature;

use App\Jobs\ProcessNotionSync;
use App\Jobs\SyncWallpaperResultToNotion;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\Wallpaper;
use App\Services\ImageService;
use App\Services\NotionClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Tests\TestCase;

class NotionBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_shares_notion_configuration_without_exposing_token(): void
    {
        config(['lucky.notion.token' => 'secret-test-token']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/settings/notion-backup')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/notion-backup', false)
                ->where('integrations.notion.configured', true)
                ->where('latestRestore', null)
                ->missing('integrations.notion.token'));
    }

    public function test_restore_is_rejected_without_creating_a_run_when_notion_is_not_configured(): void
    {
        config(['lucky.notion.token' => null]);
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/notion-backup')
            ->post('/notion-syncs')
            ->assertRedirect('/settings/notion-backup')
            ->assertSessionHasErrors([
                'restore' => 'NOTION_TOKENが未設定のため、バックアップから復元できません。',
            ]);

        $this->assertDatabaseCount('sync_runs', 0);
        Queue::assertNotPushed(ProcessNotionSync::class);
    }

    public function test_restore_queues_the_existing_import_job_when_notion_is_configured(): void
    {
        config(['lucky.notion.token' => 'test']);
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/notion-backup')
            ->post('/notion-syncs')
            ->assertRedirect('/settings/notion-backup')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('operationId');

        $run = SyncRun::query()->sole();
        $this->assertSame('notion_import', $run->type);
        Queue::assertPushed(
            ProcessNotionSync::class,
            fn (ProcessNotionSync $job): bool => $job->runId === $run->id,
        );
    }

    public function test_successful_backup_deletes_local_image_when_configured(): void
    {
        config(['lucky.image.delete_after_notion_backup' => true]);

        [$wallpaper, $run, $notion, $images] = $this->prepareWallpaperBackup();

        (new SyncWallpaperResultToNotion($wallpaper->id, $run->id))->handle($notion, $images);

        Storage::disk('local')->assertMissing('wallpapers/backup.jpg');
        $this->assertDatabaseHas('wallpapers', [
            'id' => $wallpaper->id,
            'image_disk' => null,
            'image_path' => null,
            'image_bytes' => null,
            'state' => 'archived',
        ]);
    }

    public function test_successful_backup_keeps_local_image_when_deletion_is_disabled(): void
    {
        config(['lucky.image.delete_after_notion_backup' => false]);

        [$wallpaper, $run, $notion, $images] = $this->prepareWallpaperBackup();

        (new SyncWallpaperResultToNotion($wallpaper->id, $run->id))->handle($notion, $images);

        Storage::disk('local')->assertExists('wallpapers/backup.jpg');
        $this->assertDatabaseHas('wallpapers', [
            'id' => $wallpaper->id,
            'image_disk' => 'local',
            'image_path' => 'wallpapers/backup.jpg',
            'image_bytes' => 11,
            'state' => 'result_synced',
        ]);
    }

    /**
     * @return array{Wallpaper, SyncRun, NotionClient, ImageService}
     */
    private function prepareWallpaperBackup(): array
    {
        Storage::fake('local');
        Storage::disk('local')->put('wallpapers/backup.jpg', 'image-bytes');

        $wallpaper = Wallpaper::factory()->create([
            'target_date' => '2026-07-30',
            'notion_page_id' => 'page-id',
            'prize_vnd' => 1000,
            'image_disk' => 'local',
            'image_path' => 'wallpapers/backup.jpg',
            'image_bytes' => 11,
        ]);
        $run = SyncRun::query()->create([
            'wallpaper_id' => $wallpaper->id,
            'type' => 'notion_result',
        ]);

        $notion = Mockery::mock(NotionClient::class);
        $notion->shouldReceive('uploadFile')->once()->with('image-bytes', 'backup.jpg')->andReturn('upload-id');
        $notion->shouldReceive('updateResult')->once()->with('page-id', 1000, 'upload-id', 'backup.jpg');
        $notion->shouldReceive('getPage')->once()->with('page-id')->andReturn(['id' => 'page-id']);
        $notion->shouldReceive('parseCandidate')->once()->with(['id' => 'page-id'])->andReturn([
            'target_date' => '2026-07-30',
            'price_vnd' => 1000,
        ]);
        $notion->shouldReceive('wallpaperFileUrl')
            ->once()
            ->with(['id' => 'page-id'])
            ->andReturn('https://example.com/backup.jpg');

        $images = Mockery::mock(ImageService::class);
        $images->shouldReceive('fitForNotion')->once()->with('image-bytes')->andReturn('image-bytes');

        return [$wallpaper, $run, $notion, $images];
    }
}
