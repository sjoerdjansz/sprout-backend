<?php

use App\Exceptions\AiRequestFailed;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (): bool => true);

        // Expected when a key is missing or the provider is down; RequestAiCompletion logs the cause.
        $exceptions->dontReport(AiRequestFailed::class);

        // One body for every 404 (unknown route, missing record, someone else's record), so the frontend
        // can show its not found page and the API never reveals model names or which records exist.
        $exceptions->render(fn (NotFoundHttpException $exception): JsonResponse => response()->json(
            ['message' => "We couldn't find that. Check the link, or head back to your dashboard."],
            404,
        ));
    })->create();
