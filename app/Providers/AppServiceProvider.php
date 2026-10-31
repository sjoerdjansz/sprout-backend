<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Responses are the resource itself, not wrapped in a "data" key (see API.md).
        JsonResource::withoutWrapping();

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        $tooManyAttempts = fn (Request $request, array $headers): JsonResponse => response()->json(
            ['message' => "You've asked for a few links in a row. Wait a minute and try again."],
            429,
            $headers,
        );

        // Per email so nobody's inbox can be flooded, per IP so one client can't spray many inboxes.
        RateLimiter::for('magic-link', function (Request $request) use ($tooManyAttempts): array {
            $email = Str::lower((string) $request->input('email'));

            return [
                Limit::perMinute(3)->by('email:'.$email)->response($tooManyAttempts),
                Limit::perMinute(10)->by('ip:'.$request->ip())->response($tooManyAttempts),
            ];
        });

        RateLimiter::for('verify-magic-link', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($request->ip())
            ->response(fn (Request $request, array $headers): JsonResponse => response()->json(
                ['message' => 'Too many sign in attempts. Wait a minute and try again.'],
                429,
                $headers,
            )));

        // Per learner, to keep AI costs predictable (specs/tutor-chat.md, Limits).
        RateLimiter::for('tutor-messages', fn (Request $request): Limit => Limit::perMinute(10)
            ->by((string) $request->user()?->id)
            ->response(fn (Request $request, array $headers): JsonResponse => response()->json(
                ['message' => "You're sending messages quickly. Wait a moment and try again."],
                429,
                $headers,
            )));

        RateLimiter::for('save-conversations', fn (Request $request): Limit => Limit::perMinute(5)
            ->by((string) $request->user()?->id)
            ->response(fn (Request $request, array $headers): JsonResponse => response()->json(
                ['message' => "You've saved a few times in a row. Wait a minute and try again."],
                429,
                $headers,
            )));

        RateLimiter::for('generate-material', fn (Request $request): Limit => Limit::perMinute(5)
            ->by((string) $request->user()?->id)
            ->response(fn (Request $request, array $headers): JsonResponse => response()->json(
                ['message' => "You've saved a few subtopics in a row. Wait a minute and try again."],
                429,
                $headers,
            )));

        RateLimiter::for('suggest-subtopics', fn (Request $request): Limit => Limit::perMinute(5)
            ->by((string) $request->user()?->id)
            ->response(fn (Request $request, array $headers): JsonResponse => response()->json(
                ['message' => "You've asked for suggestions a few times in a row. Wait a minute and try again."],
                429,
                $headers,
            )));

        RateLimiter::for('generate-more-material', fn (Request $request): Limit => Limit::perMinute(5)
            ->by((string) $request->user()?->id)
            ->response(fn (Request $request, array $headers): JsonResponse => response()->json(
                ['message' => "You've asked for new cards a few times in a row. Wait a minute and try again."],
                429,
                $headers,
            )));

        // Generous for clicking through multiple choice; it stops a loop from running up AI calls on open answers.
        RateLimiter::for('answer-questions', fn (Request $request): Limit => Limit::perMinute(30)
            ->by((string) $request->user()?->id)
            ->response(fn (Request $request, array $headers): JsonResponse => response()->json(
                ['message' => "You're answering very quickly. Wait a moment and try again."],
                429,
                $headers,
            )));

        // Exports and resets read or write all of a learner's data, so a few per minute is plenty.
        RateLimiter::for('account-data', fn (Request $request): Limit => Limit::perMinute(5)
            ->by((string) $request->user()?->id)
            ->response(fn (Request $request, array $headers): JsonResponse => response()->json(
                ['message' => "You've done this a few times in a row. Wait a minute and try again."],
                429,
                $headers,
            )));
    }
}
