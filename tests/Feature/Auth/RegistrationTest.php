<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_setup_screen_is_only_available_when_no_user_exists(): void
    {
        $this->get('/setup')->assertOk();

        User::factory()->create();

        $this->get('/setup')->assertNotFound();
    }

    public function test_setup_requires_configured_key_and_creates_single_argon2id_admin(): void
    {
        config(['lucky.setup_key' => 'test-setup-key']);

        $this->post('/setup', [
            'setup_key' => 'wrong',
            'username' => 'admin',
            'name' => '管理者',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
        ])->assertSessionHasErrors('setup_key');

        $response = $this->post('/setup', [
            'setup_key' => 'test-setup-key',
            'username' => 'admin',
            'name' => '管理者',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();
        $user = User::query()->sole();
        $this->assertSame('argon2id', Hash::info($user->password)['algoName']);
        auth()->logout();
        $this->post('/setup', [
            'setup_key' => 'test-setup-key',
            'username' => 'another',
            'name' => '別管理者',
            'password' => 'StrongPassword456',
            'password_confirmation' => 'StrongPassword456',
        ])->assertNotFound();
        $this->assertDatabaseCount('users', 1);
    }
}
