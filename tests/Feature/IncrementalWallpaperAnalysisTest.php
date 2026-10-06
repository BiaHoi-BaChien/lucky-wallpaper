<?php

namespace Tests\Feature;

use App\Exceptions\ExternalApiException;
use App\Jobs\GenerateHistoricalAnalysis;
use App\Models\AnalysisSnapshot;
use App\Models\ApiRun;
use App\Models\User;
use App\Models\Wallpaper;
use App\Services\HistoricalAnalysisService;
use App\Services\OpenAiClient;
use App\Services\WallpaperPromptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class IncrementalWallpaperAnalysisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->actingAs(User::factory()->create());
    }

    public function test_manual_delta_contains_previous_result_and_only_new_records(): void
    {
        $old = Wallpaper::factory()->create(['prize_vnd' => 1000, 'title' => '分析済み原文']);
        $base = $this->saveBaseline();
        $new = Wallpaper::factory()->create(['prize_vnd' => 2000]);
        $prompt = $this->postJson('/wallpaper-analyses/manual-prompt')->assertOk()->json();
        $data = $this->postJson('/wallpaper-analyses/manual-data', [
            'prompt_date' => $prompt['prompt_date'], 'prompt_hash' => $prompt['prompt_hash'],
        ])->assertOk()->json();

        $this->assertSame('incremental', $data['analysis_mode']);
        $this->assertSame($base->summary, $data['previous_analysis']);
        $this->assertSame(1, $data['previous_statistics']['records']);
        $this->assertSame(2, $data['current_statistics']['records']);
        $this->assertSame(1, $data['record_count']);
        $this->assertSame([$new->id], array_column($data['records'], 'id'));
        $this->assertSame('added', $data['records'][0]['change_type']);
        $this->assertStringNotContainsString($old->title, json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('未承認の切り口による全件再分析は実行せず', $prompt['prompt']);

        $this->post('/wallpaper-analyses/manual-result', [
            'prompt_date' => $prompt['prompt_date'], 'prompt_hash' => $prompt['prompt_hash'],
            'analysis_markdown' => '# 差分を反映した分析',
        ])->assertSessionHasNoErrors();
        $current = app(HistoricalAnalysisService::class)->currentSnapshot();
        $this->assertNotSame($base->id, $current->id);
        $this->assertSame(2, $current->statistics['records']);
        $this->assertSame(1, $current->statistics['analyzed_records']);
        $this->assertSame($base->id, $current->statistics['base_snapshot_id']);
        $this->assertSame('# 前回の分析', $base->refresh()->summary);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_delta_includes_records_reclassified_by_the_global_threshold(): void
    {
        $records = collect([100, 200, 300, 400])->map(fn (int $prize): Wallpaper => Wallpaper::factory()->create([
            'prize_vnd' => $prize, 'purchase_count' => 1,
        ]));
        $this->saveBaseline();
        $new = Wallpaper::factory()->create(['prize_vnd' => 500, 'purchase_count' => 1]);
        $data = json_decode(app(HistoricalAnalysisService::class)->manualData('2026-10-06')['content'], true);

        $this->assertSame(400, $data['high_prize_threshold_vnd']);
        $this->assertSame(2, $data['record_count']);
        $this->assertSame([$new->id, $records[2]->id], array_column($data['records'], 'id'));
        $this->assertSame('reclassified', $data['records'][1]['change_type']);
        $this->assertTrue($data['records'][1]['previous_classification']['is_high_prize']);
        $this->assertFalse($data['records'][1]['is_high_prize']);
        $this->assertTrue($data['records'][1]['previous_classification']['is_high_prize_per_ticket']);
        $this->assertFalse($data['records'][1]['is_high_prize_per_ticket']);
    }

    public function test_changed_or_deleted_history_requires_confirmation_on_every_entry_point(): void
    {
        $record = Wallpaper::factory()->create(['prize_vnd' => 1000]);
        $base = $this->saveBaseline();
        $record->update(['composition' => '修正された構図']);

        $this->postJson('/wallpaper-analyses/manual-prompt')->assertUnprocessable()->assertJsonValidationErrors('full_confirmed');
        $this->postJson('/wallpaper-analyses/manual-data', ['prompt_date' => '2026-10-06'])->assertUnprocessable()->assertJsonValidationErrors('full_confirmed');
        $this->post('/wallpaper-analyses', ['api_confirmed' => true])->assertSessionHasErrors('full_confirmed');
        $this->post('/wallpaper-analyses/manual-result', [
            'analysis_markdown' => '# 無許可', 'prompt_hash' => str_repeat('a', 64), 'prompt_date' => '2026-10-06',
        ])->assertSessionHasErrors('full_confirmed');
        Queue::assertNothingPushed();
        $this->assertSame('# 前回の分析', $base->refresh()->summary);

        $record->delete();
        $this->postJson('/wallpaper-analyses/manual-prompt')->assertJsonValidationErrors('full_confirmed');
        $this->post('/wallpaper-analyses', ['api_confirmed' => true, 'full_confirmed' => true])->assertSessionHasNoErrors();
        Queue::assertPushed(GenerateHistoricalAnalysis::class, fn (GenerateHistoricalAnalysis $job): bool => $job->fullConfirmed);
    }

    public function test_legacy_and_changed_prompt_versions_require_confirmation(): void
    {
        Wallpaper::factory()->create(['prize_vnd' => 1000]);
        $base = $this->saveBaseline();
        $base->update(['statistics' => ['records' => 1]]);
        Wallpaper::factory()->create(['prize_vnd' => 2000]);
        $this->postJson('/wallpaper-analyses/manual-prompt')->assertJsonValidationErrors('full_confirmed');
        $this->postJson('/wallpaper-analyses/manual-prompt', ['full_confirmed' => true])->assertOk();

        config(['lucky.openai.prompt_version' => 'v2']);
        $this->postJson('/wallpaper-analyses/manual-prompt')->assertJsonValidationErrors('full_confirmed');
    }

    public function test_unchanged_history_skips_the_api_and_reuses_the_saved_result(): void
    {
        Wallpaper::factory()->create(['prize_vnd' => 1000]);
        $base = $this->saveBaseline();
        $this->post('/wallpaper-analyses', ['api_confirmed' => true])->assertSessionHasNoErrors();
        $this->get('/wallpaper-analyses')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('analysisPlan.mode', 'unchanged')->where('analysis.id', $base->id)
            ->missing('analysis.statistics.record_manifest'));
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('api_runs', 0);
    }

    public function test_deleting_a_draft_allows_reusing_the_invalidated_result_without_api_confirmation(): void
    {
        Wallpaper::factory()->create(['prize_vnd' => 1000]);
        $base = $this->saveBaseline();
        $statistics = $base->statistics;
        $draft = Wallpaper::factory()->create([
            'state' => 'draft', 'prize_vnd' => null, 'title' => null, 'composition' => null,
        ]);
        $this->delete('/wallpapers/'.$draft->id)->assertSessionHasNoErrors();
        $this->assertSame('invalidated', $base->refresh()->status);
        $this->assertNull(app(HistoricalAnalysisService::class)->currentSnapshot());
        $this->get('/wallpaper-analyses')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('analysisPlan.mode', 'unchanged')->where('analysis.is_latest', false));

        $this->post('/wallpaper-analyses', ['reuse_only' => true])->assertSessionHasNoErrors();

        $this->assertSame('succeeded', $base->refresh()->status);
        $this->assertSame('# 前回の分析', $base->summary);
        $this->assertSame($statistics, $base->statistics);
        $this->get('/wallpaper-analyses')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('analysis.is_latest', true));
        $composition = app(WallpaperPromptService::class)->composition('2026-10-07');
        $this->assertSame($base->data_hash, $composition['analysis_hash']);
        $this->assertDatabaseCount('api_runs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_reuse_request_does_not_start_analysis_if_history_changes_or_full_analysis_is_requested(): void
    {
        Wallpaper::factory()->create(['prize_vnd' => 1000]);
        $base = $this->saveBaseline();
        $this->post('/wallpaper-analyses', ['reuse_only' => true, 'full_confirmed' => true])
            ->assertSessionHasErrors('analysis');
        Wallpaper::factory()->create(['prize_vnd' => 2000]);
        $base->update(['status' => 'invalidated']);
        $this->post('/wallpaper-analyses', ['reuse_only' => true])->assertSessionHasErrors('analysis');
        $this->assertSame('invalidated', $base->refresh()->status);
        $this->assertDatabaseCount('api_runs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_manual_result_reuse_restores_invalidated_status(): void
    {
        Wallpaper::factory()->create(['prize_vnd' => 1000]);
        $base = $this->saveBaseline();
        $base->update(['status' => 'invalidated']);
        $prompt = $this->postJson('/wallpaper-analyses/manual-prompt')->assertOk()->json();
        $this->post('/wallpaper-analyses/manual-result', [
            'prompt_date' => $prompt['prompt_date'], 'prompt_hash' => $prompt['prompt_hash'],
            'analysis_markdown' => $prompt['default_result'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('succeeded', $base->refresh()->status);
        $this->assertTrue(app(HistoricalAnalysisService::class)->currentSnapshot()->is($base));
        Queue::assertNothingPushed();
    }

    public function test_manual_full_reanalysis_accumulates_approved_perspectives_and_keeps_them_when_blank(): void
    {
        Wallpaper::factory()->create(['prize_vnd' => 1000]);
        $service = app(HistoricalAnalysisService::class);
        $this->saveBaseline();
        foreach (['季節別', '曜日別', ''] as $perspective) {
            $prompt = $service->manualPrompt(fullConfirmed: true, perspective: $perspective);
            $data = json_decode($service->manualData($prompt['prompt_date'], true, $perspective, $prompt['prompt_hash'])['content'], true);
            $expected = $perspective === '季節別' ? '季節別' : "季節別\n\n曜日別";
            $this->assertSame($expected, $data['approved_perspective']);
            $snapshot = $service->saveManualResult('# 全件分析', $prompt['prompt_hash'], $prompt['prompt_date'], true, $perspective);
            $this->assertSame($expected, $snapshot->statistics['approved_perspective']);
        }

        Wallpaper::factory()->create(['prize_vnd' => 2000]);
        $data = json_decode($service->manualData('2026-10-06')['content'], true);
        $this->assertSame('incremental', $data['analysis_mode']);
        $this->assertSame("季節別\n\n曜日別", $data['approved_perspective']);
        Queue::assertNothingPushed();
    }

    public function test_api_full_reanalysis_receives_and_saves_existing_and_new_perspectives(): void
    {
        Wallpaper::factory()->create(['prize_vnd' => 1000]);
        $base = $this->saveBaseline();
        $statistics = $base->statistics;
        $statistics['approved_perspective'] = '季節別';
        $base->update(['statistics' => $statistics]);
        $this->mock(OpenAiClient::class)->shouldReceive('structured')->once()
            ->withArgs(function (ApiRun $run, string $instructions, string $input): bool {
                $data = json_decode(substr($input, strpos($input, '{')), true);

                return $data['analysis_mode'] === 'full' && $data['approved_perspective'] === "季節別\n\n曜日別";
            })->andReturn(['analysis_markdown' => '# 複数の切り口による分析']);
        $this->post('/wallpaper-analyses', [
            'api_confirmed' => true, 'full_confirmed' => true, 'perspective' => '曜日別',
        ])->assertSessionHasNoErrors();
        $job = Queue::pushed(GenerateHistoricalAnalysis::class)->first();
        $restored = unserialize(serialize($job));
        $this->assertTrue($restored->fullConfirmed);
        $this->assertSame('曜日別', $restored->perspective);
        $this->assertSame($job->planToken, $restored->planToken);
        $restored->handle(app(HistoricalAnalysisService::class));
        $this->assertSame("季節別\n\n曜日別", $base->refresh()->statistics['approved_perspective']);
    }

    public function test_legacy_queued_job_restores_defaults_and_completes_initial_analysis(): void
    {
        Wallpaper::factory()->create(['prize_vnd' => 1000]);
        $this->post('/wallpaper-analyses', ['api_confirmed' => true])->assertSessionHasNoErrors();
        $job = Queue::pushed(GenerateHistoricalAnalysis::class)->first();
        $values = $job->__serialize();
        unset($values['fullConfirmed'], $values['perspective'], $values['planToken']);
        $class = GenerateHistoricalAnalysis::class;
        $serialized = 'O:'.strlen($class).':"'.$class.'":'.substr(serialize($values), 2);
        $legacy = unserialize($serialized, ['allowed_classes' => [$class]]);
        $this->assertFalse($legacy->fullConfirmed);
        $this->assertSame('', $legacy->perspective);
        $this->assertNull($legacy->planToken);
        $this->assertSame('openai', $legacy->queue);
        $this->mock(OpenAiClient::class)->shouldReceive('structured')->once()
            ->andReturn(['analysis_markdown' => '# 旧ジョブの初回分析']);

        $legacy->handle(app(HistoricalAnalysisService::class));

        $this->assertSame('succeeded', ApiRun::findOrFail($job->apiRunId)->status);
        $this->assertSame('# 旧ジョブの初回分析', app(HistoricalAnalysisService::class)->currentSnapshot()->summary);
    }

    public function test_proposal_waits_for_permission_and_is_included_in_approved_full_analysis(): void
    {
        Wallpaper::factory()->create(['prize_vnd' => 1000]);
        $proposal = '季節別に構図を比較し、季節による違いを確認する。';
        $base = $this->saveBaseline("# 前回の分析\n\n## 全件再分析の提案\n\n".$proposal);
        $this->get('/wallpaper-analyses')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('analysisPlan.proposal', $proposal));
        Queue::assertNothingPushed();
        $this->postJson('/wallpaper-analyses/manual-prompt', ['perspective' => $proposal])
            ->assertJsonValidationErrors('full_confirmed');
        $this->post('/wallpaper-analyses', ['api_confirmed' => true, 'perspective' => $proposal])
            ->assertSessionHasErrors('full_confirmed');

        $options = ['full_confirmed' => 1, 'perspective' => $proposal];
        $prompt = $this->postJson('/wallpaper-analyses/manual-prompt', $options)->assertOk()->json();
        $data = $this->postJson('/wallpaper-analyses/manual-data', $options + [
            'prompt_date' => $prompt['prompt_date'], 'prompt_hash' => $prompt['prompt_hash'],
        ])->assertOk()->json();
        $this->assertSame('full', $data['analysis_mode']);
        $this->assertSame($proposal, $data['approved_perspective']);
        $this->assertCount(1, $data['records']);
        $this->post('/wallpaper-analyses/manual-result', $options + [
            'prompt_date' => $prompt['prompt_date'], 'prompt_hash' => $prompt['prompt_hash'],
            'analysis_markdown' => '# 承認された切り口の分析',
        ])->assertSessionHasNoErrors();
        $this->assertNull($base->refresh()->statistics['reanalysis_proposal']);
        $this->assertSame($proposal, $base->statistics['approved_perspective']);
    }

    public function test_stale_download_and_result_are_rejected_even_when_record_count_is_unchanged(): void
    {
        Wallpaper::factory()->create(['prize_vnd' => 1000]);
        $base = $this->saveBaseline();
        Wallpaper::factory()->create(['prize_vnd' => 2000]);
        $prompt = $this->postJson('/wallpaper-analyses/manual-prompt')->json();
        $base->update(['summary' => '# 別の分析に更新']);
        $options = ['prompt_date' => $prompt['prompt_date'], 'prompt_hash' => $prompt['prompt_hash']];
        $this->postJson('/wallpaper-analyses/manual-data', $options)->assertJsonValidationErrors('prompt_hash');
        $this->post('/wallpaper-analyses/manual-result', $options + ['analysis_markdown' => '# 古い前回結果に基づく分析'])
            ->assertSessionHasErrors('analysis_markdown');
        $this->assertDatabaseCount('analysis_snapshots', 1);
    }

    public function test_api_delta_sends_only_changes_and_preserves_previous_snapshot(): void
    {
        Wallpaper::factory()->create(['prize_vnd' => 1000, 'title' => '送信しない既存原文']);
        $base = $this->saveBaseline();
        $new = Wallpaper::factory()->create(['prize_vnd' => 2000]);
        $this->mock(OpenAiClient::class)->shouldReceive('structured')->once()
            ->withArgs(function (ApiRun $run, string $instructions, string $input) use ($base, $new): bool {
                $data = json_decode(substr($input, strpos($input, '{')), true);

                return $data['previous_analysis'] === $base->summary
                    && array_column($data['records'], 'id') === [$new->id]
                    && ! str_contains($input, '送信しない既存原文');
            })->andReturn(['analysis_markdown' => '# APIの差分分析']);
        $this->post('/wallpaper-analyses', ['api_confirmed' => true])->assertSessionHasNoErrors();
        $job = Queue::pushed(GenerateHistoricalAnalysis::class)->first();
        $job->handle(app(HistoricalAnalysisService::class));

        $current = app(HistoricalAnalysisService::class)->currentSnapshot();
        $this->assertSame('incremental', $current->statistics['analysis_mode']);
        $this->assertSame(2, $current->statistics['records']);
        $this->assertSame('# 前回の分析', $base->refresh()->summary);
        $this->assertSame('succeeded', ApiRun::findOrFail($job->apiRunId)->status);
    }

    public function test_full_analysis_failure_preserves_the_existing_result(): void
    {
        Wallpaper::factory()->create(['prize_vnd' => 1000]);
        $base = $this->saveBaseline();
        $statistics = $base->statistics;
        $this->mock(OpenAiClient::class)->shouldReceive('structured')->once()
            ->andThrow(new ExternalApiException('test_failure', false));
        $this->post('/wallpaper-analyses', ['api_confirmed' => true, 'full_confirmed' => true])->assertSessionHasNoErrors();
        $job = Queue::pushed(GenerateHistoricalAnalysis::class)->first();
        try {
            $job->handle(app(HistoricalAnalysisService::class));
            $this->fail('The API should fail.');
        } catch (ExternalApiException $exception) {
            $job->failed($exception);
        }
        $this->assertSame('# 前回の分析', $base->refresh()->summary);
        $this->assertSame($statistics, $base->statistics);
        $this->assertSame('succeeded', $base->status);
    }

    public function test_api_rejects_history_changed_while_the_request_was_running(): void
    {
        $old = Wallpaper::factory()->create(['prize_vnd' => 1000]);
        $base = $this->saveBaseline();
        Wallpaper::factory()->create(['prize_vnd' => 2000]);
        $this->mock(OpenAiClient::class)->shouldReceive('structured')->once()->andReturnUsing(function () use ($old): array {
            $old->update(['prize_vnd' => 3000]);

            return ['analysis_markdown' => '# 実行中に古くなった分析'];
        });
        $this->post('/wallpaper-analyses', ['api_confirmed' => true])->assertSessionHasNoErrors();
        $job = Queue::pushed(GenerateHistoricalAnalysis::class)->first();
        try {
            $job->handle(app(HistoricalAnalysisService::class));
            $this->fail('Stale analysis must not be saved.');
        } catch (ExternalApiException $exception) {
            $this->assertSame('historical_analysis_stale_input', $exception->errorCode);
            $job->failed($exception);
        }
        $this->assertSame('# 前回の分析', $base->refresh()->summary);
        $this->assertNull(app(HistoricalAnalysisService::class)->currentSnapshot());
    }

    private function saveBaseline(string $summary = '# 前回の分析'): AnalysisSnapshot
    {
        $service = app(HistoricalAnalysisService::class);
        $prompt = $service->manualPrompt();

        return $service->saveManualResult($summary, $prompt['prompt_hash'], $prompt['prompt_date']);
    }
}
