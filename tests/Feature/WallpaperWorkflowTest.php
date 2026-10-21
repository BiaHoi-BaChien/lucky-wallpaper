<?php

namespace Tests\Feature;

use App\Jobs\SyncWallpaperResultToNotion;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\Wallpaper;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_result_screen_reports_an_existing_image_as_available(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'image_disk' => 'local',
            'image_path' => 'wallpapers/result.jpg',
        ]);
        Storage::disk('local')->put('wallpapers/result.jpg', 'image-bytes');

        $this->actingAs($user)
            ->get('/results?date='.$wallpaper->target_date->format('Y-m-d'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('results/index', false)
                ->where('wallpaper.id', $wallpaper->id)
                ->where('imageAvailable', true));
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

    public function test_wallpaper_detail_receives_nearest_previous_and_next_dates(): void
    {
        $user = User::factory()->create();
        $previous = Wallpaper::factory()->create(['target_date' => '2026-08-01']);
        $current = Wallpaper::factory()->create(['target_date' => '2026-08-05']);
        $next = Wallpaper::factory()->create(['target_date' => '2026-08-10']);

        $this->actingAs($user)->get("/wallpapers/{$current->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('wallpapers/show', false)
                ->where('previousWallpaperId', $previous->id)
                ->where('nextWallpaperId', $next->id));
    }

    public function test_wallpaper_html_is_not_cached_for_browser_restoration(): void
    {
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create();

        $this->actingAs($user)->get("/wallpapers/{$wallpaper->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Vary', 'X-Inertia')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('wallpapers/show', false)
                ->where('wallpaper.id', $wallpaper->id));
    }

    public function test_wallpaper_inertia_json_is_not_cached_and_a_reload_returns_html(): void
    {
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create();
        $url = "/wallpapers/{$wallpaper->id}";
        $html = $this->actingAs($user)->get($url)->assertOk();

        $this->get($url, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $html->viewData('page')['version'] ?? '',
        ])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeader('X-Inertia', 'true')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Vary', 'X-Inertia')
            ->assertJsonPath('component', 'wallpapers/show')
            ->assertJsonPath('props.wallpaper.id', $wallpaper->id);

        $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Vary', 'X-Inertia')
            ->assertHeaderMissing('X-Inertia')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('wallpapers/show', false)
                ->where('wallpaper.id', $wallpaper->id));
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

    public function test_purchase_count_is_optional_positive_integer(): void
    {
        config(['lucky.notion.token' => 'test']);
        Queue::fake();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create(['prize_vnd' => 1_000]);

        foreach (['0', '-1', '1.5'] as $purchaseCount) {
            $this->actingAs($user)->put("/wallpapers/{$wallpaper->id}/result", [
                'prize_vnd' => '1000',
                'purchase_count' => $purchaseCount,
            ])->assertSessionHasErrors('purchase_count');
        }

        $this->actingAs($user)->put("/wallpapers/{$wallpaper->id}/result", [
            'prize_vnd' => '1000',
            'purchase_count' => '',
        ])->assertSessionHasNoErrors();
        $this->assertNull($wallpaper->refresh()->purchase_count);

        $this->actingAs($user)->put("/wallpapers/{$wallpaper->id}/result", [
            'prize_vnd' => '1000',
            'purchase_count' => '2',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, $wallpaper->refresh()->purchase_count);
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
        config(['lucky.notion.token' => 'test']);
        Storage::fake('local');
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'image_disk' => 'local',
            'image_path' => 'wallpapers/preview.jpg',
            'image_mime' => 'image/jpeg',
            'notion_page_id' => 'notion-page-id',
        ]);
        Storage::disk('local')->put('wallpapers/preview.jpg', 'image-bytes');

        $response = $this->actingAs($user)
            ->get("/wallpapers/{$wallpaper->id}/preview")
            ->assertOk()
            ->assertHeader('content-type', 'image/jpeg')
            ->assertHeader('Cache-Control', 'max-age=300, private')
            ->assertHeader('content-disposition', 'inline; filename='.$wallpaper->target_date->format('Y-m-d').'-lucky-wallpaper.jpg');
        $this->assertSame('image-bytes', $response->streamedContent());

        $this->actingAs($user)->get("/wallpapers/{$wallpaper->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('wallpapers/show', false)
                ->where('localImageAvailable', true)
                ->where('downloadAvailable', true));

        $this->get('/results?date='.$wallpaper->target_date->format('Y-m-d'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('imageAvailable', true));

        Http::assertNothingSent();
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

    public function test_missing_local_image_uses_a_verified_notion_image(): void
    {
        config(['lucky.notion.token' => 'test']);
        Storage::fake('local');
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'image_disk' => 'local',
            'image_path' => 'wallpapers/missing.jpg',
            'notion_page_id' => 'notion-page-id',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'api.notion.com/v1/pages/notion-page-id' => Http::response($this->notionPage('https://files.example.com/wallpaper.jpg')),
        ]);

        $this->actingAs($user)->get("/wallpapers/{$wallpaper->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('wallpapers/show', false)
                ->where('localImageAvailable', false)
                ->where('downloadAvailable', true)
                ->where('downloadUnavailableReason', null));

        $this->get('/results?date='.$wallpaper->target_date->format('Y-m-d'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('imageAvailable', true));

        Http::assertSentCount(2);
    }

    public function test_notion_page_without_a_wallpaper_file_does_not_offer_image_controls(): void
    {
        config(['lucky.notion.token' => 'test']);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create([
            'source' => 'notion',
            'state' => 'imported',
            'composition' => '画像のないNotionページから取り込んだ構図の説明',
            'notion_page_id' => 'notion-page-id',
        ]);
        Http::fake([
            'api.notion.com/v1/pages/notion-page-id' => Http::sequence()
                ->push(['properties' => [config('lucky.notion.property_wallpaper') => ['files' => []]]])
                ->push(['properties' => []]),
        ]);

        $this->actingAs($user)->get("/wallpapers/{$wallpaper->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('wallpapers/show', false)
                ->where('wallpaper.composition', $wallpaper->composition)
                ->where('localImageAvailable', false)
                ->where('downloadAvailable', false));

        $this->get('/results?date='.$wallpaper->target_date->format('Y-m-d'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('wallpaper.composition', $wallpaper->composition)
                ->where('imageAvailable', false));

        Http::assertSentCount(2);
    }

    #[DataProvider('unavailableNotionStatuses')]
    public function test_notion_page_lookup_failure_keeps_the_detail_and_result_pages_available(int $status): void
    {
        config(['lucky.notion.token' => 'test']);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create(['notion_page_id' => 'notion-page-id']);
        Http::fake([
            'api.notion.com/v1/pages/notion-page-id' => function (Request $request, array $options) use ($status) {
                $this->assertSame(3, $options['timeout']);
                $this->assertSame(2, $options['connect_timeout']);
                $this->assertFalse($options['allow_redirects']);

                return Http::response(['code' => 'unavailable'], $status, ['Retry-After' => '1']);
            },
        ]);

        $this->actingAs($user)->get("/wallpapers/{$wallpaper->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('wallpaper.composition', $wallpaper->composition)
                ->where('downloadAvailable', false)
                ->where('downloadUnavailableReason', 'Notionバックアップの画像を確認できませんでした。Notionページの画像ファイルと接続設定を確認してください。'));
        Http::assertSentCount(1);

        $this->get('/results?date='.$wallpaper->target_date->format('Y-m-d'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('imageAvailable', false));

        Http::assertSentCount(2);
    }

    public static function unavailableNotionStatuses(): array
    {
        return ['missing page' => [404], 'rate limit' => [429], 'service unavailable' => [503]];
    }

    public function test_notion_connection_failure_keeps_the_detail_and_result_pages_available(): void
    {
        config(['lucky.notion.token' => 'test']);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create(['notion_page_id' => 'notion-page-id']);
        Http::fake(['api.notion.com/v1/pages/notion-page-id' => Http::failedConnection()]);

        $this->actingAs($user)->get("/wallpapers/{$wallpaper->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('wallpaper.composition', $wallpaper->composition)
                ->where('downloadAvailable', false));

        $this->get('/results?date='.$wallpaper->target_date->format('Y-m-d'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('imageAvailable', false));
    }

    public function test_wallpaper_without_a_local_image_or_notion_page_does_not_call_notion(): void
    {
        config(['lucky.notion.token' => 'test']);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $wallpaper = Wallpaper::factory()->create();

        $this->actingAs($user)->get("/wallpapers/{$wallpaper->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('downloadAvailable', false)
                ->where('downloadUnavailableReason', null));

        $this->get('/results?date='.$wallpaper->target_date->format('Y-m-d'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('imageAvailable', false));

        Http::assertNothingSent();
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
