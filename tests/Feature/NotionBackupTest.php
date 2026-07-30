<?php

namespace Tests\Feature;

use App\Jobs\ProcessNotionSync;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class NotionBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_shares_notion_configuration_without_exposing_token(): void
    {
        config(['lucky.notion.token' => 'secret-test-token']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/settings/notion-backup')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('settings/notion-backup', false)
                ->where('integrations.notion.configured', true)
                ->where('latestRestore', null)
                ->missing('integrations.notion.token'));
    }

    public function test_restore_is_rejected_without_creating_a_run_when_notion_is_not_configured(): void
    {
        config(['lucky.notion.token' => null]);
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/notion-backup')
            ->post('/notion-syncs')
            ->assertRedirect('/settings/notion-backup')
            ->assertSessionHasErrors([
                'restore' => 'NOTION_TOKENが未設定のため、バックアップから復元できません。',
            ]);

        $this->assertDatabaseCount('sync_runs', 0);
        Queue::assertNotPushed(ProcessNotionSync::class);
    }

    public function test_restore_queues_the_existing_import_job_when_notion_is_configured(): void
    {
        config(['lucky.notion.token' => 'test']);
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings/notion-backup')
            ->post('/notion-syncs')
            ->assertRedirect('/settings/notion-backup')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('operationId');

        $run = SyncRun::query()->sole();
        $this->assertSame('notion_import', $run->type);
        Queue::assertPushed(
            ProcessNotionSync::class,
            fn (ProcessNotionSync $job): bool => $job->runId === $run->id,
        );
    }
}
