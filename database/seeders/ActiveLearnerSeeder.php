<?php

namespace Database\Seeders;

use App\Enums\AiProvider;
use App\Enums\ContentOrigin;
use App\Enums\Interest;
use App\Enums\MaterialStatus;
use App\Enums\MessageRole;
use App\Enums\QuestionType;
use App\Enums\ReviewRating;
use App\Enums\SessionKind;
use App\Enums\Technique;
use App\Models\Flashcard;
use App\Models\Message;
use App\Models\Question;
use App\Models\StudySession;
use App\Models\Subtopic;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Ada has been learning for three weeks: two topics, cards in every scheduling
 * state (new, due today, overdue, scheduled), answered questions and a 6-day
 * streak. All dates are relative to now, so the data stays "live" after reseeding.
 */
class ActiveLearnerSeeder extends Seeder
{
    private const TUTOR_MODEL = 'gpt-5.6-terra';

    public function run(): void
    {
        $ada = User::factory()->create([
            'name' => 'Ada',
            'email' => 'ada@sprout.test',
            'created_at' => now()->subDays(21),
        ]);

        $ada->settings()->create([
            'interests' => [Interest::Science, Interest::Languages],
            'wants_daily_reminder' => true,
            'timezone' => 'Europe/Amsterdam',
            'daily_goal_cards' => 20,
            'reminder_time' => '19:00',
        ]);

        $this->connectDevelopmentApiKey($ada);
        $this->seedPhotosynthesis($ada);
        $this->seedSpanish($ada);
        $this->seedMixedReviewYesterday($ada);
        $this->seedOpenExploration($ada);
        $this->seedDailyActivity($ada);
    }

    /**
     * An exploration Ada hasn't saved yet: no topic, a half-filled draft, and still open to continue.
     */
    private function seedOpenExploration(User $user): void
    {
        $startedAt = now()->subHours(2);

        $session = $user->studySessions()->create([
            'topic_id' => null,
            'kind' => SessionKind::Learn,
            'draft' => [
                'title' => 'Biomechanics of running',
                'goal' => [
                    'description' => 'Explain the phases of the running gait to the runners I coach.',
                    'deadline' => null,
                    'recall_percentage' => null,
                ],
                'subtopics' => [
                    ['title' => 'Gait cycle', 'content' => 'A running stride has a stance phase (foot on the ground) and a swing phase (foot in the air). Unlike walking, running has a flight phase where both feet are off the ground.'],
                ],
            ],
            'started_at' => $startedAt,
            'ended_at' => null,
        ]);

        $this->createConversation($session, [
            [MessageRole::User, null, 'I want to learn more about biomechanics.'],
            [MessageRole::Tutor, null, "Great choice! Biomechanics is a big field, so let's narrow it down. What draws you to it: **sport**, **injuries**, or how the body moves in everyday life?"],
            [MessageRole::User, null, 'Running. I coach a small group of runners.'],
            [MessageRole::Tutor, null, 'Nice, that gives us a clear focus. Before I explain anything: when you watch one of your runners, which phases of a stride can you already name?'],
            [MessageRole::User, null, 'When the foot is on the ground and when it swings forward?'],
            [MessageRole::Tutor, null, "Exactly right: the **stance** and **swing** phases. The one missing piece is the **flight phase**, when both feet are off the ground. That's what makes running different from walking. What do you think happens to the forces on the leg when the foot lands after a flight phase?"],
        ]);
    }

    /**
     * Gives Ada the developer's OpenAI key from .env, so the tutor works with this account locally.
     */
    private function connectDevelopmentApiKey(User $user): void
    {
        $apiKey = config('services.openai.key');

        if (blank($apiKey)) {
            $this->command?->warn('OPENAI_API_KEY is empty: ada@sprout.test gets no AI key.');

            return;
        }

        $user->aiCredentials()->create([
            'provider' => AiProvider::OpenAi,
            'encrypted_api_key' => $apiKey,
            'key_last_four' => substr($apiKey, -4),
            'validated_at' => null,
        ]);
    }

    private function seedPhotosynthesis(User $user): void
    {
        $topic = $user->topics()->create([
            'title' => 'Photosynthesis',
            'description' => 'How plants turn light, water and carbon dioxide into sugar and oxygen.',
            'goal' => 'Explain both stages of photosynthesis, and what limits their rate, without notes.',
            'goal_deadline' => now()->addWeeks(3)->toDateString(),
            'goal_recall_percentage' => 85,
            'created_at' => now()->subDays(21),
        ]);

        [$pigments, $lightReactions, $calvinCycle, $limitingFactors] = $this->createSubtopics($topic, [
            'Light and pigments',
            'Light-dependent reactions',
            'The Calvin cycle',
            'Limiting factors',
        ]);

        $notes = "Photosynthesis happens in the chloroplasts.\n"
            ."Chlorophyll absorbs red and blue light and reflects green.\n"
            ."Light-dependent reactions (thylakoids): water is split, oxygen is released, ATP and NADPH are made.\n"
            ."Calvin cycle (stroma): CO2 is fixed by RuBisCO and turned into glucose using ATP and NADPH.\n"
            .'Limiting factors: light intensity, CO2 concentration and temperature.';
        Storage::disk('local')->put('materials/seed/photosynthesis-notes.txt', $notes);

        $topic->materials()->create([
            'original_filename' => 'photosynthesis-notes.txt',
            'storage_path' => 'materials/seed/photosynthesis-notes.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($notes),
            'extracted_text' => $notes,
            'status' => MaterialStatus::Ready,
            'created_at' => now()->subDays(21),
        ]);

        $session = $this->createSession($user, $topic, SessionKind::Learn, now()->subDays(3)->setTime(19, 5));
        $messages = $this->createConversation($session, [
            [MessageRole::Tutor, Technique::RetrievalPractice, 'Before I explain: what do you think chlorophyll does in a leaf?'],
            [MessageRole::User, null, 'It makes the leaf green? And it catches sunlight.'],
            [MessageRole::Tutor, Technique::RetrievalPractice, 'Both right. One missing piece: it absorbs red and blue light and reflects green, which is why leaves look green. What happens to the light energy it catches?'],
            [MessageRole::User, null, 'It gets turned into sugar?'],
            [MessageRole::Tutor, Technique::Feynman, "Close: first it's stored as ATP and NADPH, and the Calvin cycle uses those to build sugar. Can you explain the Calvin cycle back to me in your own words?"],
            [MessageRole::User, null, 'The plant takes CO2 from the air and uses the energy from the light reactions to make glucose.'],
            [MessageRole::Tutor, Technique::SpacedRepetition, "That's the core of it. I made three flashcards from this session; they'll come back right before you'd forget them."],
        ]);
        $cardSourceMessage = $messages[6];

        $this->createFlashcard($user, $topic, $pigments, 'What does chlorophyll absorb?', 'Mostly red and blue light. It reflects green light, which is why leaves look green.', $cardSourceMessage,
            [[18, ReviewRating::Good], [17, ReviewRating::Good], [14, ReviewRating::Good], [8, ReviewRating::Easy]]);
        $this->createFlashcard($user, $topic, $pigments, 'Where in the cell does photosynthesis happen?', 'In the chloroplasts.', $cardSourceMessage,
            [[10, ReviewRating::Good], [9, ReviewRating::Easy], [6, ReviewRating::Good]]);
        $this->createFlashcard($user, $topic, $lightReactions, 'Which gas is released in the light-dependent reactions?', 'Oxygen, from splitting water.', $cardSourceMessage,
            [[12, ReviewRating::Good], [11, ReviewRating::Good], [8, ReviewRating::Good]]);
        $this->createFlashcard($user, $topic, $lightReactions, 'What do the light-dependent reactions produce for the Calvin cycle?', 'ATP and NADPH.', $cardSourceMessage,
            [[7, ReviewRating::Again], [6, ReviewRating::Hard], [3, ReviewRating::Good]]);
        $this->createFlashcard($user, $topic, $calvinCycle, 'Which enzyme fixes CO2 in the Calvin cycle?', 'RuBisCO.', $cardSourceMessage,
            [[1, ReviewRating::Hard]]);
        $this->createFlashcard($user, $topic, $calvinCycle, 'Where does the Calvin cycle take place?', 'In the stroma of the chloroplast.', null,
            [], origin: ContentOrigin::User);
        $this->createFlashcard($user, $topic, $limitingFactors, 'Name the three main limiting factors of photosynthesis.', 'Light intensity, CO2 concentration and temperature.', null,
            []);

        $multipleChoice = $this->createQuestion($topic, $lightReactions, QuestionType::MultipleChoice,
            'Which gas do plants release during the light-dependent reactions?', 'Oxygen', $messages[4], [
                ['Oxygen', true, 'Water is split and oxygen is released as a by-product.'],
                ['Carbon dioxide', false, 'Carbon dioxide is taken in, and used in the Calvin cycle.'],
                ['Nitrogen', false, 'Nitrogen plays no part in photosynthesis.'],
                ['Hydrogen', false, 'Hydrogen from water ends up in NADPH, not in the air.'],
            ]);
        $this->createAnswer($user, $multipleChoice, $session, now()->subDays(3)->setTime(19, 20), option: 'Oxygen', score: 100, isCorrect: true,
            feedback: 'Correct. Oxygen is the by-product of splitting water.');

        $open = $this->createQuestion($topic, $pigments, QuestionType::Open,
            'Why do most leaves look green?', 'Chlorophyll absorbs red and blue light and reflects green light.', $messages[2]);
        $this->createAnswer($user, $open, $session, now()->subDays(3)->setTime(19, 12), response: 'Because chlorophyll is green.', score: 50, isCorrect: false,
            feedback: "Not quite. You're right that it's chlorophyll; the missing piece is that it reflects green light while absorbing red and blue. Next, try explaining it with the word \"reflect\".");
        $this->createAnswer($user, $open, null, now()->subDays(1)->setTime(8, 40), response: 'Chlorophyll absorbs red and blue light and reflects the green light, so we see green.', score: 95, isCorrect: true,
            feedback: 'Spot on: absorb red and blue, reflect green.');

        $feynman = $this->createQuestion($topic, $calvinCycle, QuestionType::Feynman,
            'Explain the Calvin cycle as if to a 12-year-old.', 'The plant grabs CO2 from the air and, using energy stored by the light reactions (ATP and NADPH), builds it into sugar in the stroma. RuBisCO is the enzyme that grabs the CO2.', $messages[4]);
        $this->createAnswer($user, $feynman, $session, now()->subDays(3)->setTime(19, 30),
            response: 'The plant takes CO2 from the air and uses the energy from the light reactions to make glucose.', score: 70, isCorrect: null,
            feedback: "Good start: you've got the inputs and the output. Two gaps: where it happens, and what does the grabbing.",
            gaps: ['Where it happens: the stroma of the chloroplast', 'RuBisCO is the enzyme that fixes CO2']);

        $this->createProgress($user, [$pigments, 85], [$lightReactions, 68], [$calvinCycle, 45], [$limitingFactors, 0]);
    }

    private function seedSpanish(User $user): void
    {
        $topic = $user->topics()->create([
            'title' => 'Spanish basics',
            'description' => 'Getting by on a first trip to Spain: greetings, numbers and the first verbs.',
            'goal' => 'Greet people, order food and count to one hundred in Spanish on my trip.',
            'goal_deadline' => now()->addMonths(2)->toDateString(),
            'goal_recall_percentage' => 80,
            'created_at' => now()->subDays(14),
        ]);

        [$greetings, $numbers, $serEstar, $arVerbs] = $this->createSubtopics($topic, [
            'Greetings',
            'Numbers 1 to 20',
            'Ser and estar',
            'Present tense -ar verbs',
        ]);

        $session = $this->createSession($user, $topic, SessionKind::Learn, now()->subDays(2)->setTime(20, 15));
        $messages = $this->createConversation($session, [
            [MessageRole::Tutor, Technique::RetrievalPractice, 'Quick check before we start: how would you greet someone in the evening?'],
            [MessageRole::User, null, 'Buenas noches?'],
            [MessageRole::Tutor, Technique::RetrievalPractice, "Nearly: \"buenas noches\" is for saying goodbye or late at night. When you arrive in the evening, say \"buenas tardes\". Let's look at ser and estar next: which one would you use for where Madrid is?"],
            [MessageRole::User, null, 'Madrid es en España.'],
            [MessageRole::Tutor, Technique::TestingEffect, "Not quite: location uses estar, so \"Madrid está en España\". I've added a quiz question on this so it sticks."],
        ]);

        $this->createFlashcard($user, $topic, $greetings, 'Good afternoon / good evening (when arriving)', 'Buenas tardes', $messages[2],
            [[13, ReviewRating::Good], [12, ReviewRating::Good], [9, ReviewRating::Good], [3, ReviewRating::Good]]);
        $this->createFlashcard($user, $topic, $greetings, 'Nice to meet you', 'Mucho gusto', null,
            [[5, ReviewRating::Easy], [4, ReviewRating::Easy]], origin: ContentOrigin::User);
        $this->createFlashcard($user, $topic, $numbers, '15', 'quince', null,
            [[6, ReviewRating::Again], [5, ReviewRating::Good], [2, ReviewRating::Hard]]);
        $this->createFlashcard($user, $topic, $numbers, '12', 'doce', null,
            [[5, ReviewRating::Good], [4, ReviewRating::Good], [1, ReviewRating::Good]]);
        $this->createFlashcard($user, $topic, $serEstar, 'Madrid ___ en España (ser or estar?)', 'está: location uses estar', $messages[4],
            [[2, ReviewRating::Again]]);
        $this->createFlashcard($user, $topic, $arVerbs, 'hablar, yo form', 'hablo', null,
            []);

        $multipleChoice = $this->createQuestion($topic, $serEstar, QuestionType::MultipleChoice,
            'Madrid ___ en España.', 'está', $messages[4], [
                ['está', true, 'Location always uses estar.'],
                ['es', false, 'Ser is for identity and characteristics, not location.'],
                ['son', false, 'Son is the plural of ser.'],
            ]);
        $this->createAnswer($user, $multipleChoice, $session, now()->subDays(2)->setTime(20, 30), option: 'es', score: 0, isCorrect: false,
            feedback: 'Not quite: location uses estar. "Madrid está en España."');
        $this->createAnswer($user, $multipleChoice, null, now()->subDay()->setTime(8, 50), option: 'está', score: 100, isCorrect: true,
            feedback: 'Correct. Estar for location.');

        $open = $this->createQuestion($topic, $greetings, QuestionType::Open,
            'How do you say "good evening" when you walk into a shop at 7pm?', 'Buenas tardes', $messages[2]);
        $this->createAnswer($user, $open, $session, now()->subDays(2)->setTime(20, 20), response: 'Buenas noches', score: 30, isCorrect: false,
            feedback: 'Not quite: "buenas noches" is for leaving or late at night. Arriving in the evening, say "buenas tardes".');

        $this->createProgress($user, [$greetings, 78], [$numbers, 55], [$serEstar, 20], [$arVerbs, 0]);
    }

    /**
     * An interleaved review session mixes due cards from both topics and has no topic of its own.
     */
    private function seedMixedReviewYesterday(User $user): void
    {
        $session = $this->createSession($user, null, SessionKind::Review, now()->subDay()->setTime(8, 30));

        $this->createConversation($session, [
            [MessageRole::Tutor, Technique::Interleaving, "Morning! Today's review mixes Photosynthesis and Spanish basics, which helps you tell similar ideas apart. 9 cards are due."],
            [MessageRole::Tutor, Technique::SpacedRepetition, "That's everything due today. See you tomorrow!"],
        ]);
    }

    /**
     * A 6-day streak up to and including today, plus a few earlier days.
     */
    private function seedDailyActivity(User $user): void
    {
        $days = [
            20 => [30, 8, 1], 18 => [60, 14, 2], 14 => [50, 12, 1], 12 => [40, 10, 1],
            5 => [45, 11, 1], 4 => [35, 9, 0], 3 => [80, 15, 3], 2 => [70, 12, 3], 1 => [60, 9, 1], 0 => [20, 4, 0],
        ];

        foreach ($days as $daysAgo => [$xpEarned, $cardsReviewed, $questionsAnswered]) {
            $user->dailyActivities()->create([
                'activity_date' => today()->subDays($daysAgo),
                'xp_earned' => $xpEarned,
                'cards_reviewed' => $cardsReviewed,
                'questions_answered' => $questionsAnswered,
            ]);
        }
    }

    /**
     * @param  array<int, string>  $titles
     * @return array<int, Subtopic>
     */
    private function createSubtopics(Topic $topic, array $titles): array
    {
        $subtopics = [];

        foreach ($titles as $index => $title) {
            $subtopics[] = $topic->subtopics()->create(['title' => $title, 'position' => $index + 1]);
        }

        return $subtopics;
    }

    private function createSession(User $user, ?Topic $topic, SessionKind $kind, Carbon $startedAt): StudySession
    {
        return $user->studySessions()->create([
            'topic_id' => $topic?->id,
            'kind' => $kind,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addMinutes(25),
        ]);
    }

    /**
     * Creates the turns one minute apart. Tutor turns get a model and plausible token counts.
     *
     * @param  array<int, array{0: MessageRole, 1: ?Technique, 2: string}>  $turns
     * @return array<int, Message>
     */
    private function createConversation(StudySession $session, array $turns): array
    {
        $messages = [];

        foreach ($turns as $index => [$role, $technique, $content]) {
            $isTutor = $role === MessageRole::Tutor;
            $sentAt = $session->started_at->copy()->addMinutes($index);

            $messages[] = $session->messages()->create([
                'role' => $role,
                'content' => $content,
                'technique' => $technique,
                'model' => $isTutor ? self::TUTOR_MODEL : null,
                'input_tokens' => $isTutor ? 900 + $index * 150 : null,
                'output_tokens' => $isTutor ? 60 + intdiv(strlen($content), 4) : null,
                'created_at' => $sentAt,
                'updated_at' => $sentAt,
            ]);
        }

        return $messages;
    }

    /**
     * Replays the reviews through a simplified SM-2, so the card's scheduling state matches its history.
     *
     * @param  array<int, array{0: int, 1: ReviewRating}>  $reviews  [days ago, rating], oldest first
     */
    private function createFlashcard(
        User $user,
        Topic $topic,
        Subtopic $subtopic,
        string $front,
        string $back,
        ?Message $sourceMessage,
        array $reviews,
        ContentOrigin $origin = ContentOrigin::Ai,
    ): Flashcard {
        $flashcard = $user->flashcards()->create([
            'topic_id' => $topic->id,
            'subtopic_id' => $subtopic->id,
            'front' => $front,
            'back' => $back,
            'origin' => $origin,
            'source_message_id' => $sourceMessage?->id,
            'due_at' => today(),
            'created_at' => $reviews === [] ? now()->subDay() : now()->subDays($reviews[0][0]),
        ]);

        $intervalDays = 0;
        $easeFactor = 2.5;
        $repetitions = 0;
        $lapses = 0;
        $lastReviewedAt = null;

        foreach ($reviews as [$daysAgo, $rating]) {
            $intervalBefore = $intervalDays;

            if ($rating === ReviewRating::Again) {
                $repetitions = 0;
                $lapses++;
                $intervalDays = 1;
                $easeFactor = max(1.3, $easeFactor - 0.2);
            } else {
                $repetitions++;
                $intervalDays = match ($repetitions) {
                    1 => 1,
                    2 => 3,
                    default => (int) round($intervalDays * $easeFactor),
                };
                $easeFactor = match ($rating) {
                    ReviewRating::Hard => max(1.3, $easeFactor - 0.15),
                    ReviewRating::Easy => $easeFactor + 0.15,
                    default => $easeFactor,
                };
            }

            $lastReviewedAt = now()->subDays($daysAgo)->setTime(8, 45);

            $flashcard->reviews()->create([
                'rating' => $rating,
                'interval_days_before' => $intervalBefore,
                'interval_days_after' => $intervalDays,
                'reviewed_at' => $lastReviewedAt,
            ]);
        }

        if ($lastReviewedAt !== null) {
            $flashcard->update([
                // Due from the start of the day, so "due today" holds all day.
                'due_at' => $lastReviewedAt->copy()->addDays($intervalDays)->startOfDay(),
                'interval_days' => $intervalDays,
                'ease_factor' => $easeFactor,
                'repetitions' => $repetitions,
                'lapses' => $lapses,
            ]);
        }

        return $flashcard;
    }

    /**
     * @param  array<int, array{0: string, 1: bool, 2: string}>  $options  [label, is correct, explanation]
     */
    private function createQuestion(
        Topic $topic,
        Subtopic $subtopic,
        QuestionType $type,
        string $prompt,
        string $modelAnswer,
        Message $sourceMessage,
        array $options = [],
    ): Question {
        $question = $topic->questions()->create([
            'subtopic_id' => $subtopic->id,
            'type' => $type,
            'prompt' => $prompt,
            'model_answer' => $modelAnswer,
            'origin' => ContentOrigin::Ai,
            'source_message_id' => $sourceMessage->id,
        ]);

        foreach ($options as $index => [$label, $isCorrect, $explanation]) {
            $question->options()->create([
                'label' => $label,
                'is_correct' => $isCorrect,
                'explanation' => $explanation,
                'position' => $index + 1,
            ]);
        }

        return $question;
    }

    /**
     * @param  array<int, string>|null  $gaps
     */
    private function createAnswer(
        User $user,
        Question $question,
        ?StudySession $session,
        Carbon $answeredAt,
        ?string $option = null,
        ?string $response = null,
        ?int $score = null,
        ?bool $isCorrect = null,
        ?string $feedback = null,
        ?array $gaps = null,
    ): void {
        $chosenOption = $option === null ? null : $question->options()->where('label', $option)->sole();

        $question->answers()->create([
            'user_id' => $user->id,
            'study_session_id' => $session?->id,
            'question_option_id' => $chosenOption?->id,
            'response' => $response,
            'score' => $score,
            'is_correct' => $isCorrect,
            'feedback' => $feedback,
            'gaps' => $gaps,
            'answered_at' => $answeredAt,
        ]);
    }

    /**
     * @param  array{0: Subtopic, 1: int}  ...$masteryPerSubtopic
     */
    private function createProgress(User $user, array ...$masteryPerSubtopic): void
    {
        foreach ($masteryPerSubtopic as [$subtopic, $mastery]) {
            $user->subtopicProgress()->create([
                'subtopic_id' => $subtopic->id,
                'mastery' => $mastery,
                'last_studied_at' => $mastery > 0 ? now()->subDay() : null,
            ]);
        }
    }
}
