<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\GradeRecord;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Submission;
use App\Models\User;
use App\Services\TeacherWorkload;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminReportController extends Controller
{
    private const OVERVIEW_SECONDS = 60;

    private const CSV_LIMIT = 20000;

    /**
     * Who did what: grade changes, deadline changes, enrolments, account changes, settings, backups. Filters: `causer_id`,
     * `q` (part of the description), `from` and `to` (dates), `subject_type` (for example Assignment). `?format=csv`
     * downloads up to 20,000 matching entries as a spreadsheet.
     */
    public function audit(Request $request): JsonResponse|StreamedResponse
    {
        abort_unless($request->user()->can('manage-users'), 403);
        $data = $request->validate([
            'causer_id' => ['sometimes', 'integer'], 'q' => ['sometimes', 'string', 'max:100'], 'from' => ['sometimes', 'date'], 'to' => ['sometimes', 'date'],
            'subject_type' => ['sometimes', 'string', 'max:60', 'regex:/^[A-Za-z]+$/'], 'format' => ['sometimes', 'in:json,csv'],
        ]);

        $query = Activity::query()->with('causer:id,name,email')->latest('id');
        if (isset($data['causer_id'])) {
            $query->where('causer_type', User::class)->where('causer_id', $data['causer_id']);
        }
        if (isset($data['q'])) {
            // '!' is the LIKE escape character: a backslash breaks PDO's placeholder parsing on PostgreSQL.
            $query->whereRaw("lower(description) like ? escape '!'", ['%'.strtolower(preg_replace('/[!%_]/', '!$0', $data['q'])).'%']);
        }
        if (isset($data['from'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($data['from'])->startOfDay());
        }
        if (isset($data['to'])) {
            $query->where('created_at', '<=', CarbonImmutable::parse($data['to'])->endOfDay());
        }
        if (isset($data['subject_type'])) {
            $query->where('subject_type', 'like', '%\\'.$data['subject_type']);
        }

        if (($data['format'] ?? 'json') === 'csv') {
            return $this->auditCsv($request, $query);
        }

        return response()->json($query->paginate(50));
    }

    private function auditCsv(Request $request, $query): StreamedResponse
    {
        activity()->causedBy($request->user())->log('audit log exported');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            $put = fn (array $row) => fputcsv($out, array_map(fn ($cell) => $this->safeCell((string) $cell), $row), ',', '"', '');
            $put(['When (UTC)', 'Who', 'Email', 'What', 'About', 'Details']);
            foreach ($query->take(self::CSV_LIMIT)->get() as $entry) {
                $put([
                    $entry->created_at?->toDateTimeString(), $entry->causer?->name ?? 'System', $entry->causer?->email ?? '', $entry->description,
                    $entry->subject_type ? class_basename($entry->subject_type).' #'.$entry->subject_id : '', json_encode($entry->properties, JSON_UNESCAPED_UNICODE),
                ]);
            }
            fclose($out);
        }, 'audit-log-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function overview(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage-courses') || $request->user()->can('manage-enrolments'), 403);

        // Counting whole tables is the costliest thing a dashboard does, and to-the-second accuracy is not needed:
        // everyone who opens the report within a minute shares one calculation.
        return response()->json(Cache::remember('reports:overview', self::OVERVIEW_SECONDS, fn () => [
            'users' => [
                'total' => User::count(),
                'active' => User::where('is_active', true)->count(),
                'by_role' => DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->where('model_has_roles.model_type', User::class)
                    // A plain array, not a Collection: the cache does not restore objects (they come back unusable), so only
                    // simple values may be stored in it.
                    ->select('roles.name', DB::raw('count(*) as total'))->groupBy('roles.name')->pluck('total', 'name')->map(fn ($n) => (int) $n)->all(),
            ],
            'offerings' => ['total' => CourseOffering::count(), 'published' => CourseOffering::where('published', true)->count()],
            'enrolments_active' => Enrolment::where('status', 'active')->count(),
            'assignments' => Assignment::count(),
            'submissions' => Submission::count(),
            'quizzes' => Quiz::count(),
            'quiz_attempts_submitted' => QuizAttempt::whereNotNull('submitted_at')->count(),
            'warnings' => $this->catalogueWarnings(),
        ]));
    }

    /** Course-catalogue health checks worth a coordinator's or administrator's attention, cheap enough to run every minute. */
    private function catalogueWarnings(): array
    {
        $unstaffed = CourseOffering::where('published', true)->whereDoesntHave('teachers')
            ->with('course:id,code', 'term:id,name')->get()
            ->map(fn ($o) => "{$o->course->code} ({$o->term->name}) is published but has no teacher assigned.");
        $startingSoon = CourseOffering::where('published', false)
            ->whereHas('term', fn ($q) => $q->whereBetween('starts_on', [now()->toDateString(), now()->addDays(14)->toDateString()]))
            ->with('course:id,code', 'term:id,name')->get()
            ->map(fn ($o) => "{$o->course->code} ({$o->term->name}) starts within 14 days but is not published.");

        return $unstaffed->concat($startingSoon)->values()->all();
    }

    /**
     * How much the system is used: sign-ins, submissions, quiz attempts and new accounts for each of the last 30 days
     * (days are UTC), and how many different people were active in the last 7 and 30 days.
     */
    public function usage(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage-courses'), 403);
        $since = now()->subDays(29)->startOfDay();
        $perDay = function (string $table, string $column, ?callable $filter = null) use ($since) {
            $counts = DB::table($table)->where($column, '>=', $since)->when($filter, fn ($q) => $filter($q))
                ->selectRaw("date({$column}) as day, count(*) as total")->groupBy('day')->pluck('total', 'day');

            return collect(range(0, 29))->map(fn ($i) => $since->copy()->addDays($i)->toDateString())->map(fn ($day) => ['date' => $day, 'count' => (int) ($counts[$day] ?? 0)])->all();
        };
        $active = fn (int $days) => DB::table('security_events')->where('event', 'login.success')->where('created_at', '>=', now()->subDays($days))->whereNotNull('user_id')->distinct()->count('user_id');

        return response()->json([
            'sign_ins_per_day' => $perDay('security_events', 'created_at', fn ($q) => $q->where('event', 'login.success')),
            'submissions_per_day' => $perDay('submissions', 'submitted_at'),
            'quiz_attempts_per_day' => $perDay('quiz_attempts', 'submitted_at', fn ($q) => $q->whereNotNull('submitted_at')),
            'new_accounts_per_day' => $perDay('users', 'created_at'),
            'active_people' => ['last_7_days' => $active(7), 'last_30_days' => $active(30), 'accounts' => User::where('is_active', true)->count()],
        ]);
    }

    /**
     * Enrolment across the institution: each term and department with its offerings, students, places and how full they are, the
     * fullest courses, and (for `?format=csv`) one line per offering. `?term_id=` limits it to one term.
     */
    public function enrolments(Request $request): JsonResponse|StreamedResponse
    {
        // The registrar (who enrols students but does not shape courses) needs this one.
        abort_unless($request->user()->can('manage-courses') || $request->user()->can('manage-enrolments'), 403);
        $data = $request->validate(['term_id' => ['sometimes', 'integer'], 'format' => ['sometimes', 'in:json,csv']]);

        $rows = DB::table('course_offerings as o')
            ->join('courses as c', 'c.id', '=', 'o.course_id')->join('academic_terms as t', 't.id', '=', 'o.academic_term_id')->leftJoin('departments as d', 'd.id', '=', 'c.department_id')
            ->leftJoin(DB::raw("(select course_offering_id, count(*) as n from enrolments where status = 'active' group by course_offering_id) e"), 'e.course_offering_id', '=', 'o.id')
            ->when(isset($data['term_id']), fn ($q) => $q->where('o.academic_term_id', $data['term_id']))
            ->orderByDesc('t.starts_on')->orderBy('c.code')->orderBy('o.section')
            ->get(['o.id', 'c.code', 'c.title', 'o.section', 't.id as term_id', 't.name as term', 't.academic_year', 'd.code as department_code', 'd.name as department', 'o.capacity', 'o.published', 'o.archived_at', DB::raw('coalesce(e.n, 0) as enrolled')]);

        if (($data['format'] ?? 'json') === 'csv') {
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                $put = fn (array $row) => fputcsv($out, array_map(fn ($cell) => $this->safeCell((string) $cell), $row), ',', '"', '');
                $put(['Semester', 'Academic year', 'Department', 'Course code', 'Course title', 'Section', 'Students enrolled', 'Capacity', 'Places left', 'Published', 'Archived']);
                foreach ($rows as $r) {
                    $put([$r->term, $r->academic_year, $r->department, $r->code, $r->title, $r->section, $r->enrolled, $r->capacity ?? '', $r->capacity === null ? '' : max(0, $r->capacity - $r->enrolled), $r->published ? 'yes' : 'no', $r->archived_at ? 'yes' : 'no']);
                }
                fclose($out);
            }, 'enrolment-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
        }

        $sum = fn ($items) => [
            'offerings' => $items->count(), 'published' => $items->where('published', true)->count(), 'enrolled' => (int) $items->sum('enrolled'),
            'capacity' => (int) $items->whereNotNull('capacity')->sum('capacity'),
            'enrolled_in_limited' => (int) $items->whereNotNull('capacity')->sum('enrolled'),
        ];
        $fill = fn (array $s) => $s['capacity'] > 0 ? round($s['enrolled_in_limited'] / $s['capacity'] * 100, 1) : null;

        return response()->json([
            'terms' => $rows->groupBy('term_id')->map(fn ($items) => ['id' => $items[0]->term_id, 'name' => $items[0]->term, 'academic_year' => $items[0]->academic_year] + $sum($items) + ['fill_percent' => $fill($sum($items))])->values(),
            'departments' => $rows->groupBy(fn ($r) => $r->department_code ?? '')->map(fn ($items, $code) => ['code' => $code ?: null, 'name' => $items[0]->department ?? 'No department'] + $sum($items))->values(),
            'fullest' => $rows->whereNotNull('capacity')->filter(fn ($r) => $r->capacity > 0)->sortByDesc(fn ($r) => $r->enrolled / $r->capacity)->take(10)
                ->map(fn ($r) => ['offering_id' => $r->id, 'course' => "{$r->code} {$r->title}", 'section' => $r->section, 'term' => $r->term, 'enrolled' => (int) $r->enrolled, 'capacity' => (int) $r->capacity])->values(),
            'largest' => $rows->sortByDesc('enrolled')->take(10)->filter(fn ($r) => $r->enrolled > 0)
                ->map(fn ($r) => ['offering_id' => $r->id, 'course' => "{$r->code} {$r->title}", 'section' => $r->section, 'term' => $r->term, 'enrolled' => (int) $r->enrolled])->values(),
            'total_enrolled' => (int) $rows->sum('enrolled'),
        ]);
    }

    /**
     * How much each lecturer or teaching assistant is carrying right now: their offerings, how much work is still
     * unmarked, and how long students wait for feedback - the institution-wide counterpart to a course's own
     * response-time figure.
     */
    public function workload(Request $request): JsonResponse|StreamedResponse
    {
        abort_unless($request->user()->can('manage-courses'), 403);
        $data = $request->validate(['format' => ['sometimes', 'in:json,csv']]);
        $rows = Cache::remember('reports:workload', self::OVERVIEW_SECONDS, fn () => app(TeacherWorkload::class)->report());

        if (($data['format'] ?? 'json') === 'csv') {
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                $put = fn (array $row) => fputcsv($out, array_map(fn ($cell) => $this->safeCell((string) $cell), $row), ',', '"', '');
                $put(['Name', 'Email', 'Offerings', 'Unmarked submissions', 'Unmarked essay answers', 'Average feedback hours']);
                foreach ($rows as $r) {
                    $put([$r['name'], $r['email'], $r['offerings_count'], $r['unmarked_submissions'], $r['unmarked_essay_answers'], $r['avg_feedback_hours'] ?? '']);
                }
                fclose($out);
            }, 'workload-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
        }

        return response()->json(['teachers' => $rows]);
    }

    /**
     * The institution-wide counterpart to a course's own "hardest topics" figure (CourseAnalytics::topicAverages):
     * every published course module's average coursework mark, across every published offering, in one place -
     * so a course or system administrator does not have to open each course to see how its modules are doing.
     * Averages only ever come from the lecturer's own published grades; a module with nothing graded yet is
     * left out rather than shown as zero. Students never see this: it is gated the same as the other reports here.
     */
    public function moduleAverages(Request $request): JsonResponse|StreamedResponse
    {
        abort_unless($request->user()->can('manage-courses'), 403);
        $data = $request->validate(['term_id' => ['sometimes', 'integer'], 'format' => ['sometimes', 'in:json,csv']]);

        $offeringIds = CourseOffering::where('published', true)
            ->when(isset($data['term_id']), fn ($q) => $q->where('academic_term_id', $data['term_id']))
            ->pluck('id');

        $modules = DB::table('course_modules as m')
            ->join('course_offerings as o', 'o.id', '=', 'm.course_offering_id')
            ->join('courses as c', 'c.id', '=', 'o.course_id')
            ->join('academic_terms as t', 't.id', '=', 'o.academic_term_id')
            ->whereIn('m.course_offering_id', $offeringIds)->where('m.published', true)
            ->get(['m.id', 'm.title', 'o.id as offering_id', 'o.section', 'c.code', 'c.title as course_title', 't.name as term']);
        if ($modules->isEmpty()) {
            return $this->moduleAveragesResponse(collect(), $data);
        }
        $moduleIds = $modules->pluck('id');

        $assignmentAverages = $this->assignmentModuleAverages($moduleIds);
        $quizAverages = $this->quizModuleAverages($moduleIds);
        $perModule = $assignmentAverages->concat($quizAverages)->groupBy('module_id');

        $rows = $modules->map(function ($m) use ($perModule) {
            $scores = $perModule->get($m->id, collect());
            if ($scores->isEmpty()) {
                return null;
            }

            return [
                'module_id' => (int) $m->id, 'module' => $m->title, 'offering_id' => (int) $m->offering_id,
                'course' => "{$m->code} {$m->course_title}", 'section' => $m->section, 'term' => $m->term,
                'average_percent' => round($scores->avg('average'), 1), 'assessments_counted' => $scores->count(),
            ];
        })->filter()->sortBy('average_percent')->values();

        return $this->moduleAveragesResponse($rows, $data);
    }

    /** Each published, graded assignment's own average percent, one row per (module_id, average_percent). */
    private function assignmentModuleAverages($moduleIds): Collection
    {
        $assignments = Assignment::where('published', true)->whereIn('course_module_id', $moduleIds)->get(['id', 'course_module_id', 'max_score']);
        $submissions = Submission::whereIn('assignment_id', $assignments->pluck('id'))->get(['id', 'assignment_id']);
        $grades = GradeRecord::whereIn('submission_id', $submissions->pluck('id'))->where('status', 'published')->orderBy('id')->get()->groupBy('submission_id')->map(fn ($g) => $g->last());

        return $assignments->map(function ($a) use ($submissions, $grades) {
            $percents = $submissions->where('assignment_id', $a->id)->map(fn ($s) => $grades->get($s->id))->filter()
                ->map(fn ($g) => (float) $a->max_score > 0 ? (float) $g->score / (float) $a->max_score * 100 : null)->filter(fn ($p) => $p !== null);

            return $percents->isEmpty() ? null : ['module_id' => (int) $a->course_module_id, 'average' => $percents->avg()];
        })->filter()->values();
    }

    /** Each published, attempted quiz's own average percent (best attempt per student), one row per module. */
    private function quizModuleAverages($moduleIds): Collection
    {
        $quizzes = Quiz::where('published', true)->where('is_practice', false)->whereIn('course_module_id', $moduleIds)
            ->withSum('questions as total_points', 'points')->get(['id', 'course_module_id']);
        $bestByQuiz = QuizAttempt::whereIn('quiz_id', $quizzes->pluck('id'))->whereNotNull('submitted_at')
            ->selectRaw('quiz_id, user_id, max(score) as best_score')->groupBy('quiz_id', 'user_id')->get()->groupBy('quiz_id');

        return $quizzes->map(function ($q) use ($bestByQuiz) {
            $total = (float) $q->total_points;
            $percents = $total > 0 ? $bestByQuiz->get($q->id, collect())->map(fn ($b) => (float) $b->best_score / $total * 100) : collect();

            return $percents->isEmpty() ? null : ['module_id' => (int) $q->course_module_id, 'average' => $percents->avg()];
        })->filter()->values();
    }

    private function moduleAveragesResponse(Collection $rows, array $data): JsonResponse|StreamedResponse
    {
        if (($data['format'] ?? 'json') === 'csv') {
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                $put = fn (array $row) => fputcsv($out, array_map(fn ($cell) => $this->safeCell((string) $cell), $row), ',', '"', '');
                $put(['Semester', 'Course', 'Section', 'Module', 'Average %', 'Assessments counted']);
                foreach ($rows as $r) {
                    $put([$r['term'], $r['course'], $r['section'], $r['module'], $r['average_percent'], $r['assessments_counted']]);
                }
                fclose($out);
            }, 'module-averages-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
        }

        return response()->json(['modules' => $rows]);
    }

    /** Stops spreadsheet apps from running user-supplied text (names, titles) as a formula. */
    private function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
