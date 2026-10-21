<?php

namespace Tests\Feature;

use App\Exceptions\ExternalApiException;
use App\Models\ApiRun;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\Wallpaper;
use App\Services\ImageService;
use App\Services\WallpaperPromptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\KigakuFixtures as Fixtures;
use Tests\TestCase;

class ManualWallpaperWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        Queue::fake();
        config(['lucky.notion.token' => null]);
        $this->actingAs(User::factory()->create());
    }

    public function test_prompt_uses_only_date_star_timezone_and_needs_no_analysis(): void
    {
        Wallpaper::factory()->create(['title' => '過去の秘密の構図', 'prize_vnd' => 12345]);
        $prompt = $this->getJson('/wallpapers/proposals/manual-prompt?target_date=2026-10-22')->assertOk()->json();
        foreach (['六白金星', '2026-10-22', 'Asia/Ho_Chi_Minh', 'candidate_directions', 'undetermined', '1440×2560', 'プレビュー', 'ダウンロード', '実際に使用'] as $text) {
            $this->assertStringContainsString($text, $prompt['prompt']);
        }
        foreach (['過去の秘密の構図', '12345', 'historical_analysis_markdown', 'top_winners', 'recent_art_styles', 'feng_shui', 'composition_zone'] as $text) {
            $this->assertStringNotContainsString($text, $prompt['prompt']);
        }
        $this->assertSame('wallpaper-creation-2026-10-22.txt', $prompt['filename']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[DataProvider('statuses')]
    public function test_json_and_image_are_saved_together_for_all_statuses(string $status): void
    {
        $payload = Fixtures::payload(status: $status);
        $response = $this->post('/wallpapers/proposals/manual-result', $this->submission($payload));
        $wallpaper = Wallpaper::query()->sole();
        $response->assertRedirect('/wallpapers/'.$wallpaper->id)->assertSessionHasNoErrors();
        $this->assertSame('approved', $wallpaper->chosenProposal->status);
        $this->assertSame($payload['kigaku'], $wallpaper->kigaku());
        $this->assertNull($wallpaper->composition_zone);
        $this->assertNull($wallpaper->chosenProposal->analysis_hash);
        Storage::disk('local')->assertExists($wallpaper->image_path);
        $size = getimagesizefromstring(Storage::disk('local')->get($wallpaper->image_path));
        $this->assertSame([1440, 2560], [$size[0], $size[1]]);
        $this->get('/wallpapers/'.$wallpaper->id)->assertOk();
        $this->assertDatabaseCount('api_runs', 0);
        $this->assertDatabaseCount('analysis_snapshots', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function statuses(): array
    {
        return [['auspicious'], ['none'], ['undetermined']];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_judgement_cannot_leave_records_or_files(string $path, mixed $value): void
    {
        $payload = Fixtures::payload();
        Arr::set($payload, $path, $value);
        $this->post('/wallpapers/proposals/manual-result', $this->submission($payload))->assertSessionHasErrors('proposal_json');
        $this->assertDatabaseCount('wallpapers', 0);
        $this->assertDatabaseCount('composition_proposals', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function invalidPayloads(): array
    {
        return [
            ['kigaku.target_date', '2026-10-23'], ['kigaku.personal_star', '一白水星'],
            ['kigaku.timezone', 'Asia/Tokyo'], ['kigaku.primary_direction', 'west'],
            ['kigaku.candidate_directions', ['north', 'north']], ['kigaku.candidate_directions', ['outside']],
            ['kigaku.candidate_directions', []], ['kigaku.motifs', []], ['kigaku.reason', ''],
            ['kigaku.status', 'invalid'], ['kigaku.status', 'none'], ['kigaku.extra', 'unexpected'],
            ['kigaku.references.0.url', 'javascript:alert(1)'], ['kigaku.references.0.url', 'file:///private'],
            ['title', str_repeat('a', 256)], ['kigaku', null], ['feng_shui', ['flow' => '静穏']],
        ];
    }

    public function test_fallback_requires_reason_and_empty_directions(): void
    {
        foreach (['none', 'undetermined'] as $status) {
            $payload = Fixtures::payload(status: $status);
            $payload['kigaku']['fallback_reason'] = null;
            $this->post('/wallpapers/proposals/manual-result', $this->submission($payload))->assertSessionHasErrors('proposal_json');
        }
        $this->assertDatabaseCount('wallpapers', 0);
    }

    public function test_stale_prompt_missing_image_and_duplicate_date_do_not_save_partial_results(): void
    {
        $data = $this->submission(Fixtures::payload());
        $data['prompt_hash'] = str_repeat('f', 64);
        $this->post('/wallpapers/proposals/manual-result', $data)->assertSessionHasErrors('proposal_json');
        $data = $this->submission(Fixtures::payload());
        unset($data['image']);
        $this->post('/wallpapers/proposals/manual-result', $data)->assertSessionHasErrors('image');
        $data = $this->submission(Fixtures::payload());
        Wallpaper::factory()->create(['target_date' => '2026-10-22']);
        $this->post('/wallpapers/proposals/manual-result', $data)->assertSessionHasErrors('target_date');
        $this->assertDatabaseCount('wallpapers', 1);
        $this->assertDatabaseCount('composition_proposals', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_invalid_json_and_non_image_upload_are_rejected(): void
    {
        $data = $this->submission(Fixtures::payload());
        $data['proposal_json'] = '{broken';
        $this->post('/wallpapers/proposals/manual-result', $data)->assertSessionHasErrors('proposal_json');
        $data = $this->submission(Fixtures::payload());
        $data['image'] = UploadedFile::fake()->create('bad.jpg', 1, 'text/plain');
        $this->post('/wallpapers/proposals/manual-result', $data)->assertSessionHasErrors('image');
        $this->assertDatabaseCount('wallpapers', 0);
    }

    public function test_storage_failure_keeps_database_empty(): void
    {
        $images = Mockery::mock(ImageService::class);
        $images->shouldReceive('normalizeAndStore')->once()->andThrow(new ExternalApiException('image_storage_failed', true));
        $this->app->instance(ImageService::class, $images);
        $this->post('/wallpapers/proposals/manual-result', $this->submission(Fixtures::payload()))->assertSessionHasErrors('image');
        $this->assertDatabaseCount('wallpapers', 0);
    }

    public function test_database_failure_removes_the_new_image_and_rolls_back_wallpaper(): void
    {
        $data = $this->submission(Fixtures::payload());
        $prompts = Mockery::mock(WallpaperPromptService::class)->makePartial();
        $prompts->shouldReceive('saveProposal')->once()->andThrow(new \RuntimeException('test save failure'));
        $this->app->instance(WallpaperPromptService::class, $prompts);
        $this->post('/wallpapers/proposals/manual-result', $data)->assertServerError();
        $this->assertDatabaseCount('wallpapers', 0);
        $this->assertDatabaseCount('composition_proposals', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_result_updates_do_not_change_a_creation_prompt(): void
    {
        $old = Wallpaper::factory()->create(['target_date' => '2026-10-01']);
        $before = app(WallpaperPromptService::class)->composition('2026-10-22');
        $this->put('/wallpapers/'.$old->id.'/result', ['prize_vnd' => 5000, 'purchase_count' => 2])->assertSessionHasNoErrors();
        $this->assertSame($before, app(WallpaperPromptService::class)->composition('2026-10-22'));
    }

    public function test_reproposal_registers_image_and_preserves_result_and_previous_proposal(): void
    {
        $wallpaper = Fixtures::wallpaper('2026-10-22', attributes: ['prize_vnd' => 700, 'purchase_count' => 2]);
        $previous = $wallpaper->chosen_proposal_id;
        $prompt = $this->getJson('/wallpapers/'.$wallpaper->id.'/proposals/manual-prompt')->assertOk()->json();
        $payload = Fixtures::payload(direction: 'west');
        $this->post('/wallpapers/'.$wallpaper->id.'/proposals/manual-result', [
            'proposal_json' => json_encode($payload), 'prompt_hash' => $prompt['prompt_hash'],
            'image' => UploadedFile::fake()->image('wallpaper.png', 9, 16),
        ])->assertRedirect('/wallpapers/'.$wallpaper->id)->assertSessionHasNoErrors();
        $this->assertSame(700, $wallpaper->refresh()->prize_vnd);
        $this->assertSame(2, $wallpaper->purchase_count);
        $this->assertNotSame($previous, $wallpaper->chosen_proposal_id);
        $this->assertSame('west', $wallpaper->kigaku()['primary_direction']);
        $this->assertSame(2, $wallpaper->proposals()->count());
    }

    public function test_local_image_and_active_notion_sync_prevent_reproposal(): void
    {
        $wallpaper = Fixtures::wallpaper('2026-10-22', attributes: ['image_disk' => 'local', 'image_path' => 'wallpapers/existing.jpg']);
        Storage::disk('local')->put('wallpapers/existing.jpg', 'original');
        $this->getJson('/wallpapers/'.$wallpaper->id.'/proposals/manual-prompt')->assertUnprocessable();
        $wallpaper->update(['image_path' => null]);
        SyncRun::query()->create(['type' => 'notion_result', 'wallpaper_id' => $wallpaper->id, 'status' => 'running']);
        $this->getJson('/wallpapers/'.$wallpaper->id.'/proposals/manual-prompt')->assertUnprocessable();
        $this->assertSame('original', Storage::disk('local')->get('wallpapers/existing.jpg'));
    }

    public function test_image_only_registration_supports_legacy_composition_without_guessing_direction(): void
    {
        $wallpaper = Wallpaper::factory()->create(['target_date' => '2026-10-22', 'source' => 'notion']);
        $prompt = $this->getJson('/wallpapers/'.$wallpaper->id.'/image/manual-prompt')->assertOk()->json();
        $this->assertStringNotContainsString('流れは静穏', $prompt['prompt']);
        $this->post('/wallpapers/'.$wallpaper->id.'/image/manual-result', [
            'prompt_hash' => $prompt['prompt_hash'], 'image' => UploadedFile::fake()->image('legacy.png'),
        ])->assertSessionHasNoErrors();
        $this->assertNull($wallpaper->refresh()->kigaku());
        Storage::disk('local')->assertExists($wallpaper->image_path);
        Http::assertNothingSent();
    }

    public function test_retired_api_and_analysis_routes_cannot_dispatch_work(): void
    {
        $wallpaper = Wallpaper::factory()->create();
        foreach (['/wallpaper-analyses', '/wallpaper-analyses/manual-prompt', '/wallpaper-analyses/manual-data',
            '/wallpaper-analyses/manual-result', '/wallpapers/proposals', '/wallpapers/'.$wallpaper->id.'/image', '/wallpapers/'.$wallpaper->id.'/repropose'] as $url) {
            $response = $this->post($url, ['api_confirmed' => true]);
            $this->assertContains($response->status(), [404, 405]);
        }
        $this->get('/wallpaper-analyses')->assertNotFound();
        $this->assertDatabaseCount('api_runs', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_legacy_api_run_is_retained_and_does_not_block_manual_creation(): void
    {
        $run = ApiRun::query()->create(['type' => 'composition_proposal', 'model' => 'old', 'prompt_version' => 'v1', 'input_hash' => str_repeat('b', 64), 'status' => 'queued']);
        $this->post('/wallpapers/proposals/manual-result', $this->submission(Fixtures::payload()))->assertSessionHasNoErrors();
        $this->assertSame('queued', $run->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_reproposal_prompt_becomes_stale_when_another_proposal_is_registered(): void
    {
        $wallpaper = Fixtures::wallpaper('2026-10-22');
        $prompt = $this->getJson('/wallpapers/'.$wallpaper->id.'/proposals/manual-prompt')->json();
        $other = $wallpaper->chosenProposal->replicate();
        $other->sequence = 2;
        $other->title = '別の構図';
        $other->save();
        $this->post('/wallpapers/'.$wallpaper->id.'/proposals/manual-result', [
            'proposal_json' => json_encode(Fixtures::payload()), 'prompt_hash' => $prompt['prompt_hash'],
            'image' => UploadedFile::fake()->image('stale.png'),
        ])->assertSessionHasErrors('proposal_json');
        $this->assertSame(2, $wallpaper->proposals()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_image_only_registration_cannot_use_another_wallpapers_proposal(): void
    {
        $owner = Fixtures::wallpaper('2026-10-22');
        $other = Wallpaper::factory()->create(['target_date' => '2026-10-23']);
        $this->post('/wallpapers/'.$other->id.'/image/manual-result', [
            'proposal_id' => $owner->chosen_proposal_id, 'image' => UploadedFile::fake()->image('foreign.png'),
        ])->assertNotFound();
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function submission(array $payload): array
    {
        $date = '2026-10-22';
        $prompt = $this->getJson('/wallpapers/proposals/manual-prompt?target_date='.$date)->assertOk()->json();

        return [
            'target_date' => $date, 'proposal_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'prompt_hash' => $prompt['prompt_hash'], 'image' => UploadedFile::fake()->image('wallpaper.png', 9, 16),
        ];
    }
}
