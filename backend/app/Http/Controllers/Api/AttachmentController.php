<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttachmentFeedbackToken;
use App\Models\AttachmentLogbookEntry;
use App\Models\AttachmentPlacement;
use App\Models\CourseOffering;
use App\Notifications\AttachmentFeedbackRequested;
use App\Rules\CleanFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The industrial attachment workspace: a student's work placement, their weekly logbook, and a secure link a workplace supervisor (who has no LMS account) uses to leave an assessment. */
class AttachmentController extends Controller
{
    public function index(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('manage', $offering);

        return response()->json($offering->attachmentPlacements()->with('student:id,name,email')->orderBy('id')->get());
    }

    public function store(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('manage', $offering);
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('enrolments', 'user_id')->where('course_offering_id', $offering->id)->where('status', 'active')],
            'organisation' => ['required', 'string', 'max:255'],
            'supervisor_name' => ['required', 'string', 'max:255'],
            'supervisor_email' => ['required', 'email', 'max:255'],
            'objectives' => ['nullable', 'string', 'max:2000'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ]);

        return response()->json($offering->attachmentPlacements()->create($data)->load('student:id,name,email'), 201);
    }

    /** The caller's own placement for this offering, or null if none has been set up yet. */
    public function mine(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->authorize('view', $offering);

        return response()->json($offering->attachmentPlacements()->where('user_id', $request->user()->id)->first());
    }

    public function storeLogbookEntry(Request $request, AttachmentPlacement $placement): JsonResponse
    {
        abort_unless($placement->user_id === $request->user()->id, 403);
        $data = $request->validate([
            'week_ending' => ['required', 'date', 'before_or_equal:today'],
            'hours' => ['required', 'numeric', 'min:0.25', 'max:168'],
            'activities' => ['required', 'string', 'max:4000'],
            'evidence' => ['bail', 'nullable', 'file', 'max:'.(int) config('lms.limits.upload_mb') * 1024, 'mimes:'.config('lms.upload_mimes'), new CleanFile],
        ]);
        if ($placement->logbookEntries()->whereDate('week_ending', $data['week_ending'])->exists()) {
            throw ValidationException::withMessages(['week_ending' => 'An entry for this week already exists.']);
        }
        $path = $request->hasFile('evidence') ? $request->file('evidence')->store('attachments/'.$placement->id, 's3') : null;

        return response()->json($placement->logbookEntries()->create(['week_ending' => $data['week_ending'], 'hours' => $data['hours'], 'activities' => $data['activities'], 'evidence_path' => $path]), 201);
    }

    public function indexLogbookEntries(Request $request, AttachmentPlacement $placement): JsonResponse
    {
        abort_unless($placement->user_id === $request->user()->id || $request->user()->can('manage', $placement->offering), 403);

        return response()->json($placement->logbookEntries()->orderBy('week_ending')->get());
    }

    public function downloadEvidence(Request $request, AttachmentLogbookEntry $entry)
    {
        $placement = $entry->placement;
        abort_unless($placement->user_id === $request->user()->id || $request->user()->can('manage', $placement->offering), 403);
        abort_unless($entry->evidence_path, 404);

        return Storage::disk('s3')->download($entry->evidence_path);
    }

    /** Emails the supervisor a one-time link, invalidating any unused link sent earlier. */
    public function requestSupervisorFeedback(Request $request, AttachmentPlacement $placement): JsonResponse
    {
        $this->authorize('manage', $placement->offering);
        $placement->feedbackTokens()->whereNull('used_at')->update(['used_at' => now()]);
        $token = Str::random(64);
        $placement->feedbackTokens()->create(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(14)]);
        Notification::route('mail', $placement->supervisor_email)->notify(new AttachmentFeedbackRequested($placement, $token));

        return response()->json(['message' => 'Feedback request sent to '.$placement->supervisor_email.'.']);
    }

    /** Public: what the token's supervisor needs to see before giving feedback. No LMS account required. */
    public function showFeedbackForm(Request $request, string $token): JsonResponse
    {
        $record = $this->validToken($token);
        $placement = $record->placement()->with('student:id,name', 'offering.course:id,code,title')->first();

        return response()->json([
            'student_name' => $placement->student->name,
            'course' => $placement->offering->course?->code.' '.$placement->offering->course?->title,
            'organisation' => $placement->organisation,
            'objectives' => $placement->objectives,
            'starts_on' => $placement->starts_on,
            'ends_on' => $placement->ends_on,
            'already_submitted' => $placement->supervisor_submitted_at !== null,
        ]);
    }

    /** Public: the supervisor's one-time submission. */
    public function submitFeedback(Request $request, string $token): JsonResponse
    {
        $record = $this->validToken($token);
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $record->placement->update(['supervisor_rating' => $data['rating'], 'supervisor_comment' => $data['comment'] ?? null, 'supervisor_submitted_at' => now()]);
        $record->update(['used_at' => now()]);

        return response()->json(['message' => 'Thank you - your feedback has been recorded.']);
    }

    private function validToken(string $token): AttachmentFeedbackToken
    {
        $record = AttachmentFeedbackToken::where('token_hash', hash('sha256', $token))->first();
        abort_if(! $record || ! $record->isValid(), 404, 'This link is invalid or has expired.');

        return $record;
    }
}
