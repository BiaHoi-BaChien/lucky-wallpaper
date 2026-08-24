<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'NewStrongPassword123',
                'password_confirmation' => 'NewStrongPassword123',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/password');

        $this->assertTrue(Hash::check('NewStrongPassword123', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password()
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create(['remember_token' => 'existing-remember-token']);

        DB::table('sessions')->insert([
            'id' => 'session-before-rejected-update',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $response = $this
            ->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'wrong-password',
                'password' => 'NewStrongPassword123',
                'password_confirmation' => 'NewStrongPassword123',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect('/settings/password');

        $this->assertDatabaseHas('sessions', ['id' => 'session-before-rejected-update']);
        $this->assertSame('existing-remember-token', $user->refresh()->getRememberToken());
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_password_update_invalidates_existing_sessions_and_remember_token(): void
    {
        config(['session.driver' => 'database']);

        $user = User::factory()->create(['remember_token' => 'old-remember-token']);
        $otherUser = User::factory()->create();

        DB::table('sessions')->insert([
            [
                'id' => 'existing-session',
                'user_id' => $user->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'payload' => '',
                'last_activity' => time(),
            ],
            [
                'id' => 'other-user-session',
                'user_id' => $otherUser->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'payload' => '',
                'last_activity' => time(),
            ],
        ]);

        $this->actingAs($user)
            ->from('/settings/password')
            ->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'NewStrongPassword123',
                'password_confirmation' => 'NewStrongPassword123',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/password');

        $this->assertDatabaseMissing('sessions', ['id' => 'existing-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-user-session']);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'remember_token' => null]);
        $this->assertAuthenticatedAs($user);
    }
}
