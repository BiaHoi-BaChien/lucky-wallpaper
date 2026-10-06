<?php

namespace Tests\Feature;

use App\Models\AnalysisSnapshot;
use App\Models\User;
use App\Models\Wallpaper;
use App\Services\HistoricalAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManualAnalysisRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public static function perspectives(): array
    {
        return [
            'Japanese maximum' => [str_repeat('日本語の切り口を比較', 200)],
            'short with URL special characters' => ["季節 & 曜日別 + 構図=比較？\n色も確認"],
            'empty' => [''],
            'whitespace' => [" \n "],
        ];
    }

    #[DataProvider('perspectives')]
    public function test_post_round_trip_preserves_the_approved_perspective_without_starting_analysis(string $perspective): void
    {
        $this->actingAs(User::factory()->create());
        Wallpaper::factory()->create(['prize_vnd' => 1000]);
        $service = app(HistoricalAnalysisService::class);
        $initial = $service->manualPrompt();
        $baseline = $service->saveManualResult('# 前回の分析', $initial['prompt_hash'], $initial['prompt_date']);
        $options = ['full_confirmed' => true, 'perspective' => $perspective];

        $prompt = $this->postJson('/wallpaper-analyses/manual-prompt', $options)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->postJson('/wallpaper-analyses/manual-prompt', $options)
            ->assertOk()->assertJsonPath('prompt_hash', $prompt['prompt_hash']);
        $download = $options + ['prompt_date' => $prompt['prompt_date'], 'prompt_hash' => $prompt['prompt_hash']];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $data = $this->postJson('/wallpaper-analyses/manual-data', $download)
                ->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('Content-Type', 'application/json; charset=UTF-8')
                ->assertHeader('Content-Disposition', 'attachment; filename="'.$prompt['data_filename'].'"')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertJsonPath('analysis_mode', 'full')
                ->assertJsonPath('approved_perspective', trim($perspective))
                ->json();
            $this->assertStringContainsString($data['analysis_token'], $prompt['prompt']);
        }

        // Prompt creation and repeated downloads must not modify the saved result.
        $this->assertSame('# 前回の分析', $baseline->refresh()->summary);
        $this->assertDatabaseCount('analysis_snapshots', 1);
        $this->post('/wallpaper-analyses/manual-result', $download + ['analysis_markdown' => '# 新しい分析'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(trim($perspective), AnalysisSnapshot::query()->sole()->statistics['approved_perspective']);
        $this->assertDatabaseCount('api_runs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public static function endpoints(): array
    {
        return [
            'prompt' => ['/wallpaper-analyses/manual-prompt'],
            'data' => ['/wallpaper-analyses/manual-data'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_manual_posts_require_authentication_and_do_not_accept_get(string $endpoint): void
    {
        $this->postJson($endpoint, ['prompt_date' => '2026-10-06'])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson($endpoint)->assertStatus(405);
    }

    #[DataProvider('endpoints')]
    public function test_manual_posts_validate_options_before_creating_any_work(string $endpoint): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([
            [['full_confirmed' => true, 'perspective' => str_repeat('日', 2001)], 'perspective'],
            [['full_confirmed' => true, 'perspective' => ['invalid']], 'perspective'],
            [['full_confirmed' => 'invalid'], 'full_confirmed'],
            [['full_confirmed' => false, 'perspective' => '曜日別'], 'full_confirmed'],
            [['perspective' => '曜日別'], 'full_confirmed'],
        ] as [$options, $field]) {
            $this->postJson($endpoint, $options + ['prompt_date' => '2026-10-06'])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('analysis_snapshots', 0);
        $this->assertDatabaseCount('api_runs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[DataProvider('endpoints')]
    public function test_manual_posts_enforce_csrf_and_accept_the_browser_xsrf_header(string $endpoint): void
    {
        // Enable the real CSRF middleware instead of its PHPUnit bypass.
        $this->app->detectEnvironment(fn (): string => 'local');
        $this->actingAs(User::factory()->create());
        $options = ['prompt_date' => '2026-10-06'];
        $this->postJson($endpoint, $options)->assertStatus(419);

        $cookie = $this->get('/wallpaper-analyses')->assertOk()->getCookie('XSRF-TOKEN', decrypt: false);
        $this->postJson($endpoint, $options, ['X-XSRF-TOKEN' => $cookie->getValue()])->assertOk();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
