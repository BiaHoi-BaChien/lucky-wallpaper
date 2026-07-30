<?php

namespace Tests\Feature;

use App\Jobs\GenerateCompositionProposal;
use App\Jobs\SyncWallpaperResultToNotion;
use App\Models\AnalysisSnapshot;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\Wallpaper;
use App\Services\HistoricalAnalysisService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class WallpaperWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_target_date_is_tomorrow_in_ho_chi_minh(): void
    {
        CarbonImmutable::setTestNow('2026-07-29 23:30:00 Asia/Ho_Chi_Minh');
        $user = User::factory()->create();

        $this->actingAs($user)->get('/wallpapers/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('wallpapers/create', false)
                ->where('defaultDate', '2026-07-30'));
    }

    public function test_result_search_defaults_to_today_in_ho_chi_minh(): void
    {
        CarbonImmutable::setTestNow('2026-07-29 23:30:00 Asia/Ho_Chi_Minh');
        $user = User::factory()->create();

        $this->actingAs($user)->get('/results')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('results/index', false)
                ->where('defaultDate', '2026-07-29')
                ->where('selectedDate', ''));
    }

    public function test_notion_backup_settings_receives_latest_restore(): void
    {
        $user = User::factory()->create();
        SyncRun::query()->create(['type' => 'notion_import', 'status' => 'succeeded', 'created_at' => now()->subMinute()]);
        $latestRestore = SyncRun::query()->create(['type' => 'notion_import', 'status' => 'queued']);

        $this->actingAs($user)->get('/settings/notion-backup')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/notion-backup', false)
                ->where('latestRestore.id', $latestRestore->id)
                ->where('latestRestore.status', 'queued'));
    }

    public function test_existing_date_is_not_generated_again(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create(['target_date' => '2026-08-01']);

        $this->actingAs($user)->post('/wallpapers/proposals', ['target_date' => '2026-08-01'])
            ->assertRedirect('/wallpapers/'.$wallpaper->id);

        Queue::assertNotPushed(GenerateCompositionProposal::class);
        $this->assertDatabaseCount('wallpapers', 1);
    }

    public function test_valid_new_date_queues_one_proposal(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->createCurrentAnalysis();

        $this->actingAs($user)->post('/wallpapers/proposals', ['target_date' => '2026-08-02'])
            ->assertRedirect();

        Queue::assertPushed(GenerateCompositionProposal::class, 1);
        $this->assertDatabaseHas('wallpapers', ['target_date' => '2026-08-02', 'state' => 'draft']);
    }

    public function test_vnd_must_be_non_negative_integer_and_zero_is_valid(): void
    {
        config(['lucky.notion.token' => 'test']);
        Queue::fake();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create();

        $this->actingAs($user)->put("/wallpapers/{$wallpaper->id}/result", ['prize_vnd' => '-1'])
            ->assertSessionHasErrors('prize_vnd');
        $this->actingAs($user)->put("/wallpapers/{$wallpaper->id}/result", ['prize_vnd' => '1.5'])
            ->assertSessionHasErrors('prize_vnd');
        $this->actingAs($user)->put("/wallpapers/{$wallpaper->id}/result", ['prize_vnd' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('wallpapers', ['id' => $wallpaper->id, 'prize_vnd' => 0]);
        Queue::assertPushed(SyncWallpaperResultToNotion::class, 1);
    }

    public function test_result_is_saved_without_queuing_backup_when_notion_is_not_configured(): void
    {
        config(['lucky.notion.token' => null]);
        Queue::fake();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create(['prize_vnd' => null]);

        $this->actingAs($user)
            ->from('/results?date='.$wallpaper->target_date->format('Y-m-d'))
            ->put("/wallpapers/{$wallpaper->id}/result", ['prize_vnd' => '1000'])
            ->assertRedirect('/results?date='.$wallpaper->target_date->format('Y-m-d'))
            ->assertSessionHas(
                'status',
                '実績をサーバーに保存しました。Notionバックアップは未設定のため実行していません。',
            );

        $this->assertDatabaseHas('wallpapers', ['id' => $wallpaper->id, 'prize_vnd' => 1000]);
        $this->assertDatabaseCount('sync_runs', 0);
        Queue::assertNotPushed(SyncWallpaperResultToNotion::class);
    }

    public function test_unauthenticated_download_is_rejected(): void
    {
        $wallpaper = Wallpaper::factory()->create();

        $this->get("/wallpapers/{$wallpaper->id}/download")->assertRedirect('/login');
    }

    private function createCurrentAnalysis(): AnalysisSnapshot
    {
        return AnalysisSnapshot::query()->create([
            'data_hash' => app(HistoricalAnalysisService::class)->currentDataHash(),
            'prompt_version' => config('lucky.openai.prompt_version'),
            'model' => config('lucky.openai.text_model'),
            'summary' => "# 高額当選壁紙の傾向分析\n\nテスト分析",
            'status' => 'succeeded',
        ]);
    }
}
