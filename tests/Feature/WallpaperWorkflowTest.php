<?php

namespace Tests\Feature;

use App\Jobs\GenerateCompositionProposal;
use App\Jobs\GenerateWallpaperImage;
use App\Jobs\SyncWallpaperResultToNotion;
use App\Models\AnalysisSnapshot;
use App\Models\ApiRun;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\Wallpaper;
use App\Services\HistoricalAnalysisService;
use App\Services\ImageService;
use App\Services\OpenAiClient;
use App\Services\WallpaperPromptService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Mockery;
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

        $this->actingAs($user)->post('/wallpapers/proposals', ['target_date' => '2026-08-01', 'api_confirmed' => true])
            ->assertRedirect('/wallpapers/'.$wallpaper->id);

        Queue::assertNotPushed(GenerateCompositionProposal::class);
        $this->assertDatabaseCount('wallpapers', 1);
    }

    public function test_valid_new_date_queues_one_proposal(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->createCurrentAnalysis();

        $this->actingAs($user)->post('/wallpapers/proposals', ['target_date' => '2026-08-02', 'api_confirmed' => true])
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

    public function test_local_image_is_displayed_as_an_inline_preview(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'image_disk' => 'local',
            'image_path' => 'wallpapers/preview.jpg',
            'image_mime' => 'image/jpeg',
        ]);
        Storage::disk('local')->put('wallpapers/preview.jpg', 'image-bytes');

        $response = $this->actingAs($user)
            ->get("/wallpapers/{$wallpaper->id}/preview")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg')
            ->assertHeader('content-disposition', 'inline; filename='.$wallpaper->target_date->format('Y-m-d').'-lucky-wallpaper.jpg');
        $this->assertSame('image-bytes', $response->streamedContent());

        $this->actingAs($user)->get("/wallpapers/{$wallpaper->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('wallpapers/show', false)
                ->where('localImageAvailable', true)
                ->where('downloadAvailable', true));
    }

    public function test_notion_html_is_rejected_instead_of_being_displayed_inline(): void
    {
        config(['lucky.notion.token' => 'test']);
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'image_disk' => null,
            'image_path' => null,
            'notion_page_id' => 'notion-page-id',
        ]);
        $fileUrl = 'https://files.example.com/notion-wallpaper.html';
        $script = '<script>window.location="/settings"</script>';
        Http::fake([
            'api.notion.com/v1/pages/notion-page-id' => Http::response($this->notionPage($fileUrl)),
            $fileUrl => Http::response($script, 200, ['Content-Type' => 'text/html; charset=utf-8']),
        ]);

        $response = $this->actingAs($user)
            ->get("/wallpapers/{$wallpaper->id}/preview")
            ->assertStatus(502);

        $this->assertNotSame($script, $response->getContent());
    }

    public function test_notion_image_is_reencoded_as_a_fixed_inline_jpeg(): void
    {
        config(['lucky.notion.token' => 'test']);
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'image_disk' => null,
            'image_path' => null,
            'notion_page_id' => 'notion-page-id',
        ]);
        $fileUrl = 'https://files.example.com/notion-wallpaper.png';
        Http::fake([
            'api.notion.com/v1/pages/notion-page-id' => Http::response($this->notionPage($fileUrl)),
            $fileUrl => Http::response($this->pngBytes(), 200, ['Content-Type' => 'text/html']),
        ]);

        $response = $this->actingAs($user)
            ->get("/wallpapers/{$wallpaper->id}/preview")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg')
            ->assertHeader('content-disposition', 'inline; filename="'.$wallpaper->target_date->format('Y-m-d').'-lucky-wallpaper.jpg"')
            ->assertHeader('x-content-type-options', 'nosniff');

        $size = getimagesizefromstring((string) $response->getContent());
        $this->assertIsArray($size);
        $this->assertSame('image/jpeg', $size['mime']);
    }

    public function test_notion_image_download_is_reencoded_as_a_fixed_attachment_jpeg(): void
    {
        config(['lucky.notion.token' => 'test']);
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'image_disk' => null,
            'image_path' => null,
            'notion_page_id' => 'notion-page-id',
        ]);
        $fileUrl = 'https://files.example.com/notion-wallpaper.png';
        Http::fake([
            'api.notion.com/v1/pages/notion-page-id' => Http::response($this->notionPage($fileUrl)),
            $fileUrl => Http::response($this->pngBytes(), 200, ['Content-Type' => 'image/png']),
        ]);

        $response = $this->actingAs($user)
            ->get("/wallpapers/{$wallpaper->id}/download")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg')
            ->assertHeader('content-disposition', 'attachment; filename="'.$wallpaper->target_date->format('Y-m-d').'-lucky-wallpaper.jpg"')
            ->assertHeader('x-content-type-options', 'nosniff');
        $size = getimagesizefromstring((string) $response->getContent());
        $this->assertIsArray($size);
        $this->assertSame('image/jpeg', $size['mime']);
    }

    public function test_oversized_notion_image_is_rejected_before_display(): void
    {
        config(['lucky.notion.token' => 'test']);
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'image_disk' => null,
            'image_path' => null,
            'notion_page_id' => 'notion-page-id',
        ]);
        $fileUrl = 'https://files.example.com/notion-wallpaper.png';
        $png = $this->pngBytes();
        config(['lucky.notion.max_download_bytes' => strlen($png) - 1]);
        Http::fake([
            'api.notion.com/v1/pages/notion-page-id' => Http::response($this->notionPage($fileUrl)),
            $fileUrl => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $this->actingAs($user)
            ->get("/wallpapers/{$wallpaper->id}/preview")
            ->assertStatus(502);
    }

    public function test_notion_image_over_content_length_limit_is_rejected_before_display(): void
    {
        config([
            'lucky.notion.token' => 'test',
            'lucky.notion.max_download_bytes' => 100,
        ]);
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'image_disk' => null,
            'image_path' => null,
            'notion_page_id' => 'notion-page-id',
        ]);
        $fileUrl = 'https://files.example.com/notion-wallpaper.png';
        Http::fake([
            'api.notion.com/v1/pages/notion-page-id' => Http::response($this->notionPage($fileUrl)),
            $fileUrl => Http::response(
                $this->pngBytes(),
                200,
                ['Content-Type' => 'image/png', 'Content-Length' => '101'],
            ),
        ]);

        $this->actingAs($user)
            ->get("/wallpapers/{$wallpaper->id}/preview")
            ->assertStatus(502);
    }

    public function test_missing_local_image_is_not_displayed_as_a_preview(): void
    {
        config(['lucky.notion.token' => 'test']);
        Storage::fake('local');
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'image_disk' => 'local',
            'image_path' => 'wallpapers/missing.jpg',
            'notion_page_id' => 'notion-page-id',
        ]);

        $this->actingAs($user)->get("/wallpapers/{$wallpaper->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('wallpapers/show', false)
                ->where('localImageAvailable', false)
                ->where('downloadAvailable', true)
                ->where('downloadUnavailableReason', null));
    }

    public function test_imported_composition_details_can_queue_image_regeneration_without_a_proposal(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'source' => 'notion',
            'state' => 'archived',
            'image_disk' => null,
            'image_path' => null,
            'notion_page_id' => null,
        ]);

        $this->actingAs($user)
            ->post("/wallpapers/{$wallpaper->id}/image", ['api_confirmed' => true])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Queue::assertPushed(
            GenerateWallpaperImage::class,
            fn (GenerateWallpaperImage $job): bool => $job->wallpaperId === $wallpaper->id
                && $job->proposalId === null,
        );
        $this->assertDatabaseHas('api_runs', [
            'subject_id' => $wallpaper->id,
            'type' => 'image_generation',
            'status' => 'queued',
        ]);
    }

    public function test_image_regeneration_requires_composition_details(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'title' => null,
            'conclusion' => null,
            'overview' => null,
            'composition' => null,
            'color_wu_xing' => null,
            'symbolism' => null,
            'image_disk' => null,
            'image_path' => null,
        ]);

        $this->actingAs($user)
            ->post("/wallpapers/{$wallpaper->id}/image", ['api_confirmed' => true])
            ->assertSessionHasErrors('image');

        Queue::assertNotPushed(GenerateWallpaperImage::class);
        $this->assertDatabaseCount('api_runs', 0);
    }

    public function test_active_image_generation_cannot_be_queued_twice(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'image_disk' => null,
            'image_path' => null,
        ]);
        ApiRun::query()->create([
            'type' => 'image_generation',
            'model' => 'test-image-model',
            'prompt_version' => 'v1',
            'input_hash' => str_repeat('a', 64),
            'subject_type' => $wallpaper->getMorphClass(),
            'subject_id' => $wallpaper->id,
            'status' => 'running',
        ]);

        $this->actingAs($user)
            ->post("/wallpapers/{$wallpaper->id}/image", ['api_confirmed' => true])
            ->assertSessionHasErrors('image');

        Queue::assertNotPushed(GenerateWallpaperImage::class);
        $this->assertDatabaseCount('api_runs', 1);
    }

    public function test_image_regeneration_job_uses_saved_composition_details_without_a_proposal(): void
    {
        $wallpaper = Wallpaper::factory()->create([
            'title' => '保存済みの構図名',
            'art_style' => '保存済みの画風',
            'overview' => '保存済みの概要',
            'composition' => '保存済みの配置',
            'color_wu_xing' => '保存済みの色彩',
            'symbolism' => '保存済みの象徴意図',
            'image_disk' => null,
            'image_path' => null,
        ]);
        $run = ApiRun::query()->create([
            'type' => 'image_generation',
            'model' => 'test-image-model',
            'prompt_version' => 'v1',
            'input_hash' => str_repeat('b', 64),
            'subject_type' => $wallpaper->getMorphClass(),
            'subject_id' => $wallpaper->id,
        ]);
        $openAi = Mockery::mock(OpenAiClient::class);
        $openAi->shouldReceive('image')
            ->once()
            ->with(
                Mockery::on(fn (ApiRun $actual): bool => $actual->is($run)),
                Mockery::on(fn (string $prompt): bool => str_contains($prompt, '構図名: 保存済みの構図名')
                    && str_contains($prompt, '画風: 保存済みの画風')
                    && str_contains($prompt, '配置: 保存済みの配置')),
            )
            ->andReturn('generated-image-bytes');
        $images = Mockery::mock(ImageService::class);
        $images->shouldReceive('normalizeAndStore')
            ->once()
            ->with('generated-image-bytes')
            ->andReturn([
                'disk' => 'local',
                'path' => 'wallpapers/regenerated.jpg',
                'mime' => 'image/jpeg',
                'bytes' => 123,
                'sha256' => str_repeat('c', 64),
            ]);

        (new GenerateWallpaperImage($wallpaper->id, null, $run->id))->handle(
            $openAi,
            $images,
            app(WallpaperPromptService::class),
        );

        $this->assertDatabaseHas('wallpapers', [
            'id' => $wallpaper->id,
            'chosen_proposal_id' => null,
            'image_path' => 'wallpapers/regenerated.jpg',
            'state' => 'generated',
        ]);
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

    private function notionPage(string $fileUrl): array
    {
        return [
            'properties' => [
                config('lucky.notion.property_wallpaper') => [
                    'files' => [[
                        'type' => 'file',
                        'file' => ['url' => $fileUrl],
                    ]],
                ],
            ],
        ];
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(2, 3);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return is_string($bytes) ? $bytes : '';
    }
}
