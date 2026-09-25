<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Services\EnrolmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Course registration by students themselves: browse the offerings that allow it, take a place while registration is open and
 * seats remain, and drop again until the add/drop deadline. After that only the registrar can change an enrolment.
 */
class RegistrationController extends Controller
{
    public function __construct(private EnrolmentService $enrolments) {}

    /** Offerings that allow self-registration this term or later, with whether registration is open and how many places are left. */
    public function index(Request $request): JsonResponse
    {
        $this->student($request);
        $filters = $request->validate(['q' => ['sometimes', 'string', 'max:100'], 'department_id' => ['sometimes', 'integer']]);
        $today = now(config('lms.institution.timezone'))->toDateString();

        $query = CourseOffering::with(['course.department:id,code,name', 'term', 'teachers.user:id,name'])
            ->withCount(['enrolments as active_count' => fn ($q) => $q->where('status', 'active')])
            ->where('published', true)->where('self_enrolment', true)->whereNull('archived_at')
            ->whereHas('term', fn ($t) => $t->where('ends_on', '>=', $today))->orderBy('id');
        if (isset($filters['department_id'])) {
            $query->whereHas('course', fn ($c) => $c->where('department_id', $filters['department_id']));
        }
        if (isset($filters['q'])) {
            $like = '%'.strtolower(preg_replace('/[!%_]/', '!$0', $filters['q'])).'%';
            $query->whereHas('course', fn ($c) => $c->whereRaw("lower(code) like ? escape '!'", [$like])->orWhereRaw("lower(title) like ? escape '!'", [$like]));
        }

        $page = $query->paginate(30);
        $mine = Enrolment::where('user_id', $request->user()->id)->whereIn('course_offering_id', $page->getCollection()->pluck('id'))->pluck('status', 'course_offering_id');
        $page->getCollection()->transform(function (CourseOffering $o) use ($mine) {
            $status = $mine[$o->id] ?? null;
            $seatsLeft = $o->capacity === null ? null : max(0, $o->capacity - $o->active_count);

            return $o->toArray() + ['registration' => [
                'open' => $o->term->registrationIsOpen(),
                'opens_on' => $o->term->registration_opens_on,
                'closes_on' => $o->term->registration_closes_on,
                'seats_left' => $seatsLeft,
                'full' => $seatsLeft === 0,
                'my_status' => $status,
                'can_register' => $o->term->registrationIsOpen() && $seatsLeft !== 0 && $status !== 'active',
                'can_drop' => $status === 'active' && $o->term->dropIsAllowed(),
                'drop_deadline' => $o->term->dropDeadline(),
            ]];
        });

        return response()->json($page);
    }

    public function register(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->student($request);
        $offering->load('term');
        if (! $offering->published || ! $offering->self_enrolment || $offering->archived_at !== null) {
            throw ValidationException::withMessages(['offering' => 'This course does not accept registration by students. Ask the registrar.']);
        }
        if (! $offering->term->registrationIsOpen()) {
            throw ValidationException::withMessages(['offering' => 'Registration for this term is not open.']);
        }
        $already = Enrolment::where('course_offering_id', $offering->id)->where('user_id', $request->user()->id)->where('status', 'active')->exists();
        $enrolment = $this->enrolments->activate($offering, $request->user()->id);
        if (! $already) {
            activity()->causedBy($request->user())->performedOn($enrolment)->withProperties(['self' => true])->log('student registered');
        }

        return response()->json($enrolment, 201);
    }

    public function drop(Request $request, CourseOffering $offering): JsonResponse
    {
        $this->student($request);
        $offering->load('term');
        $enrolment = Enrolment::where('course_offering_id', $offering->id)->where('user_id', $request->user()->id)->where('status', 'active')->first();
        if (! $enrolment) {
            throw ValidationException::withMessages(['offering' => 'You are not enrolled in this course.']);
        }
        if (! $offering->self_enrolment) {
            throw ValidationException::withMessages(['offering' => 'This enrolment was made by the registrar, so only the registrar can change it.']);
        }
        if (! $offering->term->dropIsAllowed()) {
            throw ValidationException::withMessages(['offering' => 'The add/drop deadline has passed. Ask the registrar to withdraw you.']);
        }
        $this->enrolments->withdraw($offering, $request->user()->id);
        activity()->causedBy($request->user())->performedOn($enrolment)->withProperties(['self' => true])->log('student dropped a course');

        return response()->json(['message' => 'You have dropped this course.']);
    }

    private function student(Request $request): void
    {
        abort_unless($request->user()->hasRole('student'), 403);
    }
}
