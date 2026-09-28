<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Passkey;
use Mockery\MockInterface;
use ParagonIE\ConstantTime\Base64UrlSafe;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SessionRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('session-regression');
        config([
            'session.driver' => 'file',
            'session.files' => Storage::disk('session-regression')->path(''),
            'session.lottery' => [0, 100],
        ]);
        $this->newClient();
    }

    public static function sessionDrivers(): array
    {
        return [['file'], ['database']];
    }

    #[DataProvider('sessionDrivers')]
    public function test_unused_login_session_is_revoked_after_password_change(string $driver): void
    {
        config(['session.driver' => $driver]);
        $user = User::factory()->create();

        // Keep the login cookie without following the redirect.
        $unusedSession = $this->sessionId($this->login($user));
        $changedSession = $this->changePasswordFromAnotherClient($user);

        $this->newClient($unusedSession);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();

        $this->newClient($changedSession);
        $this->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_legacy_unbound_session_is_rejected_even_with_a_valid_remember_cookie(): void
    {
        $user = User::factory()->create();
        $response = $this->login($user, remember: true);
        $sessionId = $this->sessionId($response);
        $recallerName = auth()->guard()->getRecallerName();
        $recaller = $response->getCookie($recallerName)->getValue();

        // Model a session issued before the issuance-time binding was deployed.
        $session = app('session')->driver();
        $session->forget('password_hash_web');
        $session->save();

        $this->newClient($sessionId);
        $this->withCookie($recallerName, $recaller)
            ->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_remember_cookie_restores_a_session_but_not_after_password_change(): void
    {
        $user = User::factory()->create();
        $response = $this->login($user, remember: true);
        $recallerName = auth()->guard()->getRecallerName();
        $recaller = $response->getCookie($recallerName)->getValue();

        $this->newClient();
        $this->withCookie($recallerName, $recaller)
            ->get('/dashboard')->assertOk()->assertSessionHas('password_hash_web');
        $this->assertAuthenticatedAs($user);

        $this->changePasswordFromAnotherClient($user);

        $this->newClient();
        $this->withCookie($recallerName, $recaller)
            ->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_existing_raw_hash_session_remains_compatible_and_is_revoked(): void
    {
        $user = User::factory()->create();
        $sessionId = $this->sessionId($this->login($user));
        $session = app('session')->driver();
        $session->put('password_hash_web', $user->getAuthPassword());
        $session->save();

        $this->newClient($sessionId);
        $this->get('/dashboard')->assertOk();
        $this->changePasswordFromAnotherClient($user);

        $this->newClient($sessionId);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_rejected_password_change_preserves_other_sessions(): void
    {
        $user = User::factory()->create();
        $sessionId = $this->sessionId($this->login($user));
        $this->newClient($this->sessionId($this->login($user)));

        $this->from('/settings/password')->put('/settings/password', [
            'current_password' => 'wrong-password',
            'password' => 'NewStrongPassword123',
            'password_confirmation' => 'NewStrongPassword123',
        ])->assertSessionHasErrors('current_password');

        $this->newClient($sessionId);
        $this->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_initial_setup_binds_the_session_before_redirecting(): void
    {
        config(['lucky.setup_key' => 'test-setup-key']);
        $response = $this->post('/setup', [
            'setup_key' => 'test-setup-key',
            'username' => 'admin',
            'name' => 'Administrator',
            'password' => 'StrongPassword123',
            'password_confirmation' => 'StrongPassword123',
        ])->assertRedirect('/dashboard')->assertSessionHas('password_hash_web');

        $user = User::query()->sole();
        $sessionId = $this->sessionId($response);
        $this->changePasswordFromAnotherClient($user, 'StrongPassword123');

        $this->newClient($sessionId);
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_successful_passkey_login_binds_the_session_before_its_first_use(): void
    {
        $user = User::factory()->create();
        $passkey = new Passkey;
        $passkey->setRelation('user', $user);

        // Stub only cryptographic verification; exercise the real package login route and guard.
        $this->mock(VerifyPasskey::class, function (MockInterface $mock) use ($passkey): void {
            $mock->shouldReceive('__invoke')->once()->andReturn($passkey);
        });

        $options = $this->getJson('/passkeys/login/options')->assertOk();
        $this->newClient($this->sessionId($options));
        $credentialId = Base64UrlSafe::encodeUnpadded('test-credential');
        $response = $this->withCredentials()->postJson('/passkeys/login', [
            'credential' => [
                'id' => $credentialId,
                'rawId' => $credentialId,
                'type' => 'public-key',
                'response' => [
                    'clientDataJSON' => Base64UrlSafe::encodeUnpadded(json_encode([
                        'type' => 'webauthn.get',
                        'challenge' => $options->json('options.challenge'),
                        'origin' => config('app.url'),
                    ], JSON_THROW_ON_ERROR)),
                    'authenticatorData' => base64_encode(str_repeat("\0", 32)."\x05".pack('N', 1)),
                    'signature' => base64_encode('test-signature'),
                    'userHandle' => null,
                ],
            ],
        ])->assertOk()->assertSessionHas('password_hash_web');

        $sessionId = $this->sessionId($response);
        $this->changePasswordFromAnotherClient($user);

        $this->newClient($sessionId);
        $this->get('/dashboard')->assertRedirect('/login');
    }

    private function login(User $user, string $password = 'password', bool $remember = false): TestResponse
    {
        $this->newClient();

        return $this->post('/login', [
            'username' => $user->username,
            'password' => $password,
            'remember' => $remember,
        ])->assertRedirect('/dashboard');
    }

    private function changePasswordFromAnotherClient(User $user, string $password = 'password'): string
    {
        $this->newClient($this->sessionId($this->login($user, $password)));

        return $this->sessionId($this->from('/settings/password')->put('/settings/password', [
            'current_password' => $password,
            'password' => 'NewStrongPassword123',
            'password_confirmation' => 'NewStrongPassword123',
        ])->assertSessionHasNoErrors()->assertRedirect('/settings/password'));
    }

    private function sessionId(TestResponse $response): string
    {
        return $response->getCookie(config('session.cookie'))->getValue();
    }

    private function newClient(?string $sessionId = null): void
    {
        // A separate HTTP client must not reuse in-process guard or session state.
        app('auth')->forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        app('redirect')->setSession(app('session.store'));
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        if ($sessionId !== null) {
            $this->withCookie(config('session.cookie'), $sessionId);
        }
    }
}
