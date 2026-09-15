<?php

namespace Tests\Feature;

use App\Exceptions\ExternalApiException;
use App\Jobs\GenerateCompositionProposal;
use App\Jobs\GenerateHistoricalAnalysis;
use App\Models\AnalysisSnapshot;
use App\Models\ApiRun;
use App\Models\User;
use App\Models\Wallpaper;
use App\Services\HistoricalAnalysisService;
use App\Services\OpenAiClient;
use App\Services\WallpaperPromptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class WallpaperAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_analysis_data_schema_version_is_part_of_freshness_hash(): void
    {
        $service = app(HistoricalAnalysisService::class);
        $hash = $service->currentDataHash();
        $previous = AnalysisSnapshot::query()->create([
            'data_hash' => hash('sha256', '4|[]'),
            'prompt_version' => config('lucky.openai.prompt_version'),
            'model' => config('lucky.openai.text_model'),
            'summary' => '# 本命星を補助情報に含める前の分析',
            'status' => 'succeeded',
        ]);

        $this->assertSame(hash('sha256', '5|[]'), $hash);
        $this->assertNotSame($previous->data_hash, $hash);
        $this->assertNull($service->currentSnapshot());
        $this->assertTrue($service->latestDisplayableSnapshot()->is($previous));
    }

    public function test_analysis_click_queues_job_and_creates_snapshot(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Wallpaper::factory()->create(['prize_vnd' => 1_000_000]);

        $this->actingAs($user)
            ->post('/wallpaper-analyses', ['api_confirmed' => true])
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

    public function test_latest_analysis_is_displayed_on_analysis_page_and_can_be_queued_again(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Wallpaper::factory()->create(['prize_vnd' => 2_000_000]);
        $snapshot = $this->createCurrentAnalysis();

        $this->actingAs($user)->get('/wallpaper-analyses')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('wallpaper-analyses/index', false)
                ->where('analysis.id', $snapshot->id)
                ->where('analysis.markdown', $snapshot->summary)
                ->where('analysis.html', "<h1>高額当選壁紙の傾向分析</h1>\n<ul>\n<li><strong>中央配置</strong>が多い</li>\n</ul>\n")
                ->where('analysis.is_latest', true));

        $this->actingAs($user)->get('/wallpapers/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('wallpapers/create', false)
                ->where('analysisIsLatest', true)
                ->missing('analysis'));

        $this->actingAs($user)
            ->post('/wallpaper-analyses', ['api_confirmed' => true])
            ->assertRedirect();

        Queue::assertPushed(
            GenerateHistoricalAnalysis::class,
            fn (GenerateHistoricalAnalysis $job): bool => $job->preserveExistingResult,
        );
        $this->assertDatabaseCount('analysis_snapshots', 1);
        $this->assertDatabaseHas('analysis_snapshots', [
            'id' => $snapshot->id,
            'status' => 'succeeded',
            'summary' => $snapshot->summary,
        ]);
    }

    public function test_new_proposal_requires_latest_analysis(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/wallpapers/create')
            ->post('/wallpapers/proposals', ['target_date' => '2026-08-03', 'api_confirmed' => true])
            ->assertRedirect('/wallpapers/create')
            ->assertSessionHasErrors([
                'proposal' => '最新の傾向分析を実行してから構図を提案してください。',
            ]);

        Queue::assertNotPushed(GenerateCompositionProposal::class);
        $this->assertDatabaseCount('wallpapers', 0);
    }

    public function test_analysis_html_strips_raw_html_and_unsafe_links(): void
    {
        $user = User::factory()->create();
        Wallpaper::factory()->create(['prize_vnd' => 2_000_000]);
        $snapshot = $this->createCurrentAnalysis();
        $snapshot->update([
            'summary' => "<script>alert('unsafe')</script>\n\n[危険なリンク](javascript:alert('unsafe'))",
        ]);

        $this->actingAs($user)->get('/wallpaper-analyses')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('analysis.html', fn (string $html): bool => ! str_contains($html, '<script')
                    && ! str_contains($html, 'javascript:')));
    }

    public function test_analysis_job_stores_markdown_result_and_statistics(): void
    {
        config(['lucky.openai.api_key' => 'test']);
        Wallpaper::factory()->create([
            'target_date' => '2026-07-26',
            'prize_vnd' => 3_000_000,
            'purchase_count' => 3,
            'composition_zone' => 'center',
        ]);
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

        Http::assertSent(function (Request $request): bool {
            $instructions = $request->data()['instructions'] ?? '';
            $input = $request->data()['input'] ?? '';

            return is_string($instructions)
                && ! str_contains($instructions, 'moon_age')
                && str_contains($instructions, '1口あたり当選額（prize_per_ticket_vnd）')
                && str_contains($instructions, '九星（nine_star）と九宮構図（composition_zone）')
                && str_contains($instructions, '利用者の本命星は六白金星（五行：金）です。')
                && str_contains($instructions, '実績で見られた傾向と九星に基づく解釈を分け')
                && is_string($input)
                && ! str_contains($input, '"moon_age":')
                && str_contains($input, '"nine_star":"八白土洞明"')
                && str_contains($input, '"composition_zone":"center"')
                && str_contains($input, '"purchase_count":3')
                && str_contains($input, '"prize_per_ticket_vnd":1000000');
        });
        $snapshot->refresh();
        $run->refresh();
        $this->assertSame('succeeded', $snapshot->status);
        $this->assertStringStartsWith('# 高額当選壁紙の傾向分析', $snapshot->summary);
        $this->assertSame(1, $snapshot->statistics['records']);
        $this->assertSame(3_000_000, $snapshot->statistics['high_prize_threshold_vnd']);
        $this->assertSame(1, $snapshot->statistics['prize_per_ticket_record_count']);
        $this->assertSame(1, $snapshot->statistics['nine_palace_record_count']);
        $this->assertSame(1_000_000, $snapshot->statistics['high_prize_per_ticket_threshold_vnd']);
        $this->assertSame('succeeded', $run->status);
    }

    public function test_analysis_merge_preserves_supplementary_birth_star_context(): void
    {
        config(['lucky.analysis.records_per_chunk' => 1]);
        Http::preventStrayRequests();
        Wallpaper::factory()->create(['target_date' => '2026-07-26', 'prize_vnd' => 3_000_000]);
        Wallpaper::factory()->create(['target_date' => '2026-07-27', 'prize_vnd' => 1_000_000]);
        $snapshot = $this->createCurrentAnalysis();
        $partial = "# 部分分析\n\n## 本命星（六白金星）を踏まえた補助的な考察\n\n- 対象1件。傾向は判断できません。";
        $merged = "# 高額当選壁紙の傾向分析\n\n## 本命星（六白金星）を踏まえた補助的な考察\n\n- 対象2件。傾向は判断できません。";
        $openAi = $this->mock(OpenAiClient::class);
        $openAi->shouldReceive('structured')->twice()
            ->withArgs(fn (ApiRun $run, string $instructions, string $input, array $schema, string $name): bool => $name === 'wallpaper_analysis_chunk'
                && str_contains($instructions, '利用者の本命星は六白金星（五行：金）です。'))
            ->andReturn(['analysis_markdown' => $partial]);
        $openAi->shouldReceive('structured')->once()
            ->withArgs(fn (ApiRun $run, string $instructions, string $input, array $schema, string $name): bool => $name === 'wallpaper_analysis_summary'
                && str_contains($instructions, '本命星（六白金星）を踏まえた補助的な考察')
                && str_contains($instructions, '実績で見られた傾向と九星に基づく解釈を分け')
                && json_decode($input, true) === [$partial, $partial])
            ->andReturn(['analysis_markdown' => $merged]);

        $result = app(HistoricalAnalysisService::class)->analyze($snapshot);

        $this->assertSame($merged, $result->summary);
        $this->assertSame(2, $result->statistics['records']);
        $this->assertSame(2, $result->statistics['chunks']);
        $this->assertSame('succeeded', $result->status);
        Http::assertNothingSent();
    }

    public function test_composition_api_receives_target_date_and_saved_markdown_analysis(): void
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
            app(WallpaperPromptService::class),
            app(OpenAiClient::class),
        );

        Http::assertSent(function (Request $request) use ($analysis): bool {
            $instructions = $request->data()['instructions'] ?? '';
            $input = $request->data()['input'] ?? '';
            $decoded = is_string($input) ? json_decode($input, true) : null;

            return is_string($instructions)
                && str_contains($instructions, '2026年8月4日用のスマートフォン壁紙です。')
                && is_array($decoded)
                && ($decoded['historical_analysis_markdown'] ?? null) === $analysis->summary;
        });
        $this->assertDatabaseHas('composition_proposals', [
            'wallpaper_id' => $wallpaper->id,
            'analysis_hash' => $analysis->data_hash,
            'composition_zone' => 'center',
        ]);
        $this->assertSame('center', $wallpaper->refresh()->composition_zone);
    }

    public function test_composition_api_rejects_analysis_invalidated_while_queued(): void
    {
        config(['lucky.notion.token' => '']);
        Queue::fake();
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $history = Wallpaper::factory()->create([
            'target_date' => '2026-07-01',
            'prize_vnd' => 1_000,
            'purchase_count' => 1,
        ]);
        $analysis = $this->createCurrentAnalysis();
        $this->actingAs($user)
            ->post('/wallpapers/proposals', ['target_date' => '2026-08-04', 'api_confirmed' => true])
            ->assertSessionHasNoErrors();
        Queue::assertPushed(GenerateCompositionProposal::class);
        $run = ApiRun::query()->where('type', 'composition_proposal')->sole();

        $this->put("/wallpapers/{$history->id}/result", [
            'prize_vnd' => 1_000,
            'purchase_count' => 2,
        ])->assertSessionHasNoErrors();
        $this->assertSame('invalidated', $analysis->refresh()->status);
        $openAi = $this->mock(OpenAiClient::class);
        $openAi->shouldNotReceive('structured')->andReturn($this->proposalPayload());

        try {
            (new GenerateCompositionProposal($run->subject_id, $run->id))->handle(
                app(WallpaperPromptService::class),
                $openAi,
            );
            $this->fail('API proposals must reject analysis invalidated while queued.');
        } catch (ExternalApiException $exception) {
            $this->assertSame('historical_analysis_required', $exception->errorCode);
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('composition_proposals', 0);
        $this->assertDatabaseHas('wallpapers', ['id' => $run->subject_id, 'state' => 'draft']);
    }

    public function test_composition_api_rechecks_analysis_before_saving_response(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $history = Wallpaper::factory()->create([
            'target_date' => '2026-07-01',
            'prize_vnd' => 1_000,
            'purchase_count' => 1,
        ]);
        $analysis = $this->createCurrentAnalysis();
        $this->actingAs($user)
            ->post('/wallpapers/proposals', ['target_date' => '2026-08-04', 'api_confirmed' => true])
            ->assertSessionHasNoErrors();
        $run = ApiRun::query()->where('type', 'composition_proposal')->sole();
        $openAi = $this->mock(OpenAiClient::class);
        $openAi->shouldReceive('structured')->once()->andReturnUsing(function () use ($history, $analysis): array {
            $history->update(['purchase_count' => 2]);
            $analysis->update(['status' => 'invalidated']);

            return $this->proposalPayload();
        });

        try {
            (new GenerateCompositionProposal($run->subject_id, $run->id))->handle(
                app(WallpaperPromptService::class),
                $openAi,
            );
            $this->fail('API proposals must recheck current analysis before saving.');
        } catch (ExternalApiException $exception) {
            $this->assertSame('historical_analysis_required', $exception->errorCode);
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('composition_proposals', 0);
        $this->assertDatabaseHas('wallpapers', ['id' => $run->subject_id, 'state' => 'draft']);
    }

    private function createCurrentAnalysis(): AnalysisSnapshot
    {
        return AnalysisSnapshot::query()->create([
            'data_hash' => app(HistoricalAnalysisService::class)->currentDataHash(),
            'prompt_version' => config('lucky.openai.prompt_version'),
            'model' => config('lucky.openai.text_model'),
            'summary' => "# 高額当選壁紙の傾向分析\n\n- **中央配置**が多い",
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
            'composition_zone' => 'center',
            'color_wu_xing' => '金',
            'symbolism' => '象徴',
        ];
    }
}
