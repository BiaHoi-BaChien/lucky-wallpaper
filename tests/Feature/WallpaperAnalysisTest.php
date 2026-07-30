<?php

namespace Tests\Feature;

use App\Jobs\GenerateCompositionProposal;
use App\Jobs\GenerateHistoricalAnalysis;
use App\Models\AnalysisSnapshot;
use App\Models\ApiRun;
use App\Models\User;
use App\Models\Wallpaper;
use App\Services\CalendarContextService;
use App\Services\HistoricalAnalysisService;
use App\Services\OpenAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class WallpaperAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_analysis_click_queues_job_and_creates_snapshot(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Wallpaper::factory()->create(['prize_vnd' => 1_000_000]);

        $this->actingAs($user)
            ->post('/wallpaper-analyses')
            ->assertRedirect();

        $snapshot = AnalysisSnapshot::query()->sole();
        $this->assertSame(app(HistoricalAnalysisService::class)->currentDataHash(), $snapshot->data_hash);
        $this->assertSame('queued', $snapshot->status);
        $this->assertSame('', $snapshot->summary);
        $this->assertDatabaseHas('api_runs', [
            'type' => 'historical_analysis',
            'status' => 'queued',
            'subject_type' => $snapshot->getMorphClass(),
            'subject_id' => $snapshot->id,
        ]);
        Queue::assertPushed(GenerateHistoricalAnalysis::class, 1);
    }

    public function test_latest_analysis_is_displayed_and_is_not_queued_again(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Wallpaper::factory()->create(['prize_vnd' => 2_000_000]);
        $snapshot = $this->createCurrentAnalysis();

        $this->actingAs($user)->get('/wallpapers/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('wallpapers/create', false)
                ->where('analysis.id', $snapshot->id)
                ->where('analysis.markdown', $snapshot->summary)
                ->where('analysis.is_latest', true));

        $this->actingAs($user)->post('/wallpaper-analyses')->assertRedirect();

        Queue::assertNotPushed(GenerateHistoricalAnalysis::class);
        $this->assertDatabaseCount('analysis_snapshots', 1);
    }

    public function test_new_proposal_requires_latest_analysis(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/wallpapers/create')
            ->post('/wallpapers/proposals', ['target_date' => '2026-08-03'])
            ->assertRedirect('/wallpapers/create')
            ->assertSessionHasErrors([
                'proposal' => '最新の傾向分析を実行してから構図を提案してください。',
            ]);

        Queue::assertNotPushed(GenerateCompositionProposal::class);
        $this->assertDatabaseCount('wallpapers', 0);
    }

    public function test_analysis_job_stores_markdown_result_and_statistics(): void
    {
        config(['lucky.openai.api_key' => 'test']);
        Wallpaper::factory()->create(['prize_vnd' => 3_000_000]);
        $snapshot = AnalysisSnapshot::query()->create([
            'data_hash' => app(HistoricalAnalysisService::class)->currentDataHash(),
            'prompt_version' => config('lucky.openai.prompt_version'),
            'model' => config('lucky.openai.text_model'),
            'summary' => '',
            'status' => 'queued',
        ]);
        $run = $this->makeRun($snapshot, 'historical_analysis');
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'analysis_markdown' => "## 高額当選側で見られる傾向\n\n- 中央配置",
                        ], JSON_UNESCAPED_UNICODE),
                    ]],
                ]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
            ], 200),
        ]);

        (new GenerateHistoricalAnalysis($snapshot->id, $run->id))
            ->handle(app(HistoricalAnalysisService::class));

        $snapshot->refresh();
        $run->refresh();
        $this->assertSame('succeeded', $snapshot->status);
        $this->assertStringStartsWith('# 高額当選壁紙の傾向分析', $snapshot->summary);
        $this->assertSame(1, $snapshot->statistics['records']);
        $this->assertSame(3_000_000, $snapshot->statistics['high_prize_threshold_vnd']);
        $this->assertSame('succeeded', $run->status);
    }

    public function test_composition_api_receives_saved_markdown_analysis(): void
    {
        config(['lucky.openai.api_key' => 'test']);
        Wallpaper::factory()->create([
            'target_date' => '2026-07-01',
            'prize_vnd' => 4_000_000,
        ]);
        $analysis = $this->createCurrentAnalysis();
        $wallpaper = Wallpaper::factory()->create([
            'target_date' => '2026-08-04',
            'title' => null,
            'composition' => null,
            'prize_vnd' => null,
            'state' => 'draft',
        ]);
        $run = ApiRun::query()->create([
            'type' => 'composition_proposal',
            'model' => config('lucky.openai.text_model'),
            'prompt_version' => config('lucky.openai.prompt_version'),
            'input_hash' => str_repeat('a', 64),
            'subject_type' => $wallpaper->getMorphClass(),
            'subject_id' => $wallpaper->id,
        ]);
        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode($this->proposalPayload(), JSON_UNESCAPED_UNICODE),
                    ]],
                ]],
            ], 200),
        ]);

        (new GenerateCompositionProposal($wallpaper->id, $run->id))->handle(
            app(HistoricalAnalysisService::class),
            app(CalendarContextService::class),
            app(OpenAiClient::class),
        );

        Http::assertSent(function (Request $request) use ($analysis): bool {
            $input = $request->data()['input'] ?? '';
            $decoded = is_string($input) ? json_decode($input, true) : null;

            return is_array($decoded)
                && ($decoded['historical_analysis_markdown'] ?? null) === $analysis->summary;
        });
        $this->assertDatabaseHas('composition_proposals', [
            'wallpaper_id' => $wallpaper->id,
            'analysis_hash' => $analysis->data_hash,
        ]);
    }

    private function createCurrentAnalysis(): AnalysisSnapshot
    {
        return AnalysisSnapshot::query()->create([
            'data_hash' => app(HistoricalAnalysisService::class)->currentDataHash(),
            'prompt_version' => config('lucky.openai.prompt_version'),
            'model' => config('lucky.openai.text_model'),
            'summary' => "# 高額当選壁紙の傾向分析\n\n- 中央配置が多い",
            'statistics' => ['records' => Wallpaper::query()->whereNotNull('prize_vnd')->count()],
            'status' => 'succeeded',
        ]);
    }

    private function makeRun(AnalysisSnapshot $snapshot, string $type): ApiRun
    {
        return ApiRun::query()->create([
            'type' => $type,
            'model' => config('lucky.openai.text_model'),
            'prompt_version' => config('lucky.openai.prompt_version'),
            'input_hash' => $snapshot->data_hash,
            'subject_type' => $snapshot->getMorphClass(),
            'subject_id' => $snapshot->id,
        ]);
    }

    private function proposalPayload(): array
    {
        return [
            'title' => '黄金の中央庭園',
            'art_style' => '実写写真',
            'conclusion' => '黄金の中央庭園 × 実写写真',
            'overview' => '概要',
            'composition' => '配置',
            'color_wu_xing' => '金',
            'symbolism' => '象徴',
        ];
    }
}
