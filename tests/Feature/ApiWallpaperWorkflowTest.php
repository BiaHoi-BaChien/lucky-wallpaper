<?php

namespace Tests\Feature;

use App\Jobs\GenerateWallpaper;
use App\Models\ApiRun;
use App\Models\User;
use App\Models\Wallpaper;
use App\Services\ImageService;
use App\Services\OpenAiClient;
use App\Services\WallpaperPromptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\KigakuFixtures as Fixtures;
use Tests\TestCase;

class ApiWallpaperWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        Queue::fake();
        config(['lucky.openai.api_key' => 'server-only-test-key', 'lucky.notion.token' => null]);
        $this->actingAs(User::factory()->create());
    }

    public function test_start_is_authenticated_validated_and_requires_a_key(): void
    {
        $this->app['auth']->forgetGuards();
        $this->post('/wallpapers/generate', ['target_date' => '2026-10-22'])->assertRedirect('/login');
        $this->actingAs(User::query()->sole());
        $this->post('/wallpapers/generate', ['target_date' => 'invalid'])->assertSessionHasErrors('target_date');
        config(['lucky.openai.api_key' => '   ']);
        $this->post('/wallpapers/generate', ['target_date' => '2026-10-22'])->assertSessionHasErrors('api_generation');
        $this->get('/wallpapers/create?date=2026-10-22')->assertInertia(fn (Assert $page) => $page->where('apiConfigured', false));
        $this->assertDatabaseCount('api_runs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_start_queues_once_and_page_recovers_status_without_exposing_the_key(): void
    {
        $run = $this->start();
        $this->post('/wallpapers/generate', ['target_date' => '2026-10-22'])->assertRedirect('/wallpapers/create?date=2026-10-22');
        Queue::assertPushed(GenerateWallpaper::class, 1);
        $this->assertDatabaseCount('api_runs', 1);
        $this->assertDatabaseCount('wallpapers', 0);
        $this->get('/wallpapers/create?date=2026-10-22')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('apiConfigured', true)->where('apiGeneration.id', $run->id)->where('apiGeneration.status', 'queued'));
        $this->getJson('/operations/'.$run->id)->assertOk()->assertJsonPath('status', 'queued')
            ->assertJsonMissing(['api_key' => 'server-only-test-key']);
        Http::assertNothingSent();
    }

    public function test_existing_wallpaper_is_rejected_without_dispatching_paid_work(): void
    {
        Wallpaper::factory()->create(['target_date' => '2026-10-22']);
        $this->post('/wallpapers/generate', ['target_date' => '2026-10-22'])->assertSessionHasErrors('api_generation');
        $this->assertDatabaseCount('api_runs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_unsafe_queue_visibility_timeout_is_rejected_before_dispatch(): void
    {
        config(['queue.connections.database.retry_after' => 90]);
        $this->post('/wallpapers/generate', ['target_date' => '2026-10-22'])->assertSessionHasErrors('api_generation');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('api_runs', 0);
    }

    public function test_job_saves_valid_json_and_image_together_and_both_downloads_are_available(): void
    {
        $payload = Fixtures::currentPayload();
        $this->fakeApi($payload);
        $run = $this->start();
        $this->handle($run);
        $wallpaper = Wallpaper::query()->sole();
        $this->assertSame('succeeded', $run->refresh()->status);
        $this->assertSame($payload['kigaku'], $wallpaper->kigaku());
        $this->assertSame($payload['title'], $wallpaper->title);
        $this->assertSame('approved', $wallpaper->chosenProposal->status);
        $size = getimagesizefromstring(Storage::disk('local')->get($wallpaper->image_path));
        $this->assertSame([1440, 2560], [$size[0], $size[1]]);
        $this->getJson('/operations/'.$run->id)->assertOk()->assertJsonPath('wallpaper_id', $wallpaper->id);
        $download = $this->get('/wallpapers/'.$wallpaper->id.'/composition-download')->assertOk()
            ->assertDownload('wallpaper-composition-2026-10-22.json');
        $this->assertSame($payload, json_decode($download->streamedContent(), true));
        $this->get('/wallpapers/'.$wallpaper->id.'/download')->assertOk()->assertDownload();
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/responses'
            && $request['store'] === false && $request['text']['format']['strict'] === true
            && ! isset($request['text']['format']['schema']['properties']['kigaku']['properties']['candidate_directions']['uniqueItems'])
            && str_contains($request['input'], 'JSONオブジェクトのみ') && ! str_contains($request['input'], '必須の納品手順'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/images/generations'
            && $request['size'] === '1440x2560' && $request['n'] === 1
            && str_contains($request['prompt'], $payload['composition']) && ! str_contains($request['prompt'], 'ダウンロード'));
        $this->handle($run);
        (new GenerateWallpaper($run->id, '2026-10-22'))->failed(null);
        Http::assertSentCount(2);
        $this->assertSame('succeeded', $run->refresh()->status);
    }

    public function test_invalid_fixed_judgement_never_reaches_image_generation(): void
    {
        $payload = Fixtures::currentPayload();
        $payload['kigaku']['subject_position']['x_percent'] = 30;
        $this->fakeApi($payload);
        $run = $this->start();
        $this->handle($run);
        $this->assertSame('openai_invalid_composition', $run->refresh()->error_code);
        Http::assertSentCount(1);
        $this->assertNothingWasSaved();
    }

    #[DataProvider('invalidImages')]
    public function test_invalid_image_does_not_leave_a_partial_registration(string $encoded): void
    {
        $this->fakeApi(Fixtures::currentPayload(), $encoded);
        $run = $this->start();
        $this->handle($run);
        $this->assertSame('failed', $run->refresh()->status);
        $this->assertNothingWasSaved();
    }

    public static function invalidImages(): array
    {
        return [['%%%'], [base64_encode('not an image')], ['']];
    }

    #[DataProvider('apiFailures')]
    public function test_api_failures_are_not_retried_and_only_safe_error_codes_are_returned(int $status, string $code): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'private-provider-error']], $status)]);
        $run = $this->start();
        $this->handle($run);
        $this->assertSame($code, $run->refresh()->error_code);
        $this->getJson('/operations/'.$run->id)->assertOk()->assertJsonPath('status', 'failed')
            ->assertDontSee('private-provider-error')->assertDontSee('server-only-test-key');
        Http::assertSentCount(1);
        $this->assertNothingWasSaved();
    }

    public static function apiFailures(): array
    {
        return [[401, 'openai_auth_failed'], [429, 'openai_rate_limited'], [500, 'openai_request_failed']];
    }

    #[DataProvider('invalidResponses')]
    public function test_incomplete_refused_or_malformed_responses_never_reach_image_generation(array|string $body, string $code): void
    {
        Http::fake(['api.openai.com/*' => Http::response($body)]);
        $run = $this->start();
        $this->handle($run);
        $this->assertSame($code, $run->refresh()->error_code);
        Http::assertSentCount(1);
        $this->assertNothingWasSaved();
    }

    public static function invalidResponses(): array
    {
        return [
            [['status' => 'incomplete'], 'openai_incomplete_response'],
            [['status' => 'completed', 'output' => [['content' => [['type' => 'refusal']]]]], 'openai_refused'],
            [['status' => 'completed', 'output' => []], 'openai_invalid_response'],
            [['status' => 'completed', 'output' => 'invalid'], 'openai_invalid_response'],
            [['status' => 'completed', 'output' => [['content' => ['invalid']]]], 'openai_invalid_response'],
            ['not-json', 'openai_invalid_response'],
            [str_repeat('x', 2_000_001), 'openai_response_too_large'],
        ];
    }

    public function test_connection_failure_is_not_retried_and_failure_allows_an_explicit_new_attempt(): void
    {
        Http::fake(['api.openai.com/*' => Http::failedConnection()]);
        $run = $this->start();
        $this->handle($run);
        $this->assertSame('openai_connection_failed', $run->refresh()->error_code);
        $this->assertNothingWasSaved();
        $this->post('/wallpapers/generate', ['target_date' => '2026-10-22'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('api_runs', 2);
        Queue::assertPushed(GenerateWallpaper::class, 2);
        $latest = ApiRun::query()->where('status', 'queued')->sole();
        $latest->update(['created_at' => $run->created_at]);
        $this->get('/wallpapers/create?date=2026-10-22')->assertInertia(fn (Assert $page) => $page
            ->where('apiGeneration.id', $latest->id)->where('apiGeneration.status', 'queued'));
    }

    public function test_wallpaper_registered_while_image_is_generating_is_preserved_and_new_image_is_cleaned_up(): void
    {
        $image = base64_encode(UploadedFile::fake()->image('wallpaper.jpg')->get());
        Http::fake(function (Request $request) use ($image) {
            if (str_ends_with($request->url(), '/responses')) {
                return Http::response($this->compositionResponse(Fixtures::currentPayload()));
            }
            Wallpaper::factory()->create(['target_date' => '2026-10-22', 'title' => '手動登録した壁紙']);

            return Http::response(['data' => [['b64_json' => $image]]]);
        });
        $run = $this->start();
        $this->handle($run);
        $this->assertSame('wallpaper_already_exists', $run->refresh()->error_code);
        $this->assertSame('手動登録した壁紙', Wallpaper::query()->sole()->title);
        $this->assertDatabaseCount('composition_proposals', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_save_failure_rolls_back_the_wallpaper_and_removes_the_normalized_image(): void
    {
        $this->fakeApi(Fixtures::currentPayload());
        $run = $this->start();
        $prompts = Mockery::mock(WallpaperPromptService::class)->makePartial();
        $prompts->shouldReceive('saveProposal')->once()->andThrow(new \RuntimeException('save failed'));
        $this->handle($run, $prompts);
        $this->assertSame('failed', $run->refresh()->status);
        $this->assertNothingWasSaved();
    }

    public function test_stale_queued_input_stops_before_any_api_request(): void
    {
        $run = $this->start();
        $run->update(['input_hash' => str_repeat('f', 64)]);
        $this->handle($run);
        $this->assertSame('composition_prompt_stale', $run->refresh()->error_code);
        Http::assertNothingSent();
        $this->assertNothingWasSaved();
    }

    private function start(): ApiRun
    {
        $this->post('/wallpapers/generate', ['target_date' => '2026-10-22'])
            ->assertSessionHasNoErrors()->assertRedirect('/wallpapers/create?date=2026-10-22');

        return ApiRun::query()->sole();
    }

    private function handle(ApiRun $run, ?WallpaperPromptService $prompts = null): void
    {
        (new GenerateWallpaper($run->id, '2026-10-22'))->handle(
            app(OpenAiClient::class), $prompts ?? app(WallpaperPromptService::class), app(ImageService::class),
        );
    }

    private function fakeApi(array $payload, ?string $image = null): void
    {
        Http::fake([
            'api.openai.com/v1/responses' => Http::response($this->compositionResponse($payload)),
            'api.openai.com/v1/images/generations' => Http::response([
                'data' => [['b64_json' => $image ?? base64_encode(UploadedFile::fake()->image('wallpaper.jpg')->get())]],
            ]),
        ]);
    }

    private function compositionResponse(array $payload): array
    {
        return ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [[
            'type' => 'output_text', 'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]]]]];
    }

    private function assertNothingWasSaved(): void
    {
        $this->assertDatabaseCount('wallpapers', 0);
        $this->assertDatabaseCount('composition_proposals', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
