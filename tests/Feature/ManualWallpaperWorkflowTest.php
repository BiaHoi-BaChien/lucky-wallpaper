<?php

namespace Tests\Feature;

use App\Exceptions\ExternalApiException;
use App\Models\ApiRun;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\Wallpaper;
use App\Services\ImageService;
use App\Services\KigakuCalendar;
use App\Services\KigakuPolicy;
use App\Services\WallpaperPromptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
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
        foreach (['六白金星', '2026-10-22', 'Asia/Ho_Chi_Minh', 'candidate_directions', 'undetermined', '1440×2560', 'プレビュー', 'ダウンロード', '実際に使用',
            '日盤＞月盤＞年盤', 'fixed_kigaku', 'subject_position', 'direction_star',
            '北→北東→東→南東→南→南西→西→北西', '月命星は使わず', '上部30%',
            '画面上＝北、右＝東', 'star_motif', '南でも下降', '人工的な金色発光'] as $text) {
            $this->assertStringContainsString($text, $prompt['prompt']);
        }
        foreach (['過去の秘密の構図', '12345', 'historical_analysis_markdown', 'top_winners', 'recent_art_styles', 'feng_shui', 'composition_zone', '"references"', '判定方式はあなたが選び'] as $text) {
            $this->assertStringNotContainsString($text, $prompt['prompt']);
        }
        $this->assertStringContainsString('参照資料の一覧や出典名・出典URLは回答・JSONに含めないでください。referencesフィールドも出力しないでください。', $prompt['prompt']);
        $this->assertSame('wallpaper-creation-2026-10-22.txt', $prompt['filename']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[DataProvider('statuses')]
    public function test_json_and_image_are_saved_without_requesting_a_prompt_for_all_statuses(string $status): void
    {
        if ($status !== 'auspicious') {
            $calendar = Mockery::mock(KigakuCalendar::class);
            if ($status === 'none') {
                foreach (['day', 'month', 'year'] as $type) {
                    $calendar->shouldReceive('board')->with('2026-10-22', $type)->andReturn(['center_star' => 2, 'branch' => 10]);
                }
            } else {
                $calendar->shouldReceive('board')->with('2026-10-22', 'day')->andThrow(new \RuntimeException('unavailable'));
            }
            $this->app->instance(KigakuCalendar::class, $calendar);
        }
        $payload = Fixtures::currentPayload();
        $response = $this->post('/wallpapers/proposals/manual-result', $this->submission($payload));
        $wallpaper = Wallpaper::query()->sole();
        $response->assertRedirect('/wallpapers/'.$wallpaper->id)->assertSessionHasNoErrors();
        $this->assertSame('approved', $wallpaper->chosenProposal->status);
        $this->assertSame($payload['kigaku'], $wallpaper->kigaku());
        $this->assertNull($wallpaper->composition_zone);
        $this->assertNull($wallpaper->chosenProposal->analysis_hash);
        $this->assertSame($status, $wallpaper->kigaku()['status']);
        $this->assertSame(KigakuPolicy::VERSION, $wallpaper->chosenProposal->calendar_context['policy_version']);
        $this->assertStringContainsString('主役中心:', $wallpaper->compositionDetails());
        $imagePrompt = app(WallpaperPromptService::class)->image($wallpaper)['prompt'];
        $this->assertStringContainsString('左から'.$payload['kigaku']['subject_position']['x_percent'].'%', $imagePrompt);
        $this->assertStringContainsString('上部30%', $imagePrompt);
        Storage::disk('local')->assertExists($wallpaper->image_path);
        $size = getimagesizefromstring(Storage::disk('local')->get($wallpaper->image_path));
        $this->assertSame([1440, 2560], [$size[0], $size[1]]);
        $this->get('/wallpapers/'.$wallpaper->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('kigaku.references'));
        $this->assertDatabaseCount('api_runs', 0);
        $this->assertDatabaseCount('analysis_snapshots', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function statuses(): array
    {
        return [['auspicious'], ['none'], ['undetermined']];
    }

    public function test_existing_json_with_references_can_still_be_registered_and_displayed(): void
    {
        $payload = Fixtures::currentPayload();
        $payload['kigaku']['references'] = [['title' => '作成済みJSONの参照資料', 'url' => 'https://example.com/calendar']];
        $response = $this->post('/wallpapers/proposals/manual-result', $this->submission($payload));
        $wallpaper = Wallpaper::query()->sole();
        $response->assertRedirect('/wallpapers/'.$wallpaper->id)->assertSessionHasNoErrors();
        $this->get('/wallpapers/'.$wallpaper->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('kigaku.references', $payload['kigaku']['references']));
        $this->assertStringContainsString('参照資料: 作成済みJSONの参照資料 https://example.com/calendar', $wallpaper->compositionDetails());
    }

    public function test_creation_accepts_an_empty_prompt_hash_from_the_form(): void
    {
        $data = $this->submission(Fixtures::currentPayload());
        $data['prompt_hash'] = '';
        $this->post('/wallpapers/proposals/manual-result', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('wallpapers', 1);
        $this->assertDatabaseCount('composition_proposals', 1);
    }

    public function test_creation_still_accepts_the_displayed_prompt_hash(): void
    {
        $prompt = $this->getJson('/wallpapers/proposals/manual-prompt?target_date=2026-10-22')->assertOk()->json();
        $data = $this->submission(Fixtures::currentPayload());
        $data['prompt_hash'] = $prompt['prompt_hash'];
        $this->post('/wallpapers/proposals/manual-result', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('wallpapers', 1);
        $this->assertDatabaseCount('composition_proposals', 1);
    }

    public function test_old_unregistered_json_is_rejected_without_changing_an_existing_record(): void
    {
        $old = Fixtures::wallpaper('2026-10-21', attributes: ['prize_vnd' => 500, 'purchase_count' => 2]);
        $context = $old->chosenProposal->calendar_context;
        $this->post('/wallpapers/proposals/manual-result', $this->submission(Fixtures::payload()))->assertSessionHasErrors('proposal_json');
        $this->assertSame($context, $old->fresh()->chosenProposal->calendar_context);
        $this->assertSame(500, $old->fresh()->prize_vnd);
        $this->assertDatabaseCount('wallpapers', 1);
        $this->assertDatabaseCount('composition_proposals', 1);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_current_and_previous_records_share_the_same_direction_aggregation(): void
    {
        $old = Fixtures::wallpaper('2026-10-21', direction: 'east', attributes: ['prize_vnd' => 500]);
        $this->post('/wallpapers/proposals/manual-result', $this->submission(Fixtures::currentPayload()))->assertSessionHasNoErrors();
        $this->assertSame(2, Wallpaper::query()->filtered(['direction' => 'east'])->count());
        $this->assertSame('kigaku-v1', $old->fresh()->chosenProposal->calendar_context['policy_version']);
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_judgement_cannot_leave_records_or_files(string $path, mixed $value): void
    {
        $payload = Fixtures::currentPayload();
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
            ['kigaku.policy_version', 'kigaku-v1'], ['kigaku.selected_board', 'year'],
            ['kigaku.direction_star', 2], ['kigaku.subject_position.x_percent', 30],
            ['kigaku.motifs', [['motif' => '金貨', 'meaning' => '財宝']]],
            ['kigaku.references', [['title' => '参照資料', 'url' => 'javascript:alert(1)']]],
            ['kigaku.references', [['title' => '参照資料', 'url' => 'file:///private']]],
            ['title', str_repeat('a', 256)], ['kigaku', null], ['feng_shui', ['flow' => '静穏']],
        ];
    }

    public function test_fallback_requires_reason_and_empty_directions(): void
    {
        foreach (['none', 'undetermined'] as $status) {
            $payload = Fixtures::currentPayload();
            $payload['kigaku']['status'] = $status;
            $payload['kigaku']['fallback_reason'] = null;
            $this->post('/wallpapers/proposals/manual-result', $this->submission($payload))->assertSessionHasErrors('proposal_json');
        }
        $this->assertDatabaseCount('wallpapers', 0);
    }

    public function test_stale_prompt_missing_image_and_duplicate_date_do_not_save_partial_results(): void
    {
        $data = $this->submission(Fixtures::currentPayload());
        $data['prompt_hash'] = str_repeat('f', 64);
        $this->post('/wallpapers/proposals/manual-result', $data)->assertSessionHasErrors('proposal_json');
        $data = $this->submission(Fixtures::currentPayload());
        unset($data['image']);
        $this->post('/wallpapers/proposals/manual-result', $data)->assertSessionHasErrors('image');
        $data = $this->submission(Fixtures::currentPayload());
        Wallpaper::factory()->create(['target_date' => '2026-10-22']);
        $this->post('/wallpapers/proposals/manual-result', $data)->assertSessionHasErrors('target_date');
        $this->assertDatabaseCount('wallpapers', 1);
        $this->assertDatabaseCount('composition_proposals', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_invalid_json_and_non_image_upload_are_rejected(): void
    {
        $data = $this->submission(Fixtures::currentPayload());
        $data['proposal_json'] = '{broken';
        $this->post('/wallpapers/proposals/manual-result', $data)->assertSessionHasErrors('proposal_json');
        $data = $this->submission(Fixtures::currentPayload());
        $data['image'] = UploadedFile::fake()->create('bad.jpg', 1, 'text/plain');
        $this->post('/wallpapers/proposals/manual-result', $data)->assertSessionHasErrors('image');
        $this->assertDatabaseCount('wallpapers', 0);
    }

    public function test_storage_failure_keeps_database_empty(): void
    {
        $images = Mockery::mock(ImageService::class);
        $images->shouldReceive('normalizeAndStore')->once()->andThrow(new ExternalApiException('image_storage_failed', true));
        $this->app->instance(ImageService::class, $images);
        $this->post('/wallpapers/proposals/manual-result', $this->submission(Fixtures::currentPayload()))->assertSessionHasErrors('image');
        $this->assertDatabaseCount('wallpapers', 0);
    }

    public function test_database_failure_removes_the_new_image_and_rolls_back_wallpaper(): void
    {
        $data = $this->submission(Fixtures::currentPayload());
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
        $this->assertStringContainsString('日盤＞月盤＞年盤の優先方式', $prompt['prompt']);
        $payload = Fixtures::currentPayload();
        $payload['title'] = '再作成した山の稜線';
        $this->post('/wallpapers/'.$wallpaper->id.'/proposals/manual-result', [
            'proposal_json' => json_encode($payload), 'prompt_hash' => $prompt['prompt_hash'],
            'image' => UploadedFile::fake()->image('wallpaper.png', 9, 16),
        ])->assertRedirect('/wallpapers/'.$wallpaper->id)->assertSessionHasNoErrors();
        $this->assertSame(700, $wallpaper->refresh()->prize_vnd);
        $this->assertSame(2, $wallpaper->purchase_count);
        $this->assertNotSame($previous, $wallpaper->chosen_proposal_id);
        $this->assertSame('east', $wallpaper->kigaku()['primary_direction']);
        $this->assertSame('north', $wallpaper->proposals()->findOrFail($previous)->calendar_context['kigaku']['primary_direction']);
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
        $this->post('/wallpapers/proposals/manual-result', $this->submission(Fixtures::currentPayload()))->assertSessionHasNoErrors();
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
            'proposal_json' => json_encode(Fixtures::currentPayload()), 'prompt_hash' => $prompt['prompt_hash'],
            'image' => UploadedFile::fake()->image('stale.png'),
        ])->assertSessionHasErrors('proposal_json');
        $this->assertSame(2, $wallpaper->proposals()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_reproposal_still_requires_a_prompt_hash(): void
    {
        $wallpaper = Fixtures::wallpaper('2026-10-22');
        $this->post('/wallpapers/'.$wallpaper->id.'/proposals/manual-result', $this->submission(Fixtures::currentPayload()))
            ->assertSessionHasErrors('prompt_hash');
        $this->assertSame(1, $wallpaper->proposals()->count());
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

        return [
            'target_date' => $date, 'proposal_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'image' => UploadedFile::fake()->image('wallpaper.png', 9, 16),
        ];
    }
}
