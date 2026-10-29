<?php

namespace Tests\Feature;

use App\Mail\MagicLinkMail;
use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VerifyMagicLinkTest extends TestCase
{
    use RefreshDatabase;

    private const PLAIN_TOKEN = 'a-known-plain-token-used-to-sign-in-during-tests-0123456789abcd';

    public function test_magic_link_returns_an_api_token_and_the_user(): void
    {
        $user = User::factory()->create();
        MagicLinkToken::factory()->for($user)->forPlainToken(self::PLAIN_TOKEN)->create();

        $response = $this->postJson('/api/v1/auth/verify', ['token' => self::PLAIN_TOKEN]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']])
            ->assertJsonPath('user.id', $user->id);
        $this->withToken($response->json('token'))->getJson('/api/v1/user')->assertOk()->assertJsonPath('id', $user->id);
    }

    public function test_magic_link_from_registration_signs_in_and_verifies_the_email(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/auth/register', ['name' => 'Ada', 'email' => 'ada@example.com'])->assertAccepted();
        $plainToken = $this->plainTokenFromSentMail();

        $response = $this->postJson('/api/v1/auth/verify', ['token' => $plainToken]);

        $response->assertOk()->assertJsonPath('user.email', 'ada@example.com');
        $this->assertNotNull(User::sole()->email_verified_at);
    }

    public function test_magic_link_works_only_once(): void
    {
        MagicLinkToken::factory()->forPlainToken(self::PLAIN_TOKEN)->create();
        $this->postJson('/api/v1/auth/verify', ['token' => self::PLAIN_TOKEN])->assertOk();

        $response = $this->postJson('/api/v1/auth/verify', ['token' => self::PLAIN_TOKEN]);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'token' => 'This link has expired or was already used. Request a new one.',
        ]);
    }

    public function test_magic_link_expires_after_15_minutes(): void
    {
        MagicLinkToken::factory()->forPlainToken(self::PLAIN_TOKEN)->create();
        $this->travel(16)->minutes();

        $response = $this->postJson('/api/v1/auth/verify', ['token' => self::PLAIN_TOKEN]);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'token' => 'This link has expired or was already used. Request a new one.',
        ]);
    }

    public function test_unknown_token_is_rejected(): void
    {
        $response = $this->postJson('/api/v1/auth/verify', ['token' => 'not-a-real-token']);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'token' => 'This link has expired or was already used. Request a new one.',
        ]);
    }

    public function test_token_is_required(): void
    {
        $response = $this->postJson('/api/v1/auth/verify', []);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'token' => 'This link is incomplete. Request a new one.',
        ]);
    }

    private function plainTokenFromSentMail(): string
    {
        $plainToken = null;

        Mail::assertSent(MagicLinkMail::class, function (MagicLinkMail $mail) use (&$plainToken): bool {
            parse_str((string) parse_url($mail->magicLinkUrl, PHP_URL_QUERY), $query);
            $plainToken = $query['token'];

            return true;
        });

        return $plainToken;
    }
}
