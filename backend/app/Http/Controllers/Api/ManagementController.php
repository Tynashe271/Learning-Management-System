<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseOffering;
use App\Models\LearningItem;
use App\Models\TeachingAssignment;
use App\Models\User;
use App\Services\EnrolmentService;
use App\Support\CsvReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Edit and remove operations for the academic catalogue and course content. */
class ManagementController extends Controller
{
    public function removeTeacher(Request $request, CourseOffering $offering, User $user): JsonResponse
    {
        abort_unless($request->user()->can('manage-courses'), 403);
        TeachingAssignment::where('course_offering_id', $offering->id)->where('user_id', $user->id)->firstOrFail()->delete();
        activity()->causedBy($request->user())->performedOn($offering)->withProperties(['user_id' => $user->id])->log('teacher removed');

        return response()->json(['message' => 'Teacher removed.']);
    }

    /**
     * Enrols (or re-activates) existing students by email, from a JSON list or a CSV file with an `email` column. Unknown or
     * non-student emails are reported back, as are values that are not email addresses, and so are students who did not get a
     * place because the offering filled up (they are not enrolled, and nothing else is affected).
     */
    public function importEnrolments(Request $request, CourseOffering $offering, EnrolmentService $enrolments): JsonResponse
    {
        abort_unless($request->user()->can('manage-enrolments'), 403);
        $data = $request->validate([
            'emails' => ['required_without:file', 'array', 'min:1', 'max:500'],
            'emails.*' => ['required', 'email', 'max:255'],
            'file' => ['required_without:emails', 'file', 'mimes:csv,txt', 'max:1024'],
        ]);
        $listed = $request->hasFile('file') ? CsvReader::column($request->file('file')->getRealPath(), 'email', 500) : $data['emails'];
        $emails = collect($listed)->map(fn ($e) => mb_strtolower(trim($e)));
        $invalid = $emails->reject(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))->unique()->values();
        $emails = $emails->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))->unique()->values();

        $students = User::role('student')->whereIn(DB::raw('lower(email)'), $emails->all())->orderBy('id')->get(['id', 'email']);
        $enrolled = 0;
        $full = [];
        foreach ($students as $student) {
            try {
                $enrolments->activate($offering, $student->id);
                $enrolled++;
            } catch (ValidationException $e) {
                if (! isset($e->errors()['capacity'])) {
                    throw $e;
                }
                $full[] = mb_strtolower($student->email);
            }
        }
        $notFound = $emails->diff($students->pluck('email')->map(fn ($e) => mb_strtolower($e)))->values();
        activity()->causedBy($request->user())->performedOn($offering)->withProperties(['enrolled' => $enrolled, 'not_found' => $notFound->count(), 'no_place' => count($full)])->log('enrolments imported');

        return response()->json(['enrolled' => $enrolled, 'not_found' => $notFound, 'invalid' => $invalid, 'full' => $full]);
    }

    public function deleteModule(Request $request, CourseModule $module): JsonResponse
    {
        $this->authorize('manage', $module->offering);
        $files = $module->items()->whereNotNull('storage_path')->pluck('storage_path');
        $module->delete();
        $this->deleteFiles($files->all());
        activity()->causedBy($request->user())->performedOn($module)->log('module deleted');

        return response()->json(['message' => 'Module deleted.']);
    }

    public function deleteItem(Request $request, LearningItem $item): JsonResponse
    {
        $this->authorize('manage', $item->module->offering);
        $item->delete();
        $this->deleteFiles([$item->storage_path]);
        activity()->causedBy($request->user())->performedOn($item)->log('learning item deleted');

        return response()->json(['message' => 'Item deleted.']);
    }

    public function deleteAssignment(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('manage', $assignment->offering);
        if ($assignment->submissions()->exists()) {
            throw ValidationException::withMessages(['assignment' => 'An assignment with submissions cannot be deleted; unpublish it instead.']);
        }
        $assignment->delete();
        activity()->causedBy($request->user())->performedOn($assignment)->log('assignment deleted');

        return response()->json(['message' => 'Assignment deleted.']);
    }

    /** @param  list<string|null>  $paths */
    private function deleteFiles(array $paths): void
    {
        $paths = array_values(array_filter($paths));
        if ($paths !== []) {
            Storage::disk('s3')->delete($paths);
        }
    }
}
