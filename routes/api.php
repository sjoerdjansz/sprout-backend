<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\RequestMagicLinkController;
use App\Http\Controllers\Auth\VerifyMagicLinkController;
use App\Http\Controllers\Conversations\DeleteConversationController;
use App\Http\Controllers\Conversations\ListConversationsController;
use App\Http\Controllers\Conversations\SaveConversationController;
use App\Http\Controllers\Conversations\SendMessageController;
use App\Http\Controllers\Conversations\ShowConversationController;
use App\Http\Controllers\Conversations\StartConversationController;
use App\Http\Controllers\CurrentUserController;
use App\Http\Controllers\Flashcards\DeleteFlashcardController;
use App\Http\Controllers\Flashcards\ListFlashcardsController;
use App\Http\Controllers\Flashcards\PauseFlashcardController;
use App\Http\Controllers\Flashcards\ResumeFlashcardController;
use App\Http\Controllers\Flashcards\ReviewFlashcardController;
use App\Http\Controllers\Flashcards\StoreFlashcardController;
use App\Http\Controllers\Flashcards\UpdateFlashcardController;
use App\Http\Controllers\KnowledgeMap\DeleteSubtopicController;
use App\Http\Controllers\KnowledgeMap\ExplainSubtopicController;
use App\Http\Controllers\KnowledgeMap\GenerateMoreMaterialController;
use App\Http\Controllers\KnowledgeMap\ListCategoriesController;
use App\Http\Controllers\KnowledgeMap\ListTopicsController;
use App\Http\Controllers\KnowledgeMap\ShowSubtopicController;
use App\Http\Controllers\KnowledgeMap\StoreSubtopicController;
use App\Http\Controllers\KnowledgeMap\StoreTopicController;
use App\Http\Controllers\KnowledgeMap\UpdateSubtopicController;
use App\Http\Controllers\KnowledgeMap\UpdateTopicController;
use App\Http\Controllers\Questions\AnswerQuestionController;
use App\Http\Controllers\Questions\DeleteQuestionController;
use App\Http\Controllers\Questions\ListQuestionsController;
use App\Http\Controllers\Settings\ConfirmEmailChangeController;
use App\Http\Controllers\Settings\DeleteAccountController;
use App\Http\Controllers\Settings\ExportFlashcardsController;
use App\Http\Controllers\Settings\ExportLearnerDataController;
use App\Http\Controllers\Settings\RequestEmailChangeController;
use App\Http\Controllers\Settings\ResetProgressController;
use App\Http\Controllers\Settings\ShowAiUsageController;
use App\Http\Controllers\Settings\ShowSettingsController;
use App\Http\Controllers\Settings\UpdateProfileController;
use App\Http\Controllers\Settings\UpdateSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/status', function () {
    return ['status' => 'ok'];
})->name('status');

Route::prefix('auth')->name('auth.')->group(function () {
    Route::middleware('throttle:magic-link')->group(function () {
        Route::post('/register', RegisterController::class)->name('register');
        Route::post('/login', RequestMagicLinkController::class)->name('login');
    });

    Route::post('/verify', VerifyMagicLinkController::class)
        ->middleware('throttle:verify-magic-link')
        ->name('verify');

    Route::post('/logout', LogoutController::class)
        ->middleware('auth:sanctum')
        ->name('logout');
});

Route::get('/user', CurrentUserController::class)
    ->middleware('auth:sanctum')
    ->name('user');

// The settings page (specs/settings.md).
Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('user')->name('user.')->group(function () {
        Route::patch('/', UpdateProfileController::class)->name('update');
        Route::delete('/', DeleteAccountController::class)->name('destroy');
        Route::post('/email', RequestEmailChangeController::class)->middleware('throttle:magic-link')->name('email.store');
        Route::get('/export', ExportLearnerDataController::class)->middleware('throttle:account-data')->name('export');
        Route::get('/export/flashcards', ExportFlashcardsController::class)->middleware('throttle:account-data')->name('export.flashcards');
        Route::post('/progress/reset', ResetProgressController::class)->middleware('throttle:account-data')->name('progress.reset');
    });

    Route::get('/settings', ShowSettingsController::class)->name('settings.show');
    Route::patch('/settings', UpdateSettingsController::class)->name('settings.update');
    Route::get('/settings/usage', ShowAiUsageController::class)->name('settings.usage');
});

// Public: the token proves the learner owns the new address, also on a device where they aren't signed in.
Route::post('/user/email/confirm', ConfirmEmailChangeController::class)
    ->middleware('throttle:verify-magic-link')
    ->name('user.email.confirm');

Route::middleware('auth:sanctum')->prefix('conversations')->name('conversations.')->group(function () {
    Route::get('/', ListConversationsController::class)->name('index');
    Route::post('/', StartConversationController::class)->middleware('throttle:tutor-messages')->name('store');
    Route::get('/{conversation}', ShowConversationController::class)->whereNumber('conversation')->name('show');
    Route::delete('/{conversation}', DeleteConversationController::class)->whereNumber('conversation')->name('destroy');
    Route::post('/{conversation}/messages', SendMessageController::class)
        ->whereNumber('conversation')
        ->middleware('throttle:tutor-messages')
        ->name('messages.store');
    Route::post('/{conversation}/save', SaveConversationController::class)
        ->whereNumber('conversation')
        ->middleware('throttle:save-conversations')
        ->name('save');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/categories', ListCategoriesController::class)->name('categories.index');

    Route::prefix('topics')->name('topics.')->group(function () {
        Route::get('/', ListTopicsController::class)->name('index');
        Route::post('/', StoreTopicController::class)->name('store');
        Route::put('/{topic}', UpdateTopicController::class)->whereNumber('topic')->name('update');
    });

    Route::prefix('subtopics')->name('subtopics.')->group(function () {
        // Subtopics with notes get flashcards and questions generated, so adding and editing share a rate limit.
        Route::post('/', StoreSubtopicController::class)->middleware('throttle:generate-material')->name('store');
        Route::get('/{subtopic}', ShowSubtopicController::class)->whereNumber('subtopic')->name('show');
        Route::put('/{subtopic}', UpdateSubtopicController::class)
            ->whereNumber('subtopic')
            ->middleware('throttle:generate-material')
            ->name('update');
        Route::delete('/{subtopic}', DeleteSubtopicController::class)->whereNumber('subtopic')->name('destroy');
        Route::post('/{subtopic}/generate', GenerateMoreMaterialController::class)
            ->whereNumber('subtopic')
            ->middleware('throttle:generate-more-material')
            ->name('generate');
        Route::get('/{subtopic}/flashcards', ListFlashcardsController::class)->whereNumber('subtopic')->name('flashcards.index');
        Route::post('/{subtopic}/flashcards', StoreFlashcardController::class)->whereNumber('subtopic')->name('flashcards.store');
        Route::get('/{subtopic}/questions', ListQuestionsController::class)->whereNumber('subtopic')->name('questions.index');
        // Explanations are checked by the AI, like open answers, so they share that rate limit.
        Route::post('/{subtopic}/explanations', ExplainSubtopicController::class)
            ->whereNumber('subtopic')
            ->middleware('throttle:answer-questions')
            ->name('explanations.store');
    });

    Route::prefix('flashcards')->name('flashcards.')->group(function () {
        Route::put('/{flashcard}', UpdateFlashcardController::class)->whereNumber('flashcard')->name('update');
        Route::delete('/{flashcard}', DeleteFlashcardController::class)->whereNumber('flashcard')->name('destroy');
        Route::post('/{flashcard}/pause', PauseFlashcardController::class)->whereNumber('flashcard')->name('pause');
        Route::post('/{flashcard}/resume', ResumeFlashcardController::class)->whereNumber('flashcard')->name('resume');
        Route::post('/{flashcard}/reviews', ReviewFlashcardController::class)->whereNumber('flashcard')->name('reviews.store');
    });

    Route::delete('/questions/{question}', DeleteQuestionController::class)->whereNumber('question')->name('questions.destroy');
    // Open answers are checked by the AI, so answering has a rate limit of its own.
    Route::post('/questions/{question}/answers', AnswerQuestionController::class)
        ->whereNumber('question')
        ->middleware('throttle:answer-questions')
        ->name('questions.answers.store');
});
