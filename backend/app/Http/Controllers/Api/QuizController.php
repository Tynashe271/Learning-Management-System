<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuizController extends Controller
{
    public function store(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('manage', $offering);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
            'opens_at' => ['nullable', 'date'],
            'due_at' => ['required', 'date', 'after:now'],
            'time_limit_minutes' => ['nullable', 'integer', 'min:1', 'max:600'],
            'max_attempts' => ['sometimes', 'integer', 'min:1', $this->maxAttemptsCap($request->boolean('is_practice'))],
            'published' => ['sometimes', 'boolean'],
            'is_practice' => ['sometimes', 'boolean'],
            'questions_per_attempt' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'course_module_id' => ['nullable', 'integer', Rule::exists('course_modules', 'id')->where('course_offering_id', $offering->id)],
            'target_user_ids' => ['sometimes', 'array'],
            'target_user_ids.*' => ['integer', Rule::exists('enrolments', 'user_id')->where('course_offering_id', $offering->id)->where('status', 'active')],
        ]);
        $this->assertWindow($data['opens_at'] ?? null, $data['due_at']);
        $targetIds = $data['target_user_ids'] ?? null;
        unset($data['target_user_ids']);

        $quiz = $offering->quizzes()->create($data);
        if ($targetIds !== null) {
            $quiz->targetedUsers()->sync($targetIds);
        }
        $quiz->target_user_ids = $quiz->targetedUsers()->pluck('users.id')->all();

        return response()->json($quiz, 201);
    }

    public function show(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorize('view', $quiz);
        if ($request->user()->can('manage', $quiz->offering)) {
            $quiz->load(['questions' => fn ($q) => $q->ordered(), 'questions.options']);
            $quiz->target_user_ids = $quiz->targetedUsers()->pluck('users.id')->all();

            return response()->json($quiz);
        }

        // Students never receive questions here; they arrive with an attempt.
        return response()->json($quiz->only(['id', 'course_offering_id', 'title', 'instructions', 'opens_at', 'due_at', 'time_limit_minutes', 'max_attempts', 'is_practice', 'questions_per_attempt']) + [
            'questions_count' => $quiz->questions()->count(),
            'total_points' => $quiz->totalPoints(),
            'attempts_used' => $quiz->attempts()->where('user_id', $request->user()->id)->count(),
        ]);
    }

    public function update(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorize('manage', $quiz->offering);
        $isPractice = $request->has('is_practice') ? $request->boolean('is_practice') : $quiz->is_practice;
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string'],
            'opens_at' => ['sometimes', 'nullable', 'date'],
            'due_at' => ['sometimes', 'date', 'after:now'],
            'time_limit_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:600'],
            'max_attempts' => ['sometimes', 'integer', 'min:1', $this->maxAttemptsCap($isPractice)],
            'published' => ['sometimes', 'boolean'],
            'is_practice' => ['sometimes', 'boolean'],
            'questions_per_attempt' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'change_reason' => ['sometimes', 'string', 'max:1000'],
            'course_module_id' => ['sometimes', 'nullable', 'integer', Rule::exists('course_modules', 'id')->where('course_offering_id', $quiz->course_offering_id)],
            'target_user_ids' => ['sometimes', 'array'],
            'target_user_ids.*' => ['integer', Rule::exists('enrolments', 'user_id')->where('course_offering_id', $quiz->course_offering_id)->where('status', 'active')],
        ]);
        if (isset($data['due_at']) && empty($data['change_reason'])) {
            throw ValidationException::withMessages(['change_reason' => 'A reason is required for deadline changes.']);
        }
        $this->assertWindow(array_key_exists('opens_at', $data) ? $data['opens_at'] : $quiz->opens_at, $data['due_at'] ?? $quiz->due_at);
        $targetIds = array_key_exists('target_user_ids', $data) ? $data['target_user_ids'] : null;
        unset($data['target_user_ids']);
        $reason = $data['change_reason'] ?? null;
        unset($data['change_reason']);
        $quiz->update($data);
        if ($targetIds !== null) {
            $quiz->targetedUsers()->sync($targetIds);
        }
        activity()->causedBy($request->user())->performedOn($quiz)->withProperties(['changes' => $data, 'reason' => $reason])->log('quiz changed');
        $quiz->target_user_ids = $quiz->targetedUsers()->pluck('users.id')->all();

        return response()->json($quiz);
    }

    public function destroy(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorize('manage', $quiz->offering);
        if ($quiz->attempts()->exists()) {
            throw ValidationException::withMessages(['quiz' => 'A quiz with attempts cannot be deleted; unpublish it instead.']);
        }
        $quiz->delete();
        activity()->causedBy($request->user())->performedOn($quiz)->log('quiz deleted');

        return response()->json(['message' => 'Quiz deleted.']);
    }

    public function addQuestion(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorize('manage', $quiz->offering);
        $this->assertEditable($quiz);
        $type = $request->validate(['type' => ['required', Rule::in(QuizQuestion::TYPES)]])['type'];
        [$fields, $options] = $this->questionPayload($request, $type);
        $fields['position'] ??= (int) $quiz->questions()->max('position') + 1;

        $question = DB::transaction(function () use ($quiz, $type, $fields, $options) {
            $question = $quiz->questions()->create($fields + ['type' => $type]);
            $question->options()->createMany($options);

            return $question;
        });

        return response()->json($question->load('options'), 201);
    }

    public function updateQuestion(Request $request, QuizQuestion $question): JsonResponse
    {
        $quiz = $question->quiz;
        $this->authorize('manage', $quiz->offering);
        $this->assertEditable($quiz);
        [$fields, $options] = $this->questionPayload($request, $question->type);

        DB::transaction(function () use ($question, $fields, $options) {
            $question->update($fields);
            $question->options()->delete();
            $question->options()->createMany($options);
        });

        return response()->json($question->load('options'));
    }

    public function deleteQuestion(Request $request, QuizQuestion $question): JsonResponse
    {
        $quiz = $question->quiz;
        $this->authorize('manage', $quiz->offering);
        $this->assertEditable($quiz);
        $question->delete();

        return response()->json(['message' => 'Question deleted.']);
    }

    /** Questions are locked once anyone has attempted the quiz, so past scores stay meaningful. */
    private function assertEditable(Quiz $quiz): void
    {
        if ($quiz->attempts()->exists()) {
            throw ValidationException::withMessages(['quiz' => 'This quiz already has attempts; unpublish it instead of changing its questions.']);
        }
    }

    private function assertWindow(mixed $opensAt, mixed $dueAt): void
    {
        if ($opensAt && Carbon::parse($opensAt)->greaterThanOrEqualTo(Carbon::parse($dueAt))) {
            throw ValidationException::withMessages(['opens_at' => 'The quiz must open before it is due.']);
        }
    }

    /** A practice quiz is meant to be retaken freely, so its attempts ceiling is far higher than a graded quiz's. */
    private function maxAttemptsCap(bool $isPractice): string
    {
        return 'max:'.($isPractice ? 999 : 20);
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} question fields and normalised options */
    private function questionPayload(Request $request, string $type): array
    {
        $isTrueFalse = $type === 'true_false';
        $isEssay = $type === 'essay';
        $data = $request->validate([
            'prompt' => ['required', 'string'],
            'points' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'options' => [$isTrueFalse || $isEssay ? 'prohibited' : 'required', 'array', 'max:10'],
            'options.*.text' => ['required', 'string', 'max:500'],
            'options.*.is_correct' => ['sometimes', 'boolean'],
            'correct' => [$isTrueFalse ? 'required' : 'prohibited', 'boolean'],
        ]);

        if ($isTrueFalse) {
            $correct = $request->boolean('correct');
            $options = [['text' => 'True', 'is_correct' => $correct], ['text' => 'False', 'is_correct' => ! $correct]];
        } elseif ($isEssay) {
            $options = [];
        } else {
            $options = array_map(fn ($o) => ['text' => $o['text'], 'is_correct' => filter_var($o['is_correct'] ?? false, FILTER_VALIDATE_BOOLEAN)], $data['options']);
        }
        $correctCount = count(array_filter($options, fn ($o) => $o['is_correct']));
        $problem = match ($type) {
            'single_choice' => count($options) < 2 ? 'At least two options are required.' : ($correctCount !== 1 ? 'Exactly one option must be correct.' : null),
            'multiple_choice' => count($options) < 2 ? 'At least two options are required.' : ($correctCount < 1 ? 'At least one option must be correct.' : null),
            default => null,
        };
        if ($problem) {
            throw ValidationException::withMessages(['options' => $problem]);
        }
        if ($type === 'short_answer') {
            // Every option is an accepted answer.
            $options = array_map(fn ($o) => ['text' => $o['text'], 'is_correct' => true], $options);
        }
        $options = array_map(fn ($option, $i) => $option + ['position' => $i], $options, array_keys($options));

        return [array_intersect_key($data, array_flip(['prompt', 'points', 'position'])), $options];
    }
}
