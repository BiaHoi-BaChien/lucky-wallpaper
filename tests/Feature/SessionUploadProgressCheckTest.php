<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionUploadProgressCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/settings/session-upload-progress-check')->assertRedirect('/login');
    }

    public function test_the_sole_user_can_read_only_the_raw_effective_value_without_caching(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/settings/session-upload-progress-check');

        $response->assertOk()->assertExactJson([
            'session.upload_progress.enabled' => ini_get('session.upload_progress.enabled'),
        ]);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        foreach (['private', 'no-store', 'max-age=0'] as $directive) {
            $this->assertStringContainsString($directive, $cacheControl);
        }
    }

    public function test_access_is_forbidden_when_multiple_users_exist(): void
    {
        $users = User::factory()->count(2)->create();

        $this->actingAs($users->first())
            ->get('/settings/session-upload-progress-check')->assertForbidden();
    }
}
