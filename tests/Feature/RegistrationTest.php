<?php

namespace Tests\Feature;

use App\Actions\CreateStarterCategories;
use App\Enums\Interest;
use App\Mail\MagicLinkMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_register_and_receives_a_magic_link(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada',
            'email' => 'Ada@Example.com',
            'interests' => ['science', 'coding'],
            'wants_daily_reminder' => true,
        ]);

        $response->assertAccepted()
            ->assertExactJson(['message' => 'Check your inbox for your magic link.']);

        $user = User::sole();
        $this->assertSame('Ada', $user->name);
        $this->assertSame('ada@example.com', $user->email);
        $this->assertEquals([Interest::Science, Interest::Coding], $user->settings->interests->all());
        $this->assertTrue($user->settings->wants_daily_reminder);
        $this->assertNull($user->email_verified_at);
        $this->assertSame(CreateStarterCategories::NAMES, $user->categories()->orderBy('id')->pluck('name')->all());
        $this->assertDatabaseCount('magic_link_tokens', 1);

        Mail::assertSent(MagicLinkMail::class, fn (MagicLinkMail $mail): bool => $mail->hasTo('ada@example.com')
            && str_starts_with($mail->magicLinkUrl, 'http://localhost:5173/auth/verify?token='));
    }

    public function test_interests_and_daily_reminder_are_optional(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/register', ['name' => 'Ada', 'email' => 'ada@example.com']);

        $response->assertAccepted();
        $user = User::sole();
        $this->assertCount(0, $user->settings->interests);
        $this->assertFalse($user->settings->wants_daily_reminder);
    }

    public function test_registering_an_existing_email_sends_a_sign_in_link_without_changing_the_account(): void
    {
        Mail::fake();
        $existingUser = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);

        $response = $this->postJson('/api/v1/auth/register', ['name' => 'Someone else', 'email' => 'ada@example.com']);

        $response->assertAccepted()
            ->assertExactJson(['message' => 'Check your inbox for your magic link.']);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Ada', $existingUser->fresh()->name);
        Mail::assertSent(MagicLinkMail::class, fn (MagicLinkMail $mail): bool => $mail->hasTo('ada@example.com'));
    }

    public function test_name_and_email_are_required(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/v1/auth/register', []);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'name' => 'Tell us what to call you.',
            'email' => 'Enter your email address.',
        ]);
        Mail::assertNothingSent();
    }

    public function test_email_must_be_a_valid_address(): void
    {
        $response = $this->postJson('/api/v1/auth/register', ['name' => 'Ada', 'email' => 'not-an-email']);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'email' => "That doesn't look like an email address. Check it and try again.",
        ]);
    }

    public function test_name_may_not_be_longer_than_50_characters(): void
    {
        $response = $this->postJson('/api/v1/auth/register', ['name' => str_repeat('a', 51), 'email' => 'ada@example.com']);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'name' => 'Keep your name under 50 characters.',
        ]);
    }

    public function test_interests_must_come_from_the_list(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'interests' => ['science', 'astrology'],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'interests.1' => 'Pick your interests from the list.',
        ]);
    }

    public function test_registration_is_rate_limited_per_email(): void
    {
        Mail::fake();
        $payload = ['name' => 'Ada', 'email' => 'ada@example.com'];

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->postJson('/api/v1/auth/register', $payload)->assertAccepted();
        }

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertTooManyRequests()
            ->assertExactJson(['message' => "You've asked for a few links in a row. Wait a minute and try again."]);
    }
}
