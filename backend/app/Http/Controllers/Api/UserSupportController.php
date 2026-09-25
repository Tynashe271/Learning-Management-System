<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\LoginGuard;
use App\Support\SecurityLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * What an administrator needs to help one person: why they cannot sign in, what they are in, a way to unstick them, and the
 * privacy tools (export, delete, anonymise) for when someone asks for their data.
 */
class UserSupportController extends Controller
{
    public function __construct(private LoginGuard $guard) {}

    /** One account in full: state, sign-in problems, courses, active sessions, recent security events, and what it owns. */
    public function show(Request $request, User $user): JsonResponse
    {
        $this->allow($request);

        $lock = $user->anonymised_at ? 0 : $this->guard->retryAfter(mb_strtolower($user->email));
        $lifetime = (int) config('sanctum.expiration');
        $sessions = DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->orderByDesc('last_used_at')->orderByDesc('id')
            ->get(['id', 'name', 'last_used_at', 'created_at'])
            ->map(fn ($t) => ['id' => $t->id, 'kind' => $t->name === 'sso' ? 'Single sign-on' : 'Password', 'last_used_at' => $t->last_used_at, 'created_at' => $t->created_at, 'expires_at' => $lifetime > 0 ? Carbon::parse($t->created_at)->addMinutes($lifetime)->toIso8601String() : null]);

        return response()->json([
            'user' => $user->load('roles:id,name')->only(['id', 'name', 'email', 'is_active', 'last_login_at', 'created_at', 'anonymised_at', 'digest_frequency']) + [
                'roles' => $user->roles->map->only(['id', 'name'])->values(),
                'has_sso_link' => $user->sso_subject !== null,
            ],
            'signin' => [
                'locked' => $lock > 0,
                'locked_for_seconds' => $lock,
                'can_sign_in' => $user->is_active && $lock === 0 && ! $user->anonymised_at,
            ],
            'sessions' => $sessions,
            'enrolments' => DB::table('enrolments')->join('course_offerings', 'course_offerings.id', '=', 'enrolments.course_offering_id')->join('courses', 'courses.id', '=', 'course_offerings.course_id')
                ->join('academic_terms', 'academic_terms.id', '=', 'course_offerings.academic_term_id')->where('enrolments.user_id', $user->id)
                ->get(['course_offerings.id as offering_id', 'courses.code', 'courses.title', 'academic_terms.name as term', 'course_offerings.section', 'enrolments.status']),
            'teaching' => DB::table('teaching_assignments')->join('course_offerings', 'course_offerings.id', '=', 'teaching_assignments.course_offering_id')->join('courses', 'courses.id', '=', 'course_offerings.course_id')
                ->join('academic_terms', 'academic_terms.id', '=', 'course_offerings.academic_term_id')->where('teaching_assignments.user_id', $user->id)
                ->get(['course_offerings.id as offering_id', 'courses.code', 'courses.title', 'academic_terms.name as term', 'course_offerings.section']),
            'events' => SecurityEvent::where('user_id', $user->id)->latest('id')->limit(15)->get(['id', 'event', 'level', 'ip', 'created_at']),
            'records' => $this->records($user),
        ]);
    }

    /** Clears a lockout, so someone locked out by mistyped passwords can try again straight away. */
    public function unlock(Request $request, User $user): JsonResponse
    {
        $this->allow($request);
        $this->guard->clear(mb_strtolower($user->email));
        activity()->causedBy($request->user())->performedOn($user)->log('account unlocked');
        SecurityLog::event('account.unlocked', ['target_user_id' => $user->id]);

        return response()->json(['message' => 'The account is unlocked.']);
    }

    /** Emails the person a link to choose a new password (valid for an hour). Nothing is shown to the administrator. */
    public function resetLink(Request $request, User $user): JsonResponse
    {
        $this->allow($request);
        if (! $user->is_active || $user->anonymised_at) {
            throw ValidationException::withMessages(['user' => 'Only active accounts can be sent a reset link.']);
        }
        Password::sendResetLink(['email' => $user->email]);
        activity()->causedBy($request->user())->performedOn($user)->log('password reset link sent');
        SecurityLog::event('password.reset_sent_by_admin', ['target_user_id' => $user->id]);

        return response()->json(['message' => "A reset link has been emailed to {$user->email}."]);
    }

    /** Signs the person out of every device (for a lost phone or a shared computer). */
    public function revokeSessions(Request $request, User $user): JsonResponse
    {
        $this->allow($request);
        $count = $user->tokens()->count();
        $user->tokens()->delete();
        activity()->causedBy($request->user())->performedOn($user)->withProperties(['sessions' => $count])->log('sessions revoked');
        SecurityLog::event('sessions.revoked_by_admin', ['target_user_id' => $user->id, 'sessions' => $count], 'warning');

        return response()->json(['revoked' => $count]);
    }

    /**
     * Deletes an account that has never produced anything. Anyone with academic or communication records is deactivated or
     * anonymised instead: grades, submissions and messages are records the institution must be able to stand behind.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->allow($request);
        abort_if($user->hasRole('super-admin') && ! $request->user()->hasRole('super-admin'), 403);
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => 'You cannot delete your own account.']);
        }
        if ($user->hasRole('super-admin') && User::role('super-admin')->where('is_active', true)->where('id', '!=', $user->id)->doesntExist()) {
            throw ValidationException::withMessages(['user' => 'This is the only active super administrator, so it cannot be deleted.']);
        }
        $records = array_filter($this->records($user));
        if ($records !== []) {
            $what = collect($records)->map(fn ($n, $k) => "{$n} ".str_replace('_', ' ', $k))->implode(', ');
            throw ValidationException::withMessages(['user' => "This account has records ({$what}), so it cannot be deleted. Deactivate it, or anonymise it if the person has asked for their data to be erased."]);
        }

        DB::transaction(function () use ($user) {
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->delete();
            DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->delete();
            DB::table('model_has_roles')->where('model_type', User::class)->where('model_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('account_invitation_tokens')->where('email', $user->email)->delete();
            $user->delete();
        });
        activity()->causedBy($request->user())->withProperties(['user_id' => $user->id, 'email' => $user->email, 'name' => $user->name])->log('user deleted');
        SecurityLog::event('account.deleted', ['target_user_id' => $user->id], 'warning');

        return response()->json(['message' => 'Account deleted.']);
    }

    /** Everything held about one person, as a document to hand over when they ask for it. */
    public function export(Request $request, User $user): JsonResponse
    {
        $this->allow($request);
        $id = $user->id;
        $data = [
            'exported_at' => now()->toIso8601String(),
            'profile' => $user->load('roles:id,name')->only(['id', 'name', 'email', 'is_active', 'created_at', 'last_login_at', 'digest_frequency']) + ['roles' => $user->roles->pluck('name')],
            'enrolments' => DB::table('enrolments')->join('course_offerings', 'course_offerings.id', '=', 'enrolments.course_offering_id')->join('courses', 'courses.id', '=', 'course_offerings.course_id')
                ->where('enrolments.user_id', $id)->get(['courses.code', 'courses.title', 'course_offerings.section', 'enrolments.status', 'enrolments.created_at']),
            'submissions' => DB::table('submissions')->join('assignments', 'assignments.id', '=', 'submissions.assignment_id')->where('submissions.user_id', $id)
                ->get(['assignments.title as assignment', 'submissions.body', 'submissions.storage_path as file', 'submissions.submitted_at'])->map(fn ($s) => ['assignment' => $s->assignment, 'body' => $s->body, 'file' => $s->file ? basename($s->file) : null, 'submitted_at' => $s->submitted_at]),
            'grades' => DB::table('grade_records')->join('submissions', 'submissions.id', '=', 'grade_records.submission_id')->join('assignments', 'assignments.id', '=', 'submissions.assignment_id')
                ->where('submissions.user_id', $id)->where('grade_records.status', 'published')->get(['assignments.title as assignment', 'grade_records.score', 'grade_records.feedback', 'grade_records.created_at']),
            'quiz_attempts' => DB::table('quiz_attempts')->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')->where('quiz_attempts.user_id', $id)->get(['quizzes.title as quiz', 'quiz_attempts.started_at', 'quiz_attempts.submitted_at', 'quiz_attempts.score', 'quiz_attempts.max_score']),
            'attendance' => DB::table('attendance_records')->join('class_sessions', 'class_sessions.id', '=', 'attendance_records.class_session_id')->where('attendance_records.user_id', $id)->get(['class_sessions.title as class', 'class_sessions.starts_at', 'attendance_records.status']),
            'appeals' => DB::table('grade_appeals')->where('user_id', $id)->get(['reason', 'status', 'response', 'created_at']),
            'discussion_posts' => DB::table('discussion_posts')->where('user_id', $id)->get(['body', 'created_at']),
            'messages_sent' => DB::table('direct_messages')->where('sender_id', $id)->get(['recipient_id', 'body', 'created_at']),
            'messages_received_count' => DB::table('direct_messages')->where('recipient_id', $id)->count(),
            'security_events' => SecurityEvent::where('user_id', $id)->latest('id')->limit(200)->get(['event', 'ip', 'created_at']),
        ];
        activity()->causedBy($request->user())->performedOn($user)->log('user data exported');
        SecurityLog::event('account.exported', ['target_user_id' => $id], 'warning');

        return response()->json($data);
    }

    /**
     * Erases who someone is while keeping what the institution must keep. The account stays (their submissions and grades still
     * belong to a real record) but its name and email are replaced, sign-in is impossible, their messages are emptied and their
     * notifications deleted. Cannot be undone, so the email must be typed to confirm.
     */
    public function anonymise(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('manage-system'), 403);
        $data = $request->validate(['confirm_email' => ['required', 'email']]);
        if (mb_strtolower($data['confirm_email']) !== mb_strtolower($user->email)) {
            throw ValidationException::withMessages(['confirm_email' => 'That is not this account\'s email address.']);
        }
        if ($user->anonymised_at) {
            throw ValidationException::withMessages(['user' => 'This account is already anonymised.']);
        }
        if ($user->is($request->user()) || $user->hasRole('super-admin')) {
            throw ValidationException::withMessages(['user' => 'A super administrator account cannot be anonymised. Change its role first.']);
        }

        $paths = DB::table('message_attachments')->join('direct_messages', 'direct_messages.id', '=', 'message_attachments.direct_message_id')->where('direct_messages.sender_id', $user->id)->pluck('message_attachments.storage_path')->all();
        DB::transaction(function () use ($user) {
            DB::table('message_attachments')->whereIn('direct_message_id', DB::table('direct_messages')->where('sender_id', $user->id)->select('id'))->delete();
            DB::table('direct_messages')->where('sender_id', $user->id)->update(['body' => '[removed]']);
            DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('account_invitation_tokens')->where('email', $user->email)->delete();
            $user->forceFill([
                'name' => "Former user #{$user->id}",
                'email' => "anonymised-{$user->id}@invalid.example",
                'password' => Str::random(64),
                'is_active' => false,
                'sso_subject' => null,
                'digest_frequency' => 'off',
                'anonymised_at' => now(),
            ])->save();
        });
        try {
            if ($paths !== []) {
                Storage::disk('s3')->delete($paths);
            }
        } catch (Throwable $e) {
            report($e);
        }
        activity()->causedBy($request->user())->withProperties(['user_id' => $user->id])->log('user anonymised');
        SecurityLog::event('account.anonymised', ['target_user_id' => $user->id], 'warning');

        return response()->json(['message' => 'The account has been anonymised.']);
    }

    private function allow(Request $request): void
    {
        abort_unless($request->user()->can('manage-users'), 403);
    }

    /**
     * What this account owns that must not simply vanish, counted by kind. Empty means it has never produced anything.
     *
     * @return array<string, int>
     */
    private function records(User $user): array
    {
        $id = $user->id;

        return [
            'submissions' => DB::table('submissions')->where('user_id', $id)->count(),
            'quiz_attempts' => DB::table('quiz_attempts')->where('user_id', $id)->count(),
            'grades_given' => DB::table('grade_records')->where('graded_by', $id)->count(),
            'appeals' => DB::table('grade_appeals')->where('user_id', $id)->orWhere('resolved_by', $id)->count(),
            'messages' => DB::table('direct_messages')->where('sender_id', $id)->orWhere('recipient_id', $id)->count(),
            'discussion_threads' => DB::table('discussion_threads')->where('user_id', $id)->count(),
            'discussion_posts' => DB::table('discussion_posts')->where('user_id', $id)->count(),
            'announcements' => DB::table('announcements')->where('user_id', $id)->count(),
            'attendance_marks' => DB::table('attendance_records')->where('marked_by', $id)->count(),
        ];
    }
}
