<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Services\AiAssistant;
use App\Services\AiUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The AI learning assistant (roadmap item 15) and lecturer teaching assistant (item 16): both share one Claude-backed
 * service, grounded in the course's own published material, and both are off unless an administrator has configured
 * a real API key (see "AI learning assistant" in the README).
 */
class AiAssistantController extends Controller
{
    private const STUDENT_MODES = [
        'ask' => 'Answer the student\'s question clearly and directly.',
        'summarise' => 'Write a clear, concise summary of what the student is asking about.',
        'revision_questions' => 'Write a short set of revision questions, each followed by its answer, to help the student practise.',
        'flashcards' => 'Write a set of flashcards as "Front: ... / Back: ..." pairs.',
        'study_plan' => 'Write a short, concrete, day-by-day personal study plan for what the student describes.',
    ];

    private const TEACHING_MODES = [
        'lesson_outline' => 'Draft a lesson outline for the described topic.',
        'quiz_draft' => 'Draft a short set of quiz questions, each with its correct answer clearly marked, for the described topic.',
        'rubric' => 'Draft a marking rubric (criteria with point values that add up to a sensible total) for the described assessment.',
        'discussion_questions' => 'Suggest a handful of open-ended discussion questions for the described topic.',
        'remedial_suggestions' => 'Suggest remedial activities for students who are struggling with the described topic.',
    ];

    public function assist(Request $request, CourseOffering $offering, AiAssistant $ai): JsonResponse
    {
        $this->authorize('view', $offering);
        abort_unless($offering->enrolments()->where('user_id', $request->user()->id)->where('status', 'active')->exists(), 403);
        $data = $request->validate([
            'mode' => ['required', Rule::in(array_keys(self::STUDENT_MODES))],
            'prompt' => ['required', 'string', 'max:2000'],
        ]);

        [$system, $sources] = $this->buildPrompt($offering, self::STUDENT_MODES[$data['mode']], restrictToMaterial: true);

        return $this->respond($ai, $system, $data['prompt'], $sources);
    }

    public function teachingAssist(Request $request, CourseOffering $offering, AiAssistant $ai): JsonResponse
    {
        $this->authorize('manage', $offering);
        $data = $request->validate([
            'mode' => ['required', Rule::in(array_keys(self::TEACHING_MODES))],
            'prompt' => ['required', 'string', 'max:2000'],
        ]);

        [$system, $sources] = $this->buildPrompt($offering, self::TEACHING_MODES[$data['mode']], restrictToMaterial: false);

        return $this->respond($ai, $system, $data['prompt'], $sources);
    }

    /** @return array{0: string, 1: Collection<int, array{id: int, title: string}>} the system prompt, and the numbered sources it refers to */
    private function buildPrompt(CourseOffering $offering, string $instruction, bool $restrictToMaterial): array
    {
        $items = $offering->modules()->with(['items' => fn ($q) => $q->where('published', true)->where('type', 'text')])->get()->pluck('items')->flatten();
        $budget = (int) config('lms.ai.max_source_chars');
        $used = 0;
        $sources = collect();
        $material = '';
        foreach ($items as $i => $item) {
            $chunk = Str::limit((string) $item->body, max(0, min(2000, $budget - $used)));
            if ($chunk === '') {
                continue;
            }
            $n = $i + 1;
            $material .= "[{$n}] {$item->title}\n{$chunk}\n\n";
            $sources->push(['id' => $item->id, 'title' => $item->title]);
            $used += mb_strlen($chunk);
            if ($used >= $budget) {
                break;
            }
        }

        $course = $offering->course?->title ?? 'this course';
        $rules = $restrictToMaterial
            ? "Rules:\n- Use ONLY the numbered course material below. Do not use outside knowledge, even if you know the answer.\n- If the material does not cover what is asked, say so plainly instead of guessing.\n- Never write a complete, submission-ready answer to a specific graded assignment or quiz question. Offer hints, explanations and related practice instead.\n- End your reply with a final line exactly like \"Sources: 1, 3\" naming the numbers you drew on, or \"Sources: none\" if the material had nothing useful."
            : "Rules:\n- Ground your draft in the numbered course material below where it is relevant, so it fits what has already been taught.\n- You may also use your general teaching knowledge, since this is a draft for the lecturer to review before use - say so is not required.\n- End your reply with a final line exactly like \"Sources: 1, 3\" naming any material numbers you drew on, or \"Sources: none\".";

        $system = "You are a teaching assistant for \"{$course}\". {$instruction}\n\n{$rules}\n\nCourse material:\n".($material !== '' ? $material : '(none published yet)');

        return [$system, $sources];
    }

    /** @param  Collection<int, array{id: int, title: string}>  $sources */
    private function respond(AiAssistant $ai, string $system, string $prompt, Collection $sources): JsonResponse
    {
        try {
            $text = $ai->complete($system, $prompt);
        } catch (AiUnavailable $e) {
            throw ValidationException::withMessages(['prompt' => $e->getMessage()]);
        }

        $used = [];
        if (preg_match('/Sources:\s*([^\n]*)\s*$/i', trim($text), $m)) {
            $text = trim(Str::beforeLast($text, $m[0]));
            foreach (explode(',', $m[1]) as $n) {
                $n = (int) trim($n);
                if ($n >= 1 && $sources->has($n - 1)) {
                    $used[] = $sources[$n - 1];
                }
            }
        }

        return response()->json(['answer' => $text, 'sources' => array_values($used)]);
    }
}
