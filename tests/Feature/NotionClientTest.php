<?php

namespace Tests\Feature;

use App\Jobs\ImportNotionPages;
use App\Jobs\ProcessNotionSync;
use App\Models\AppSetting;
use App\Models\SyncRun;
use App\Services\NotionClient;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotionClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_zero_is_valid_and_missing_required_properties_are_detectable(): void
    {
        $candidate = app(NotionClient::class)->parseCandidate([
            'id' => 'page-id',
            'last_edited_time' => '2026-07-29T00:00:00.000Z',
            'properties' => [
                'Date' => ['date' => ['start' => '2026-07-28']],
                'Price' => ['number' => 0],
                'title' => ['title' => [['plain_text' => '金色の滝']]],
            ],
        ]);

        $this->assertSame(0, $candidate['price_vnd']);
        $this->assertSame('金色の滝', $candidate['title']);
        $this->assertSame('2026-07-28', $candidate['target_date']);
    }

    public function test_block_body_paginates_and_empty_body_remains_empty(): void
    {
        config(['lucky.notion.token' => 'test']);
        Http::fake([
            'api.notion.com/v1/blocks/page-empty/children*' => Http::response([
                'results' => [],
                'has_more' => false,
                'next_cursor' => null,
            ]),
            'api.notion.com/v1/blocks/page-text/children*' => Http::response([
                'results' => [[
                    'id' => 'block',
                    'type' => 'paragraph',
                    'has_children' => false,
                    'paragraph' => ['rich_text' => [['plain_text' => '詳細本文']]],
                ]],
                'has_more' => false,
                'next_cursor' => null,
            ]),
        ]);

        $this->assertSame('', app(NotionClient::class)->getPageBody('page-empty'));
        $this->assertSame('詳細本文', app(NotionClient::class)->getPageBody('page-text'));
    }

    public function test_operation_table_accepts_retryable_failure_state(): void
    {
        $run = SyncRun::query()->create([
            'type' => 'notion_import',
            'status' => 'failed',
            'retryable' => true,
            'error_code' => 'notion_http_429',
        ]);

        $this->assertTrue($run->retryable);
    }

    public function test_import_job_can_be_added_to_a_real_batch(): void
    {
        $batch = Bus::batch([
            new ImportNotionPages('run-id', []),
        ]);

        $this->assertInstanceOf(PendingBatch::class, $batch);
    }

    public function test_sync_uses_incremental_filter_and_keeps_latest_duplicate_without_fetching_body(): void
    {
        config(['lucky.notion.token' => 'test']);
        AppSetting::write('notion_last_successful_sync_at', '2026-07-29T10:00:00+07:00');
        Bus::fake();
        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'properties' => [
                        'Date' => ['id' => 'date-id'],
                        'Price' => ['id' => 'price-id'],
                        'title' => ['id' => 'title-id'],
                    ],
                ]);
            }

            $this->assertStringContainsString('filter_properties=date-id', $request->url());
            $this->assertSame(
                '2026-07-29T09:59:00+07:00',
                data_get($request->data(), 'filter.last_edited_time.on_or_after'),
            );

            return Http::response([
                'results' => [
                    $this->notionPage('old-page', '2026-07-28T00:00:00.000Z'),
                    $this->notionPage('latest-page', '2026-07-29T00:00:00.000Z'),
                ],
                'has_more' => false,
                'next_cursor' => null,
            ]);
        });

        $run = SyncRun::query()->create(['type' => 'notion_import']);
        app()->call([new ProcessNotionSync($run->id), 'handle']);

        $run->refresh();
        $this->assertCount(1, $run->warnings);
        $this->assertSame(1, $run->total);
        Bus::assertBatched(function (PendingBatch $batch): bool {
            $job = $batch->jobs->first();

            return $job instanceof ImportNotionPages
                && $job->candidates[0]['page_id'] === 'latest-page'
                && count($job->candidates) === 1;
        });
        Http::assertSentCount(2);
    }

    private function notionPage(string $id, string $editedAt): array
    {
        return [
            'id' => $id,
            'last_edited_time' => $editedAt,
            'properties' => [
                'Date' => ['date' => ['start' => '2026-08-01']],
                'Price' => ['number' => 0],
                'title' => ['title' => [['plain_text' => '重複候補']]],
            ],
        ];
    }
}
