<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_password_screen_can_be_rendered()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/confirm-password');

        $response->assertStatus(200);
    }

    public function test_password_can_be_confirmed()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
    }

    public function test_password_is_not_confirmed_with_invalid_password()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors();
    }

    public function test_password_confirmation_is_rate_limited_after_five_failures()
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($user)->post('/confirm-password', [
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('password');
        }

        $this->actingAs($user)->post('/confirm-password', [
            'password' => 'wrong-password',
        ])->assertTooManyRequests();
    }

    public function test_successful_password_confirmation_clears_failed_attempts()
    {
        $user = User::factory()->create();

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->actingAs($user)->post('/confirm-password', [
                'password' => 'wrong-password',
            ]);
        }

        $this->actingAs($user)->post('/confirm-password', [
            'password' => 'password',
        ])->assertRedirect();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($user)->post('/confirm-password', [
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('password');
        }
    }
}
