<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_authentication_routes_are_not_exposed(): void
    {
        $this->get('/verify-email')->assertNotFound();
        $this->post('/email/verification-notification')->assertNotFound();
    }
}
