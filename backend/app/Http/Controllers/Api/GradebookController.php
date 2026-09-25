<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\GradeRecord;
use App\Models\QuizAttempt;
use App\Models\Submission;
use App\Models\User;
use App\Support\Paging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GradebookController extends Controller
{
    private const CSV_CHUNK = 200;

    /** One page of actively enrolled students' marks (`?page=`, `?per_page=`); `?format=csv` streams all of them as a spreadsheet. */
    public function gradebook(Request $request, CourseOffering $offering): JsonResponse|StreamedResponse
    {
        $this->authorize('manage', $offering);
        if ($request->query('format') === 'csv') {
            return $this->csv($offering);
        }
        [$enrolments, $meta] = Paging::page($request, Paging::activeStudents($offering));

        return response()->json($this->build($offering, $enrolments->pluck('user')->filter()->values()) + ['meta' => $meta]);
    }

    /** The signed-in student's own marks: published grades and submitted quiz attempts only. */
    public function mine(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('view', $offering);
        $me = Enrolment::where('course_offering_id', $offering->id)->where('user_id', $request->user()->id)->where('status', 'active')->with('user:id,name,email')->first();
        $book = $this->build($offering, collect([$me?->user])->filter()->values());

        return response()->json(['columns' => $book['columns'], 'grades' => $book['rows'][0] ?? null]);
    }

    /**
     * Assignments count their latest published grade; quizzes count the best submitted attempt.
     * Percent is taken over the items a student has a mark for, so unmarked work does not drag it down.
     *
     * @param  Collection<int, User>  $students  the people to build rows for, in the order to show them
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    private function build(CourseOffering $offering, Collection $students): array
    {
        $assignments = $offering->assignments()->where('published', true)->orderBy('due_at')->orderBy('id')->get();
        $quizzes = $offering->quizzes()->where('published', true)->where('is_practice', false)->withSum('questions as total_points', 'points')->orderBy('due_at')->orderBy('id')->get();

        $columns = $assignments->map(fn ($a) => ['key' => 'assignment:'.$a->id, 'type' => 'assignment', 'id' => $a->id, 'title' => $a->title, 'max' => (float) $a->max_score])
            ->concat($quizzes->map(fn ($q) => ['key' => 'quiz:'.$q->id, 'type' => 'quiz', 'id' => $q->id, 'title' => $q->title, 'max' => (float) $q->total_points]))
            ->values()->all();
        $max = collect($columns)->pluck('max', 'key');
        $userIds = $students->pluck('id');

        $scores = [];
        $submissions = Submission::whereIn('assignment_id', $assignments->pluck('id'))->whereIn('user_id', $userIds)->get(['id', 'assignment_id', 'user_id']);
        // Ordered by id, so keyBy leaves each submission's most recent published grade.
        $grades = GradeRecord::whereIn('submission_id', $submissions->pluck('id'))->where('status', 'published')->orderBy('id')->get()->keyBy('submission_id');
        foreach ($submissions as $submission) {
            if ($grade = $grades->get($submission->id)) {
                $scores[$submission->user_id]['assignment:'.$submission->assignment_id] = (float) $grade->score;
            }
        }
        $attempts = QuizAttempt::whereIn('quiz_id', $quizzes->pluck('id'))->whereNotNull('submitted_at')->whereIn('user_id', $userIds)->get(['quiz_id', 'user_id', 'score']);
        foreach ($attempts as $attempt) {
            $key = 'quiz:'.$attempt->quiz_id;
            $score = (float) $attempt->score;
            if (! isset($scores[$attempt->user_id][$key]) || $score > $scores[$attempt->user_id][$key]) {
                $scores[$attempt->user_id][$key] = $score;
            }
        }

        $rows = $students->map(function ($user) use ($columns, $scores, $max) {
            $marked = $scores[$user->id] ?? [];
            $total = array_sum($marked);
            $possible = array_sum(array_map(fn ($key) => $max[$key], array_keys($marked)));

            return [
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                'scores' => collect($columns)->mapWithKeys(fn ($c) => [$c['key'] => $marked[$c['key']] ?? null])->all(),
                'total' => $total,
                'possible' => $possible,
                'percent' => $possible > 0 ? round($total / $possible * 100, 2) : null,
            ];
        })->values()->all();

        return ['columns' => $columns, 'rows' => $rows];
    }

    /** Streams the whole class in chunks, so a very large class never has to be held in memory at once. */
    private function csv(CourseOffering $offering): StreamedResponse
    {
        return response()->streamDownload(function () use ($offering) {
            $out = fopen('php://output', 'w');
            $put = fn (array $row) => fputcsv($out, $row, ',', '"', '');
            $page = 1;
            do {
                $paginator = Paging::activeStudents($offering)->paginate(self::CSV_CHUNK, ['*'], 'page', $page);
                $book = $this->build($offering, $paginator->getCollection()->pluck('user')->filter()->values());
                if ($page === 1) {
                    $put(array_merge(['Name', 'Email'], array_map(fn ($c) => $this->safeCell($c['title'].' (out of '.$c['max'].')'), $book['columns']), ['Total', 'Possible', 'Percent']));
                }
                foreach ($book['rows'] as $row) {
                    $put(array_merge(
                        [$this->safeCell($row['user']['name']), $this->safeCell($row['user']['email'])],
                        array_map(fn ($c) => $row['scores'][$c['key']] ?? '', $book['columns']),
                        [$row['total'], $row['possible'], $row['percent'] ?? ''],
                    ));
                }
                $page++;
            } while ($paginator->hasMorePages());
            fclose($out);
        }, 'gradebook-offering-'.$offering->id.'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Stops spreadsheet apps from running user-supplied text (names, titles) as a formula. */
    private function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
