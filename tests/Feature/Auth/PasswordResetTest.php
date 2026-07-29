<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_password_reset_routes_are_not_exposed(): void
    {
        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password')->assertNotFound();
    }
}
