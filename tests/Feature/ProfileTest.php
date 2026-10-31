<?php

namespace Tests\Feature;

use App\Mail\ConfirmEmailChangeMail;
use App\Mail\EmailChangedMail;
use App\Models\EmailChange;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cant_change_a_profile(): void
    {
        $this->patchJson('/api/v1/user', ['name' => 'Ada'])->assertUnauthorized();
        $this->postJson('/api/v1/user/email', ['email' => 'ada@example.com'])->assertUnauthorized();
    }

    public function test_a_learner_changes_their_name(): void
    {
        $learner = $this->learner();

        $response = $this->patchJson('/api/v1/user', ['name' => 'Augusta']);

        $response->assertOk()->assertJsonPath('name', 'Augusta')->assertJsonPath('email', $learner->email);
        $this->assertSame('Augusta', $learner->fresh()->name);
    }

    public function test_a_name_is_required_and_short(): void
    {
        $this->learner();

        $this->patchJson('/api/v1/user', ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'Tell us what to call you.']);
        $this->patchJson('/api/v1/user', ['name' => str_repeat('a', 51)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'Keep your name under 50 characters.']);
    }

    public function test_changing_email_sends_a_link_to_the_new_address_and_waits_for_it(): void
    {
        Mail::fake();
        $learner = $this->learner('ada@example.com');

        $response = $this->postJson('/api/v1/user/email', ['email' => 'Ada@New.example']);

        $response->assertAccepted()->assertExactJson(['message' => 'Check your new inbox and open the link to confirm.']);
        Mail::assertSent(ConfirmEmailChangeMail::class, fn (ConfirmEmailChangeMail $mail): bool => $mail->hasTo('ada@new.example')
            && str_starts_with($mail->confirmUrl, config('app.frontend_url').'/settings/email/confirm?token='));
        $this->assertSame('ada@example.com', $learner->fresh()->email);
        $this->getJson('/api/v1/user')->assertJsonPath('pending_email', 'ada@new.example');
    }

    public function test_opening_the_link_changes_the_email_and_tells_the_old_address(): void
    {
        Mail::fake();
        $learner = $this->learner('ada@example.com');
        $this->postJson('/api/v1/user/email', ['email' => 'ada@new.example'])->assertAccepted();
        $token = $this->tokenFromConfirmMail();

        $response = $this->postJson('/api/v1/user/email/confirm', ['token' => $token]);

        $response->assertOk()->assertJsonPath('email', 'ada@new.example')->assertJsonPath('pending_email', null);
        $this->assertSame('ada@new.example', $learner->fresh()->email);
        Mail::assertSent(EmailChangedMail::class, fn (EmailChangedMail $mail): bool => $mail->hasTo('ada@example.com')
            && $mail->newEmail === 'ada@new.example');
        $this->postJson('/api/v1/user/email/confirm', ['token' => $token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token' => 'This link has expired or was already used. Change your email again from your settings.']);
    }

    public function test_only_the_latest_email_change_can_be_confirmed(): void
    {
        Mail::fake();
        $learner = $this->learner();
        $this->postJson('/api/v1/user/email', ['email' => 'typo@exmaple.com'])->assertAccepted();
        $firstToken = $this->tokenFromConfirmMail();

        $this->postJson('/api/v1/user/email', ['email' => 'right@example.com'])->assertAccepted();

        $this->postJson('/api/v1/user/email/confirm', ['token' => $firstToken])->assertUnprocessable();
        $this->assertSame(1, $learner->emailChanges()->count());
    }

    public function test_an_expired_or_unknown_link_changes_nothing(): void
    {
        $learner = $this->learner('ada@example.com');
        EmailChange::factory()->for($learner)->expired()->forPlainToken('expired-token')->create();

        $this->postJson('/api/v1/user/email/confirm', ['token' => 'expired-token'])->assertUnprocessable();
        $this->postJson('/api/v1/user/email/confirm', ['token' => 'unknown-token'])->assertUnprocessable();
        $this->postJson('/api/v1/user/email/confirm', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token' => 'This link is incomplete. Change your email again from your settings.']);
        $this->assertSame('ada@example.com', $learner->fresh()->email);
    }

    public function test_an_address_taken_in_the_meantime_cant_be_confirmed(): void
    {
        $learner = $this->learner('ada@example.com');
        EmailChange::factory()->for($learner)->forPlainToken('valid-token')->create(['new_email' => 'taken@example.com']);
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/user/email/confirm', ['token' => 'valid-token'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token' => 'That email got an account in the meantime. Change your email to another address.']);
        $this->assertSame('ada@example.com', $learner->fresh()->email);
    }

    public function test_the_new_email_must_be_valid_new_and_free(): void
    {
        Mail::fake();
        $this->learner('ada@example.com');
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/user/email', ['email' => 'not an email'])
            ->assertJsonValidationErrors(['email' => "That doesn't look like an email address. Check it and try again."]);
        $this->postJson('/api/v1/user/email', ['email' => 'ADA@example.com'])
            ->assertJsonValidationErrors(['email' => "That's already your email address."]);
        $this->postJson('/api/v1/user/email', ['email' => 'taken@example.com'])
            ->assertJsonValidationErrors(['email' => 'That email already has an account. Use another one.']);
        Mail::assertNothingSent();
    }

    public function test_asking_for_many_email_changes_is_rate_limited(): void
    {
        Mail::fake();
        $this->learner();

        foreach (range(1, 3) as $attempt) {
            $this->postJson('/api/v1/user/email', ['email' => 'new@example.com'])->assertAccepted();
        }

        $this->postJson('/api/v1/user/email', ['email' => 'new@example.com'])->assertTooManyRequests();
    }

    private function learner(string $email = 'ada@example.com'): User
    {
        $learner = User::factory()->create(['email' => $email]);
        UserSetting::factory()->for($learner)->create();
        Sanctum::actingAs($learner);

        return $learner;
    }

    private function tokenFromConfirmMail(): string
    {
        $confirmUrl = '';
        Mail::assertSent(ConfirmEmailChangeMail::class, function (ConfirmEmailChangeMail $mail) use (&$confirmUrl): bool {
            $confirmUrl = $mail->confirmUrl;

            return true;
        });
        parse_str((string) parse_url($confirmUrl, PHP_URL_QUERY), $query);

        return $query['token'];
    }
}
