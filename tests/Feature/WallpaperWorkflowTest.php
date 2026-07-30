<?php

namespace Tests\Feature;

use App\Jobs\GenerateCompositionProposal;
use App\Jobs\SyncWallpaperResultToNotion;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\Wallpaper;
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
                ->where('selectedDate', '')
                ->where('latestImport', null));
    }

    public function test_result_page_receives_latest_notion_import(): void
    {
        $user = User::factory()->create();
        SyncRun::query()->create(['type' => 'notion_import', 'status' => 'succeeded', 'created_at' => now()->subMinute()]);
        $latestImport = SyncRun::query()->create(['type' => 'notion_import', 'status' => 'queued']);

        $this->actingAs($user)->get('/results')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('results/index', false)
                ->where('latestImport.id', $latestImport->id)
                ->where('latestImport.status', 'queued'));
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

        $this->actingAs($user)->post('/wallpapers/proposals', ['target_date' => '2026-08-02'])
            ->assertRedirect();

        Queue::assertPushed(GenerateCompositionProposal::class, 1);
        $this->assertDatabaseHas('wallpapers', ['target_date' => '2026-08-02', 'state' => 'draft']);
    }

    public function test_vnd_must_be_non_negative_integer_and_zero_is_valid(): void
    {
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

    public function test_unauthenticated_download_is_rejected(): void
    {
        $wallpaper = Wallpaper::factory()->create();

        $this->get("/wallpapers/{$wallpaper->id}/download")->assertRedirect('/login');
    }
}
