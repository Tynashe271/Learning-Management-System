<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\AssignmentGroup;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseOffering;
use App\Models\GradeRecord;
use App\Models\LearningItem;
use App\Models\PeerReview;
use App\Models\RubricCriterion;
use App\Models\Submission;
use App\Models\SubmissionVersion;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Notifications\GradePublished;
use App\Rules\CleanFile;
use App\Services\EnrolmentService;
use App\Services\LoginGuard;
use App\Support\PasswordPolicy;
use App\Support\SecurityLog;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class LmsController extends Controller
{
    public function createUser(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('manage-users'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Emails are compared without regard to case, so "Ada@uni.edu" and "ada@uni.edu" cannot be two accounts.
            'email' => ['required', 'email', 'max:255', function (string $attribute, mixed $value, Closure $fail) {
                if (User::whereRaw('lower(email) = ?', [mb_strtolower((string) $value)])->exists()) {
                    $fail('The email has already been taken.');
                }
            }],
            'password' => PasswordPolicy::rules(),
            'role' => ['required', Rule::in(User::ROLES)],
        ]);
        abort_if($data['role'] === 'super-admin' && ! $request->user()->hasRole('super-admin'), 403);
        $user = User::create(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'password' => $data['password']]);
        $user->assignRole($data['role']);
        activity()->causedBy($request->user())->performedOn($user)->withProperties(['role' => $data['role']])->log('user created');

        return response()->json($user->load('roles'), 201);
    }

    public function notifications(Request $request): JsonResponse
    {
        return response()->json($request->user()->notifications()->latest()->paginate(20));
    }

    public function readNotification(Request $request, string $notification): JsonResponse
    {
        $record = $request->user()->notifications()->findOrFail($notification);
        $record->markAsRead();

        return response()->json(['message' => 'Notification read.']);
    }

    public function login(Request $request, LoginGuard $guard): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $email = mb_strtolower($data['email']);
        $who = SecurityLog::emailFingerprint($email);

        if ($seconds = $guard->retryAfter($email)) {
            SecurityLog::event('login.blocked_while_locked', ['email' => $who], 'warning');
            throw new ThrottleRequestsException('Too many failed sign-in attempts. Try again in '.max(1, (int) ceil($seconds / 60)).' minute(s).', null, ['Retry-After' => $seconds]);
        }

        $user = User::whereRaw('lower(email) = ?', [$email])->first();
        if ($user) {
            $valid = Hash::check($data['password'], $user->password);
        } else {
            Hash::make($data['password']); // the same work whether or not the account exists, so timing does not reveal it
            $valid = false;
        }
        if (! $valid || ! $user->is_active) {
            $locked = $guard->fail($email);
            SecurityLog::event($locked ? 'login.locked' : 'login.failed', ['email' => $who], 'warning');
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }
        if (config('lms.sso.enabled') && ! config('lms.sso.password_login') && ! $user->hasRole('super-admin')) {
            throw ValidationException::withMessages(['email' => 'Password sign-in is turned off. Use single sign-on.']);
        }
        // During maintenance only super administrators may sign in; everyone else is told why, without being issued a token.
        if (config('lms.maintenance.enabled') && ! $user->hasRole('super-admin')) {
            return response()->json(['message' => (string) config('lms.maintenance.message'), 'maintenance' => true], 503, ['Retry-After' => 300]);
        }
        $guard->clear($email);
        $user->forceFill(['last_login_at' => now()])->save();
        SecurityLog::event('login.success', ['user_id' => $user->id, 'email' => $who, 'method' => 'password']);

        return response()->json(['token' => $user->createToken('api')->plainTextToken, 'user' => $user->only('id', 'name', 'email'), 'roles' => $user->getRoleNames()]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * The course offerings the caller may see. Staff who run the catalogue see every offering (archived ones only when asked
     * for); teachers and students also see their past, archived ones, read-only. Filters: `term_id`, `department_id`, `q`
     * (course code or title), `status` (published or draft) and `archived` (exclude, include, only).
     */
    public function offerings(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->validate([
            'archived' => ['sometimes', Rule::in(['exclude', 'include', 'only'])], 'term_id' => ['sometimes', 'integer'], 'department_id' => ['sometimes', 'integer'],
            'q' => ['sometimes', 'string', 'max:100'], 'status' => ['sometimes', Rule::in(['published', 'draft'])],
        ]);
        $staff = $user->can('manage-courses') || $user->can('manage-enrolments');
        $query = CourseOffering::with(['course.department:id,code,name', 'term', 'teachers.user:id,name'])->orderBy('id');
        if (! $staff) {
            $query->where(function ($q) use ($user) {
                $q->whereHas('teachers', fn ($t) => $t->where('user_id', $user->id))
                    ->orWhere(fn ($e) => $e->where('published', true)->whereHas('enrolments', fn ($n) => $n->where('user_id', $user->id)->where('status', 'active')));
            });
        }
        match ($filters['archived'] ?? ($staff ? 'exclude' : 'include')) {
            'exclude' => $query->whereNull('archived_at'),
            'only' => $query->whereNotNull('archived_at'),
            default => null,
        };
        if (isset($filters['term_id'])) {
            $query->where('academic_term_id', $filters['term_id']);
        }
        if (isset($filters['department_id'])) {
            $query->whereHas('course', fn ($c) => $c->where('department_id', $filters['department_id']));
        }
        if (isset($filters['status'])) {
            $query->where('published', $filters['status'] === 'published');
        }
        if (isset($filters['q'])) {
            $like = '%'.strtolower(preg_replace('/[!%_]/', '!$0', $filters['q'])).'%';
            $query->whereHas('course', fn ($c) => $c->whereRaw("lower(code) like ? escape '!'", [$like])->orWhereRaw("lower(title) like ? escape '!'", [$like]));
        }

        return response()->json($query->paginate(20));
    }

    public function offering(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('view', $offering);
        $manage = $request->user()->can('manage', $offering);
        $offering->load(['course', 'term', 'modules' => function ($q) use ($manage) {
            if (! $manage) {
                $q->where('published', true);
            }
            $q->orderBy('position')->with(['items' => function ($items) use ($manage) {
                if (! $manage) {
                    $items->where('published', true);
                }
                $items->orderBy('position');
            }]);
        }, 'assignments' => function ($q) use ($manage, $request) {
            if ($manage) {
                $q->with('targetedUsers:id');
            } else {
                $q->where('published', true)->where(fn ($visible) => $visible->whereDoesntHave('targetedUsers')->orWhereHas('targetedUsers', fn ($t) => $t->where('users.id', $request->user()->id)));
            }
        }, 'quizzes' => function ($q) use ($manage, $request) {
            if ($manage) {
                $q->with('targetedUsers:id');
            } else {
                $q->where('published', true)->where(fn ($visible) => $visible->whereDoesntHave('targetedUsers')->orWhereHas('targetedUsers', fn ($t) => $t->where('users.id', $request->user()->id)));
            }
            $q->withCount('questions');
        }]);

        // Who teaches it, and whether the caller may edit it, so a frontend can show the right controls.
        $offering->load('teachers.user:id,name');

        if ($manage) {
            $offering->assignments->each(fn ($a) => $a->target_user_ids = $a->targetedUsers->pluck('id')->all())->each->unsetRelation('targetedUsers');
            $offering->quizzes->each(fn ($q) => $q->target_user_ids = $q->targetedUsers->pluck('id')->all())->each->unsetRelation('targetedUsers');
        }

        return response()->json($offering->toArray() + ['abilities' => ['manage' => $manage]]);
    }

    /**
     * Enrols a student, or withdraws them. Enrolling is refused when the offering is archived or full; an administrator who may
     * manage courses can pass `override` to go over the capacity on purpose (recorded in the audit log).
     */
    public function enrol(Request $request, CourseOffering $offering, EnrolmentService $enrolments): JsonResponse
    {
        abort_unless($request->user()->can('manage-enrolments'), 403);
        $data = $request->validate(['user_id' => ['required', 'exists:users,id'], 'status' => ['required', Rule::in(['active', 'withdrawn'])], 'override' => ['sometimes', 'boolean']]);
        $override = $request->boolean('override') && $request->user()->can('manage-courses');
        if ($request->boolean('override') && ! $override) {
            abort(403, 'Only a course administrator can enrol beyond the capacity.');
        }

        $enrolment = $data['status'] === 'active'
            ? $enrolments->activate($offering, (int) $data['user_id'], $override)
            : $enrolments->withdraw($offering, (int) $data['user_id']);
        $over = $override && $offering->capacity !== null && $offering->activeEnrolmentCount() > $offering->capacity;
        activity()->causedBy($request->user())->performedOn($enrolment)->withProperties(['user_id' => $data['user_id'], 'status' => $data['status'], 'over_capacity' => $over])->log('enrolment changed');

        return response()->json($enrolment);
    }

    public function teacher(Request $request, CourseOffering $offering): JsonResponse
    {
        abort_unless($request->user()->can('manage-courses'), 403);
        $data = $request->validate(['user_id' => ['required', 'exists:users,id']]);
        $teacherUser = User::findOrFail($data['user_id']);
        if (! $teacherUser->can('teach-courses')) {
            throw ValidationException::withMessages(['user_id' => 'User must have teaching permission.']);
        }
        $teacher = TeachingAssignment::firstOrCreate(['course_offering_id' => $offering->id, 'user_id' => $data['user_id']]);
        activity()->causedBy($request->user())->performedOn($offering)->withProperties($data)->log('teacher assigned');

        return response()->json($teacher);
    }

    /** A teacher publishes when students can reach them for this course. Only that teacher, or a course administrator, may set it. */
    public function updateTeacher(Request $request, CourseOffering $offering, User $user): JsonResponse
    {
        $this->authorize('manage', $offering);
        abort_unless($request->user()->is($user) || $request->user()->can('manage-courses'), 403);
        $assignment = TeachingAssignment::where('course_offering_id', $offering->id)->where('user_id', $user->id)->firstOrFail();
        $data = $request->validate(['consultation_hours' => ['nullable', 'string', 'max:500']]);
        $assignment->update($data);

        return response()->json($assignment->load('user:id,name'));
    }

    public function module(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('manage', $offering);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'published' => ['sometimes', 'boolean'],
            'prerequisite_module_id' => ['nullable', 'integer', Rule::exists('course_modules', 'id')->where('course_offering_id', $offering->id)],
        ]);

        return response()->json($offering->modules()->create($data), 201);
    }

    public function updateModule(Request $request, CourseModule $module): JsonResponse
    {
        $this->authorize('manage', CourseOffering::findOrFail($module->course_offering_id));
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'position' => ['sometimes', 'integer', 'min:0'],
            'published' => ['sometimes', 'boolean'],
            'prerequisite_module_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('course_modules', 'id')->where('course_offering_id', $module->course_offering_id),
                Rule::notIn([$module->id]),
            ],
        ]);
        if (array_key_exists('prerequisite_module_id', $data) && $data['prerequisite_module_id'] && $this->wouldCreateCycle($module, $data['prerequisite_module_id'])) {
            throw ValidationException::withMessages(['prerequisite_module_id' => 'That would make a topic a prerequisite of itself, directly or through a chain of other topics.']);
        }
        $module->update($data);

        return response()->json($module);
    }

    /** Walks up a proposed prerequisite chain (bounded, so a corrupt chain can never hang the request) looking for a way back to $module. */
    private function wouldCreateCycle(CourseModule $module, int $proposedPrerequisiteId): bool
    {
        $currentId = $proposedPrerequisiteId;
        for ($hops = 0; $hops < 50 && $currentId !== null; $hops++) {
            if ($currentId === $module->id) {
                return true;
            }
            $currentId = CourseModule::where('id', $currentId)->value('prerequisite_module_id');
        }

        return false;
    }

    /** A topic's readings, a list of its links, and its files, bundled into one zip for offline reading. */
    public function downloadModule(Request $request, CourseModule $module)
    {
        $offering = CourseOffering::findOrFail($module->course_offering_id);
        $this->authorize('view', $offering);
        $manage = $request->user()->can('manage', $offering);
        abort_unless($manage || $module->published, 403);

        $items = $module->items()->when(! $manage, fn ($q) => $q->where('published', true))->orderBy('position')->get();

        $zipPath = tempnam(sys_get_temp_dir(), 'topic').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $index = [$module->title, str_repeat('=', mb_strlen($module->title)), ''];
        foreach ($items as $i => $item) {
            $n = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            $slug = Str::slug($item->title) ?: 'untitled';
            if ($item->type === 'link') {
                $index[] = "{$n}. {$item->title} (link): {$item->body}";
            } elseif ($item->type === 'text') {
                $filename = "{$n}-{$slug}.txt";
                $index[] = "{$n}. {$item->title} (reading) -- see {$filename}";
                $zip->addFromString($filename, $item->title."\n\n".($item->body ?? ''));
            } elseif ($item->type === 'file' && $item->storage_path) {
                $extension = pathinfo($item->storage_path, PATHINFO_EXTENSION);
                $filename = "{$n}-{$slug}".($extension ? ".{$extension}" : '');
                $index[] = "{$n}. {$item->title} (file): {$filename}";
                $zip->addFromString($filename, Storage::disk('s3')->get($item->storage_path));
            }
        }
        if (count($index) === 3) {
            $index[] = 'Nothing published under this topic yet.';
        }
        $zip->addFromString('index.txt', implode("\n", $index)."\n");
        $zip->close();

        return response()->download($zipPath, Str::slug($module->title).'.zip')->deleteFileAfterSend(true);
    }

    public function item(Request $request, CourseModule $module): JsonResponse
    {
        $offering = CourseOffering::findOrFail($module->course_offering_id);
        $this->authorize('manage', $offering);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'type' => ['required', Rule::in(['text', 'link', 'file'])], 'body' => ['nullable', 'string'], 'file' => ['bail', 'nullable', 'file', 'max:'.(int) config('lms.limits.upload_mb') * 1024, 'mimes:'.config('lms.upload_mimes'), new CleanFile], 'position' => ['sometimes', 'integer', 'min:0'], 'published' => ['sometimes', 'boolean']]);
        if ($data['type'] === 'file' && ! $request->hasFile('file')) {
            throw ValidationException::withMessages(['file' => 'A file is required.']);
        }
        if ($data['type'] === 'link') {
            $request->validate(['body' => ['required', 'url:http,https', 'max:2048']]);
        } elseif ($data['type'] === 'text') {
            $request->validate(['body' => ['required', 'string']]);
        }
        if ($request->hasFile('file')) {
            $data['storage_path'] = $request->file('file')->store('course-files/'.$offering->id, 's3');
        }
        unset($data['file']);

        return response()->json($module->items()->create($data), 201);
    }

    public function updateItem(Request $request, LearningItem $item): JsonResponse
    {
        $module = CourseModule::findOrFail($item->course_module_id);
        $this->authorize('manage', CourseOffering::findOrFail($module->course_offering_id));
        $data = $request->validate(['title' => ['sometimes', 'string', 'max:255'], 'body' => ['sometimes', 'nullable', 'string'], 'position' => ['sometimes', 'integer', 'min:0'], 'published' => ['sometimes', 'boolean']]);
        $item->update($data);

        return response()->json($item);
    }

    public function downloadItem(Request $request, LearningItem $item)
    {
        $module = CourseModule::findOrFail($item->course_module_id);
        $offering = CourseOffering::findOrFail($module->course_offering_id);
        $this->authorize('view', $offering);
        abort_unless($request->user()->can('manage', $offering) || ($module->published && $item->published), 403);
        abort_unless($item->type === 'file' && $item->storage_path, 404);

        return Storage::disk('s3')->download($item->storage_path);
    }

    public function assignment(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('manage', $offering);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['nullable', 'string'],
            'due_at' => ['required', 'date', 'after:now'],
            'max_score' => ['required', 'integer', 'min:1'],
            'weight' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'published' => ['sometimes', 'boolean'],
            'allow_late_submissions' => ['sometimes', 'boolean'],
            'allow_resubmission' => ['sometimes', 'boolean'],
            'is_group_assignment' => ['sometimes', 'boolean'],
            'course_module_id' => ['nullable', 'integer', Rule::exists('course_modules', 'id')->where('course_offering_id', $offering->id)],
            'target_user_ids' => ['sometimes', 'array'],
            'target_user_ids.*' => ['integer', Rule::exists('enrolments', 'user_id')->where('course_offering_id', $offering->id)->where('status', 'active')],
        ]);
        $targetIds = $data['target_user_ids'] ?? null;
        unset($data['target_user_ids']);

        $assignment = $offering->assignments()->create($data);
        if ($targetIds !== null) {
            $assignment->targetedUsers()->sync($targetIds);
        }
        $assignment->target_user_ids = $assignment->targetedUsers()->pluck('users.id')->all();

        return response()->json($assignment, 201);
    }

    public function updateAssignment(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('manage', $assignment->offering);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'instructions' => ['sometimes', 'nullable', 'string'],
            'due_at' => ['sometimes', 'date', 'after:now'],
            'weight' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'published' => ['sometimes', 'boolean'],
            'allow_late_submissions' => ['sometimes', 'boolean'],
            'allow_resubmission' => ['sometimes', 'boolean'],
            'is_group_assignment' => ['sometimes', 'boolean'],
            'change_reason' => ['sometimes', 'string', 'max:1000'],
            'course_module_id' => ['sometimes', 'nullable', 'integer', Rule::exists('course_modules', 'id')->where('course_offering_id', $assignment->course_offering_id)],
            'target_user_ids' => ['sometimes', 'array'],
            'target_user_ids.*' => ['integer', Rule::exists('enrolments', 'user_id')->where('course_offering_id', $assignment->course_offering_id)->where('status', 'active')],
        ]);
        if (isset($data['due_at']) && empty($data['change_reason'])) {
            throw ValidationException::withMessages(['change_reason' => 'A reason is required for deadline changes.']);
        }
        $targetIds = array_key_exists('target_user_ids', $data) ? $data['target_user_ids'] : null;
        unset($data['target_user_ids']);
        $reason = $data['change_reason'] ?? null;
        unset($data['change_reason']);
        $assignment->update($data);
        if ($targetIds !== null) {
            $assignment->targetedUsers()->sync($targetIds);
        }
        activity()->causedBy($request->user())->performedOn($assignment)->withProperties(['changes' => $data, 'reason' => $reason])->log('assignment changed');
        $assignment->target_user_ids = $assignment->targetedUsers()->pluck('users.id')->all();

        return response()->json($assignment);
    }

    /** The groups for a group assignment, with their members, so a manager can see who is (and isn't) placed. */
    public function assignmentGroups(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('manage', $assignment->offering);

        return response()->json($assignment->groups()->with('members:id,name,email')->orderBy('id')->get());
    }

    public function storeAssignmentGroup(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('manage', $assignment->offering);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', Rule::exists('enrolments', 'user_id')->where('course_offering_id', $assignment->course_offering_id)->where('status', 'active')],
        ]);
        $this->assertNotAlreadyGrouped($assignment, $data['user_ids']);
        $group = $assignment->groups()->create(['name' => $data['name']]);
        $group->members()->sync($data['user_ids']);

        return response()->json($group->load('members:id,name,email'), 201);
    }

    public function updateAssignmentGroup(Request $request, AssignmentGroup $group): JsonResponse
    {
        $this->authorize('manage', $group->assignment->offering);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'user_ids' => ['sometimes', 'array', 'min:1'],
            'user_ids.*' => ['integer', Rule::exists('enrolments', 'user_id')->where('course_offering_id', $group->assignment->course_offering_id)->where('status', 'active')],
        ]);
        if (isset($data['user_ids'])) {
            $this->assertNotAlreadyGrouped($group->assignment, $data['user_ids'], excludingGroupId: $group->id);
            $group->members()->sync($data['user_ids']);
        }
        if (isset($data['name'])) {
            $group->update(['name' => $data['name']]);
        }

        return response()->json($group->load('members:id,name,email'));
    }

    public function destroyAssignmentGroup(Request $request, AssignmentGroup $group): JsonResponse
    {
        $this->authorize('manage', $group->assignment->offering);
        $group->delete();

        return response()->json(['message' => 'Group deleted.']);
    }

    /** @param  list<int>  $userIds */
    private function assertNotAlreadyGrouped(Assignment $assignment, array $userIds, ?int $excludingGroupId = null): void
    {
        $taken = $assignment->groups()
            ->when($excludingGroupId, fn ($q) => $q->where('id', '!=', $excludingGroupId))
            ->whereHas('members', fn ($q) => $q->whereIn('users.id', $userIds))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['user_ids' => 'One of these students is already in another group for this assignment.']);
        }
    }

    public function submit(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('submit', $assignment);
        $late = now()->greaterThan($assignment->due_at);
        $data = $request->validate([
            'body' => ['nullable', 'string'],
            'file' => ['bail', 'nullable', 'file', 'max:'.(int) config('lms.limits.upload_mb') * 1024, 'mimes:'.config('lms.upload_mimes'), new CleanFile],
            'late_explanation' => [Rule::requiredIf($late), 'nullable', 'string', 'max:1000'],
            'used_ai' => ['sometimes', 'boolean'],
            'ai_use_description' => [Rule::requiredIf(fn () => $request->boolean('used_ai')), 'nullable', 'string', 'max:1000'],
        ]);
        if (empty($data['body']) && ! $request->hasFile('file')) {
            throw ValidationException::withMessages(['body' => 'Provide text or a file.']);
        }

        $group = null;
        if ($assignment->is_group_assignment) {
            $group = $assignment->groups()->whereHas('members', fn ($q) => $q->where('users.id', $request->user()->id))->first();
            abort_unless($group, 422, 'You are not in a group for this assignment yet. Ask your teacher to add you to one.');
        }

        $alreadySubmitted = ValidationException::withMessages(['assignment' => 'Already submitted.']);
        $groupRows = $group ? Submission::where('assignment_id', $assignment->id)->where('assignment_group_id', $group->id)->get() : collect();
        $anyExisting = $group ? $groupRows->isNotEmpty() : Submission::where('assignment_id', $assignment->id)->where('user_id', $request->user()->id)->exists();
        $alreadyGraded = $group
            ? $groupRows->contains(fn (Submission $s) => $s->gradeRecords()->where('status', 'published')->exists())
            : ($anyExisting && Submission::where('assignment_id', $assignment->id)->where('user_id', $request->user()->id)->first()->gradeRecords()->where('status', 'published')->exists());

        if ($anyExisting && ! $assignment->allow_resubmission) {
            throw $alreadySubmitted;
        }
        if ($alreadyGraded) {
            throw ValidationException::withMessages(['assignment' => 'This assignment has already been graded and can no longer be resubmitted.']);
        }

        $path = $request->hasFile('file') ? $request->file('file')->store('submissions/'.$assignment->id.'/'.$request->user()->id, 's3') : null;
        $memberIds = $group ? $group->members()->pluck('users.id')->all() : [$request->user()->id];

        try {
            $mine = DB::transaction(function () use ($assignment, $group, $groupRows, $memberIds, $data, $path, $late, $request) {
                $mine = null;
                foreach ($memberIds as $memberId) {
                    $row = $group ? $groupRows->firstWhere('user_id', $memberId) : Submission::where('assignment_id', $assignment->id)->where('user_id', $memberId)->first();
                    if ($row) {
                        SubmissionVersion::create(['submission_id' => $row->id, 'version' => $row->version, 'body' => $row->body, 'storage_path' => $row->storage_path, 'submitted_at' => $row->submitted_at]);
                        $row->update(['body' => $data['body'] ?? null, 'storage_path' => $path, 'submitted_at' => now(), 'late' => $late, 'late_explanation' => $late ? $data['late_explanation'] : null, 'used_ai' => $data['used_ai'] ?? false, 'ai_use_description' => ($data['used_ai'] ?? false) ? $data['ai_use_description'] : null, 'version' => $row->version + 1]);
                    } else {
                        $row = Submission::create([
                            'assignment_id' => $assignment->id,
                            'user_id' => $memberId,
                            'assignment_group_id' => $group?->id,
                            'body' => $data['body'] ?? null,
                            'storage_path' => $path,
                            'submitted_at' => now(),
                            'late' => $late,
                            'late_explanation' => $late ? $data['late_explanation'] : null,
                            'used_ai' => $data['used_ai'] ?? false,
                            'ai_use_description' => ($data['used_ai'] ?? false) ? $data['ai_use_description'] : null,
                        ]);
                    }
                    if ($memberId === $request->user()->id) {
                        $mine = $row;
                    }
                }

                return $mine;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request won the race; drop the file we just stored.
            if ($path) {
                Storage::disk('s3')->delete($path);
            }
            throw $alreadySubmitted;
        }
        activity()->causedBy($request->user())->performedOn($mine)->log($anyExisting ? 'assignment resubmitted' : 'assignment submitted');

        return response()->json($mine, $anyExisting ? 200 : 201);
    }

    /** The prior versions of a submission, oldest first, each a snapshot taken just before a resubmission. */
    public function submissionVersions(Request $request, Submission $submission): JsonResponse
    {
        $this->authorizeSubmissionAccess($request, $submission);

        return response()->json($submission->versions()->orderBy('version')->get());
    }

    /** A grader leaves feedback on a submission as it currently stands, before the student resubmits or is formally graded. */
    public function giveSubmissionFeedback(Request $request, Submission $submission): JsonResponse
    {
        $this->authorize('grade', $submission->assignment);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $feedback = $submission->feedback()->create($data + ['version' => $submission->version, 'author_id' => $request->user()->id]);

        return response()->json($feedback->load('author:id,name'), 201);
    }

    /** All feedback left on a submission across its versions, oldest first. */
    public function submissionFeedback(Request $request, Submission $submission): JsonResponse
    {
        $this->authorizeSubmissionAccess($request, $submission);

        return response()->json($submission->feedback()->with('author:id,name')->orderBy('id')->get());
    }

    private function authorizeSubmissionAccess(Request $request, Submission $submission): void
    {
        abort_unless($submission->user_id === $request->user()->id || $request->user()->can('grade', $submission->assignment), 403);
    }

    /** One assignment, with its course and what the caller may do with it, so a page can be built from its link alone. */
    public function showAssignment(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('view', $assignment);
        $user = $request->user();
        $assignment->load('offering.course:id,code,title', 'offering.term:id,name', 'offering.modules:id,course_offering_id,title,position');
        $manage = $user->can('manage', $assignment->offering);
        if ($manage) {
            $assignment->target_user_ids = $assignment->targetedUsers()->pluck('users.id')->all();
        }

        return response()->json($assignment->toArray() + ['abilities' => [
            'manage' => $manage,
            'grade' => $user->can('grade', $assignment),
            'submit' => $user->can('submit', $assignment),
        ]]);
    }

    public function submissions(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('grade', $assignment);

        return response()->json(Submission::where('assignment_id', $assignment->id)->with('gradeRecords', 'user:id,name,email', 'group:id,name')->orderBy('id')->paginate(20));
    }

    public function downloadSubmission(Request $request, Submission $submission)
    {
        abort_unless(
            $submission->user_id === $request->user()->id
                || $request->user()->can('grade', $submission->assignment),
            403
        );
        abort_unless($submission->storage_path, 404);

        return Storage::disk('s3')->download($submission->storage_path);
    }

    public function downloadGradeRecording(Request $request, GradeRecord $grade)
    {
        $submission = $grade->submission;
        abort_unless($submission->user_id === $request->user()->id || $request->user()->can('grade', $submission->assignment), 403);
        abort_unless($grade->feedback_recording_path, 404);

        return Storage::disk('s3')->download($grade->feedback_recording_path);
    }

    public function grade(Request $request, Submission $submission): JsonResponse
    {
        $assignment = $submission->assignment;
        $this->authorize('grade', $assignment);
        $rubric = $assignment->rubricCriteria()->with('levels')->orderBy('position')->orderBy('id')->get();
        $rules = [
            'status' => ['required', Rule::in(['draft', 'published'])],
            'feedback' => ['nullable', 'string'],
            'feedback_recording' => ['bail', 'nullable', 'file', 'max:'.(int) config('lms.limits.upload_mb') * 1024, 'mimes:mp3,mp4,wav,m4a,webm,ogg', new CleanFile],
            'change_reason' => ['nullable', 'string'],
        ];
        if ($rubric->isEmpty()) {
            $rules += ['score' => ['required', 'numeric', 'min:0', 'max:'.$assignment->max_score], 'criteria' => ['prohibited']];
        } else {
            // With a rubric the score is the sum of the criteria marks; a separate score is not accepted.
            $rules += [
                'criteria' => ['required', 'array'],
                'criteria.*.criterion_id' => ['required', 'integer', Rule::in($rubric->pluck('id')->all())],
                // Either pick one of the criterion's levels (its points are used) or give points directly.
                'criteria.*.level_id' => ['nullable', 'integer'],
                'criteria.*.points' => ['required_without:criteria.*.level_id', 'nullable', 'numeric', 'min:0'],
                'criteria.*.comment' => ['nullable', 'string', 'max:2000'],
            ];
        }
        $data = $request->validate($rules);
        if ($rubric->isNotEmpty()) {
            [$data['score'], $data['criteria_scores']] = $this->scoreRubric($rubric, $data['criteria']);
            unset($data['criteria']);
        }
        if ($submission->gradeRecords()->exists() && empty($data['change_reason'])) {
            throw ValidationException::withMessages(['change_reason' => 'A reason is required for grade changes.']);
        }
        if ($request->hasFile('feedback_recording')) {
            $data['feedback_recording_path'] = $request->file('feedback_recording')->store('grade-feedback/'.$submission->id, 's3');
        }
        unset($data['feedback_recording']);
        $grade = $submission->gradeRecords()->create($data + ['graded_by' => $request->user()->id]);
        activity()->causedBy($request->user())->performedOn($submission)->withProperties(['grade_record_id' => $grade->id, 'status' => $grade->status])->log('grade recorded');
        if ($grade->status === 'published') {
            $submission->user->notify(GradePublished::for($submission, $assignment));
        }
        // A group submission's grade applies to every member: give each sibling row the same mark.
        if ($submission->assignment_group_id) {
            $siblings = Submission::where('assignment_group_id', $submission->assignment_group_id)->where('id', '!=', $submission->id)->get();
            foreach ($siblings as $sibling) {
                $siblingGrade = $sibling->gradeRecords()->create($data + ['graded_by' => $request->user()->id]);
                if ($siblingGrade->status === 'published') {
                    $sibling->user->notify(GradePublished::for($sibling, $assignment));
                }
            }
        }

        return response()->json($grade, 201);
    }

    /**
     * @param  Collection<int, RubricCriterion>  $rubric
     * @param  list<array<string, mixed>>  $given
     * @return array{0: float|int, 1: list<array<string, mixed>>} the total and a snapshot of each criterion's mark
     */
    private function scoreRubric(Collection $rubric, array $given): array
    {
        $byCriterion = collect($given)->keyBy('criterion_id');
        if ($byCriterion->count() !== count($given) || $byCriterion->count() !== $rubric->count()) {
            throw ValidationException::withMessages(['criteria' => 'Give exactly one mark for each rubric criterion.']);
        }
        $total = 0;
        $snapshot = [];
        foreach ($rubric as $criterion) {
            $entry = $byCriterion[$criterion->id];
            $level = null;
            if (! empty($entry['level_id'])) {
                $level = $criterion->levels->firstWhere('id', (int) $entry['level_id']);
                if (! $level) {
                    throw ValidationException::withMessages(['criteria' => "The chosen level does not belong to \"{$criterion->title}\"."]);
                }
                if (isset($entry['points']) && (float) $entry['points'] !== (float) $level->points) {
                    throw ValidationException::withMessages(['criteria' => "\"{$criterion->title}\": the points do not match the chosen level ({$level->points})."]);
                }
            }
            $points = $level ? (float) $level->points : (float) $entry['points'];
            if ($points > $criterion->max_points) {
                throw ValidationException::withMessages(['criteria' => "\"{$criterion->title}\" is worth at most {$criterion->max_points} points."]);
            }
            $total += $points;
            $snapshot[] = [
                'criterion_id' => $criterion->id, 'title' => $criterion->title, 'max_points' => $criterion->max_points, 'points' => $points,
                'level_id' => $level?->id, 'level_title' => $level?->title, 'comment' => $entry['comment'] ?? null,
            ];
        }

        return [$total, $snapshot];
    }

    public function myGrade(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('view', $assignment);
        $submission = Submission::where('assignment_id', $assignment->id)->where('user_id', $request->user()->id)->firstOrFail();

        return response()->json(['submission' => $submission, 'grade' => $submission->gradeRecords()->where('status', 'published')->latest('id')->first()]);
    }

    /** The caller's own group and teammates for a group assignment, or null if not placed in one yet. */
    public function myGroup(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('view', $assignment);
        $group = $assignment->groups()->whereHas('members', fn ($q) => $q->where('users.id', $request->user()->id))->with('members:id,name')->first();

        return response()->json($group);
    }

    /** Wipes any not-yet-done assignments and hands every student with a submission a fresh, random batch of classmates to review. Already-completed reviews are kept. */
    public function assignPeerReviews(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('manage', $assignment->offering);
        abort_if($assignment->is_group_assignment, 422, 'Peer review is not supported for group assignments.');
        $data = $request->validate(['per_student' => ['required', 'integer', 'min:1', 'max:10']]);

        $submissions = Submission::where('assignment_id', $assignment->id)->get(['id', 'user_id']);
        abort_if($submissions->count() < 2, 422, 'Need at least two submissions before assigning peer reviews.');

        PeerReview::where('assignment_id', $assignment->id)->whereNull('submitted_at')->delete();

        $perStudent = min($data['per_student'], $submissions->count() - 1);
        $byUser = $submissions->keyBy('user_id');
        $rows = [];
        foreach ($byUser->keys() as $reviewerId) {
            $targets = $byUser->keys()->filter(fn ($id) => $id !== $reviewerId)->shuffle()->take($perStudent);
            foreach ($targets as $targetUserId) {
                $rows[] = ['assignment_id' => $assignment->id, 'reviewer_id' => $reviewerId, 'submission_id' => $byUser[$targetUserId]->id, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            PeerReview::upsert($chunk, ['assignment_id', 'reviewer_id', 'submission_id'], ['updated_at']);
        }
        $assignment->update(['peer_reviews_per_student' => $perStudent]);
        activity()->causedBy($request->user())->performedOn($assignment)->withProperties(['count' => count($rows)])->log('peer reviews assigned');

        return response()->json(['assigned' => count($rows)]);
    }

    /** The submissions this student has been assigned to review, without revealing whose they are. */
    public function myPeerReviews(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('view', $assignment);
        $reviews = PeerReview::where('assignment_id', $assignment->id)->where('reviewer_id', $request->user()->id)
            ->with('submission:id,body,storage_path')->orderBy('id')->get();

        return response()->json($reviews->map(fn (PeerReview $r) => ['id' => $r->id, 'submission' => $r->submission->only(['id', 'body', 'storage_path']), 'body' => $r->body, 'submitted_at' => $r->submitted_at])->values());
    }

    public function givePeerReview(Request $request, PeerReview $review): JsonResponse
    {
        abort_unless($review->reviewer_id === $request->user()->id, 403);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $review->update($data + ['submitted_at' => now()]);

        return response()->json(['id' => $review->id, 'body' => $review->body, 'submitted_at' => $review->submitted_at]);
    }

    /** Completed reviews a submission has received, oldest first, with no reviewer identity attached. */
    public function submissionPeerReviews(Request $request, Submission $submission): JsonResponse
    {
        $this->authorizeSubmissionAccess($request, $submission);
        $reviews = $submission->peerReviews()->whereNotNull('submitted_at')->orderBy('id')->get(['id', 'body', 'submitted_at']);

        return response()->json($reviews);
    }
}
