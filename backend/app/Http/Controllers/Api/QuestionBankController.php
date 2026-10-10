<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuestionBankItem;
use App\Models\QuizQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** A lecturer's own reusable library of quiz questions - stored once, copied into any of their quizzes when used, organised by type and tag. */
class QuestionBankController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['sometimes', Rule::in(QuizQuestion::TYPES)],
            'tag' => ['sometimes', 'string', 'max:50'],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);
        $query = QuestionBankItem::with('options')->where('owner_id', $request->user()->id)->ordered();
        if (isset($data['type'])) {
            $query->where('type', $data['type']);
        }
        if (isset($data['tag'])) {
            $query->whereJsonContains('tags', $data['tag']);
        }
        if (isset($data['q'])) {
            $like = '%'.strtolower(preg_replace('/[!%_]/', '!$0', $data['q'])).'%';
            $query->whereRaw("lower(prompt) like ? escape '!'", [$like]);
        }

        return response()->json($query->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $type = $request->validate(['type' => ['required', Rule::in(QuizQuestion::TYPES)]])['type'];
        [$fields, $options] = $this->payload($request, $type);

        $item = DB::transaction(function () use ($request, $type, $fields, $options) {
            $item = QuestionBankItem::create($fields + ['type' => $type, 'owner_id' => $request->user()->id]);
            $item->options()->createMany($options);

            return $item;
        });

        return response()->json($item->load('options'), 201);
    }

    public function update(Request $request, QuestionBankItem $item): JsonResponse
    {
        $this->authorizeOwner($request, $item);
        [$fields, $options] = $this->payload($request, $item->type);

        DB::transaction(function () use ($item, $fields, $options) {
            $item->update($fields);
            $item->options()->delete();
            $item->options()->createMany($options);
        });

        return response()->json($item->load('options'));
    }

    public function destroy(Request $request, QuestionBankItem $item): JsonResponse
    {
        $this->authorizeOwner($request, $item);
        $item->delete();

        return response()->json(['message' => 'Removed from your question bank.']);
    }

    private function authorizeOwner(Request $request, QuestionBankItem $item): void
    {
        abort_unless($item->owner_id === $request->user()->id, 403);
    }

    /** @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} */
    private function payload(Request $request, string $type): array
    {
        $isTrueFalse = $type === 'true_false';
        $isEssay = $type === 'essay';
        $data = $request->validate([
            'prompt' => ['required', 'string'],
            'points' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:50'],
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
            $options = array_map(fn ($o) => ['text' => $o['text'], 'is_correct' => true], $options);
        }
        $options = array_map(fn ($option, $i) => $option + ['position' => $i], $options, array_keys($options));

        return [array_intersect_key($data, array_flip(['prompt', 'points', 'tags'])), $options];
    }
}
