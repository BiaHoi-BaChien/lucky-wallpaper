<?php

namespace Tests\Feature;

use App\Jobs\GenerateCompositionProposal;
use App\Jobs\GenerateHistoricalAnalysis;
use App\Jobs\GenerateWallpaperImage;
use App\Models\AnalysisSnapshot;
use App\Models\ApiRun;
use App\Models\User;
use App\Models\Wallpaper;
use App\Services\HistoricalAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ManualWallpaperWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_analysis_can_be_saved_and_overwritten_without_api_usage(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $user = User::factory()->create();
        Wallpaper::factory()->create(['prize_vnd' => 1_000_000]);

        $prompt = $this->actingAs($user)
            ->getJson('/wallpaper-analyses/manual-prompt')
            ->assertOk()
            ->assertJsonStructure(['prompt', 'prompt_hash', 'context_hash', 'filename'])
            ->json();
        $this->assertStringContainsString('画面に表示するとともに', $prompt['prompt']);
        $this->assertStringContainsString('wallpaper-analysis.md', $prompt['prompt']);

        $this->actingAs($user)
            ->post('/wallpaper-analyses/manual-result', [
                'analysis_markdown' => "## 初回分析\n\n- 中央配置",
                'data_hash' => $prompt['context_hash'],
                'prompt_hash' => $prompt['prompt_hash'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $snapshot = AnalysisSnapshot::query()->sole();
        $this->assertSame('chatgpt-manual', $snapshot->model);
        $this->assertStringContainsString('初回分析', $snapshot->summary);

        $this->actingAs($user)
            ->post('/wallpaper-analyses/manual-result', [
                'analysis_markdown' => "## 再分析\n\n- 左右非対称",
                'data_hash' => $prompt['context_hash'],
                'prompt_hash' => $prompt['prompt_hash'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('analysis_snapshots', 1);
        $this->assertStringContainsString('再分析', $snapshot->refresh()->summary);
        $this->assertDatabaseCount('api_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_stale_manual_analysis_prompt_is_rejected(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create(['prize_vnd' => 1_000_000]);
        $prompt = $this->actingAs($user)->getJson('/wallpaper-analyses/manual-prompt')->json();
        $wallpaper->update(['prize_vnd' => 2_000_000]);

        $this->actingAs($user)
            ->post('/wallpaper-analyses/manual-result', [
                'analysis_markdown' => '# 古い分析',
                'data_hash' => $prompt['context_hash'],
                'prompt_hash' => $prompt['prompt_hash'],
            ])
            ->assertSessionHasErrors('analysis_markdown');

        $this->assertDatabaseCount('analysis_snapshots', 0);
        $this->assertDatabaseCount('api_runs', 0);
    }

    public function test_empty_manual_analysis_uses_server_default_without_chatgpt(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $prompt = $this->actingAs($user)
            ->getJson('/wallpaper-analyses/manual-prompt')
            ->assertOk()
            ->assertJsonPath('default_result', fn (string $value): bool => str_contains($value, '壁紙履歴はまだありません'))
            ->json();

        $this->actingAs($user)
            ->post('/wallpaper-analyses/manual-result', [
                'analysis_markdown' => '',
                'data_hash' => $prompt['context_hash'],
                'prompt_hash' => $prompt['prompt_hash'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertStringContainsString('壁紙履歴はまだありません', AnalysisSnapshot::query()->sole()->summary);
        $this->assertDatabaseCount('api_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_manual_initial_proposal_accepts_fenced_json_without_api_usage(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $this->createCurrentAnalysis();

        $prompt = $this->actingAs($user)
            ->getJson('/wallpapers/proposals/manual-prompt?target_date=2026-08-10')
            ->assertOk()
            ->json();
        $this->assertStringContainsString('画面に表示するとともに', $prompt['prompt']);
        $this->assertStringContainsString('wallpaper-composition-2026-08-10.json', $prompt['prompt']);
        $json = "```json\n".json_encode($this->proposalPayload('手動の黄金庭園'), JSON_UNESCAPED_UNICODE)."\n```";

        $this->actingAs($user)
            ->post('/wallpapers/proposals/manual-result', [
                'target_date' => '2026-08-10',
                'proposal_json' => $json,
                'prompt_hash' => $prompt['prompt_hash'],
            ])
            ->assertRedirect('/wallpapers/1')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('wallpapers', [
            'target_date' => '2026-08-10',
            'title' => '手動の黄金庭園',
            'state' => 'proposed',
        ]);
        $this->assertDatabaseHas('composition_proposals', [
            'title' => '手動の黄金庭園',
            'sequence' => 1,
            'status' => 'proposed',
        ]);
        $this->assertDatabaseCount('api_runs', 0);
        Queue::assertNotPushed(GenerateCompositionProposal::class);
    }

    public function test_manual_reproposal_rejects_previous_proposal(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->createCurrentAnalysis();
        $wallpaper = Wallpaper::factory()->create([
            'target_date' => '2026-08-11',
            'state' => 'proposed',
            'image_disk' => null,
            'image_path' => null,
        ]);
        $previous = $wallpaper->proposals()->create([
            ...$this->proposalPayload('以前の案'),
            'sequence' => 1,
            'status' => 'proposed',
            'input_hash' => str_repeat('a', 64),
        ]);
        $prompt = $this->actingAs($user)
            ->getJson("/wallpapers/{$wallpaper->id}/proposals/manual-prompt")
            ->assertOk()
            ->json();

        $this->actingAs($user)
            ->post("/wallpapers/{$wallpaper->id}/proposals/manual-result", [
                'proposal_json' => json_encode($this->proposalPayload('新しい案'), JSON_UNESCAPED_UNICODE),
                'prompt_hash' => $prompt['prompt_hash'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('rejected', $previous->refresh()->status);
        $this->assertDatabaseHas('composition_proposals', [
            'wallpaper_id' => $wallpaper->id,
            'title' => '新しい案',
            'sequence' => 2,
            'status' => 'proposed',
        ]);
        $this->assertDatabaseCount('api_runs', 0);
    }

    public function test_invalid_manual_proposal_json_does_not_create_wallpaper(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->createCurrentAnalysis();
        $prompt = $this->actingAs($user)
            ->getJson('/wallpapers/proposals/manual-prompt?target_date=2026-08-14')
            ->assertOk()
            ->json();

        $this->actingAs($user)
            ->post('/wallpapers/proposals/manual-result', [
                'target_date' => '2026-08-14',
                'proposal_json' => '{not-json}',
                'prompt_hash' => $prompt['prompt_hash'],
            ])
            ->assertSessionHasErrors('proposal_json');

        $this->assertDatabaseCount('wallpapers', 0);
        $this->assertDatabaseCount('composition_proposals', 0);
        Queue::assertNothingPushed();
    }

    public function test_manual_image_is_stored_without_loading_prompt_or_using_api(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'target_date' => '2026-08-12',
            'state' => 'proposed',
            'image_disk' => null,
            'image_path' => null,
        ]);
        $proposal = $wallpaper->proposals()->create([
            ...$this->proposalPayload('画像用の案'),
            'sequence' => 1,
            'status' => 'proposed',
            'input_hash' => str_repeat('b', 64),
        ]);
        $this->actingAs($user)
            ->post("/wallpapers/{$wallpaper->id}/image/manual-result", [
                'proposal_id' => $proposal->id,
                'image' => UploadedFile::fake()->image('chatgpt.png', 900, 1600),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $wallpaper->refresh();
        Storage::disk('local')->assertExists($wallpaper->image_path);
        $size = getimagesizefromstring(Storage::disk('local')->get($wallpaper->image_path));
        $this->assertSame([1440, 2560], [$size[0], $size[1]]);
        $this->assertSame('generated', $wallpaper->state);
        $this->assertSame('approved', $proposal->refresh()->status);
        $this->assertDatabaseCount('api_runs', 0);
        Queue::assertNotPushed(GenerateWallpaperImage::class);
    }

    public function test_manual_image_rejects_an_invalid_optional_prompt_hash(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'state' => 'proposed',
            'image_disk' => null,
            'image_path' => null,
        ]);
        $proposal = $wallpaper->proposals()->create([
            ...$this->proposalPayload('画像用の案'),
            'sequence' => 1,
            'status' => 'proposed',
            'input_hash' => str_repeat('b', 64),
        ]);

        $this->actingAs($user)
            ->post("/wallpapers/{$wallpaper->id}/image/manual-result", [
                'proposal_id' => $proposal->id,
                'prompt_hash' => str_repeat('0', 64),
                'image' => UploadedFile::fake()->image('chatgpt.png', 900, 1600),
            ])
            ->assertSessionHasErrors('image');

        $this->assertNull($wallpaper->refresh()->image_path);
    }

    public function test_manual_image_rejects_proposal_from_another_wallpaper(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create(['image_disk' => null, 'image_path' => null]);
        $other = Wallpaper::factory()->create();
        $proposal = $other->proposals()->create([
            ...$this->proposalPayload('別壁紙の案'),
            'sequence' => 1,
            'status' => 'proposed',
            'input_hash' => str_repeat('c', 64),
        ]);

        $this->actingAs($user)
            ->getJson("/wallpapers/{$wallpaper->id}/image/manual-prompt?proposal_id={$proposal->id}")
            ->assertNotFound();

        $this->assertNull($wallpaper->refresh()->image_path);
    }

    public function test_openai_endpoints_require_explicit_confirmation(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'image_disk' => null,
            'image_path' => null,
        ]);

        $this->actingAs($user)->post('/wallpaper-analyses')->assertSessionHasErrors('api_confirmed');
        $this->actingAs($user)
            ->post('/wallpapers/proposals', ['target_date' => '2026-08-13'])
            ->assertSessionHasErrors('api_confirmed');
        $this->actingAs($user)
            ->post("/wallpapers/{$wallpaper->id}/image")
            ->assertSessionHasErrors('api_confirmed');

        $this->assertDatabaseCount('api_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_failed_api_reanalysis_keeps_previous_successful_result(): void
    {
        $snapshot = $this->createCurrentAnalysis();
        $originalSummary = $snapshot->summary;
        $run = ApiRun::query()->create([
            'type' => 'historical_analysis',
            'model' => config('lucky.openai.text_model'),
            'prompt_version' => config('lucky.openai.prompt_version'),
            'input_hash' => $snapshot->data_hash,
            'subject_type' => $snapshot->getMorphClass(),
            'subject_id' => $snapshot->id,
        ]);
        $job = new GenerateHistoricalAnalysis($snapshot->id, $run->id, true);

        $job->failed(new \RuntimeException('failed'));

        $this->assertSame('succeeded', $snapshot->refresh()->status);
        $this->assertSame($originalSummary, $snapshot->summary);
        $this->assertSame('failed', $run->refresh()->status);
    }

    private function createCurrentAnalysis(): AnalysisSnapshot
    {
        return AnalysisSnapshot::query()->create([
            'data_hash' => app(HistoricalAnalysisService::class)->currentDataHash(),
            'prompt_version' => config('lucky.openai.prompt_version'),
            'model' => config('lucky.openai.text_model'),
            'summary' => "# 高額当選壁紙の傾向分析\n\n- テスト",
            'statistics' => ['records' => Wallpaper::query()->whereNotNull('prize_vnd')->count()],
            'status' => 'succeeded',
        ]);
    }

    private function proposalPayload(string $title): array
    {
        return [
            'title' => $title,
            'art_style' => '実写写真',
            'conclusion' => $title.' × 実写写真',
            'overview' => '概要',
            'composition' => '配置',
            'color_wu_xing' => '金',
            'symbolism' => '象徴',
        ];
    }
}
