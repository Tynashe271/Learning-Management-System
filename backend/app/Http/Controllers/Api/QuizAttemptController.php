<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuizAttemptController extends Controller
{
    public function start(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorize('take', $quiz);
        $user = $request->user();

        [$attempt, $created] = DB::transaction(function () use ($quiz, $user) {
            // Serialise concurrent starts so a student cannot open two attempts at once.
            Quiz::whereKey($quiz->id)->lockForUpdate()->first();
            $attempts = $quiz->attempts()->where('user_id', $user->id)->get();
            foreach ($attempts as $attempt) {
                $attempt->setRelation('quiz', $quiz);
                $this->closeIfExpired($attempt);
            }
            if ($open = $attempts->first(fn ($a) => $a->submitted_at === null)) {
                return [$open, false];
            }
            if ($attempts->count() >= $quiz->max_attempts) {
                throw ValidationException::withMessages(['quiz' => 'No attempts remaining.']);
            }
            if ($quiz->questions()->doesntExist()) {
                throw ValidationException::withMessages(['quiz' => 'This quiz has no questions yet.']);
            }

            return [$quiz->attempts()->create(['user_id' => $user->id, 'started_at' => now()]), true];
        });

        return response()->json($this->inProgress($attempt->setRelation('quiz', $quiz)), $created ? 201 : 200);
    }

    public function show(Request $request, QuizAttempt $attempt): JsonResponse
    {
        $attempt->load('quiz.offering');
        abort_unless($attempt->user_id === $request->user()->id || $request->user()->can('manage', $attempt->quiz->offering), 403);
        $this->closeIfExpired($attempt);

        return response()->json($attempt->submitted_at === null ? $this->inProgress($attempt) : $this->result($attempt));
    }

    /** Saves a snapshot of in-progress answers without grading, so a crashed browser, a cleared cache, or switching devices does not lose an exam in progress. */
    public function saveDraft(Request $request, QuizAttempt $attempt): JsonResponse
    {
        abort_unless($attempt->user_id === $request->user()->id, 403);
        abort_if($attempt->submitted_at !== null, 422, 'This attempt has already been submitted.');
        $data = $request->validate([
            'answers' => ['present', 'array'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.option_ids' => ['sometimes', 'array', 'max:10'],
            'answers.*.option_ids.*' => ['integer'],
            'answers.*.text' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);
        $attempt->update(['draft_answers' => $data['answers']]);

        return response()->json(['saved_at' => now()]);
    }

    public function submit(Request $request, QuizAttempt $attempt): JsonResponse
    {
        abort_unless($attempt->user_id === $request->user()->id, 403);
        $attempt->load('quiz');
        $data = $request->validate([
            'answers' => ['present', 'array'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.option_ids' => ['sometimes', 'array', 'max:10'],
            'answers.*.option_ids.*' => ['integer'],
            'answers.*.text' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ]);

        $graded = DB::transaction(function () use ($attempt, $data) {
            $locked = QuizAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($locked->submitted_at !== null) {
                throw ValidationException::withMessages(['attempt' => 'Already submitted.']);
            }
            $locked->setRelation('quiz', $attempt->quiz);
            if ($locked->isExpired()) {
                $this->closeIfExpired($locked);

                return null;
            }

            $questions = $attempt->quiz->questions()->ordered()->with('options')->get()->keyBy('id');
            $given = collect($data['answers'])->keyBy('question_id');
            if ($given->keys()->diff($questions->keys())->isNotEmpty()) {
                throw ValidationException::withMessages(['answers' => 'An answer refers to a question that is not in this quiz.']);
            }
            $score = 0;
            foreach ($questions as $question) {
                if ($question->type === 'essay') {
                    $text = trim((string) ($given->get($question->id)['text'] ?? ''));
                    $locked->answers()->create(['quiz_question_id' => $question->id, 'response' => ['text' => $text], 'is_correct' => false, 'points' => 0, 'needs_manual_grading' => true]);

                    continue;
                }
                [$response, $correct] = $this->grade($question, $given->get($question->id));
                $points = $correct ? $question->points : 0;
                $score += $points;
                $locked->answers()->create(['quiz_question_id' => $question->id, 'response' => $response, 'is_correct' => $correct, 'points' => $points]);
            }
            $locked->update(['submitted_at' => now(), 'score' => $score, 'max_score' => $questions->sum('points'), 'draft_answers' => null]);

            return $locked;
        });
        if ($graded === null) {
            throw ValidationException::withMessages(['attempt' => 'The time limit has passed; this attempt was closed with no score.']);
        }

        return response()->json($this->result($graded->setRelation('quiz', $attempt->quiz)));
    }

    public function index(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorize('manage', $quiz->offering);

        return response()->json($quiz->attempts()->with('user:id,name,email')->latest('id')->paginate(50));
    }

    public function mine(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorize('view', $quiz);
        $attempts = $quiz->attempts()->where('user_id', $request->user()->id)->orderBy('id')->get();
        foreach ($attempts as $attempt) {
            $attempt->setRelation('quiz', $quiz);
            $this->closeIfExpired($attempt);
        }

        return response()->json($attempts->map(fn ($a) => $a->only(['id', 'started_at', 'submitted_at', 'score', 'max_score']))->values());
    }

    /** An attempt that ran out of time without being submitted is closed with a zero score. */
    private function closeIfExpired(QuizAttempt $attempt): void
    {
        if ($attempt->isExpired()) {
            $attempt->update(['submitted_at' => now(), 'score' => 0, 'max_score' => $attempt->quiz->totalPoints()]);
        }
    }

    /** Questions for a student mid-attempt: no correct flags, and short-answer keys are never sent. */
    private function inProgress(QuizAttempt $attempt): array
    {
        return [
            'id' => $attempt->id,
            'quiz_id' => $attempt->quiz_id,
            'started_at' => $attempt->started_at,
            'deadline' => $attempt->deadline(),
            'draft_answers' => $attempt->draft_answers ?? [],
            'questions' => $attempt->quiz->questions()->ordered()->with('options')->get()->map(fn ($q) => [
                'id' => $q->id,
                'type' => $q->type,
                'prompt' => $q->prompt,
                'points' => $q->points,
                'options' => in_array($q->type, ['short_answer', 'essay'], true) ? [] : $q->options->map(fn ($o) => ['id' => $o->id, 'text' => $o->text])->values(),
            ])->values(),
        ];
    }

    private function result(QuizAttempt $attempt): array
    {
        $questions = $attempt->quiz->questions()->ordered()->get()->keyBy('id');
        $answers = $attempt->answers()->orderBy('id')->get();

        return [
            'id' => $attempt->id,
            'quiz_id' => $attempt->quiz_id,
            'started_at' => $attempt->started_at,
            'submitted_at' => $attempt->submitted_at,
            'score' => $attempt->score,
            'max_score' => $attempt->max_score,
            'awaiting_manual_grading' => $answers->contains('needs_manual_grading', true),
            'answers' => $answers->map(fn ($a) => [
                'id' => $a->id,
                'question_id' => $a->quiz_question_id,
                'prompt' => $questions->get($a->quiz_question_id)?->prompt,
                'max_points' => $questions->get($a->quiz_question_id)?->points,
                'response' => $a->response,
                'is_correct' => $a->is_correct,
                'points' => $a->points,
                'needs_manual_grading' => $a->needs_manual_grading,
            ])->values(),
        ];
    }

    /** A grader marks one essay answer; the attempt's score is recalculated from every answer's points. */
    public function gradeAnswer(Request $request, QuizAnswer $answer): JsonResponse
    {
        $attempt = $answer->attempt()->with('quiz')->firstOrFail();
        $this->authorize('manage', $attempt->quiz->offering);
        $question = $answer->question;
        $data = $request->validate(['points' => ['required', 'numeric', 'min:0', 'max:'.$question->points]]);
        $answer->update(['points' => $data['points'], 'is_correct' => $data['points'] >= $question->points, 'needs_manual_grading' => false]);
        $attempt->update(['score' => $attempt->answers()->sum('points')]);

        return response()->json($this->result($attempt->setRelation('quiz', $attempt->quiz)));
    }

    /** @return array{0: array<string, mixed>, 1: bool} the stored response and whether it is fully correct */
    private function grade(QuizQuestion $question, ?array $answer): array
    {
        if ($question->type === 'short_answer') {
            $normalise = fn (string $s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
            $text = trim((string) ($answer['text'] ?? ''));
            $accepted = $question->options->map(fn ($o) => $normalise($o->text));

            return [['text' => $text], $text !== '' && $accepted->contains($normalise($text))];
        }
        $selected = array_values(array_unique(array_map('intval', $answer['option_ids'] ?? [])));
        $correct = $question->options->where('is_correct', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        sort($selected);
        sort($correct);

        return [['option_ids' => $selected], $selected !== [] && $selected === $correct];
    }
}
