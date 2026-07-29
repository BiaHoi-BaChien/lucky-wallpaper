<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_profile_and_account_deletion_routes_are_not_exposed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings/profile')->assertNotFound();
        $this->actingAs($user)->delete('/settings/profile')->assertNotFound();
        $this->assertDatabaseCount('users', 1);
    }
}
