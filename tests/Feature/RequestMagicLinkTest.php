<?php

namespace Tests\Feature;

use App\Mail\MagicLinkMail;
use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RequestMagicLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_user_receives_a_magic_link_that_expires_in_15_minutes(): void
    {
        Mail::fake();
        $this->freezeSecond();
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'ADA@example.com']);

        $response->assertAccepted()
            ->assertExactJson(['message' => 'Check your inbox for your magic link.']);
        $magicLinkToken = MagicLinkToken::sole();
        $this->assertTrue($magicLinkToken->user->is($user));
        $this->assertTrue($magicLinkToken->expires_at->equalTo(now()->addMinutes(15)));
        Mail::assertSent(MagicLinkMail::class, fn (MagicLinkMail $mail): bool => $mail->hasTo('ada@example.com'));
    }

    public function test_unknown_email_gets_the_same_response_but_no_mail(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com']);

        $response->assertAccepted()
            ->assertExactJson(['message' => 'Check your inbox for your magic link.']);
        $this->assertDatabaseCount('magic_link_tokens', 0);
        Mail::assertNothingSent();
    }

    public function test_email_is_required(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertUnprocessable()->assertJsonValidationErrors(['email' => 'Enter your email address.']);
    }

    public function test_email_must_be_a_valid_address(): void
    {
        $response = $this->postJson('/api/v1/auth/login', ['email' => 'not-an-email']);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'email' => "That doesn't look like an email address. Check it and try again.",
        ]);
    }

    public function test_requesting_links_is_rate_limited_per_email(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'ada@example.com']);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'ada@example.com'])->assertAccepted();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'ADA@example.com'])->assertTooManyRequests();
    }
}
