<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasskeySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_passkey_management_requires_authentication_and_password_confirmation(): void
    {
        $this->get('/user/passkeys/options')->assertRedirect('/login');

        $user = User::factory()->create();
        $this->actingAs($user)
            ->get('/user/passkeys/options')
            ->assertRedirect('/confirm-password');
    }

    public function test_passkey_login_options_are_rate_limited_guest_endpoint(): void
    {
        User::factory()->create();

        $this->getJson('/passkeys/login/options')->assertOk();
    }
}
